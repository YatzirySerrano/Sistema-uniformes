<script setup lang="ts">
import { FileText, Loader2, Upload, X } from '@lucide/vue';
import { computed, ref } from 'vue';

/**
 * Subida de archivo reusable: botón claro + arrastrar y soltar, muestra el
 * archivo actual (imagen o nombre), permite cambiarlo o quitarlo. Compatible
 * con Laravel Storage: el consumidor sigue enviando `modelValue` (un `File`)
 * dentro de su `useForm` normal (Inertia serializa `multipart/form-data`
 * automáticamente cuando hay un `File` en el payload).
 *
 * `tamano` ajusta la presencia visual sin cambiar el comportamiento:
 * `compact`/`normal` para logos e imágenes puntuales, `large` para
 * importaciones (Excel) donde la zona de arrastre es el foco de la pantalla.
 *
 * Semántica de "Quitar" (Casos A–E): "no elegir archivo nuevo" (`modelValue`
 * nulo) NO significa "eliminar la imagen actual". Para eso el consumidor
 * enlaza `v-model:eliminar` a una bandera booleana que viaja al backend; sólo
 * cuando esa bandera es `true` el backend borra la imagen guardada. Elegir un
 * archivo nuevo siempre limpia la bandera (gana la imagen nueva).
 */
const props = withDefaults(
    defineProps<{
        modelValue: File | null;
        /** Bandera "eliminar la imagen ya guardada al guardar el formulario". */
        eliminar?: boolean;
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
        /** Tamaño de presencia visual de la zona de arrastre. */
        tamano?: 'compact' | 'normal' | 'large';
        invalido?: boolean;
        /** Mensaje de error a mostrar bajo la zona de arrastre (opcional; el
         * consumidor puede seguir usando su propio `InputError` externo). */
        mensajeError?: string | null;
        /** Deshabilita la interacción mientras se procesa una subida en curso. */
        cargando?: boolean;
        disabled?: boolean;
        id?: string;
    }>(),
    { tipo: 'documento', tamano: 'normal', eliminar: false },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: File | null): void;
    (e: 'update:eliminar', value: boolean): void;
}>();

const input = ref<HTMLInputElement | null>(null);
const arrastrando = ref(false);

const deshabilitado = computed(() => !!props.disabled || !!props.cargando);

const previsualizacion = computed(() =>
    props.modelValue && props.tipo === 'imagen'
        ? URL.createObjectURL(props.modelValue)
        : null,
);

// Hay una imagen guardada que el usuario todavía no ha marcado para eliminar.
const hayGuardadoVigente = computed(
    () =>
        !props.eliminar &&
        (!!props.archivoActualUrl || !!props.archivoActualNombre),
);

// "Quitar" pulsado sobre una imagen ya guardada, sin archivo nuevo que la
// sustituya: se mostrará el aviso "se eliminará al guardar".
const marcadoParaEliminar = computed(
    () =>
        !!props.eliminar &&
        !props.modelValue &&
        (!!props.archivoActualUrl || !!props.archivoActualNombre),
);

const hayArchivoActual = computed(
    () => !!props.modelValue || hayGuardadoVigente.value,
);

function elegir(): void {
    if (deshabilitado.value) return;
    input.value?.click();
}

function establecerArchivo(archivo: File | null): void {
    emit('update:modelValue', archivo);
    // Elegir un archivo nuevo cancela cualquier intención previa de eliminar:
    // gana la imagen nueva (Caso D).
    if (archivo && props.eliminar) emit('update:eliminar', false);
}

function alSeleccionar(evento: Event): void {
    establecerArchivo((evento.target as HTMLInputElement).files?.[0] ?? null);
}

function quitar(): void {
    // Descarta el archivo nuevo que estuviera elegido...
    if (props.modelValue) emit('update:modelValue', null);
    // ...y, si hay una imagen ya guardada, marca la intención de eliminarla
    // (el backend sólo borra cuando recibe esta bandera).
    if (
        (!!props.archivoActualUrl || !!props.archivoActualNombre) &&
        !props.eliminar
    ) {
        emit('update:eliminar', true);
    }
    if (input.value) input.value.value = '';
}

function deshacerEliminar(): void {
    emit('update:eliminar', false);
}

function alSoltar(evento: DragEvent): void {
    arrastrando.value = false;
    if (deshabilitado.value) return;
    const archivo = evento.dataTransfer?.files?.[0] ?? null;
    if (archivo) establecerArchivo(archivo);
}

const tamanoClase = computed(
    () => ({ compact: 'p-3', normal: 'p-4', large: 'p-8' })[props.tamano],
);

const iconoClase = computed(
    () =>
        ({ compact: 'size-5', normal: 'size-6', large: 'size-10' })[
            props.tamano
        ],
);

const miniaturaClase = computed(
    () =>
        ({
            compact: 'h-12 w-12',
            normal: 'h-20 w-20',
            large: 'h-28 w-28',
        })[props.tamano],
);
</script>

<template>
    <div class="grid gap-1.5">
        <div
            class="border-input relative flex flex-col items-center gap-2 rounded-md border border-dashed text-center transition-colors"
            :class="[
                tamanoClase,
                arrastrando ? 'border-primary bg-primary/5' : '',
                invalido ? 'border-destructive' : '',
                deshabilitado
                    ? 'cursor-not-allowed opacity-60'
                    : 'cursor-pointer',
            ]"
            @click="elegir"
            @dragover.prevent="deshabilitado ? null : (arrastrando = true)"
            @dragleave.prevent="arrastrando = false"
            @drop.prevent="alSoltar"
        >
            <div
                v-if="cargando"
                class="bg-background/70 absolute inset-0 flex items-center justify-center rounded-md"
            >
                <Loader2 class="text-primary size-5 animate-spin" />
            </div>

            <input
                :id="id"
                ref="input"
                type="file"
                class="sr-only"
                :accept="accept"
                :disabled="deshabilitado"
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
                    class="rounded-md object-cover"
                    :class="miniaturaClase"
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
                        :disabled="deshabilitado"
                        @click.stop="elegir"
                    >
                        Cambiar
                    </button>
                    <button
                        type="button"
                        class="text-destructive flex items-center gap-1 text-xs underline"
                        :disabled="deshabilitado"
                        @click.stop="quitar"
                    >
                        <X class="size-3" /> Quitar
                    </button>
                </div>
            </template>
            <template v-else-if="marcadoParaEliminar">
                <div
                    class="text-muted-foreground flex flex-col items-center gap-1"
                    :class="{ 'py-2': tamano !== 'compact' }"
                >
                    <X class="text-destructive" :class="iconoClase" />
                    <p class="text-foreground text-sm font-medium">
                        La imagen se eliminará al guardar
                    </p>
                    <p class="text-xs">
                        Mientras no guardes el formulario, la imagen actual
                        sigue intacta.
                    </p>
                </div>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="text-primary text-xs underline"
                        :disabled="deshabilitado"
                        @click.stop="elegir"
                    >
                        Elegir otra
                    </button>
                    <button
                        type="button"
                        class="text-xs underline"
                        :disabled="deshabilitado"
                        @click.stop="deshacerEliminar"
                    >
                        Deshacer
                    </button>
                </div>
            </template>
            <template v-else>
                <Upload class="text-muted-foreground" :class="iconoClase" />
                <p v-if="tamano === 'large'" class="text-sm font-medium">
                    Arrastra tus archivos aquí
                </p>
                <p class="text-sm">
                    <span v-if="tamano !== 'large'">
                        <span class="text-primary underline"
                            >Selecciona un archivo</span
                        >
                        o arrástralo aquí
                    </span>
                    <span v-else class="text-muted-foreground"
                        >o usa los botones de abajo</span
                    >
                </p>
                <div v-if="tamano === 'large'" class="mt-1 flex gap-2">
                    <button
                        type="button"
                        class="border-input hover:bg-accent rounded-md border px-3 py-1.5 text-xs font-medium transition-colors"
                        :disabled="deshabilitado"
                        @click.stop="elegir"
                    >
                        Buscar archivos
                    </button>
                    <button
                        type="button"
                        class="text-muted-foreground rounded-md px-3 py-1.5 text-xs underline disabled:pointer-events-none disabled:opacity-40"
                        :disabled="deshabilitado || !modelValue"
                        @click.stop="quitar"
                    >
                        Limpiar
                    </button>
                </div>
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
        <p v-if="mensajeError" class="text-destructive text-xs">
            {{ mensajeError }}
        </p>
    </div>
</template>
