import jsQR from 'jsqr';
import { computed, onBeforeUnmount, ref, shallowRef } from 'vue';
import {
    type CamaraInfo,
    camarasDeVideo,
    siguienteCamara,
} from './camara/dispositivos';

export type EstadoEscaner = 'inactivo' | 'iniciando' | 'activo' | 'error';

const CLAVE_PREFERENCIA = 'camara:preferida';

/**
 * Escáner de QR por cámara para el inventario físico. Usa `jsqr` (decodificado
 * puro en JS, sin dependencia de `BarcodeDetector`) para funcionar también en
 * Safari de iPhone/iPad además de Chrome Android y de escritorio.
 *
 * - `facingMode: { ideal: 'environment' }` es sólo la preferencia INICIAL. Tras
 *   obtener permiso se enumeran las cámaras reales y `cambiarCamara()` cicla
 *   entre ellas por `deviceId` exacto (reiniciando el bucle de lectura y el
 *   antirebote), recordando la elección en `localStorage`.
 * - Antirebote doble: un QR que se lee en varios frames seguidos sólo dispara
 *   `alDetectar` una vez cada ~2.5 s; además hay un bloqueo corto tras cada
 *   detección (el consumidor lo libera con `desbloquear()` al terminar su POST).
 * - Errores de cámara (permiso denegado, sin cámara, HTTPS, en uso) devuelven
 *   un mensaje humano en `mensajeError`; nunca revientan. La entrada manual
 *   sigue disponible como respaldo.
 */
export function useEscanerQr(alDetectar: (texto: string) => void) {
    const estado = ref<EstadoEscaner>('inactivo');
    const mensajeError = ref<string | null>(null);
    const stream = shallowRef<MediaStream | null>(null);

    const camaras = ref<CamaraInfo[]>([]);
    const camaraActualId = ref<string | null>(null);
    const cambiandoCamara = ref(false);
    const puedeCambiarCamara = computed(() => camaras.value.length > 1);

    let video: HTMLVideoElement | null = null;
    let lienzo: HTMLCanvasElement | null = null;
    let rafId: number | null = null;
    let bloqueado = false;
    const vistosRecientes = new Map<string, number>();

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

    function detenerBucle(): void {
        if (rafId !== null) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }
    }

    function liberarStream(): void {
        stream.value?.getTracks().forEach((t) => t.stop());
        stream.value = null;
        if (video) {
            video.srcObject = null;
        }
        video = null;
        lienzo = null;
    }

    function detener(): void {
        detenerBucle();
        liberarStream();
        vistosRecientes.clear();
        bloqueado = false;
        camaras.value = [];
        camaraActualId.value = null;
        if (estado.value !== 'error') {
            estado.value = 'inactivo';
        }
    }

    function fallar(mensaje: string): void {
        detenerBucle();
        liberarStream();
        mensajeError.value = mensaje;
        estado.value = 'error';
    }

    function mensajeDesdeError(e: unknown): string {
        const nombre = (e as { name?: string })?.name ?? '';

        if (nombre === 'NotAllowedError' || nombre === 'SecurityError') {
            return 'No diste permiso para usar la cámara. Habilítalo en el navegador o ingresa el código manualmente.';
        }
        if (
            nombre === 'NotFoundError' ||
            nombre === 'DevicesNotFoundError' ||
            nombre === 'OverconstrainedError'
        ) {
            return 'No se encontró ninguna cámara en este dispositivo. Puedes ingresar el código manualmente.';
        }
        if (nombre === 'NotReadableError' || nombre === 'TrackStartError') {
            return 'La cámara está siendo usada por otra aplicación. Ciérrala e inténtalo de nuevo, o ingresa el código manualmente.';
        }

        return 'No fue posible acceder a la cámara. Puedes ingresar el código manualmente.';
    }

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
        if (estado.value === 'activo' || estado.value === 'iniciando') {
            return;
        }

        mensajeError.value = null;
        estado.value = 'iniciando';
        video = elVideo;

        if (!window.isSecureContext) {
            fallar(
                'Se requiere una conexión segura (HTTPS) para usar la cámara. Puedes ingresar el código manualmente.',
            );
            return;
        }
        if (!navigator.mediaDevices?.getUserMedia) {
            fallar(
                'Este navegador no permite usar la cámara. Puedes ingresar el código manualmente.',
            );
            return;
        }

        try {
            stream.value = await abrirStream(leerPreferencia());
        } catch (e) {
            fallar(mensajeDesdeError(e));
            return;
        }

        await conectarVideo(stream.value);
        await sincronizarDispositivos(stream.value);
        guardarPreferencia(camaraActualId.value);

        lienzo = document.createElement('canvas');
        estado.value = 'activo';
        bucle();
    }

    /**
     * Cicla a la siguiente cámara: cancela el RAF, detiene el stream anterior,
     * abre el nuevo por `deviceId` exacto, reconecta el vídeo y reinicia el
     * bucle de lectura + el antirebote (para no arrastrar lecturas fantasma).
     * Si falla, mensaje humano e intento de volver a la cámara previa.
     */
    async function cambiarCamara(): Promise<void> {
        if (cambiandoCamara.value || estado.value !== 'activo' || !video) {
            return;
        }
        const objetivo = siguienteCamara(camaras.value, camaraActualId.value);
        if (objetivo === null) {
            return;
        }

        cambiandoCamara.value = true;
        detenerBucle();
        stream.value?.getTracks().forEach((t) => t.stop());

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
                vistosRecientes.clear();
                bloqueado = false;
                bucle();
            } catch {
                fallar(mensajeDesdeError({ name: 'NotReadableError' }));
            }
            cambiandoCamara.value = false;
            return;
        }

        mensajeError.value = null;
        await conectarVideo(stream.value);
        await sincronizarDispositivos(stream.value);
        guardarPreferencia(camaraActualId.value);
        vistosRecientes.clear();
        bloqueado = false;
        bucle();
        cambiandoCamara.value = false;
    }

    function bucle(): void {
        rafId = requestAnimationFrame(bucle);

        if (!video || !lienzo || video.readyState < 2 || bloqueado) {
            return;
        }

        const anchoReal = video.videoWidth;
        const altoReal = video.videoHeight;
        if (!anchoReal || !altoReal) {
            return;
        }

        // Downscale: jsQR sobre ~640 px de lado es barato incluso en móviles viejos.
        const escala = Math.min(1, 640 / Math.max(anchoReal, altoReal));
        const w = Math.max(1, Math.round(anchoReal * escala));
        const h = Math.max(1, Math.round(altoReal * escala));
        lienzo.width = w;
        lienzo.height = h;

        const ctx = lienzo.getContext('2d', { willReadFrequently: true });
        if (!ctx) {
            return;
        }
        ctx.drawImage(video, 0, 0, w, h);
        const datos = ctx.getImageData(0, 0, w, h);
        const codigo = jsQR(datos.data, w, h, {
            inversionAttempts: 'dontInvert',
        });
        if (!codigo?.data) {
            return;
        }

        const ahora = Date.now();
        if (ahora - (vistosRecientes.get(codigo.data) ?? 0) < 2500) {
            return;
        }
        vistosRecientes.set(codigo.data, ahora);
        if (vistosRecientes.size > 40) {
            vistosRecientes.clear();
        }

        bloqueado = true;
        try {
            navigator.vibrate?.(60);
        } catch {
            // Sin soporte de vibración: no pasa nada.
        }
        alDetectar(codigo.data);
    }

    /** El consumidor lo llama tras resolver su POST para admitir el siguiente escaneo. */
    function desbloquear(): void {
        bloqueado = false;
    }

    onBeforeUnmount(detener);

    return {
        estado,
        mensajeError,
        camaras,
        camaraActualId,
        puedeCambiarCamara,
        iniciar,
        detener,
        cambiarCamara,
        desbloquear,
    };
}
