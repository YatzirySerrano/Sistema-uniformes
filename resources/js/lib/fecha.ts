import { usePage } from '@inertiajs/vue3';

/**
 * Formatea un timestamp ISO en la zona horaria de presentación del sistema
 * (`zonaHoraria` compartida por Inertia, p. ej. `America/Mexico_City`), NO en
 * la zona del navegador de quien mira. Así la web y los PDF muestran la misma
 * hora para todos.
 *
 * Usar SIEMPRE esto en lugar de `new Date(iso).toLocaleString('es-MX')`.
 * Para fechas de negocio (día sin hora) que ya llegan como `dd/mm/aaaa`, no
 * hace falta: se pintan tal cual.
 */
export function fechaHora(
    iso: string | null | undefined,
    opciones: Intl.DateTimeFormatOptions = {},
): string {
    if (!iso) {
        return '—';
    }

    const zona =
        (usePage().props.zonaHoraria as string | undefined) ??
        'America/Mexico_City';

    return new Intl.DateTimeFormat('es-MX', {
        dateStyle: 'short',
        timeStyle: 'short',
        timeZone: zona,
        ...opciones,
    }).format(new Date(iso));
}

/** Sólo la fecha (sin hora), en la zona de presentación. */
export function soloFecha(iso: string | null | undefined): string {
    return fechaHora(iso, { dateStyle: 'short', timeStyle: undefined });
}

/**
 * Formatea una fecha de NEGOCIO ("Y-m-d", día sin hora — p. ej.
 * `fecha_entrega`, `fecha` de devolución) como "dd/mm/aaaa" mediante
 * manipulación de texto. NUNCA usar `new Date(fecha)` aquí: interpretaría el
 * string en UTC y luego lo reformatearía en la zona del navegador, pudiendo
 * desplazar el día mostrado — exactamente el bug que este formateo evita.
 */
export function fechaNegocio(fecha: string | null | undefined): string {
    if (!fecha) return '—';
    const [anio, mes, dia] = fecha.split('-');
    if (!anio || !mes || !dia) return fecha;

    return `${dia}/${mes}/${anio}`;
}
