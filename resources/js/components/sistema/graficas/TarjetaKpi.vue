<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';

/**
 * KPI del Dashboard. Dos densidades: la normal (fila principal, con
 * descripción opcional) y `compacto` (fila secundaria de mini-KPIs, mismo
 * lenguaje visual pero más denso). `tonoClase` colorea el contenedor del
 * icono (fondo tenue + icono), nunca el valor — el valor siempre usa tinta de
 * texto normal para no competir visualmente con alertas reales.
 */
const props = withDefaults(
    defineProps<{
        titulo: string;
        valor: number;
        icono: unknown;
        tonoClase?: string;
        descripcion?: string;
        ayuda?: string;
        compacto?: boolean;
        href?: string;
    }>(),
    {
        tonoClase: 'bg-muted text-muted-foreground',
        descripcion: undefined,
        ayuda: undefined,
        compacto: false,
        href: undefined,
    },
);

const formateador = new Intl.NumberFormat('es-MX');
const valorFormateado = computed(() => formateador.format(props.valor));
const etiquetaComponente = computed(() => (props.href ? Link : 'div'));
</script>

<template>
    <component
        :is="etiquetaComponente"
        :href="href"
        class="group hover:border-primary/30 hover:bg-muted/20 bg-card relative flex rounded-xl border transition-[color,box-shadow,border-color] duration-150 hover:shadow-sm"
        :class="[
            compacto ? 'items-center gap-3 p-3' : 'flex-col gap-3 p-4',
            href && 'cursor-pointer',
        ]"
    >
        <span
            class="flex shrink-0 items-center justify-center rounded-lg"
            :class="[tonoClase, compacto ? 'size-9' : 'size-10']"
        >
            <component :is="icono" :class="compacto ? 'size-4' : 'size-5'" />
        </span>

        <div class="min-w-0 flex-1" :class="compacto && 'flex flex-col'">
            <p
                class="text-muted-foreground flex items-center gap-1 truncate font-medium"
                :class="compacto ? 'text-xs' : 'text-xs'"
            >
                {{ titulo }}
                <AyudaTooltip v-if="ayuda" :texto="ayuda" />
            </p>
            <p
                class="font-semibold tracking-tight tabular-nums"
                :class="compacto ? 'text-lg' : 'mt-1 text-3xl'"
            >
                {{ valorFormateado }}
            </p>
            <p
                v-if="descripcion && !compacto"
                class="text-muted-foreground mt-0.5 text-xs"
            >
                {{ descripcion }}
            </p>
        </div>
    </component>
</template>
