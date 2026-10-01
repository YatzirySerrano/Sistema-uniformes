/**
 * Etiquetas explícitas para renglones POR CANTIDAD (distribución del activo,
 * custodia del colaborador, inventario físico): nunca un número suelto que
 * el usuario tenga que adivinar si es la talla o la cantidad.
 *
 * "Talla / variante" es el término del catálogo (`tallas`): una prenda usa
 * tallas, otros activos usan variantes; "sin variante" = `talla_id` NULL.
 */
export const ETIQUETA_VARIANTE = 'Talla / variante';

export function textoVariante(talla: string | null | undefined): string {
    return talla ? talla : 'Sin variante';
}

/** Lo que hay HOY: en un almacén, o en poder de un custodio. */
export function etiquetaCantidadActual(enCustodia: boolean): string {
    return enCustodia ? 'Cantidad actual en custodia' : 'Cantidad actual';
}

/** Lo que la ronda de inventario físico congeló al iniciar. */
export function etiquetaCantidadEsperada(enCustodia: boolean): string {
    return enCustodia ? 'Cantidad esperada en custodia' : 'Cantidad esperada';
}
