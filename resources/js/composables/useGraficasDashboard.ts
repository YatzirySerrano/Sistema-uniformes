import type { ApexOptions, ApexTooltip } from 'apexcharts';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useAppearance } from '@/composables/useAppearance';

/**
 * Capa central de configuración para ApexCharts, reutilizada por todas las
 * gráficas del Dashboard y de Reportes. Nunca configurar una gráfica desde
 * cero en la página: los colores, el tema claro/oscuro, los formatos de
 * número/fecha y las animaciones salen SIEMPRE de aquí, para que todas las
 * gráficas del sistema se vean y se comporten igual.
 *
 * Los colores se leen en caliente de los custom properties CSS
 * (`--primary`, `--chart-1`…`--chart-5`, `--muted-foreground`, `--border`)
 * en vez de codificarse aquí: así la gráfica respeta tanto el modo
 * claro/oscuro como la personalización visual global del sistema
 * (`ConfiguracionSistema`, aplicada en caliente sobre esas mismas
 * variables — ver `lib/temaVisual.ts`).
 *
 * SERIES TEMPORALES: siempre `xaxis.type = 'datetime'` con puntos
 * `{ x: timestamp, y: valor }` (nunca `xaxis.categories` + un array plano de
 * valores). Con `categories` + array plano, ApexCharts asigna como "x" real
 * de cada punto el ÍNDICE (1, 2, 3…) — no la fecha — y `tooltip.x.formatter`
 * recibe ese índice, no la categoría; sólo `xaxis.labels.formatter` ve la
 * categoría (son dos contratos distintos). `datetime` unifica ambos
 * formatters sobre el mismo timestamp real. `fechaLocalATimestamp()` arma el
 * timestamp desde año/mes/día locales (nunca `new Date('YYYY-MM-DD')`, que
 * parsea como UTC y puede desplazar un día en husos horarios negativos como
 * México).
 */

type ColoresTema = {
    primary: string;
    mutedForeground: string;
    foreground: string;
    border: string;
    destructive: string;
    chart1: string;
    chart2: string;
    chart3: string;
    chart4: string;
    chart5: string;
};

const VALORES_POR_DEFECTO: ColoresTema = {
    primary: 'hsl(0 0% 9%)',
    mutedForeground: 'hsl(0 0% 45.1%)',
    foreground: 'hsl(0 0% 3.9%)',
    border: 'hsl(0 0% 92.8%)',
    destructive: 'hsl(0 84.2% 60.2%)',
    chart1: 'hsl(12 76% 61%)',
    chart2: 'hsl(173 58% 39%)',
    chart3: 'hsl(197 37% 24%)',
    chart4: 'hsl(43 74% 66%)',
    chart5: 'hsl(27 87% 67%)',
};

function leerVariable(nombre: string, fallback: string): string {
    if (typeof window === 'undefined') {
        return fallback;
    }

    const valor = getComputedStyle(document.documentElement)
        .getPropertyValue(nombre)
        .trim();

    return valor || fallback;
}

function leerColoresTema(): ColoresTema {
    return {
        primary: leerVariable('--primary', VALORES_POR_DEFECTO.primary),
        mutedForeground: leerVariable(
            '--muted-foreground',
            VALORES_POR_DEFECTO.mutedForeground,
        ),
        foreground: leerVariable(
            '--foreground',
            VALORES_POR_DEFECTO.foreground,
        ),
        border: leerVariable('--border', VALORES_POR_DEFECTO.border),
        destructive: leerVariable(
            '--destructive',
            VALORES_POR_DEFECTO.destructive,
        ),
        chart1: leerVariable('--chart-1', VALORES_POR_DEFECTO.chart1),
        chart2: leerVariable('--chart-2', VALORES_POR_DEFECTO.chart2),
        chart3: leerVariable('--chart-3', VALORES_POR_DEFECTO.chart3),
        chart4: leerVariable('--chart-4', VALORES_POR_DEFECTO.chart4),
        chart5: leerVariable('--chart-5', VALORES_POR_DEFECTO.chart5),
    };
}

/**
 * Paleta semántica para "Unidades por estado" (mismos tonos que
 * `lib/estadoVisibleUnidad.ts` usa en los badges, para que la gráfica y las
 * etiquetas del resto del sistema cuenten la misma historia visual).
 */
const COLORES_ESTADO_UNIDAD: Record<string, string> = {
    disponible: '#10b981', // emerald-500
    asignado: '#3b82f6', // blue-500
    reparacion: '#f59e0b', // amber-500
    perdido: '#f87171', // red-400
    robado: '#dc2626', // red-600
    baja: '#9ca3af', // gray-400 (≈ muted-foreground)
};

export function colorEstadoUnidad(valor: string): string {
    return COLORES_ESTADO_UNIDAD[valor] ?? COLORES_ESTADO_UNIDAD.baja;
}

export const formatoNumero = (valor: number): string =>
    new Intl.NumberFormat('es-MX').format(valor);

/**
 * Forma abreviada para ejes con magnitudes grandes (miles). El valor exacto
 * sigue mostrándose siempre en el tooltip — esto es sólo para que el eje no
 * se llene de dígitos.
 */
export const formatoNumeroCorto = (valor: number): string => {
    if (Math.abs(valor) >= 1000) {
        const miles = valor / 1000;
        const texto = Number.isInteger(miles)
            ? String(miles)
            : miles.toFixed(1);
        return `${texto} mil`;
    }

    return formatoNumero(valor);
};

/**
 * Construye un timestamp de medianoche LOCAL a partir de una fecha calendario
 * `YYYY-MM-DD`. Nunca `new Date('YYYY-MM-DD')`: ese formato se interpreta
 * como UTC y en husos horarios negativos (México, UTC-6) puede mostrar el
 * día anterior.
 */
export function fechaLocalATimestamp(iso: string): number {
    const [anio, mes, dia] = iso.split('-').map(Number);
    return new Date(anio, (mes || 1) - 1, dia || 1).getTime();
}

export const formatoFechaCortaTs = (ts: number): string =>
    new Date(ts).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'short',
    });

export const formatoFechaLargaTs = (ts: number): string =>
    new Date(ts).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    });

export const formatoFechaCorta = (iso: string): string =>
    formatoFechaCortaTs(fechaLocalATimestamp(iso));

export const formatoFechaLarga = (iso: string): string =>
    formatoFechaLargaTs(fechaLocalATimestamp(iso));

/**
 * Empareja fechas calendario (`YYYY-MM-DD`) con sus valores en puntos
 * `{ x: timestamp, y: valor }`, listos para una serie de `xaxis.type =
 * 'datetime'`.
 */
export function puntosSerieTemporal(
    fechas: string[],
    valores: number[],
): { x: number; y: number }[] {
    return fechas.map((fecha, i) => ({
        x: fechaLocalATimestamp(fecha),
        y: valores[i] ?? 0,
    }));
}

export function useGraficasDashboard() {
    const { resolvedAppearance } = useAppearance();
    const colores = ref<ColoresTema>(VALORES_POR_DEFECTO);

    let observador: MutationObserver | null = null;

    function releer(): void {
        colores.value = leerColoresTema();
    }

    onMounted(() => {
        releer();

        // El tema (claro/oscuro) y la personalización visual global se
        // aplican como clase/estilo inline sobre <html> — se releen los
        // colores cuando cambian, sin necesitar recargar la página.
        observador = new MutationObserver(releer);
        observador.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class', 'style'],
        });
    });

    onUnmounted(() => observador?.disconnect());

    const paleta = computed(() => [
        colores.value.primary,
        colores.value.chart2,
        colores.value.chart4,
        colores.value.chart3,
        colores.value.chart5,
    ]);

    const temaApex = computed<'light' | 'dark'>(() => resolvedAppearance.value);

    const prefiereMovimientoReducido = (): boolean =>
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function chartBase(
        tipo: NonNullable<ApexOptions['chart']>['type'],
    ): ApexOptions['chart'] {
        const animar = !prefiereMovimientoReducido();

        return {
            type: tipo,
            fontFamily: 'inherit',
            foreColor: colores.value.mutedForeground,
            background: 'transparent',
            toolbar: { show: false },
            zoom: { enabled: false },
            animations: {
                enabled: animar,
                easing: 'easeinout',
                speed: 300,
                animateGradually: { enabled: animar, delay: 80 },
                dynamicAnimation: { enabled: animar, speed: 300 },
            },
        };
    }

    function grid(): ApexOptions['grid'] {
        return {
            borderColor: colores.value.border,
            strokeDashArray: 3,
            padding: { left: 8, right: 8, top: 0 },
        };
    }

    function legend(): ApexOptions['legend'] {
        return {
            fontFamily: 'inherit',
            fontSize: '12px',
            position: 'top',
            horizontalAlign: 'right',
            labels: { colors: colores.value.mutedForeground },
            markers: { size: 6 },
            itemMargin: { horizontal: 10, vertical: 6 },
        };
    }

    function tooltip(): ApexOptions['tooltip'] {
        return {
            theme: temaApex.value,
            style: { fontFamily: 'inherit', fontSize: '12px' },
        };
    }

    function ejeEstilo(): { colors: string } {
        return { colors: colores.value.mutedForeground };
    }

    /**
     * Ticks enteros y "agradables" para conteos pequeños (entregas,
     * devoluciones…): con el `tickAmount` por defecto de Apex, un máximo de 1
     * produce pasos fraccionarios (0, 0.2, 0.4…1). Forzamos un `tickAmount`
     * acorde al máximo real de la serie.
     */
    function tickAmountEntero(maximo: number): number {
        return Math.max(1, Math.min(5, Math.ceil(maximo)));
    }

    /**
     * Serie temporal (línea/área) sobre un eje de fechas real
     * (`xaxis.type = 'datetime'`). Ideal para comparar 1-3 series a lo largo
     * de un rango de fechas (p. ej. "Entregas y devoluciones"). Los datos de
     * cada serie deben venir como `{x, y}` — usar `puntosSerieTemporal()`.
     */
    function opcionesArea(
        opciones: {
            formatoValor?: (valor: number) => string;
            formatoEjeY?: (valor: number) => string;
            colores?: string[];
            apilado?: boolean;
            maximoY?: number;
            tooltipY?: ApexTooltip['y'];
        } = {},
    ): ApexOptions {
        const fValor = opciones.formatoValor ?? formatoNumero;
        const fEjeY = opciones.formatoEjeY ?? fValor;
        const enteroPequeno =
            opciones.maximoY !== undefined && opciones.maximoY <= 10;

        return {
            chart: { ...chartBase('area'), stacked: opciones.apilado ?? false },
            colors: opciones.colores ?? paleta.value,
            dataLabels: { enabled: false },
            stroke: { width: 2, curve: 'straight' },
            fill: {
                type: 'gradient',
                gradient: {
                    opacityFrom: 0.14,
                    opacityTo: 0.02,
                    shadeIntensity: 1,
                    stops: [0, 90, 100],
                },
            },
            markers: {
                size: 0,
                hover: { size: 5, sizeOffset: 2 },
                strokeWidth: 0,
            },
            grid: grid(),
            legend: legend(),
            tooltip: {
                ...tooltip(),
                x: { formatter: (ts: number) => formatoFechaLargaTs(ts) },
                y: opciones.tooltipY ?? { formatter: fValor },
            },
            xaxis: {
                type: 'datetime',
                tickAmount: 6,
                labels: {
                    formatter: (valor: string, timestamp?: number) =>
                        timestamp ? formatoFechaCortaTs(timestamp) : valor,
                    style: ejeEstilo(),
                    datetimeUTC: false,
                },
                axisBorder: { color: colores.value.border },
                axisTicks: { color: colores.value.border },
            },
            yaxis: {
                min: 0,
                forceNiceScale: true,
                decimalsInFloat: 0,
                ...(enteroPequeno
                    ? { tickAmount: tickAmountEntero(opciones.maximoY ?? 1) }
                    : {}),
                labels: {
                    formatter: (valor: number) => fEjeY(Math.round(valor)),
                    style: ejeEstilo(),
                },
            },
            responsive: [
                {
                    breakpoint: 640,
                    options: { xaxis: { tickAmount: 4 } },
                },
            ],
        };
    }

    /**
     * Barras horizontales — mejor que verticales cuando las etiquetas
     * (nombres de almacén, categoría…) son largas.
     */
    function opcionesBarrasHorizontales(
        categorias: string[],
        opciones: {
            formatoValor?: (valor: number) => string;
            colores?: string[];
        } = {},
    ): ApexOptions {
        const fValor = opciones.formatoValor ?? formatoNumero;

        return {
            chart: chartBase('bar'),
            colors: opciones.colores ?? [colores.value.primary],
            dataLabels: {
                enabled: true,
                formatter: fValor,
                style: { colors: [colores.value.foreground] },
                offsetX: 8,
            },
            plotOptions: {
                bar: {
                    horizontal: true,
                    barHeight: '60%',
                    borderRadius: 4,
                    borderRadiusApplication: 'end',
                    distributed: false,
                },
            },
            grid: grid(),
            legend: { show: false },
            tooltip: { ...tooltip(), y: { formatter: fValor } },
            xaxis: {
                categories: categorias,
                labels: { formatter: fValor, style: ejeEstilo() },
                axisBorder: { color: colores.value.border },
                axisTicks: { color: colores.value.border },
            },
            yaxis: { labels: { style: ejeEstilo() } },
        };
    }

    /**
     * Donut — distribución de pocas categorías (≤ 6-7). Para catálogos más
     * grandes, usar `opcionesBarrasHorizontales` en su lugar (un donut con
     * demasiados pedazos deja de ser legible).
     */
    function opcionesDonut(
        etiquetas: string[],
        opciones: {
            formatoValor?: (valor: number) => string;
            colores?: string[];
            totalEtiqueta?: string;
        } = {},
    ): ApexOptions {
        const fValor = opciones.formatoValor ?? formatoNumero;

        return {
            chart: chartBase('donut'),
            labels: etiquetas,
            colors: opciones.colores ?? paleta.value,
            dataLabels: {
                enabled: true,
                formatter: (val: number) => `${Math.round(val)}%`,
                style: { fontFamily: 'inherit' },
            },
            stroke: { width: 2, colors: [colores.value.border] },
            legend: { ...legend(), position: 'bottom' },
            tooltip: { ...tooltip(), y: { formatter: fValor } },
            plotOptions: {
                pie: {
                    donut: {
                        size: '68%',
                        labels: {
                            show: true,
                            total: {
                                show: true,
                                label: opciones.totalEtiqueta ?? 'Total',
                                color: colores.value.mutedForeground,
                                formatter: (w) =>
                                    fValor(
                                        w.globals.seriesTotals.reduce(
                                            (a: number, b: number) => a + b,
                                            0,
                                        ),
                                    ),
                            },
                            value: {
                                color: colores.value.foreground,
                                formatter: (val: string) => fValor(Number(val)),
                            },
                        },
                    },
                },
            },
        };
    }

    return {
        colores,
        paleta,
        temaApex,
        formatoNumero,
        formatoNumeroCorto,
        formatoFechaCorta,
        formatoFechaLarga,
        colorEstadoUnidad,
        opcionesArea,
        opcionesBarrasHorizontales,
        opcionesDonut,
    };
}
