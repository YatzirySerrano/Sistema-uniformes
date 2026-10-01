import { describe, expect, it } from 'vitest';
import {
    ETIQUETA_VARIANTE,
    etiquetaCantidadActual,
    etiquetaCantidadEsperada,
    textoVariante,
} from './etiquetasCantidad';

/**
 * En "Distribución actual del activo", la custodia del colaborador y el
 * inventario físico, talla y cantidad siempre van rotuladas: "17" nunca debe
 * poder leerse como otra cantidad.
 */
describe('etiquetas de renglones por cantidad', () => {
    it('la talla/variante se rotula y su ausencia es explícita', () => {
        expect(ETIQUETA_VARIANTE).toBe('Talla / variante');
        expect(textoVariante('17')).toBe('17');
        expect(textoVariante(null)).toBe('Sin variante');
        expect(textoVariante('')).toBe('Sin variante');
    });

    it('distingue la cantidad en almacén de la cantidad en custodia', () => {
        expect(etiquetaCantidadActual(false)).toBe('Cantidad actual');
        expect(etiquetaCantidadActual(true)).toBe(
            'Cantidad actual en custodia',
        );
    });

    it('el inventario físico rotula lo esperado según el origen', () => {
        expect(etiquetaCantidadEsperada(false)).toBe('Cantidad esperada');
        expect(etiquetaCantidadEsperada(true)).toBe(
            'Cantidad esperada en custodia',
        );
    });
});
