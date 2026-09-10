import { onBeforeUnmount, ref, shallowRef } from 'vue';

export type EstadoCamara = 'inactiva' | 'iniciando' | 'activa' | 'error';

/**
 * Captura de una FOTO con la cámara del dispositivo. Reutiliza el mismo patrón
 * seguro de `getUserMedia` que `useEscanerQr` (contexto seguro / HTTPS,
 * cámara trasera preferida, mensajes de error humanos por `e.name`, limpieza
 * del stream `onBeforeUnmount`), pero NO decodifica nada: sólo dibuja el
 * fotograma actual en un `<canvas>` y devuelve un `File` JPEG.
 *
 * Nunca produce un base64 gigante en un JSON: el `File` viaja como
 * `multipart/form-data` dentro del `useForm` normal del consumidor.
 */
export function useCamaraFoto() {
    const estado = ref<EstadoCamara>('inactiva');
    const mensajeError = ref<string | null>(null);
    const stream = shallowRef<MediaStream | null>(null);

    let video: HTMLVideoElement | null = null;

    function detener(): void {
        stream.value?.getTracks().forEach((t) => t.stop());
        stream.value = null;
        if (video) {
            video.srcObject = null;
        }
        video = null;
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
            stream.value = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false,
            });
        } catch (e) {
            mensajeError.value = mensajeDesdeError(e);
            estado.value = 'error';
            return;
        }

        video.srcObject = stream.value;
        video.setAttribute('playsinline', 'true');
        video.muted = true;
        try {
            await video.play();
        } catch {
            // Autoplay bloqueado: el usuario tocará el vídeo para reproducirlo.
        }
        estado.value = 'activa';
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

    return { estado, mensajeError, stream, iniciar, detener, capturar };
}
