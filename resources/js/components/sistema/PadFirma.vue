<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';

const emit = defineEmits<{
    (e: 'cambio', vacio: boolean): void;
}>();

const contenedor = ref<HTMLDivElement | null>(null);
const canvas = ref<HTMLCanvasElement | null>(null);
const dibujando = ref(false);
const hayTrazos = ref(false);
let ctx: CanvasRenderingContext2D | null = null;
let ultimo: { x: number; y: number } | null = null;

function ajustarTamano() {
    if (!canvas.value || !contenedor.value) return;
    const ratio = window.devicePixelRatio || 1;
    const ancho = contenedor.value.clientWidth;
    const alto = 200;

    // Conserva el contenido al redimensionar.
    const previo =
        hayTrazos.value && canvas.value.width > 0
            ? canvas.value.toDataURL()
            : null;

    canvas.value.width = ancho * ratio;
    canvas.value.height = alto * ratio;
    canvas.value.style.width = `${ancho}px`;
    canvas.value.style.height = `${alto}px`;

    ctx = canvas.value.getContext('2d');
    if (!ctx) return;
    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#0f172a';

    if (previo) {
        const img = new Image();
        img.onload = () => ctx?.drawImage(img, 0, 0, ancho, alto);
        img.src = previo;
    }
}

function posicion(evento: PointerEvent) {
    const rect = canvas.value!.getBoundingClientRect();
    return { x: evento.clientX - rect.left, y: evento.clientY - rect.top };
}

function iniciar(evento: PointerEvent) {
    evento.preventDefault();
    dibujando.value = true;
    ultimo = posicion(evento);
    canvas.value?.setPointerCapture(evento.pointerId);
}

function mover(evento: PointerEvent) {
    if (!dibujando.value || !ctx || !ultimo) return;
    evento.preventDefault();
    const actual = posicion(evento);
    ctx.beginPath();
    ctx.moveTo(ultimo.x, ultimo.y);
    ctx.lineTo(actual.x, actual.y);
    ctx.stroke();
    ultimo = actual;
    if (!hayTrazos.value) {
        hayTrazos.value = true;
        emit('cambio', false);
    }
}

function terminar(evento: PointerEvent) {
    dibujando.value = false;
    ultimo = null;
    try {
        canvas.value?.releasePointerCapture(evento.pointerId);
    } catch {
        /* noop */
    }
}

function limpiar() {
    if (!ctx || !canvas.value) return;
    ctx.clearRect(0, 0, canvas.value.width, canvas.value.height);
    hayTrazos.value = false;
    emit('cambio', true);
}

function obtenerDataUrl(): string | null {
    if (!hayTrazos.value || !canvas.value) return null;
    return canvas.value.toDataURL('image/png');
}

defineExpose({ limpiar, obtenerDataUrl, estaVacio: () => !hayTrazos.value });

onMounted(() => {
    ajustarTamano();
    window.addEventListener('resize', ajustarTamano);
});

onBeforeUnmount(() => window.removeEventListener('resize', ajustarTamano));
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
