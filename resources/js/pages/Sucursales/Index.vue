<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Building2,
    MapPin,
    Pencil,
    Phone,
    Plus,
    Search,
    SquareArrowOutUpRight,
    Store,
    Users,
    X,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import type { SucursalEditable } from '@/components/sucursales/FormularioSucursal.vue';
import FormularioSucursal from '@/components/sucursales/FormularioSucursal.vue';
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

type SucursalFila = SucursalEditable & {
    activa: boolean;
    empresa: { id: number; nombre_comercial: string };
    colaboradores_activos: number;
};

const props = defineProps<{
    sucursales: Paginado<SucursalFila>;
    empresasAutorizadas: EmpresaAutorizada[];
    filtros: {
        buscar: string;
        estado: '' | 'activas' | 'inactivas';
        orden: 'az' | 'za';
        empresa_id: number | null;
    };
    permisos: {
        crear: boolean;
        editar: boolean;
        desactivar: boolean;
        verEliminadas: boolean;
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Sucursales', href: '/sucursales' }] },
});

// --- Filtros ---
const buscar = ref(props.filtros.buscar);
const estado = ref<'' | 'activas' | 'inactivas'>(props.filtros.estado);
const orden = ref<'az' | 'za'>(props.filtros.orden);
const empresaSeleccionada = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === props.filtros.empresa_id) ??
        null,
);
const empresaId = computed(() => empresaSeleccionada.value?.id ?? '');
// Resincroniza el filtro si el backend resuelve una empresa distinta a la
// que ya tenía este ref local — nunca se queda con un valor obsoleto ni
// "inventa" la primera empresa de la lista.
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
            '/sucursales',
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
                only: ['sucursales', 'filtros'],
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

// La opción "Eliminadas" (internamente sigue siendo `activa = false`) sólo
// se ofrece a quien puede eliminar/restaurar sucursales — el backend además
// la ignora si alguien la fuerza por URL sin el permiso.
const filtrosEstado = computed<
    { valor: '' | 'activas' | 'inactivas'; texto: string }[]
>(() => [
    { valor: '', texto: 'Todas' },
    { valor: 'activas', texto: 'Activas' },
    ...(props.permisos.verEliminadas
        ? ([{ valor: 'inactivas', texto: 'Eliminadas' }] as const)
        : []),
]);

// --- Alta / edición ---
const modalAbierto = ref(false);
const enEdicion = ref<SucursalEditable | null>(null);
const claveFormulario = ref(0);

function nueva(): void {
    enEdicion.value = null;
    claveFormulario.value++;
    modalAbierto.value = true;
}

function editar(s: SucursalFila): void {
    enEdicion.value = {
        id: s.id,
        codigo: s.codigo,
        nombre: s.nombre,
        direccion: s.direccion,
        telefono: s.telefono,
        empresa_id: s.empresa.id,
    };
    claveFormulario.value++;
    modalAbierto.value = true;
}

function alGuardar(): void {
    modalAbierto.value = false;
}

function verDetalle(s: SucursalFila): void {
    router.visit(`/sucursales/${s.id}`);
}

function irAColaboradores(s: SucursalFila): void {
    router.visit(`/colaboradores?sucursal_id=${s.id}`);
}

function telefonoLegible(telefono: string | null): string | null {
    if (!telefono) return null;
    return telefono.length === 10
        ? `${telefono.slice(0, 2)} ${telefono.slice(2, 6)} ${telefono.slice(6)}`
        : telefono;
}

// Apertura automática al llegar desde el detalle de Empresa (?nueva=1).
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('nueva') === '1' && props.permisos.crear) {
        nueva();
    }
});

// --- Activar / desactivar ---
const confirmando = ref<SucursalFila | null>(null);
const procesandoEstado = ref(false);

function confirmarEstado(): void {
    if (!confirmando.value) return;
    procesandoEstado.value = true;
    router.post(
        `/sucursales/${confirmando.value.id}/estado`,
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

function alternarEstado(s: SucursalFila): void {
    if (s.activa) {
        confirmando.value = s; // desactivar => confirmación
    } else {
        // reactivar es seguro: sin confirmación
        router.post(`/sucursales/${s.id}/estado`, {}, { preserveScroll: true });
    }
}

const vista = useVistaPreferida('sucursales');
</script>

<template>
    <Head title="Sucursales" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Sucursales"
            descripcion="Ubicaciones de las empresas. Usa el filtro de empresa para acotar el listado."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/sucursales/exportar"
                    :filtros="filtros"
                />
                <Button v-if="permisos.crear" @click="nueva">
                    <Plus class="size-4" /> Nueva sucursal
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
            v-if="!sucursales.data.length"
            titulo="No hay sucursales para mostrar"
            :descripcion="
                hayFiltrosActivos
                    ? 'Ninguna sucursal coincide con la búsqueda o los filtros aplicados.'
                    : 'No hay sucursales registradas para esta empresa.'
            "
        >
            <template v-if="hayFiltrosActivos" #acciones>
                <Button variant="outline" @click="limpiarFiltros">
                    <X class="size-4" /> Limpiar filtros
                </Button>
            </template>
            <template v-else-if="permisos.crear" #acciones>
                <Button @click="nueva">
                    <Plus class="size-4" /> Registrar primera sucursal
                </Button>
            </template>
        </EstadoVacio>

        <TooltipProvider v-else-if="vista === 'cards'" :delay-duration="150">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="s in sucursales.data"
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
                                <Store class="text-muted-foreground size-4" />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ s.nombre }}
                                </p>
                                <p
                                    class="text-muted-foreground flex items-center gap-1 font-mono text-xs"
                                >
                                    {{ s.codigo }}
                                    <AyudaTooltip
                                        texto="Identificador interno de la sucursal dentro de la empresa."
                                        etiqueta="Ayuda sobre el código"
                                    />
                                </p>
                            </div>
                        </div>
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Badge
                                    :variant="
                                        s.activa ? 'success' : 'secondary'
                                    "
                                >
                                    {{ s.activa ? 'Activa' : 'Eliminada' }}
                                </Badge>
                            </TooltipTrigger>
                            <TooltipContent>
                                {{
                                    s.activa
                                        ? 'Disponible para nuevas operaciones.'
                                        : 'No disponible para nuevas operaciones; sus registros históricos se conservan.'
                                }}
                            </TooltipContent>
                        </Tooltip>
                    </div>

                    <p
                        class="text-muted-foreground flex items-center gap-1.5 text-xs"
                    >
                        <Building2 class="size-3" />
                        {{ s.empresa.nombre_comercial }}
                    </p>

                    <div
                        v-if="s.direccion || s.telefono"
                        class="text-muted-foreground flex flex-col gap-1 text-sm"
                    >
                        <p v-if="s.direccion" class="flex items-center gap-1.5">
                            <MapPin class="size-3.5 shrink-0" />
                            <span class="line-clamp-1">{{ s.direccion }}</span>
                        </p>
                        <p v-if="s.telefono" class="flex items-center gap-1.5">
                            <Phone class="size-3.5 shrink-0" />
                            {{ telefonoLegible(s.telefono) }}
                        </p>
                    </div>

                    <div
                        role="button"
                        tabindex="0"
                        :aria-label="`Ver colaboradores activos de ${s.nombre}`"
                        class="bg-muted/40 hover:bg-muted/70 hover:border-primary/20 focus-visible:ring-ring w-fit rounded-lg border border-transparent px-3 py-2 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                        @click.stop="irAColaboradores(s)"
                        @keydown.enter.stop="irAColaboradores(s)"
                        @keydown.space.stop.prevent="irAColaboradores(s)"
                    >
                        <p
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <Users class="size-3" /> Colaboradores activos
                            <span @click.stop>
                                <AyudaTooltip
                                    texto="Colaboradores con estado activo asignados a esta sucursal. No incluye inactivos ni dados de baja."
                                    etiqueta="Ayuda sobre colaboradores activos"
                                />
                            </span>
                            <ArrowUpRight
                                class="text-muted-foreground/70 ml-auto size-3.5"
                            />
                        </p>
                        <p class="text-lg font-semibold">
                            {{ s.colaboradores_activos }}
                        </p>
                    </div>

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
                            v-if="permisos.desactivar"
                            variant="ghost"
                            size="sm"
                            @click.stop="alternarEstado(s)"
                        >
                            {{ s.activa ? 'Eliminar' : 'Restaurar' }}
                        </Button>
                    </div>
                </div>
            </div>
        </TooltipProvider>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Sucursal</th>
                        <th class="px-3 py-2 font-medium">Empresa</th>
                        <th class="px-3 py-2 font-medium">
                            Dirección / Teléfono
                        </th>
                        <th class="px-3 py-2 font-medium">
                            Colaboradores activos
                        </th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="s in sucursales.data"
                        :key="s.id"
                        class="hover:bg-muted/40 border-t transition-colors"
                    >
                        <td class="px-3 py-2">
                            <p class="font-medium">{{ s.nombre }}</p>
                            <p class="text-muted-foreground font-mono text-xs">
                                {{ s.codigo }}
                            </p>
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ s.empresa.nombre_comercial }}
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ s.direccion ?? '—' }}
                            <span v-if="s.telefono" class="block text-xs">{{
                                telefonoLegible(s.telefono)
                            }}</span>
                        </td>
                        <td class="px-3 py-2">
                            <button
                                type="button"
                                class="text-primary hover:underline"
                                @click="irAColaboradores(s)"
                            >
                                {{ s.colaboradores_activos }}
                            </button>
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="s.activa ? 'success' : 'secondary'"
                            >
                                {{ s.activa ? 'Activa' : 'Eliminada' }}
                            </Badge>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <div class="flex justify-end gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="verDetalle(s)"
                                    >Ver</Button
                                >
                                <Button
                                    v-if="permisos.editar"
                                    variant="ghost"
                                    size="sm"
                                    @click="editar(s)"
                                    >Editar</Button
                                >
                                <Button
                                    v-if="permisos.desactivar"
                                    variant="ghost"
                                    size="sm"
                                    @click="alternarEstado(s)"
                                >
                                    {{ s.activa ? 'Eliminar' : 'Restaurar' }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="sucursales.links" :total="sucursales.total" />

        <!-- Modal alta / edición -->
        <Dialog v-model:open="modalAbierto">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {{ enEdicion ? 'Editar sucursal' : 'Nueva sucursal' }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            enEdicion
                                ? 'Actualiza los datos de la sucursal.'
                                : 'Elige la empresa y captura los datos de la sucursal.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <FormularioSucursal
                    :key="claveFormulario"
                    :sucursal="enEdicion"
                    :empresas-autorizadas="empresasAutorizadas"
                    @guardado="alGuardar"
                    @cancelar="modalAbierto = false"
                />
            </DialogContent>
        </Dialog>

        <!-- Confirmar desactivación -->
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
                        >¿Eliminar la sucursal
                        <span v-if="confirmando">{{ confirmando.nombre }}</span
                        >?</DialogTitle
                    >
                    <DialogDescription>
                        Esta acción retirará la sucursal de los listados y
                        operaciones disponibles. Los registros históricos no se
                        eliminarán y podrás restaurarla cuando quieras.
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
