import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

const MENSAJE_GENERAL = 'No tienes permiso para realizar esta acción.';

let ultimoAviso = 0;

/**
 * Evita repetir el mismo toast cuando varias peticiones (p. ej. los
 * buscadores de un formulario) reciben 403 casi al mismo tiempo.
 */
function avisar(mensaje: string | null | undefined): void {
    const ahora = Date.now();

    if (ahora - ultimoAviso < 2500) {
        return;
    }

    ultimoAviso = ahora;
    toast.error(mensaje && mensaje.trim() !== '' ? mensaje : MENSAJE_GENERAL);
}

function mensajeDe(data: unknown): string | null {
    if (data && typeof data === 'object' && 'message' in data) {
        const mensaje = (data as { message: unknown }).message;

        return typeof mensaje === 'string' ? mensaje : null;
    }

    if (typeof data === 'string') {
        try {
            return mensajeDe(JSON.parse(data));
        } catch {
            return null;
        }
    }

    return null;
}

/**
 * Presentación amigable de un acceso NO autorizado (HTTP 403) en acciones
 * que no navegan. El backend sigue respondiendo 403 con un JSON en español
 * (`App\Soporte\AccesoNoAutorizado`); aquí sólo se convierte en toast:
 *
 * - Acciones Inertia (formularios, botones, diálogos): se cancela el modal
 *   de "respuesta no Inertia" y se muestra el mensaje sin salir de la
 *   pantalla.
 * - `fetch` de la propia aplicación (buscadores, acciones inline): se avisa
 *   con el mismo toast, sin alterar la respuesta que recibe quien llamó.
 *
 * Las navegaciones (GET) reciben la página `Errores/SinPermiso`.
 */
export function initializeAvisoSinPermiso(): void {
    router.on('httpException', (event) => {
        const respuesta = event.detail.response;

        if (respuesta.status !== 403) {
            return;
        }

        event.preventDefault();
        avisar(mensajeDe(respuesta.data));
    });

    if (typeof window === 'undefined' || typeof window.fetch !== 'function') {
        return;
    }

    const fetchOriginal = window.fetch.bind(window);

    window.fetch = async (
        entrada: RequestInfo | URL,
        opciones?: RequestInit,
    ): Promise<Response> => {
        const respuesta = await fetchOriginal(entrada, opciones);

        if (respuesta.status === 403) {
            const url =
                entrada instanceof Request ? entrada.url : String(entrada);
            const mismoOrigen =
                new URL(url, window.location.href).origin ===
                window.location.origin;

            if (mismoOrigen) {
                const datos = await respuesta
                    .clone()
                    .json()
                    .catch(() => null);
                avisar(mensajeDe(datos));
            }
        }

        return respuesta;
    };
}
