import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CABECERA_SEGUNDO_PLANO } from '@/lib/avisoSinPermiso';
import { useReservaBorrador } from './useReservaBorrador';

/**
 * Regresión (2026-09): salir de "Nueva entrega" por el breadcrumb mostraba
 * "No tienes permiso para realizar esta acción." a quien sólo redistribuye:
 * al desmontar, el formulario liberaba SIEMPRE el apartado
 * (`DELETE /entregas/reserva/{token}`) aunque nunca hubiera reservado nada, y
 * ese 403 terminaba en el toast global.
 */
const rutas = {
    reservar: '/entregas/reserva',
    liberarBase: '/entregas/reserva',
    extenderBase: '/entregas/reserva',
};

describe('useReservaBorrador · liberar', () => {
    const fetchSimulado = vi.fn();

    beforeEach(() => {
        vi.stubGlobal('document', { cookie: '' });
        vi.stubGlobal('fetch', fetchSimulado);
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        fetchSimulado.mockReset();
        fetchSimulado.mockResolvedValue(
            new Response(
                JSON.stringify({
                    token: 't',
                    expira_en: '2099-01-01T00:00:00Z',
                    ok: true,
                }),
                { status: 200 },
            ),
        );
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

    it('libera lo apartado con el token actual, marcado como petición de segundo plano, una sola vez', async () => {
        const reserva = useReservaBorrador(rutas);
        await reserva.reservar({ activos: [] });

        reserva.liberar();
        reserva.liberar();

        const llamadasDelete = fetchSimulado.mock.calls.filter(
            ([, opciones]) => (opciones as RequestInit).method === 'DELETE',
        );
        expect(llamadasDelete).toHaveLength(1);
        expect(llamadasDelete[0][0]).toBe(
            `/entregas/reserva/${reserva.token.value}`,
        );
        expect(
            (llamadasDelete[0][1] as { headers: Record<string, string> })
                .headers[CABECERA_SEGUNDO_PLANO],
        ).toBe('1');
    });
});
