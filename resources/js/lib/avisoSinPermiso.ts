import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

const MENSAJE_GENERAL = 'No tienes permiso para realizar esta acción.';

/**
 * Marca una petición interna best-effort (limpieza al salir de una pantalla,
 * p. ej. liberar un apartado temporal): no es una acción del usuario, así que
 * su 403 no se le avisa. Sólo la usan peticiones de limpieza; los 403 de
 * acciones y buscadores reales siguen mostrándose.
 */
export const CABECERA_SEGUNDO_PLANO = 'X-Peticion-Segundo-Plano';

function esSegundoPlano(
    entrada: RequestInfo | URL,
    opciones?: RequestInit,
): boolean {
    const cabeceras = new Headers(
        opciones?.headers ??
            (entrada instanceof Request ? entrada.headers : undefined),
    );

    return cabeceras.get(CABECERA_SEGUNDO_PLANO) === '1';
}

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
 * Las navegaciones (GET) reciben la página `Errores/SinPermiso`. Las
 * peticiones marcadas con `CABECERA_SEGUNDO_PLANO` no avisan.
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

        if (respuesta.status === 403 && !esSegundoPlano(entrada, opciones)) {
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
