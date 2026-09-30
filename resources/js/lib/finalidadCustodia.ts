import type { BadgeVariants } from '@/components/ui/badge';

/**
 * Variante de Badge para la finalidad de un renglón de custodia
 * (`App\Enums\FinalidadCustodia`): «Para redistribuir» resalta, «Uso
 * personal» es neutra y «Sin clasificar» (null, histórico) va con contorno
 * para que nunca se confunda con uso personal.
 */
export function varianteBadgeFinalidad(
    finalidad: string | null,
): NonNullable<BadgeVariants['variant']> {
    if (finalidad === 'redistribucion') return 'warning';

    return finalidad ? 'secondary' : 'outline';
}
