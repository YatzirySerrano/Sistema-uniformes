import { beforeEach, describe, expect, it, vi } from 'vitest';

const toastError = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { error: toastError } }));
vi.mock('@inertiajs/vue3', () => ({ router: { on: vi.fn() } }));

/**
 * El aviso global de 403 debe seguir apareciendo en acciones y buscadores
 * reales, y callar SÓLO en la limpieza interna marcada como segundo plano
 * (p. ej. liberar un apartado al salir de un formulario).
 */
describe('aviso global de 403 en fetch', () => {
    const fetchReal = vi.fn();

    beforeEach(async () => {
        vi.resetModules();
        toastError.mockReset();
        fetchReal.mockReset();
        fetchReal.mockImplementation(
            async () =>
                new Response(
                    JSON.stringify({ message: 'Sin acceso a ese almacén.' }),
                    {
                        status: 403,
                    },
                ),
        );
        vi.stubGlobal('window', {
            fetch: fetchReal,
            location: {
                href: 'http://app.test/entregas/crear',
                origin: 'http://app.test',
            },
        });

        const { initializeAvisoSinPermiso } = await import('./avisoSinPermiso');
        initializeAvisoSinPermiso();
    });

    it('avisa un 403 real con el mensaje del backend', async () => {
        await window.fetch('/almacenes/buscar?q=x');

        expect(toastError).toHaveBeenCalledWith('Sin acceso a ese almacén.');
    });

    it('no avisa el 403 de una petición de limpieza en segundo plano', async () => {
        const { CABECERA_SEGUNDO_PLANO } = await import('./avisoSinPermiso');

        const respuesta = await window.fetch('/entregas/reserva/abc', {
            method: 'DELETE',
            headers: { [CABECERA_SEGUNDO_PLANO]: '1' },
        });

        expect(respuesta.status).toBe(403);
        expect(toastError).not.toHaveBeenCalled();
    });
});
