import { effectScope } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    INTERVALO_DISPONIBILIDAD_MS,
    useDisponibilidadViva,
} from './useDisponibilidadViva';

/**
 * Disponibilidad "viva" entre dos sesiones (QA 2026-10): el usuario B veía
 * la cifra congelada hasta recargar. El sondeo es sólo lectura, se pausa con
 * la pestaña oculta o al confirmar, y nunca deja que una respuesta vieja
 * pise una más reciente.
 */
describe('useDisponibilidadViva', () => {
    let oyentesDocumento: Record<string, () => void>;
    let oyentesVentana: Record<string, () => void>;
    let documentoSimulado: { visibilityState: string };

    beforeEach(() => {
        vi.useFakeTimers();
        oyentesDocumento = {};
        oyentesVentana = {};
        documentoSimulado = {
            visibilityState: 'visible',
            addEventListener: (n: string, f: () => void) => {
                oyentesDocumento[n] = f;
            },
            removeEventListener: (n: string) => {
                delete oyentesDocumento[n];
            },
        } as unknown as { visibilityState: string };
        vi.stubGlobal('document', documentoSimulado);
        vi.stubGlobal('window', {
            addEventListener: (n: string, f: () => void) => {
                oyentesVentana[n] = f;
            },
            removeEventListener: (n: string) => {
                delete oyentesVentana[n];
            },
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    function montar(
        consultar: (signal: AbortSignal) => Promise<number | null>,
        habilitado = () => true,
    ) {
        const pantalla = { disponible: 10 };
        const scope = effectScope();
        const viva = scope.run(() =>
            useDisponibilidadViva<number>({
                consultar,
                aplicar: (n) => {
                    pantalla.disponible = n;
                },
                habilitado,
            }),
        )!;

        return { pantalla, viva, scope };
    }

    it('actualiza 10 → 9 → 1 en cada pulso sin recargar', async () => {
        const respuestas = [9, 1];
        const consultar = vi.fn(async () => respuestas.shift() ?? null);
        const { pantalla, scope } = montar(consultar);

        await vi.advanceTimersByTimeAsync(INTERVALO_DISPONIBILIDAD_MS);
        expect(pantalla.disponible).toBe(9);

        await vi.advanceTimersByTimeAsync(INTERVALO_DISPONIBILIDAD_MS);
        expect(pantalla.disponible).toBe(1);
        expect(consultar).toHaveBeenCalledTimes(2);
        scope.stop();
    });

    it('con la pestaña oculta no consulta; al volver visible consulta de inmediato y una sola vez', async () => {
        const consultar = vi.fn(async () => 9);
        const { pantalla, scope } = montar(consultar);
        documentoSimulado.visibilityState = 'hidden';

        await vi.advanceTimersByTimeAsync(INTERVALO_DISPONIBILIDAD_MS * 3);
        expect(consultar).not.toHaveBeenCalled();

        documentoSimulado.visibilityState = 'visible';
        oyentesDocumento.visibilitychange();
        oyentesVentana.focus(); // llega junto: no duplica
        await vi.advanceTimersByTimeAsync(0);

        expect(consultar).toHaveBeenCalledTimes(1);
        expect(pantalla.disponible).toBe(9);
        scope.stop();
    });

    it('mientras se confirma (habilitado = false) no consulta, ni aplica una respuesta que llega después', async () => {
        let confirmando = false;
        let resolver: (n: number) => void = () => {};
        const consultar = vi.fn(
            () => new Promise<number>((r) => (resolver = r)),
        );
        const { pantalla, viva, scope } = montar(consultar, () => !confirmando);

        void viva.refrescar();
        confirmando = true;
        resolver(0);
        await vi.advanceTimersByTimeAsync(INTERVALO_DISPONIBILIDAD_MS * 2);

        expect(consultar).toHaveBeenCalledTimes(1);
        expect(pantalla.disponible).toBe(10);
        scope.stop();
    });

    it('no solapa: el pulso se salta si la lectura anterior sigue en vuelo', async () => {
        const consultar = vi.fn(() => new Promise<number>(() => {}));
        const { scope } = montar(consultar);

        await vi.advanceTimersByTimeAsync(INTERVALO_DISPONIBILIDAD_MS * 3);

        expect(consultar).toHaveBeenCalledTimes(1);
        scope.stop();
    });

    it('una respuesta vieja nunca pisa una más reciente', async () => {
        const pendientes: ((n: number) => void)[] = [];
        const consultar = vi.fn(
            () => new Promise<number>((r) => pendientes.push(r)),
        );
        const { pantalla, viva, scope } = montar(consultar);

        void viva.refrescar(); // A
        void viva.refrescar(); // B (aborta y reemplaza a A)
        pendientes[1](1);
        await vi.advanceTimersByTimeAsync(0);
        pendientes[0](9); // A responde tarde
        await vi.advanceTimersByTimeAsync(0);

        expect(pantalla.disponible).toBe(1);
        scope.stop();
    });

    it('invalidar() (llegó la respuesta de apartar, más fresca) descarta la lectura en vuelo', async () => {
        let resolver: (n: number) => void = () => {};
        const { pantalla, viva, scope } = montar(
            () => new Promise<number>((r) => (resolver = r)),
        );

        void viva.refrescar();
        pantalla.disponible = 1; // la respuesta del rechazo ya actualizó la pantalla
        viva.invalidar();
        resolver(9);
        await vi.advanceTimersByTimeAsync(0);

        expect(pantalla.disponible).toBe(1);
        scope.stop();
    });

    it('un fallo de red no toca la pantalla y el siguiente pulso vuelve a intentar', async () => {
        const consultar = vi
            .fn<(s: AbortSignal) => Promise<number | null>>()
            .mockRejectedValueOnce(new Error('red'))
            .mockResolvedValueOnce(null) // HTTP no OK
            .mockResolvedValueOnce(7);
        const { pantalla, scope } = montar(consultar);

        await vi.advanceTimersByTimeAsync(INTERVALO_DISPONIBILIDAD_MS);
        expect(pantalla.disponible).toBe(10);
        await vi.advanceTimersByTimeAsync(INTERVALO_DISPONIBILIDAD_MS);
        expect(pantalla.disponible).toBe(10);
        await vi.advanceTimersByTimeAsync(INTERVALO_DISPONIBILIDAD_MS);
        expect(pantalla.disponible).toBe(7);
        scope.stop();
    });

    it('cada lectura usa el token vigente del borrador (nunca uno ya rotado)', async () => {
        let token = 'token-1';
        const urls: string[] = [];
        const { viva, scope } = montar(async () => {
            urls.push(`/entregas/disponibilidad?token=${token}`);
            return 10;
        });

        await viva.refrescar();
        token = 'token-2';
        await viva.refrescar();

        expect(urls).toEqual([
            '/entregas/disponibilidad?token=token-1',
            '/entregas/disponibilidad?token=token-2',
        ]);
        scope.stop();
    });

    it('al desmontar deja de consultar y quita sus oyentes', async () => {
        const consultar = vi.fn(async () => 9);
        const { scope } = montar(consultar);
        scope.stop();

        await vi.advanceTimersByTimeAsync(INTERVALO_DISPONIBILIDAD_MS * 2);

        expect(consultar).not.toHaveBeenCalled();
        expect(oyentesDocumento.visibilitychange).toBeUndefined();
        expect(oyentesVentana.focus).toBeUndefined();
    });
});
