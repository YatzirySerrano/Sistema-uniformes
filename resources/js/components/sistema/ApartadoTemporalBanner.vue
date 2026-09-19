<script setup lang="ts">
import { Clock } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/**
 * Banner del apartado temporal (TTL) de existencias/custodia mientras se
 * prepara una Entrega o una Devolución. Sólo presentación: el ciclo de vida
 * real vive en `composables/useReservaBorrador.ts`. Nunca sugiere una
 * renovación automática — "Extender" es siempre una acción explícita del
 * usuario.
 */
defineProps<{
    /** `mm:ss` ya formateado por el composable. */
    minutosSegundos: string;
    /** Últimos ~90s antes de vencer: se resalta y se ofrece "Extender". */
    porVencer: boolean;
    /** Ya pasó el TTL: hay que recalcular antes de poder continuar. */
    vencida: boolean;
    cargando: boolean;
    error: string | null;
}>();

defineEmits<{ extender: [] }>();
</script>

<template>
    <div
        class="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm"
        :class="
            vencida
                ? 'border-destructive/40 bg-destructive/5 text-destructive'
                : porVencer
                  ? 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-400'
                  : 'bg-muted/40 text-muted-foreground'
        "
        aria-live="polite"
    >
        <div class="flex items-center gap-2">
            <Clock class="size-4 shrink-0" />
            <span v-if="vencida">
                Tu apartado de existencias venció. Actualizamos las existencias
                disponibles; revisa los artículos antes de continuar.
            </span>
            <span v-else-if="error">{{ error }}</span>
            <span v-else>
                Existencias apartadas durante
                <span class="font-semibold tabular-nums">{{
                    minutosSegundos
                }}</span>
                — es un apartado temporal, no la operación en sí.
            </span>
        </div>
        <Button
            v-if="porVencer && !vencida"
            type="button"
            variant="outline"
            size="sm"
            :disabled="cargando"
            @click="$emit('extender')"
        >
            Extender 10 min
        </Button>
    </div>
</template>
