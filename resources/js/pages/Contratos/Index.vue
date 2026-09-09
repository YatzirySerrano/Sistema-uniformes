<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Building2,
    FileSignature,
    MapPin,
    Pencil,
    Plus,
    Search,
    SquareArrowOutUpRight,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { ContratoEditable } from '@/components/contratos/FormularioContrato.vue';
import FormularioContrato from '@/components/contratos/FormularioContrato.vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
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
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

type ContratoFila = ContratoEditable & {
    activo: boolean;
    empresa: { id: number; nombre_comercial: string };
    servicios_total: number;
    servicios_activos: number;
};

const props = defineProps<{
    contratos: Paginado<ContratoFila>;
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
    layout: {
        breadcrumbs: [{ title: 'Contratos', href: '/contratos' }],
    },
});

const buscar = ref(props.filtros.buscar);
const estado = ref<'' | 'activos' | 'inactivos'>(props.filtros.estado);
const orden = ref<'az' | 'za'>(props.filtros.orden);
const empresaSeleccionada = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === props.filtros.empresa_id) ??
        null,
);
const empresaId = computed(() => empresaSeleccionada.value?.id ?? '');
watch(
    () => props.filtros.empresa_id,
    (nuevoId) => {
        if (nuevoId !== (empresaSeleccionada.value?.id ?? null)) {
            empresaSeleccionada.value =
                props.empresasAutorizadas.find((e) => e.id === nuevoId) ?? null;
        }
    },
);

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
            '/contratos',
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
                only: ['contratos', 'filtros'],
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
const enEdicion = ref<ContratoEditable | null>(null);
const claveFormulario = ref(0);

function nuevo(): void {
    enEdicion.value = null;
    claveFormulario.value++;
    modalAbierto.value = true;
}

function editar(c: ContratoFila): void {
    enEdicion.value = {
        id: c.id,
        nombre: c.nombre,
        codigo: c.codigo,
        descripcion: c.descripcion,
        fecha_inicio: c.fecha_inicio,
        fecha_fin: c.fecha_fin,
        empresa_id: c.empresa.id,
    };
    claveFormulario.value++;
    modalAbierto.value = true;
}

function alGuardar(): void {
    modalAbierto.value = false;
}

function verDetalle(c: ContratoFila): void {
    router.visit(`/contratos/${c.id}`);
}

function irAServicios(c: ContratoFila): void {
    router.visit(`/servicios?contrato_id=${c.id}`);
}

const confirmando = ref<ContratoFila | null>(null);
const procesandoEstado = ref(false);

function confirmarEstado(): void {
    if (!confirmando.value) return;
    procesandoEstado.value = true;
    router.post(
        `/contratos/${confirmando.value.id}/estado`,
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

function alternarEstado(c: ContratoFila): void {
    if (c.activo) {
        confirmando.value = c;
    } else {
        router.post(`/contratos/${c.id}/estado`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Contratos" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Contratos"
            descripcion="Contratos comerciales de cada empresa, de los que se derivan los servicios operativos donde trabaja el personal."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/contratos/exportar"
                    :filtros="filtros"
                />
                <Button v-if="permisos.crear" @click="nuevo">
                    <Plus class="size-4" /> Nuevo contrato
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
                    placeholder="Buscar por nombre o código"
                    aria-label="Buscar por nombre o código"
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
            </div>
        </div>

        <EstadoVacio
            v-if="!contratos.data.length"
            titulo="No hay contratos para mostrar"
            :descripcion="
                hayFiltrosActivos
                    ? 'Ningún contrato coincide con la búsqueda o los filtros aplicados.'
                    : 'No hay contratos registrados para esta empresa.'
            "
        >
            <template v-if="hayFiltrosActivos" #acciones>
                <Button variant="outline" @click="limpiarFiltros">
                    <X class="size-4" /> Limpiar filtros
                </Button>
            </template>
            <template v-else-if="permisos.crear" #acciones>
                <Button @click="nuevo">
                    <Plus class="size-4" /> Registrar primer contrato
                </Button>
            </template>
        </EstadoVacio>

        <TooltipProvider v-else :delay-duration="150">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="c in contratos.data"
                    :key="c.id"
                    role="button"
                    tabindex="0"
                    :aria-label="`Ver detalles de ${c.nombre}`"
                    class="group focus-visible:ring-ring hover:border-primary/20 flex cursor-pointer flex-col gap-3 rounded-xl border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    @click="verDetalle(c)"
                    @keydown.enter="verDetalle(c)"
                    @keydown.space.prevent="verDetalle(c)"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <span
                                class="bg-muted/60 flex size-9 shrink-0 items-center justify-center rounded-lg border"
                            >
                                <FileSignature
                                    class="text-muted-foreground size-4"
                                />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ c.nombre }}
                                </p>
                                <p
                                    class="text-muted-foreground flex items-center gap-1 font-mono text-xs"
                                >
                                    {{ c.codigo ?? '—' }}
                                    <AyudaTooltip
                                        texto="Identificador interno del contrato dentro de la empresa."
                                        etiqueta="Ayuda sobre el código"
                                    />
                                </p>
                            </div>
                        </div>
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Badge
                                    :variant="
                                        c.activo ? 'success' : 'secondary'
                                    "
                                >
                                    {{ c.activo ? 'Activo' : 'Eliminado' }}
                                </Badge>
                            </TooltipTrigger>
                            <TooltipContent>
                                {{
                                    c.activo
                                        ? 'Disponible para asignar servicios y entregas.'
                                        : 'No disponible para nuevos servicios ni asignaciones; sus registros históricos se conservan.'
                                }}
                            </TooltipContent>
                        </Tooltip>
                    </div>

                    <p
                        class="text-muted-foreground flex items-center gap-1.5 text-xs"
                    >
                        <Building2 class="size-3" />
                        {{ c.empresa.nombre_comercial }}
                    </p>

                    <p
                        v-if="c.descripcion"
                        class="text-muted-foreground line-clamp-2 text-sm"
                    >
                        {{ c.descripcion }}
                    </p>

                    <div
                        role="button"
                        tabindex="0"
                        :aria-label="`Ver servicios del contrato ${c.nombre}`"
                        class="bg-muted/40 hover:bg-muted/70 hover:border-primary/20 focus-visible:ring-ring w-fit rounded-lg border border-transparent px-3 py-2 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                        @click.stop="irAServicios(c)"
                        @keydown.enter.stop="irAServicios(c)"
                        @keydown.space.stop.prevent="irAServicios(c)"
                    >
                        <p
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <MapPin class="size-3" /> Servicios activos
                            <ArrowUpRight
                                class="text-muted-foreground/70 ml-auto size-3.5"
                            />
                        </p>
                        <p class="text-lg font-semibold">
                            {{ c.servicios_activos }}
                            <span
                                class="text-muted-foreground text-sm font-normal"
                            >
                                / {{ c.servicios_total }}
                            </span>
                        </p>
                    </div>

                    <div class="mt-auto flex flex-wrap gap-2 pt-1">
                        <Button
                            variant="outline"
                            size="sm"
                            @click.stop="verDetalle(c)"
                        >
                            <SquareArrowOutUpRight class="size-3.5" />
                            Ver detalles
                        </Button>
                        <Button
                            v-if="permisos.editar"
                            variant="ghost"
                            size="sm"
                            @click.stop="editar(c)"
                        >
                            <Pencil class="size-3.5" /> Editar
                        </Button>
                        <Button
                            v-if="permisos.administrar"
                            variant="ghost"
                            size="sm"
                            @click.stop="alternarEstado(c)"
                        >
                            {{ c.activo ? 'Eliminar' : 'Restaurar' }}
                        </Button>
                    </div>
                </div>
            </div>
        </TooltipProvider>

        <Paginacion :links="contratos.links" :total="contratos.total" />

        <Dialog v-model:open="modalAbierto">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {{ enEdicion ? 'Editar contrato' : 'Nuevo contrato' }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            enEdicion
                                ? 'Actualiza los datos del contrato.'
                                : 'Elige la empresa y captura los datos del contrato.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <FormularioContrato
                    :key="claveFormulario"
                    :contrato="enEdicion"
                    :empresas-autorizadas="empresasAutorizadas"
                    @guardado="alGuardar"
                    @cancelar="modalAbierto = false"
                />
            </DialogContent>
        </Dialog>

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
                        >¿Eliminar el contrato
                        <span v-if="confirmando">{{ confirmando.nombre }}</span
                        >?</DialogTitle
                    >
                    <DialogDescription>
                        Esta acción lo retirará de los listados y de nuevas
                        asignaciones de servicio y entregas. Sus servicios y
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
