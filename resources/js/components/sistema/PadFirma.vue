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

// Respaldo del trazo actual como data URL. Se conserva entre
// ocultamientos del pad (p. ej. un paso oculto con `v-show`/`display:none`):
// al volver a mostrarse se restaura, así cambiar de paso nunca borra la
// firma en silencio.
let respaldo: string | null = null;
let observador: ResizeObserver | null = null;

const ALTO = 200;

function ajustarTamano() {
    if (!canvas.value || !contenedor.value) return;

    const ratio = window.devicePixelRatio || 1;
    const ancho = contenedor.value.clientWidth;

    // Guarda lo dibujado antes de tocar el tamaño del canvas (reasignar
    // width/height lo limpia). Sólo si hay algo y el canvas tiene tamaño
    // real; si está oculto el `width` sigue siendo el último válido.
    if (hayTrazos.value && canvas.value.width > 0) {
        respaldo = canvas.value.toDataURL();
    }

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

    ctx = canvas.value.getContext('2d');
    if (!ctx) return;
    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#0f172a';

    if (respaldo) {
        const img = new Image();
        img.onload = () => ctx?.drawImage(img, 0, 0, ancho, ALTO);
        img.src = respaldo;
    }
}

function posicion(evento: PointerEvent) {
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
    // Persiste el respaldo tras soltar, para sobrevivir a un ocultamiento.
    if (hayTrazos.value && canvas.value && canvas.value.width > 0) {
        respaldo = canvas.value.toDataURL();
    }
    try {
        canvas.value?.releasePointerCapture(evento.pointerId);
    } catch {
        /* noop */
    }
}

function limpiar() {
    respaldo = null;
    if (!ctx || !canvas.value) return;
    ctx.clearRect(0, 0, canvas.value.width, canvas.value.height);
    hayTrazos.value = false;
    emit('cambio', true);
}

function obtenerDataUrl(): string | null {
    if (!hayTrazos.value || !canvas.value) return null;
    // Si el canvas está oculto en este instante, usa el respaldo.
    if (canvas.value.width === 0) return respaldo;
    return canvas.value.toDataURL('image/png');
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
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', ajustarTamano);
    observador?.disconnect();
    observador = null;
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
