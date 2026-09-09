import jsQR from 'jsqr';
import { onBeforeUnmount, ref, shallowRef } from 'vue';

export type EstadoEscaner = 'inactivo' | 'iniciando' | 'activo' | 'error';

/**
 * Escáner de QR por cámara para el inventario físico. Usa `jsqr` (decodificado
 * puro en JS, sin dependencia de `BarcodeDetector`) para funcionar también en
 * Safari de iPhone/iPad además de Chrome Android y de escritorio.
 *
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

    let video: HTMLVideoElement | null = null;
    let lienzo: HTMLCanvasElement | null = null;
    let rafId: number | null = null;
    let bloqueado = false;
    const vistosRecientes = new Map<string, number>();

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
            stream.value = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false,
            });
        } catch (e) {
            fallar(mensajeDesdeError(e));
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

        lienzo = document.createElement('canvas');
        estado.value = 'activo';
        bucle();
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

    return { estado, mensajeError, iniciar, detener, desbloquear };
}
