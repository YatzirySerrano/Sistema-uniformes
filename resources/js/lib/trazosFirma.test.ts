import { describe, expect, it } from 'vitest';
import {
    COLOR_TRAZO_DOCUMENTO,
    colorTrazoVisual,
    dibujarParaDocumento,
    dibujarTrazos,
} from './trazosFirma';
import type { LienzoFirma, TrazoFirma } from './trazosFirma';

/** Contexto falso que registra los colores con los que se trazó. */
function lienzoFalso(): LienzoFirma & { colores: string[]; segmentos: number } {
    const lienzo = {
        colores: [] as string[],
        segmentos: 0,
        lineWidth: 1,
        lineCap: 'butt' as CanvasLineCap,
        lineJoin: 'miter' as CanvasLineJoin,
        strokeStyle: '' as string | CanvasGradient | CanvasPattern,
        beginPath() {},
        moveTo() {},
        lineTo() {
            lienzo.segmentos++;
        },
        stroke() {
            lienzo.colores.push(lienzo.strokeStyle as string);
        },
    };
    return lienzo;
}

const trazos: TrazoFirma[] = [
    [
        { x: 10, y: 10 },
        { x: 40, y: 30 },
        { x: 80, y: 20 },
    ],
    [{ x: 5, y: 5 }],
];

describe('pad de firma: tema visual vs. imagen guardada', () => {
    it('en modo oscuro el trazo VISIBLE es claro y en modo claro es oscuro', () => {
        expect(colorTrazoVisual(true)).toBe('#f8fafc');
        expect(colorTrazoVisual(false)).toBe(COLOR_TRAZO_DOCUMENTO);
    });

    it('la imagen para el documento siempre se traza oscura (imprimible), aunque se haya firmado en modo oscuro', () => {
        const visual = lienzoFalso();
        dibujarTrazos(visual, trazos, colorTrazoVisual(true));
        expect(visual.colores).toEqual(['#f8fafc']);

        const documento = lienzoFalso();
        dibujarParaDocumento(documento, trazos);
        expect(documento.colores).toEqual([COLOR_TRAZO_DOCUMENTO]);
        expect(documento.colores).not.toContain('#f8fafc');
        // Mismos trazos: la geometría no cambia, sólo el color.
        expect(documento.segmentos).toBe(visual.segmentos);
    });
});
