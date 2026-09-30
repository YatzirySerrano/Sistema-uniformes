<script setup lang="ts">
import { FileUp, PenLine } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import PadFirma from '@/components/sistema/PadFirma.vue';
import SubidaArchivo from '@/components/sistema/SubidaArchivo.vue';

/**
 * Firma de QUIEN RECIBE / QUIEN DEVUELVE en Entregas y Devoluciones: se
 * dibuja en el pad (presencial) o se sube un archivo (firma remota, p. ej.
 * un envío por paquetería a otra sucursal). UNA sola fuente: al cambiar de
 * método se descarta lo capturado con el otro, para nunca enviar dos firmas
 * contradictorias. El backend valida de nuevo el método, el MIME real, la
 * extensión y el peso — esto es sólo la experiencia de captura.
 *
 * Expone la misma API que `PadFirma` (`obtenerDataUrl`, `recalibrar`,
 * `limpiar`, `estaVacio`) y emite `cambio(vacio)` para cualquiera de los dos
 * métodos, así el wizard no cambia su lógica de "falta la firma".
 */
export type MetodoFirma = 'dibujada' | 'archivo';

const props = defineProps<{
    metodo: MetodoFirma;
    archivo: File | null;
    /** Error del backend para `firma_archivo` (se muestra bajo la zona). */
    error?: string | null;
    /** Quién firma, para los textos ("el colaborador", "quien devuelve"). */
    quien?: string;
}>();

const emit = defineEmits<{
    (e: 'update:metodo', valor: MetodoFirma): void;
    (e: 'update:archivo', valor: File | null): void;
    (e: 'cambio', vacio: boolean): void;
}>();

const PESO_MAXIMO_MB = 5;
const TIPOS_PERMITIDOS = [
    'image/png',
    'image/jpeg',
    'image/webp',
    'application/pdf',
];

const pad = ref<InstanceType<typeof PadFirma> | null>(null);
const padVacio = ref(true);
const errorLocal = ref<string | null>(null);

const vacio = computed(() =>
    props.metodo === 'archivo' ? props.archivo === null : padVacio.value,
);
watch(vacio, (v) => emit('cambio', v));

function elegirMetodo(metodo: MetodoFirma): void {
    if (metodo === props.metodo) return;
    // Una sola fuente de firma: lo capturado con el otro método se descarta.
    if (metodo === 'archivo') {
        pad.value?.limpiar();
    } else {
        emit('update:archivo', null);
        errorLocal.value = null;
    }
    emit('update:metodo', metodo);
}

function alElegirArchivo(archivo: File | null): void {
    errorLocal.value = null;
    if (archivo && !TIPOS_PERMITIDOS.includes(archivo.type)) {
        errorLocal.value =
            'Formato no admitido. Sube una imagen PNG, JPG o WEBP, o un PDF.';
        emit('update:archivo', null);
        return;
    }
    if (archivo && archivo.size > PESO_MAXIMO_MB * 1024 * 1024) {
        errorLocal.value = `El archivo pesa más de ${PESO_MAXIMO_MB} MB.`;
        emit('update:archivo', null);
        return;
    }
    emit('update:archivo', archivo);
}

defineExpose({
    /** Data URL de la firma dibujada; vacío si el método es archivo. */
    obtenerDataUrl: (): string | null =>
        props.metodo === 'dibujada'
            ? (pad.value?.obtenerDataUrl() ?? null)
            : null,
    recalibrar: (): void => pad.value?.recalibrar(),
    limpiar: (): void => {
        pad.value?.limpiar();
        emit('update:archivo', null);
    },
    estaVacio: (): boolean => vacio.value,
});
</script>

<template>
    <div class="space-y-3">
        <div
            class="grid gap-2 sm:grid-cols-2"
            role="radiogroup"
            aria-label="Cómo se registra la firma"
        >
            <button
                type="button"
                role="radio"
                :aria-checked="metodo === 'dibujada'"
                class="flex items-start gap-2 rounded-lg border p-2.5 text-left text-sm transition-colors"
                :class="
                    metodo === 'dibujada'
                        ? 'border-primary bg-primary/5'
                        : 'hover:bg-muted/50'
                "
                @click="elegirMetodo('dibujada')"
            >
                <PenLine class="mt-0.5 size-4 shrink-0" />
                <span>
                    <span class="font-medium">Dibujar firma</span>
                    <span class="text-muted-foreground block text-xs"
                        >Firma aquí mismo, en este dispositivo.</span
                    >
                </span>
            </button>
            <button
                type="button"
                role="radio"
                :aria-checked="metodo === 'archivo'"
                class="flex items-start gap-2 rounded-lg border p-2.5 text-left text-sm transition-colors"
                :class="
                    metodo === 'archivo'
                        ? 'border-primary bg-primary/5'
                        : 'hover:bg-muted/50'
                "
                @click="elegirMetodo('archivo')"
            >
                <FileUp class="mt-0.5 size-4 shrink-0" />
                <span>
                    <span class="font-medium">Subir archivo de firma</span>
                    <span class="text-muted-foreground block text-xs"
                        >Firma a distancia: {{ quien ?? 'quien firma' }} la
                        envía como imagen o PDF.</span
                    >
                </span>
            </button>
        </div>

        <!-- El pad se conserva montado (v-show) para no perder su calibración. -->
        <div v-show="metodo === 'dibujada'">
            <PadFirma ref="pad" @cambio="(v: boolean) => (padVacio = v)" />
        </div>

        <div v-if="metodo === 'archivo'" class="space-y-1.5">
            <SubidaArchivo
                :model-value="archivo"
                tipo="documento"
                accept="image/png,image/jpeg,image/webp,application/pdf,.png,.jpg,.jpeg,.webp,.pdf"
                formatos-etiqueta="PNG, JPG, WEBP o PDF"
                :peso-maximo-mb="PESO_MAXIMO_MB"
                :invalido="!!(errorLocal || error)"
                :mensaje-error="errorLocal ?? error ?? null"
                @update:model-value="alElegirArchivo"
            />
            <p class="text-muted-foreground text-xs">
                Una imagen se usa como firma visual en el comprobante. Un PDF se
                conserva íntegro como evidencia documental y el comprobante
                indica «Firma: documento adjunto». No se toma foto con la
                cámara.
            </p>
        </div>
    </div>
</template>
