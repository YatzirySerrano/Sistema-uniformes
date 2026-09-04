<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Building2,
    Pencil,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
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

import type { EmpresaAutorizada } from '@/types/sistema';

type EmpChip = { id: number; nombre_comercial: string };
type Talla = {
    id: number;
    valor: string;
    activa: boolean;
    activos_count: number;
    habilitada: boolean;
    empresas: EmpChip[];
};

const props = defineProps<{
    tallas: Talla[];
    empresasAutorizadas: EmpresaAutorizada[];
    empresaSeleccionadaId: number;
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

const empresaActual = computed(
    () =>
        props.empresasAutorizadas.find(
            (e) => e.id === props.empresaSeleccionadaId,
        )?.nombre_comercial ?? 'la empresa seleccionada',
);

// --- Selector de empresa (contexto de la columna "Disponible aquí") ---
const empresaSel = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find(
        (e) => e.id === props.empresaSeleccionadaId,
    ) ?? null,
);
function buscarEmpresas(q: string): Promise<EmpresaAutorizada[]> {
    const t = q.trim().toLowerCase();
    return Promise.resolve(
        t
            ? props.empresasAutorizadas.filter(
                  (e) =>
                      e.nombre_comercial.toLowerCase().includes(t) ||
                      e.codigo.toLowerCase().includes(t),
              )
            : props.empresasAutorizadas,
    );
}
function cambiarEmpresa(e: EmpresaAutorizada | null) {
    if (!e || e.id === props.empresaSeleccionadaId) return;
    router.get(
        '/tallas',
        { empresa_id: e.id },
        { preserveScroll: true, preserveState: false },
    );
}

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

// --- Habilitar / deshabilitar para la empresa seleccionada ---
function alternarEmpresa(t: Talla) {
    router.post(
        `/tallas/${t.id}/empresa`,
        { empresa_id: props.empresaSeleccionadaId },
        { preserveScroll: true, onSuccess: recargar },
    );
}

// --- Diálogo "Empresas que la usan" ---
const detalle = ref<Talla | null>(null);
const buscarEmpDetalle = ref('');
const empresasDetalle = computed(() => {
    if (!detalle.value) return [];
    const habilitadas = new Set(detalle.value.empresas.map((e) => e.id));
    const q = buscarEmpDetalle.value.trim().toLowerCase();
    return props.empresasAutorizadas
        .filter(
            (e) =>
                !q ||
                e.nombre_comercial.toLowerCase().includes(q) ||
                e.codigo.toLowerCase().includes(q),
        )
        .map((e) => ({ ...e, habilitada: habilitadas.has(e.id) }));
});
function alternarEmpresaDetalle(empresaId: number) {
    if (!detalle.value) return;
    router.post(
        `/tallas/${detalle.value.id}/empresa`,
        { empresa_id: empresaId },
        { preserveScroll: true, onSuccess: recargar },
    );
}

// --- Alta ---
const dialogoNueva = ref(false);
const nueva = useForm<{ valor: string; empresa_ids: number[] }>({
    valor: '',
    empresa_ids: [props.empresaSeleccionadaId],
});
const buscarEmpresaAlta = ref('');
const empresasAltaFiltradas = computed(() => {
    const q = buscarEmpresaAlta.value.trim().toLowerCase();
    if (!q) return props.empresasAutorizadas;
    return props.empresasAutorizadas.filter(
        (e) =>
            e.nombre_comercial.toLowerCase().includes(q) ||
            e.codigo.toLowerCase().includes(q),
    );
});
function alternarEmpresaAlta(id: number) {
    const i = nueva.empresa_ids.indexOf(id);
    if (i === -1) nueva.empresa_ids.push(id);
    else nueva.empresa_ids.splice(i, 1);
}
function crear() {
    nueva.post('/tallas', {
        preserveScroll: true,
        onSuccess: () => {
            nueva.reset();
            nueva.empresa_ids = [props.empresaSeleccionadaId];
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

watch(
    () => props.tallas,
    (t) => {
        lista.value = [...t];
        if (detalle.value) {
            detalle.value = t.find((x) => x.id === detalle.value?.id) ?? null;
        }
    },
);
</script>

<template>
    <Head title="Variantes / tallas" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
        <EncabezadoPagina
            titulo="Variantes / tallas"
            descripcion="Catálogo compartido: cada variante (XS, S, M, L; 28, 30; 36R; Unitalla…) existe una sola vez y cada empresa habilita las que usa. El selector de empresa de abajo indica para qué empresa administras la disponibilidad; deshabilitar una variante ahí no la borra: sigue disponible globalmente y en las demás empresas. «Sin variante» no es una fila: un activo que no usa tallas se controla sin variante automáticamente."
        >
            <template #acciones>
                <Button v-if="puedeAdministrar" @click="dialogoNueva = true">
                    <Plus class="size-4" /> Nueva variante / talla
                </Button>
            </template>
        </EncabezadoPagina>

        <div
            v-if="empresasAutorizadas.length > 1"
            class="flex flex-wrap items-center gap-2 text-sm"
        >
            <span class="text-muted-foreground"
                >Administrar disponibilidad para</span
            >
            <div class="w-60">
                <BuscadorAsync
                    :model-value="empresaSel"
                    :buscar="buscarEmpresas"
                    :etiqueta="(e) => (e as EmpresaAutorizada).nombre_comercial"
                    :descripcion="(e) => (e as EmpresaAutorizada).codigo"
                    placeholder="Empresa"
                    placeholder-busqueda="Buscar empresa"
                    @update:model-value="
                        (v) => cambiarEmpresa(v as EmpresaAutorizada | null)
                    "
                />
            </div>
        </div>

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
            descripcion="Crea la primera variante / talla. Si un activo no usa tallas (un mouse, un cable), no necesitas crear nada: se controla sin variante automáticamente."
        />

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="(t, i) in filtradas"
                :key="t.id"
                class="rounded-xl border p-3"
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
                            Activa globalmente
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
                            class="flex shrink-0 flex-col pt-0.5"
                        >
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
                                    Inactiva globalmente
                                </Badge>
                                <button
                                    type="button"
                                    class="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs underline-offset-2 hover:underline"
                                    @click="detalle = t"
                                >
                                    <Building2 class="size-3" />
                                    {{ t.empresas.length }}
                                    {{
                                        t.empresas.length === 1
                                            ? 'empresa la usa'
                                            : 'empresas la usan'
                                    }}
                                </button>
                            </div>

                            <label
                                v-if="puedeAdministrar"
                                class="mt-1.5 flex items-center gap-1.5 text-xs"
                            >
                                <input
                                    type="checkbox"
                                    class="size-4"
                                    :checked="t.habilitada"
                                    :aria-label="`Disponible en ${empresaActual}`"
                                    @change="alternarEmpresa(t)"
                                />
                                Disponible en
                                <span class="font-medium">{{
                                    empresaActual
                                }}</span>
                                <AyudaTooltip
                                    texto="Marca esta variante como disponible para la empresa seleccionada. Deshabilitarla aquí no la elimina: sigue en el catálogo global y en las otras empresas donde esté habilitada. Los activos que ya la usan conservan su historial."
                                    etiqueta="Ayuda sobre disponibilidad por empresa"
                                />
                            </label>
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

        <!-- Diálogo: empresas que usan esta variante -->
        <Dialog
            :open="detalle !== null"
            @update:open="(v: boolean) => !v && (detalle = null)"
        >
            <DialogContent v-if="detalle">
                <DialogHeader>
                    <DialogTitle
                        >Empresas que usan «{{ detalle.valor }}»</DialogTitle
                    >
                    <DialogDescription>
                        Marca o desmarca para habilitar la variante en cada
                        empresa. Es un catálogo compartido: el cambio no afecta
                        a las demás.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Input
                        v-model="buscarEmpDetalle"
                        class="h-8"
                        placeholder="Buscar empresa…"
                        aria-label="Buscar empresa"
                    />
                    <div
                        class="max-h-64 space-y-1 overflow-y-auto rounded-md border p-2"
                    >
                        <label
                            v-for="e in empresasDetalle"
                            :key="e.id"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                class="size-4"
                                :checked="e.habilitada"
                                :disabled="!puedeAdministrar"
                                @change="alternarEmpresaDetalle(e.id)"
                            />
                            {{ e.nombre_comercial }}
                            <span
                                class="text-muted-foreground font-mono text-xs"
                            >
                                {{ e.codigo }}
                            </span>
                        </label>
                    </div>
                </div>
                <DialogFooter>
                    <Button type="button" @click="detalle = null"
                        >Cerrar</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

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
                        Se agrega al catálogo compartido y se habilita para las
                        empresas que marques. Ejemplos: XS, S, M, L, XL · 28,
                        30, 32 · 36R, 38R · Unitalla.
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
                    <div class="grid gap-1.5">
                        <Label>Habilitar para empresas</Label>
                        <Input
                            v-model="buscarEmpresaAlta"
                            class="h-8"
                            placeholder="Buscar empresa…"
                            aria-label="Buscar empresa"
                        />
                        <div
                            class="max-h-36 space-y-1 overflow-y-auto rounded-md border p-2"
                        >
                            <label
                                v-for="e in empresasAltaFiltradas"
                                :key="e.id"
                                class="flex items-center gap-2 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    class="size-4"
                                    :checked="nueva.empresa_ids.includes(e.id)"
                                    @change="alternarEmpresaAlta(e.id)"
                                />
                                {{ e.nombre_comercial }}
                            </label>
                        </div>
                        <InputError :message="nueva.errors.empresa_ids" />
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
