<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    GripVertical,
    Pencil,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Talla = {
    id: number;
    valor: string;
    activa: boolean;
    activos_count: number;
};

const props = defineProps<{
    tallas: Talla[];
    puedeAdministrar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Activos', href: '/activos' },
            { title: 'Variantes / tallas', href: '/tallas' },
        ],
    },
});

// Copia local para el reordenamiento optimista.
const lista = ref<Talla[]>([...props.tallas]);
const buscar = ref('');

const filtradas = computed(() => {
    const q = buscar.value.trim().toLowerCase();
    return q
        ? lista.value.filter((t) => t.valor.toLowerCase().includes(q))
        : lista.value;
});
const puedeReordenar = computed(
    () => props.puedeAdministrar && !buscar.value.trim(),
);

function recargar() {
    router.reload({
        only: ['tallas'],
        onSuccess: () => (lista.value = [...props.tallas]),
    });
}

// --- Alta ---
const dialogoNueva = ref(false);
const nueva = useForm<{ valor: string }>({ valor: '' });
function crear() {
    nueva.post('/tallas', {
        preserveScroll: true,
        onSuccess: () => {
            nueva.reset();
            dialogoNueva.value = false;
            recargar();
        },
    });
}

// --- Edición ---
const editando = ref<number | null>(null);
const edicion = useForm({ valor: '', activa: true });
function abrirEdicion(t: Talla) {
    editando.value = t.id;
    edicion.defaults({ valor: t.valor, activa: t.activa });
    edicion.reset();
}
function guardarEdicion(id: number) {
    edicion.put(`/tallas/${id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editando.value = null;
            recargar();
        },
    });
}

function eliminar(id: number) {
    router.delete(`/tallas/${id}`, {
        preserveScroll: true,
        onSuccess: recargar,
    });
}

// --- Reordenar ---
function guardarOrden(copia: Talla[]) {
    lista.value = copia;
    router.post(
        '/tallas/reordenar',
        { orden: copia.map((t) => t.id) },
        { preserveScroll: true, preserveState: true },
    );
}

function mover(indice: number, delta: number) {
    const destino = indice + delta;
    if (destino < 0 || destino >= lista.value.length) return;
    const copia = [...lista.value];
    [copia[indice], copia[destino]] = [copia[destino], copia[indice]];
    guardarOrden(copia);
}

// Arrastrar y soltar (además de ↑/↓, que siguen siendo el camino accesible
// por teclado): sólo activo cuando `puedeReordenar` (sin filtro de búsqueda,
// igual que los botones).
const indiceArrastrado = ref<number | null>(null);
function alSoltar(indiceDestino: number) {
    const origen = indiceArrastrado.value;
    indiceArrastrado.value = null;
    if (origen === null || origen === indiceDestino) return;

    const copia = [...lista.value];
    const [movido] = copia.splice(origen, 1);
    copia.splice(indiceDestino, 0, movido);
    guardarOrden(copia);
}

watch(
    () => props.tallas,
    (t) => {
        lista.value = [...t];
    },
);
</script>

<template>
    <Head title="Variantes / tallas" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
        <EncabezadoPagina
            titulo="Variantes / tallas"
            descripcion="Catálogo compartido de la plataforma: cada variante (XS, S, M, L; 28, 30; 36R; Unitalla…) existe una sola vez y está disponible para todas las empresas por igual. «Sin variante» no es una fila: un activo que no usa tallas se controla sin variante automáticamente."
        >
            <template #acciones>
                <Button v-if="puedeAdministrar" @click="dialogoNueva = true">
                    <Plus class="size-4" /> Nueva variante / talla
                </Button>
            </template>
        </EncabezadoPagina>

        <div v-if="lista.length > 6" class="relative">
            <Input
                v-model="buscar"
                type="search"
                placeholder="Buscar variante / talla…"
                aria-label="Buscar variante o talla"
            />
        </div>
        <p
            v-if="lista.length > 6 && buscar.trim()"
            class="text-muted-foreground -mt-3 text-xs"
        >
            El reordenamiento (arrastrar o ↑ / ↓) se desactiva mientras filtras.
            Limpia la búsqueda para reordenar.
        </p>

        <EstadoVacio
            v-if="!lista.length"
            titulo="Aún no hay variantes"
            descripcion="Crea la primera variante / talla. Si un activo no usa tallas (un mouse, un cable), no necesitas crear nada: se controla sin variante automáticamente."
        />

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="(t, i) in filtradas"
                :key="t.id"
                class="rounded-xl border p-3"
                :class="{ 'opacity-50': indiceArrastrado === i }"
                :draggable="puedeReordenar"
                @dragstart="indiceArrastrado = i"
                @dragend="indiceArrastrado = null"
                @dragover.prevent
                @drop="alSoltar(i)"
            >
                <template v-if="editando === t.id">
                    <div class="flex flex-wrap items-center gap-2">
                        <Input
                            v-model="edicion.valor"
                            class="h-8 w-32"
                            aria-label="Nombre de la variante"
                            @keydown.enter.prevent="guardarEdicion(t.id)"
                        />
                        <label class="flex items-center gap-1.5 text-xs">
                            <input
                                v-model="edicion.activa"
                                type="checkbox"
                                class="size-4"
                            />
                            Activa
                        </label>
                        <Button size="sm" @click="guardarEdicion(t.id)">
                            Guardar
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="editando = null"
                        >
                            Cancelar
                        </Button>
                    </div>
                    <InputError :message="edicion.errors.valor" class="mt-1" />
                </template>

                <template v-else>
                    <div class="flex items-start gap-2">
                        <div
                            v-if="puedeReordenar"
                            class="flex shrink-0 flex-col items-center pt-0.5"
                        >
                            <GripVertical
                                class="text-muted-foreground/60 size-4 cursor-grab"
                                aria-hidden="true"
                            />
                            <button
                                type="button"
                                class="text-muted-foreground hover:text-foreground disabled:opacity-30"
                                :disabled="i === 0"
                                :aria-label="`Mover ${t.valor} arriba`"
                                :title="`Mover ${t.valor} arriba`"
                                @click="mover(i, -1)"
                            >
                                <ArrowUp class="size-4" />
                            </button>
                            <button
                                type="button"
                                class="text-muted-foreground hover:text-foreground disabled:opacity-30"
                                :disabled="i === filtradas.length - 1"
                                :aria-label="`Mover ${t.valor} abajo`"
                                :title="`Mover ${t.valor} abajo`"
                                @click="mover(i, 1)"
                            >
                                <ArrowDown class="size-4" />
                            </button>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="font-medium">{{ t.valor }}</span>
                                <Badge
                                    v-if="!t.activa"
                                    variant="outline"
                                    class="text-xs"
                                >
                                    Inactiva
                                </Badge>
                                <span class="text-muted-foreground text-xs">
                                    Se usa en {{ t.activos_count }}
                                    {{
                                        t.activos_count === 1
                                            ? 'activo'
                                            : 'activos'
                                    }}
                                </span>
                            </div>
                        </div>

                        <div
                            v-if="puedeAdministrar"
                            class="flex shrink-0 items-center gap-1"
                        >
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="`Editar ${t.valor}`"
                                @click="abrirEdicion(t)"
                            >
                                <Pencil class="size-4" />
                            </Button>
                            <Button
                                v-if="t.activos_count === 0"
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="`Eliminar ${t.valor}`"
                                @click="eliminar(t.id)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </div>
                </template>
            </li>
        </ul>

        <Dialog v-model:open="dialogoNueva">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Nueva variante / talla</DialogTitle>
                    <DialogDescription>
                        Se agrega al catálogo compartido, disponible de
                        inmediato para todas las empresas y al final del orden
                        actual (luego la mueves con ↑ / ↓). Ejemplos: XS, S, M,
                        L, XL · 28, 30, 32 · 36R, 38R · Unitalla.
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-3" @submit.prevent="crear">
                    <div class="grid gap-1.5">
                        <Label for="nueva-valor"
                            >Nombre de la variante / talla</Label
                        >
                        <Input
                            id="nueva-valor"
                            v-model="nueva.valor"
                            autofocus
                            placeholder="M"
                        />
                        <InputError :message="nueva.errors.valor" />
                    </div>
                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            @click="dialogoNueva = false"
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" :disabled="nueva.processing">
                            Agregar
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
