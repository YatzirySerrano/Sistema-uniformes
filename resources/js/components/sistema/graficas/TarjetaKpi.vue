<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';

/**
 * KPI compacto reutilizado en el Dashboard (12 tarjetas con la misma forma:
 * título, icono semántico, valor). `colorClase` colorea sólo el icono — el
 * valor siempre usa tinta de texto normal (nunca el color de la serie).
 */
withDefaults(
    defineProps<{
        titulo: string;
        valor: number;
        icono: unknown;
        colorClase?: string;
        ayuda?: string;
    }>(),
    { colorClase: 'text-muted-foreground', ayuda: undefined },
);

const formateador = new Intl.NumberFormat('es-MX');
</script>

<template>
    <Card>
        <CardHeader
            class="flex flex-row items-center justify-between gap-2 pb-2"
        >
            <CardTitle
                class="text-muted-foreground flex items-center gap-1 text-xs font-medium"
            >
                {{ titulo }}
                <AyudaTooltip v-if="ayuda" :texto="ayuda" />
            </CardTitle>
            <component
                :is="icono"
                class="size-4 shrink-0"
                :class="colorClase"
            />
        </CardHeader>
        <CardContent class="text-2xl font-semibold">
            {{ formateador.format(valor) }}
        </CardContent>
    </Card>
</template>
