<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { reactive, ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import DatePicker from '@/components/sistema/DatePicker.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

const props = defineProps<{
    tab: 'entregas' | 'inventario';
    filtros: Record<string, string | number | boolean | undefined>;
    totales?: { entregas: number; activos: number; pendientes_firma: number };
    entregas?: Paginado<{
        folio: string;
        fecha_entrega: string;
        empresa: string | null;
        sucursal: string;
        colaborador: string;
        numero_empleado: string;
        encargado: string;
        estado_etiqueta: string;
        activos: number;
    }>;
    inventario?: Paginado<{
        empresa: string | null;
        almacen: string | null;
        activo: string;
        talla: string;
        cantidad: number;
        minimo: number;
        bajo_minimo: boolean;
    }>;
    catalogos: {
        empresas: EmpresaAutorizada[];
        estados: { valor: string; etiqueta: string }[];
    };
    puedeExportar: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Reportes', href: '/reportes' }] },
});

const f = reactive({
    tab: props.tab,
    empresa_id: props.filtros.empresa_id ?? '',
    estado: String(props.filtros.estado ?? ''),
    firmado: props.filtros.firmado ?? '',
    desde: String(props.filtros.desde ?? ''),
    hasta: String(props.filtros.hasta ?? ''),
    solo_bajo_minimo: !!props.filtros.solo_bajo_minimo,
});

const empresaSel = ref<EmpresaAutorizada | null>(
    props.catalogos.empresas.find((e) => e.id === Number(f.empresa_id)) ?? null,
);
async function buscarEmpresas(q: string): Promise<EmpresaAutorizada[]> {
    const t = q.trim().toLowerCase();

    return props.catalogos.empresas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}
watch(empresaSel, (e) => {
    f.empresa_id = e?.id ?? '';
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
            descripcion="Consulta y exporta información de entregas e inventario. Filtra por empresa cuando lo necesites."
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
                <div v-if="catalogos.empresas.length > 1" class="w-56">
                    <BuscadorAsync
                        v-model="empresaSel"
                        :buscar="buscarEmpresas"
                        :etiqueta="(e) => String(e.nombre_comercial)"
                        placeholder="Todas las empresas"
                        placeholder-busqueda="Buscar empresa…"
                    />
                </div>
                <template v-if="f.tab === 'entregas'">
                    <div class="w-52">
                        <SelectSimple
                            v-model="f.estado"
                            :opciones="[
                                { valor: '', etiqueta: 'Todos los estados' },
                                ...catalogos.estados.map((e) => ({
                                    valor: e.valor,
                                    etiqueta: e.etiqueta,
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
                        <th class="px-3 py-2 font-medium">Empresa</th>
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
                        <td class="px-3 py-2">{{ e.empresa }}</td>
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
                        <th class="px-3 py-2 font-medium">Empresa</th>
                        <th class="px-3 py-2 font-medium">Almacén</th>
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
                        <td class="px-3 py-2">{{ s.empresa }}</td>
                        <td class="px-3 py-2">{{ s.almacen }}</td>
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
