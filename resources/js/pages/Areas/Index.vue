<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Building2,
    Network,
    Pencil,
    Plus,
    Search,
    SquareArrowOutUpRight,
    Users,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { AreaEditable } from '@/components/areas/FormularioArea.vue';
import FormularioArea from '@/components/areas/FormularioArea.vue';
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

type AreaFila = AreaEditable & {
    activa: boolean;
    empresa: { id: number; nombre_comercial: string };
    colaboradores_total: number;
    colaboradores_activos: number;
};

const props = defineProps<{
    areas: Paginado<AreaFila>;
    empresasAutorizadas: EmpresaAutorizada[];
    filtros: {
        buscar: string;
        estado: '' | 'activas' | 'inactivas';
        orden: 'az' | 'za';
        empresa_id: number | null;
    };
    permisos: { crear: boolean; editar: boolean; desactivar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Áreas / Departamentos', href: '/areas' }],
    },
});

const buscar = ref(props.filtros.buscar);
const estado = ref<'' | 'activas' | 'inactivas'>(props.filtros.estado);
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
            '/areas',
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
                only: ['areas', 'filtros'],
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

const filtrosEstado: { valor: '' | 'activas' | 'inactivas'; texto: string }[] =
    [
        { valor: '', texto: 'Todas' },
        { valor: 'activas', texto: 'Activas' },
        { valor: 'inactivas', texto: 'Inactivas' },
    ];

const modalAbierto = ref(false);
const enEdicion = ref<AreaEditable | null>(null);
const claveFormulario = ref(0);

function nueva(): void {
    enEdicion.value = null;
    claveFormulario.value++;
    modalAbierto.value = true;
}

function editar(a: AreaFila): void {
    enEdicion.value = {
        id: a.id,
        nombre: a.nombre,
        codigo: a.codigo,
        descripcion: a.descripcion,
        empresa_id: a.empresa.id,
    };
    claveFormulario.value++;
    modalAbierto.value = true;
}

function alGuardar(): void {
    modalAbierto.value = false;
}

function verDetalle(a: AreaFila): void {
    router.visit(`/areas/${a.id}`);
}

function irAColaboradores(a: AreaFila): void {
    router.visit(`/colaboradores?area_id=${a.id}`);
}

const confirmando = ref<AreaFila | null>(null);
const procesandoEstado = ref(false);

function confirmarEstado(): void {
    if (!confirmando.value) return;
    procesandoEstado.value = true;
    router.post(
        `/areas/${confirmando.value.id}/estado`,
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

function alternarEstado(a: AreaFila): void {
    if (a.activa) {
        confirmando.value = a;
    } else {
        router.post(`/areas/${a.id}/estado`, {}, { preserveScroll: true });
    }
}

const vista = useVistaPreferida('areas');
</script>

<template>
    <Head title="Áreas / Departamentos" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Áreas / Departamentos"
            descripcion="Estructura organizacional por empresa. Usa el filtro de empresa para acotar el listado."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/areas/exportar"
                    :filtros="filtros"
                />
                <Button v-if="permisos.crear" @click="nueva">
                    <Plus class="size-4" /> Nueva área
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

                <SelectorVista v-model="vista" class="ml-auto" />
            </div>
        </div>

        <EstadoVacio
            v-if="!areas.data.length"
            titulo="No hay áreas para mostrar"
            :descripcion="
                hayFiltrosActivos
                    ? 'Ninguna área coincide con la búsqueda o los filtros aplicados.'
                    : 'No hay áreas registradas para esta empresa.'
            "
        >
            <template v-if="hayFiltrosActivos" #acciones>
                <Button variant="outline" @click="limpiarFiltros">
                    <X class="size-4" /> Limpiar filtros
                </Button>
            </template>
            <template v-else-if="permisos.crear" #acciones>
                <Button @click="nueva">
                    <Plus class="size-4" /> Registrar primera área
                </Button>
            </template>
        </EstadoVacio>

        <TooltipProvider v-else-if="vista === 'cards'" :delay-duration="150">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="a in areas.data"
                    :key="a.id"
                    role="button"
                    tabindex="0"
                    :aria-label="`Ver detalles de ${a.nombre}`"
                    class="group focus-visible:ring-ring hover:border-primary/40 flex cursor-pointer flex-col gap-3 rounded-xl border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    @click="verDetalle(a)"
                    @keydown.enter="verDetalle(a)"
                    @keydown.space.prevent="verDetalle(a)"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <span
                                class="bg-muted/60 flex size-9 shrink-0 items-center justify-center rounded-lg border"
                            >
                                <Network class="text-muted-foreground size-4" />
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
                                        texto="Identificador interno del área dentro de la empresa."
                                        etiqueta="Ayuda sobre el código"
                                    />
                                </p>
                            </div>
                        </div>
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Badge
                                    :variant="
                                        a.activa ? 'default' : 'secondary'
                                    "
                                >
                                    {{ a.activa ? 'Activa' : 'Inactiva' }}
                                </Badge>
                            </TooltipTrigger>
                            <TooltipContent>
                                {{
                                    a.activa
                                        ? 'Disponible para asignar colaboradores.'
                                        : 'No disponible para nuevas asignaciones; sus registros históricos se conservan.'
                                }}
                            </TooltipContent>
                        </Tooltip>
                    </div>

                    <p
                        class="text-muted-foreground flex items-center gap-1.5 text-xs"
                    >
                        <Building2 class="size-3" />
                        {{ a.empresa.nombre_comercial }}
                    </p>

                    <p
                        v-if="a.descripcion"
                        class="text-muted-foreground line-clamp-2 text-sm"
                    >
                        {{ a.descripcion }}
                    </p>

                    <div
                        role="button"
                        tabindex="0"
                        :aria-label="`Ver colaboradores del área ${a.nombre}`"
                        class="bg-muted/40 hover:bg-muted/70 hover:border-primary/40 focus-visible:ring-ring w-fit rounded-lg border border-transparent px-3 py-2 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                        @click.stop="irAColaboradores(a)"
                        @keydown.enter.stop="irAColaboradores(a)"
                        @keydown.space.stop.prevent="irAColaboradores(a)"
                    >
                        <p
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <Users class="size-3" /> Colaboradores activos
                            <span @click.stop>
                                <AyudaTooltip
                                    texto="Colaboradores con estado activo asignados a esta área. Entre paréntesis, el total incluyendo inactivos."
                                    etiqueta="Ayuda sobre colaboradores activos"
                                />
                            </span>
                            <ArrowUpRight
                                class="text-muted-foreground/70 ml-auto size-3.5"
                            />
                        </p>
                        <p class="text-lg font-semibold">
                            {{ a.colaboradores_activos }}
                            <span
                                class="text-muted-foreground text-sm font-normal"
                            >
                                / {{ a.colaboradores_total }}
                            </span>
                        </p>
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
                            v-if="permisos.desactivar"
                            variant="ghost"
                            size="sm"
                            @click.stop="alternarEstado(a)"
                        >
                            {{ a.activa ? 'Desactivar' : 'Activar' }}
                        </Button>
                    </div>
                </div>
            </div>
        </TooltipProvider>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Área</th>
                        <th class="px-3 py-2 font-medium">Empresa</th>
                        <th class="px-3 py-2 font-medium">
                            Colaboradores (activos / total)
                        </th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in areas.data" :key="a.id" class="border-t">
                        <td class="px-3 py-2">
                            <p class="font-medium">{{ a.nombre }}</p>
                            <p class="text-muted-foreground font-mono text-xs">
                                {{ a.codigo ?? '—' }}
                            </p>
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ a.empresa.nombre_comercial }}
                        </td>
                        <td class="px-3 py-2">
                            <button
                                type="button"
                                class="text-primary hover:underline"
                                @click="irAColaboradores(a)"
                            >
                                {{ a.colaboradores_activos }} /
                                {{ a.colaboradores_total }}
                            </button>
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="a.activa ? 'default' : 'secondary'"
                            >
                                {{ a.activa ? 'Activa' : 'Inactiva' }}
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
                                    v-if="permisos.editar"
                                    variant="ghost"
                                    size="sm"
                                    @click="editar(a)"
                                    >Editar</Button
                                >
                                <Button
                                    v-if="permisos.desactivar"
                                    variant="ghost"
                                    size="sm"
                                    @click="alternarEstado(a)"
                                >
                                    {{ a.activa ? 'Desactivar' : 'Activar' }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="areas.links" :total="areas.total" />

        <Dialog v-model:open="modalAbierto">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {{ enEdicion ? 'Editar área' : 'Nueva área' }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            enEdicion
                                ? 'Actualiza los datos del área.'
                                : 'Elige la empresa y captura los datos del área.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <FormularioArea
                    :key="claveFormulario"
                    :area="enEdicion"
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
                    <DialogTitle>¿Desactivar esta área?</DialogTitle>
                    <DialogDescription>
                        <span v-if="confirmando" class="font-medium">{{
                            confirmando.nombre
                        }}</span>
                        dejará de estar disponible para nuevas asignaciones. Los
                        colaboradores ya asignados y los registros históricos no
                        se modifican, y podrás reactivarla cuando quieras.
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
                        Desactivar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
