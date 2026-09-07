<script setup lang="ts">
import { LayoutGrid, List } from '@lucide/vue';
import type { VistaListado } from '@/composables/useVistaPreferida';
import { cn } from '@/lib/utils';

/**
 * Control segmentado compacto para alternar entre Cards y Tabla en un
 * listado. No cambia el dataset — sólo la representación (ver
 * `useVistaPreferida`). Aplícalo únicamente en listados donde ambas vistas
 * aporten algo real; si un módulo no se beneficia, no lo fuerces.
 */
const modelValue = defineModel<VistaListado>({ required: true });

const opciones: { valor: VistaListado; etiqueta: string; icono: unknown }[] = [
    { valor: 'cards', etiqueta: 'Cards', icono: LayoutGrid },
    { valor: 'tabla', etiqueta: 'Tabla', icono: List },
];
</script>

<template>
    <div
        class="bg-muted inline-flex items-center gap-0.5 rounded-md p-0.5"
        role="group"
        aria-label="Cambiar tipo de vista del listado"
        data-tour="selector-vista"
    >
        <button
            v-for="opcion in opciones"
            :key="opcion.valor"
            type="button"
            :aria-pressed="modelValue === opcion.valor"
            :class="
                cn(
                    'inline-flex items-center gap-1.5 rounded-sm px-2.5 py-1 text-sm font-medium transition-colors',
                    modelValue === opcion.valor
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground',
                )
            "
            @click="modelValue = opcion.valor"
        >
            <component :is="opcion.icono" class="size-4" />
            {{ opcion.etiqueta }}
        </button>
    </div>
</template>
