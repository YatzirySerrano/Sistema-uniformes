import { effectScope } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CABECERA_SEGUNDO_PLANO } from '@/lib/avisoSinPermiso';
import { useReservaBorrador } from './useReservaBorrador';

/**
 * Ciclo de vida del apartado temporal. Regresiones cubiertas:
 *  - (2026-09) salir de "Nueva entrega" mostraba "No tienes permiso…" a quien
 *    sólo redistribuye: la limpieza liberaba aunque nunca hubiera reservado.
 *  - (2026-10) reservas fantasma: recargar/cerrar la pestaña no liberaba, un
 *    recálculo tardío reabría lo liberado y la limpieza podía correr mientras
 *    se confirmaba.
 */
const rutas = {
    reservar: '/entregas/reserva',
    liberarBase: '/entregas/reserva',
    extenderBase: '/entregas/reserva',
};

type Llamada = [string, RequestInit];

function crearAlmacen(): Storage {
    const datos = new Map<string, string>();
    return {
        get length() {
            return datos.size;
        },
        clear: () => datos.clear(),
        getItem: (k: string) => datos.get(k) ?? null,
        key: (i: number) => Array.from(datos.keys())[i] ?? null,
        removeItem: (k: string) => void datos.delete(k),
        setItem: (k: string, v: string) => void datos.set(k, v),
    };
}

function respuestaOk(): Response {
    return new Response(
        JSON.stringify({
            token: 't',
            expira_en: '2099-01-01T00:00:00Z',
            ok: true,
        }),
        { status: 200 },
    );
}

describe('useReservaBorrador · ciclo de vida del apartado', () => {
    const fetchSimulado = vi.fn();
    let sesion: Storage;
    let oyentes: Record<string, (e: unknown) => void>;

    const deletes = (): Llamada[] =>
        (fetchSimulado.mock.calls as Llamada[]).filter(
            ([, o]) => o.method === 'DELETE',
        );
    const posts = (): Llamada[] =>
        (fetchSimulado.mock.calls as Llamada[]).filter(
            ([url, o]) => o.method === 'POST' && url === rutas.reservar,
        );

    beforeEach(() => {
        sesion = crearAlmacen();
        oyentes = {};
        vi.stubGlobal('document', { cookie: '' });
        vi.stubGlobal('window', {
            sessionStorage: sesion,
            addEventListener: (n: string, f: (e: unknown) => void) => {
                oyentes[n] = f;
            },
            removeEventListener: (n: string) => {
                delete oyentes[n];
            },
        });
        vi.stubGlobal('fetch', fetchSimulado);
        fetchSimulado.mockReset();
        fetchSimulado.mockImplementation(async () => respuestaOk());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('no dispara ninguna petición si el formulario nunca apartó nada', () => {
        const reserva = useReservaBorrador(rutas);

        reserva.liberar();
        reserva.reiniciarToken();

        expect(fetchSimulado).not.toHaveBeenCalled();
    });

    it('Cancelar libera el token apartado una sola vez, en segundo plano, y empieza con un token nuevo', async () => {
        const reserva = useReservaBorrador(rutas);
        await reserva.reservar({ activos: [] });
        const apartado = reserva.token.value;

        reserva.liberar();
        reserva.liberar();

        expect(deletes()).toHaveLength(1);
        expect(deletes()[0][0]).toBe(`/entregas/reserva/${apartado}`);
        expect(deletes()[0][1].keepalive).toBe(true);
        expect(
            (deletes()[0][1].headers as Record<string, string>)[
                CABECERA_SEGUNDO_PLANO
            ],
        ).toBe('1');
        // El token liberado queda cerrado: nunca se reutiliza.
        expect(reserva.token.value).not.toBe(apartado);
    });

    it('al desmontar (navegar) libera; mientras se confirma no libera nada', async () => {
        const scopeA = effectScope();
        const a = scopeA.run(() => useReservaBorrador(rutas))!;
        await a.reservar({ activos: [] });
        scopeA.stop();
        expect(deletes()).toHaveLength(1);

        fetchSimulado.mockClear();
        const scopeB = effectScope();
        const b = scopeB.run(() => useReservaBorrador(rutas))!;
        await b.reservar({ activos: [] });
        b.iniciarConfirmacion();
        b.liberar({ automatico: true });
        oyentes.pagehide?.({});
        scopeB.stop();

        expect(deletes()).toHaveLength(0);
        // Y no deja nada pendiente para limpiar tras recargar.
        expect(sesion.length).toBe(0);
    });

    it('mientras se confirma no recalcula (ni debounced) para no tocar el apartado que se consume', async () => {
        vi.useFakeTimers();
        const reserva = useReservaBorrador(rutas);
        await reserva.reservar({ activos: [] });
        fetchSimulado.mockClear();

        reserva.reservarConRetraso({ activos: [1] });
        reserva.iniciarConfirmacion();
        reserva.reservarConRetraso({ activos: [2] });
        await vi.advanceTimersByTimeAsync(1000);

        expect(posts()).toHaveLength(0);
        vi.useRealTimers();
    });

    it('pagehide (recargar/cerrar) libera con keepalive', async () => {
        const reserva = useReservaBorrador(rutas);
        await reserva.reservar({ activos: [] });
        const apartado = reserva.token.value;

        oyentes.pagehide?.({ persisted: false });

        expect(deletes()).toHaveLength(1);
        expect(deletes()[0][0]).toBe(`/entregas/reserva/${apartado}`);
        expect(deletes()[0][1].keepalive).toBe(true);
    });

    it('al volver del bfcache re-aparta lo que sigue en pantalla con un token nuevo', async () => {
        const reserva = useReservaBorrador(rutas);
        await reserva.reservar({ activos: [5] });
        const apartado = reserva.token.value;

        oyentes.pagehide?.({ persisted: true });
        oyentes.pageshow?.({ persisted: true });
        await Promise.resolve();

        const ultima = posts().at(-1)!;
        const cuerpo = JSON.parse(ultima[1].body as string);
        expect(cuerpo.token).toBe(reserva.token.value);
        expect(cuerpo.token).not.toBe(apartado);
        expect(cuerpo.activos).toEqual([5]);
    });

    it('si la carga anterior de esta pestaña dejó un apartado (recarga sin pagehide), lo libera al abrir', async () => {
        const anterior = useReservaBorrador(rutas);
        await anterior.reservar({ activos: [] });
        const tokenAnterior = anterior.token.value;
        // La pestaña se recargó sin que llegara la limpieza: el estado vivo
        // se pierde, pero `sessionStorage` sobrevive.
        fetchSimulado.mockClear();

        const nueva = useReservaBorrador(rutas);

        expect(deletes()).toHaveLength(1);
        expect(deletes()[0][0]).toBe(`/entregas/reserva/${tokenAnterior}`);
        expect(nueva.token.value).not.toBe(tokenAnterior);
    });

    it('si la carga anterior se estaba confirmando, no la libera (lo decide el backend)', async () => {
        const anterior = useReservaBorrador(rutas);
        await anterior.reservar({ activos: [] });
        anterior.iniciarConfirmacion();
        fetchSimulado.mockClear();

        useReservaBorrador(rutas);

        expect(deletes()).toHaveLength(0);
    });

    it('un recálculo en vuelo al liberar se vuelve a liberar al terminar y su respuesta se descarta', async () => {
        let resolver!: (r: Response) => void;
        fetchSimulado.mockImplementationOnce(
            () =>
                new Promise<Response>((r) => {
                    resolver = r;
                }),
        );
        const reserva = useReservaBorrador(rutas);
        const enVuelo = reserva.reservar({ activos: [] });
        const apartado = (
            JSON.parse(posts()[0][1].body as string) as { token: string }
        ).token;

        reserva.liberar();
        resolver(respuestaOk());
        await enVuelo;
        await Promise.resolve();

        expect(deletes().map(([url]) => url)).toEqual([
            `/entregas/reserva/${apartado}`,
            `/entregas/reserva/${apartado}`,
        ]);
        expect(reserva.resultado.value).toBeNull();
        expect(reserva.expiraEn.value).toBeNull();
    });

    it('una limpieza fallida (403, red) no lanza ni deja estado roto', async () => {
        const reserva = useReservaBorrador(rutas);
        await reserva.reservar({ activos: [] });
        fetchSimulado.mockImplementation(
            async () => new Response('{}', { status: 403 }),
        );

        expect(() => reserva.liberar({ automatico: true })).not.toThrow();
        fetchSimulado.mockImplementation(async () => {
            throw new TypeError('red');
        });
        await reserva.reservar({ activos: [] });
        expect(() => reserva.liberar()).not.toThrow();
    });
});
