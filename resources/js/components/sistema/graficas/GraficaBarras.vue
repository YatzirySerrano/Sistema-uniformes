<script setup lang="ts">
import { computed } from 'vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';

/**
 * Gráfica de barras verticales en CSS puro (sin dependencia nueva). Un único
 * color por defecto (magnitud, no identidad) — pásale `colorClase` por dato
 * sólo cuando la barra representa un estado/semántica ya establecida en la
 * app (p. ej. estado visible de unidades), nunca para "decorar" categorías
 * distintas con colores distintos.
 */
type BarraDato = { etiqueta: string; valor: number; colorClase?: string };

const props = withDefaults(
    defineProps<{
        datos: BarraDato[];
        alto?: number;
        colorClase?: string;
        formatoValor?: (valor: number) => string;
        tituloVacio?: string;
        descripcionVacio?: string;
    }>(),
    {
        alto: 200,
        colorClase: 'bg-chart-1',
        formatoValor: (valor: number) => String(valor),
        tituloVacio: 'Sin datos',
        descripcionVacio: 'No hay información para este periodo.',
    },
);

const max = computed(() => Math.max(1, ...props.datos.map((d) => d.valor)));

function altoPct(valor: number): string {
    return `${Math.max(valor > 0 ? 2 : 0, (valor / max.value) * 100)}%`;
}
</script>

<template>
    <EstadoVacio
        v-if="!datos.length"
        :titulo="tituloVacio"
        :descripcion="descripcionVacio"
        class="py-6"
    />
    <div
        v-else
        class="flex items-end gap-1.5 sm:gap-2"
        :style="{ height: `${alto}px` }"
    >
        <div
            v-for="(d, i) in datos"
            :key="i"
            class="group relative flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1.5"
        >
            <div class="relative flex h-full w-full items-end justify-center">
                <div
                    class="bg-popover text-popover-foreground pointer-events-none absolute top-0 left-1/2 z-10 -translate-x-1/2 -translate-y-full rounded-md border px-2 py-1 text-xs whitespace-nowrap opacity-0 shadow-md transition-opacity group-focus-within:opacity-100 group-hover:opacity-100"
                    role="tooltip"
                >
                    <span class="font-medium">{{ d.etiqueta }}:</span>
                    {{ formatoValor(d.valor) }}
                </div>
                <div
                    tabindex="0"
                    role="img"
                    :aria-label="`${d.etiqueta}: ${formatoValor(d.valor)}`"
                    class="focus-visible:ring-ring w-full max-w-12 rounded-t-md transition-[height] focus-visible:ring-2 focus-visible:outline-none"
                    :class="d.colorClase ?? colorClase"
                    :style="{ height: altoPct(d.valor) }"
                />
            </div>
            <span
                class="text-muted-foreground w-full truncate text-center text-[11px]"
                :title="d.etiqueta"
                >{{ d.etiqueta }}</span
            >
        </div>
    </div>
</template>
