<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { getLocalTimeZone, today } from '@internationalized/date';
import type { ApexFormatterOpts, ApexOptions } from 'apexcharts';
import {
    AlertTriangle,
    Boxes,
    CheckCircle2,
    ClipboardList,
    RotateCcw,
    Users,
    Warehouse,
    Wrench,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import DatePicker from '@/components/sistema/DatePicker.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
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
    formatoNumeroCorto,
    puntosSerieTemporal,
    useGraficasDashboard,
} from '@/composables/useGraficasDashboard';
import type { EmpresaAutorizada } from '@/types/sistema';

type Opcion = { id: number; nombre: string };

type Resumen = {
    kpis: {
        colaboradores_activos: number;
        activos_activos: number;
        existencias_disponibles: number;
        entregas_periodo: number;
        devoluciones_periodo: number;
        activos_stock_bajo: number;
        almacenes_activos: number;
        unidades_disponibles: number;
        unidades_asignadas: number;
        unidades_en_reparacion: number;
        unidades_perdidas: number;
        unidades_robadas: number;
    };
    series: {
        entregas_por_periodo: { fecha: string; total: number }[];
        devoluciones_por_periodo: { fecha: string; total: number }[];
        movimientos_por_periodo: {
            fecha: string;
            entradas: number;
            salidas: number;
        }[];
        unidades_por_estado: {
            estado: string;
            etiqueta: string;
            total: number;
        }[];
        existencias_por_almacen: { almacen: string; total: number }[];
        stock_por_categoria: { categoria: string; total: number }[];
    };
    entregas_recientes: {
        id: number;
        folio: string;
        colaborador: string;
        sucursal: string;
        empresa: string | null;
        estado: string;
        estado_etiqueta: string;
        fecha_entrega: string;
    }[];
    stock_bajo_detalle: {
        activo: string;
        talla: string | null;
        almacen: string;
        empresa: string | null;
        cantidad: number;
        minimo: number;
    }[];
};

const props = defineProps<{
    resumen: Resumen;
    filtros: {
        empresa_id: number | null;
        sucursal_id: number | null;
        almacen_id: number | null;
        desde: string;
        hasta: string;
    };
    sucursalSeleccionada: Opcion | null;
    almacenSeleccionado: Opcion | null;
    empresasAutorizadas: EmpresaAutorizada[];
    totalEmpresasIncluidas: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }],
    },
});

const {
    colores,
    opcionesArea,
    opcionesBarrasHorizontales,
    opcionesDonut,
    colorEstadoUnidad,
    formatoNumero,
    formatoFechaCorta,
} = useGraficasDashboard();

const empresaSeleccionada = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === props.filtros.empresa_id) ??
        null,
);
const empresaId = computed(() => empresaSeleccionada.value?.id ?? '');
const sucursalSeleccionada = ref<Opcion | null>(props.sucursalSeleccionada);
const almacenSeleccionado = ref<Opcion | null>(props.almacenSeleccionado);
const desde = ref(props.filtros.desde);
const hasta = ref(props.filtros.hasta);
const cargando = ref(false);

const hayFiltrosActivos = computed(
    () =>
        empresaId.value !== '' ||
        sucursalSeleccionada.value !== null ||
        almacenSeleccionado.value !== null,
);

const rangoEtiqueta = computed(
    () =>
        `${formatoFechaCorta(props.filtros.desde)} – ${formatoFechaCorta(props.filtros.hasta)}`,
);

const subtitulo = computed(() => {
    if (!empresaSeleccionada.value) {
        return props.totalEmpresasIncluidas > 1
            ? `Resumen operativo de todas las empresas autorizadas (${props.totalEmpresasIncluidas}).`
            : 'Resumen operativo de todas las empresas autorizadas.';
    }

    const partes = [empresaSeleccionada.value.nombre_comercial];
    if (sucursalSeleccionada.value) {
        partes.push(sucursalSeleccionada.value.nombre);
    }

    return `Resumen operativo de ${partes.join(' · ')}.`;
});

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();
    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

async function buscarSucursales(termino: string, signal?: AbortSignal) {
    const params = new URLSearchParams({ q: termino });
    if (empresaId.value) {
        params.set('empresa_id', String(empresaId.value));
    }
    const res = await fetch(`/sucursales/buscar?${params}`, { signal });
    const datos = await res.json();
    return datos.sucursales as Opcion[];
}

async function buscarAlmacenes(termino: string, signal?: AbortSignal) {
    const params = new URLSearchParams({ q: termino });
    if (empresaId.value) {
        params.set('empresa_id', String(empresaId.value));
    }
    const res = await fetch(`/almacenes/buscar?${params}`, { signal });
    const datos = await res.json();
    return datos.almacenes as Opcion[];
}

// Cambiar de empresa invalida sucursal/almacén elegidos (pertenecen al
// universo de la empresa anterior): se limpian en vez de conservarlos
// incompatibles.
watch(empresaId, () => {
    sucursalSeleccionada.value = null;
    almacenSeleccionado.value = null;
});

let t: ReturnType<typeof setTimeout>;
watch(
    [empresaId, sucursalSeleccionada, almacenSeleccionado, desde, hasta],
    () => {
        clearTimeout(t);
        t = setTimeout(() => {
            router.get(
                '/dashboard',
                {
                    empresa_id: empresaId.value || undefined,
                    sucursal_id: sucursalSeleccionada.value?.id ?? undefined,
                    almacen_id: almacenSeleccionado.value?.id ?? undefined,
                    desde: desde.value || undefined,
                    hasta: hasta.value || undefined,
                },
                {
                    preserveState: true,
                    replace: true,
                    preserveScroll: true,
                    onStart: () => (cargando.value = true),
                    onFinish: () => (cargando.value = false),
                },
            );
        }, 300);
    },
);

function limpiarFiltros() {
    empresaSeleccionada.value = null;
    sucursalSeleccionada.value = null;
    almacenSeleccionado.value = null;
    // `today(getLocalTimeZone())` (misma librería que el DatePicker): nunca
    // `new Date().toISOString()`, que convierte a UTC y puede adelantar un
    // día en husos horarios negativos como México.
    const hoy = today(getLocalTimeZone());
    desde.value = hoy.subtract({ days: 29 }).toString();
    hasta.value = hoy.toString();
}

const sumaEntregas = computed(() =>
    props.resumen.series.entregas_por_periodo.reduce((a, p) => a + p.total, 0),
);
const sumaDevoluciones = computed(() =>
    props.resumen.series.devoluciones_por_periodo.reduce(
        (a, p) => a + p.total,
        0,
    ),
);
const sumaMovimientos = computed(() =>
    props.resumen.series.movimientos_por_periodo.reduce(
        (a, p) => a + p.entradas + p.salidas,
        0,
    ),
);
const sumaUnidades = computed(() =>
    props.resumen.series.unidades_por_estado.reduce((a, u) => a + u.total, 0),
);

const maximoEntregasDevoluciones = computed(() =>
    Math.max(
        1,
        ...props.resumen.series.entregas_por_periodo.map((p) => p.total),
        ...props.resumen.series.devoluciones_por_periodo.map((p) => p.total),
    ),
);
const opcionesEntregasDevoluciones = computed(() =>
    opcionesArea({
        colores: [colores.value.primary, colores.value.chart2],
        maximoY: maximoEntregasDevoluciones.value,
    }),
);
const seriesEntregasDevoluciones = computed(() => {
    const fechas = props.resumen.series.entregas_por_periodo.map(
        (p) => p.fecha,
    );

    return [
        {
            name: 'Entregas',
            data: puntosSerieTemporal(
                fechas,
                props.resumen.series.entregas_por_periodo.map((p) => p.total),
            ),
        },
        {
            name: 'Devoluciones',
            data: puntosSerieTemporal(
                fechas,
                props.resumen.series.devoluciones_por_periodo.map(
                    (p) => p.total,
                ),
            ),
        },
    ];
});

/**
 * El "Balance" se agrega al texto del tooltip de Salidas leyendo el par
 * Entradas/Salidas del mismo día directamente de `opts.series` (todas las
 * series ya renderizadas) — sólo para mostrar, no requiere nada del backend.
 */
const tooltipYMovimientos: NonNullable<ApexOptions['tooltip']>['y'] = [
    { formatter: (val: number) => formatoNumero(val) },
    {
        formatter: (val: number, opts?: ApexFormatterOpts) => {
            const entradas = Number(
                opts?.series?.[0]?.[opts.dataPointIndex] ?? 0,
            );
            const salidas = Number(
                opts?.series?.[1]?.[opts.dataPointIndex] ?? 0,
            );
            const balance = entradas - salidas;
            const signo = balance > 0 ? '+' : '';
            return `${formatoNumero(val)}   ·   Balance: ${signo}${formatoNumero(balance)}`;
        },
    },
];

const opcionesMovimientos = computed(() =>
    opcionesArea({
        colores: [colores.value.chart2, colores.value.destructive],
        formatoValor: formatoNumero,
        formatoEjeY: formatoNumeroCorto,
        tooltipY: tooltipYMovimientos,
    }),
);
const seriesMovimientos = computed(() => {
    const fechas = props.resumen.series.movimientos_por_periodo.map(
        (p) => p.fecha,
    );

    return [
        {
            name: 'Entradas',
            data: puntosSerieTemporal(
                fechas,
                props.resumen.series.movimientos_por_periodo.map(
                    (p) => p.entradas,
                ),
            ),
        },
        {
            name: 'Salidas',
            data: puntosSerieTemporal(
                fechas,
                props.resumen.series.movimientos_por_periodo.map(
                    (p) => p.salidas,
                ),
            ),
        },
    ];
});

const opcionesUnidades = computed(() =>
    opcionesDonut(
        props.resumen.series.unidades_por_estado.map((u) => u.etiqueta),
        {
            colores: props.resumen.series.unidades_por_estado.map((u) =>
                colorEstadoUnidad(u.estado),
            ),
            totalEtiqueta: 'Unidades',
        },
    ),
);
const seriesUnidades = computed(() =>
    props.resumen.series.unidades_por_estado.map((u) => u.total),
);

const opcionesAlmacen = computed(() =>
    opcionesBarrasHorizontales(
        props.resumen.series.existencias_por_almacen.map((a) => a.almacen),
    ),
);
const seriesAlmacen = computed(() => [
    {
        name: 'Existencias',
        data: props.resumen.series.existencias_por_almacen.map((a) => a.total),
    },
]);

function varianteEstadoEntrega(
    estado: string,
): 'warning' | 'success' | 'destructive' | 'outline' {
    switch (estado) {
        case 'pendiente_firma':
            return 'warning';
        case 'firmada':
            return 'success';
        case 'anulada':
            return 'destructive';
        default:
            return 'outline';
    }
}

type SeveridadStock = {
    variante: 'destructive' | 'warning';
    etiqueta: string;
    colorBarra: string;
    porcentaje: number;
};

function severidadStock(cantidad: number, minimo: number): SeveridadStock {
    const base = Math.max(1, minimo);
    const ratio = cantidad / base;
    const porcentaje = Math.min(100, Math.max(0, ratio * 100));

    if (cantidad <= 0) {
        return {
            variante: 'destructive',
            etiqueta: 'Sin existencias',
            colorBarra: 'bg-destructive',
            porcentaje,
        };
    }
    if (ratio <= 0.34) {
        return {
            variante: 'destructive',
            etiqueta: 'Stock crítico',
            colorBarra: 'bg-destructive',
            porcentaje,
        };
    }
    if (ratio <= 0.7) {
        return {
            variante: 'warning',
            etiqueta: 'Stock bajo',
            colorBarra: 'bg-warning',
            porcentaje,
        };
    }
    return {
        variante: 'warning',
        etiqueta: 'Cerca del mínimo',
        colorBarra: 'bg-warning/60',
        porcentaje,
    };
}

const stockBajoConSeveridad = computed(() =>
    props.resumen.stock_bajo_detalle.map((s) => ({
        ...s,
        severidad: severidadStock(s.cantidad, s.minimo),
    })),
);

const categoriaEsDonut = computed(
    () =>
        props.resumen.series.stock_por_categoria.length > 0 &&
        props.resumen.series.stock_por_categoria.length <= 5,
);
const opcionesCategoria = computed(() =>
    categoriaEsDonut.value
        ? opcionesDonut(
              props.resumen.series.stock_por_categoria.map((c) => c.categoria),
              { totalEtiqueta: 'Existencias' },
          )
        : opcionesBarrasHorizontales(
              props.resumen.series.stock_por_categoria.map((c) => c.categoria),
          ),
);
const seriesCategoria = computed(() =>
    categoriaEsDonut.value
        ? props.resumen.series.stock_por_categoria.map((c) => c.total)
        : [
              {
                  name: 'Existencias',
                  data: props.resumen.series.stock_por_categoria.map(
                      (c) => c.total,
                  ),
              },
          ],
);
</script>

<template>
    <Head title="Dashboard" />

    <div
        class="flex h-full flex-1 flex-col gap-4 p-4 transition-opacity duration-150"
        :class="cargando && 'opacity-60'"
    >
        <div
            class="flex flex-wrap items-center gap-2"
            data-tour="titulo-dashboard"
        >
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Dashboard</h1>
                <p class="text-muted-foreground text-sm">{{ subtitulo }}</p>
            </div>
        </div>

        <Card data-tour="filtros-dashboard">
            <CardContent class="pt-6">
                <div
                    class="grid grid-cols-1 items-end gap-3 md:grid-cols-2 xl:grid-cols-5"
                >
                    <label
                        v-if="empresasAutorizadas.length > 1"
                        class="flex min-w-0 flex-col gap-1 text-sm"
                    >
                        <span class="text-muted-foreground text-xs"
                            >Empresa</span
                        >
                        <BuscadorAsync
                            v-model="empresaSeleccionada"
                            :buscar="buscarEmpresas"
                            :etiqueta="(e) => String(e.nombre_comercial)"
                            placeholder="Todas las empresas"
                            placeholder-busqueda="Buscar empresa…"
                            class="w-full"
                        />
                    </label>
                    <label class="flex min-w-0 flex-col gap-1 text-sm">
                        <span class="text-muted-foreground text-xs"
                            >Sucursal</span
                        >
                        <BuscadorAsync
                            v-model="sucursalSeleccionada"
                            :buscar="buscarSucursales"
                            :etiqueta="(s) => String(s.nombre)"
                            :dependencia="empresaId"
                            placeholder="Todas las sucursales"
                            placeholder-busqueda="Buscar sucursal…"
                            class="w-full"
                        />
                    </label>
                    <label class="flex min-w-0 flex-col gap-1 text-sm">
                        <span class="text-muted-foreground text-xs"
                            >Almacén</span
                        >
                        <BuscadorAsync
                            v-model="almacenSeleccionado"
                            :buscar="buscarAlmacenes"
                            :etiqueta="(a) => String(a.nombre)"
                            :dependencia="empresaId"
                            placeholder="Todos los almacenes"
                            placeholder-busqueda="Buscar almacén…"
                            class="w-full"
                        />
                    </label>
                    <label class="flex min-w-0 flex-col gap-1 text-sm">
                        <span class="text-muted-foreground text-xs">Desde</span>
                        <DatePicker v-model="desde" class="w-full" />
                    </label>
                    <label class="flex min-w-0 flex-col gap-1 text-sm">
                        <span class="text-muted-foreground text-xs">Hasta</span>
                        <DatePicker v-model="hasta" class="w-full" />
                    </label>
                </div>
                <div
                    v-if="hayFiltrosActivos"
                    class="mt-3 flex items-center justify-between gap-2 border-t pt-3"
                >
                    <p class="text-muted-foreground text-xs">
                        Filtros aplicados
                    </p>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground"
                        @click="limpiarFiltros"
                    >
                        <X class="size-3.5" /> Limpiar filtros
                    </Button>
                </div>
            </CardContent>
        </Card>

        <!--
            4 KPIs principales (tamaño de operación, inventario, actividad,
            alertas) + una fila secundaria compacta. El resto de los datos
            (unidades por estado, existencias por almacén…) sigue disponible
            en `resumen.series` para las gráficas de abajo.
        -->
        <div
            class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
            data-tour="kpis-dashboard"
        >
            <TarjetaKpi
                titulo="Colaboradores activos"
                :valor="resumen.kpis.colaboradores_activos"
                :icono="Users"
                tono-clase="bg-blue-500/10 text-blue-600 dark:text-blue-400"
                descripcion="Activos actualmente"
            />
            <TarjetaKpi
                titulo="Existencias disponibles"
                :valor="resumen.kpis.existencias_disponibles"
                :icono="Boxes"
                tono-clase="bg-cyan-500/10 text-cyan-600 dark:text-cyan-400"
                descripcion="Activos por cantidad, todos los almacenes"
                ayuda="Suma de cantidades en existencia de activos por cantidad (no incluye unidades identificadas)."
            />
            <TarjetaKpi
                titulo="Entregas del periodo"
                :valor="resumen.kpis.entregas_periodo"
                :icono="ClipboardList"
                tono-clase="bg-indigo-500/10 text-indigo-600 dark:text-indigo-400"
                descripcion="Dentro del rango de fechas"
            />
            <TarjetaKpi
                titulo="Activos con stock bajo"
                :valor="resumen.kpis.activos_stock_bajo"
                :icono="AlertTriangle"
                :tono-clase="
                    resumen.kpis.activos_stock_bajo
                        ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                        : 'bg-muted text-muted-foreground'
                "
                descripcion="Estado actual del inventario"
            />
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <TarjetaKpi
                compacto
                titulo="Devoluciones"
                :valor="resumen.kpis.devoluciones_periodo"
                :icono="RotateCcw"
                tono-clase="bg-orange-500/10 text-orange-600 dark:text-orange-400"
            />
            <TarjetaKpi
                compacto
                titulo="Almacenes activos"
                :valor="resumen.kpis.almacenes_activos"
                :icono="Warehouse"
                tono-clase="bg-slate-500/10 text-slate-600 dark:text-slate-400"
            />
            <TarjetaKpi
                compacto
                titulo="Unidades disponibles"
                :valor="resumen.kpis.unidades_disponibles"
                :icono="CheckCircle2"
                tono-clase="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
            />
            <TarjetaKpi
                compacto
                titulo="Unidades asignadas"
                :valor="resumen.kpis.unidades_asignadas"
                :icono="Users"
                tono-clase="bg-blue-500/10 text-blue-600 dark:text-blue-400"
            />
            <TarjetaKpi
                compacto
                titulo="En reparación"
                :valor="resumen.kpis.unidades_en_reparacion"
                :icono="Wrench"
                tono-clase="bg-amber-500/10 text-amber-600 dark:text-amber-400"
            />
        </div>

        <div class="grid gap-4 lg:grid-cols-2" data-tour="graficas-dashboard">
            <Card
                class="hover:border-primary/20 transition-[border-color,box-shadow] duration-200 hover:shadow-sm"
            >
                <CardHeader
                    class="flex flex-row items-start justify-between gap-2"
                >
                    <div>
                        <CardTitle class="text-base"
                            >Entregas y devoluciones</CardTitle
                        >
                        <CardDescription
                            >Actividad diaria del periodo
                            seleccionado</CardDescription
                        >
                    </div>
                    <Badge variant="secondary" class="shrink-0 font-normal">{{
                        rangoEtiqueta
                    }}</Badge>
                </CardHeader>
                <CardContent>
                    <EstadoVacio
                        v-if="!sumaEntregas && !sumaDevoluciones"
                        titulo="Sin actividad en este periodo"
                        descripcion="No hubo entregas ni devoluciones en el rango de fechas seleccionado."
                        class="py-6"
                    />
                    <VueApexCharts
                        v-else
                        type="area"
                        height="260"
                        :options="opcionesEntregasDevoluciones"
                        :series="seriesEntregasDevoluciones"
                    />
                </CardContent>
            </Card>

            <Card
                class="hover:border-primary/20 transition-[border-color,box-shadow] duration-200 hover:shadow-sm"
            >
                <CardHeader>
                    <CardTitle class="text-base"
                        >Movimientos de inventario</CardTitle
                    >
                    <CardDescription
                        >Entradas y salidas registradas durante el
                        periodo</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <EstadoVacio
                        v-if="!sumaMovimientos"
                        titulo="Sin movimientos en este periodo"
                        descripcion="No se registraron entradas ni salidas de inventario en el rango seleccionado."
                        class="py-6"
                    />
                    <VueApexCharts
                        v-else
                        type="area"
                        height="260"
                        :options="opcionesMovimientos"
                        :series="seriesMovimientos"
                    />
                </CardContent>
            </Card>

            <Card
                class="hover:border-primary/20 transition-[border-color,box-shadow] duration-200 hover:shadow-sm"
            >
                <CardHeader>
                    <CardTitle class="text-base">Unidades por estado</CardTitle>
                </CardHeader>
                <CardContent>
                    <EstadoVacio
                        v-if="!sumaUnidades"
                        titulo="Sin unidades registradas"
                        descripcion="Todavía no hay unidades de seguimiento individual en este alcance."
                        class="py-6"
                    />
                    <VueApexCharts
                        v-else
                        type="donut"
                        height="260"
                        :options="opcionesUnidades"
                        :series="seriesUnidades"
                    />
                </CardContent>
            </Card>

            <Card
                class="hover:border-primary/20 transition-[border-color,box-shadow] duration-200 hover:shadow-sm"
            >
                <CardHeader>
                    <CardTitle class="text-base"
                        >Existencias por almacén</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <EstadoVacio
                        v-if="!resumen.series.existencias_por_almacen.length"
                        titulo="Sin existencias"
                        descripcion="No hay existencias registradas en este alcance."
                        class="py-6"
                    />
                    <VueApexCharts
                        v-else
                        type="bar"
                        height="260"
                        :options="opcionesAlmacen"
                        :series="seriesAlmacen"
                    />
                </CardContent>
            </Card>

            <Card
                class="hover:border-primary/20 transition-[border-color,box-shadow] duration-200 hover:shadow-sm lg:col-span-2"
            >
                <CardHeader>
                    <CardTitle class="text-base">Stock por categoría</CardTitle>
                </CardHeader>
                <CardContent>
                    <EstadoVacio
                        v-if="!resumen.series.stock_por_categoria.length"
                        titulo="Sin categorías con existencias"
                        descripcion="No hay existencias clasificadas por categoría en este alcance."
                        class="py-6"
                    />
                    <VueApexCharts
                        v-else
                        :type="categoriaEsDonut ? 'donut' : 'bar'"
                        height="260"
                        :options="opcionesCategoria"
                        :series="seriesCategoria"
                    />
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card data-tour="entregas-recientes-dashboard">
                <CardHeader
                    class="flex flex-row items-start justify-between gap-2"
                >
                    <div>
                        <CardTitle class="text-base"
                            >Entregas recientes</CardTitle
                        >
                        <CardDescription
                            >Últimos movimientos de entrega</CardDescription
                        >
                    </div>
                    <Badge
                        v-if="resumen.entregas_recientes.length"
                        variant="secondary"
                        class="shrink-0 font-normal"
                        >{{ resumen.entregas_recientes.length }}
                        {{
                            resumen.entregas_recientes.length === 1
                                ? 'entrega'
                                : 'entregas'
                        }}</Badge
                    >
                </CardHeader>
                <CardContent class="space-y-1">
                    <EstadoVacio
                        v-if="!resumen.entregas_recientes.length"
                        titulo="Sin entregas recientes"
                        descripcion="No hubo entregas recientes para los filtros seleccionados."
                        class="py-6"
                    />
                    <Link
                        v-for="e in resumen.entregas_recientes"
                        :key="e.id"
                        :href="`/entregas/${e.id}`"
                        class="hover:bg-muted/30 hover:border-primary/15 flex items-start gap-3 rounded-lg border border-transparent px-3 py-2.5 transition-colors duration-150 hover:shadow-sm"
                    >
                        <span
                            class="bg-muted text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-full"
                        >
                            <ClipboardList class="size-4" />
                        </span>
                        <span class="min-w-0 flex-1 space-y-0.5">
                            <span
                                class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1"
                            >
                                <span class="truncate text-sm font-medium">{{
                                    e.folio
                                }}</span>
                                <Badge
                                    :variant="varianteEstadoEntrega(e.estado)"
                                    class="shrink-0 font-normal"
                                    >{{ e.estado_etiqueta }}</Badge
                                >
                            </span>
                            <span
                                class="text-muted-foreground block truncate text-xs"
                                >{{ e.colaborador }}</span
                            >
                            <span
                                class="text-muted-foreground block truncate text-xs"
                            >
                                {{ e.sucursal }}
                                <template
                                    v-if="!filtros.empresa_id && e.empresa"
                                >
                                    · {{ e.empresa }}</template
                                >
                                · {{ formatoFechaCorta(e.fecha_entrega) }}
                            </span>
                        </span>
                    </Link>
                </CardContent>
            </Card>

            <Card data-tour="existencias-bajas-dashboard">
                <CardHeader
                    class="flex flex-row items-start justify-between gap-2"
                >
                    <div>
                        <CardTitle class="text-base"
                            >Existencias bajas</CardTitle
                        >
                        <CardDescription
                            >Activos que requieren reposición</CardDescription
                        >
                    </div>
                    <Badge
                        v-if="stockBajoConSeveridad.length"
                        variant="secondary"
                        class="shrink-0 font-normal"
                        >{{ stockBajoConSeveridad.length }} con stock
                        bajo</Badge
                    >
                </CardHeader>
                <CardContent class="space-y-1">
                    <EstadoVacio
                        v-if="!stockBajoConSeveridad.length"
                        titulo="Todo el inventario está por encima de sus mínimos"
                        descripcion="Ningún activo requiere reposición en este alcance."
                        class="py-6"
                    >
                        <template #icono>
                            <CheckCircle2 class="text-success size-6" />
                        </template>
                    </EstadoVacio>
                    <div
                        v-for="(s, i) in stockBajoConSeveridad"
                        :key="i"
                        class="hover:bg-muted/30 hover:border-primary/15 flex flex-col gap-2 rounded-lg border border-transparent px-3 py-2.5 transition-colors duration-150 hover:shadow-sm"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-full"
                                :class="
                                    s.severidad.variante === 'destructive'
                                        ? 'bg-destructive/10 text-destructive'
                                        : 'bg-warning/10 text-warning'
                                "
                            >
                                <AlertTriangle class="size-4" />
                            </span>
                            <div class="min-w-0 flex-1 space-y-0.5">
                                <span
                                    class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1"
                                >
                                    <span
                                        class="truncate text-sm font-medium"
                                        >{{ s.activo }}</span
                                    >
                                    <span
                                        class="shrink-0 text-sm font-medium tabular-nums"
                                        >{{ formatoNumero(s.cantidad) }}/{{
                                            formatoNumero(s.minimo)
                                        }}</span
                                    >
                                </span>
                                <p
                                    v-if="s.talla"
                                    class="text-muted-foreground truncate text-xs"
                                >
                                    Variante {{ s.talla }}
                                </p>
                                <p
                                    class="text-muted-foreground truncate text-xs"
                                >
                                    {{ s.almacen }}
                                    <template
                                        v-if="!filtros.empresa_id && s.empresa"
                                    >
                                        · {{ s.empresa }}</template
                                    >
                                </p>
                            </div>
                        </div>
                        <div class="min-w-0 space-y-1 pl-12">
                            <div
                                class="bg-muted h-1.5 w-full overflow-hidden rounded-full"
                            >
                                <div
                                    class="h-full rounded-full transition-all"
                                    :class="s.severidad.colorBarra"
                                    :style="{
                                        width: `${s.severidad.porcentaje}%`,
                                    }"
                                />
                            </div>
                            <Badge
                                :variant="s.severidad.variante"
                                class="font-normal"
                                >{{ s.severidad.etiqueta }}</Badge
                            >
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
