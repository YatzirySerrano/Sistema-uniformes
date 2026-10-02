import { describe, expect, it } from 'vitest';
import {
    estadoReparto,
    partesParaEnviar,
    repartoInicial,
} from './repartoCondicion';

/**
 * "Dividir por condición" en Devoluciones: la suma debe cuadrar exacto con
 * la cantidad a devolver, con mensajes claros y sin ajustes silenciosos.
 */
describe('reparto por condición', () => {
    const condiciones = ['reutilizable', 'danado', 'baja'];

    it('al activar parte de la condición ya elegida, con todo el total', () => {
        expect(repartoInicial(condiciones, 'reutilizable', 8)).toEqual({
            reutilizable: 8,
            danado: 0,
            baja: 0,
        });
    });

    it('6 + 1 + 1 de 8 cuadra', () => {
        expect(
            estadoReparto(8, { reutilizable: 6, danado: 1, baja: 1 }),
        ).toEqual({ asignado: 8, mensaje: null });
    });

    it('falta asignar (singular y plural); un campo vacío cuenta como 0', () => {
        expect(
            estadoReparto(8, { reutilizable: 6, danado: 1, baja: '' }).mensaje,
        ).toBe('Falta asignar condición a 1 pieza.');
        expect(estadoReparto(8, { reutilizable: 6 }).mensaje).toBe(
            'Falta asignar condición a 2 piezas.',
        );
    });

    it('excedente', () => {
        expect(
            estadoReparto(8, { reutilizable: 6, danado: 2, baja: 1 }).mensaje,
        ).toBe(
            'La distribución por condición supera la cantidad a devolver por 1 pieza.',
        );
    });

    it('cambiar el total de 8 a 6 deja la distribución marcada, sin tocar sus valores', () => {
        const reparto = { reutilizable: 6, danado: 1, baja: 1 };

        expect(estadoReparto(6, reparto).mensaje).toBe(
            'La distribución por condición supera la cantidad a devolver por 2 piezas.',
        );
        expect(reparto).toEqual({ reutilizable: 6, danado: 1, baja: 1 });
    });

    it('rechaza negativos y decimales', () => {
        expect(estadoReparto(8, { reutilizable: 9, danado: -1 }).mensaje).toBe(
            'Las cantidades por condición deben ser números enteros de 0 o más.',
        );
        expect(
            estadoReparto(8, { reutilizable: 7.5, danado: 0.5 }).mensaje,
        ).not.toBeNull();
    });

    it('envía sólo las condiciones con piezas', () => {
        expect(
            partesParaEnviar({ reutilizable: 6, danado: 0, baja: 2 }),
        ).toEqual([
            { condicion: 'reutilizable', cantidad: 6 },
            { condicion: 'baja', cantidad: 2 },
        ]);
    });
});
