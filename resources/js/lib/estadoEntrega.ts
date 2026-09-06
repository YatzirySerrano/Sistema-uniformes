import type { BadgeVariants } from '@/components/ui/badge';

/**
 * Variante de Badge para el estado de una entrega (`App\Enums\EstadoEntrega`).
 * Nunca `'default'` (color de marca/`--primary`, configurable): una entrega
 * "Firmada" debe leerse como éxito aunque la identidad configurada sea roja
 * o cualquier otro tono.
 */
const VARIANTES: Record<string, NonNullable<BadgeVariants['variant']>> = {
    pendiente_firma: 'secondary',
    firmada: 'success',
    corregida: 'warning',
    anulada: 'destructive',
};

export function varianteBadgeEstadoEntrega(
    estado: string,
): NonNullable<BadgeVariants['variant']> {
    return VARIANTES[estado] ?? 'secondary';
}
