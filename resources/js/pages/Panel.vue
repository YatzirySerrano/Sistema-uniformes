<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { getLocalTimeZone, today } from '@internationalized/date';
import type { ApexFormatterOpts, ApexOptions } from 'apexcharts';
import {
    AlertTriangle,
    Boxes,
    Briefcase,
    Building2,
    CheckCircle2,
    ClipboardList,
    FileText,
    MapPin,
    Package,
    RotateCcw,
    ScanLine,
    Users,
    UserX,
    Warehouse,
    Wrench,
    X,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue';
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

/**
 * Bloques que el BACKEND autorizó para este usuario (permisos efectivos,
 * resueltos en `SeccionDashboard`). El frontend no decide seguridad: sólo
 * pinta lo que llegó — cada KPI/serie/lista es opcional y existe únicamente
 * si su sección fue autorizada y consultada.
 */
type SeccionDashboard =
    | 'colaboradores'
    | 'activos'
    | 'inventario'
    | 'entregas'
    | 'devoluciones'
    | 'almacenes'
    | 'unidades'
    | 'inventario_fisico'
    | 'empresas'
    | 'sucursales'
    | 'contratos'
    | 'servicios';

type Resumen = {
    secciones: SeccionDashboard[];
    kpis: {
        colaboradores_activos?: number;
        activos_activos?: number;
        existencias_disponibles?: number;
        entregas_periodo?: number;
        devoluciones_periodo?: number;
        activos_stock_bajo?: number;
        almacenes_activos?: number;
        unidades_disponibles?: number;
        unidades_asignadas?: number;
        unidades_en_reparacion?: number;
        unidades_perdidas?: number;
        unidades_robadas?: number;
        rondas_inventario_fisico_en_proceso?: number;
        colaboradores_sin_servicio?: number;
        empresas_activas?: number;
        sucursales_activas?: number;
        contratos_activos?: number;
        servicios_activos?: number;
    };
    series: {
        entregas_por_periodo?: { fecha: string; total: number }[];
        devoluciones_por_periodo?: { fecha: string; total: number }[];
        movimientos_por_periodo?: {
            fecha: string;
            entradas: number;
            salidas: number;
        }[];
        unidades_por_estado?: {
            estado: string;
            etiqueta: string;
            total: number;
        }[];
        existencias_por_almacen?: { almacen: string; total: number }[];
        stock_por_categoria?: { categoria: string; total: number }[];
    };
    entregas_recientes?: {
        id: number;
        folio: string;
        colaborador: string;
        sucursal: string;
        empresa: string | null;
        estado: string;
        estado_etiqueta: string;
        fecha_entrega: string;
    }[];
    stock_bajo_detalle?: {
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
    filtrosDisponibles: {
        sucursal: boolean;
        almacen: boolean;
        fechas: boolean;
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

// ApexCharts es una librería pesada: cargarla de forma asíncrona permite que
// los KPIs y filtros (lo primero que el usuario necesita ver) se rendericen
// sin esperar a que se descargue y evalúe el bundle completo de gráficas.
const VueApexCharts = defineAsyncComponent(() => import('vue3-apexcharts'));

// Las 5 gráficas montan simultáneamente en cuanto el chunk resuelve; retrasar
// su montaje un frame (tras el primer paint de KPIs/filtros, que es lo que el
// usuario necesita ver primero) evita competir por el mismo frame de render.
// Nunca bloquea: si `requestIdleCallback` no existe (Safari), cae a rAF doble.
const graficasListas = ref(false);
onMounted(() => {
    const activar = () => (graficasListas.value = true);
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(activar, { timeout: 500 });
    } else {
        requestAnimationFrame(() => requestAnimationFrame(activar));
    }
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
// Resincroniza el filtro si el backend resuelve una empresa distinta a la
// que ya tenía este ref local (p. ej. tras crear/editar un registro en otra
// página y volver aquí sin remontar el componente) — nunca se queda con un
// valor obsoleto ni "inventa" la primera empresa de la lista.
watch(
    () => props.filtros.empresa_id,
    (nuevoId) => {
        if (nuevoId !== (empresaSeleccionada.value?.id ?? null)) {
            empresaSeleccionada.value =
                props.empresasAutorizadas.find((e) => e.id === nuevoId) ?? null;
        }
    },
);
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

// ------------------------------------------------------------------
// Navegación desde las cards de KPI: cada una arma la URL del listado
// destino con EXACTAMENTE los filtros que ese módulo soporta y que
// reproducen el mismo criterio con el que se calculó el KPI — nunca un
// `router.visit('/modulo')` genérico ni un parámetro que el destino ignore.
// Los filtros usados son siempre `props.filtros` (lo que el backend YA
// aplicó para el `resumen` mostrado), no los refs locales todavía sin
// confirmar.
// ------------------------------------------------------------------
function qs(params: Record<string, string | number | null | undefined>) {
    const p = new URLSearchParams();
    for (const [k, v] of Object.entries(params)) {
        if (v !== null && v !== undefined && v !== '') p.set(k, String(v));
    }
    const s = p.toString();
    return s ? `?${s}` : '';
}

const hrefColaboradoresActivos = computed(
    () =>
        `/colaboradores${qs({
            empresa_id: props.filtros.empresa_id,
            sucursal_id: props.filtros.sucursal_id,
            estado: 'activos',
        })}`,
);
const hrefExistenciasDisponibles = computed(
    () =>
        `/inventario${qs({
            empresa_id: props.filtros.empresa_id,
            almacen_id: props.filtros.almacen_id,
        })}`,
);
const hrefEntregasPeriodo = computed(
    () =>
        `/entregas${qs({
            empresa_id: props.filtros.empresa_id,
            sucursal_id: props.filtros.sucursal_id,
            almacen_id: props.filtros.almacen_id,
            desde: props.filtros.desde,
            hasta: props.filtros.hasta,
        })}`,
);
const hrefStockBajo = computed(
    () =>
        `/inventario${qs({
            empresa_id: props.filtros.empresa_id,
            almacen_id: props.filtros.almacen_id,
            estado_stock: 'bajo_minimo',
        })}`,
);
const hrefDevolucionesPeriodo = computed(
    () =>
        `/devoluciones${qs({
            empresa_id: props.filtros.empresa_id,
            sucursal_id: props.filtros.sucursal_id,
            almacen_id: props.filtros.almacen_id,
            desde: props.filtros.desde,
            hasta: props.filtros.hasta,
        })}`,
);
const hrefAlmacenesActivos = computed(
    () =>
        `/almacenes${qs({ empresa_id: props.filtros.empresa_id, estado: 'activos' })}`,
);
const hrefActivosActivos = computed(
    () =>
        `/activos${qs({ empresa_id: props.filtros.empresa_id, estado: 'activos' })}`,
);
const hrefRondasEnProceso = computed(
    () =>
        `/inventarios-fisicos${qs({ empresa_id: props.filtros.empresa_id, estado: 'en_proceso' })}`,
);
const hrefEmpresasActivas = computed(
    () => `/empresas${qs({ estado: 'activas' })}`,
);
const hrefSucursalesActivas = computed(
    () =>
        `/sucursales${qs({ empresa_id: props.filtros.empresa_id, estado: 'activas' })}`,
);
const hrefContratosActivos = computed(
    () =>
        `/contratos${qs({ empresa_id: props.filtros.empresa_id, estado: 'activos' })}`,
);
const hrefServiciosActivos = computed(
    () =>
        `/servicios${qs({ empresa_id: props.filtros.empresa_id, estado: 'activos' })}`,
);
function hrefUnidades(estadoVisible: string): string {
    return `/activos/unidades${qs({
        empresa_id: props.filtros.empresa_id,
        almacen_id: props.filtros.almacen_id,
        estado_visible: estadoVisible,
    })}`;
}

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

// ------------------------------------------------------------------
// Cards de KPI: se arman sólo con los KPIs que el backend ENVIÓ (una
// sección no autorizada ni se consulta ni viaja en `resumen`). No hay
// ningún mapa de permisos aquí: presencia del dato = sección autorizada,
// y su `href` apunta al mismo módulo cuya Policy la autorizó.
// ------------------------------------------------------------------
type TarjetaKpiDatos = {
    clave: string;
    titulo: string;
    valor: number;
    icono: Component;
    href: string;
    tonoClase: string;
    descripcion?: string;
    ayuda?: string;
};

function soloPresentes(
    tarjetas: (Omit<TarjetaKpiDatos, 'valor'> & { valor?: number })[],
): TarjetaKpiDatos[] {
    return tarjetas.filter(
        (t): t is TarjetaKpiDatos => typeof t.valor === 'number',
    );
}

const tarjetasPrincipales = computed(() => {
    const k = props.resumen.kpis;

    return soloPresentes([
        {
            clave: 'colaboradores',
            titulo: 'Colaboradores activos',
            valor: k.colaboradores_activos,
            icono: Users,
            href: hrefColaboradoresActivos.value,
            tonoClase: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
            descripcion: 'Activos actualmente',
        },
        {
            clave: 'existencias',
            titulo: 'Existencias disponibles',
            valor: k.existencias_disponibles,
            icono: Boxes,
            href: hrefExistenciasDisponibles.value,
            tonoClase: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400',
            descripcion: 'Activos por cantidad, todos los almacenes',
            ayuda: 'Suma de cantidades en existencia de activos por cantidad (no incluye unidades identificadas).',
        },
        {
            clave: 'entregas',
            titulo: 'Entregas del periodo',
            valor: k.entregas_periodo,
            icono: ClipboardList,
            href: hrefEntregasPeriodo.value,
            tonoClase: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
            descripcion: 'Dentro del rango de fechas',
        },
        {
            clave: 'stock-bajo',
            titulo: 'Activos con stock bajo',
            valor: k.activos_stock_bajo,
            icono: AlertTriangle,
            href: hrefStockBajo.value,
            tonoClase: k.activos_stock_bajo
                ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                : 'bg-muted text-muted-foreground',
            descripcion: 'Estado actual del inventario',
        },
    ]);
});

const tarjetasSecundarias = computed(() => {
    const k = props.resumen.kpis;

    return soloPresentes([
        {
            clave: 'devoluciones',
            titulo: 'Devoluciones',
            valor: k.devoluciones_periodo,
            icono: RotateCcw,
            href: hrefDevolucionesPeriodo.value,
            tonoClase: 'bg-orange-500/10 text-orange-600 dark:text-orange-400',
        },
        {
            clave: 'activos',
            titulo: 'Activos activos',
            valor: k.activos_activos,
            icono: Package,
            href: hrefActivosActivos.value,
            tonoClase: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
        },
        {
            clave: 'almacenes',
            titulo: 'Almacenes activos',
            valor: k.almacenes_activos,
            icono: Warehouse,
            href: hrefAlmacenesActivos.value,
            tonoClase: 'bg-slate-500/10 text-slate-600 dark:text-slate-400',
        },
        {
            clave: 'unidades-disponibles',
            titulo: 'Unidades disponibles',
            valor: k.unidades_disponibles,
            icono: CheckCircle2,
            href: hrefUnidades('disponible'),
            tonoClase:
                'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        },
        {
            clave: 'unidades-asignadas',
            titulo: 'Unidades asignadas',
            valor: k.unidades_asignadas,
            icono: Users,
            href: hrefUnidades('asignado'),
            tonoClase: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
        },
        {
            clave: 'unidades-reparacion',
            titulo: 'En reparación',
            valor: k.unidades_en_reparacion,
            icono: Wrench,
            href: hrefUnidades('reparacion'),
            tonoClase: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        },
        {
            clave: 'rondas-en-proceso',
            titulo: 'Rondas de inventario físico en proceso',
            valor: k.rondas_inventario_fisico_en_proceso,
            icono: ScanLine,
            href: hrefRondasEnProceso.value,
            tonoClase: 'bg-teal-500/10 text-teal-600 dark:text-teal-400',
        },
    ]);
});

// Estructura de la organización (perfiles administrativos): una fila
// compacta aparte para no mezclarla con la operación. Mismo principio:
// sólo llegan los KPIs de secciones autorizadas.
const tarjetasEstructura = computed(() => {
    const k = props.resumen.kpis;

    return soloPresentes([
        {
            clave: 'empresas',
            titulo: 'Empresas activas',
            valor: k.empresas_activas,
            icono: Building2,
            href: hrefEmpresasActivas.value,
            tonoClase: 'bg-slate-500/10 text-slate-600 dark:text-slate-400',
        },
        {
            clave: 'sucursales',
            titulo: 'Sucursales activas',
            valor: k.sucursales_activas,
            icono: MapPin,
            href: hrefSucursalesActivas.value,
            tonoClase: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
        },
        {
            clave: 'contratos',
            titulo: 'Contratos activos',
            valor: k.contratos_activos,
            icono: FileText,
            href: hrefContratosActivos.value,
            tonoClase: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
        },
        {
            clave: 'servicios',
            titulo: 'Servicios activos',
            valor: k.servicios_activos,
            icono: Briefcase,
            href: hrefServiciosActivos.value,
            tonoClase: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
        },
        {
            clave: 'colaboradores-sin-servicio',
            titulo: 'Colaboradores sin servicio',
            valor: k.colaboradores_sin_servicio,
            icono: UserX,
            // Sin enlace: el listado de Colaboradores no filtra por esto.
            href: '',
            tonoClase: k.colaboradores_sin_servicio
                ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                : 'bg-muted text-muted-foreground',
        },
    ]);
});

// Columnas según cuántas cards llegaron: nunca huecos por cards ausentes.
// Clases literales (Tailwind sólo genera las que ve escritas).
const COLUMNAS_PRINCIPALES: Record<number, string> = {
    1: 'grid-cols-1',
    2: 'grid-cols-1 sm:grid-cols-2',
    3: 'grid-cols-1 sm:grid-cols-3',
    4: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4',
};
const COLUMNAS_SECUNDARIAS: Record<number, string> = {
    1: 'grid-cols-2',
    2: 'grid-cols-2',
    3: 'grid-cols-2 sm:grid-cols-3',
    4: 'grid-cols-2 lg:grid-cols-4',
    5: 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5',
    6: 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6',
    7: 'grid-cols-2 sm:grid-cols-4 lg:grid-cols-7',
};

const sinSecciones = computed(() => props.resumen.secciones.length === 0);

const columnasFiltros = computed(() => {
    const visibles =
        (props.empresasAutorizadas.length > 1 ? 1 : 0) +
        (props.filtrosDisponibles.sucursal ? 1 : 0) +
        (props.filtrosDisponibles.almacen ? 1 : 0) +
        (props.filtrosDisponibles.fechas ? 2 : 0);

    const columnas: Record<number, string> = {
        2: 'md:grid-cols-2',
        3: 'md:grid-cols-2 xl:grid-cols-3',
        4: 'md:grid-cols-2 xl:grid-cols-4',
        5: 'md:grid-cols-2 xl:grid-cols-5',
    };

    return columnas[visibles] ?? '';
});
const hayFiltrosVisibles = computed(
    () =>
        props.empresasAutorizadas.length > 1 ||
        props.filtrosDisponibles.sucursal ||
        props.filtrosDisponibles.almacen ||
        props.filtrosDisponibles.fechas,
);

const serieEntregas = computed(() => props.resumen.series.entregas_por_periodo);
const serieDevoluciones = computed(
    () => props.resumen.series.devoluciones_por_periodo,
);
const tituloEntregasDevoluciones = computed(() =>
    serieEntregas.value && serieDevoluciones.value
        ? 'Entregas y devoluciones'
        : serieEntregas.value
          ? 'Entregas'
          : 'Devoluciones',
);

const sumaEntregas = computed(() =>
    (serieEntregas.value ?? []).reduce((a, p) => a + p.total, 0),
);
const sumaDevoluciones = computed(() =>
    (serieDevoluciones.value ?? []).reduce((a, p) => a + p.total, 0),
);
const sumaMovimientos = computed(() =>
    (props.resumen.series.movimientos_por_periodo ?? []).reduce(
        (a, p) => a + p.entradas + p.salidas,
        0,
    ),
);
const sumaUnidades = computed(() =>
    (props.resumen.series.unidades_por_estado ?? []).reduce(
        (a, u) => a + u.total,
        0,
    ),
);

const maximoEntregasDevoluciones = computed(() =>
    Math.max(
        1,
        ...(serieEntregas.value ?? []).map((p) => p.total),
        ...(serieDevoluciones.value ?? []).map((p) => p.total),
    ),
);
const opcionesEntregasDevoluciones = computed(() =>
    opcionesArea({
        colores: [
            ...(serieEntregas.value ? [colores.value.primary] : []),
            ...(serieDevoluciones.value ? [colores.value.chart2] : []),
        ],
        maximoY: maximoEntregasDevoluciones.value,
    }),
);
const seriesEntregasDevoluciones = computed(() => {
    const series: {
        name: string;
        data: ReturnType<typeof puntosSerieTemporal>;
    }[] = [];
    if (serieEntregas.value) {
        series.push({
            name: 'Entregas',
            data: puntosSerieTemporal(
                serieEntregas.value.map((p) => p.fecha),
                serieEntregas.value.map((p) => p.total),
            ),
        });
    }
    if (serieDevoluciones.value) {
        series.push({
            name: 'Devoluciones',
            data: puntosSerieTemporal(
                serieDevoluciones.value.map((p) => p.fecha),
                serieDevoluciones.value.map((p) => p.total),
            ),
        });
    }

    return series;
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
    const movimientos = props.resumen.series.movimientos_por_periodo ?? [];
    const fechas = movimientos.map((p) => p.fecha);

    return [
        {
            name: 'Entradas',
            data: puntosSerieTemporal(
                fechas,
                movimientos.map((p) => p.entradas),
            ),
        },
        {
            name: 'Salidas',
            data: puntosSerieTemporal(
                fechas,
                movimientos.map((p) => p.salidas),
            ),
        },
    ];
});

const unidadesPorEstado = computed(
    () => props.resumen.series.unidades_por_estado ?? [],
);
const opcionesUnidades = computed(() =>
    opcionesDonut(
        unidadesPorEstado.value.map((u) => u.etiqueta),
        {
            colores: unidadesPorEstado.value.map((u) =>
                colorEstadoUnidad(u.estado),
            ),
            totalEtiqueta: 'Unidades',
        },
    ),
);
const seriesUnidades = computed(() =>
    unidadesPorEstado.value.map((u) => u.total),
);

const existenciasPorAlmacen = computed(
    () => props.resumen.series.existencias_por_almacen ?? [],
);
const opcionesAlmacen = computed(() =>
    opcionesBarrasHorizontales(
        existenciasPorAlmacen.value.map((a) => a.almacen),
    ),
);
const seriesAlmacen = computed(() => [
    {
        name: 'Existencias',
        data: existenciasPorAlmacen.value.map((a) => a.total),
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
    (props.resumen.stock_bajo_detalle ?? []).map((s) => ({
        ...s,
        severidad: severidadStock(s.cantidad, s.minimo),
    })),
);

const stockPorCategoria = computed(
    () => props.resumen.series.stock_por_categoria ?? [],
);
const categoriaEsDonut = computed(
    () =>
        stockPorCategoria.value.length > 0 &&
        stockPorCategoria.value.length <= 5,
);
const opcionesCategoria = computed(() =>
    categoriaEsDonut.value
        ? opcionesDonut(
              stockPorCategoria.value.map((c) => c.categoria),
              { totalEtiqueta: 'Existencias' },
          )
        : opcionesBarrasHorizontales(
              stockPorCategoria.value.map((c) => c.categoria),
          ),
);
const seriesCategoria = computed(() =>
    categoriaEsDonut.value
        ? stockPorCategoria.value.map((c) => c.total)
        : [
              {
                  name: 'Existencias',
                  data: stockPorCategoria.value.map((c) => c.total),
              },
          ],
);

// Gráficas visibles, en orden: con un número impar, la ÚLTIMA ocupa las
// dos columnas en `lg` — nunca queda una celda vacía a su lado.
type ClaveGrafica =
    | 'entregas-devoluciones'
    | 'movimientos'
    | 'unidades'
    | 'almacen'
    | 'categoria';
const graficasVisibles = computed(() => {
    const s = props.resumen.series;
    const visibles: ClaveGrafica[] = [];
    if (s.entregas_por_periodo || s.devoluciones_por_periodo) {
        visibles.push('entregas-devoluciones');
    }
    if (s.movimientos_por_periodo) visibles.push('movimientos');
    if (s.unidades_por_estado) visibles.push('unidades');
    if (s.existencias_por_almacen) visibles.push('almacen');
    if (s.stock_por_categoria) visibles.push('categoria');

    return visibles;
});
function claseGrafica(clave: ClaveGrafica): string {
    const lista = graficasVisibles.value;
    const esUltimaImpar =
        lista.length % 2 === 1 && lista[lista.length - 1] === clave;

    return esUltimaImpar ? 'lg:col-span-2' : '';
}
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

        <!--
            Ninguna sección autorizada (p. ej. sólo permisos de firma): un
            Dashboard válido con estado vacío, sin filtros ni cards.
        -->
        <Card v-if="sinSecciones">
            <CardContent class="pt-6">
                <EstadoVacio
                    titulo="Sin información para mostrar"
                    descripcion="No hay información disponible para los módulos a los que tienes acceso."
                    class="py-10"
                />
            </CardContent>
        </Card>

        <template v-else>
            <Card v-if="hayFiltrosVisibles" data-tour="filtros-dashboard">
                <CardContent class="pt-6">
                    <div
                        class="grid grid-cols-1 items-end gap-3"
                        :class="columnasFiltros"
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
                        <label
                            v-if="filtrosDisponibles.sucursal"
                            class="flex min-w-0 flex-col gap-1 text-sm"
                        >
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
                        <label
                            v-if="filtrosDisponibles.almacen"
                            class="flex min-w-0 flex-col gap-1 text-sm"
                        >
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
                        <template v-if="filtrosDisponibles.fechas">
                            <label class="flex min-w-0 flex-col gap-1 text-sm">
                                <span class="text-muted-foreground text-xs"
                                    >Desde</span
                                >
                                <DatePicker v-model="desde" class="w-full" />
                            </label>
                            <label class="flex min-w-0 flex-col gap-1 text-sm">
                                <span class="text-muted-foreground text-xs"
                                    >Hasta</span
                                >
                                <DatePicker v-model="hasta" class="w-full" />
                            </label>
                        </template>
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
            KPIs principales (tamaño de operación, inventario, actividad,
            alertas) + fila secundaria compacta. Cada card existe sólo si el
            backend envió su KPI (sección autorizada); las columnas se
            ajustan a cuántas llegaron.
        -->
            <div
                v-if="tarjetasPrincipales.length"
                class="grid gap-3"
                :class="COLUMNAS_PRINCIPALES[tarjetasPrincipales.length]"
                data-tour="kpis-dashboard"
            >
                <TarjetaKpi
                    v-for="t in tarjetasPrincipales"
                    :key="t.clave"
                    :titulo="t.titulo"
                    :valor="t.valor"
                    :icono="t.icono"
                    :href="t.href"
                    :tono-clase="t.tonoClase"
                    :descripcion="t.descripcion"
                    :ayuda="t.ayuda"
                />
            </div>

            <section
                v-if="tarjetasEstructura.length"
                aria-labelledby="titulo-estructura"
                class="space-y-2"
            >
                <h2
                    id="titulo-estructura"
                    class="text-muted-foreground text-xs font-medium tracking-wide uppercase"
                >
                    Estructura de la organización
                </h2>
                <div
                    class="grid gap-3"
                    :class="COLUMNAS_SECUNDARIAS[tarjetasEstructura.length]"
                >
                    <TarjetaKpi
                        v-for="t in tarjetasEstructura"
                        :key="t.clave"
                        compacto
                        :titulo="t.titulo"
                        :valor="t.valor"
                        :icono="t.icono"
                        :href="t.href || undefined"
                        :tono-clase="t.tonoClase"
                    />
                </div>
            </section>

            <div
                v-if="tarjetasSecundarias.length"
                class="grid gap-3"
                :class="COLUMNAS_SECUNDARIAS[tarjetasSecundarias.length]"
            >
                <TarjetaKpi
                    v-for="t in tarjetasSecundarias"
                    :key="t.clave"
                    compacto
                    :titulo="t.titulo"
                    :valor="t.valor"
                    :icono="t.icono"
                    :href="t.href"
                    :tono-clase="t.tonoClase"
                />
            </div>

            <div
                v-if="graficasVisibles.length"
                class="grid gap-4 lg:grid-cols-2"
                data-tour="graficas-dashboard"
            >
                <Card
                    v-if="graficasVisibles.includes('entregas-devoluciones')"
                    class="hover:border-primary/20 transition-[border-color,box-shadow] duration-200 hover:shadow-sm"
                    :class="claseGrafica('entregas-devoluciones')"
                >
                    <CardHeader
                        class="flex flex-row items-start justify-between gap-2"
                    >
                        <div>
                            <CardTitle class="text-base">{{
                                tituloEntregasDevoluciones
                            }}</CardTitle>
                            <CardDescription
                                >Actividad diaria del periodo
                                seleccionado</CardDescription
                            >
                        </div>
                        <Badge
                            variant="secondary"
                            class="shrink-0 font-normal"
                            >{{ rangoEtiqueta }}</Badge
                        >
                    </CardHeader>
                    <CardContent>
                        <EstadoVacio
                            v-if="!sumaEntregas && !sumaDevoluciones"
                            titulo="Sin actividad en este periodo"
                            descripcion="No hubo actividad en el rango de fechas seleccionado."
                            class="py-6"
                        />
                        <VueApexCharts
                            v-else-if="graficasListas"
                            type="area"
                            height="260"
                            :options="opcionesEntregasDevoluciones"
                            :series="seriesEntregasDevoluciones"
                        />
                        <div
                            v-else
                            class="bg-muted/50 h-[260px] rounded-lg motion-safe:animate-pulse"
                        />
                    </CardContent>
                </Card>

                <Card
                    v-if="graficasVisibles.includes('movimientos')"
                    class="hover:border-primary/20 transition-[border-color,box-shadow] duration-200 hover:shadow-sm"
                    :class="claseGrafica('movimientos')"
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
                            v-else-if="graficasListas"
                            type="area"
                            height="260"
                            :options="opcionesMovimientos"
                            :series="seriesMovimientos"
                        />
                        <div
                            v-else
                            class="bg-muted/50 h-[260px] rounded-lg motion-safe:animate-pulse"
                        />
                    </CardContent>
                </Card>

                <Card
                    v-if="graficasVisibles.includes('unidades')"
                    class="hover:border-primary/20 transition-[border-color,box-shadow] duration-200 hover:shadow-sm"
                    :class="claseGrafica('unidades')"
                >
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Unidades por estado</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <EstadoVacio
                            v-if="!sumaUnidades"
                            titulo="Sin unidades registradas"
                            descripcion="Todavía no hay unidades de seguimiento individual en este alcance."
                            class="py-6"
                        />
                        <VueApexCharts
                            v-else-if="graficasListas"
                            type="donut"
                            height="260"
                            :options="opcionesUnidades"
                            :series="seriesUnidades"
                        />
                        <div
                            v-else
                            class="bg-muted/50 h-[260px] rounded-lg motion-safe:animate-pulse"
                        />
                    </CardContent>
                </Card>

                <Card
                    v-if="graficasVisibles.includes('almacen')"
                    class="hover:border-primary/20 transition-[border-color,box-shadow] duration-200 hover:shadow-sm"
                    :class="claseGrafica('almacen')"
                >
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Existencias por almacén</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <EstadoVacio
                            v-if="!existenciasPorAlmacen.length"
                            titulo="Sin existencias"
                            descripcion="No hay existencias registradas en este alcance."
                            class="py-6"
                        />
                        <VueApexCharts
                            v-else-if="graficasListas"
                            type="bar"
                            height="260"
                            :options="opcionesAlmacen"
                            :series="seriesAlmacen"
                        />
                        <div
                            v-else
                            class="bg-muted/50 h-[260px] rounded-lg motion-safe:animate-pulse"
                        />
                    </CardContent>
                </Card>

                <Card
                    v-if="graficasVisibles.includes('categoria')"
                    class="hover:border-primary/20 transition-[border-color,box-shadow] duration-200 hover:shadow-sm"
                    :class="claseGrafica('categoria')"
                >
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Stock por categoría</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <EstadoVacio
                            v-if="!stockPorCategoria.length"
                            titulo="Sin categorías con existencias"
                            descripcion="No hay existencias clasificadas por categoría en este alcance."
                            class="py-6"
                        />
                        <VueApexCharts
                            v-else-if="graficasListas"
                            :type="categoriaEsDonut ? 'donut' : 'bar'"
                            height="260"
                            :options="opcionesCategoria"
                            :series="seriesCategoria"
                        />
                        <div
                            v-else
                            class="bg-muted/50 h-[260px] rounded-lg motion-safe:animate-pulse"
                        />
                    </CardContent>
                </Card>
            </div>

            <div
                v-if="resumen.entregas_recientes || resumen.stock_bajo_detalle"
                class="grid min-w-0 gap-4"
                :class="
                    resumen.entregas_recientes && resumen.stock_bajo_detalle
                        ? 'lg:grid-cols-2'
                        : ''
                "
            >
                <Card
                    v-if="resumen.entregas_recientes"
                    data-tour="entregas-recientes-dashboard"
                    class="min-w-0"
                >
                    <CardHeader
                        class="flex flex-row items-start justify-between gap-2"
                    >
                        <div class="min-w-0">
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
                                    <span
                                        class="min-w-0 truncate text-sm font-medium"
                                        >{{ e.folio }}</span
                                    >
                                    <Badge
                                        :variant="
                                            varianteEstadoEntrega(e.estado)
                                        "
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

                <Card
                    v-if="resumen.stock_bajo_detalle"
                    data-tour="existencias-bajas-dashboard"
                    class="min-w-0"
                >
                    <CardHeader
                        class="flex flex-row items-start justify-between gap-2"
                    >
                        <div class="min-w-0">
                            <CardTitle class="text-base"
                                >Existencias bajas</CardTitle
                            >
                            <CardDescription
                                >Activos que requieren
                                reposición</CardDescription
                            >
                        </div>
                        <Link
                            v-if="stockBajoConSeveridad.length"
                            :href="hrefStockBajo"
                            class="focus-visible:ring-ring shrink-0 rounded"
                        >
                            <Badge
                                variant="secondary"
                                class="hover:bg-secondary/70 cursor-pointer font-normal transition-colors"
                                >{{ stockBajoConSeveridad.length }} con stock
                                bajo</Badge
                            >
                        </Link>
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
                                            class="min-w-0 truncate text-sm font-medium"
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
                                            v-if="
                                                !filtros.empresa_id && s.empresa
                                            "
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
        </template>
    </div>
</template>
