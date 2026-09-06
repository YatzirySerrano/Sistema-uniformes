<script setup lang="ts">
import { computed, ref } from 'vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';

/**
 * Serie temporal en SVG puro (sin dependencia nueva): un eje compartido
 * (nunca doble eje), cuadrícula recesiva, y una línea vertical + tooltip al
 * pasar el cursor ("crosshair"). 1 serie no necesita leyenda (el título de
 * la card ya la nombra); 2+ series muestran leyenda con color + etiqueta.
 */
type SerieLinea = { clave: string; etiqueta: string; claseTrazo: string };
type Punto = Record<string, string | number>;

const props = withDefaults(
    defineProps<{
        puntos: Punto[];
        series: SerieLinea[];
        claveEje?: string;
        alto?: number;
        formatoValor?: (valor: number) => string;
        formatoEje?: (valor: string) => string;
        tituloVacio?: string;
        descripcionVacio?: string;
    }>(),
    {
        claveEje: 'fecha',
        alto: 220,
        formatoValor: (valor: number) => String(valor),
        formatoEje: (valor: string) => valor,
        tituloVacio: 'Sin datos',
        descripcionVacio: 'No hay información para este periodo.',
    },
);

const ANCHO = 640;
const ALTO_INTERNO = 200;
const PAD_SUP = 12;
const PAD_INF = 24;

const max = computed(
    () =>
        Math.max(
            1,
            ...props.puntos.flatMap((p) =>
                props.series.map((s) => Number(p[s.clave]) || 0),
            ),
        ) * 1.1,
);

function x(i: number): number {
    const n = props.puntos.length;
    return n <= 1 ? ANCHO / 2 : (i / (n - 1)) * ANCHO;
}

function y(valor: number): number {
    const disponible = ALTO_INTERNO - PAD_SUP - PAD_INF;
    return PAD_SUP + disponible - (valor / max.value) * disponible;
}

function linea(clave: string): string {
    return props.puntos
        .map(
            (p, i) =>
                `${i === 0 ? 'M' : 'L'}${x(i)},${y(Number(p[clave]) || 0)}`,
        )
        .join(' ');
}

const lineasGrid = [0.25, 0.5, 0.75, 1];

const svgRef = ref<SVGSVGElement | null>(null);
const indiceActivo = ref<number | null>(null);

function alMover(evento: MouseEvent) {
    if (!svgRef.value || props.puntos.length === 0) {
        return;
    }
    const rect = svgRef.value.getBoundingClientRect();
    const relativo = (evento.clientX - rect.left) / rect.width;
    const indice = Math.round(relativo * (props.puntos.length - 1));
    indiceActivo.value = Math.min(Math.max(indice, 0), props.puntos.length - 1);
}

const puntoActivo = computed(() =>
    indiceActivo.value === null ? null : props.puntos[indiceActivo.value],
);

const posicionTooltip = computed(() => {
    if (indiceActivo.value === null) {
        return { left: '50%', transform: 'translateX(-50%)' };
    }
    const pct = (x(indiceActivo.value) / ANCHO) * 100;
    if (pct < 20) {
        return { left: '0%', transform: 'translateX(0)' };
    }
    if (pct > 80) {
        return { left: '100%', transform: 'translateX(-100%)' };
    }
    return { left: `${pct}%`, transform: 'translateX(-50%)' };
});
</script>

<template>
    <EstadoVacio
        v-if="!puntos.length"
        :titulo="tituloVacio"
        :descripcion="descripcionVacio"
        class="py-6"
    />
    <div v-else class="flex flex-col gap-2">
        <div
            v-if="series.length > 1"
            class="flex flex-wrap items-center gap-3 text-xs"
        >
            <span
                v-for="s in series"
                :key="s.clave"
                class="flex items-center gap-1.5"
            >
                <span
                    class="inline-block size-2 rounded-full"
                    :class="s.claseTrazo.replace('stroke-', 'bg-')"
                />
                <span class="text-muted-foreground">{{ s.etiqueta }}</span>
            </span>
        </div>

        <div class="relative" :style="{ height: `${alto}px` }">
            <svg
                ref="svgRef"
                :viewBox="`0 0 ${ANCHO} ${ALTO_INTERNO}`"
                preserveAspectRatio="none"
                class="h-full w-full"
                @mousemove="alMover"
                @mouseleave="indiceActivo = null"
            >
                <line
                    v-for="frac in lineasGrid"
                    :key="frac"
                    :x1="0"
                    :x2="ANCHO"
                    :y1="y(max * frac)"
                    :y2="y(max * frac)"
                    class="stroke-border"
                    stroke-width="1"
                    vector-effect="non-scaling-stroke"
                />

                <path
                    v-for="s in series"
                    :key="s.clave"
                    :d="linea(s.clave)"
                    fill="none"
                    :class="s.claseTrazo"
                    stroke-width="2"
                    vector-effect="non-scaling-stroke"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />

                <line
                    v-if="indiceActivo !== null"
                    :x1="x(indiceActivo)"
                    :x2="x(indiceActivo)"
                    :y1="PAD_SUP"
                    :y2="ALTO_INTERNO - PAD_INF"
                    class="stroke-muted-foreground/40"
                    stroke-width="1"
                    vector-effect="non-scaling-stroke"
                />
                <template v-if="indiceActivo !== null">
                    <circle
                        v-for="s in series"
                        :key="`punto-${s.clave}`"
                        :cx="x(indiceActivo)"
                        :cy="y(Number(puntoActivo?.[s.clave]) || 0)"
                        r="3.5"
                        :class="s.claseTrazo.replace('stroke-', 'fill-')"
                        :style="{ stroke: 'var(--color-card)' }"
                        stroke-width="1.5"
                    />
                </template>
            </svg>

            <div
                v-if="puntoActivo"
                class="bg-popover text-popover-foreground pointer-events-none absolute top-0 z-10 min-w-max rounded-md border px-2.5 py-1.5 text-xs shadow-md"
                :style="posicionTooltip"
            >
                <p class="text-muted-foreground mb-1 font-medium">
                    {{ formatoEje(String(puntoActivo[claveEje])) }}
                </p>
                <p
                    v-for="s in series"
                    :key="s.clave"
                    class="flex items-center gap-1.5"
                >
                    <span
                        class="inline-block size-1.5 rounded-full"
                        :class="s.claseTrazo.replace('stroke-', 'bg-')"
                    />
                    {{ s.etiqueta }}:
                    {{ formatoValor(Number(puntoActivo[s.clave]) || 0) }}
                </p>
            </div>
        </div>

        <div class="text-muted-foreground flex justify-between text-[11px]">
            <span>{{ formatoEje(String(puntos[0][claveEje])) }}</span>
            <span>{{
                formatoEje(String(puntos[puntos.length - 1][claveEje]))
            }}</span>
        </div>
    </div>
</template>
