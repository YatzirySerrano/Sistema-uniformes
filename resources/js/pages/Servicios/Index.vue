<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
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
import type { ServicioEditable } from '@/components/servicios/FormularioServicio.vue';
import FormularioServicio from '@/components/servicios/FormularioServicio.vue';
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
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

type ContratoOpcion = { id: number; nombre: string; activo: boolean };
type ServicioFila = {
    id: number;
    nombre: string;
    codigo: string | null;
    direccion: string | null;
    descripcion: string | null;
    activo: boolean;
    contrato: ContratoOpcion;
    sucursal: { id: number; nombre: string };
    empresa: { id: number; nombre_comercial: string };
};

const props = defineProps<{
    servicios: Paginado<ServicioFila>;
    empresasAutorizadas: EmpresaAutorizada[];
    filtros: {
        buscar: string;
        estado: '' | 'activos' | 'inactivos';
        orden: 'az' | 'za';
        empresa_id: number | null;
        contrato_id: number | null;
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
        breadcrumbs: [{ title: 'Servicios', href: '/servicios' }],
    },
});

const buscar = ref(props.filtros.buscar);
const estado = ref<'' | 'activos' | 'inactivos'>(props.filtros.estado);
const orden = ref<'az' | 'za'>(props.filtros.orden);
const empresaSeleccionada = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === props.filtros.empresa_id) ??
        null,
);
const contratoSeleccionado = ref<ContratoOpcion | null>(null);
const empresaId = computed(() => empresaSeleccionada.value?.id ?? '');
const contratoId = computed(() => contratoSeleccionado.value?.id ?? '');

watch(empresaSeleccionada, () => {
    contratoSeleccionado.value = null;
});

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

async function buscarContratos(termino: string, signal?: AbortSignal) {
    if (empresaSeleccionada.value === null) return [];

    const params = new URLSearchParams({
        empresa_id: String(empresaSeleccionada.value.id),
        q: termino,
    });
    const res = await fetch(`/contratos/buscar?${params}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];

    return ((await res.json()).contratos ?? []) as ContratoOpcion[];
}

const hayFiltrosActivos = computed(
    () =>
        buscar.value !== '' ||
        estado.value !== '' ||
        orden.value !== 'az' ||
        empresaId.value !== '' ||
        contratoId.value !== '',
);

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch([buscar, estado, orden, empresaId, contratoId], () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get(
            '/servicios',
            {
                buscar: buscar.value || undefined,
                estado: estado.value || undefined,
                orden: orden.value === 'az' ? undefined : orden.value,
                empresa_id: empresaId.value || undefined,
                contrato_id: contratoId.value || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['servicios', 'filtros'],
            },
        );
    }, 300);
});

function limpiarFiltros(): void {
    buscar.value = '';
    estado.value = '';
    orden.value = 'az';
    empresaSeleccionada.value = null;
    contratoSeleccionado.value = null;
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
const enEdicion = ref<ServicioEditable | null>(null);
const claveFormulario = ref(0);

function nuevo(): void {
    enEdicion.value = null;
    claveFormulario.value++;
    modalAbierto.value = true;
}

function editar(s: ServicioFila): void {
    enEdicion.value = {
        id: s.id,
        nombre: s.nombre,
        codigo: s.codigo,
        direccion: s.direccion,
        descripcion: s.descripcion,
        contrato: s.contrato,
        sucursal: s.sucursal,
        empresa: s.empresa,
    };
    claveFormulario.value++;
    modalAbierto.value = true;
}

function alGuardar(): void {
    modalAbierto.value = false;
}

function verDetalle(s: ServicioFila): void {
    router.visit(`/servicios/${s.id}`);
}

const confirmando = ref<ServicioFila | null>(null);
const procesandoEstado = ref(false);

function confirmarEstado(): void {
    if (!confirmando.value) return;
    procesandoEstado.value = true;
    router.post(
        `/servicios/${confirmando.value.id}/estado`,
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

function alternarEstado(s: ServicioFila): void {
    if (s.activo) {
        confirmando.value = s;
    } else {
        router.post(`/servicios/${s.id}/estado`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Servicios" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Servicios"
            descripcion="Puestos operativos derivados de un contrato, donde trabajan los colaboradores y sus activos asignados."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/servicios/exportar"
                    :filtros="filtros"
                />
                <Button v-if="permisos.crear" @click="nuevo">
                    <Plus class="size-4" /> Nuevo servicio
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

                <label
                    v-if="empresaSeleccionada"
                    class="flex items-center gap-1.5 text-sm"
                >
                    <span class="text-muted-foreground">Contrato</span>
                    <BuscadorAsync
                        v-model="contratoSeleccionado"
                        :buscar="buscarContratos"
                        :etiqueta="(c) => String(c.nombre)"
                        :dependencia="empresaSeleccionada?.id ?? null"
                        placeholder="Todos"
                        placeholder-busqueda="Buscar contrato…"
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
            v-if="!servicios.data.length"
            titulo="No hay servicios para mostrar"
            :descripcion="
                hayFiltrosActivos
                    ? 'Ningún servicio coincide con la búsqueda o los filtros aplicados.'
                    : 'No hay servicios registrados todavía.'
            "
        >
            <template v-if="hayFiltrosActivos" #acciones>
                <Button variant="outline" @click="limpiarFiltros">
                    <X class="size-4" /> Limpiar filtros
                </Button>
            </template>
            <template v-else-if="permisos.crear" #acciones>
                <Button @click="nuevo">
                    <Plus class="size-4" /> Registrar primer servicio
                </Button>
            </template>
        </EstadoVacio>

        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="s in servicios.data"
                :key="s.id"
                role="button"
                tabindex="0"
                :aria-label="`Ver detalles de ${s.nombre}`"
                class="group focus-visible:ring-ring hover:border-primary/20 flex cursor-pointer flex-col gap-3 rounded-xl border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                @click="verDetalle(s)"
                @keydown.enter="verDetalle(s)"
                @keydown.space.prevent="verDetalle(s)"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2.5">
                        <span
                            class="bg-muted/60 flex size-9 shrink-0 items-center justify-center rounded-lg border"
                        >
                            <MapPin class="text-muted-foreground size-4" />
                        </span>
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ s.nombre }}</p>
                            <p class="text-muted-foreground font-mono text-xs">
                                {{ s.codigo ?? '—' }}
                            </p>
                        </div>
                    </div>
                    <Badge :variant="s.activo ? 'success' : 'secondary'">
                        {{ s.activo ? 'Activo' : 'Eliminado' }}
                    </Badge>
                </div>

                <div class="text-muted-foreground flex flex-col gap-1 text-xs">
                    <span class="flex items-center gap-1.5">
                        <FileSignature class="size-3" />
                        {{ s.contrato.nombre }}
                        <Badge
                            v-if="!s.contrato.activo"
                            variant="secondary"
                            class="ml-1"
                            >Contrato inactivo</Badge
                        >
                    </span>
                    <span class="flex items-center gap-1.5">
                        <Building2 class="size-3" />
                        {{ s.empresa.nombre_comercial }} · Sucursal
                        {{ s.sucursal.nombre }}
                    </span>
                </div>

                <p
                    v-if="s.descripcion"
                    class="text-muted-foreground line-clamp-2 text-sm"
                >
                    {{ s.descripcion }}
                </p>

                <div class="mt-auto flex flex-wrap gap-2 pt-1">
                    <Button
                        variant="outline"
                        size="sm"
                        @click.stop="verDetalle(s)"
                    >
                        <SquareArrowOutUpRight class="size-3.5" />
                        Ver detalles
                    </Button>
                    <Button
                        v-if="permisos.editar"
                        variant="ghost"
                        size="sm"
                        @click.stop="editar(s)"
                    >
                        <Pencil class="size-3.5" /> Editar
                    </Button>
                    <Button
                        v-if="permisos.administrar"
                        variant="ghost"
                        size="sm"
                        @click.stop="alternarEstado(s)"
                    >
                        {{ s.activo ? 'Eliminar' : 'Restaurar' }}
                    </Button>
                </div>
            </div>
        </div>

        <Paginacion :links="servicios.links" :total="servicios.total" />

        <Dialog v-model:open="modalAbierto">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {{ enEdicion ? 'Editar servicio' : 'Nuevo servicio' }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            enEdicion
                                ? 'Actualiza los datos del servicio.'
                                : 'Elige empresa, contrato y sucursal, y captura los datos del servicio.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <FormularioServicio
                    :key="claveFormulario"
                    :servicio="enEdicion"
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
                        >¿Eliminar el servicio
                        <span v-if="confirmando">{{ confirmando.nombre }}</span
                        >?</DialogTitle
                    >
                    <DialogDescription>
                        Esta acción lo retirará de los listados y de nuevas
                        asignaciones. Los colaboradores que ya lo tengan como
                        servicio actual NO se modifican automáticamente, y
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
