import { describe, expect, it } from 'vitest';
import { formatoCurpValido, formatoRfcValido } from './validacionCurpRfc';

/**
 * Regresión (2026-09): el estado `'invalido'` de CURP/RFC se calculaba bien
 * pero el template nunca lo mostraba — ningún `<p v-else-if>` lo cubría, así
 * que escribir algo como "ASASAS¿" no daba ningún aviso en tiempo real. Este
 * archivo prueba la LÓGICA pura (regex/longitud) que alimenta ese estado —
 * `FormularioColaborador.vue`/`FormularioEmpresa.vue` ahora usan un switch
 * exhaustivo (`vue-tsc` falla si un estado se queda sin mensaje), que es la
 * garantía estructural contra que la regresión de UI vuelva.
 */
describe('formatoCurpValido', () => {
    it('acepta una CURP con estructura válida (ejemplo de referencia RENAPO)', () => {
        expect(formatoCurpValido('HEGG560427MVZRRL04')).toBe(true);
    });

    it('rechaza texto claramente inválido (símbolos, no 18 caracteres)', () => {
        expect(formatoCurpValido('ASASAS¿')).toBe(false);
        expect(formatoCurpValido('!@#$%^&*()12345678')).toBe(false);
        expect(formatoCurpValido('')).toBe(false);
    });

    it('rechaza longitudes distintas de 18', () => {
        expect(formatoCurpValido('HEGG560427MVZRRL0')).toBe(false); // 17
        expect(formatoCurpValido('HEGG560427MVZRRL044')).toBe(false); // 19
    });

    it('rechaza mes/día/sexo/entidad fuera de catálogo aunque el resto tenga forma correcta', () => {
        expect(formatoCurpValido('HEGG561327MVZRRL04')).toBe(false); // mes 13
        expect(formatoCurpValido('HEGG560432MVZRRL04')).toBe(false); // día 32
        expect(formatoCurpValido('HEGG560427XVZRRL04')).toBe(false); // sexo X
        expect(formatoCurpValido('HEGG560427MXXRRL04')).toBe(false); // entidad XX no existe
    });
});

describe('formatoRfcValido', () => {
    it('acepta un RFC de persona moral (12 caracteres)', () => {
        expect(formatoRfcValido('ABC010203XYZ')).toBe(true);
    });

    it('acepta un RFC de persona física (13 caracteres)', () => {
        expect(formatoRfcValido('XAXX010101000')).toBe(true);
    });

    it('rechaza texto claramente inválido', () => {
        expect(formatoRfcValido('ASASAS¿')).toBe(false);
        expect(formatoRfcValido('12345678')).toBe(false);
        expect(formatoRfcValido('')).toBe(false);
    });

    it('rechaza longitudes fuera de 12-13', () => {
        expect(formatoRfcValido('AB010203XYZ')).toBe(false); // 11
        expect(formatoRfcValido('ABCDE010203XYZ')).toBe(false); // 14
    });
});
