<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeftRight, Boxes, Calendar, User, Warehouse } from '@lucide/vue';
import { ref, watch } from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import BotonVer from '@/components/sistema/BotonVer.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import DatePicker from '@/components/sistema/DatePicker.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import { fechaHora } from '@/lib/fecha';
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

type Traspaso = {
    id: number;
    folio: string;
    estado: string;
    interempresa: boolean;
    empresa_origen: string | null;
    almacen_origen: string | null;
    empresa_destino: string | null;
    almacen_destino: string | null;
    renglones: number;
    unidades: number;
    realizado_por: string | null;
    ocurrido_en: string;
};

const props = defineProps<{
    traspasos: Paginado<Traspaso>;
    filtros: Record<string, string | number | undefined>;
    empresasAutorizadas: EmpresaAutorizada[];
    almacenes: { id: number; nombre: string }[];
    puedeTransferir: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inventario', href: '/inventario' },
            { title: 'Traspasos de inventario', href: '/inventario/traspasos' },
        ],
    },
});

const f = ref({
    empresa_id: props.filtros.empresa_id ?? '',
    almacen_id: props.filtros.almacen_id ?? '',
    desde: String(props.filtros.desde ?? ''),
    hasta: String(props.filtros.hasta ?? ''),
});

const empresaSeleccionada = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === f.value.empresa_id) ?? null,
);
const almacenSeleccionado = ref<{ id: number; nombre: string } | null>(
    props.almacenes.find((a) => a.id === f.value.almacen_id) ?? null,
);

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

async function buscarAlmacenes(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.almacenes.filter((a) => a.nombre.toLowerCase().includes(t));
}

watch(empresaSeleccionada, (e) => {
    f.value.empresa_id = e?.id ?? '';
});
watch(almacenSeleccionado, (a) => {
    f.value.almacen_id = a?.id ?? '';
});

watch(
    f,
    () => {
        router.get(
            '/inventario/traspasos',
            { ...f.value },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    },
    { deep: true },
);

function fecha(iso: string) {
    return fechaHora(iso);
}

const vista = useVistaPreferida('traspasos', 'cards');
</script>

<template>
    <Head title="Traspasos de inventario" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Traspasos de inventario"
            descripcion="Consulta y registra transferencias de inventario entre almacenes."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/inventario/traspasos/exportar"
                    :filtros="filtros"
                />
                <Button v-if="puedeTransferir" as-child size="sm">
                    <Link href="/inventario/traspasos/crear">
                        <ArrowLeftRight class="size-4" /> Nuevo traspaso
                    </Link>
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="flex flex-wrap gap-2">
            <BuscadorAsync
                v-if="empresasAutorizadas.length > 1"
                v-model="empresaSeleccionada"
                :buscar="buscarEmpresas"
                :etiqueta="(e) => String(e.nombre_comercial)"
                placeholder="Todas las empresas"
                placeholder-busqueda="Buscar empresa…"
                class="w-56"
            />
            <BuscadorAsync
                v-model="almacenSeleccionado"
                :buscar="buscarAlmacenes"
                :etiqueta="(a) => String(a.nombre)"
                placeholder="Todos los almacenes"
                placeholder-busqueda="Buscar almacén…"
                class="w-56"
            />
            <div class="w-40">
                <DatePicker v-model="f.desde" placeholder="Desde" />
            </div>
            <div class="w-40">
                <DatePicker v-model="f.hasta" placeholder="Hasta" />
            </div>
            <SelectorVista v-model="vista" class="ml-auto" />
        </div>

        <EstadoVacio
            v-if="!traspasos.data.length"
            titulo="Sin traspasos"
            descripcion="No hay traspasos de inventario que coincidan con los filtros."
        />

        <div
            v-else-if="vista === 'cards'"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
        >
            <Link
                v-for="t in traspasos.data"
                :key="t.id"
                :href="`/inventario/traspasos/${t.id}`"
                class="hover:bg-muted/40 focus-visible:ring-ring flex flex-col gap-2 rounded-xl border p-4 text-sm transition-colors focus-visible:ring-2 focus-visible:outline-none"
            >
                <div class="flex items-start justify-between gap-2">
                    <span class="flex items-center gap-1.5 font-medium">
                        <ArrowLeftRight
                            class="text-muted-foreground size-4 shrink-0"
                        />
                        {{ t.folio }}
                    </span>
                    <Badge v-if="t.interempresa" variant="outline"
                        >Interempresa</Badge
                    >
                </div>

                <div class="text-muted-foreground flex flex-col gap-1 text-xs">
                    <span class="flex min-w-0 items-center gap-1">
                        <Warehouse class="size-3.5 shrink-0" />
                        <span class="truncate"
                            >Origen: {{ t.empresa_origen }} ·
                            {{ t.almacen_origen }}</span
                        >
                    </span>
                    <span class="flex min-w-0 items-center gap-1">
                        <Warehouse class="size-3.5 shrink-0" />
                        <span class="truncate"
                            >Destino: {{ t.empresa_destino }} ·
                            {{ t.almacen_destino }}</span
                        >
                    </span>
                </div>

                <div
                    class="text-muted-foreground mt-auto flex items-center justify-between gap-2 pt-1 text-xs"
                >
                    <span class="flex items-center gap-1">
                        <Boxes class="size-3.5 shrink-0" />
                        {{ t.renglones }} renglón(es) · {{ t.unidades }}
                        unidad(es)
                    </span>
                    <span class="flex shrink-0 items-center gap-1">
                        <Calendar class="size-3.5" />
                        {{ fecha(t.ocurrido_en) }}
                    </span>
                </div>
                <p
                    v-if="t.realizado_por"
                    class="text-muted-foreground flex items-center gap-1 text-xs"
                >
                    <User class="size-3.5 shrink-0" /> {{ t.realizado_por }}
                </p>
            </Link>
        </div>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[820px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Folio</th>
                        <th class="px-3 py-2 font-medium">Origen</th>
                        <th class="px-3 py-2 font-medium">Destino</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Renglones
                        </th>
                        <th class="px-3 py-2 text-right font-medium">
                            Unidades
                        </th>
                        <th class="px-3 py-2 font-medium">Fecha</th>
                        <th class="px-3 py-2 font-medium">Responsable</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="t in traspasos.data"
                        :key="t.id"
                        class="hover:bg-muted/40 border-t transition-colors"
                    >
                        <td class="px-3 py-2 font-medium">
                            {{ t.folio }}
                            <Badge
                                v-if="t.interempresa"
                                variant="outline"
                                class="ml-1"
                                >Interempresa</Badge
                            >
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ t.empresa_origen }} · {{ t.almacen_origen }}
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ t.empresa_destino }} · {{ t.almacen_destino }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            {{ t.renglones }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            {{ t.unidades }}
                        </td>
                        <td
                            class="text-muted-foreground px-3 py-2 whitespace-nowrap"
                        >
                            {{ fecha(t.ocurrido_en) }}
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ t.realizado_por ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            <BotonVer :href="`/inventario/traspasos/${t.id}`" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="traspasos.links" :total="traspasos.total" />
    </div>
</template>
