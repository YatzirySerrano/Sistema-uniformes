<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
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
type OpcionTipo = { id: number; nombre: string };

const props = defineProps<{
    tipos: Tipo[];
    categorias: Categoria[];
    tiposSelect: OpcionTipo[];
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

// --- Filtros locales ---
const fTipo = ref({ buscar: '', estado: '' as '' | 'activos' | 'inactivos' });
const fCat = ref({
    buscar: '',
    tipo_id: '' as number | '',
    estado: '' as '' | 'activas' | 'inactivas',
});
const tipoFiltroSel = ref<OpcionTipo | null>(null);
function buscarTiposLocal(q: string): Promise<OpcionTipo[]> {
    const t = q.trim().toLowerCase();
    return Promise.resolve(
        t
            ? props.tiposSelect.filter((x) =>
                  x.nombre.toLowerCase().includes(t),
              )
            : props.tiposSelect,
    );
}

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
function limpiarFiltroCat() {
    fCat.value = { buscar: '', tipo_id: '', estado: '' };
    tipoFiltroSel.value = null;
}

// --- Alta ---
const tipoForm = useForm<{ nombre: string }>({ nombre: '' });
const categoriaForm = useForm<{
    nombre: string;
    tipo_activo_id: number | '';
}>({ nombre: '', tipo_activo_id: '' });
const categoriaFormTipoSel = ref<OpcionTipo | null>(null);

function crearTipo() {
    tipoForm.post('/tipos-activo', {
        preserveScroll: true,
        onSuccess: () => tipoForm.reset(),
    });
}
function crearCategoria() {
    categoriaForm.post('/categorias-activo', {
        preserveScroll: true,
        onSuccess: () => {
            categoriaForm.reset();
            categoriaFormTipoSel.value = null;
        },
    });
}

// --- Edición inline ---
const editandoTipo = ref<number | null>(null);
const tipoEdit = useForm({ nombre: '', activo: true });
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

const editandoCategoria = ref<number | null>(null);
const categoriaEdit = useForm({
    nombre: '',
    tipo_activo_id: '' as number | '',
    activa: true,
});
const categoriaEditTipoSel = ref<OpcionTipo | null>(null);
function abrirEdicionCategoria(c: Categoria) {
    editandoCategoria.value = c.id;
    categoriaEdit.defaults({
        nombre: c.nombre,
        tipo_activo_id: c.tipo_activo_id ?? '',
        activa: c.activa,
    });
    categoriaEdit.reset();
    categoriaEditTipoSel.value = c.tipo_activo_id
        ? { id: c.tipo_activo_id, nombre: c.tipo ?? '' }
        : null;
}
function guardarCategoria(id: number) {
    categoriaEdit.put(`/categorias-activo/${id}`, {
        preserveScroll: true,
        onSuccess: () => (editandoCategoria.value = null),
    });
}

// --- Confirmación de activar / desactivar GLOBAL ---
const confirmacion = ref<{
    recurso: 'tipo' | 'categoria';
    id: number;
    nombre: string;
    activar: boolean;
} | null>(null);
function confirmarEstado() {
    const c = confirmacion.value;
    if (!c) return;
    router.post(
        c.recurso === 'tipo'
            ? `/tipos-activo/${c.id}/estado`
            : `/categorias-activo/${c.id}/estado`,
        {},
        { preserveScroll: true, onFinish: () => (confirmacion.value = null) },
    );
}
</script>

<template>
    <Head title="Tipos y categorías de activo" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Tipos y categorías de activo"
            descripcion="Catálogos GLOBALES de la plataforma: el mismo tipo o categoría se reutiliza en todas las empresas por igual. «Activo» / «Activa» retira un elemento de nuevas selecciones en todas las empresas a la vez; los activos que ya lo usan conservan su información."
        >
            <template #acciones>
                <Button variant="ghost" as-child>
                    <Link href="/activos">Volver a activos</Link>
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- ===== Tipos ===== -->
            <section class="min-w-0 space-y-3">
                <div>
                    <h2 class="text-sm font-semibold">Tipos de activo</h2>
                    <p class="text-muted-foreground text-xs">
                        Naturaleza del activo: Prenda, Equipo de cómputo,
                        Dispositivo móvil, Accesorio…
                    </p>
                </div>

                <form
                    v-if="permisos.administrar_tipos"
                    class="flex flex-wrap items-end gap-2 rounded-lg border p-3"
                    @submit.prevent="crearTipo"
                >
                    <div class="grid min-w-0 flex-1 gap-1.5">
                        <Label for="nuevo-tipo">Nuevo tipo</Label>
                        <Input
                            id="nuevo-tipo"
                            v-model="tipoForm.nombre"
                            placeholder="p. ej. Equipo de protección"
                        />
                        <p
                            v-if="tipoForm.errors.nombre"
                            class="text-destructive text-xs"
                        >
                            {{ tipoForm.errors.nombre }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            Se crea en el catálogo compartido y queda disponible
                            de inmediato para todas las empresas.
                        </p>
                    </div>
                    <Button type="submit" :disabled="tipoForm.processing">
                        <Plus class="size-4" /> Agregar
                    </Button>
                </form>

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
                        <option value="">Estado: todos</option>
                        <option value="activos">Activos</option>
                        <option value="inactivos">Inactivos</option>
                    </select>
                    <Button
                        v-if="hayFiltroTipo"
                        variant="ghost"
                        size="sm"
                        @click="fTipo = { buscar: '', estado: '' }"
                    >
                        <X class="size-3.5" /> Limpiar
                    </Button>
                </div>

                <p
                    v-if="!tiposFiltrados.length"
                    class="text-muted-foreground rounded-lg border border-dashed p-4 text-center text-sm"
                >
                    No hay tipos que coincidan.
                </p>

                <ul class="flex flex-col gap-2">
                    <li
                        v-for="t in tiposFiltrados"
                        :key="t.id"
                        class="rounded-xl border p-3"
                    >
                        <template v-if="editandoTipo === t.id">
                            <div class="flex flex-wrap items-center gap-2">
                                <Input
                                    v-model="tipoEdit.nombre"
                                    class="h-8 w-44"
                                    aria-label="Nombre del tipo"
                                />
                                <label
                                    class="flex items-center gap-1.5 text-xs"
                                >
                                    <input
                                        v-model="tipoEdit.activo"
                                        type="checkbox"
                                        class="size-4"
                                    />
                                    Activo
                                </label>
                                <Button size="sm" @click="guardarTipo(t.id)"
                                    >Guardar</Button
                                >
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    @click="editandoTipo = null"
                                    >Cancelar</Button
                                >
                            </div>
                            <p
                                v-if="tipoEdit.errors.nombre"
                                class="text-destructive mt-1 text-xs"
                            >
                                {{ tipoEdit.errors.nombre }}
                            </p>
                        </template>

                        <template v-else>
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-medium">
                                        {{ t.nombre }}
                                        <span
                                            class="text-muted-foreground font-mono text-xs"
                                            >{{ t.codigo }}</span
                                        >
                                    </p>
                                    <div
                                        class="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs"
                                    >
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
                                        <span
                                            >Se usa en
                                            {{ t.activos_count }} activos</span
                                        >
                                    </div>
                                </div>
                                <div
                                    v-if="permisos.administrar_tipos"
                                    class="flex shrink-0 items-center gap-1"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        :aria-label="`Editar ${t.nombre}`"
                                        @click="abrirEdicionTipo(t)"
                                    >
                                        <Pencil class="size-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="
                                            confirmacion = {
                                                recurso: 'tipo',
                                                id: t.id,
                                                nombre: t.nombre,
                                                activar: !t.activo,
                                            }
                                        "
                                    >
                                        {{
                                            t.activo ? 'Desactivar' : 'Activar'
                                        }}
                                    </Button>
                                </div>
                            </div>
                        </template>
                    </li>
                </ul>
            </section>

            <!-- ===== Categorías ===== -->
            <section class="min-w-0 space-y-3">
                <div>
                    <h2 class="text-sm font-semibold">Categorías de activo</h2>
                    <p class="text-muted-foreground text-xs">
                        Qué es dentro del tipo: Camisola, Pantalón, Laptop,
                        Teléfono celular…
                    </p>
                </div>

                <form
                    v-if="permisos.administrar_categorias"
                    class="grid gap-2 rounded-lg border p-3"
                    @submit.prevent="crearCategoria"
                >
                    <div class="grid gap-1.5">
                        <Label for="nueva-cat">Nueva categoría</Label>
                        <Input
                            id="nueva-cat"
                            v-model="categoriaForm.nombre"
                            placeholder="p. ej. Camisola"
                        />
                        <p
                            v-if="categoriaForm.errors.nombre"
                            class="text-destructive text-xs"
                        >
                            {{ categoriaForm.errors.nombre }}
                        </p>
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Tipo relacionado (opcional)</Label>
                        <BuscadorAsync
                            :model-value="categoriaFormTipoSel"
                            :buscar="buscarTiposLocal"
                            :etiqueta="(x) => (x as OpcionTipo).nombre"
                            placeholder="Sin tipo"
                            placeholder-busqueda="Buscar tipo"
                            @update:model-value="
                                (v) => {
                                    categoriaFormTipoSel =
                                        v as OpcionTipo | null;
                                    categoriaForm.tipo_activo_id =
                                        (v as OpcionTipo | null)?.id ?? '';
                                }
                            "
                        />
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-muted-foreground text-xs">
                            Se crea en el catálogo compartido, disponible para
                            todas las empresas.
                        </p>
                        <Button
                            type="submit"
                            :disabled="categoriaForm.processing"
                        >
                            <Plus class="size-4" /> Agregar
                        </Button>
                    </div>
                </form>

                <div class="flex flex-wrap items-center gap-2">
                    <Input
                        v-model="fCat.buscar"
                        type="search"
                        placeholder="Buscar categoría…"
                        aria-label="Buscar categoría"
                        class="h-8 w-40"
                    />
                    <div class="w-40">
                        <BuscadorAsync
                            :model-value="tipoFiltroSel"
                            :buscar="buscarTiposLocal"
                            :etiqueta="(x) => (x as OpcionTipo).nombre"
                            placeholder="Tipo: todos"
                            placeholder-busqueda="Buscar tipo"
                            @update:model-value="
                                (v) => {
                                    tipoFiltroSel = v as OpcionTipo | null;
                                    fCat.tipo_id =
                                        (v as OpcionTipo | null)?.id ?? '';
                                }
                            "
                        />
                    </div>
                    <select
                        v-model="fCat.estado"
                        class="border-input bg-background h-8 rounded-md border px-2 text-sm"
                        aria-label="Filtrar por estado"
                    >
                        <option value="">Estado: todas</option>
                        <option value="activas">Activas</option>
                        <option value="inactivas">Inactivas</option>
                    </select>
                    <Button
                        v-if="hayFiltroCat"
                        variant="ghost"
                        size="sm"
                        @click="limpiarFiltroCat"
                    >
                        <X class="size-3.5" /> Limpiar
                    </Button>
                </div>

                <p
                    v-if="!categoriasFiltradas.length"
                    class="text-muted-foreground rounded-lg border border-dashed p-4 text-center text-sm"
                >
                    No hay categorías que coincidan.
                </p>

                <ul class="flex flex-col gap-2">
                    <li
                        v-for="c in categoriasFiltradas"
                        :key="c.id"
                        class="rounded-xl border p-3"
                    >
                        <template v-if="editandoCategoria === c.id">
                            <div class="grid gap-2">
                                <Input
                                    v-model="categoriaEdit.nombre"
                                    class="h-8"
                                    aria-label="Nombre de la categoría"
                                />
                                <BuscadorAsync
                                    :model-value="categoriaEditTipoSel"
                                    :buscar="buscarTiposLocal"
                                    :etiqueta="(x) => (x as OpcionTipo).nombre"
                                    placeholder="Sin tipo"
                                    placeholder-busqueda="Buscar tipo"
                                    @update:model-value="
                                        (v) => {
                                            categoriaEditTipoSel =
                                                v as OpcionTipo | null;
                                            categoriaEdit.tipo_activo_id =
                                                (v as OpcionTipo | null)?.id ??
                                                '';
                                        }
                                    "
                                />
                                <div class="flex flex-wrap items-center gap-2">
                                    <label
                                        class="flex items-center gap-1.5 text-xs"
                                    >
                                        <input
                                            v-model="categoriaEdit.activa"
                                            type="checkbox"
                                            class="size-4"
                                        />
                                        Activa
                                    </label>
                                    <Button
                                        size="sm"
                                        @click="guardarCategoria(c.id)"
                                        >Guardar</Button
                                    >
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        @click="editandoCategoria = null"
                                        >Cancelar</Button
                                    >
                                </div>
                            </div>
                        </template>

                        <template v-else>
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-medium">{{ c.nombre }}</p>
                                    <div
                                        class="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs"
                                    >
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
                                        <span>Tipo: {{ c.tipo ?? '—' }}</span>
                                        <span
                                            >Se usa en
                                            {{ c.activos_count }} activos</span
                                        >
                                    </div>
                                </div>
                                <div
                                    v-if="permisos.administrar_categorias"
                                    class="flex shrink-0 items-center gap-1"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        :aria-label="`Editar ${c.nombre}`"
                                        @click="abrirEdicionCategoria(c)"
                                    >
                                        <Pencil class="size-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="
                                            confirmacion = {
                                                recurso: 'categoria',
                                                id: c.id,
                                                nombre: c.nombre,
                                                activar: !c.activa,
                                            }
                                        "
                                    >
                                        {{
                                            c.activa ? 'Desactivar' : 'Activar'
                                        }}
                                    </Button>
                                </div>
                            </div>
                        </template>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Confirmación de estado -->
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
                                ? 'tipo de activo'
                                : 'categoría'
                        }}?
                    </DialogTitle>
                    <DialogDescription>
                        <span class="font-medium">{{
                            confirmacion.nombre
                        }}</span
                        >.
                        {{
                            confirmacion.activar
                                ? 'Volverá a poder seleccionarse en todas las empresas.'
                                : 'Dejará de ofrecerse para nuevas selecciones en TODAS las empresas. Los activos que ya lo usan conservan su información.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="confirmacion = null"
                        >Cancelar</Button
                    >
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
