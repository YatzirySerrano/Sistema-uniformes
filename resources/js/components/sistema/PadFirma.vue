<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    colorTrazoVisual,
    dibujarParaDocumento,
    dibujarTrazos,
    prepararTrazo,
} from '@/lib/trazosFirma';
import type { PuntoFirma, TrazoFirma } from '@/lib/trazosFirma';

const emit = defineEmits<{
    (e: 'cambio', vacio: boolean): void;
}>();

const contenedor = ref<HTMLDivElement | null>(null);
const canvas = ref<HTMLCanvasElement | null>(null);
const dibujando = ref(false);
const hayTrazos = ref(false);
let ctx: CanvasRenderingContext2D | null = null;
let ultimo: PuntoFirma | null = null;

// La firma se conserva como TRAZOS (coordenadas en px CSS), no como imagen:
// sobrevive a ocultamientos del pad (p. ej. un paso con `v-show`), se puede
// repintar con el color del tema visible y, al exportar, se re-dibuja con
// trazo oscuro imprimible (ver `lib/trazosFirma.ts`).
const trazos: TrazoFirma[] = [];
let trazoActual: TrazoFirma | null = null;
// Último tamaño CSS válido del lienzo: permite exportar aunque en este
// instante esté oculto (ancho 0).
let anchoValido = 0;
let observador: ResizeObserver | null = null;
let observadorTema: MutationObserver | null = null;

const ALTO = 200;

function temaOscuro(): boolean {
    return (
        typeof document !== 'undefined' &&
        document.documentElement.classList.contains('dark')
    );
}

/** Repinta todo con el color del tema VISIBLE (nunca afecta lo guardado). */
function repintar(): void {
    if (!ctx || !canvas.value) return;
    ctx.clearRect(0, 0, canvas.value.width, canvas.value.height);
    dibujarTrazos(ctx, trazos, colorTrazoVisual(temaOscuro()));
}

function ajustarTamano() {
    if (!canvas.value || !contenedor.value) return;

    const ratio = window.devicePixelRatio || 1;
    const ancho = contenedor.value.clientWidth;

    // Oculto (`display:none` → clientWidth 0): NO reconfigurar a 0×0 (eso
    // dejaba el canvas colapsado y sin poder dibujar al reaparecer). Se
    // espera a que el ResizeObserver avise cuando vuelva a tener ancho.
    if (ancho === 0) return;

    // Nada que hacer si ya está exactamente a ese tamaño (evita trabajo
    // redundante y cualquier bucle del ResizeObserver).
    if (
        canvas.value.width === Math.round(ancho * ratio) &&
        canvas.value.height === Math.round(ALTO * ratio) &&
        ctx !== null
    ) {
        return;
    }

    canvas.value.width = Math.round(ancho * ratio);
    canvas.value.height = Math.round(ALTO * ratio);
    canvas.value.style.width = `${ancho}px`;
    canvas.value.style.height = `${ALTO}px`;
    anchoValido = ancho;

    ctx = canvas.value.getContext('2d');
    if (!ctx) return;
    ctx.scale(ratio, ratio);
    repintar();
}

function posicion(evento: PointerEvent): PuntoFirma {
    const rect = canvas.value!.getBoundingClientRect();
    return { x: evento.clientX - rect.left, y: evento.clientY - rect.top };
}

function iniciar(evento: PointerEvent) {
    evento.preventDefault();
    // Si el pad se montó oculto (paso con v-show), asegura que el canvas
    // esté calibrado antes del primer trazo.
    if (!ctx) ajustarTamano();
    if (!ctx) return;
    dibujando.value = true;
    ultimo = posicion(evento);
    trazoActual = [ultimo];
    trazos.push(trazoActual);
    canvas.value?.setPointerCapture(evento.pointerId);
}

function mover(evento: PointerEvent) {
    if (!dibujando.value || !ctx || !ultimo || !trazoActual) return;
    evento.preventDefault();
    const actual = posicion(evento);
    prepararTrazo(ctx, colorTrazoVisual(temaOscuro()));
    ctx.beginPath();
    ctx.moveTo(ultimo.x, ultimo.y);
    ctx.lineTo(actual.x, actual.y);
    ctx.stroke();
    trazoActual.push(actual);
    ultimo = actual;
    if (!hayTrazos.value) {
        hayTrazos.value = true;
        emit('cambio', false);
    }
}

function terminar(evento: PointerEvent) {
    dibujando.value = false;
    ultimo = null;
    trazoActual = null;
    try {
        canvas.value?.releasePointerCapture(evento.pointerId);
    } catch {
        /* noop */
    }
}

function limpiar() {
    trazos.splice(0, trazos.length);
    trazoActual = null;
    if (ctx && canvas.value) {
        ctx.clearRect(0, 0, canvas.value.width, canvas.value.height);
    }
    if (hayTrazos.value) {
        hayTrazos.value = false;
        emit('cambio', true);
    }
}

/**
 * Imagen PNG para guardar: fondo transparente + trazo oscuro, re-dibujada
 * desde los trazos en un lienzo aparte — independiente del tema con el que
 * se firmó (en modo oscuro el trazo visible es claro; aquí nunca).
 */
function obtenerDataUrl(): string | null {
    if (!hayTrazos.value || anchoValido === 0) return null;
    const ratio = window.devicePixelRatio || 1;
    const lienzo = document.createElement('canvas');
    lienzo.width = Math.round(anchoValido * ratio);
    lienzo.height = Math.round(ALTO * ratio);
    const contexto = lienzo.getContext('2d');
    if (!contexto) return null;
    contexto.scale(ratio, ratio);
    dibujarParaDocumento(contexto, trazos);
    return lienzo.toDataURL('image/png');
}

defineExpose({
    limpiar,
    obtenerDataUrl,
    estaVacio: () => !hayTrazos.value,
    // Recalibra el canvas cuando el pad se vuelve visible (p. ej. al
    // entrar a un paso del asistente). El ResizeObserver ya lo hace solo,
    // pero exponerlo permite forzarlo justo tras `nextTick`.
    recalibrar: ajustarTamano,
});

onMounted(() => {
    ajustarTamano();
    window.addEventListener('resize', ajustarTamano);

    // Detecta cuando el contenedor pasa de oculto (0px) a visible: es lo
    // que rompía la firma dentro de un paso con `v-show`.
    if (typeof ResizeObserver !== 'undefined' && contenedor.value) {
        observador = new ResizeObserver(() => ajustarTamano());
        observador.observe(contenedor.value);
    }

    // Cambio de tema claro/oscuro con la firma ya dibujada: repinta el trazo
    // visible con el color del nuevo tema (lo guardado no cambia).
    if (typeof MutationObserver !== 'undefined') {
        observadorTema = new MutationObserver(() => repintar());
        observadorTema.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', ajustarTamano);
    observador?.disconnect();
    observador = null;
    observadorTema?.disconnect();
    observadorTema = null;
});
</script>

<template>
    <div class="space-y-2">
        <div
            ref="contenedor"
            class="bg-background overflow-hidden rounded-lg border-2 border-dashed"
        >
            <canvas
                ref="canvas"
                class="block w-full touch-none"
                style="height: 200px"
                aria-label="Área para dibujar la firma de recepción"
                @pointerdown="iniciar"
                @pointermove="mover"
                @pointerup="terminar"
                @pointerleave="terminar"
                @pointercancel="terminar"
            />
        </div>
        <div class="flex items-center justify-between">
            <p class="text-muted-foreground text-xs">
                Firma con el dedo, el mouse o un lápiz óptico.
            </p>
            <Button type="button" variant="outline" size="sm" @click="limpiar">
                Limpiar
            </Button>
        </div>
    </div>
</template>
