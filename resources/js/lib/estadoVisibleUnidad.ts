/**
 * Colores semánticos del estado VISIBLE consolidado de una unidad (ver
 * `App\Enums\EstadoVisibleUnidad` / `UnidadActivo::estadoVisible()` en el
 * backend, que es la única fuente de verdad del cálculo). Este mapa es
 * puramente de presentación.
 */
export type EstadoVisibleUnidadValor =
    | 'disponible'
    | 'asignado'
    | 'reparacion'
    | 'perdido'
    | 'robado'
    | 'baja';

const CLASES: Record<EstadoVisibleUnidadValor, string> = {
    disponible:
        'bg-emerald-100 text-emerald-800 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-900',
    asignado:
        'bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-900',
    reparacion:
        'bg-amber-100 text-amber-800 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-900',
    perdido:
        'bg-red-50 text-red-700 border-red-200 dark:bg-red-950/30 dark:text-red-300 dark:border-red-900',
    robado: 'bg-red-600 text-white border-red-700 dark:bg-red-700 dark:border-red-800',
    baja: 'bg-muted text-muted-foreground border-border',
};

export function claseEstadoVisibleUnidad(valor: string): string {
    return CLASES[valor as EstadoVisibleUnidadValor] ?? CLASES.baja;
}
