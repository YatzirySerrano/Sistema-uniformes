import { getCurrentScope, onScopeDispose } from 'vue';

/** Cada cuánto se vuelve a leer la disponibilidad con la pestaña visible. */
export const INTERVALO_DISPONIBILIDAD_MS = 8000;
/** `focus` y `visibilitychange` suelen llegar juntos: uno solo basta. */
const MARGEN_EVENTOS_MS = 1000;

function pestanaVisible(): boolean {
    return (
        typeof document === 'undefined' ||
        document.visibilityState === undefined ||
        document.visibilityState === 'visible'
    );
}

/**
 * Mantiene "suficientemente fresca" la disponibilidad que muestra un
 * formulario con apartado temporal (Entregas, Traspasos, Devoluciones) sin
 * WebSockets ni recargar la página: relee cada ~8 s mientras la pestaña está
 * visible y `habilitado()` (formulario en el paso que la usa, con contexto y
 * sin estar confirmando), y de inmediato al volver a la pestaña.
 *
 * Es SÓLO lectura: `consultar` debe pegar a un endpoint de disponibilidad
 * (GET), nunca al de reservar — así el sondeo jamás crea, libera ni renueva
 * (TTL) un apartado; "Extender" sigue siendo una acción explícita.
 *
 * Sin peticiones duplicadas ni respuestas viejas: el tick se salta si hay
 * una lectura en vuelo; un `refrescar()` explícito la aborta y la reemplaza;
 * cada respuesta se aplica sólo si sigue siendo la más reciente (contador) y
 * el formulario sigue habilitado. `invalidar()` descarta la lectura en vuelo
 * cuando llega un dato aún más fresco por otra vía (la respuesta de
 * reservar). Un fallo de red se ignora: el formulario se queda como estaba.
 */
export function useDisponibilidadViva<T>(opciones: {
    consultar: (signal: AbortSignal) => Promise<T | null>;
    aplicar: (datos: T) => void;
    habilitado: () => boolean;
    intervaloMs?: number;
}) {
    let secuencia = 0;
    let controlador: AbortController | null = null;
    let enVuelo = false;
    let ultimoInicio = 0;

    function invalidar(): void {
        secuencia++;
        controlador?.abort();
        controlador = null;
        enVuelo = false;
    }

    async function refrescar(): Promise<void> {
        if (!opciones.habilitado() || !pestanaVisible()) return;

        invalidar();
        const miSecuencia = secuencia;
        const miControlador = new AbortController();
        controlador = miControlador;
        enVuelo = true;
        ultimoInicio = Date.now();

        try {
            const datos = await opciones.consultar(miControlador.signal);
            if (
                miSecuencia !== secuencia ||
                datos === null ||
                !opciones.habilitado()
            ) {
                return;
            }
            opciones.aplicar(datos);
        } catch {
            // Red caída o abortada: se conserva lo que había en pantalla.
        } finally {
            if (miSecuencia === secuencia) {
                enVuelo = false;
                controlador = null;
            }
        }
    }

    function alPulso(): void {
        if (enVuelo) return;
        void refrescar();
    }

    function alVolver(): void {
        if (!pestanaVisible()) return;
        if (Date.now() - ultimoInicio < MARGEN_EVENTOS_MS) return;
        void refrescar();
    }

    const intervalo = setInterval(
        alPulso,
        opciones.intervaloMs ?? INTERVALO_DISPONIBILIDAD_MS,
    );

    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('visibilitychange', alVolver);
    }
    if (typeof window !== 'undefined' && window.addEventListener) {
        window.addEventListener('focus', alVolver);
    }

    function detener(): void {
        clearInterval(intervalo);
        invalidar();
        if (typeof document !== 'undefined' && document.removeEventListener) {
            document.removeEventListener('visibilitychange', alVolver);
        }
        if (typeof window !== 'undefined' && window.removeEventListener) {
            window.removeEventListener('focus', alVolver);
        }
    }

    if (getCurrentScope()) {
        onScopeDispose(detener);
    }

    return { refrescar, invalidar, detener };
}
