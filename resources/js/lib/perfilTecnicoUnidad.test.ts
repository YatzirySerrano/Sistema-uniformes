import { describe, expect, it } from 'vitest';
import {
    ETIQUETA_CAMPO,
    camposRequeridos,
    camposVisibles,
    especificacionVacia,
} from './perfilTecnicoUnidad';

/**
 * Espejo de `PerfilTecnicoUnidad::camposVisibles()`: la columna interna `plan`
 * se rotula «Observaciones» y es opcional en Celular, Computadora y Tablet.
 */
describe('Observaciones (campo plan) por perfil técnico', () => {
    it('se rotula Observaciones conservando la clave interna plan', () => {
        expect(ETIQUETA_CAMPO.plan).toBe('Observaciones');
        expect(especificacionVacia()).toHaveProperty('plan', '');
        expect(especificacionVacia()).not.toHaveProperty('observaciones');
    });

    it('aparece en Celular, Computadora y Tablet sin quitar campos previos', () => {
        expect(camposVisibles('celular')).toEqual([
            'marca',
            'modelo',
            'imei',
            'numero_telefonico',
            'operador',
            'plan',
        ]);
        expect(camposVisibles('computadora')).toEqual([
            'marca',
            'modelo',
            'plan',
        ]);
        expect(camposVisibles('tablet')).toEqual(['marca', 'modelo', 'plan']);
    });

    it('nunca es obligatorio', () => {
        for (const perfil of ['celular', 'computadora', 'tablet'] as const) {
            expect(camposRequeridos(perfil)).not.toContain('plan');
        }
    });
});
