<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Boxes,
    Layers,
    Package,
    Pencil,
    Plus,
    Ruler,
    Search,
    SquareArrowOutUpRight,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
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
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import type { EmpresaAutorizada } from '@/types/sistema';

type OpcionTipo = { id: number; nombre: string };
type OpcionCategoria = {
    id: number;
    nombre: string;
    tipo_activo_id: number | null;
    tipo?: string | null;
};
type OpcionAlmacen = { id: number; nombre: string; codigo: string | null };

type Activo = {
    id: number;
    nombre: string;
    categoria: string | null;
    codigo: string | null;
    tipo: string | null;
    tipo_control: 'cantidad' | 'individual';
    tipo_control_etiqueta: string;
    activo: boolean;
    imagen_url: string | null;
    tallas: string[];
    existencias: number;
    tallas_bajo_minimo: number;
};

const props = defineProps<{
    activos: Activo[];
    empresasAutorizadas: EmpresaAutorizada[];
    filtrosSeleccion: {
        tipo: OpcionTipo | null;
        categoria: OpcionCategoria | null;
        almacen: OpcionAlmacen | null;
    };
    filtros: {
        buscar: string;
        empresa_id: number | null;
        tipo_activo_id: number | '';
        categoria_id: number | '';
        almacen_id: number | '';
        control: '' | 'cantidad' | 'individual';
        estado: '' | 'activos' | 'inactivos';
        orden: 'az' | 'za';
    };
    permisos: {
        crear: boolean;
        editar: boolean;
        administrar: boolean;
        administrar_catalogos: boolean;
        verEliminados: boolean;
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Activos', href: '/activos' }] },
});

const buscar = ref(props.filtros.buscar);
const empresaSeleccionada = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === props.filtros.empresa_id) ??
        null,
);
const empresaId = computed(() => empresaSeleccionada.value?.id ?? '');

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

const tipoActivoId = ref<number | ''>(props.filtros.tipo_activo_id);
const categoriaId = ref<number | ''>(props.filtros.categoria_id);
const almacenId = ref<number | ''>(props.filtros.almacen_id);
const control = ref<'' | 'cantidad' | 'individual'>(props.filtros.control);
const estado = ref<'' | 'activos' | 'inactivos'>(props.filtros.estado);

// "Eliminados" (internamente `activo = false`) sólo se ofrece a quien puede
// administrar activos — el backend además lo ignora si se fuerza por URL.
const opcionesEstado = computed(() => [
    { valor: '', etiqueta: 'Todos' },
    { valor: 'activos', etiqueta: 'Activos' },
    ...(props.permisos.verEliminados
        ? [{ valor: 'inactivos', etiqueta: 'Eliminados' }]
        : []),
]);
const orden = ref<'az' | 'za'>(props.filtros.orden);

// Objeto seleccionado en cada combobox de filtro (el id vive en su ref).
const tipoSel = ref<OpcionTipo | null>(props.filtrosSeleccion.tipo);
const categoriaSel = ref<OpcionCategoria | null>(
    props.filtrosSeleccion.categoria,
);
const almacenSel = ref<OpcionAlmacen | null>(props.filtrosSeleccion.almacen);

// Tipo y categoría son catálogos globales: no dependen de la empresa.
async function buscarTipos(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionTipo[]> {
    const res = await fetch(`/tipos-activo/buscar?q=${encodeURIComponent(q)}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];
    return (await res.json()).tipos ?? [];
}

async function buscarCategorias(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionCategoria[]> {
    const tipo = tipoActivoId.value
        ? `&tipo_activo_id=${tipoActivoId.value}`
        : '';
    const res = await fetch(
        `/categorias-activo/buscar?q=${encodeURIComponent(q)}${tipo}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).categorias ?? [];
}

// El almacén sí depende de la empresa (sólo tiene sentido dentro de las que
// abastece).
async function buscarAlmacenes(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionAlmacen[]> {
    const emp = empresaId.value ? `&empresa_id=${empresaId.value}` : '';
    const res = await fetch(
        `/almacenes/buscar?q=${encodeURIComponent(q)}${emp}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).almacenes ?? [];
}

function alElegirTipo(o: OpcionTipo | null): void {
    tipoSel.value = o;
    tipoActivoId.value = o?.id ?? '';
}
function alElegirCategoria(o: OpcionCategoria | null): void {
    categoriaSel.value = o;
    categoriaId.value = o?.id ?? '';
}
function alElegirAlmacen(o: OpcionAlmacen | null): void {
    almacenSel.value = o;
    almacenId.value = o?.id ?? '';
}

// Cambiar de empresa invalida el almacén elegido (depende de ella).
watch(empresaId, () => {
    almacenSel.value = null;
    almacenId.value = '';
});

const hayFiltrosActivos = computed(
    () =>
        buscar.value !== '' ||
        empresaId.value !== '' ||
        tipoActivoId.value !== '' ||
        categoriaId.value !== '' ||
        almacenId.value !== '' ||
        control.value !== '' ||
        estado.value !== '' ||
        orden.value !== 'az',
);

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch(
    [
        buscar,
        empresaId,
        tipoActivoId,
        categoriaId,
        almacenId,
        control,
        estado,
        orden,
    ],
    () => {
        clearTimeout(temporizador);
        temporizador = setTimeout(() => {
            router.get(
                '/activos',
                {
                    buscar: buscar.value || undefined,
                    empresa_id: empresaId.value || undefined,
                    tipo_activo_id: tipoActivoId.value || undefined,
                    categoria_id: categoriaId.value || undefined,
                    almacen_id: almacenId.value || undefined,
                    control: control.value || undefined,
                    estado: estado.value || undefined,
                    orden: orden.value === 'az' ? undefined : orden.value,
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    only: ['activos', 'filtros'],
                },
            );
        }, 300);
    },
);

function limpiarFiltros(): void {
    buscar.value = '';
    empresaSeleccionada.value = null;
    tipoActivoId.value = '';
    categoriaId.value = '';
    almacenId.value = '';
    tipoSel.value = null;
    categoriaSel.value = null;
    almacenSel.value = null;
    control.value = '';
    estado.value = '';
    orden.value = 'az';
}

const confirmando = ref<Activo | null>(null);
const procesandoEstado = ref(false);

function alternarEstado(a: Activo): void {
    if (a.activo) {
        confirmando.value = a; // eliminar => confirmación
    } else {
        // restaurar es seguro: sin confirmación
        router.post(`/activos/${a.id}/estado`, {}, { preserveScroll: true });
    }
}

function confirmarEstado(): void {
    if (!confirmando.value) return;
    procesandoEstado.value = true;
    router.post(
        `/activos/${confirmando.value.id}/estado`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                procesandoEstado.value = false;
                confirmando.value = null;
            },
        },
    );
}

const vista = useVistaPreferida('activos');
</script>

<template>
    <Head title="Activos" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Activos"
            descripcion="Administra los bienes y prendas que la empresa entrega o mantiene en inventario: uniformes, equipo de cómputo, dispositivos y accesorios."
        >
            <template #acciones>
                <Button variant="outline" as-child>
                    <Link href="/tallas"
                        ><Ruler class="size-4" /> Variantes / tallas</Link
                    >
                </Button>
                <Button
                    v-if="permisos.administrar_catalogos"
                    variant="outline"
                    as-child
                >
                    <Link href="/activos-catalogos"
                        ><Layers class="size-4" /> Tipos y categorías</Link
                    >
                </Button>
                <Button v-if="permisos.crear" as-child>
                    <Link href="/activos/crear"
                        ><Plus class="size-4" /> Nuevo activo</Link
                    >
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="flex flex-col gap-3">
            <div class="relative w-full sm:w-[420px]">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="buscar"
                    class="pl-8"
                    placeholder="Buscar por nombre, código o categoría"
                    aria-label="Buscar activos"
                />
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <label
                    v-if="empresasAutorizadas.length > 1"
                    class="flex items-center gap-1.5 text-sm"
                >
                    <span class="text-muted-foreground">Empresa</span>
                    <BuscadorAsync
                        v-model="empresaSeleccionada"
                        :buscar="buscarEmpresas"
                        :etiqueta="(e) => String(e.nombre_comercial)"
                        placeholder="Todas"
                        placeholder-busqueda="Buscar empresa…"
                        class="w-56"
                    />
                </label>
                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Tipo</span>
                    <div class="w-44">
                        <BuscadorAsync
                            :model-value="tipoSel"
                            :buscar="buscarTipos"
                            :etiqueta="(t) => (t as OpcionTipo).nombre"
                            placeholder="Todos"
                            placeholder-busqueda="Buscar tipo"
                            sin-resultados="Sin tipos"
                            @update:model-value="
                                (v) => alElegirTipo(v as OpcionTipo | null)
                            "
                        />
                    </div>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Categoría</span>
                    <div class="w-44">
                        <BuscadorAsync
                            :model-value="categoriaSel"
                            :buscar="buscarCategorias"
                            :dependencia="tipoActivoId"
                            :etiqueta="(c) => (c as OpcionCategoria).nombre"
                            :descripcion="
                                (c) => (c as OpcionCategoria).tipo ?? 'Sin tipo'
                            "
                            placeholder="Todas"
                            placeholder-busqueda="Buscar categoría"
                            sin-resultados="Sin categorías"
                            @update:model-value="
                                (v) =>
                                    alElegirCategoria(
                                        v as OpcionCategoria | null,
                                    )
                            "
                        />
                    </div>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Almacén</span>
                    <div class="w-44">
                        <BuscadorAsync
                            :model-value="almacenSel"
                            :buscar="buscarAlmacenes"
                            :dependencia="empresaId"
                            :etiqueta="(a) => (a as OpcionAlmacen).nombre"
                            :descripcion="
                                (a) => (a as OpcionAlmacen).codigo ?? ''
                            "
                            placeholder="Todos"
                            placeholder-busqueda="Buscar almacén"
                            sin-resultados="Sin almacenes"
                            @update:model-value="
                                (v) =>
                                    alElegirAlmacen(v as OpcionAlmacen | null)
                            "
                        />
                    </div>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Control</span>
                    <div class="w-48">
                        <SelectSimple
                            v-model="control"
                            :opciones="[
                                { valor: '', etiqueta: 'Todos' },
                                { valor: 'cantidad', etiqueta: 'Por cantidad' },
                                {
                                    valor: 'individual',
                                    etiqueta: 'Seguimiento individual',
                                },
                            ]"
                        />
                    </div>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Estado</span>
                    <div class="w-36">
                        <SelectSimple
                            v-model="estado"
                            :opciones="opcionesEstado"
                        />
                    </div>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Orden</span>
                    <div class="w-36">
                        <SelectSimple
                            v-model="orden"
                            :opciones="[
                                { valor: 'az', etiqueta: 'Nombre A–Z' },
                                { valor: 'za', etiqueta: 'Nombre Z–A' },
                            ]"
                        />
                    </div>
                </label>

                <Button
                    v-if="hayFiltrosActivos"
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="limpiarFiltros"
                >
                    <X class="size-3.5" /> Limpiar filtros
                </Button>

                <SelectorVista v-model="vista" class="ml-auto" />
            </div>
        </div>

        <EstadoVacio
            v-if="!activos.length"
            titulo="No hay activos para mostrar"
            :descripcion="
                hayFiltrosActivos
                    ? 'Ningún activo coincide con la búsqueda o los filtros aplicados.'
                    : 'Crea el primer activo para empezar a controlar el inventario.'
            "
        >
            <template v-if="hayFiltrosActivos" #acciones>
                <Button variant="outline" @click="limpiarFiltros">
                    <X class="size-4" /> Limpiar filtros
                </Button>
            </template>
            <template v-else-if="permisos.crear" #acciones>
                <Button as-child>
                    <Link href="/activos/crear">Crear primer activo</Link>
                </Button>
            </template>
        </EstadoVacio>

        <div
            v-else-if="vista === 'cards'"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <div
                v-for="a in activos"
                :key="a.id"
                role="button"
                tabindex="0"
                :aria-label="`Ver detalle de ${a.nombre}`"
                class="group focus-visible:ring-ring hover:border-primary/20 flex cursor-pointer flex-col gap-3 rounded-xl border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                @click="router.visit(`/activos/${a.id}`)"
                @keydown.enter="router.visit(`/activos/${a.id}`)"
                @keydown.space.prevent="router.visit(`/activos/${a.id}`)"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2.5">
                        <span
                            class="bg-muted/60 flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border"
                        >
                            <img
                                v-if="a.imagen_url"
                                :src="a.imagen_url"
                                :alt="a.nombre"
                                class="size-full object-cover"
                            />
                            <Package
                                v-else
                                class="text-muted-foreground size-5"
                            />
                        </span>
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ a.nombre }}</p>
                            <p
                                class="text-muted-foreground flex items-center gap-1 font-mono text-xs"
                            >
                                {{ a.codigo ?? '—' }}
                            </p>
                        </div>
                    </div>
                    <Badge :variant="a.activo ? 'success' : 'secondary'">
                        {{ a.activo ? 'Activo' : 'Eliminado' }}
                    </Badge>
                </div>

                <div class="flex flex-wrap gap-1.5">
                    <Badge v-if="a.tipo" variant="outline" class="gap-1">
                        <Layers class="size-3" /> {{ a.tipo }}
                    </Badge>
                    <Badge variant="outline" class="gap-1">
                        <Boxes class="size-3" /> {{ a.tipo_control_etiqueta }}
                    </Badge>
                    <Badge v-if="a.categoria" variant="secondary">
                        {{ a.categoria }}
                    </Badge>
                </div>

                <div v-if="a.tallas.length" class="flex flex-wrap gap-1">
                    <span
                        v-for="t in a.tallas.slice(0, 6)"
                        :key="t"
                        class="bg-muted/60 rounded px-1.5 py-0.5 font-mono text-[11px]"
                    >
                        {{ t }}
                    </span>
                    <span
                        v-if="a.tallas.length > 6"
                        class="text-muted-foreground text-[11px]"
                    >
                        +{{ a.tallas.length - 6 }}
                    </span>
                </div>
                <p v-else class="text-muted-foreground text-xs">
                    Sin variantes / tallas
                </p>

                <div
                    v-if="a.tipo_control === 'cantidad'"
                    class="text-muted-foreground flex items-center gap-2 text-xs"
                >
                    <span
                        >Existencias:
                        <b class="text-foreground">{{ a.existencias }}</b></span
                    >
                    <span
                        v-if="a.tallas_bajo_minimo > 0"
                        class="text-destructive"
                    >
                        {{ a.tallas_bajo_minimo }} bajo mínimo
                    </span>
                </div>

                <div class="mt-auto flex flex-wrap gap-2 pt-1">
                    <Button
                        variant="outline"
                        size="sm"
                        @click.stop="router.visit(`/activos/${a.id}`)"
                    >
                        <SquareArrowOutUpRight class="size-3.5" /> Ver detalle
                    </Button>
                    <Button
                        v-if="permisos.editar"
                        variant="ghost"
                        size="sm"
                        as-child
                        @click.stop
                    >
                        <Link :href="`/activos/${a.id}/editar`">
                            <Pencil class="size-3.5" /> Editar
                        </Link>
                    </Button>
                    <Button
                        v-if="permisos.administrar"
                        variant="ghost"
                        size="sm"
                        @click.stop="alternarEstado(a)"
                    >
                        {{ a.activo ? 'Eliminar' : 'Restaurar' }}
                    </Button>
                </div>
            </div>
        </div>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[760px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Activo</th>
                        <th class="px-3 py-2 font-medium">Tipo / Categoría</th>
                        <th class="px-3 py-2 font-medium">Control</th>
                        <th class="px-3 py-2 font-medium">Existencias</th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="a in activos"
                        :key="a.id"
                        class="hover:bg-muted/40 border-t transition-colors"
                    >
                        <td class="px-3 py-2">
                            <p class="font-medium">{{ a.nombre }}</p>
                            <p class="text-muted-foreground font-mono text-xs">
                                {{ a.codigo ?? '—' }}
                            </p>
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{
                                [a.tipo, a.categoria]
                                    .filter(Boolean)
                                    .join(' · ') || '—'
                            }}
                        </td>
                        <td class="px-3 py-2">{{ a.tipo_control_etiqueta }}</td>
                        <td class="px-3 py-2">
                            <template v-if="a.tipo_control === 'cantidad'">
                                {{ a.existencias }}
                                <span
                                    v-if="a.tallas_bajo_minimo > 0"
                                    class="text-destructive block text-xs"
                                >
                                    {{ a.tallas_bajo_minimo }} bajo mínimo
                                </span>
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="a.activo ? 'success' : 'secondary'"
                            >
                                {{ a.activo ? 'Activo' : 'Eliminado' }}
                            </Badge>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <div class="flex justify-end gap-2">
                                <Button variant="outline" size="sm" as-child>
                                    <Link :href="`/activos/${a.id}`">Ver</Link>
                                </Button>
                                <Button
                                    v-if="permisos.editar"
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                >
                                    <Link :href="`/activos/${a.id}/editar`"
                                        >Editar</Link
                                    >
                                </Button>
                                <Button
                                    v-if="permisos.administrar"
                                    variant="ghost"
                                    size="sm"
                                    @click="alternarEstado(a)"
                                >
                                    {{ a.activo ? 'Eliminar' : 'Restaurar' }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Dialog
            :open="confirmando !== null"
            @update:open="
                (v) => {
                    if (!v) confirmando = null;
                }
            "
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >¿Eliminar el activo
                        <span v-if="confirmando">{{ confirmando.nombre }}</span
                        >?</DialogTitle
                    >
                    <DialogDescription>
                        Esta acción lo retirará de los listados y nuevas
                        operaciones. Los registros históricos no se eliminarán y
                        podrás restaurarlo cuando quieras.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        :disabled="procesandoEstado"
                        @click="confirmando = null"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="procesandoEstado"
                        @click="confirmarEstado"
                    >
                        Eliminar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
