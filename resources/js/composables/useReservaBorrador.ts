import { computed, onBeforeUnmount, ref } from 'vue';
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

function generarToken(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(16).slice(2)}-${Date.now()}`.replace(
              /^(\w{8})-?(\w{4})-?(\w{4})-?(\w{4})-?(\w+)$/,
              '$1-$2-$3-$4-$5',
          );
}

/**
 * Ciclo de vida del apartado temporal (TTL) de un borrador de Entrega o
 * Devolución: token de operación, countdown, extensión EXPLÍCITA (nunca
 * heartbeat automático), y liberación best-effort al desmontar (nunca
 * depende de `beforeunload` ni de que el usuario pulse "Cancelar" — el TTL de
 * `Reserva::DURACION_MINUTOS` en backend es la garantía real).
 *
 * Compartido por `Entregas/Crear.vue` y `Devoluciones/Crear.vue` para no
 * duplicar el mismo ciclo de vida dos veces.
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

    let temporizadorCountdown: ReturnType<typeof setInterval> | null = null;
    let temporizadorDebounce: ReturnType<typeof setTimeout> | null = null;

    function detenerCountdown(): void {
        if (temporizadorCountdown !== null) {
            clearInterval(temporizadorCountdown);
            temporizadorCountdown = null;
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
     * debounced.
     */
    async function reservar(
        payload: Record<string, unknown>,
    ): Promise<T | null> {
        if (temporizadorDebounce !== null) {
            clearTimeout(temporizadorDebounce);
            temporizadorDebounce = null;
        }

        cargando.value = true;
        error.value = null;

        try {
            const res = await fetch(rutas.reservar, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ token: token.value, ...payload }),
            });

            if (!res.ok) {
                error.value =
                    'No pudimos actualizar el apartado de existencias. Vuelve a intentarlo.';
                return null;
            }

            const json = (await res.json()) as T;
            resultado.value = json;
            expiraEn.value = json.expira_en;
            vencida.value = false;
            iniciarCountdown();

            return json;
        } catch {
            error.value =
                'No pudimos actualizar el apartado de existencias. Revisa tu conexión.';
            return null;
        } finally {
            cargando.value = false;
        }
    }

    /** Igual que `reservar`, pero con debounce — para llamarse en caliente mientras el usuario edita. */
    function reservarConRetraso(
        payload: Record<string, unknown>,
        ms = 500,
    ): void {
        if (temporizadorDebounce !== null) clearTimeout(temporizadorDebounce);
        temporizadorDebounce = setTimeout(() => {
            void reservar(payload);
        }, ms);
    }

    /** Libera la reserva actual. Best-effort: no bloquea ni falla la UI si no responde. */
    function liberar(): void {
        detenerCountdown();
        if (temporizadorDebounce !== null) {
            clearTimeout(temporizadorDebounce);
            temporizadorDebounce = null;
        }
        const t = token.value;
        void fetch(`${rutas.liberarBase}/${t}`, {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            keepalive: true,
        }).catch(() => {
            // Best-effort: el TTL de 10 minutos libera la reserva de todas formas.
        });
        resultado.value = null;
        expiraEn.value = null;
        vencida.value = false;
        segundosRestantes.value = DURACION_MINUTOS * 60;
    }

    /** Libera la reserva vigente (si la hay) y empieza un borrador nuevo con otro token — usar al cambiar de almacén/empresa/colaborador origen. */
    function reiniciarToken(): void {
        liberar();
        token.value = generarToken();
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

    onBeforeUnmount(() => {
        // Best-effort: nunca depende de `beforeunload`; el TTL en backend es
        // la garantía real si el usuario cierra la pestaña en vez de navegar
        // dentro de la SPA.
        liberar();
    });

    return {
        token,
        resultado,
        expiraEn,
        cargando,
        error,
        vencida,
        segundosRestantes,
        minutosSegundos,
        porVencer,
        reservar,
        reservarConRetraso,
        liberar,
        reiniciarToken,
        extender,
    };
}
