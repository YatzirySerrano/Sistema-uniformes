<script setup lang="ts">
import { FileText, Upload, X } from '@lucide/vue';
import { computed, ref } from 'vue';

/**
 * Subida de archivo reusable: botón claro + arrastrar y soltar, muestra el
 * archivo actual (imagen o nombre), permite cambiarlo o quitarlo. Compatible
 * con Laravel Storage: el consumidor sigue enviando `modelValue` (un `File`)
 * dentro de su `useForm` normal (Inertia serializa `multipart/form-data`
 * automáticamente cuando hay un `File` en el payload).
 */
const props = withDefaults(
    defineProps<{
        modelValue: File | null;
        /** Atributo `accept` del input, p. ej. "image/png,image/jpeg". */
        accept?: string;
        /** Sólo para el texto de ayuda ("Formatos aceptados: ... Peso máximo: N MB"). */
        formatosEtiqueta?: string;
        pesoMaximoMb?: number;
        /** URL del archivo ya guardado (p. ej. el logo actual), si lo hay. */
        archivoActualUrl?: string | null;
        /** Nombre a mostrar del archivo ya guardado cuando no es una imagen. */
        archivoActualNombre?: string | null;
        /** `imagen`: previsualiza con <img>. `documento`: sólo muestra el nombre. */
        tipo?: 'imagen' | 'documento';
        invalido?: boolean;
        disabled?: boolean;
        id?: string;
    }>(),
    { tipo: 'documento' },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: File | null): void;
}>();

const input = ref<HTMLInputElement | null>(null);
const arrastrando = ref(false);

const previsualizacion = computed(() =>
    props.modelValue && props.tipo === 'imagen'
        ? URL.createObjectURL(props.modelValue)
        : null,
);

const hayArchivoActual = computed(
    () =>
        !!props.modelValue ||
        !!props.archivoActualUrl ||
        !!props.archivoActualNombre,
);

function elegir(): void {
    if (props.disabled) return;
    input.value?.click();
}

function alSeleccionar(evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;
    emit('update:modelValue', archivo);
}

function quitar(): void {
    emit('update:modelValue', null);
    if (input.value) input.value.value = '';
}

function alSoltar(evento: DragEvent): void {
    arrastrando.value = false;
    if (props.disabled) return;
    const archivo = evento.dataTransfer?.files?.[0] ?? null;
    if (archivo) emit('update:modelValue', archivo);
}
</script>

<template>
    <div class="grid gap-1.5">
        <div
            class="border-input flex flex-col items-center gap-2 rounded-md border border-dashed p-4 text-center transition-colors"
            :class="[
                arrastrando ? 'border-primary bg-primary/5' : '',
                invalido ? 'border-destructive' : '',
                disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
            ]"
            @click="elegir"
            @dragover.prevent="disabled ? null : (arrastrando = true)"
            @dragleave.prevent="arrastrando = false"
            @drop.prevent="alSoltar"
        >
            <input
                :id="id"
                ref="input"
                type="file"
                class="sr-only"
                :accept="accept"
                :disabled="disabled"
                @change="alSeleccionar"
                @click.stop
            />

            <template v-if="hayArchivoActual">
                <img
                    v-if="
                        tipo === 'imagen' &&
                        (previsualizacion || archivoActualUrl)
                    "
                    :src="previsualizacion ?? archivoActualUrl ?? undefined"
                    alt="Vista previa"
                    class="h-20 w-20 rounded-md object-cover"
                />
                <p class="flex items-center gap-1.5 text-sm">
                    <FileText
                        v-if="tipo === 'documento'"
                        class="size-4 shrink-0"
                    />
                    <span class="truncate">{{
                        modelValue?.name ??
                        archivoActualNombre ??
                        'Archivo actual'
                    }}</span>
                </p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="text-primary text-xs underline"
                        :disabled="disabled"
                        @click.stop="elegir"
                    >
                        Cambiar
                    </button>
                    <button
                        type="button"
                        class="text-destructive flex items-center gap-1 text-xs underline"
                        :disabled="disabled"
                        @click.stop="quitar"
                    >
                        <X class="size-3" /> Quitar
                    </button>
                </div>
            </template>
            <template v-else>
                <Upload class="text-muted-foreground size-6" />
                <p class="text-sm">
                    <span class="text-primary underline"
                        >Selecciona un archivo</span
                    >
                    o arrástralo aquí
                </p>
            </template>

            <p
                v-if="formatosEtiqueta || pesoMaximoMb"
                class="text-muted-foreground text-xs"
            >
                <template v-if="formatosEtiqueta">{{
                    formatosEtiqueta
                }}</template>
                <template v-if="pesoMaximoMb">
                    <template v-if="formatosEtiqueta"> · </template>Peso máximo:
                    {{ pesoMaximoMb }} MB.</template
                >
            </p>
        </div>
    </div>
</template>
