<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Building2,
    MapPin,
    Pencil,
    Plus,
    Search,
    SquareArrowOutUpRight,
    UserRound,
    Warehouse,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { AlmacenEditable } from '@/components/almacenes/FormularioAlmacen.vue';
import FormularioAlmacen from '@/components/almacenes/FormularioAlmacen.vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
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
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

type EmpresaChip = { id: number; nombre_comercial: string };

type AlmacenFila = {
    id: number;
    nombre: string;
    codigo: string | null;
    direccion: string | null;
    activo: boolean;
    empresas_count: number;
    empresas: EmpresaChip[];
    responsable: {
        id: number;
        nombre_completo: string;
        numero_empleado: string;
    } | null;
};

const props = defineProps<{
    almacenes: Paginado<AlmacenFila>;
    empresasAutorizadas: EmpresaAutorizada[];
    filtros: {
        buscar: string;
        estado: '' | 'activos' | 'inactivos';
        orden: 'az' | 'za';
        empresa_id: number | null;
    };
    permisos: {
        crear: boolean;
        editar: boolean;
        administrar: boolean;
        verEliminados: boolean;
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Almacenes', href: '/almacenes' }] },
});

const buscar = ref(props.filtros.buscar);
const estado = ref<'' | 'activos' | 'inactivos'>(props.filtros.estado);
const orden = ref<'az' | 'za'>(props.filtros.orden);
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

const hayFiltrosActivos = computed(
    () =>
        buscar.value !== '' ||
        estado.value !== '' ||
        orden.value !== 'az' ||
        empresaId.value !== '',
);

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch([buscar, estado, orden, empresaId], () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get(
            '/almacenes',
            {
                buscar: buscar.value || undefined,
                estado: estado.value || undefined,
                orden: orden.value === 'az' ? undefined : orden.value,
                empresa_id: empresaId.value || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['almacenes', 'filtros'],
            },
        );
    }, 300);
});

function limpiarFiltros(): void {
    buscar.value = '';
    estado.value = '';
    orden.value = 'az';
    empresaSeleccionada.value = null;
}

// "Eliminados" (internamente `activo = false`) sólo se ofrece a quien puede
// administrar almacenes — el backend además lo ignora si se fuerza por URL.
const filtrosEstado = computed<
    { valor: '' | 'activos' | 'inactivos'; texto: string }[]
>(() => [
    { valor: '', texto: 'Todos' },
    { valor: 'activos', texto: 'Activos' },
    ...(props.permisos.verEliminados
        ? ([{ valor: 'inactivos', texto: 'Eliminados' }] as const)
        : []),
]);

const modalAbierto = ref(false);
const enEdicion = ref<AlmacenEditable | null>(null);
const claveFormulario = ref(0);

function nuevo(): void {
    enEdicion.value = null;
    claveFormulario.value++;
    modalAbierto.value = true;
}

function editar(a: AlmacenFila): void {
    router.visit(`/almacenes/${a.id}`);
}

function alGuardar(): void {
    modalAbierto.value = false;
}

function verDetalle(a: AlmacenFila): void {
    router.visit(`/almacenes/${a.id}`);
}

const confirmando = ref<AlmacenFila | null>(null);
const procesandoEstado = ref(false);

function confirmarEstado(): void {
    if (!confirmando.value) return;
    procesandoEstado.value = true;
    router.post(
        `/almacenes/${confirmando.value.id}/estado`,
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

function alternarEstado(a: AlmacenFila): void {
    if (a.activo) {
        confirmando.value = a;
    } else {
        router.post(`/almacenes/${a.id}/estado`, {}, { preserveScroll: true });
    }
}

const vista = useVistaPreferida('almacenes');
</script>

<template>
    <Head title="Almacenes" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Almacenes"
            descripcion="Administra los lugares físicos donde se resguardan las existencias. Un almacén puede abastecer a varias empresas / razones sociales; su inventario se mantiene separado por empresa."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/almacenes/exportar"
                    :filtros="filtros"
                />
                <Button v-if="permisos.crear" @click="nuevo">
                    <Plus class="size-4" /> Nuevo almacén
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
                    placeholder="Buscar por nombre, código o dirección"
                    aria-label="Buscar por nombre, código o dirección"
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

                <div
                    class="flex gap-1"
                    role="group"
                    aria-label="Filtrar por estado"
                >
                    <Button
                        v-for="f in filtrosEstado"
                        :key="f.valor"
                        type="button"
                        size="sm"
                        :variant="estado === f.valor ? 'default' : 'outline'"
                        @click="estado = f.valor"
                    >
                        {{ f.texto }}
                    </Button>
                </div>

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
            v-if="!almacenes.data.length"
            titulo="No hay almacenes para mostrar"
            :descripcion="
                hayFiltrosActivos
                    ? 'Ningún almacén coincide con la búsqueda o los filtros aplicados.'
                    : 'No hay almacenes registrados.'
            "
        >
            <template v-if="hayFiltrosActivos" #acciones>
                <Button variant="outline" @click="limpiarFiltros">
                    <X class="size-4" /> Limpiar filtros
                </Button>
            </template>
            <template v-else-if="permisos.crear" #acciones>
                <Button @click="nuevo">
                    <Plus class="size-4" /> Registrar primer almacén
                </Button>
            </template>
        </EstadoVacio>

        <TooltipProvider v-else-if="vista === 'cards'" :delay-duration="150">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="a in almacenes.data"
                    :key="a.id"
                    role="button"
                    tabindex="0"
                    :aria-label="`Ver detalles de ${a.nombre}`"
                    class="group focus-visible:ring-ring hover:border-primary/20 flex cursor-pointer flex-col gap-3 rounded-xl border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    @click="verDetalle(a)"
                    @keydown.enter="verDetalle(a)"
                    @keydown.space.prevent="verDetalle(a)"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <span
                                class="bg-muted/60 flex size-9 shrink-0 items-center justify-center rounded-lg border"
                            >
                                <Warehouse
                                    class="text-muted-foreground size-4"
                                />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ a.nombre }}
                                </p>
                                <p
                                    class="text-muted-foreground flex items-center gap-1 font-mono text-xs"
                                >
                                    {{ a.codigo ?? '—' }}
                                    <AyudaTooltip
                                        texto="Identificador interno del almacén (único a nivel plataforma)."
                                        etiqueta="Ayuda sobre el código"
                                    />
                                </p>
                            </div>
                        </div>
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Badge
                                    :variant="
                                        a.activo ? 'success' : 'secondary'
                                    "
                                >
                                    {{ a.activo ? 'Activo' : 'Eliminado' }}
                                </Badge>
                            </TooltipTrigger>
                            <TooltipContent>
                                {{
                                    a.activo
                                        ? 'Disponible para operaciones.'
                                        : 'No disponible para operaciones desde este almacén; el catálogo de activos y los históricos no se modifican.'
                                }}
                            </TooltipContent>
                        </Tooltip>
                    </div>

                    <div
                        class="text-muted-foreground flex flex-col gap-1 text-sm"
                    >
                        <p class="flex items-center gap-1.5">
                            <UserRound class="size-3.5 shrink-0" />
                            <span class="line-clamp-1">
                                {{
                                    a.responsable
                                        ? a.responsable.nombre_completo
                                        : 'Sin responsable asignado'
                                }}
                            </span>
                        </p>
                        <p v-if="a.direccion" class="flex items-center gap-1.5">
                            <MapPin class="size-3.5 shrink-0" />
                            <span class="line-clamp-1">{{ a.direccion }}</span>
                        </p>
                    </div>

                    <div
                        class="bg-muted/40 rounded-lg px-3 py-2"
                        aria-label="Empresas abastecidas"
                    >
                        <p
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <Building2 class="size-3" /> Abastece a
                            {{ a.empresas_count }}
                            {{
                                a.empresas_count === 1 ? 'empresa' : 'empresas'
                            }}
                        </p>
                        <div class="mt-1 flex flex-wrap gap-1">
                            <Badge
                                v-for="e in a.empresas"
                                :key="e.id"
                                variant="outline"
                                class="text-xs"
                            >
                                {{ e.nombre_comercial }}
                            </Badge>
                        </div>
                    </div>

                    <div class="mt-auto flex flex-wrap gap-2 pt-1">
                        <Button
                            variant="outline"
                            size="sm"
                            @click.stop="verDetalle(a)"
                        >
                            <SquareArrowOutUpRight class="size-3.5" />
                            Ver detalles
                        </Button>
                        <Button
                            v-if="permisos.editar"
                            variant="ghost"
                            size="sm"
                            @click.stop="editar(a)"
                        >
                            <Pencil class="size-3.5" /> Editar
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
        </TooltipProvider>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Almacén</th>
                        <th class="px-3 py-2 font-medium">Responsable</th>
                        <th class="px-3 py-2 font-medium">
                            Empresas abastecidas
                        </th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="a in almacenes.data"
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
                            {{ a.responsable?.nombre_completo ?? '—' }}
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="e in a.empresas"
                                    :key="e.id"
                                    variant="outline"
                                    class="text-xs"
                                >
                                    {{ e.nombre_comercial }}
                                </Badge>
                            </div>
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
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="verDetalle(a)"
                                    >Ver</Button
                                >
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

        <Paginacion :links="almacenes.links" :total="almacenes.total" />

        <Dialog v-model:open="modalAbierto">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {{ enEdicion ? 'Editar almacén' : 'Nuevo almacén' }}
                    </DialogTitle>
                    <DialogDescription>
                        Un almacén puede surtir a varias razones sociales; su
                        inventario se mantiene separado por empresa.
                    </DialogDescription>
                </DialogHeader>
                <FormularioAlmacen
                    :key="claveFormulario"
                    :almacen="enEdicion"
                    :empresas-autorizadas="empresasAutorizadas"
                    @guardado="alGuardar"
                    @cancelar="modalAbierto = false"
                />
            </DialogContent>
        </Dialog>

        <Dialog
            :open="confirmando !== null"
            @update:open="
                (v: boolean) => {
                    if (!v) confirmando = null;
                }
            "
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >¿Eliminar el almacén
                        <span v-if="confirmando">{{ confirmando.nombre }}</span
                        >?</DialogTitle
                    >
                    <DialogDescription>
                        Esta acción lo retirará de las operaciones disponibles
                        (para todas sus empresas). El catálogo de activos y los
                        registros históricos no se modifican, y podrás
                        restaurarlo cuando quieras.
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
