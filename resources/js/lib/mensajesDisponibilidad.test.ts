import { describe, expect, it } from 'vitest';
import {
    mensajeDisponibilidadInsuficiente,
    textoDisponibles,
} from './mensajesDisponibilidad';

/**
 * QA 2026-10: «solicitaste 9 piezas combinando artículos sueltos y
 * conjuntos, pero sólo hay 1 disponibles» confundía (plural roto, detalle
 * innecesario y sin decir que la causa era otra operación concurrente).
 */
describe('mensajes de disponibilidad insuficiente', () => {
    const base = {
        operacion: 'entrega' as const,
        nombre: 'prueba',
        variante: 'CH',
        solicitado: 9,
    };

    it('concurrencia, queda 1: singular y la causa', () => {
        expect(
            mensajeDisponibilidadInsuficiente({
                ...base,
                disponible: 1,
                porOtros: true,
            }),
        ).toBe(
            'La disponibilidad cambió mientras realizabas la entrega. Solicitaste 9 piezas de «prueba» talla CH, pero actualmente sólo queda 1 disponible porque otras operaciones apartaron existencias.',
        );
    });

    it('concurrencia, quedan 0', () => {
        expect(
            mensajeDisponibilidadInsuficiente({
                ...base,
                disponible: 0,
                porOtros: true,
            }),
        ).toBe(
            'La disponibilidad cambió mientras realizabas la entrega. Solicitaste 9 piezas de «prueba» talla CH, pero actualmente ya no quedan existencias disponibles.',
        );
    });

    it('sin concurrencia: plural correcto y sin el aviso de cambio', () => {
        expect(
            mensajeDisponibilidadInsuficiente({
                ...base,
                disponible: 2,
                porOtros: false,
            }),
        ).toBe(
            'Solicitaste 9 piezas de «prueba» talla CH, pero sólo quedan 2 disponibles.',
        );
        expect(
            mensajeDisponibilidadInsuficiente({
                ...base,
                disponible: 0,
                porOtros: false,
            }),
        ).toBe(
            'Solicitaste 9 piezas de «prueba» talla CH, pero no hay existencias disponibles.',
        );
    });

    it('nunca muestra cifras negativas ni "1 piezas"', () => {
        expect(
            mensajeDisponibilidadInsuficiente({
                ...base,
                solicitado: 1,
                variante: null,
                disponible: -3,
                porOtros: false,
            }),
        ).toBe(
            'Solicitaste 1 pieza de «prueba», pero no hay existencias disponibles.',
        );
    });

    it('menciona conjuntos sólo cuando la cifra sale de sumarlos', () => {
        expect(
            mensajeDisponibilidadInsuficiente({
                ...base,
                disponible: 1,
                porOtros: false,
                origen: 'mixto',
            }),
        ).toBe(
            'Solicitaste 9 piezas de «prueba» talla CH (sumando artículos sueltos y conjuntos), pero sólo queda 1 disponible.',
        );
    });

    it('traspaso y devolución nombran su operación y, en devolución, la custodia', () => {
        expect(
            mensajeDisponibilidadInsuficiente({
                ...base,
                operacion: 'traspaso',
                disponible: 2,
                porOtros: true,
            }),
        ).toContain('mientras realizabas el traspaso');
        expect(
            mensajeDisponibilidadInsuficiente({
                ...base,
                operacion: 'devolucion',
                nombre: 'Camisa',
                variante: 'M',
                solicitado: 3,
                disponible: 2,
                porOtros: true,
            }),
        ).toBe(
            'La disponibilidad cambió mientras realizabas la devolución. Solicitaste devolver 3 piezas de «Camisa» talla M, pero actualmente sólo quedan 2 disponibles para devolver porque otra devolución apartó esas piezas.',
        );
    });

    it('textoDisponibles: 1 disponible / 2 disponibles', () => {
        expect(textoDisponibles(1)).toBe('1 disponible');
        expect(textoDisponibles(2)).toBe('2 disponibles');
        expect(textoDisponibles(0)).toBe('0 disponibles');
    });
});
