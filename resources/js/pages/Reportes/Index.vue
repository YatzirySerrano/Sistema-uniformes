<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { reactive } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { Paginado } from '@/types/sistema';

const props = defineProps<{
    tab: 'entregas' | 'inventario';
    filtros: Record<string, string | number | boolean | undefined>;
    totales?: { entregas: number; activos: number; pendientes_firma: number };
    entregas?: Paginado<{
        folio: string;
        fecha_entrega: string;
        sucursal: string;
        colaborador: string;
        numero_empleado: string;
        encargado: string;
        estado_etiqueta: string;
        activos: number;
    }>;
    inventario?: Paginado<{
        sucursal: string;
        activo: string;
        talla: string;
        cantidad: number;
        minimo: number;
        bajo_minimo: boolean;
    }>;
    catalogos: {
        sucursales: { id: number; nombre: string }[];
        activos: { id: number; nombre: string }[];
        tallas: { id: number; valor: string }[];
        estados: { valor: string; etiqueta: string }[];
    };
    puedeExportar: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Reportes', href: '/reportes' }] },
});

const f = reactive({
    tab: props.tab,
    sucursal_id: props.filtros.sucursal_id ?? '',
    activo_id: props.filtros.activo_id ?? '',
    talla_id: props.filtros.talla_id ?? '',
    estado: props.filtros.estado ?? '',
    firmado: props.filtros.firmado ?? '',
    desde: props.filtros.desde ?? '',
    hasta: props.filtros.hasta ?? '',
    solo_bajo_minimo: !!props.filtros.solo_bajo_minimo,
});

function aplicar() {
    router.get(
        '/reportes',
        { ...f, solo_bajo_minimo: f.solo_bajo_minimo ? 1 : undefined },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

function cambiarTab(t: 'entregas' | 'inventario') {
    f.tab = t;
    aplicar();
}

function urlExport(formato: string) {
    const base =
        f.tab === 'inventario'
            ? '/reportes/inventario/exportar'
            : '/reportes/entregas/exportar';
    const params = new URLSearchParams({
        ...Object.fromEntries(
            Object.entries(f).filter(
                ([k, v]) => v !== '' && v !== false && k !== 'tab',
            ) as [string, string][],
        ),
        formato,
    });
    return `${base}?${params.toString()}`;
}
</script>

<template>
    <Head title="Reportes" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Reportes"
            descripcion="Consulta y exporta información de entregas e inventario de la empresa activa."
        />

        <div class="flex gap-2">
            <Button
                :variant="f.tab === 'entregas' ? 'default' : 'outline'"
                size="sm"
                @click="cambiarTab('entregas')"
                >Entregas</Button
            >
            <Button
                :variant="f.tab === 'inventario' ? 'default' : 'outline'"
                size="sm"
                @click="cambiarTab('inventario')"
                >Inventario</Button
            >
        </div>

        <Card>
            <CardContent class="flex flex-wrap items-end gap-2 pt-6">
                <select
                    v-model="f.sucursal_id"
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                >
                    <option value="">Todas las sucursales</option>
                    <option
                        v-for="s in catalogos.sucursales"
                        :key="s.id"
                        :value="s.id"
                    >
                        {{ s.nombre }}
                    </option>
                </select>
                <select
                    v-model="f.activo_id"
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                >
                    <option value="">Todos los activos</option>
                    <option
                        v-for="p in catalogos.activos"
                        :key="p.id"
                        :value="p.id"
                    >
                        {{ p.nombre }}
                    </option>
                </select>
                <template v-if="f.tab === 'entregas'">
                    <select
                        v-model="f.estado"
                        class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="">Todos los estados</option>
                        <option
                            v-for="e in catalogos.estados"
                            :key="e.valor"
                            :value="e.valor"
                        >
                            {{ e.etiqueta }}
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
                </template>
                <label v-else class="flex items-center gap-2 text-sm">
                    <input
                        v-model="f.solo_bajo_minimo"
                        type="checkbox"
                        class="size-4"
                    />
                    Solo bajo mínimo
                </label>
                <Button size="sm" @click="aplicar">Aplicar filtros</Button>
                <template v-if="puedeExportar">
                    <Button size="sm" variant="outline" as-child>
                        <a :href="urlExport('xlsx')"
                            ><Download class="size-4" /> Excel</a
                        >
                    </Button>
                    <Button
                        v-if="f.tab === 'entregas'"
                        size="sm"
                        variant="outline"
                        as-child
                    >
                        <a :href="urlExport('pdf')"
                            ><Download class="size-4" /> PDF</a
                        >
                    </Button>
                </template>
            </CardContent>
        </Card>

        <div
            v-if="f.tab === 'entregas' && totales"
            class="grid gap-3 sm:grid-cols-3"
        >
            <Card
                ><CardContent class="pt-6">
                    <p class="text-2xl font-semibold">{{ totales.entregas }}</p>
                    <p class="text-muted-foreground text-xs">Entregas</p>
                </CardContent></Card
            >
            <Card
                ><CardContent class="pt-6">
                    <p class="text-2xl font-semibold">{{ totales.activos }}</p>
                    <p class="text-muted-foreground text-xs">
                        Activos entregadas
                    </p>
                </CardContent></Card
            >
            <Card
                ><CardContent class="pt-6">
                    <p class="text-2xl font-semibold">
                        {{ totales.pendientes_firma }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        Pendientes de firma
                    </p>
                </CardContent></Card
            >
        </div>

        <div
            v-if="f.tab === 'entregas' && entregas"
            class="overflow-x-auto rounded-xl border"
        >
            <table class="w-full min-w-[760px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Folio</th>
                        <th class="px-3 py-2 font-medium">Fecha</th>
                        <th class="px-3 py-2 font-medium">Sucursal</th>
                        <th class="px-3 py-2 font-medium">Colaborador</th>
                        <th class="px-3 py-2 font-medium">Responsable</th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Activos
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(e, i) in entregas.data"
                        :key="i"
                        class="border-t"
                    >
                        <td class="px-3 py-2 font-medium">{{ e.folio }}</td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ e.fecha_entrega }}
                        </td>
                        <td class="px-3 py-2">{{ e.sucursal }}</td>
                        <td class="px-3 py-2">
                            {{ e.colaborador }}
                            <span class="text-muted-foreground"
                                >· {{ e.numero_empleado }}</span
                            >
                        </td>
                        <td class="px-3 py-2">{{ e.encargado }}</td>
                        <td class="px-3 py-2">{{ e.estado_etiqueta }}</td>
                        <td class="px-3 py-2 text-right">{{ e.activos }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="f.tab === 'inventario' && inventario"
            class="overflow-x-auto rounded-xl border"
        >
            <table class="w-full min-w-[560px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Sucursal</th>
                        <th class="px-3 py-2 font-medium">Activo</th>
                        <th class="px-3 py-2 font-medium">Talla</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Existencia
                        </th>
                        <th class="px-3 py-2 text-right font-medium">Mínimo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(s, i) in inventario.data"
                        :key="i"
                        class="border-t"
                    >
                        <td class="px-3 py-2">{{ s.sucursal }}</td>
                        <td class="px-3 py-2">{{ s.activo }}</td>
                        <td class="px-3 py-2">{{ s.talla }}</td>
                        <td
                            class="px-3 py-2 text-right font-medium"
                            :class="s.bajo_minimo ? 'text-amber-600' : ''"
                        >
                            {{ s.cantidad }}
                        </td>
                        <td class="text-muted-foreground px-3 py-2 text-right">
                            {{ s.minimo }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion
            v-if="f.tab === 'entregas' && entregas"
            :links="entregas.links"
            :total="entregas.total"
        />
        <Paginacion
            v-else-if="inventario"
            :links="inventario.links"
            :total="inventario.total"
        />
    </div>
</template>
