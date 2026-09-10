<script setup lang="ts">
import { Camera, FileText, ImagePlus, RefreshCw, Upload, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { useCamaraFoto } from '@/composables/useCamaraFoto';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * Captura de evidencia reutilizable (por renglón de entrega / devolución y
 * para la INE durante una entrega). El consumidor mantiene el `File` en su
 * `useForm`; `origen` indica si vino de la cámara o de un archivo.
 *
 * Semántica de "Quitar": limpia REALMENTE la selección pendiente
 * (`modelValue = null`). No borra nada histórico — sólo descarta lo que aún
 * no se ha enviado.
 */
const archivo = defineModel<File | null>({ default: null });
const origen = defineModel<'camara' | 'archivo' | null>('origen', {
    default: null,
});

const props = withDefaults(
    defineProps<{
        etiqueta?: string;
        /** `image/*` por defecto; añade `,application/pdf` para la INE. */
        accept?: string;
        permitePdf?: boolean;
        disabled?: boolean;
    }>(),
    { etiqueta: 'Agregar evidencia', accept: 'image/*' },
);

const abierto = ref(false);
const modo = ref<'elegir' | 'camara' | 'previa'>('elegir');
const pendiente = ref<File | null>(null);
const videoEl = ref<HTMLVideoElement | null>(null);
const camara = useCamaraFoto();
const inputEl = ref<HTMLInputElement | null>(null);

const previaUrl = computed(() =>
    archivo.value && archivo.value.type.startsWith('image/')
        ? URL.createObjectURL(archivo.value)
        : null,
);
const pendienteUrl = computed(() =>
    pendiente.value && pendiente.value.type.startsWith('image/')
        ? URL.createObjectURL(pendiente.value)
        : null,
);
const esPdf = computed(() => archivo.value?.type === 'application/pdf');

function abrir(): void {
    if (props.disabled) return;
    pendiente.value = null;
    modo.value = 'elegir';
    abierto.value = true;
}

watch(abierto, (v) => {
    if (!v) camara.detener();
});

async function iniciarCamara(): Promise<void> {
    modo.value = 'camara';
    await nextTick();
    if (videoEl.value) await camara.iniciar(videoEl.value);
}

async function tomarFoto(): Promise<void> {
    const f = await camara.capturar();
    if (f) {
        pendiente.value = f;
        origen.value = 'camara';
        camara.detener();
        modo.value = 'previa';
    }
}

function alSeleccionarArchivo(e: Event): void {
    const f = (e.target as HTMLInputElement).files?.[0] ?? null;
    if (f) {
        pendiente.value = f;
        origen.value = 'archivo';
        modo.value = 'previa';
    }
}

function confirmar(): void {
    archivo.value = pendiente.value;
    abierto.value = false;
}

function reintentar(): void {
    pendiente.value = null;
    modo.value = 'elegir';
}

function quitar(): void {
    archivo.value = null;
    origen.value = null;
    if (inputEl.value) inputEl.value.value = '';
}

// Si el consumidor limpia el modelo desde fuera (p. ej. al cambiar el activo /
// la unidad del renglón), hay que resetear el estado interno: si no, queda un
// `pendiente` viejo y el `<input type=file>` conserva su valor, así que
// re-elegir el MISMO archivo no dispararía `@change`.
watch(archivo, (nuevo) => {
    if (nuevo === null) {
        pendiente.value = null;
        if (inputEl.value) inputEl.value.value = '';
    }
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <template v-if="archivo">
            <img
                v-if="previaUrl"
                :src="previaUrl"
                alt="Evidencia"
                class="size-12 rounded-md border object-cover"
            />
            <span v-else-if="esPdf" class="flex items-center gap-1 text-xs">
                <FileText class="size-4" /> {{ archivo.name }}
            </span>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                :disabled="disabled"
                @click="abrir"
            >
                <RefreshCw class="size-3.5" /> Cambiar
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="text-destructive"
                :disabled="disabled"
                @click="quitar"
            >
                <X class="size-3.5" /> Quitar
            </Button>
        </template>
        <Button
            v-else
            type="button"
            variant="outline"
            size="sm"
            :disabled="disabled"
            @click="abrir"
        >
            <ImagePlus class="size-4" /> {{ etiqueta }}
        </Button>

        <Dialog v-model:open="abierto">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ etiqueta }}</DialogTitle>
                    <DialogDescription>
                        Toma una foto con la cámara o sube un archivo.
                        {{
                            permitePdf
                                ? 'Se acepta imagen o PDF.'
                                : 'Se acepta imagen (JPG, PNG o WebP).'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div v-if="modo === 'elegir'" class="grid gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="iniciarCamara"
                    >
                        <Camera class="size-4" /> Tomar foto
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="inputEl?.click()"
                    >
                        <Upload class="size-4" /> Subir archivo
                    </Button>
                    <input
                        ref="inputEl"
                        type="file"
                        class="sr-only"
                        :accept="
                            permitePdf ? `${accept},application/pdf` : accept
                        "
                        @change="alSeleccionarArchivo"
                    />
                </div>

                <div v-else-if="modo === 'camara'" class="grid gap-2">
                    <video
                        ref="videoEl"
                        class="w-full rounded-md border bg-black"
                        playsinline
                    />
                    <p
                        v-if="camara.mensajeError.value"
                        class="text-destructive text-xs"
                    >
                        {{ camara.mensajeError.value }}
                    </p>
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            class="flex-1"
                            :disabled="camara.estado.value !== 'activa'"
                            @click="tomarFoto"
                        >
                            <Camera class="size-4" /> Capturar
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="
                                camara.detener();
                                modo = 'elegir';
                            "
                        >
                            Atrás
                        </Button>
                    </div>
                </div>

                <div v-else class="grid gap-2">
                    <img
                        v-if="pendienteUrl"
                        :src="pendienteUrl"
                        alt="Vista previa de la evidencia"
                        class="max-h-64 w-full rounded-md border object-contain"
                    />
                    <p v-else class="flex items-center gap-1.5 text-sm">
                        <FileText class="size-4" />
                        {{ pendiente?.name }}
                    </p>
                    <div class="flex gap-2">
                        <Button type="button" class="flex-1" @click="confirmar">
                            Usar
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="reintentar"
                        >
                            Elegir otra
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
