<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
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

type Tipo = {
    id: number;
    nombre: string;
    codigo: string | null;
    activo: boolean;
    activos_count: number;
};
type Categoria = {
    id: number;
    nombre: string;
    tipo_activo_id: number | null;
    tipo: string | null;
    activa: boolean;
    activos_count: number;
};

const props = defineProps<{
    tipos: Tipo[];
    categorias: Categoria[];
    tiposSelect: { id: number; nombre: string }[];
    empresasAutorizadas: EmpresaAutorizada[];
    empresaSeleccionadaId: number;
    permisos: {
        administrar_tipos: boolean;
        administrar_categorias: boolean;
    };
}>();

const empresaId = ref<number>(props.empresaSeleccionadaId);
watch(empresaId, (id) => {
    router.get(
        '/activos-catalogos',
        { empresa_id: id },
        { preserveScroll: true, preserveState: false },
    );
});

// --- Filtros locales (los catálogos pueden crecer) ---
const fTipo = ref({ buscar: '', estado: '' as '' | 'activos' | 'inactivos' });
const fCat = ref({
    buscar: '',
    tipo_id: '' as number | '',
    estado: '' as '' | 'activas' | 'inactivas',
});

const tiposFiltrados = computed(() => {
    const q = fTipo.value.buscar.trim().toLowerCase();
    return props.tipos.filter(
        (t) =>
            (!q ||
                t.nombre.toLowerCase().includes(q) ||
                (t.codigo ?? '').toLowerCase().includes(q)) &&
            (fTipo.value.estado === '' ||
                (fTipo.value.estado === 'activos') === t.activo),
    );
});
const categoriasFiltradas = computed(() => {
    const q = fCat.value.buscar.trim().toLowerCase();
    return props.categorias.filter(
        (c) =>
            (!q ||
                c.nombre.toLowerCase().includes(q) ||
                (c.tipo ?? '').toLowerCase().includes(q)) &&
            (fCat.value.tipo_id === '' ||
                c.tipo_activo_id === fCat.value.tipo_id) &&
            (fCat.value.estado === '' ||
                (fCat.value.estado === 'activas') === c.activa),
    );
});
const hayFiltroTipo = computed(
    () => !!fTipo.value.buscar || fTipo.value.estado !== '',
);
const hayFiltroCat = computed(
    () =>
        !!fCat.value.buscar ||
        fCat.value.tipo_id !== '' ||
        fCat.value.estado !== '',
);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Activos', href: '/activos' },
            { title: 'Tipos y categorías', href: '/activos-catalogos' },
        ],
    },
});

const tipoForm = useForm({
    nombre: '',
    empresa_id: props.empresaSeleccionadaId,
});
const categoriaForm = useForm({
    nombre: '',
    tipo_activo_id: '' as number | '',
    empresa_id: props.empresaSeleccionadaId,
});

const editandoTipo = ref<number | null>(null);
const tipoEdit = useForm({ nombre: '', activo: true });
const editandoCategoria = ref<number | null>(null);
const categoriaEdit = useForm({
    nombre: '',
    tipo_activo_id: '' as number | '',
    activa: true,
});

function crearTipo() {
    tipoForm.post('/tipos-activo', {
        preserveScroll: true,
        onSuccess: () => tipoForm.reset(),
    });
}
function abrirEdicionTipo(t: Tipo) {
    editandoTipo.value = t.id;
    tipoEdit.defaults({ nombre: t.nombre, activo: t.activo });
    tipoEdit.reset();
}
function guardarTipo(id: number) {
    tipoEdit.put(`/tipos-activo/${id}`, {
        preserveScroll: true,
        onSuccess: () => (editandoTipo.value = null),
    });
}
// --- Confirmación de activar / desactivar ---
type Confirmacion = {
    recurso: 'tipo' | 'categoria';
    id: number;
    nombre: string;
    activar: boolean;
};
const confirmacion = ref<Confirmacion | null>(null);

function pedirConfirmacion(c: Confirmacion) {
    confirmacion.value = c;
}
function confirmarEstado() {
    const c = confirmacion.value;
    if (!c) return;
    const url =
        c.recurso === 'tipo'
            ? `/tipos-activo/${c.id}/estado`
            : `/categorias-activo/${c.id}/estado`;
    router.post(
        url,
        {},
        { preserveScroll: true, onFinish: () => (confirmacion.value = null) },
    );
}

function crearCategoria() {
    categoriaForm.post('/categorias-activo', {
        preserveScroll: true,
        onSuccess: () => categoriaForm.reset(),
    });
}
function abrirEdicionCategoria(c: Categoria) {
    editandoCategoria.value = c.id;
    categoriaEdit.defaults({
        nombre: c.nombre,
        tipo_activo_id: c.tipo_activo_id ?? '',
        activa: c.activa,
    });
    categoriaEdit.reset();
}
function guardarCategoria(id: number) {
    categoriaEdit.put(`/categorias-activo/${id}`, {
        preserveScroll: true,
        onSuccess: () => (editandoCategoria.value = null),
    });
}
</script>

<template>
    <Head title="Tipos y categorías de activo" />

    <div class="flex w-full flex-col gap-6 p-4">
        <label
            v-if="empresasAutorizadas.length > 1"
            class="flex w-fit items-center gap-1.5 text-sm"
        >
            <span class="text-muted-foreground">Empresa</span>
            <select
                v-model="empresaId"
                class="border-input bg-background h-9 rounded-md border px-2.5 text-sm"
                aria-label="Empresa de los catálogos"
            >
                <option
                    v-for="e in empresasAutorizadas"
                    :key="e.id"
                    :value="e.id"
                >
                    {{ e.nombre_comercial }}
                </option>
            </select>
        </label>

        <EncabezadoPagina
            titulo="Tipos y categorías de activo"
            descripcion="Catálogos por empresa. El tipo es la naturaleza del activo (Prenda, Equipo de cómputo…); la categoría es qué es dentro de su tipo (Camisola, Laptop…)."
        >
            <template #acciones>
                <Button variant="ghost" as-child>
                    <Link href="/activos">Volver a activos</Link>
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Tipos de activo -->
            <section class="min-w-0 space-y-3 rounded-xl border p-4">
                <div>
                    <h2 class="text-sm font-semibold">Tipos de activo</h2>
                    <p class="text-muted-foreground text-xs">
                        Clasificación general o naturaleza del activo: Prenda,
                        Equipo de cómputo, Dispositivo móvil, Accesorio…
                    </p>
                </div>

                <form
                    v-if="permisos.administrar_tipos"
                    class="flex flex-wrap items-end gap-2"
                    @submit.prevent="crearTipo"
                >
                    <div class="grid min-w-0 flex-1 gap-1.5">
                        <Label for="nuevo-tipo">Nuevo tipo</Label>
                        <Input
                            id="nuevo-tipo"
                            v-model="tipoForm.nombre"
                            placeholder="p. ej. Equipo de protección"
                        />
                    </div>
                    <Button type="submit" :disabled="tipoForm.processing">
                        <Plus class="size-4" /> Agregar
                    </Button>
                </form>
                <p
                    v-if="tipoForm.errors.nombre"
                    class="text-destructive text-xs"
                >
                    {{ tipoForm.errors.nombre }}
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <Input
                        v-model="fTipo.buscar"
                        type="search"
                        placeholder="Buscar tipo…"
                        aria-label="Buscar tipo de activo"
                        class="h-8 w-40"
                    />
                    <select
                        v-model="fTipo.estado"
                        class="border-input bg-background h-8 rounded-md border px-2 text-sm"
                        aria-label="Filtrar por estado"
                    >
                        <option value="">Todos</option>
                        <option value="activos">Activos</option>
                        <option value="inactivos">Inactivos</option>
                    </select>
                    <Button
                        v-if="hayFiltroTipo"
                        variant="ghost"
                        size="sm"
                        @click="fTipo = { buscar: '', estado: '' }"
                    >
                        Limpiar filtros
                    </Button>
                </div>

                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full min-w-[420px] text-sm">
                        <thead
                            class="bg-muted/50 text-muted-foreground text-left"
                        >
                            <tr>
                                <th class="px-3 py-2 font-medium">Nombre</th>
                                <th class="px-3 py-2 font-medium">Activos</th>
                                <th class="px-3 py-2 font-medium">Estado</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="t in tiposFiltrados" :key="t.id">
                                <tr class="border-t">
                                    <td class="px-3 py-2">
                                        {{ t.nombre }}
                                        <span
                                            class="text-muted-foreground text-xs"
                                            >· {{ t.codigo }}</span
                                        >
                                    </td>
                                    <td class="text-muted-foreground px-3 py-2">
                                        {{ t.activos_count }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <Badge
                                            :variant="
                                                t.activo
                                                    ? 'secondary'
                                                    : 'outline'
                                            "
                                            class="text-xs"
                                        >
                                            {{
                                                t.activo ? 'Activo' : 'Inactivo'
                                            }}
                                        </Badge>
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right whitespace-nowrap"
                                    >
                                        <button
                                            v-if="permisos.administrar_tipos"
                                            class="text-primary text-xs hover:underline"
                                            @click="abrirEdicionTipo(t)"
                                        >
                                            Editar
                                        </button>
                                        <button
                                            v-if="permisos.administrar_tipos"
                                            class="text-primary ml-3 text-xs hover:underline"
                                            @click="
                                                pedirConfirmacion({
                                                    recurso: 'tipo',
                                                    id: t.id,
                                                    nombre: t.nombre,
                                                    activar: !t.activo,
                                                })
                                            "
                                        >
                                            {{
                                                t.activo
                                                    ? 'Desactivar'
                                                    : 'Activar'
                                            }}
                                        </button>
                                    </td>
                                </tr>
                                <tr
                                    v-if="editandoTipo === t.id"
                                    class="bg-muted/30 border-t"
                                >
                                    <td colspan="4" class="px-3 py-3">
                                        <div
                                            class="flex flex-wrap items-end gap-2"
                                        >
                                            <div
                                                class="grid min-w-0 flex-1 gap-1.5"
                                            >
                                                <Label>Nombre</Label>
                                                <Input
                                                    v-model="tipoEdit.nombre"
                                                />
                                            </div>
                                            <label
                                                class="flex items-center gap-1.5 text-sm"
                                            >
                                                <input
                                                    v-model="tipoEdit.activo"
                                                    type="checkbox"
                                                    class="size-4"
                                                />
                                                Activo
                                            </label>
                                            <Button
                                                size="sm"
                                                :disabled="tipoEdit.processing"
                                                @click="guardarTipo(t.id)"
                                            >
                                                Guardar
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                @click="editandoTipo = null"
                                            >
                                                Cancelar
                                            </Button>
                                        </div>
                                        <p
                                            v-if="tipoEdit.errors.nombre"
                                            class="text-destructive mt-1 text-xs"
                                        >
                                            {{ tipoEdit.errors.nombre }}
                                        </p>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Categorías de activo -->
            <section class="min-w-0 space-y-3 rounded-xl border p-4">
                <div>
                    <h2 class="text-sm font-semibold">Categorías de activo</h2>
                    <p class="text-muted-foreground text-xs">
                        Clasificación específica dentro de un tipo: Camisola,
                        Pantalón, Laptop, Teléfono celular…
                    </p>
                </div>

                <form
                    v-if="permisos.administrar_categorias"
                    class="flex flex-wrap items-end gap-2"
                    @submit.prevent="crearCategoria"
                >
                    <div class="grid min-w-0 flex-1 gap-1.5">
                        <Label for="nueva-cat">Nueva categoría</Label>
                        <Input
                            id="nueva-cat"
                            v-model="categoriaForm.nombre"
                            placeholder="p. ej. Camisola"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="cat-tipo">Tipo (opcional)</Label>
                        <select
                            id="cat-tipo"
                            v-model="categoriaForm.tipo_activo_id"
                            class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        >
                            <option value="">Sin tipo</option>
                            <option
                                v-for="t in tiposSelect"
                                :key="t.id"
                                :value="t.id"
                            >
                                {{ t.nombre }}
                            </option>
                        </select>
                    </div>
                    <Button type="submit" :disabled="categoriaForm.processing">
                        <Plus class="size-4" /> Agregar
                    </Button>
                </form>
                <p
                    v-if="categoriaForm.errors.nombre"
                    class="text-destructive text-xs"
                >
                    {{ categoriaForm.errors.nombre }}
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <Input
                        v-model="fCat.buscar"
                        type="search"
                        placeholder="Buscar categoría…"
                        aria-label="Buscar categoría"
                        class="h-8 w-40"
                    />
                    <select
                        v-model="fCat.tipo_id"
                        class="border-input bg-background h-8 rounded-md border px-2 text-sm"
                        aria-label="Filtrar por tipo"
                    >
                        <option value="">Todos los tipos</option>
                        <option
                            v-for="t in tiposSelect"
                            :key="t.id"
                            :value="t.id"
                        >
                            {{ t.nombre }}
                        </option>
                    </select>
                    <select
                        v-model="fCat.estado"
                        class="border-input bg-background h-8 rounded-md border px-2 text-sm"
                        aria-label="Filtrar por estado"
                    >
                        <option value="">Todas</option>
                        <option value="activas">Activas</option>
                        <option value="inactivas">Inactivas</option>
                    </select>
                    <Button
                        v-if="hayFiltroCat"
                        variant="ghost"
                        size="sm"
                        @click="fCat = { buscar: '', tipo_id: '', estado: '' }"
                    >
                        Limpiar filtros
                    </Button>
                </div>

                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full min-w-[480px] text-sm">
                        <thead
                            class="bg-muted/50 text-muted-foreground text-left"
                        >
                            <tr>
                                <th class="px-3 py-2 font-medium">Nombre</th>
                                <th class="px-3 py-2 font-medium">Tipo</th>
                                <th class="px-3 py-2 font-medium">Activos</th>
                                <th class="px-3 py-2 font-medium">Estado</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template
                                v-for="c in categoriasFiltradas"
                                :key="c.id"
                            >
                                <tr class="border-t">
                                    <td class="px-3 py-2">{{ c.nombre }}</td>
                                    <td class="text-muted-foreground px-3 py-2">
                                        {{ c.tipo ?? '—' }}
                                    </td>
                                    <td class="text-muted-foreground px-3 py-2">
                                        {{ c.activos_count }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <Badge
                                            :variant="
                                                c.activa
                                                    ? 'secondary'
                                                    : 'outline'
                                            "
                                            class="text-xs"
                                        >
                                            {{
                                                c.activa ? 'Activa' : 'Inactiva'
                                            }}
                                        </Badge>
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right whitespace-nowrap"
                                    >
                                        <button
                                            v-if="
                                                permisos.administrar_categorias
                                            "
                                            class="text-primary text-xs hover:underline"
                                            @click="abrirEdicionCategoria(c)"
                                        >
                                            Editar
                                        </button>
                                        <button
                                            v-if="
                                                permisos.administrar_categorias
                                            "
                                            class="text-primary ml-3 text-xs hover:underline"
                                            @click="
                                                pedirConfirmacion({
                                                    recurso: 'categoria',
                                                    id: c.id,
                                                    nombre: c.nombre,
                                                    activar: !c.activa,
                                                })
                                            "
                                        >
                                            {{
                                                c.activa
                                                    ? 'Desactivar'
                                                    : 'Activar'
                                            }}
                                        </button>
                                    </td>
                                </tr>
                                <tr
                                    v-if="editandoCategoria === c.id"
                                    class="bg-muted/30 border-t"
                                >
                                    <td colspan="5" class="px-3 py-3">
                                        <div
                                            class="flex flex-wrap items-end gap-2"
                                        >
                                            <div
                                                class="grid min-w-0 flex-1 gap-1.5"
                                            >
                                                <Label>Nombre</Label>
                                                <Input
                                                    v-model="
                                                        categoriaEdit.nombre
                                                    "
                                                />
                                            </div>
                                            <div class="grid gap-1.5">
                                                <Label>Tipo</Label>
                                                <select
                                                    v-model="
                                                        categoriaEdit.tipo_activo_id
                                                    "
                                                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                                >
                                                    <option value="">
                                                        Sin tipo
                                                    </option>
                                                    <option
                                                        v-for="t in tiposSelect"
                                                        :key="t.id"
                                                        :value="t.id"
                                                    >
                                                        {{ t.nombre }}
                                                    </option>
                                                </select>
                                            </div>
                                            <label
                                                class="flex items-center gap-1.5 text-sm"
                                            >
                                                <input
                                                    v-model="
                                                        categoriaEdit.activa
                                                    "
                                                    type="checkbox"
                                                    class="size-4"
                                                />
                                                Activa
                                            </label>
                                            <Button
                                                size="sm"
                                                :disabled="
                                                    categoriaEdit.processing
                                                "
                                                @click="guardarCategoria(c.id)"
                                            >
                                                Guardar
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                @click="
                                                    editandoCategoria = null
                                                "
                                            >
                                                Cancelar
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <Dialog
            :open="confirmacion !== null"
            @update:open="(v: boolean) => !v && (confirmacion = null)"
        >
            <DialogContent v-if="confirmacion">
                <DialogHeader>
                    <DialogTitle>
                        {{ confirmacion.activar ? '¿Activar' : '¿Desactivar' }}
                        {{
                            confirmacion.recurso === 'tipo'
                                ? 'tipo de activo?'
                                : 'categoría?'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        <span class="font-medium">{{
                            confirmacion.nombre
                        }}</span
                        >.
                        {{
                            confirmacion.activar
                                ? 'Volverá a estar disponible para nuevas selecciones.'
                                : 'Dejará de estar disponible para nuevas selecciones. Los activos existentes conservarán su información.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="confirmacion = null"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        :variant="
                            confirmacion.activar ? 'default' : 'destructive'
                        "
                        @click="confirmarEstado"
                    >
                        {{ confirmacion.activar ? 'Activar' : 'Desactivar' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
