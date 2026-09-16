<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Boxes,
    Building2,
    Calendar,
    CheckCircle2,
    HelpCircle,
    ListOrdered,
    Package,
    PackageCheck,
    PackageX,
    Shapes,
    TriangleAlert,
    User,
    UserCheck,
    UserCog,
    Users,
    Warehouse,
    X,
} from '@lucide/vue';
import type { ApexOptions } from 'apexcharts';
import {
    computed,
    defineAsyncComponent,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import DatePicker from '@/components/sistema/DatePicker.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import TarjetaKpi from '@/components/sistema/graficas/TarjetaKpi.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    colorEstadoUnidad,
    formatoFechaCorta,
    formatoNumero,
    useGraficasDashboard,
} from '@/composables/useGraficasDashboard';
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

const VueApexCharts = defineAsyncComponent(() => import('vue3-apexcharts'));

// Mismo patrón que el Dashboard (`Panel.vue`): retrasar el montaje de
// ApexCharts un frame evita que calcule su ancho contra un contenedor que
// todavía no terminó de layoutearse (p. ej. durante la transición de
// Inertia) — causa típica de gráficas que se ven "cortadas" al cargar en
// móvil. Nunca bloquea: si `requestIdleCallback` no existe (Safari), cae a
// doble `requestAnimationFrame`.
const graficasListas = ref(false);
onMounted(() => {
    const activar = () => (graficasListas.value = true);
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(activar, { timeout: 500 });
    } else {
        requestAnimationFrame(() => requestAnimationFrame(activar));
    }
});

type GraficaEntregasVsDevoluciones = {
    granularidad: 'diaria' | 'mensual';
    periodos: string[];
    entregadas: number[];
    devueltas: number[];
};

const props = defineProps<{
    tab: 'entregas' | 'inventario';
    filtros: Record<string, string | number | boolean | undefined>;
    kpis: Record<string, number>;
    graficas: {
        entregas_vs_devoluciones?: GraficaEntregasVsDevoluciones | null;
        top_activos?: {
            activo: string;
            talla: string | null;
            piezas: number;
        }[];
        por_sucursal?: { sucursal: string; piezas: number }[];
        unidades_por_estado?: {
            estado: string;
            etiqueta: string;
            total: number;
        }[];
        por_almacen?: { almacen: string; piezas: number }[];
        riesgo_desabasto?: {
            activo: string;
            talla: string | null;
            disponible: number;
            minimo: number;
            faltante: number;
        }[];
    };
    entregas?: Paginado<{
        folio: string;
        fecha_entrega: string;
        empresa: string | null;
        sucursal: string;
        colaborador: string;
        numero_empleado: string;
        encargado: string;
        piezas: number;
    }>;
    inventario?: Paginado<{
        empresa: string | null;
        almacen: string | null;
        activo: string;
        talla: string | null;
        disponible: number;
        minimo: number;
        estado_stock: 'sin_existencias' | 'bajo_minimo' | 'correcto';
        estado_stock_etiqueta: string;
    }>;
    catalogos: {
        empresas: EmpresaAutorizada[];
    };
    puedeExportar: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Reportes', href: '/reportes' }] },
});

const f = reactive({
    tab: props.tab,
    empresa_id: props.filtros.empresa_id ?? '',
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

const hayFiltrosActivos = computed(
    () =>
        f.empresa_id !== '' ||
        (f.tab === 'entregas' && (f.desde !== '' || f.hasta !== '')) ||
        (f.tab === 'inventario' && f.solo_bajo_minimo),
);

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch(f, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get(
            '/reportes',
            { ...f, solo_bajo_minimo: f.solo_bajo_minimo ? 1 : undefined },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }, 300);
});

function cambiarTab(t: 'entregas' | 'inventario') {
    f.tab = t;
}

function limpiarFiltros(): void {
    empresaSel.value = null;
    f.empresa_id = '';
    f.desde = '';
    f.hasta = '';
    f.solo_bajo_minimo = false;
}

// Mismos filtros que ve la pantalla (sin `tab`, que no es un filtro de la
// consulta): `BotonesExportar` arma `?<filtros>&formato=xlsx|pdf` contra el
// endpoint del tab activo.
const endpointExportar = computed(() =>
    f.tab === 'inventario'
        ? '/reportes/inventario/exportar'
        : '/reportes/entregas/exportar',
);
const filtrosExportar = computed(() => {
    const { tab: _tab, solo_bajo_minimo, ...resto } = f;
    // El backend interpreta CUALQUIER string no vacío (incluido "false") como
    // verdadero (`$filtros['solo_bajo_minimo'] ?? false`), así que igual que
    // en el `watch` de abajo, el filtro se omite por completo cuando está
    // desmarcado — nunca se manda `solo_bajo_minimo=false`.
    return { ...resto, solo_bajo_minimo: solo_bajo_minimo ? 1 : undefined };
});

const vista = useVistaPreferida('reportes', 'tabla');

const CLASE_ESTADO_STOCK: Record<string, string> = {
    sin_existencias: 'text-rose-600 border-rose-200 dark:border-rose-900',
    bajo_minimo: 'text-amber-600 border-amber-200 dark:border-amber-900',
    correcto: 'text-emerald-600 border-emerald-200 dark:border-emerald-900',
};

// Un icono + una descripción breve por KPI — las claves calzan exactamente
// con las etiquetas que arma `ReporteController::kpisEntregas()`/
// `kpisInventario()` (misma fuente que Excel/PDF, así que renombrar un KPI
// ahí es el único lugar que hay que tocar). `HelpCircle`/sin descripción es
// el respaldo si el backend agrega un KPI nuevo sin actualizar este mapa.
const ICONO_KPI: Record<string, unknown> = {
    'Entregas realizadas': PackageCheck,
    'Líneas de detalle entregadas': ListOrdered,
    'Piezas entregadas': Package,
    'Colaboradores únicos con entrega': Users,
    'Tipos de activos distintos entregados': Shapes,
    'Piezas disponibles': Boxes,
    'Variantes/tallas bajo mínimo': TriangleAlert,
    'Variantes/tallas sin existencias': PackageX,
    'Unidades disponibles': CheckCircle2,
    'Unidades asignadas': UserCheck,
};

const DESCRIPCION_KPI: Record<string, string> = {
    'Entregas realizadas':
        'Cantidad total de entregas registradas en el periodo filtrado',
    'Líneas de detalle entregadas':
        'Suma de los renglones/detalles dentro de todas las entregas',
    'Piezas entregadas': 'Suma total de piezas entregadas',
    'Colaboradores únicos con entrega':
        'Colaboradores distintos que recibieron al menos una entrega',
    'Tipos de activos distintos entregados':
        'Cantidad de activos diferentes entregados en el periodo',
    'Piezas disponibles':
        'Suma de existencias disponibles en el alcance filtrado',
    'Variantes/tallas bajo mínimo':
        'Combinaciones de activo y variante cuya existencia ya alcanzó su mínimo configurado',
    'Variantes/tallas sin existencias':
        'Combinaciones de activo y variante sin ninguna pieza disponible',
    'Unidades disponibles':
        'Unidades de seguimiento individual listas para entregar',
    'Unidades asignadas':
        'Unidades de seguimiento individual entregadas a un colaborador',
};

const { colores, temaApex, opcionesBarrasHorizontales, opcionesDonut } =
    useGraficasDashboard();

function etiquetaPeriodo(
    periodo: string,
    granularidad: 'diaria' | 'mensual',
): string {
    if (granularidad === 'mensual') {
        const [anio, mes] = periodo.split('-').map(Number);
        return new Date(anio, (mes || 1) - 1, 1).toLocaleDateString('es-MX', {
            month: 'short',
            year: 'numeric',
        });
    }

    return formatoFechaCorta(periodo);
}

const comparativa = computed(() => props.graficas.entregas_vs_devoluciones);

const opcionesComparativa = computed<ApexOptions>(() => ({
    chart: {
        type: 'bar',
        fontFamily: 'inherit',
        foreColor: colores.value.mutedForeground,
        background: 'transparent',
        toolbar: { show: false },
    },
    colors: [colores.value.primary, colores.value.chart2],
    plotOptions: {
        bar: { horizontal: false, borderRadius: 3, columnWidth: '55%' },
    },
    dataLabels: { enabled: false },
    legend: {
        position: 'top',
        horizontalAlign: 'right',
        labels: { colors: colores.value.mutedForeground },
    },
    grid: { borderColor: colores.value.border, strokeDashArray: 3 },
    tooltip: { theme: temaApex.value, y: { formatter: formatoNumero } },
    xaxis: {
        categories: (comparativa.value?.periodos ?? []).map((p) =>
            etiquetaPeriodo(p, comparativa.value!.granularidad),
        ),
        labels: { style: { colors: colores.value.mutedForeground } },
        axisBorder: { color: colores.value.border },
        axisTicks: { color: colores.value.border },
    },
    yaxis: {
        min: 0,
        forceNiceScale: true,
        labels: {
            formatter: (v: number) => formatoNumero(Math.round(v)),
            style: { colors: colores.value.mutedForeground },
        },
    },
    responsive: [
        {
            breakpoint: 640,
            options: {
                chart: { height: 240 },
                legend: {
                    horizontalAlign: 'center',
                    fontSize: '11px',
                    itemMargin: { horizontal: 6, vertical: 4 },
                },
            },
        },
        {
            breakpoint: 420,
            options: {
                chart: { height: 220 },
                legend: { fontSize: '10px' },
                yaxis: { labels: { style: { fontSize: '10px' } } },
            },
        },
    ],
}));
const seriesComparativa = computed(() =>
    comparativa.value
        ? [
              { name: 'Piezas entregadas', data: comparativa.value.entregadas },
              { name: 'Piezas devueltas', data: comparativa.value.devueltas },
          ]
        : [],
);

// El nombre del activo con la variante entre paréntesis cuando aplica (p.
// ej. "Calzado de Seguridad (32)"), para no perder ese detalle en el top.
function etiquetaTopActivo(a: {
    activo: string;
    talla: string | null;
}): string {
    return a.talla ? `${a.activo} (${a.talla})` : a.activo;
}
const opcionesTopActivos = computed<ApexOptions>(() =>
    opcionesBarrasHorizontales(
        (props.graficas.top_activos ?? []).map(etiquetaTopActivo),
        { formatoValor: formatoNumero },
    ),
);
const seriesTopActivos = computed(() => [
    {
        name: 'Piezas',
        data: (props.graficas.top_activos ?? []).map((a) => a.piezas),
    },
]);

const opcionesPorSucursal = computed<ApexOptions>(() =>
    opcionesBarrasHorizontales(
        (props.graficas.por_sucursal ?? []).map((s) => s.sucursal),
        { formatoValor: formatoNumero },
    ),
);
const seriesPorSucursal = computed(() => [
    {
        name: 'Piezas',
        data: (props.graficas.por_sucursal ?? []).map((s) => s.piezas),
    },
]);

const totalUnidades = computed(() =>
    (props.graficas.unidades_por_estado ?? []).reduce((a, e) => a + e.total, 0),
);
const opcionesUnidades = computed<ApexOptions>(() =>
    opcionesDonut(
        (props.graficas.unidades_por_estado ?? []).map((e) => e.etiqueta),
        {
            formatoValor: formatoNumero,
            colores: (props.graficas.unidades_por_estado ?? []).map((e) =>
                colorEstadoUnidad(e.estado),
            ),
            totalEtiqueta: 'Unidades',
        },
    ),
);
const seriesUnidades = computed(() =>
    (props.graficas.unidades_por_estado ?? []).map((e) => e.total),
);

const opcionesPorAlmacen = computed<ApexOptions>(() =>
    opcionesBarrasHorizontales(
        (props.graficas.por_almacen ?? []).map((a) => a.almacen),
        { formatoValor: formatoNumero },
    ),
);
const seriesPorAlmacen = computed(() => [
    {
        name: 'Disponible',
        data: (props.graficas.por_almacen ?? []).map((a) => a.piezas),
    },
]);

function etiquetaDesabasto(r: {
    activo: string;
    talla: string | null;
}): string {
    return r.talla ? `${r.activo} (${r.talla})` : r.activo;
}
const opcionesRiesgoDesabasto = computed<ApexOptions>(() =>
    opcionesBarrasHorizontales(
        (props.graficas.riesgo_desabasto ?? []).map(etiquetaDesabasto),
        { formatoValor: formatoNumero, colores: ['#f59e0b'] },
    ),
);
const seriesRiesgoDesabasto = computed(() => [
    {
        name: 'Faltante',
        data: (props.graficas.riesgo_desabasto ?? []).map((r) => r.faltante),
    },
]);
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
                <Button
                    v-if="hayFiltrosActivos"
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="limpiarFiltros"
                >
                    <X class="size-3.5" /> Limpiar filtros
                </Button>
                <BotonesExportar
                    v-if="puedeExportar"
                    :endpoint="endpointExportar"
                    :filtros="filtrosExportar"
                />
                <SelectorVista v-model="vista" class="ml-auto" />
            </CardContent>
        </Card>

        <div class="grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <TarjetaKpi
                v-for="(valor, etiqueta) in kpis"
                :key="etiqueta"
                :titulo="String(etiqueta)"
                :valor="valor"
                :icono="ICONO_KPI[etiqueta] ?? HelpCircle"
                :descripcion="DESCRIPCION_KPI[etiqueta]"
            />
        </div>

        <div
            v-if="f.tab === 'entregas'"
            class="grid min-w-0 gap-4 lg:grid-cols-2"
        >
            <Card v-if="comparativa" class="min-w-0 lg:col-span-2">
                <CardHeader>
                    <CardTitle class="text-base"
                        >Entregas vs devoluciones (piezas)</CardTitle
                    >
                    <CardDescription
                        >Piezas entregadas comparadas contra piezas devueltas,
                        agrupadas por
                        {{
                            comparativa.granularidad === 'mensual'
                                ? 'mes'
                                : 'día'
                        }}</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <VueApexCharts
                        v-if="graficasListas"
                        type="bar"
                        height="280"
                        :options="opcionesComparativa"
                        :series="seriesComparativa"
                    />
                    <div
                        v-else
                        class="bg-muted/50 h-[280px] rounded-lg motion-safe:animate-pulse"
                    />
                </CardContent>
            </Card>

            <Card
                v-if="(graficas.top_activos?.length ?? 0) > 0"
                class="min-w-0"
            >
                <CardHeader>
                    <CardTitle class="text-base"
                        >Top activos entregados</CardTitle
                    >
                    <CardDescription
                        >Por cantidad de piezas, no por número de líneas de
                        detalle — con variante/talla cuando
                        aplica</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <VueApexCharts
                        v-if="graficasListas"
                        type="bar"
                        height="280"
                        :options="opcionesTopActivos"
                        :series="seriesTopActivos"
                    />
                    <div
                        v-else
                        class="bg-muted/50 h-[280px] rounded-lg motion-safe:animate-pulse"
                    />
                </CardContent>
            </Card>

            <Card
                v-if="(graficas.por_sucursal?.length ?? 0) > 0"
                class="min-w-0"
            >
                <CardHeader>
                    <CardTitle class="text-base"
                        >Piezas entregadas por sucursal</CardTitle
                    >
                    <CardDescription
                        >Sólo sucursales con entregas reales en el periodo y
                        empresa filtrados</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <VueApexCharts
                        v-if="graficasListas"
                        type="bar"
                        height="280"
                        :options="opcionesPorSucursal"
                        :series="seriesPorSucursal"
                    />
                    <div
                        v-else
                        class="bg-muted/50 h-[280px] rounded-lg motion-safe:animate-pulse"
                    />
                </CardContent>
            </Card>
        </div>

        <div v-else class="grid min-w-0 gap-4 lg:grid-cols-2">
            <Card v-if="totalUnidades > 0" class="min-w-0">
                <CardHeader>
                    <CardTitle class="text-base">Unidades por estado</CardTitle>
                    <CardDescription
                        >Unidades de seguimiento individual — nunca mezcladas
                        con las piezas por cantidad</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <VueApexCharts
                        v-if="graficasListas"
                        type="donut"
                        height="280"
                        :options="opcionesUnidades"
                        :series="seriesUnidades"
                    />
                    <div
                        v-else
                        class="bg-muted/50 h-[280px] rounded-lg motion-safe:animate-pulse"
                    />
                </CardContent>
            </Card>

            <Card
                v-if="(graficas.por_almacen?.length ?? 0) > 0"
                class="min-w-0"
            >
                <CardHeader>
                    <CardTitle class="text-base"
                        >Disponible por almacén</CardTitle
                    >
                    <CardDescription
                        >Piezas disponibles por almacén en el alcance
                        filtrado</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <VueApexCharts
                        v-if="graficasListas"
                        type="bar"
                        height="280"
                        :options="opcionesPorAlmacen"
                        :series="seriesPorAlmacen"
                    />
                    <div
                        v-else
                        class="bg-muted/50 h-[280px] rounded-lg motion-safe:animate-pulse"
                    />
                </CardContent>
            </Card>

            <Card
                v-if="(graficas.riesgo_desabasto?.length ?? 0) > 0"
                class="min-w-0 lg:col-span-2"
            >
                <CardHeader>
                    <CardTitle class="text-base">Riesgo de desabasto</CardTitle>
                    <CardDescription
                        >Top por mayor faltante (mínimo − disponible) entre lo
                        sin existencias y lo bajo mínimo</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <VueApexCharts
                        v-if="graficasListas"
                        type="bar"
                        height="280"
                        :options="opcionesRiesgoDesabasto"
                        :series="seriesRiesgoDesabasto"
                    />
                    <div
                        v-else
                        class="bg-muted/50 h-[280px] rounded-lg motion-safe:animate-pulse"
                    />
                </CardContent>
            </Card>
        </div>

        <EstadoVacio
            v-if="
                f.tab === 'entregas' &&
                !comparativa &&
                (graficas.top_activos?.length ?? 0) === 0
            "
            titulo="Sin gráficas disponibles"
            descripcion="No hay entregas ni devoluciones que graficar con los filtros actuales."
        />

        <div
            v-if="f.tab === 'entregas' && entregas && vista === 'cards'"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
        >
            <div
                v-for="(e, i) in entregas.data"
                :key="i"
                class="flex min-w-0 flex-col gap-2 rounded-xl border p-4 text-sm"
            >
                <div class="flex items-start justify-between gap-2">
                    <p class="min-w-0 truncate font-medium">{{ e.folio }}</p>
                    <Badge variant="outline" class="shrink-0 text-xs">
                        {{ e.piezas }} pza(s)
                    </Badge>
                </div>
                <p class="flex min-w-0 items-center gap-1.5 truncate">
                    <User class="text-muted-foreground size-3.5 shrink-0" />
                    {{ e.colaborador }}
                    <span class="text-muted-foreground"
                        >· {{ e.numero_empleado }}</span
                    >
                </p>
                <div class="text-muted-foreground grid gap-1 text-xs">
                    <span class="flex min-w-0 items-center gap-1.5 truncate">
                        <Building2 class="size-3.5 shrink-0" />
                        {{ e.empresa ?? '—' }}
                        <span v-if="e.sucursal">· {{ e.sucursal }}</span>
                    </span>
                    <span class="flex min-w-0 items-center gap-1.5 truncate">
                        <UserCog class="size-3.5 shrink-0" />
                        {{ e.encargado }}
                    </span>
                </div>
                <div
                    class="text-muted-foreground mt-auto flex items-center justify-between gap-2 pt-1 text-xs"
                >
                    <span class="flex items-center gap-1">
                        <Package class="size-3.5" />
                        {{ e.piezas }} pieza(s)
                    </span>
                    <span class="flex items-center gap-1">
                        <Calendar class="size-3.5" />
                        {{ e.fecha_entrega }}
                    </span>
                </div>
            </div>
        </div>

        <div
            v-if="f.tab === 'entregas' && entregas && vista === 'tabla'"
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
                        <th class="px-3 py-2 text-right font-medium">Piezas</th>
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
                        <td class="px-3 py-2 text-right">{{ e.piezas }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="f.tab === 'inventario' && inventario && vista === 'cards'"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
        >
            <div
                v-for="(s, i) in inventario.data"
                :key="i"
                class="flex min-w-0 flex-col gap-2 rounded-xl border p-4 text-sm"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ s.activo }}</p>
                        <p class="text-muted-foreground truncate text-xs">
                            {{ s.talla ?? 'Sin talla' }}
                        </p>
                    </div>
                    <Badge
                        variant="outline"
                        class="shrink-0 text-xs"
                        :class="CLASE_ESTADO_STOCK[s.estado_stock]"
                    >
                        {{ s.estado_stock_etiqueta }}
                    </Badge>
                </div>
                <div class="text-muted-foreground grid gap-1 text-xs">
                    <span class="flex min-w-0 items-center gap-1.5 truncate">
                        <Building2 class="size-3.5 shrink-0" />
                        {{ s.empresa ?? '—' }}
                    </span>
                    <span class="flex min-w-0 items-center gap-1.5 truncate">
                        <Warehouse class="size-3.5 shrink-0" />
                        {{ s.almacen ?? '—' }}
                    </span>
                </div>
                <div
                    class="bg-muted/40 grid grid-cols-2 divide-x rounded-lg text-center"
                >
                    <div class="px-2 py-1.5">
                        <p class="text-muted-foreground text-[11px]">
                            Disponible
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ s.disponible }}
                        </p>
                    </div>
                    <div class="px-2 py-1.5">
                        <p class="text-muted-foreground text-[11px]">Mínimo</p>
                        <p class="font-semibold tabular-nums">
                            {{ s.minimo }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="f.tab === 'inventario' && inventario && vista === 'tabla'"
            class="overflow-x-auto rounded-xl border"
        >
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Empresa</th>
                        <th class="px-3 py-2 font-medium">Almacén</th>
                        <th class="px-3 py-2 font-medium">Activo</th>
                        <th class="px-3 py-2 font-medium">Talla</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Disponible
                        </th>
                        <th class="px-3 py-2 text-right font-medium">Mínimo</th>
                        <th class="px-3 py-2 font-medium">Estado de stock</th>
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
                        <td class="px-3 py-2">{{ s.talla ?? 'Sin talla' }}</td>
                        <td class="px-3 py-2 text-right font-medium">
                            {{ s.disponible }}
                        </td>
                        <td class="text-muted-foreground px-3 py-2 text-right">
                            {{ s.minimo }}
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                variant="outline"
                                class="text-xs"
                                :class="CLASE_ESTADO_STOCK[s.estado_stock]"
                            >
                                {{ s.estado_stock_etiqueta }}
                            </Badge>
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
