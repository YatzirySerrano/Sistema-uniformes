<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { QrCode, Search, SquareArrowOutUpRight, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import { claseEstadoVisibleUnidad } from '@/lib/estadoVisibleUnidad';
import type { EmpresaAutorizada } from '@/types/sistema';

type UbicacionOperativa =
    | { tipo: 'almacen'; almacen: { id: number; nombre: string } | null }
    | { tipo: 'servicio'; contrato: string; servicio: string }
    | { tipo: 'sin_servicio' }
    | { tipo: 'baja' };

type Unidad = {
    id: number;
    public_token: string;
    codigo: string;
    activo: string | null;
    almacen: string | null;
    colaborador: string | null;
    ubicacion_operativa: UbicacionOperativa;
    estado: string;
    estado_etiqueta: string;
    condicion: string;
    condicion_etiqueta: string;
    estado_visible: string;
    estado_visible_etiqueta: string;
    entregable: boolean;
    marca_modelo: string | null;
    imei_mascara: string | null;
};

const props = defineProps<{
    unidades: {
        data: Unidad[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    empresasAutorizadas: EmpresaAutorizada[];
    estadosVisibles: { valor: string; etiqueta: string }[];
    filtros: {
        buscar: string;
        empresa_id: number | null;
        activo_id: number | '';
        almacen_id: number | '';
        estado: string;
        condicion: string;
        estado_visible: string;
        contrato_id: number | null;
        servicio_id: number | null;
    };
    permisos: { administrar: boolean };
}>();

type OpcionContrato = { id: number; nombre: string };
type OpcionServicio = { id: number; nombre: string; contrato_id: number };

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Activos', href: '/activos' },
            { title: 'Unidades', href: '/activos/unidades' },
        ],
    },
});

/**
 * Texto de la ubicación operativa cuando la unidad está ASIGNADA — la
 * ubicación "en almacén" ya se muestra aparte con `u.almacen`.
 */
function ubicacionServicioTexto(u: UbicacionOperativa): string | null {
    if (u.tipo === 'servicio') return `${u.contrato} — ${u.servicio}`;
    if (u.tipo === 'sin_servicio') return 'Sin servicio asignado';
    return null;
}

const buscar = ref(props.filtros.buscar);
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
const estado = ref(props.filtros.estado);
const condicion = ref(props.filtros.condicion);
const estadoVisible = ref(props.filtros.estado_visible);

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

// --- Filtro por ubicación operativa (Contrato → Servicio del colaborador
// asignado) — dependiente de la empresa seleccionada.
const contratoSeleccionado = ref<OpcionContrato | null>(null);
const servicioSeleccionado = ref<OpcionServicio | null>(null);
const contratoId = computed(() => contratoSeleccionado.value?.id ?? '');
const servicioId = computed(() => servicioSeleccionado.value?.id ?? '');

watch(empresaId, () => {
    contratoSeleccionado.value = null;
    servicioSeleccionado.value = null;
});
watch(contratoSeleccionado, () => {
    servicioSeleccionado.value = null;
});

async function buscarContratosFiltro(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionContrato[]> {
    if (!empresaId.value) return [];
    const res = await fetch(
        `/contratos/buscar?empresa_id=${empresaId.value}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).contratos ?? [];
}

async function buscarServiciosFiltro(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionServicio[]> {
    if (!contratoId.value) return [];
    const res = await fetch(
        `/servicios/buscar?contrato_id=${contratoId.value}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).servicios ?? [];
}

const hayFiltros = computed(
    () =>
        buscar.value !== '' ||
        empresaId.value !== '' ||
        estado.value !== '' ||
        condicion.value !== '' ||
        estadoVisible.value !== '' ||
        contratoId.value !== '' ||
        servicioId.value !== '',
);

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch(
    [
        buscar,
        empresaId,
        estado,
        condicion,
        estadoVisible,
        contratoId,
        servicioId,
    ],
    () => {
        clearTimeout(temporizador);
        temporizador = setTimeout(() => {
            router.get(
                '/activos/unidades',
                {
                    buscar: buscar.value || undefined,
                    empresa_id: empresaId.value || undefined,
                    estado: estado.value || undefined,
                    condicion: condicion.value || undefined,
                    estado_visible: estadoVisible.value || undefined,
                    contrato_id: contratoId.value || undefined,
                    servicio_id: servicioId.value || undefined,
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    only: ['unidades', 'filtros'],
                },
            );
        }, 300);
    },
);

function limpiarFiltros(): void {
    buscar.value = '';
    empresaSeleccionada.value = null;
    estado.value = '';
    condicion.value = '';
    estadoVisible.value = '';
    contratoSeleccionado.value = null;
    servicioSeleccionado.value = null;
}

const idsSeleccionados = ref<number[]>([]);
function alternarSeleccion(id: number): void {
    const i = idsSeleccionados.value.indexOf(id);
    if (i === -1) idsSeleccionados.value.push(id);
    else idsSeleccionados.value.splice(i, 1);
}
function generarEtiquetas(): void {
    if (!idsSeleccionados.value.length) return;
    window.open(
        `/activos/unidades/etiquetas?ids=${idsSeleccionados.value.join(',')}`,
        '_blank',
    );
}

// Etiqueta QR de UNA unidad, sin tener que seleccionarla primero. El QR ya
// existe desde que se creó la unidad (deriva de su `public_token`).
function etiquetaUnidad(id: number): void {
    window.open(`/activos/unidades/etiquetas?ids=${id}`, '_blank');
}

const vista = useVistaPreferida('unidades-activo');
</script>

<template>
    <Head title="Unidades" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Unidades de seguimiento individual"
            descripcion="Cada fila es un objeto físico con código propio generado por el sistema. Selecciona una o varias para generar sus etiquetas QR."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/activos/unidades/exportar"
                    :filtros="filtros"
                />
                <Button
                    v-if="idsSeleccionados.length"
                    variant="outline"
                    @click="generarEtiquetas"
                >
                    <QrCode class="size-4" /> Generar etiquetas ({{
                        idsSeleccionados.length
                    }})
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="flex flex-col gap-3">
            <div class="relative w-full sm:w-[380px]">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="buscar"
                    class="pl-8"
                    placeholder="Buscar por código o activo"
                    aria-label="Buscar unidades"
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
                    v-if="empresaId"
                    class="flex items-center gap-1.5 text-sm"
                >
                    <span class="text-muted-foreground">Contrato</span>
                    <BuscadorAsync
                        v-model="contratoSeleccionado"
                        :buscar="buscarContratosFiltro"
                        :dependencia="empresaId"
                        :etiqueta="(c) => String(c.nombre)"
                        placeholder="Todos"
                        placeholder-busqueda="Buscar contrato…"
                        class="w-52"
                    />
                </label>

                <label
                    v-if="contratoId"
                    class="flex items-center gap-1.5 text-sm"
                >
                    <span class="text-muted-foreground">Servicio</span>
                    <BuscadorAsync
                        v-model="servicioSeleccionado"
                        :buscar="buscarServiciosFiltro"
                        :dependencia="contratoId"
                        :etiqueta="(s) => String(s.nombre)"
                        placeholder="Todos"
                        placeholder-busqueda="Buscar servicio…"
                        class="w-52"
                    />
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Estado</span>
                    <div class="w-40">
                        <SelectSimple
                            v-model="estadoVisible"
                            :opciones="[
                                { valor: '', etiqueta: 'Todos' },
                                ...estadosVisibles,
                            ]"
                        />
                    </div>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Posesión</span>
                    <div class="w-40">
                        <SelectSimple
                            v-model="estado"
                            :opciones="[
                                { valor: '', etiqueta: 'Todas' },
                                { valor: 'en_almacen', etiqueta: 'En almacén' },
                                { valor: 'asignada', etiqueta: 'Asignada' },
                                { valor: 'baja', etiqueta: 'Baja' },
                            ]"
                        />
                    </div>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Condición</span>
                    <div class="w-44">
                        <SelectSimple
                            v-model="condicion"
                            :opciones="[
                                { valor: '', etiqueta: 'Todas' },
                                {
                                    valor: 'funcionando',
                                    etiqueta: 'Funcionando',
                                },
                                {
                                    valor: 'en_reparacion',
                                    etiqueta: 'En reparación',
                                },
                                {
                                    valor: 'inservible',
                                    etiqueta: 'Inservible',
                                },
                                { valor: 'perdido', etiqueta: 'Perdido' },
                                { valor: 'robado', etiqueta: 'Robado' },
                            ]"
                        />
                    </div>
                </label>

                <Button
                    v-if="hayFiltros"
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
            v-if="!unidades.data.length"
            titulo="No hay unidades para mostrar"
            :descripcion="
                hayFiltros
                    ? 'Ninguna unidad coincide con la búsqueda o los filtros aplicados.'
                    : 'Las unidades se crean desde el alta de un activo de seguimiento individual.'
            "
        />

        <div
            v-else-if="vista === 'cards'"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <div
                v-for="u in unidades.data"
                :key="u.id"
                class="flex flex-col gap-2 rounded-xl border p-3"
            >
                <div class="flex items-start justify-between gap-2">
                    <label class="flex items-start gap-2">
                        <input
                            type="checkbox"
                            class="mt-1 size-4"
                            :aria-label="`Seleccionar unidad ${u.codigo}`"
                            :checked="idsSeleccionados.includes(u.id)"
                            @change="alternarSeleccion(u.id)"
                        />
                        <div class="min-w-0">
                            <p class="truncate font-mono text-sm font-medium">
                                {{ u.codigo }}
                            </p>
                            <p class="text-muted-foreground truncate text-xs">
                                {{ u.activo ?? '—' }}
                            </p>
                            <p
                                v-if="u.marca_modelo || u.imei_mascara"
                                class="text-muted-foreground truncate text-xs"
                            >
                                {{ u.marca_modelo
                                }}<span v-if="u.marca_modelo && u.imei_mascara">
                                    · </span
                                ><span v-if="u.imei_mascara"
                                    >IMEI {{ u.imei_mascara }}</span
                                >
                            </p>
                        </div>
                    </label>
                </div>

                <div class="flex flex-wrap gap-1.5">
                    <Badge
                        variant="outline"
                        class="text-xs"
                        :class="claseEstadoVisibleUnidad(u.estado_visible)"
                    >
                        {{ u.estado_visible_etiqueta }}
                    </Badge>
                    <Badge
                        v-if="u.condicion !== 'funcionando'"
                        variant="outline"
                        class="text-muted-foreground text-xs"
                    >
                        {{ u.condicion_etiqueta }}
                    </Badge>
                </div>

                <p class="text-muted-foreground text-xs">
                    {{ u.almacen ?? 'Sin almacén' }}
                </p>
                <p v-if="u.colaborador" class="text-muted-foreground text-xs">
                    Con: {{ u.colaborador }} ·
                    {{ ubicacionServicioTexto(u.ubicacion_operativa) }}
                </p>

                <div class="mt-auto flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="`/activos/unidades/${u.public_token}`">
                            <SquareArrowOutUpRight class="size-3.5" /> Ver
                            detalle
                        </Link>
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        :aria-label="`Etiqueta QR de la unidad ${u.codigo}`"
                        @click="etiquetaUnidad(u.id)"
                    >
                        <QrCode class="size-3.5" /> Etiqueta QR
                    </Button>
                </div>
            </div>
        </div>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="w-8 px-3 py-2"></th>
                        <th class="px-3 py-2 font-medium">Código / Activo</th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2 font-medium">
                            Almacén / Colaborador
                        </th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="u in unidades.data"
                        :key="u.id"
                        class="hover:bg-muted/40 border-t transition-colors"
                    >
                        <td class="px-3 py-2">
                            <input
                                type="checkbox"
                                class="size-4"
                                :aria-label="`Seleccionar unidad ${u.codigo}`"
                                :checked="idsSeleccionados.includes(u.id)"
                                @change="alternarSeleccion(u.id)"
                            />
                        </td>
                        <td class="px-3 py-2">
                            <p class="font-mono font-medium">{{ u.codigo }}</p>
                            <p class="text-muted-foreground text-xs">
                                {{ u.activo ?? '—' }}
                            </p>
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                variant="outline"
                                class="text-xs"
                                :class="
                                    claseEstadoVisibleUnidad(u.estado_visible)
                                "
                            >
                                {{ u.estado_visible_etiqueta }}
                            </Badge>
                            <span
                                v-if="u.condicion !== 'funcionando'"
                                class="text-muted-foreground block text-xs"
                            >
                                {{ u.condicion_etiqueta }}
                            </span>
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ u.almacen ?? 'Sin almacén' }}
                            <span v-if="u.colaborador" class="block text-xs">
                                Con: {{ u.colaborador }} ·
                                {{
                                    ubicacionServicioTexto(
                                        u.ubicacion_operativa,
                                    )
                                }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <div class="flex justify-end gap-2">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="`Etiqueta QR de la unidad ${u.codigo}`"
                                    @click="etiquetaUnidad(u.id)"
                                >
                                    <QrCode class="size-3.5" /> QR
                                </Button>
                                <Button variant="outline" size="sm" as-child>
                                    <Link
                                        :href="`/activos/unidades/${u.public_token}`"
                                        >Ver</Link
                                    >
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="unidades.links" :total="unidades.total" />
    </div>
</template>
