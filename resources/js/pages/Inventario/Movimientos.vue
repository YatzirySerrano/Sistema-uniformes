<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import DatePicker from '@/components/sistema/DatePicker.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

type Movimiento = {
    id: number;
    tipo_etiqueta: string;
    direccion: string;
    cantidad: number;
    existencia_anterior: number;
    existencia_resultante: number;
    empresa: string | null;
    almacen: string;
    sucursal: string | null;
    activo: string;
    talla: string;
    motivo: string | null;
    realizado_por: string | null;
    ocurrido_en: string;
};

const props = defineProps<{
    movimientos: Paginado<Movimiento>;
    filtros: Record<string, string | number | undefined>;
    empresasAutorizadas: EmpresaAutorizada[];
    almacenes: { id: number; nombre: string }[];
    tipos: { valor: string; etiqueta: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inventario', href: '/inventario' },
            { title: 'Movimientos', href: '/inventario/movimientos' },
        ],
    },
});

const f = ref({
    empresa_id: props.filtros.empresa_id ?? '',
    almacen_id: props.filtros.almacen_id ?? '',
    tipo: props.filtros.tipo ?? '',
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
    almacenSeleccionado.value = null;
});
watch(almacenSeleccionado, (a) => {
    f.value.almacen_id = a?.id ?? '';
});

watch(
    f,
    () => {
        router.get(
            '/inventario/movimientos',
            { ...f.value },
            {
                preserveState: true,
                replace: true,
                preserveScroll: true,
            },
        );
    },
    { deep: true },
);

function fecha(iso: string) {
    return new Date(iso).toLocaleString('es-MX');
}
</script>

<template>
    <Head title="Movimientos de inventario" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Movimientos de inventario"
            descripcion="Historial completo de entradas y salidas. Cada cambio de existencia queda registrado."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/inventario/movimientos/exportar"
                    :filtros="filtros"
                />
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
            <div class="w-56">
                <SelectSimple
                    v-model="f.tipo"
                    :opciones="[
                        { valor: '', etiqueta: 'Todos los tipos' },
                        ...tipos.map((t) => ({
                            valor: t.valor,
                            etiqueta: t.etiqueta,
                        })),
                    ]"
                />
            </div>
            <div class="w-40">
                <DatePicker v-model="f.desde" placeholder="Desde" />
            </div>
            <div class="w-40">
                <DatePicker v-model="f.hasta" placeholder="Hasta" />
            </div>
        </div>

        <EstadoVacio
            v-if="!movimientos.data.length"
            titulo="Sin movimientos"
            descripcion="No hay movimientos que coincidan con los filtros."
        />

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[820px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Fecha</th>
                        <th class="px-3 py-2 font-medium">Tipo</th>
                        <th class="px-3 py-2 font-medium">Almacén</th>
                        <th class="px-3 py-2 font-medium">Activo / Variante</th>
                        <th class="px-3 py-2 text-right font-medium">Cambio</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Antes → Después
                        </th>
                        <th class="px-3 py-2 font-medium">Usuario</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="m in movimientos.data"
                        :key="m.id"
                        class="border-t"
                    >
                        <td
                            class="text-muted-foreground px-3 py-2 whitespace-nowrap"
                        >
                            {{ fecha(m.ocurrido_en) }}
                        </td>
                        <td class="px-3 py-2">{{ m.tipo_etiqueta }}</td>
                        <td class="px-3 py-2">
                            {{ m.almacen ?? '—' }}
                            <span
                                v-if="m.sucursal"
                                class="text-muted-foreground text-xs"
                                >· {{ m.sucursal }}</span
                            >
                        </td>
                        <td class="px-3 py-2">
                            {{ m.activo }}
                            <span class="text-muted-foreground"
                                >· {{ m.talla }}</span
                            >
                        </td>
                        <td
                            class="px-3 py-2 text-right font-medium"
                            :class="
                                m.direccion === 'entrada'
                                    ? 'text-emerald-600'
                                    : 'text-rose-600'
                            "
                        >
                            {{ m.direccion === 'entrada' ? '+' : '−'
                            }}{{ m.cantidad }}
                        </td>
                        <td class="text-muted-foreground px-3 py-2 text-right">
                            {{ m.existencia_anterior }} →
                            {{ m.existencia_resultante }}
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ m.realizado_por ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="movimientos.links" :total="movimientos.total" />
    </div>
</template>
