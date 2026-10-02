import { computed, getCurrentScope, onScopeDispose, ref } from 'vue';
import { CABECERA_SEGUNDO_PLANO } from '@/lib/avisoSinPermiso';
import { xsrfToken } from '@/lib/utils';

/** Duración de una reserva nueva o recién extendida (debe igualar `Reserva::DURACION_MINUTOS`). */
const DURACION_MINUTOS = 10;
/** A partir de aquí se ofrece "Extender" de forma visible. */
const SEGUNDOS_POR_VENCER = 90;

export interface RespuestaReserva {
    token: string;
    expira_en: string;
    ok: boolean;
}

/** Lo que se guarda por pestaña para poder limpiar tras una recarga. */
interface ApartadoPendiente {
    token: string;
    /** `true` mientras la operación se confirma: nunca se libera por limpieza. */
    confirmando: boolean;
}

function generarToken(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(16).slice(2)}-${Date.now()}`.replace(
              /^(\w{8})-?(\w{4})-?(\w{4})-?(\w{4})-?(\w+)$/,
              '$1-$2-$3-$4-$5',
          );
}

/** `sessionStorage` sólo si existe y es usable (navegación privada, tests). */
function almacenSesion(): Storage | null {
    try {
        return typeof window !== 'undefined' && window.sessionStorage
            ? window.sessionStorage
            : null;
    } catch {
        return null;
    }
}

/**
 * Ciclo de vida del apartado temporal (TTL) de un borrador de Entrega,
 * Traspaso o Devolución. Reservar sólo bloquea disponibilidad lógica en el
 * backend; liberar sólo quita ese bloqueo (nunca devuelve stock: nunca se
 * descontó). El backend es la autoridad: un token liberado o consumido ya no
 * se puede reactivar (`ServicioReservas::obtenerOCrearCabecera()`) y liberar
 * es idempotente.
 *
 * Cuándo se libera (siempre best-effort, en segundo plano, sin toasts):
 *  - Cancelar / cambiar de contexto: `liberar()` / `reiniciarToken()`.
 *  - Salir de la pantalla dentro de la SPA (enlace, Atrás): al desmontar.
 *  - Recargar o cerrar la pestaña: en `pagehide`, con `fetch(…, {keepalive})`
 *    (permite DELETE + cabecera XSRF; `sendBeacon` sólo hace POST). Si el
 *    navegador no alcanza a enviarlo, el token quedó en `sessionStorage` y se
 *    libera al volver a abrir el formulario en esa pestaña; el TTL de 10 min
 *    es el último respaldo, no el mecanismo normal.
 *
 * El formulario NO restaura su borrador al recargar, así que nunca se
 * reutiliza el token anterior: se libera y se empieza con uno nuevo (nunca
 * reserva vieja + reserva nueva para la misma pestaña).
 *
 * Mientras se CONFIRMA (`iniciarConfirmacion()` → `finalizarConfirmacion()`)
 * ninguna limpieza automática libera el apartado: lo está consumiendo la
 * transacción de confirmación.
 */
export function useReservaBorrador<T extends RespuestaReserva>(rutas: {
    reservar: string;
    liberarBase: string;
    extenderBase: string;
}) {
    const token = ref(generarToken());
    const resultado = ref<T | null>(null);
    const expiraEn = ref<string | null>(null);
    const cargando = ref(false);
    const error = ref<string | null>(null);
    const vencida = ref(false);
    const segundosRestantes = ref(DURACION_MINUTOS * 60);
    const confirmando = ref(false);

    const claveSesion = `reserva-borrador:${rutas.reservar}`;

    // Sólo se libera lo que de verdad se pidió apartar con el token actual:
    // un formulario que nunca reservó (p. ej. modo redistribución, o salir
    // sin capturar nada) no dispara ningún DELETE.
    let tokenConApartado = false;
    // Recálculo en vuelo: si se libera mientras tanto, se vuelve a liberar
    // cuando termine (el primer POST pudo crear la fila DESPUÉS del DELETE).
    let peticionEnCurso: Promise<unknown> | null = null;
    // Para re-apartar si la página vuelve del bfcache (`pageshow.persisted`).
    let ultimoPayload: Record<string, unknown> | null = null;
    let liberadaAlOcultar = false;

    let temporizadorCountdown: ReturnType<typeof setInterval> | null = null;
    let temporizadorDebounce: ReturnType<typeof setTimeout> | null = null;

    function guardarPendiente(): void {
        const sesion = almacenSesion();
        if (!sesion) return;
        try {
            if (tokenConApartado) {
                sesion.setItem(
                    claveSesion,
                    JSON.stringify({
                        token: token.value,
                        confirmando: confirmando.value,
                    } satisfies ApartadoPendiente),
                );
            } else {
                sesion.removeItem(claveSesion);
            }
        } catch {
            // Sin almacenamiento disponible: queda el TTL como respaldo.
        }
    }

    function enviarLiberacion(tokenALiberar: string): void {
        void fetch(`${rutas.liberarBase}/${tokenALiberar}`, {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
                // Limpieza interna, no una acción del usuario: si falla
                // (incluido un 403 o una sesión ya cerrada) no se le avisa.
                [CABECERA_SEGUNDO_PLANO]: '1',
            },
            credentials: 'same-origin',
            // Sobrevive a recargar/cerrar la pestaña (pagehide).
            keepalive: true,
        }).catch(() => {
            // Best-effort: el TTL de 10 minutos libera la reserva de todas formas.
        });
    }

    // Recarga / pestaña reabierta: lo que la carga anterior dejó apartado en
    // ESTA pestaña ya no está en pantalla → se libera ahora (salvo que se
    // estuviera confirmando: eso lo resuelve el backend).
    (function liberarPendienteDeCargaAnterior(): void {
        const sesion = almacenSesion();
        if (!sesion) return;
        try {
            const crudo = sesion.getItem(claveSesion);
            sesion.removeItem(claveSesion);
            if (!crudo) return;
            const previo = JSON.parse(crudo) as Partial<ApartadoPendiente>;
            if (typeof previo.token === 'string' && !previo.confirmando) {
                enviarLiberacion(previo.token);
            }
        } catch {
            // Dato corrupto: se ignora; el TTL lo cubre.
        }
    })();

    function detenerCountdown(): void {
        if (temporizadorCountdown !== null) {
            clearInterval(temporizadorCountdown);
            temporizadorCountdown = null;
        }
    }

    function cancelarDebounce(): void {
        if (temporizadorDebounce !== null) {
            clearTimeout(temporizadorDebounce);
            temporizadorDebounce = null;
        }
    }

    function iniciarCountdown(): void {
        detenerCountdown();
        temporizadorCountdown = setInterval(() => {
            if (!expiraEn.value) {
                segundosRestantes.value = 0;
                return;
            }
            const restante = Math.floor(
                (new Date(expiraEn.value).getTime() - Date.now()) / 1000,
            );
            segundosRestantes.value = Math.max(0, restante);
            if (restante <= 0) {
                vencida.value = true;
                detenerCountdown();
            }
        }, 1000);
    }

    /**
     * Recalcula la reserva de inmediato (sin debounce) y devuelve la
     * respuesta completa — usarlo antes de avanzar al paso de firmas, donde
     * hace falta el resultado fresco de forma síncrona, no la última versión
     * debounced. Una respuesta que llega después de liberar/cambiar de token
     * se descarta.
     */
    async function reservar(
        payload: Record<string, unknown>,
    ): Promise<T | null> {
        cancelarDebounce();
        if (confirmando.value) return null;

        const tokenPeticion = token.value;
        ultimoPayload = payload;
        cargando.value = true;
        error.value = null;
        tokenConApartado = true;
        guardarPendiente();

        const peticion = (async (): Promise<T | null> => {
            try {
                const res = await fetch(rutas.reservar, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ token: tokenPeticion, ...payload }),
                });

                if (token.value !== tokenPeticion) return null;

                if (!res.ok) {
                    error.value =
                        'No pudimos actualizar el apartado de existencias. Vuelve a intentarlo.';
                    return null;
                }

                const json = (await res.json()) as T;
                if (token.value !== tokenPeticion) return null;

                resultado.value = json;
                expiraEn.value = json.expira_en;
                vencida.value = false;
                iniciarCountdown();

                return json;
            } catch {
                if (token.value === tokenPeticion) {
                    error.value =
                        'No pudimos actualizar el apartado de existencias. Revisa tu conexión.';
                }
                return null;
            } finally {
                if (token.value === tokenPeticion) cargando.value = false;
            }
        })();

        peticionEnCurso = peticion;
        void peticion.finally(() => {
            if (peticionEnCurso === peticion) peticionEnCurso = null;
        });

        return peticion;
    }

    /** Igual que `reservar`, pero con debounce — para llamarse en caliente mientras el usuario edita. */
    function reservarConRetraso(
        payload: Record<string, unknown>,
        ms = 500,
    ): void {
        if (confirmando.value) return;
        cancelarDebounce();
        temporizadorDebounce = setTimeout(() => {
            void reservar(payload);
        }, ms);
    }

    /**
     * Libera la reserva actual y deja el borrador con un token NUEVO (el
     * liberado queda cerrado en el backend y nunca se reutiliza). Idempotente
     * y best-effort: no bloquea ni falla la UI si no responde.
     *
     * `automatico` = limpieza que no pidió el usuario (desmontar, pagehide):
     * se omite mientras se está confirmando la operación.
     */
    function liberar(opciones: { automatico?: boolean } = {}): void {
        if (opciones.automatico && confirmando.value) return;

        detenerCountdown();
        cancelarDebounce();

        if (tokenConApartado) {
            const tokenLiberado = token.value;
            tokenConApartado = false;
            enviarLiberacion(tokenLiberado);
            if (peticionEnCurso !== null) {
                void peticionEnCurso.finally(() =>
                    enviarLiberacion(tokenLiberado),
                );
            }
            token.value = generarToken();
        }

        guardarPendiente();
        resultado.value = null;
        expiraEn.value = null;
        vencida.value = false;
        cargando.value = false;
        segundosRestantes.value = DURACION_MINUTOS * 60;
    }

    /** Libera la reserva vigente (si la hay) y empieza un borrador nuevo con otro token — usar al cambiar de almacén/empresa/colaborador origen. */
    function reiniciarToken(): void {
        liberar();
        token.value = generarToken();
    }

    /**
     * La operación se está confirmando con este token: a partir de aquí la
     * transacción del backend lo consume y ninguna limpieza automática lo
     * libera. Llamar `finalizarConfirmacion()` cuando la petición termine
     * (éxito o error); si tuvo éxito, el backend ya lo marcó consumido y
     * liberarlo después es un no-op.
     */
    function iniciarConfirmacion(): void {
        cancelarDebounce();
        confirmando.value = true;
        guardarPendiente();
    }

    function finalizarConfirmacion(): void {
        confirmando.value = false;
        guardarPendiente();
    }

    /** Extensión EXPLÍCITA de +10 min pedida por el usuario — nunca automática. */
    async function extender(): Promise<boolean> {
        try {
            const res = await fetch(
                `${rutas.extenderBase}/${token.value}/extender`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                    credentials: 'same-origin',
                },
            );
            if (!res.ok) return false;
            const json = (await res.json()) as {
                token: string;
                expira_en: string;
            };
            expiraEn.value = json.expira_en;
            vencida.value = false;
            iniciarCountdown();

            return true;
        } catch {
            return false;
        }
    }

    const minutosSegundos = computed(() => {
        const m = Math.floor(segundosRestantes.value / 60)
            .toString()
            .padStart(2, '0');
        const s = (segundosRestantes.value % 60).toString().padStart(2, '0');

        return `${m}:${s}`;
    });

    const porVencer = computed(
        () =>
            !vencida.value &&
            expiraEn.value !== null &&
            segundosRestantes.value > 0 &&
            segundosRestantes.value <= SEGUNDOS_POR_VENCER,
    );

    // Recargar / cerrar la pestaña: `pagehide` (no `beforeunload`, que no
    // dispara de forma fiable en móvil y bloquea el bfcache).
    function alOcultarPagina(): void {
        liberadaAlOcultar = tokenConApartado && !confirmando.value;
        liberar({ automatico: true });
    }

    // Volver con Atrás/Adelante desde el bfcache: el formulario reaparece
    // intacto pero su apartado se liberó al ocultarse → se vuelve a apartar
    // (token nuevo) lo que está en pantalla.
    function alMostrarPagina(evento: PageTransitionEvent): void {
        if (evento.persisted && liberadaAlOcultar && ultimoPayload !== null) {
            void reservar(ultimoPayload);
        }
        liberadaAlOcultar = false;
    }

    if (typeof window !== 'undefined' && window.addEventListener) {
        window.addEventListener('pagehide', alOcultarPagina);
        window.addEventListener('pageshow', alMostrarPagina);
    }

    function desmontar(): void {
        if (typeof window !== 'undefined' && window.removeEventListener) {
            window.removeEventListener('pagehide', alOcultarPagina);
            window.removeEventListener('pageshow', alMostrarPagina);
        }

        if (confirmando.value) {
            // Se sale porque la confirmación terminó (o el usuario se fue
            // mientras se confirmaba): el backend decide; nada que liberar.
            detenerCountdown();
            cancelarDebounce();
            tokenConApartado = false;
            confirmando.value = false;
            guardarPendiente();
            return;
        }

        // Navegar dentro de la SPA (enlace, Atrás, breadcrumb).
        liberar({ automatico: true });
    }

    // `onScopeDispose` = desmontar el componente (y permite probar el
    // composable dentro de un `effectScope`).
    if (getCurrentScope()) {
        onScopeDispose(desmontar);
    }

    return {
        token,
        resultado,
        expiraEn,
        cargando,
        error,
        vencida,
        confirmando,
        segundosRestantes,
        minutosSegundos,
        porVencer,
        reservar,
        reservarConRetraso,
        liberar,
        reiniciarToken,
        iniciarConfirmacion,
        finalizarConfirmacion,
        extender,
    };
}
