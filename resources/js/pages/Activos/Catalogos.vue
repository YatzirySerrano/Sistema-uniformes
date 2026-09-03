<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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

defineProps<{
    tipos: Tipo[];
    categorias: Categoria[];
    tiposSelect: { id: number; nombre: string }[];
    permisos: {
        administrar_tipos: boolean;
        administrar_categorias: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Activos', href: '/activos' },
            { title: 'Tipos y categorías', href: '/activos-catalogos' },
        ],
    },
});

const tipoForm = useForm({ nombre: '' });
const categoriaForm = useForm({
    nombre: '',
    tipo_activo_id: '' as number | '',
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
function toggleTipo(id: number) {
    router.post(`/tipos-activo/${id}/estado`, {}, { preserveScroll: true });
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
function toggleCategoria(id: number) {
    router.post(
        `/categorias-activo/${id}/estado`,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Tipos y categorías de activo" />

    <div class="flex w-full flex-col gap-6 p-4">
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
                <h2 class="text-sm font-semibold">Tipos de activo</h2>

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
                            <template v-for="t in tipos" :key="t.id">
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
                                            @click="toggleTipo(t.id)"
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
                <h2 class="text-sm font-semibold">Categorías de activo</h2>

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
                            <template v-for="c in categorias" :key="c.id">
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
                                            @click="toggleCategoria(c.id)"
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
    </div>
</template>
