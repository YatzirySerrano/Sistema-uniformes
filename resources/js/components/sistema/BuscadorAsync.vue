<script setup lang="ts">
import { Check, ChevronsUpDown, Loader2, Search, X } from '@lucide/vue';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

/**
 * Combobox de selección única con búsqueda remota (debounced). Pensado para
 * catálogos grandes donde un <select> completo no es viable (p. ej. responsable
 * de un almacén). La búsqueda la resuelve el consumidor mediante `buscar`.
 */
type Opcion = { id: number; [clave: string]: unknown };

const props = defineProps<{
    /** Elemento seleccionado (o null). */
    modelValue: Opcion | null;
    /** Resuelve resultados para el término dado. */
    buscar: (termino: string) => Promise<Opcion[]>;
    /** Texto de cada opción. */
    etiqueta: (item: Opcion) => string;
    /** Texto secundario opcional de cada opción. */
    descripcion?: (item: Opcion) => string;
    /** Texto del botón cuando no hay selección. */
    placeholder?: string;
    /** Texto del campo de búsqueda dentro del desplegable. */
    placeholderBusqueda?: string;
    /** Texto cuando la búsqueda no arroja resultados. */
    sinResultados?: string;
    /** Deshabilita el control. */
    disabled?: boolean;
    /** Marca el control como inválido (para validación). */
    invalido?: boolean;
    id?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: Opcion | null): void;
}>();

const abierto = ref(false);
const termino = ref('');
const resultados = ref<Opcion[]>([]);
const cargando = ref(false);
const contenedor = ref<HTMLElement | null>(null);
let temporizador: ReturnType<typeof setTimeout> | undefined;

async function ejecutarBusqueda(): Promise<void> {
    cargando.value = true;
    try {
        resultados.value = await props.buscar(termino.value.trim());
    } catch {
        resultados.value = [];
    } finally {
        cargando.value = false;
    }
}

watch(termino, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(ejecutarBusqueda, 250);
});

function abrir(): void {
    abierto.value = true;
    if (resultados.value.length === 0) {
        void ejecutarBusqueda();
    }
    nextTick(() => {
        contenedor.value?.querySelector('input')?.focus();
    });
}

function cerrar(): void {
    abierto.value = false;
    termino.value = '';
}

function elegir(item: Opcion): void {
    emit('update:modelValue', item);
    cerrar();
}

function limpiar(): void {
    emit('update:modelValue', null);
}

function alClicFuera(evento: MouseEvent): void {
    if (
        abierto.value &&
        contenedor.value &&
        !contenedor.value.contains(evento.target as Node)
    ) {
        cerrar();
    }
}

document.addEventListener('click', alClicFuera);
onBeforeUnmount(() => {
    document.removeEventListener('click', alClicFuera);
    clearTimeout(temporizador);
});
</script>

<template>
    <div ref="contenedor" class="relative">
        <button
            :id="id"
            type="button"
            :disabled="disabled"
            class="border-input bg-background focus-visible:ring-ring flex h-9 w-full items-center justify-between gap-2 rounded-md border px-3 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
            :class="
                invalido ? 'border-destructive ring-destructive/30 ring-2' : ''
            "
            :aria-expanded="abierto"
            :aria-invalid="invalido || undefined"
            aria-haspopup="listbox"
            @click="disabled ? null : abierto ? cerrar() : abrir()"
        >
            <span v-if="modelValue" class="truncate text-left">
                {{ etiqueta(modelValue) }}
            </span>
            <span v-else class="text-muted-foreground truncate text-left">
                {{ placeholder ?? 'Selecciona…' }}
            </span>
            <span class="flex shrink-0 items-center gap-1">
                <X
                    v-if="modelValue"
                    class="text-muted-foreground hover:text-foreground size-3.5"
                    aria-label="Quitar selección"
                    @click.stop="limpiar"
                />
                <ChevronsUpDown class="text-muted-foreground size-3.5" />
            </span>
        </button>

        <div
            v-if="abierto"
            class="bg-popover absolute z-50 mt-1 w-full rounded-md border shadow-md"
            role="listbox"
        >
            <div class="relative border-b p-2">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-4 size-3.5 -translate-y-1/2"
                />
                <input
                    v-model="termino"
                    type="text"
                    class="border-input bg-background focus-visible:ring-ring h-8 w-full rounded border pr-2 pl-7 text-sm focus-visible:ring-2 focus-visible:outline-none"
                    :placeholder="placeholderBusqueda ?? 'Buscar…'"
                    aria-label="Buscar"
                    @keydown.esc="cerrar"
                />
            </div>

            <ul class="max-h-56 overflow-y-auto py-1">
                <li
                    v-if="cargando"
                    class="text-muted-foreground flex items-center gap-2 px-3 py-2 text-sm"
                >
                    <Loader2 class="size-3.5 animate-spin" /> Buscando…
                </li>
                <li
                    v-else-if="!resultados.length"
                    class="text-muted-foreground px-3 py-2 text-sm"
                >
                    {{ sinResultados ?? 'Sin resultados.' }}
                </li>
                <li
                    v-for="item in resultados"
                    v-else
                    :key="item.id"
                    role="option"
                    :aria-selected="modelValue?.id === item.id"
                    tabindex="0"
                    class="hover:bg-accent focus-visible:bg-accent flex cursor-pointer items-start gap-2 px-3 py-2 text-sm outline-none"
                    @click="elegir(item)"
                    @keydown.enter="elegir(item)"
                    @keydown.space.prevent="elegir(item)"
                >
                    <Check
                        class="mt-0.5 size-3.5 shrink-0"
                        :class="
                            modelValue?.id === item.id
                                ? 'opacity-100'
                                : 'opacity-0'
                        "
                    />
                    <span class="min-w-0">
                        <span class="block truncate">{{ etiqueta(item) }}</span>
                        <span
                            v-if="descripcion"
                            class="text-muted-foreground block truncate text-xs"
                        >
                            {{ descripcion(item) }}
                        </span>
                    </span>
                </li>
            </ul>
        </div>
    </div>
</template>
