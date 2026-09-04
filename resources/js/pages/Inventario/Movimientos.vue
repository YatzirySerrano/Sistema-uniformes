<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import type { Paginado } from '@/types/sistema';

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
    empresasAutorizadas: import('@/types/sistema').EmpresaAutorizada[];
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
    desde: props.filtros.desde ?? '',
    hasta: props.filtros.hasta ?? '',
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
            <select
                v-if="empresasAutorizadas.length > 1"
                v-model="f.empresa_id"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                aria-label="Filtrar por empresa"
            >
                <option value="">Todas las empresas</option>
                <option
                    v-for="e in empresasAutorizadas"
                    :key="e.id"
                    :value="e.id"
                >
                    {{ e.nombre_comercial }}
                </option>
            </select>
            <select
                v-model="f.almacen_id"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option value="">Todos los almacenes</option>
                <option v-for="a in almacenes" :key="a.id" :value="a.id">
                    {{ a.nombre }}
                </option>
            </select>
            <select
                v-model="f.tipo"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option value="">Todos los tipos</option>
                <option v-for="t in tipos" :key="t.valor" :value="t.valor">
                    {{ t.etiqueta }}
                </option>
            </select>
            <input
                v-model="f.desde"
                type="date"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            />
            <input
                v-model="f.hasta"
                type="date"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            />
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
