/**
 * Presentación de la "dirección" de un movimiento de inventario (ver
 * `App\Enums\DireccionMovimiento`). `sin_efecto` = evento de custodia
 * (redistribución colaborador → colaborador): queda en el historial pero no
 * modifica el stock de ningún almacén, así que nunca se pinta como +N / −N.
 */
export type DireccionMovimiento = 'entrada' | 'salida' | 'sin_efecto';

/** Datos de custodia de una redistribución (derivados del renglón de entrega). */
export type CustodiaMovimiento = {
    entrega_id: number;
    folio: string | null;
    origen: string | null;
    destino: string | null;
    finalidad_origen: string;
    finalidad_destino: string;
};

export function textoCambio(direccion: string, cantidad: number): string {
    if (direccion === 'entrada') return `+${cantidad}`;
    if (direccion === 'salida') return `−${cantidad}`;
    return `${cantidad} (sin cambio de stock)`;
}

export function claseCambio(direccion: string): string {
    if (direccion === 'entrada') return 'text-emerald-600';
    if (direccion === 'salida') return 'text-rose-600';
    return 'text-sky-700 dark:text-sky-400';
}

export function etiquetaDireccion(direccion: string): string {
    if (direccion === 'entrada') return 'Entrada';
    if (direccion === 'salida') return 'Salida';
    return 'Sin efecto en stock';
}
