<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Talla = { id: number; valor: string; activa: boolean };

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

// --- Alta ---
const dialogoNueva = ref(false);
const nueva = useForm({ valor: '' });
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
function mover(indice: number, delta: number) {
    const destino = indice + delta;
    if (destino < 0 || destino >= lista.value.length) return;
    const copia = [...lista.value];
    [copia[indice], copia[destino]] = [copia[destino], copia[indice]];
    lista.value = copia;
    router.post(
        '/tallas/reordenar',
        { orden: copia.map((t) => t.id) },
        { preserveScroll: true, preserveState: true },
    );
}

function recargar() {
    router.reload({
        only: ['tallas'],
        onSuccess: () => (lista.value = [...props.tallas]),
    });
}
</script>

<template>
    <Head title="Variantes / tallas" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
        <EncabezadoPagina
            titulo="Variantes / tallas"
            descripcion="Define las variantes que usan tus activos (XS, S, M, L; 28, 30, 32; 36R, 38R; Unitalla…). El orden en que aparecen aquí es el orden en que se ofrecen al capturar."
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
            El reordenamiento con ↑ / ↓ se desactiva mientras filtras. Limpia la
            búsqueda para reordenar.
        </p>

        <EstadoVacio
            v-if="!lista.length"
            titulo="Aún no hay variantes"
            descripcion="Crea la primera variante / talla. Si un activo no usa tallas (un mouse, un cable), no necesitas crear nada: se marca como «sin variante» automáticamente."
        />

        <ul v-else class="divide-y rounded-xl border">
            <li
                v-for="(t, i) in filtradas"
                :key="t.id"
                class="flex flex-wrap items-center gap-2 px-3 py-2.5"
            >
                <div v-if="puedeReordenar" class="flex flex-col">
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-foreground disabled:opacity-30"
                        :disabled="i === 0"
                        aria-label="Mover arriba"
                        title="Mover arriba"
                        @click="mover(i, -1)"
                    >
                        <ArrowUp class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-foreground disabled:opacity-30"
                        :disabled="i === filtradas.length - 1"
                        aria-label="Mover abajo"
                        title="Mover abajo"
                        @click="mover(i, 1)"
                    >
                        <ArrowDown class="size-4" />
                    </button>
                </div>

                <template v-if="editando === t.id">
                    <Input
                        v-model="edicion.valor"
                        class="h-8 w-28"
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
                    <Button size="sm" variant="ghost" @click="editando = null">
                        Cancelar
                    </Button>
                </template>

                <template v-else>
                    <span class="font-medium">{{ t.valor }}</span>
                    <Badge v-if="!t.activa" variant="outline" class="text-xs">
                        Inactiva
                    </Badge>
                    <span class="ml-auto flex items-center gap-1">
                        <Button
                            v-if="puedeAdministrar"
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="`Editar ${t.valor}`"
                            @click="abrirEdicion(t)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            v-if="puedeAdministrar"
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="`Eliminar ${t.valor}`"
                            @click="eliminar(t.id)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </span>
                </template>
            </li>
        </ul>

        <Dialog v-model:open="dialogoNueva">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-1.5">
                        Nueva variante / talla
                        <AyudaTooltip
                            texto="No captures ningún número de orden: la variante se agrega al final y luego la mueves con ↑ / ↓."
                            etiqueta="Ayuda sobre variantes"
                        />
                    </DialogTitle>
                    <DialogDescription>
                        Ejemplos: XS, S, M, L, XL · 28, 30, 32 · 36R, 38R ·
                        Unitalla.
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
