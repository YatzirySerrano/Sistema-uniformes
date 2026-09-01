<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ClipboardList,
    PenLine,
    Shirt,
    Users,
} from '@lucide/vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type Resumen = {
    colaboradores_activos: number;
    entregas_mes: number;
    pendientes_firma: number;
    prendas_entregadas_mes: number;
    stock_bajo: number;
    entregas_recientes: {
        id: number;
        folio: string;
        colaborador: string;
        sucursal: string;
        estado_etiqueta: string;
        fecha_entrega: string;
    }[];
    movimientos_recientes: {
        id: number;
        tipo_etiqueta: string;
        direccion: string;
        cantidad: number;
        prenda: string;
        talla: string;
        sucursal: string;
        existencia_resultante: number;
    }[];
    stock_bajo_detalle: {
        prenda: string;
        talla: string;
        sucursal: string;
        cantidad: number;
        minimo: number;
    }[];
    distribucion_sucursal: { sucursal: string; total: number }[];
};

defineProps<{ resumen: Resumen | null; sinEmpresa: boolean }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Panel', href: '/dashboard' }],
    },
});
</script>

<template>
    <Head title="Panel" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <EstadoVacio
            v-if="sinEmpresa || !resumen"
            titulo="Selecciona una empresa"
            descripcion="Elige una empresa activa en el menú lateral para ver su panel."
        />

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between pb-2"
                    >
                        <CardTitle class="text-muted-foreground text-sm"
                            >Colaboradores activos</CardTitle
                        >
                        <Users class="text-muted-foreground size-4" />
                    </CardHeader>
                    <CardContent class="text-2xl font-semibold">
                        {{ resumen.colaboradores_activos }}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between pb-2"
                    >
                        <CardTitle class="text-muted-foreground text-sm"
                            >Entregas del mes</CardTitle
                        >
                        <ClipboardList class="text-muted-foreground size-4" />
                    </CardHeader>
                    <CardContent class="text-2xl font-semibold">
                        {{ resumen.entregas_mes }}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between pb-2"
                    >
                        <CardTitle class="text-muted-foreground text-sm"
                            >Pendientes de firma</CardTitle
                        >
                        <PenLine class="text-muted-foreground size-4" />
                    </CardHeader>
                    <CardContent class="text-2xl font-semibold">
                        {{ resumen.pendientes_firma }}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between pb-2"
                    >
                        <CardTitle class="text-muted-foreground text-sm"
                            >Prendas entregadas (mes)</CardTitle
                        >
                        <Shirt class="text-muted-foreground size-4" />
                    </CardHeader>
                    <CardContent class="text-2xl font-semibold">
                        {{ resumen.prendas_entregadas_mes }}
                    </CardContent>
                </Card>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Entregas recientes</CardTitle
                        >
                    </CardHeader>
                    <CardContent class="space-y-2">
                        <p
                            v-if="!resumen.entregas_recientes.length"
                            class="text-muted-foreground text-sm"
                        >
                            No hay entregas registradas todavía.
                        </p>
                        <Link
                            v-for="e in resumen.entregas_recientes"
                            :key="e.id"
                            :href="`/entregas/${e.id}`"
                            class="hover:bg-accent flex items-center justify-between rounded-md border px-3 py-2 text-sm"
                        >
                            <span class="min-w-0">
                                <span class="font-medium">{{ e.folio }}</span>
                                <span
                                    class="text-muted-foreground block truncate"
                                    >{{ e.colaborador }} ·
                                    {{ e.sucursal }}</span
                                >
                            </span>
                            <span
                                class="text-muted-foreground shrink-0 text-xs"
                                >{{ e.estado_etiqueta }}</span
                            >
                        </Link>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between"
                    >
                        <CardTitle class="text-base"
                            >Existencias bajas</CardTitle
                        >
                        <AlertTriangle
                            v-if="resumen.stock_bajo"
                            class="size-4 text-amber-500"
                        />
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="!resumen.stock_bajo_detalle.length"
                            class="text-muted-foreground text-sm"
                        >
                            Sin alertas de inventario.
                        </p>
                        <table v-else class="w-full text-sm">
                            <tbody>
                                <tr
                                    v-for="(s, i) in resumen.stock_bajo_detalle"
                                    :key="i"
                                    class="border-b last:border-0"
                                >
                                    <td class="py-1.5">
                                        {{ s.prenda }}
                                        <span class="text-muted-foreground"
                                            >· {{ s.talla }}</span
                                        >
                                    </td>
                                    <td class="text-muted-foreground py-1.5">
                                        {{ s.sucursal }}
                                    </td>
                                    <td class="py-1.5 text-right font-medium">
                                        {{ s.cantidad }} / {{ s.minimo }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base"
                        >Movimientos de inventario recientes</CardTitle
                    >
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <p
                        v-if="!resumen.movimientos_recientes.length"
                        class="text-muted-foreground text-sm"
                    >
                        Sin movimientos.
                    </p>
                    <table v-else class="w-full min-w-[520px] text-sm">
                        <thead class="text-muted-foreground text-left text-xs">
                            <tr>
                                <th class="py-1.5">Tipo</th>
                                <th class="py-1.5">Prenda / Talla</th>
                                <th class="py-1.5">Sucursal</th>
                                <th class="py-1.5 text-right">Cantidad</th>
                                <th class="py-1.5 text-right">Resultante</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="m in resumen.movimientos_recientes"
                                :key="m.id"
                                class="border-t"
                            >
                                <td class="py-1.5">{{ m.tipo_etiqueta }}</td>
                                <td class="py-1.5">
                                    {{ m.prenda }}
                                    <span class="text-muted-foreground"
                                        >· {{ m.talla }}</span
                                    >
                                </td>
                                <td class="py-1.5">{{ m.sucursal }}</td>
                                <td
                                    class="py-1.5 text-right"
                                    :class="
                                        m.direccion === 'entrada'
                                            ? 'text-emerald-600'
                                            : 'text-rose-600'
                                    "
                                >
                                    {{ m.direccion === 'entrada' ? '+' : '−'
                                    }}{{ m.cantidad }}
                                </td>
                                <td class="py-1.5 text-right font-medium">
                                    {{ m.existencia_resultante }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </template>
    </div>
</template>
