<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, Boxes, ClipboardList, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import DatePicker from '@/components/sistema/DatePicker.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import GraficaBarras from '@/components/sistema/graficas/GraficaBarras.vue';
import GraficaLineas from '@/components/sistema/graficas/GraficaLineas.vue';
import TarjetaKpi from '@/components/sistema/graficas/TarjetaKpi.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { claseRellenoEstadoVisibleUnidad } from '@/lib/estadoVisibleUnidad';
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
        estado_etiqueta: string;
        fecha_entrega: string;
    }[];
    stock_bajo_detalle: {
        activo: string;
        talla: string | null;
        almacen: string;
        cantidad: number;
        minimo: number;
    }[];
};

const props = defineProps<{
    resumen: Resumen | null;
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
    sinEmpresa: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }],
    },
});

const empresaSeleccionada = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === props.filtros.empresa_id) ??
        null,
);
const empresaId = computed(() => empresaSeleccionada.value?.id ?? '');
const sucursalSeleccionada = ref<Opcion | null>(props.sucursalSeleccionada);
const almacenSeleccionado = ref<Opcion | null>(props.almacenSeleccionado);
const desde = ref(props.filtros.desde);
const hasta = ref(props.filtros.hasta);

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();
    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

async function buscarSucursales(termino: string, signal?: AbortSignal) {
    if (!empresaId.value) {
        return [];
    }
    const res = await fetch(
        `/sucursales/buscar?empresa_id=${empresaId.value}&q=${encodeURIComponent(termino)}`,
        { signal },
    );
    const datos = await res.json();
    return datos.sucursales as Opcion[];
}

async function buscarAlmacenes(termino: string, signal?: AbortSignal) {
    if (!empresaId.value) {
        return [];
    }
    const res = await fetch(
        `/almacenes/buscar?empresa_id=${empresaId.value}&q=${encodeURIComponent(termino)}`,
        { signal },
    );
    const datos = await res.json();
    return datos.almacenes as Opcion[];
}

// Cambiar de empresa invalida sucursal/almacén elegidos (pertenecen a la
// empresa anterior): se limpian en vez de conservarlos incompatibles.
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
                { preserveState: true, replace: true, preserveScroll: true },
            );
        }, 300);
    },
);

function limpiarFiltros() {
    sucursalSeleccionada.value = null;
    almacenSeleccionado.value = null;
    const hoy = new Date().toISOString().slice(0, 10);
    const hace30 = new Date(Date.now() - 29 * 86_400_000)
        .toISOString()
        .slice(0, 10);
    desde.value = hace30;
    hasta.value = hoy;
}

function fechaCorta(iso: string) {
    return new Date(`${iso}T00:00:00`).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'short',
    });
}

const seriesUnidades = computed(
    () =>
        props.resumen?.series.unidades_por_estado.map((u) => ({
            etiqueta: u.etiqueta,
            valor: u.total,
            colorClase: claseRellenoEstadoVisibleUnidad(u.estado),
        })) ?? [],
);

const seriesAlmacen = computed(
    () =>
        props.resumen?.series.existencias_por_almacen.map((a) => ({
            etiqueta: a.almacen,
            valor: a.total,
        })) ?? [],
);

const seriesCategoria = computed(
    () =>
        props.resumen?.series.stock_por_categoria.map((c) => ({
            etiqueta: c.categoria,
            valor: c.total,
        })) ?? [],
);
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Dashboard</h1>
            <p class="text-muted-foreground text-sm">
                Resumen operativo de la empresa: entregas, devoluciones,
                inventario y unidades identificadas.
            </p>
        </div>

        <Card>
            <CardContent class="flex flex-wrap items-end gap-3 pt-6">
                <label
                    v-if="empresasAutorizadas.length > 1"
                    class="flex flex-col gap-1 text-sm"
                >
                    <span class="text-muted-foreground text-xs">Empresa</span>
                    <BuscadorAsync
                        v-model="empresaSeleccionada"
                        :buscar="buscarEmpresas"
                        :etiqueta="(e) => String(e.nombre_comercial)"
                        placeholder="Selecciona una empresa"
                        placeholder-busqueda="Buscar empresa…"
                        class="w-56"
                    />
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-muted-foreground text-xs">Desde</span>
                    <DatePicker v-model="desde" class="w-40" />
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-muted-foreground text-xs">Hasta</span>
                    <DatePicker v-model="hasta" class="w-40" />
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-muted-foreground text-xs"
                        >Sucursal (opcional)</span
                    >
                    <BuscadorAsync
                        v-model="sucursalSeleccionada"
                        :buscar="buscarSucursales"
                        :etiqueta="(s) => String(s.nombre)"
                        :disabled="!empresaId"
                        :dependencia="empresaId"
                        :placeholder="
                            empresaId
                                ? 'Todas las sucursales'
                                : 'Elige una empresa'
                        "
                        placeholder-busqueda="Buscar sucursal…"
                        class="w-52"
                    />
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-muted-foreground text-xs"
                        >Almacén (opcional)</span
                    >
                    <BuscadorAsync
                        v-model="almacenSeleccionado"
                        :buscar="buscarAlmacenes"
                        :etiqueta="(a) => String(a.nombre)"
                        :disabled="!empresaId"
                        :dependencia="empresaId"
                        :placeholder="
                            empresaId
                                ? 'Todos los almacenes'
                                : 'Elige una empresa'
                        "
                        placeholder-busqueda="Buscar almacén…"
                        class="w-52"
                    />
                </label>
                <Button variant="outline" size="sm" @click="limpiarFiltros"
                    >Limpiar filtros</Button
                >
            </CardContent>
        </Card>

        <EstadoVacio
            v-if="sinEmpresa || !resumen"
            titulo="No hay ninguna empresa que mostrar"
            descripcion="No tienes empresas asignadas todavía."
        />

        <template v-else>
            <!--
                Sólo 4 KPIs principales (tamaño de operación, inventario,
                actividad, alertas) — el resto de los datos (unidades por
                estado, devoluciones, almacenes activos…) sigue disponible en
                `resumen.kpis`/`resumen.series` para las gráficas de abajo, no
                se eliminó del backend.
            -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <TarjetaKpi
                    titulo="Colaboradores activos"
                    :valor="resumen.kpis.colaboradores_activos"
                    :icono="Users"
                    color-clase="text-blue-600"
                />
                <TarjetaKpi
                    titulo="Existencias disponibles"
                    :valor="resumen.kpis.existencias_disponibles"
                    :icono="Boxes"
                    color-clase="text-cyan-600"
                    ayuda="Suma de cantidades en existencia de activos por cantidad (no incluye unidades identificadas)."
                />
                <TarjetaKpi
                    titulo="Entregas del periodo"
                    :valor="resumen.kpis.entregas_periodo"
                    :icono="ClipboardList"
                    color-clase="text-blue-600"
                />
                <TarjetaKpi
                    titulo="Activos con stock bajo"
                    :valor="resumen.kpis.activos_stock_bajo"
                    :icono="AlertTriangle"
                    :color-clase="
                        resumen.kpis.activos_stock_bajo
                            ? 'text-amber-500'
                            : 'text-muted-foreground'
                    "
                />
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Entregas por periodo</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <GraficaLineas
                            :puntos="resumen.series.entregas_por_periodo"
                            :series="[
                                {
                                    clave: 'total',
                                    etiqueta: 'Entregas',
                                    claseTrazo: 'stroke-chart-1',
                                },
                            ]"
                            :formato-eje="fechaCorta"
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Devoluciones por periodo</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <GraficaLineas
                            :puntos="resumen.series.devoluciones_por_periodo"
                            :series="[
                                {
                                    clave: 'total',
                                    etiqueta: 'Devoluciones',
                                    claseTrazo: 'stroke-chart-4',
                                },
                            ]"
                            :formato-eje="fechaCorta"
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Movimientos de inventario por periodo</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <GraficaLineas
                            :puntos="resumen.series.movimientos_por_periodo"
                            :series="[
                                {
                                    clave: 'entradas',
                                    etiqueta: 'Entradas',
                                    claseTrazo: 'stroke-emerald-500',
                                },
                                {
                                    clave: 'salidas',
                                    etiqueta: 'Salidas',
                                    claseTrazo: 'stroke-rose-500',
                                },
                            ]"
                            :formato-eje="fechaCorta"
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Unidades por estado visible</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <GraficaBarras :datos="seriesUnidades" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Existencias por almacén</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <GraficaBarras :datos="seriesAlmacen" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Stock por categoría</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <GraficaBarras :datos="seriesCategoria" />
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
                            No hay entregas registradas en este periodo.
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
                            v-if="resumen.stock_bajo_detalle.length"
                            class="size-4 text-amber-500"
                        />
                    </CardHeader>
                    <CardContent class="space-y-2.5">
                        <p
                            v-if="!resumen.stock_bajo_detalle.length"
                            class="text-muted-foreground text-sm"
                        >
                            Sin alertas de inventario.
                        </p>
                        <div
                            v-for="(s, i) in resumen.stock_bajo_detalle"
                            :key="i"
                            class="space-y-1"
                        >
                            <div
                                class="flex items-center justify-between text-sm"
                            >
                                <span class="min-w-0 truncate">
                                    {{ s.activo }}
                                    <span
                                        v-if="s.talla"
                                        class="text-muted-foreground"
                                        >· {{ s.talla }}</span
                                    >
                                    <span
                                        class="text-muted-foreground block text-xs"
                                        >{{ s.almacen }}</span
                                    >
                                </span>
                                <span class="shrink-0 font-medium"
                                    >{{ s.cantidad }} / {{ s.minimo }}</span
                                >
                            </div>
                            <div
                                class="bg-muted h-1.5 w-full overflow-hidden rounded-full"
                            >
                                <div
                                    class="h-full rounded-full bg-amber-500"
                                    :style="{
                                        width: `${Math.min(100, (s.cantidad / Math.max(1, s.minimo)) * 100)}%`,
                                    }"
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </template>
    </div>
</template>
