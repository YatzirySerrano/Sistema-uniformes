import { describe, expect, it } from 'vitest';
import { urlExportacion } from './urlExportacion';

/**
 * Regresión: Unidades de seguimiento individual comparte `no_disponible:
 * false` en sus filtros; serializado como "false" la regla `boolean` de
 * Laravel lo rechazaba y Excel/PDF terminaban en un redirect silencioso.
 */
describe('urlExportacion', () => {
    it('omite los booleanos falsos y manda los verdaderos como 1', () => {
        const url = urlExportacion(
            '/activos/unidades/exportar',
            { buscar: 'iPhone', no_disponible: false },
            'xlsx',
        );
        expect(url).toBe(
            '/activos/unidades/exportar?buscar=iPhone&formato=xlsx',
        );
        expect(url).not.toContain('false');

        expect(urlExportacion('/x', { no_disponible: true }, 'pdf')).toBe(
            '/x?no_disponible=1&formato=pdf',
        );
    });

    it('conserva los filtros con valor y descarta los vacíos', () => {
        expect(
            urlExportacion(
                '/activos/unidades/exportar',
                {
                    empresa_id: 3,
                    almacen_id: '',
                    estado_visible: 'asignado',
                    contrato_id: null,
                    servicio_id: undefined,
                },
                'pdf',
            ),
        ).toBe(
            '/activos/unidades/exportar?empresa_id=3&estado_visible=asignado&formato=pdf',
        );
    });

    it('sin filtros sólo manda el formato', () => {
        expect(urlExportacion('/x', undefined, 'xlsx')).toBe('/x?formato=xlsx');
    });
});
