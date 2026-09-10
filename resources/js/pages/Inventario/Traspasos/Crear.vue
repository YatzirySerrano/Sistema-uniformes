<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    ChevronLeft,
    ChevronRight,
    Package,
    Plus,
    Trash2,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EmpresaAutorizada } from '@/types/sistema';

type OpcionAlmacen = { id: number; nombre: string; codigo: string | null };
type OpcionTalla = { id: number; valor: string; disponible?: number };
type OpcionActivo = {
    id: number;
    nombre: string;
    codigo: string | null;
    control: 'cantidad' | 'individual';
    usa_variantes: boolean;
    tallas: OpcionTalla[];
    disponible?: number;
};
type OpcionUnidad = {
    id: number;
    codigo: string;
    entregable: boolean;
    motivo_no_entregable: string | null;
};
type PreviewRenglon = {
    activo_origen_id: number;
    candidatos: { id: number; codigo: string | null; nombre: string }[];
    ambiguo: boolean;
    se_creara: boolean;
    activo_destino: {
        id: number;
        codigo: string | null;
        nombre: string;
    } | null;
};

const props = defineProps<{ empresasAutorizadas: EmpresaAutorizada[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inventario', href: '/inventario' },
            { title: 'Movimientos', href: '/inventario/movimientos' },
            { title: 'Nuevo traspaso', href: '/inventario/traspasos/crear' },
        ],
    },
});

const PASOS = [
    { n: 1, titulo: 'Origen y destino' },
    { n: 2, titulo: 'Artículos a traspasar' },
    { n: 3, titulo: 'Revisión y confirmación' },
] as const;
const paso = ref<1 | 2 | 3>(1);

// ---------------------------------------------------------------- Paso 1
const empresaOrigen = ref<EmpresaAutorizada | null>(null);
const almacenOrigen = ref<OpcionAlmacen | null>(null);
const empresaDestino = ref<EmpresaAutorizada | null>(null);
const almacenDestino = ref<OpcionAlmacen | null>(null);

const empresaOrigenId = computed(() => empresaOrigen.value?.id ?? null);
const empresaDestinoId = computed(() => empresaDestino.value?.id ?? null);
const esInterempresa = computed(
    () =>
        empresaOrigenId.value !== null &&
        empresaDestinoId.value !== null &&
        empresaOrigenId.value !== empresaDestinoId.value,
);
const mismoAlmacenMismaEmpresa = computed(
    () =>
        empresaOrigenId.value !== null &&
        empresaOrigenId.value === empresaDestinoId.value &&
        almacenOrigen.value !== null &&
        almacenOrigen.value.id === almacenDestino.value?.id,
);

async function buscarEmpresas(q: string): Promise<EmpresaAutorizada[]> {
    const t = q.trim().toLowerCase();
    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

function buscarAlmacenesDe(empresaId: () => number | null) {
    return async (
        q: string,
        signal?: AbortSignal,
    ): Promise<OpcionAlmacen[]> => {
        const id = empresaId();
        if (id === null) return [];
        const res = await fetch(
            `/almacenes/buscar?empresa_id=${id}&q=${encodeURIComponent(q)}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal,
            },
        );
        if (!res.ok) return [];
        return (await res.json()).almacenes ?? [];
    };
}
const buscarAlmacenesOrigen = buscarAlmacenesDe(() => empresaOrigenId.value);
const buscarAlmacenesDestino = buscarAlmacenesDe(() => empresaDestinoId.value);

function alCambiarEmpresaOrigen(e: EmpresaAutorizada | null): void {
    empresaOrigen.value = e;
    almacenOrigen.value = null;
    limpiarRenglones();
}
function alCambiarEmpresaDestino(e: EmpresaAutorizada | null): void {
    empresaDestino.value = e;
    almacenDestino.value = null;
    preview.value = {};
}

// ---------------------------------------------------------------- Paso 2
type FilaRenglon = {
    control: 'cantidad' | 'individual';
    activoSel: OpcionActivo | null;
    talla_id: number | null;
    cantidad: number;
    unidades: OpcionUnidad[];
    activo_destino_id: number | null;
};
const filas = reactive<FilaRenglon[]>([]);
const preview = ref<Record<number, PreviewRenglon>>({});

function limpiarRenglones(): void {
    filas.splice(0, filas.length);
    preview.value = {};
}
function agregarFila(): void {
    filas.push({
        control: 'cantidad',
        activoSel: null,
        talla_id: null,
        cantidad: 1,
        unidades: [],
        activo_destino_id: null,
    });
}
function quitarFila(i: number): void {
    filas.splice(i, 1);
    void refrescarPreview();
}

function buscarActivos(control: 'cantidad' | 'individual') {
    return async (q: string, signal?: AbortSignal): Promise<OpcionActivo[]> => {
        if (empresaOrigenId.value === null || !almacenOrigen.value) return [];
        const res = await fetch(
            `/activos/buscar?empresa_id=${empresaOrigenId.value}&almacen_id=${almacenOrigen.value.id}&control=${control}&q=${encodeURIComponent(q)}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal,
            },
        );
        if (!res.ok) return [];
        return (await res.json()).activos ?? [];
    };
}

function buscarUnidadesFila(i: number) {
    return async (q: string, signal?: AbortSignal): Promise<OpcionUnidad[]> => {
        const activoId = filas[i].activoSel?.id;
        if (!activoId || !almacenOrigen.value) return [];
        const res = await fetch(
            `/activos/unidades/buscar?activo_id=${activoId}&almacen_id=${almacenOrigen.value.id}&q=${encodeURIComponent(q)}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal,
            },
        );
        if (!res.ok) return [];
        const lista = ((await res.json()).unidades ?? []) as OpcionUnidad[];
        return lista.filter(
            (u) => !filas[i].unidades.some((x) => x.id === u.id),
        );
    };
}

function alElegirActivo(i: number, o: OpcionActivo | null): void {
    filas[i].activoSel = o;
    filas[i].talla_id = null;
    filas[i].unidades = [];
    filas[i].activo_destino_id = null;
    void refrescarPreview();
}
function agregarUnidad(i: number, u: OpcionUnidad | null): void {
    if (u && u.entregable && !filas[i].unidades.some((x) => x.id === u.id)) {
        filas[i].unidades.push(u);
    }
}
function quitarUnidad(i: number, id: number): void {
    filas[i].unidades = filas[i].unidades.filter((u) => u.id !== id);
}

let previewTimer: ReturnType<typeof setTimeout> | undefined;
function refrescarPreview(): void {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(async () => {
        const ids = [
            ...new Set(
                filas
                    .map((f) => f.activoSel?.id)
                    .filter((v): v is number => typeof v === 'number'),
            ),
        ];
        if (
            !esInterempresa.value ||
            empresaOrigenId.value === null ||
            empresaDestinoId.value === null ||
            ids.length === 0
        ) {
            preview.value = {};
            return;
        }
        const params = new URLSearchParams({
            empresa_origen_id: String(empresaOrigenId.value),
            empresa_destino_id: String(empresaDestinoId.value),
        });
        ids.forEach((id) => params.append('activo_ids[]', String(id)));
        const res = await fetch(
            `/inventario/traspasos/previsualizar?${params}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );
        if (!res.ok) return;
        const json = (await res.json()) as { renglones: PreviewRenglon[] };
        const mapa: Record<number, PreviewRenglon> = {};
        for (const r of json.renglones) mapa[r.activo_origen_id] = r;
        preview.value = mapa;
    }, 300);
}
watch(esInterempresa, () => refrescarPreview());

function previewDe(i: number): PreviewRenglon | null {
    const id = filas[i].activoSel?.id;
    return id != null ? (preview.value[id] ?? null) : null;
}
function ambiguoSinResolver(i: number): boolean {
    const p = previewDe(i);
    return !!p && p.ambiguo && filas[i].activo_destino_id === null;
}

// ---------------------------------------------------------------- Form / envío
type RenglonPayload = {
    control: 'cantidad' | 'individual';
    activo_origen_id: number | undefined;
    activo_destino_id: number | null;
    talla_id: number | null;
    cantidad: number | null;
    unidad_ids: number[] | null;
};

const form = useForm<{
    empresa_origen_id: number | null;
    almacen_origen_id: number | null;
    empresa_destino_id: number | null;
    almacen_destino_id: number | null;
    motivo: string;
    notas: string;
    renglones: RenglonPayload[];
}>({
    empresa_origen_id: null,
    almacen_origen_id: null,
    empresa_destino_id: null,
    almacen_destino_id: null,
    motivo: '',
    notas: '',
    renglones: [],
});
const errln = computed(() => form.errors as unknown as Record<string, string>);

const filaValida = (f: FilaRenglon): boolean =>
    f.activoSel !== null &&
    (f.control === 'cantidad'
        ? f.cantidad > 0 && (!f.activoSel.usa_variantes || f.talla_id !== null)
        : f.unidades.length > 0);

const puedeAvanzar1 = computed(
    () =>
        empresaOrigen.value !== null &&
        almacenOrigen.value !== null &&
        empresaDestino.value !== null &&
        almacenDestino.value !== null &&
        !mismoAlmacenMismaEmpresa.value,
);
const puedeAvanzar2 = computed(
    () =>
        filas.length > 0 &&
        filas.every(filaValida) &&
        !filas.some((_, i) => ambiguoSinResolver(i)),
);

function irA(n: 1 | 2 | 3): void {
    if (n >= 2 && !puedeAvanzar1.value) return;
    if (n === 3 && !puedeAvanzar2.value) return;
    paso.value = n;
}

function enviar(): void {
    if (!puedeAvanzar2.value) return;
    form.empresa_origen_id = empresaOrigen.value?.id ?? null;
    form.almacen_origen_id = almacenOrigen.value?.id ?? null;
    form.empresa_destino_id = empresaDestino.value?.id ?? null;
    form.almacen_destino_id = almacenDestino.value?.id ?? null;
    form.renglones = filas.map((f) => ({
        control: f.control,
        activo_origen_id: f.activoSel?.id,
        activo_destino_id: f.activo_destino_id,
        talla_id: f.control === 'cantidad' ? f.talla_id : null,
        cantidad: f.control === 'cantidad' ? f.cantidad : null,
        unidad_ids:
            f.control === 'individual' ? f.unidades.map((u) => u.id) : null,
    }));
    form.post('/inventario/traspasos', {
        preserveScroll: true,
        onError: () => {
            if (Object.keys(form.errors).some((k) => k.startsWith('renglones')))
                paso.value = 2;
            else paso.value = 1;
        },
    });
}
</script>

<template>
    <Head title="Nuevo traspaso" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Nuevo traspaso de inventario"
            descripcion="Mueve existencias entre almacenes de una misma empresa o entre empresas distintas. La operación es atómica: si algo falla, no se aplica nada."
        />

        <ol class="flex flex-wrap items-center gap-2 text-sm">
            <li
                v-for="(p, idx) in PASOS"
                :key="p.n"
                class="flex items-center gap-2"
            >
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-full border px-3 py-1.5 transition-colors"
                    :class="
                        paso === p.n
                            ? 'border-primary bg-primary text-primary-foreground'
                            : paso > p.n
                              ? 'border-primary/40 text-primary'
                              : 'text-muted-foreground'
                    "
                    @click="irA(p.n)"
                >
                    <span
                        class="flex size-5 items-center justify-center rounded-full border text-xs font-semibold"
                        >{{ p.n }}</span
                    >
                    {{ p.titulo }}
                </button>
                <ChevronRight
                    v-if="idx < PASOS.length - 1"
                    class="text-muted-foreground/50 size-4"
                />
            </li>
        </ol>

        <form class="space-y-6" @submit.prevent="enviar">
            <!-- ============ PASO 1 ============ -->
            <section
                v-show="paso === 1"
                class="grid gap-4 rounded-xl border p-4 sm:grid-cols-2"
            >
                <div class="grid gap-1.5">
                    <Label>Empresa origen</Label>
                    <BuscadorAsync
                        :model-value="empresaOrigen"
                        :buscar="buscarEmpresas"
                        :etiqueta="
                            (e) => (e as EmpresaAutorizada).nombre_comercial
                        "
                        placeholder="Selecciona la empresa origen"
                        placeholder-busqueda="Buscar empresa…"
                        :invalido="!!form.errors.empresa_origen_id"
                        @update:model-value="
                            (v) =>
                                alCambiarEmpresaOrigen(
                                    v as EmpresaAutorizada | null,
                                )
                        "
                    />
                    <InputError :message="form.errors.empresa_origen_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label>Almacén origen</Label>
                    <BuscadorAsync
                        :model-value="almacenOrigen"
                        :buscar="buscarAlmacenesOrigen"
                        :dependencia="empresaOrigenId"
                        :disabled="empresaOrigenId === null"
                        :etiqueta="(a) => (a as OpcionAlmacen).nombre"
                        :descripcion="(a) => (a as OpcionAlmacen).codigo ?? ''"
                        placeholder="Selecciona el almacén origen"
                        placeholder-busqueda="Buscar almacén…"
                        sin-resultados="Ningún almacén abastece a esta empresa."
                        :invalido="!!form.errors.almacen_origen_id"
                        @update:model-value="
                            (v) => {
                                almacenOrigen = v as OpcionAlmacen | null;
                                limpiarRenglones();
                            }
                        "
                    />
                    <InputError :message="form.errors.almacen_origen_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label>Empresa destino</Label>
                    <BuscadorAsync
                        :model-value="empresaDestino"
                        :buscar="buscarEmpresas"
                        :etiqueta="
                            (e) => (e as EmpresaAutorizada).nombre_comercial
                        "
                        placeholder="Selecciona la empresa destino"
                        placeholder-busqueda="Buscar empresa…"
                        :invalido="!!form.errors.empresa_destino_id"
                        @update:model-value="
                            (v) =>
                                alCambiarEmpresaDestino(
                                    v as EmpresaAutorizada | null,
                                )
                        "
                    />
                    <InputError :message="form.errors.empresa_destino_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label>Almacén destino</Label>
                    <BuscadorAsync
                        :model-value="almacenDestino"
                        :buscar="buscarAlmacenesDestino"
                        :dependencia="empresaDestinoId"
                        :disabled="empresaDestinoId === null"
                        :etiqueta="(a) => (a as OpcionAlmacen).nombre"
                        :descripcion="(a) => (a as OpcionAlmacen).codigo ?? ''"
                        placeholder="Selecciona el almacén destino"
                        placeholder-busqueda="Buscar almacén…"
                        sin-resultados="Ningún almacén abastece a esta empresa."
                        :invalido="!!form.errors.almacen_destino_id"
                        @update:model-value="
                            (v) => (almacenDestino = v as OpcionAlmacen | null)
                        "
                    />
                    <InputError :message="form.errors.almacen_destino_id" />
                </div>

                <p
                    v-if="esInterempresa"
                    class="text-muted-foreground bg-muted/40 rounded-md border p-2 text-xs sm:col-span-2"
                >
                    Traspaso <strong>entre empresas</strong>: cada activo se
                    homologará con su equivalente en la empresa destino (o se
                    creará uno nuevo). Los históricos de la empresa origen no se
                    modifican.
                </p>
                <p
                    v-if="mismoAlmacenMismaEmpresa"
                    class="rounded-md border border-amber-500/40 bg-amber-500/10 p-2 text-xs text-amber-700 sm:col-span-2 dark:text-amber-400"
                >
                    En un traspaso dentro de la misma empresa el almacén destino
                    debe ser distinto del origen.
                </p>
            </section>

            <!-- ============ PASO 2 ============ -->
            <div v-show="paso === 2" class="space-y-4">
                <InputError :message="errln['renglones']" />
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold">Artículos a traspasar</h2>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="agregarFila"
                    >
                        <Plus class="size-4" /> Agregar activo
                    </Button>
                </div>

                <p v-if="!filas.length" class="text-muted-foreground text-sm">
                    Agrega al menos un activo. Selecciona si se controla por
                    cantidad o por unidades identificadas.
                </p>

                <div
                    v-for="(fila, i) in filas"
                    :key="i"
                    class="space-y-3 rounded-xl border p-3"
                >
                    <div class="flex flex-wrap items-start gap-2">
                        <div class="w-40">
                            <Label class="text-xs">Control</Label>
                            <SelectSimple
                                :model-value="fila.control"
                                :opciones="[
                                    {
                                        valor: 'cantidad',
                                        etiqueta: 'Por cantidad',
                                    },
                                    {
                                        valor: 'individual',
                                        etiqueta: 'Unidades identificadas',
                                    },
                                ]"
                                @update:model-value="
                                    (v) => {
                                        fila.control = v as
                                            'cantidad' | 'individual';
                                        alElegirActivo(i, null);
                                    }
                                "
                            />
                        </div>
                        <div class="min-w-[12rem] flex-1">
                            <Label class="text-xs">Activo (origen)</Label>
                            <BuscadorAsync
                                :model-value="fila.activoSel"
                                :buscar="buscarActivos(fila.control)"
                                :dependencia="`${empresaOrigenId ?? ''}-${almacenOrigen?.id ?? ''}-${fila.control}`"
                                :disabled="!almacenOrigen"
                                :etiqueta="(a) => (a as OpcionActivo).nombre"
                                :descripcion="
                                    (a) => (a as OpcionActivo).codigo ?? ''
                                "
                                placeholder="Buscar activo…"
                                placeholder-busqueda="Buscar por nombre o código"
                                :invalido="
                                    !!errln[`renglones.${i}.activo_origen_id`]
                                "
                                @update:model-value="
                                    (v) =>
                                        alElegirActivo(
                                            i,
                                            v as OpcionActivo | null,
                                        )
                                "
                            />
                            <InputError
                                :message="
                                    errln[`renglones.${i}.activo_origen_id`]
                                "
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="mt-5"
                            :aria-label="`Quitar renglón ${i + 1}`"
                            @click="quitarFila(i)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>

                    <!-- cantidad -->
                    <div
                        v-if="fila.control === 'cantidad'"
                        class="flex flex-wrap items-start gap-2"
                    >
                        <div v-if="fila.activoSel?.usa_variantes" class="w-40">
                            <Label class="text-xs">Variante</Label>
                            <SelectSimple
                                :model-value="fila.talla_id"
                                :opciones="
                                    (fila.activoSel?.tallas ?? []).map((t) => ({
                                        valor: t.id,
                                        etiqueta: t.valor,
                                    }))
                                "
                                placeholder="Variante"
                                :invalido="!!errln[`renglones.${i}.talla_id`]"
                                @update:model-value="
                                    (v) => (fila.talla_id = v as number | null)
                                "
                            />
                            <InputError
                                :message="errln[`renglones.${i}.talla_id`]"
                            />
                        </div>
                        <div class="w-32">
                            <Label class="text-xs">Cantidad</Label>
                            <Input
                                v-model.number="fila.cantidad"
                                type="number"
                                min="1"
                                class="h-9"
                            />
                            <p
                                v-if="fila.activoSel?.disponible != null"
                                class="text-muted-foreground mt-0.5 text-[11px]"
                            >
                                Disponible: {{ fila.activoSel.disponible }}
                            </p>
                            <InputError
                                :message="errln[`renglones.${i}.cantidad`]"
                            />
                        </div>
                    </div>

                    <!-- individual -->
                    <div v-else class="space-y-2">
                        <Label class="text-xs">Unidades a traspasar</Label>
                        <BuscadorAsync
                            :model-value="null"
                            :buscar="buscarUnidadesFila(i)"
                            :dependencia="`${fila.activoSel?.id ?? ''}-${almacenOrigen?.id ?? ''}`"
                            :disabled="!fila.activoSel"
                            :deshabilitar-opcion="
                                (u) =>
                                    (u as OpcionUnidad).entregable
                                        ? false
                                        : ((u as OpcionUnidad)
                                              .motivo_no_entregable ??
                                          'No disponible')
                            "
                            :etiqueta="(u) => (u as OpcionUnidad).codigo"
                            placeholder="Agregar unidad por código…"
                            placeholder-busqueda="Buscar por código"
                            sin-resultados="Sin unidades disponibles de este activo en el almacén origen."
                            @update:model-value="
                                (v) =>
                                    agregarUnidad(i, v as OpcionUnidad | null)
                            "
                        />
                        <ul
                            v-if="fila.unidades.length"
                            class="flex flex-wrap gap-1.5"
                        >
                            <li
                                v-for="u in fila.unidades"
                                :key="u.id"
                                class="bg-muted flex items-center gap-1 rounded-md px-2 py-1 text-xs"
                            >
                                {{ u.codigo }}
                                <button
                                    type="button"
                                    :aria-label="`Quitar unidad ${u.codigo}`"
                                    @click="quitarUnidad(i, u.id)"
                                >
                                    <X class="size-3" />
                                </button>
                            </li>
                        </ul>
                        <InputError
                            :message="errln[`renglones.${i}.unidad_ids`]"
                        />
                    </div>

                    <!-- preview destino (interempresa) -->
                    <div
                        v-if="esInterempresa && previewDe(i)"
                        class="bg-muted/40 rounded-md border p-2 text-xs"
                    >
                        <template v-if="previewDe(i)?.activo_destino">
                            <span class="text-muted-foreground"
                                >Activo destino:</span
                            >
                            {{ previewDe(i)?.activo_destino?.nombre }}
                            <span class="text-muted-foreground"
                                >·
                                {{ previewDe(i)?.activo_destino?.codigo }}
                                (existente)</span
                            >
                        </template>
                        <template v-else-if="previewDe(i)?.se_creara">
                            <span class="text-muted-foreground">
                                No existe en la empresa destino. Se creará
                                automáticamente al confirmar.
                            </span>
                        </template>
                        <template v-else-if="previewDe(i)?.ambiguo">
                            <p
                                class="flex items-center gap-1 font-medium text-amber-700 dark:text-amber-400"
                            >
                                <TriangleAlert class="size-3.5 shrink-0" />
                                Hay
                                {{ previewDe(i)?.candidatos.length }} activos
                                que coinciden en la empresa destino. Elige uno:
                            </p>
                            <SelectSimple
                                class="mt-1"
                                :model-value="fila.activo_destino_id"
                                :opciones="
                                    (previewDe(i)?.candidatos ?? []).map(
                                        (c) => ({
                                            valor: c.id,
                                            etiqueta: `${c.nombre} · ${c.codigo ?? ''}`,
                                        }),
                                    )
                                "
                                placeholder="Selecciona el activo destino"
                                @update:model-value="
                                    (v) =>
                                        (fila.activo_destino_id = v as
                                            number | null)
                                "
                            />
                        </template>
                    </div>
                </div>
            </div>

            <!-- ============ PASO 3 ============ -->
            <div v-show="paso === 3" class="space-y-4">
                <section class="rounded-xl border p-4 text-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{
                            empresaOrigen?.nombre_comercial
                        }}</span>
                        <span class="text-muted-foreground">{{
                            almacenOrigen?.nombre
                        }}</span>
                        <ArrowRight class="text-muted-foreground size-4" />
                        <span class="font-medium">{{
                            empresaDestino?.nombre_comercial
                        }}</span>
                        <span class="text-muted-foreground">{{
                            almacenDestino?.nombre
                        }}</span>
                    </div>
                    <ul class="mt-3 divide-y">
                        <li
                            v-for="(fila, i) in filas"
                            :key="i"
                            class="flex flex-wrap items-center gap-2 py-2"
                        >
                            <Package
                                class="text-muted-foreground size-4 shrink-0"
                            />
                            <span class="font-medium">{{
                                fila.activoSel?.nombre
                            }}</span>
                            <span
                                v-if="fila.control === 'cantidad'"
                                class="text-muted-foreground"
                            >
                                {{
                                    fila.activoSel?.tallas.find(
                                        (t) => t.id === fila.talla_id,
                                    )?.valor ?? ''
                                }}
                                · {{ fila.cantidad }}
                            </span>
                            <span v-else class="text-muted-foreground">
                                {{ fila.unidades.length }} unidad(es):
                                {{
                                    fila.unidades
                                        .map((u) => u.codigo)
                                        .join(', ')
                                }}
                            </span>
                            <span
                                v-if="
                                    esInterempresa &&
                                    previewDe(i)?.se_creara &&
                                    fila.activo_destino_id === null
                                "
                                class="rounded bg-amber-500/10 px-1.5 py-0.5 text-[11px] text-amber-700 dark:text-amber-400"
                                >se creará en destino</span
                            >
                        </li>
                    </ul>
                </section>

                <div class="grid gap-1.5 sm:max-w-md">
                    <Label for="motivo">Motivo (opcional)</Label>
                    <Input id="motivo" v-model="form.motivo" class="h-9" />
                    <InputError :message="form.errors.motivo" />
                </div>
                <div class="grid gap-1.5 sm:max-w-md">
                    <Label for="notas">Notas (opcional)</Label>
                    <textarea
                        id="notas"
                        v-model="form.notas"
                        rows="2"
                        class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    />
                </div>
            </div>

            <!-- ============ Navegación ============ -->
            <div class="flex flex-wrap items-center gap-3">
                <Button
                    v-if="paso > 1"
                    type="button"
                    variant="outline"
                    @click="irA((paso - 1) as 1 | 2 | 3)"
                >
                    <ChevronLeft class="size-4" /> Atrás
                </Button>
                <Button
                    v-if="paso === 1"
                    type="button"
                    :disabled="!puedeAvanzar1"
                    @click="irA(2)"
                >
                    Siguiente <ChevronRight class="size-4" />
                </Button>
                <Button
                    v-else-if="paso === 2"
                    type="button"
                    :disabled="!puedeAvanzar2"
                    @click="irA(3)"
                >
                    Revisar <ChevronRight class="size-4" />
                </Button>
                <Button
                    v-else
                    type="submit"
                    :disabled="!puedeAvanzar2 || form.processing"
                >
                    Confirmar traspaso
                </Button>
                <Button variant="ghost" as-child>
                    <Link href="/inventario/movimientos">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
