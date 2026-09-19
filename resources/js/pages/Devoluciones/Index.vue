<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Boxes,
    Calendar,
    MapPin,
    Plus,
    Search,
    User,
    UserCog,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import BotonVer from '@/components/sistema/BotonVer.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import DatePicker from '@/components/sistema/DatePicker.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import { varianteBadgeEstadoDevolucion } from '@/lib/estadoDevolucion';
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

type Devolucion = {
    id: number;
    folio: string;
    empresa: string | null;
    colaborador: string;
    numero_empleado: string | null;
    sucursal: string;
    registrada_por: string;
    fecha: string;
    renglones: number;
    estado: string;
    estado_etiqueta: string;
    tiene_acuse: boolean;
};

type OpcionSucursal = { id: number; nombre: string };

const props = defineProps<{
    devoluciones: Paginado<Devolucion>;
    filtros: {
        empresa_id?: number | null;
        buscar?: string;
        sucursal_id?: number | null;
        almacen_id?: number | null;
        colaborador_id?: number | null;
        estado?: string;
        desde?: string;
        hasta?: string;
    };
    colaboradorFiltro: {
        id: number;
        nombre_completo: string;
        numero_empleado: string;
    } | null;
    empresasAutorizadas: EmpresaAutorizada[];
    estados: { valor: string; etiqueta: string }[];
    puedeCrear: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Devoluciones', href: '/devoluciones' }] },
});

const empresaSeleccionada = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === props.filtros.empresa_id) ??
        null,
);
const buscar = ref(props.filtros.buscar ?? '');
const sucursalSeleccionada = ref<OpcionSucursal | null>(null);
const estado = ref(props.filtros.estado ?? '');
const desde = ref(props.filtros.desde ?? '');
const hasta = ref(props.filtros.hasta ?? '');

// `sucursalSeleccionada` no se preselecciona (no llega el nombre de la
// sucursal, sólo su id) — este valor conserva el filtro real hasta que el
// usuario interactúe explícitamente con el combobox, para no perderlo en
// cuanto cambie cualquier otro filtro (p. ej. al llegar desde una card del
// Dashboard con `?sucursal_id=`).
const sucursalIdActivo = ref<number | undefined>(
    props.filtros.sucursal_id ?? undefined,
);
watch(sucursalSeleccionada, (s) => {
    sucursalIdActivo.value = s?.id ?? undefined;
});
// `almacen_id` no tiene selector propio en este listado — sólo llega como
// contexto del Dashboard — pero debe conservarse en cada refiltrado.
const almacenIdDashboard = props.filtros.almacen_id ?? undefined;
// `colaborador_id` llega desde "Devoluciones" del perfil de un colaborador —
// visible (chip con opción de quitarlo), a diferencia de `almacen_id`.
const colaboradorIdActivo = ref(props.filtros.colaborador_id ?? undefined);
function quitarFiltroColaborador(): void {
    colaboradorIdActivo.value = undefined;
}

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

async function buscarSucursales(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionSucursal[]> {
    const params = new URLSearchParams({ q });
    if (empresaSeleccionada.value)
        params.set('empresa_id', String(empresaSeleccionada.value.id));

    const res = await fetch(`/sucursales/buscar?${params.toString()}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];
    return (await res.json()).sucursales ?? [];
}

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

// Cambiar de empresa invalida la sucursal elegida (podría no pertenecerle).
watch(empresaSeleccionada, () => {
    sucursalSeleccionada.value = null;
});

const hayFiltros = computed(
    () =>
        buscar.value !== '' ||
        !!empresaSeleccionada.value ||
        !!sucursalIdActivo.value ||
        estado.value !== '' ||
        desde.value !== '' ||
        hasta.value !== '',
);

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch(
    [
        buscar,
        empresaSeleccionada,
        sucursalIdActivo,
        estado,
        desde,
        hasta,
        colaboradorIdActivo,
    ],
    () => {
        clearTimeout(temporizador);
        temporizador = setTimeout(() => {
            router.get(
                '/devoluciones',
                {
                    buscar: buscar.value || undefined,
                    empresa_id: empresaSeleccionada.value?.id || undefined,
                    sucursal_id: sucursalIdActivo.value || undefined,
                    almacen_id: almacenIdDashboard,
                    estado: estado.value || undefined,
                    desde: desde.value || undefined,
                    hasta: hasta.value || undefined,
                    colaborador_id: colaboradorIdActivo.value,
                },
                { preserveState: true, replace: true, preserveScroll: true },
            );
        }, 300);
    },
);

function limpiarFiltros(): void {
    buscar.value = '';
    empresaSeleccionada.value = null;
    sucursalSeleccionada.value = null;
    sucursalIdActivo.value = undefined;
    estado.value = '';
    desde.value = '';
    hasta.value = '';
}

const vista = useVistaPreferida('devoluciones', 'tabla');
</script>

<template>
    <Head title="Devoluciones" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Devoluciones"
            descripcion="Registra los activos o prendas que un colaborador regresa a un almacén y su condición. Solo los reutilizables reingresan al inventario."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/devoluciones/exportar"
                    :filtros="filtros"
                />
                <Button v-if="puedeCrear" as-child>
                    <Link href="/devoluciones/crear"
                        ><Plus class="size-4" /> Nueva devolución</Link
                    >
                </Button>
            </template>
        </EncabezadoPagina>

        <div
            v-if="colaboradorFiltro"
            class="bg-muted/40 flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2 text-sm"
        >
            <User class="text-muted-foreground size-4 shrink-0" />
            <span>
                Filtrado por colaborador:
                <strong>{{ colaboradorFiltro.nombre_completo }}</strong>
                <span class="text-muted-foreground">
                    · {{ colaboradorFiltro.numero_empleado }}</span
                >
            </span>
            <Button
                variant="ghost"
                size="sm"
                class="ml-auto"
                @click="quitarFiltroColaborador"
            >
                <X class="size-3.5" /> Quitar filtro
            </Button>
        </div>

        <div class="flex flex-col gap-3">
            <div class="relative w-full sm:w-[340px]">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="buscar"
                    class="pl-8"
                    placeholder="Buscar por folio, colaborador o N.º de empleado"
                    aria-label="Buscar devoluciones"
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
                        placeholder="Todas las empresas"
                        placeholder-busqueda="Buscar empresa…"
                        class="w-52"
                    />
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Sucursal</span>
                    <BuscadorAsync
                        v-model="sucursalSeleccionada"
                        :buscar="buscarSucursales"
                        :dependencia="empresaSeleccionada?.id"
                        :etiqueta="(s) => String((s as OpcionSucursal).nombre)"
                        placeholder="Todas"
                        placeholder-busqueda="Buscar sucursal…"
                        class="w-48"
                    />
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Estado</span>
                    <div class="w-44">
                        <SelectSimple
                            v-model="estado"
                            :opciones="[
                                { valor: '', etiqueta: 'Todos' },
                                ...estados,
                            ]"
                        />
                    </div>
                </label>

                <div class="w-36">
                    <DatePicker v-model="desde" placeholder="Desde" />
                </div>
                <div class="w-36">
                    <DatePicker v-model="hasta" placeholder="Hasta" />
                </div>

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
            v-if="!devoluciones.data.length"
            titulo="No hay devoluciones"
            descripcion="Registra una devolución cuando un colaborador entregue activos."
        />

        <div
            v-else-if="vista === 'cards'"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
        >
            <div
                v-for="d in devoluciones.data"
                :key="d.id"
                class="hover:bg-muted/40 relative flex flex-col gap-2 rounded-xl border p-4 transition-colors"
            >
                <!--
                    Tarjeta completa clicable sin anidar <a> dentro de <a>:
                    este Link cubre toda la tarjeta (stretched-link) y queda
                    POR DEBAJO (z-0) del resto del contenido; el BotonVer de
                    abajo se eleva explícitamente (z-10) para seguir siendo
                    clicable por sí mismo. El resto del texto no tiene
                    posición propia, así que un clic ahí cae sobre este Link.
                -->
                <Link
                    :href="`/devoluciones/${d.id}`"
                    :aria-label="`Ver devolución ${d.folio}`"
                    class="focus-visible:ring-ring absolute inset-0 z-0 rounded-xl focus-visible:ring-2 focus-visible:outline-none"
                />
                <div class="flex items-start justify-between gap-2">
                    <p class="font-medium">{{ d.folio }}</p>
                    <Badge :variant="varianteBadgeEstadoDevolucion(d.estado)">{{
                        d.estado_etiqueta
                    }}</Badge>
                </div>
                <p
                    class="text-muted-foreground flex items-center gap-1.5 text-sm"
                >
                    <User class="size-3.5 shrink-0" />
                    {{ d.colaborador
                    }}<span v-if="d.numero_empleado">
                        (N.º {{ d.numero_empleado }})</span
                    >
                </p>
                <p
                    class="text-muted-foreground flex items-center gap-1.5 text-sm"
                >
                    <MapPin class="size-3.5 shrink-0" />
                    {{ d.sucursal }}
                </p>
                <div
                    class="text-muted-foreground mt-auto flex items-center justify-between gap-2 pt-1 text-xs"
                >
                    <span class="flex items-center gap-1">
                        <Calendar class="size-3.5 shrink-0" />
                        {{ d.fecha }}
                    </span>
                    <span class="flex items-center gap-1">
                        <Boxes class="size-3.5 shrink-0" />
                        {{ d.renglones }} renglón(es)
                    </span>
                </div>
                <p
                    class="text-muted-foreground flex items-center gap-1.5 text-xs"
                >
                    <UserCog class="size-3.5 shrink-0" />
                    Registró: {{ d.registrada_por }}
                </p>
                <BotonVer
                    :href="`/devoluciones/${d.id}`"
                    class="relative z-10 mt-1 w-fit"
                />
            </div>
        </div>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Folio</th>
                        <th class="px-3 py-2 font-medium">Empresa</th>
                        <th class="px-3 py-2 font-medium">Colaborador</th>
                        <th class="px-3 py-2 font-medium">Sucursal</th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2 font-medium">Fecha</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Renglones
                        </th>
                        <th class="px-3 py-2 font-medium">Registró</th>
                        <th class="px-3 py-2 font-medium">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="d in devoluciones.data"
                        :key="d.id"
                        class="hover:bg-muted/40 border-t transition-colors"
                    >
                        <td class="px-3 py-2 font-medium">{{ d.folio }}</td>
                        <td class="px-3 py-2">{{ d.empresa }}</td>
                        <td class="px-3 py-2">
                            {{ d.colaborador
                            }}<span
                                v-if="d.numero_empleado"
                                class="text-muted-foreground text-xs"
                            >
                                (N.º {{ d.numero_empleado }})</span
                            >
                        </td>
                        <td class="px-3 py-2">{{ d.sucursal }}</td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="
                                    varianteBadgeEstadoDevolucion(d.estado)
                                "
                                >{{ d.estado_etiqueta }}</Badge
                            >
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ d.fecha }}
                        </td>
                        <td class="px-3 py-2 text-right">{{ d.renglones }}</td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ d.registrada_por }}
                        </td>
                        <td class="px-3 py-2">
                            <BotonVer :href="`/devoluciones/${d.id}`" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="devoluciones.links" :total="devoluciones.total" />
    </div>
</template>
