import { describe, expect, it } from 'vitest';
import {
    claseCambio,
    etiquetaDireccion,
    textoCambio,
} from './movimientoInventario';

/**
 * Una redistribución de custodia (colaborador → colaborador) aparece en
 * Movimientos, pero NO es una salida ni una entrada de almacén: nunca debe
 * pintarse como −1 / +1.
 */
describe('presentación del cambio de un movimiento', () => {
    it('entradas y salidas conservan su signo', () => {
        expect(textoCambio('entrada', 3)).toBe('+3');
        expect(textoCambio('salida', 2)).toBe('−2');
        expect(claseCambio('entrada')).toContain('emerald');
        expect(claseCambio('salida')).toContain('rose');
    });

    it('un evento de custodia no se muestra como salida ni entrada de stock', () => {
        expect(textoCambio('sin_efecto', 1)).toBe('1 (sin cambio de stock)');
        expect(textoCambio('sin_efecto', 1)).not.toMatch(/^[+−-]/);
        expect(etiquetaDireccion('sin_efecto')).toBe('Sin efecto en stock');
        expect(claseCambio('sin_efecto')).not.toContain('rose');
    });
});
