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
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Activo = {
    id: number;
    nombre: string;
    categoria: string | null;
    codigo: string | null;
    tipo: string | null;
    tipo_control: 'cantidad' | 'serializado';
    tipo_control_etiqueta: string;
    activo: boolean;
    imagen_url: string | null;
    tallas: string[];
    existencias: number;
    tallas_bajo_minimo: number;
};

const props = defineProps<{
    activos: Activo[];
    empresasAutorizadas: import('@/types/sistema').EmpresaAutorizada[];
    tiposActivo: { id: number; nombre: string }[];
    categorias: { id: number; nombre: string }[];
    filtros: {
        buscar: string;
        empresa_id: number | null;
        tipo_activo_id: number | '';
        categoria_id: number | '';
        control: '' | 'cantidad' | 'serializado';
        estado: '' | 'activos' | 'inactivos';
        orden: 'az' | 'za';
    };
    permisos: {
        crear: boolean;
        editar: boolean;
        administrar: boolean;
        administrar_catalogos: boolean;
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Activos', href: '/activos' }] },
});

const buscar = ref(props.filtros.buscar);
const empresaId = ref<number | ''>(props.filtros.empresa_id ?? '');
const tipoActivoId = ref<number | ''>(props.filtros.tipo_activo_id);
const categoriaId = ref<number | ''>(props.filtros.categoria_id);
const control = ref<'' | 'cantidad' | 'serializado'>(props.filtros.control);
const estado = ref<'' | 'activos' | 'inactivos'>(props.filtros.estado);
const orden = ref<'az' | 'za'>(props.filtros.orden);

const hayFiltrosActivos = computed(
    () =>
        buscar.value !== '' ||
        empresaId.value !== '' ||
        tipoActivoId.value !== '' ||
        categoriaId.value !== '' ||
        control.value !== '' ||
        estado.value !== '' ||
        orden.value !== 'az',
);

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch(
    [buscar, empresaId, tipoActivoId, categoriaId, control, estado, orden],
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
    empresaId.value = '';
    tipoActivoId.value = '';
    categoriaId.value = '';
    control.value = '';
    estado.value = '';
    orden.value = 'az';
}

const claseSelect =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-2.5 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none';

function alternarEstado(a: Activo): void {
    router.post(`/activos/${a.id}/estado`, {}, { preserveScroll: true });
}
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
                    <select
                        v-model="empresaId"
                        :class="claseSelect"
                        aria-label="Filtrar por empresa"
                    >
                        <option value="">Todas</option>
                        <option
                            v-for="e in empresasAutorizadas"
                            :key="e.id"
                            :value="e.id"
                        >
                            {{ e.nombre_comercial }}
                        </option>
                    </select>
                </label>
                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Tipo</span>
                    <select
                        v-model="tipoActivoId"
                        :class="claseSelect"
                        aria-label="Filtrar por tipo de activo"
                    >
                        <option value="">Todos</option>
                        <option
                            v-for="t in tiposActivo"
                            :key="t.id"
                            :value="t.id"
                        >
                            {{ t.nombre }}
                        </option>
                    </select>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Categoría</span>
                    <select
                        v-model="categoriaId"
                        :class="claseSelect"
                        aria-label="Filtrar por categoría"
                    >
                        <option value="">Todas</option>
                        <option
                            v-for="c in categorias"
                            :key="c.id"
                            :value="c.id"
                        >
                            {{ c.nombre }}
                        </option>
                    </select>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Control</span>
                    <select
                        v-model="control"
                        :class="claseSelect"
                        aria-label="Filtrar por tipo de control"
                    >
                        <option value="">Todos</option>
                        <option value="cantidad">Por cantidad</option>
                        <option value="serializado">Serializado</option>
                    </select>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Estado</span>
                    <select
                        v-model="estado"
                        :class="claseSelect"
                        aria-label="Filtrar por estado"
                    >
                        <option value="">Todos</option>
                        <option value="activos">Activos</option>
                        <option value="inactivos">Inactivos</option>
                    </select>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Orden</span>
                    <select
                        v-model="orden"
                        :class="claseSelect"
                        aria-label="Ordenar activos"
                    >
                        <option value="az">Nombre A–Z</option>
                        <option value="za">Nombre Z–A</option>
                    </select>
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
            v-else
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <div
                v-for="a in activos"
                :key="a.id"
                role="button"
                tabindex="0"
                :aria-label="`Ver detalle de ${a.nombre}`"
                class="group focus-visible:ring-ring hover:border-primary/40 flex cursor-pointer flex-col gap-3 rounded-xl border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
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
                    <Badge :variant="a.activo ? 'default' : 'secondary'">
                        {{ a.activo ? 'Activo' : 'Inactivo' }}
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
                        {{ a.activo ? 'Desactivar' : 'Activar' }}
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
