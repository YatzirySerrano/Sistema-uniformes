import { computed, onBeforeUnmount, ref, shallowRef } from 'vue';
import {
    type CamaraInfo,
    camarasDeVideo,
    siguienteCamara,
} from './camara/dispositivos';

export type EstadoCamara = 'inactiva' | 'iniciando' | 'activa' | 'error';

const CLAVE_PREFERENCIA = 'camara:preferida';

/**
 * Captura de una FOTO con la cámara del dispositivo. Reutiliza el mismo patrón
 * seguro de `getUserMedia` que `useEscanerQr` (contexto seguro / HTTPS,
 * cámara trasera preferida, mensajes de error humanos por `e.name`, limpieza
 * del stream `onBeforeUnmount`), pero NO decodifica nada: sólo dibuja el
 * fotograma actual en un `<canvas>` y devuelve un `File` JPEG.
 *
 * `facingMode: { ideal: 'environment' }` es sólo la preferencia INICIAL — el
 * navegador puede ignorarla. Tras obtener permiso se enumeran las cámaras
 * reales y `cambiarCamara()` cicla entre ellas por `deviceId` exacto,
 * recordando la elección en `localStorage`.
 *
 * Nunca produce un base64 gigante en un JSON: el `File` viaja como
 * `multipart/form-data` dentro del `useForm` normal del consumidor.
 */
export function useCamaraFoto() {
    const estado = ref<EstadoCamara>('inactiva');
    const mensajeError = ref<string | null>(null);
    const stream = shallowRef<MediaStream | null>(null);

    const camaras = ref<CamaraInfo[]>([]);
    const camaraActualId = ref<string | null>(null);
    const cambiandoCamara = ref(false);
    const puedeCambiarCamara = computed(() => camaras.value.length > 1);

    let video: HTMLVideoElement | null = null;

    function leerPreferencia(): string | null {
        try {
            return window.localStorage.getItem(CLAVE_PREFERENCIA);
        } catch {
            return null;
        }
    }

    function guardarPreferencia(deviceId: string | null): void {
        try {
            if (deviceId) {
                window.localStorage.setItem(CLAVE_PREFERENCIA, deviceId);
            }
        } catch {
            // localStorage no disponible: la preferencia sólo dura la sesión.
        }
    }

    function detener(): void {
        stream.value?.getTracks().forEach((t) => t.stop());
        stream.value = null;
        if (video) {
            video.srcObject = null;
        }
        video = null;
        camaras.value = [];
        camaraActualId.value = null;
        if (estado.value !== 'error') {
            estado.value = 'inactiva';
        }
    }

    function mensajeDesdeError(e: unknown): string {
        const nombre = (e as { name?: string })?.name ?? '';
        if (nombre === 'NotAllowedError' || nombre === 'SecurityError') {
            return 'No diste permiso para usar la cámara. Habilítalo en el navegador o sube un archivo en su lugar.';
        }
        if (
            nombre === 'NotFoundError' ||
            nombre === 'DevicesNotFoundError' ||
            nombre === 'OverconstrainedError'
        ) {
            return 'No se encontró ninguna cámara en este dispositivo. Puedes subir un archivo en su lugar.';
        }
        if (nombre === 'NotReadableError' || nombre === 'TrackStartError') {
            return 'La cámara está siendo usada por otra aplicación. Ciérrala e inténtalo de nuevo, o sube un archivo.';
        }
        return 'No fue posible acceder a la cámara. Puedes subir un archivo en su lugar.';
    }

    /**
     * Pide el stream: primero con el `deviceId` exacto si hay uno, y si eso
     * falla por restricción imposible, reintenta con la preferencia de cámara
     * trasera.
     */
    async function abrirStream(deviceId: string | null): Promise<MediaStream> {
        const restriccionVideo =
            deviceId !== null
                ? { deviceId: { exact: deviceId } }
                : { facingMode: { ideal: 'environment' as const } };
        try {
            return await navigator.mediaDevices.getUserMedia({
                video: restriccionVideo,
                audio: false,
            });
        } catch (e) {
            const nombre = (e as { name?: string })?.name ?? '';
            if (
                deviceId !== null &&
                (nombre === 'OverconstrainedError' ||
                    nombre === 'NotFoundError' ||
                    nombre === 'DevicesNotFoundError')
            ) {
                return navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' } },
                    audio: false,
                });
            }
            throw e;
        }
    }

    async function sincronizarDispositivos(activo: MediaStream): Promise<void> {
        camaraActualId.value =
            activo.getVideoTracks()[0]?.getSettings().deviceId ?? null;
        try {
            const dispositivos =
                await navigator.mediaDevices.enumerateDevices();
            camaras.value = camarasDeVideo(dispositivos);
        } catch {
            camaras.value = [];
        }
    }

    async function conectarVideo(activo: MediaStream): Promise<void> {
        if (!video) return;
        video.srcObject = activo;
        video.setAttribute('playsinline', 'true');
        video.muted = true;
        try {
            await video.play();
        } catch {
            // Autoplay bloqueado: el usuario tocará el vídeo para reproducirlo.
        }
    }

    async function iniciar(elVideo: HTMLVideoElement): Promise<void> {
        if (estado.value === 'activa' || estado.value === 'iniciando') {
            return;
        }
        mensajeError.value = null;
        estado.value = 'iniciando';
        video = elVideo;

        if (!window.isSecureContext) {
            mensajeError.value =
                'Se requiere una conexión segura (HTTPS) para usar la cámara. Puedes subir un archivo en su lugar.';
            estado.value = 'error';
            return;
        }
        if (!navigator.mediaDevices?.getUserMedia) {
            mensajeError.value =
                'Este navegador no permite usar la cámara. Puedes subir un archivo en su lugar.';
            estado.value = 'error';
            return;
        }

        try {
            stream.value = await abrirStream(leerPreferencia());
        } catch (e) {
            mensajeError.value = mensajeDesdeError(e);
            estado.value = 'error';
            return;
        }

        await conectarVideo(stream.value);
        await sincronizarDispositivos(stream.value);
        guardarPreferencia(camaraActualId.value);
        estado.value = 'activa';
    }

    /**
     * Cicla a la siguiente cámara de vídeo. Detiene el stream anterior, abre el
     * nuevo por `deviceId` exacto y reconecta el vídeo. Si falla, deja un
     * mensaje humano e intenta seguir con la cámara previa.
     */
    async function cambiarCamara(): Promise<void> {
        if (cambiandoCamara.value || estado.value !== 'activa' || !video) {
            return;
        }
        const objetivo = siguienteCamara(camaras.value, camaraActualId.value);
        if (objetivo === null) {
            return;
        }

        cambiandoCamara.value = true;
        const anterior = stream.value;
        anterior?.getTracks().forEach((t) => t.stop());

        try {
            stream.value = await navigator.mediaDevices.getUserMedia({
                video: { deviceId: { exact: objetivo.deviceId } },
                audio: false,
            });
        } catch {
            mensajeError.value =
                'No fue posible cambiar de cámara. Puedes seguir usando la cámara disponible.';
            try {
                stream.value = await abrirStream(camaraActualId.value);
                await conectarVideo(stream.value);
            } catch {
                stream.value = null;
                estado.value = 'error';
            }
            cambiandoCamara.value = false;
            return;
        }

        mensajeError.value = null;
        await conectarVideo(stream.value);
        await sincronizarDispositivos(stream.value);
        guardarPreferencia(camaraActualId.value);
        cambiandoCamara.value = false;
    }

    /**
     * Captura el fotograma actual como `File` JPEG. Reduce el lado mayor a
     * 1600 px para no subir imágenes enormes desde móviles modernos.
     */
    async function capturar(): Promise<File | null> {
        if (!video || estado.value !== 'activa') return null;
        const w0 = video.videoWidth;
        const h0 = video.videoHeight;
        if (!w0 || !h0) return null;

        const escala = Math.min(1, 1600 / Math.max(w0, h0));
        const w = Math.round(w0 * escala);
        const h = Math.round(h0 * escala);
        const lienzo = document.createElement('canvas');
        lienzo.width = w;
        lienzo.height = h;
        const ctx = lienzo.getContext('2d');
        if (!ctx) return null;
        ctx.drawImage(video, 0, 0, w, h);

        return new Promise<File | null>((resolve) => {
            lienzo.toBlob(
                (blob) => {
                    if (!blob) {
                        resolve(null);
                        return;
                    }
                    resolve(
                        new File([blob], `evidencia-${Date.now()}.jpg`, {
                            type: 'image/jpeg',
                        }),
                    );
                },
                'image/jpeg',
                0.85,
            );
        });
    }

    onBeforeUnmount(detener);

    return {
        estado,
        mensajeError,
        stream,
        camaras,
        camaraActualId,
        puedeCambiarCamara,
        iniciar,
        detener,
        cambiarCamara,
        capturar,
    };
}
