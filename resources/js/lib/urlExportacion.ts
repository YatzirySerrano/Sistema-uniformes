export type FiltrosExportacion = Record<
    string,
    string | number | boolean | null | undefined
>;

/**
 * URL de `[Exportar]` de un listado: `${endpoint}?<filtros>&formato=…`.
 *
 * Los booleanos viajan como los espera la regla `boolean` de Laravel: `true`
 * → `1` y `false` se OMITE (un filtro desmarcado equivale a no filtrar).
 * Nunca `"true"`/`"false"` en texto: Laravel los rechaza y la exportación
 * terminaba en un redirect silencioso de vuelta al listado. Mismo criterio
 * que `Reportes/Index.vue` con `solo_bajo_minimo`.
 */
export function urlExportacion(
    endpoint: string,
    filtros: FiltrosExportacion | undefined,
    formato: 'xlsx' | 'pdf',
): string {
    const params = new URLSearchParams();
    for (const [clave, valor] of Object.entries(filtros ?? {})) {
        if (
            valor === null ||
            valor === undefined ||
            valor === '' ||
            valor === false
        ) {
            continue;
        }
        params.set(clave, valor === true ? '1' : String(valor));
    }
    params.set('formato', formato);

    return `${endpoint}?${params.toString()}`;
}
