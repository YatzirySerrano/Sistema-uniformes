/**
 * Lógica pura del pad de firma, separada del componente para poder probarla.
 *
 * La experiencia VISUAL y la imagen PERSISTIDA son cosas distintas: en modo
 * oscuro el trazo se ve claro (si no, sobre el fondo oscuro casi no se ve),
 * pero la imagen que viaja al backend (acuse, PDF, vista previa) se
 * re-dibuja SIEMPRE con trazo oscuro sobre fondo transparente — el mismo
 * formato de siempre —, así nunca se guarda una firma blanca que
 * desaparecería en un documento blanco.
 */
export type PuntoFirma = { x: number; y: number };
export type TrazoFirma = PuntoFirma[];

/** Color del trazo en la imagen que se guarda (imprimible). */
export const COLOR_TRAZO_DOCUMENTO = '#0f172a';

/** Color del trazo mientras se dibuja, según el tema visible. */
export function colorTrazoVisual(temaOscuro: boolean): string {
    return temaOscuro ? '#f8fafc' : COLOR_TRAZO_DOCUMENTO;
}

/** Subconjunto del contexto 2D que se usa (facilita probarlo sin canvas). */
export type LienzoFirma = Pick<
    CanvasRenderingContext2D,
    | 'beginPath'
    | 'moveTo'
    | 'lineTo'
    | 'stroke'
    | 'lineWidth'
    | 'lineCap'
    | 'lineJoin'
    | 'strokeStyle'
>;

/** Aplica el estilo de trazo común (grosor y terminaciones redondeadas). */
export function prepararTrazo(ctx: LienzoFirma, color: string): void {
    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = color;
}

/** Dibuja todos los trazos con un color dado. */
export function dibujarTrazos(
    ctx: LienzoFirma,
    trazos: TrazoFirma[],
    color: string,
): void {
    prepararTrazo(ctx, color);
    for (const trazo of trazos) {
        if (trazo.length < 2) continue;
        ctx.beginPath();
        ctx.moveTo(trazo[0].x, trazo[0].y);
        for (const punto of trazo.slice(1)) {
            ctx.lineTo(punto.x, punto.y);
        }
        ctx.stroke();
    }
}

/**
 * Dibuja la versión para DOCUMENTO: siempre trazo oscuro, sin importar el
 * tema con el que se capturó.
 */
export function dibujarParaDocumento(
    ctx: LienzoFirma,
    trazos: TrazoFirma[],
): void {
    dibujarTrazos(ctx, trazos, COLOR_TRAZO_DOCUMENTO);
}
