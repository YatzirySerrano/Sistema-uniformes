import type { BadgeVariants } from '@/components/ui/badge';

/**
 * Variante de Badge para el estado de una devolución
 * (`App\Enums\EstadoDevolucion`). Nunca `'default'` (color de marca): una
 * devolución "Confirmada" debe leerse como éxito con cualquier identidad visual.
 */
const VARIANTES: Record<string, NonNullable<BadgeVariants['variant']>> = {
    pendiente_firma: 'secondary',
    confirmada: 'success',
};

export function varianteBadgeEstadoDevolucion(
    estado: string,
): NonNullable<BadgeVariants['variant']> {
    return VARIANTES[estado] ?? 'secondary';
}
