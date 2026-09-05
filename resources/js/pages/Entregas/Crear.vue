<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import DatePicker from '@/components/sistema/DatePicker.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type OpcionEmpresa = {
    id: number;
    nombre_comercial: string;
    codigo: string | null;
};
type OpcionSucursal = { id: number; nombre: string };

type OpcionColaborador = {
    id: number;
    nombre_completo: string;
    numero_empleado: string;
    empresa_id: number;
    sucursal_id: number;
};

type OpcionAlmacen = { id: number; nombre: string; codigo: string | null };

type OpcionActivo = {
    id: number;
    nombre: string;
    codigo: string | null;
    tipo: string | null;
    categoria: string | null;
    control: 'cantidad' | 'individual';
    usa_variantes: boolean;
    tallas: { id: number; valor: string; disponible?: number }[];
    disponible?: number;
};

type OpcionUnidad = {
    id: number;
    codigo: string;
    entregable: boolean;
    motivo_no_entregable: string | null;
};

type ComponenteVarianteLibre = {
    componente_id: number;
    activo_id: number;
    activo_nombre: string | null;
    tallas: { id: number; valor: string }[];
};

type OpcionConjunto = {
    id: number;
    nombre: string;
    codigo: string | null;
    componentes_variante_libre: ComponenteVarianteLibre[];
    disponible: number | null;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Entregas', href: '/entregas' },
            { title: 'Nueva entrega', href: '/entregas/crear' },
        ],
    },
});

const hoy = new Date().toISOString().slice(0, 10);

// ------------------------------------------------------------------
// Empresa → Sucursal → Colaborador → Almacén de origen
// ------------------------------------------------------------------
const empresaSel = ref<OpcionEmpresa | null>(null);
const sucursalSel = ref<OpcionSucursal | null>(null);
const colaboradorSel = ref<OpcionColaborador | null>(null);
const almacenSel = ref<OpcionAlmacen | null>(null);
const empresaId = computed(() => empresaSel.value?.id ?? null);
const sucursalId = computed(() => sucursalSel.value?.id ?? null);

async function buscarEmpresas(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionEmpresa[]> {
    const res = await fetch(`/empresas/buscar?q=${encodeURIComponent(q)}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];
    return (await res.json()).empresas ?? [];
}

async function buscarSucursales(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionSucursal[]> {
    if (empresaId.value === null) return [];
    const res = await fetch(
        `/sucursales/buscar?empresa_id=${empresaId.value}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).sucursales ?? [];
}

async function buscarColaboradores(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionColaborador[]> {
    if (empresaId.value === null || sucursalId.value === null) return [];
    const res = await fetch(
        `/colaboradores/buscar?empresa_id=${empresaId.value}&sucursal_id=${sucursalId.value}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).colaboradores ?? [];
}

async function buscarAlmacenes(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionAlmacen[]> {
    if (empresaId.value === null) return [];
    const res = await fetch(
        `/almacenes/buscar?empresa_id=${empresaId.value}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).almacenes ?? [];
}

function alElegirEmpresa(o: OpcionEmpresa | null): void {
    empresaSel.value = o;
    sucursalSel.value = null;
    colaboradorSel.value = null;
    almacenSel.value = null;
    form.colaborador_id = '';
    form.almacen_id = null;
    limpiarRenglones();
    form.clearErrors();
}

function alElegirSucursal(o: OpcionSucursal | null): void {
    sucursalSel.value = o;
    colaboradorSel.value = null;
    form.colaborador_id = '';
    form.clearErrors('colaborador_id');
}

function alElegirColaborador(o: OpcionColaborador | null): void {
    colaboradorSel.value = o;
    form.colaborador_id = o?.id ?? '';
    form.clearErrors('colaborador_id');
}

const avisoAlmacenCambiado = ref(false);

function alElegirAlmacen(o: OpcionAlmacen | null): void {
    const habiaRenglones =
        form.activos.length > 0 ||
        form.unidades.length > 0 ||
        form.conjuntos.length > 0;

    almacenSel.value = o;
    form.almacen_id = o?.id ?? null;
    form.clearErrors('almacen_id');

    if (habiaRenglones) {
        limpiarRenglones();
        avisoAlmacenCambiado.value = true;
    }

    void cargarDisponibilidad();
}

function limpiarRenglones(): void {
    form.activos = [];
    form.unidades = [];
    form.conjuntos = [];
    activosUI.splice(0, activosUI.length);
    unidadesUI.splice(0, unidadesUI.length);
    conjuntosUI.splice(0, conjuntosUI.length);
    disponibilidad.value = {};
}

// ------------------------------------------------------------------
// Formulario
// ------------------------------------------------------------------
type FilaActivo = {
    activo_id: number | '';
    talla_id: number | null;
    cantidad: number;
};
type FilaUnidad = { activo_id: number | ''; unidad_activo_id: number | '' };
type FilaConjunto = {
    conjunto_id: number | '';
    cantidad: number;
    variantes: Record<number, number | null>;
};

const form = useForm<{
    colaborador_id: number | '';
    almacen_id: number | null;
    fecha_entrega: string;
    notas: string;
    activos: FilaActivo[];
    unidades: FilaUnidad[];
    conjuntos: FilaConjunto[];
}>({
    colaborador_id: '',
    almacen_id: null,
    fecha_entrega: hoy,
    notas: '',
    activos: [],
    unidades: [],
    conjuntos: [],
});

/** Acceso laxo a errores anidados (`activos.0.talla_id`, `unidades.0.unidad_activo_id`…). */
const erroresLaxos = computed(
    () => form.errors as unknown as Record<string, string>,
);

// --- Disponibilidad general (hint agregado, el backend siempre revalida) ---
const disponibilidad = ref<Record<string, number>>({});

async function cargarDisponibilidad(): Promise<void> {
    disponibilidad.value = {};
    if (empresaId.value === null || !almacenSel.value) return;
    const res = await fetch(
        `/entregas/disponibilidad?empresa_id=${empresaId.value}&almacen_id=${almacenSel.value.id}`,
        { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
    );
    if (!res.ok) return;
    const json = (await res.json()) as {
        saldos: {
            activo_id: number;
            talla_id: number | null;
            disponible: number;
        }[];
    };
    const mapa: Record<string, number> = {};
    for (const s of json.saldos) {
        mapa[`${s.activo_id}-${s.talla_id ?? '0'}`] = s.disponible;
    }
    disponibilidad.value = mapa;
}

// --- Activos sueltos (por cantidad) --------------------------------
const activosUI = reactive<{ sel: OpcionActivo | null }[]>([]);

async function buscarActivosCantidad(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionActivo[]> {
    if (empresaId.value === null || !almacenSel.value) return [];
    const res = await fetch(
        `/activos/buscar?empresa_id=${empresaId.value}&almacen_id=${almacenSel.value.id}&control=cantidad&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).activos ?? [];
}

function activoSinExistencias(item: OpcionActivo): string | false {
    if (item.usa_variantes) {
        // Con variantes: sólo se bloquea si NINGUNA variante tiene existencia.
        const algunaConStock = item.tallas.some((t) => (t.disponible ?? 0) > 0);

        return algunaConStock
            ? false
            : `Sin existencias en ${almacenSel.value?.nombre ?? 'este almacén'}`;
    }

    return (item.disponible ?? 0) > 0
        ? false
        : `Sin existencias en ${almacenSel.value?.nombre ?? 'este almacén'}`;
}

function agregarActivo(): void {
    form.activos.push({ activo_id: '', talla_id: null, cantidad: 1 });
    activosUI.push({ sel: null });
}

function quitarActivo(i: number): void {
    form.activos.splice(i, 1);
    activosUI.splice(i, 1);
}

function alElegirActivo(i: number, o: OpcionActivo | null): void {
    activosUI[i].sel = o;
    form.activos[i].activo_id = o?.id ?? '';
    form.activos[i].talla_id = null;
    form.clearErrors(`activos.${i}.activo_id`, `activos.${i}.talla_id`);
}

function disponibleDe(
    activoId: number | '',
    tallaId: number | null,
): number | null {
    if (!activoId) return null;
    const clave = `${activoId}-${tallaId ?? '0'}`;
    return clave in disponibilidad.value ? disponibilidad.value[clave] : null;
}

// --- Unidades de seguimiento individual -----------------------------
const unidadesUI = reactive<
    { activoSel: OpcionActivo | null; unidadSel: OpcionUnidad | null }[]
>([]);

async function buscarActivosIndividual(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionActivo[]> {
    if (empresaId.value === null || !almacenSel.value) return [];
    const res = await fetch(
        `/activos/buscar?empresa_id=${empresaId.value}&almacen_id=${almacenSel.value.id}&control=individual&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).activos ?? [];
}

function activoIndividualSinExistencias(item: OpcionActivo): string | false {
    return (item.disponible ?? 0) > 0
        ? false
        : `Sin unidades disponibles en ${almacenSel.value?.nombre ?? 'este almacén'}`;
}

function buscarUnidades(i: number) {
    return async (q: string, signal?: AbortSignal): Promise<OpcionUnidad[]> => {
        const activoId = unidadesUI[i].activoSel?.id;
        if (!activoId || !almacenSel.value) return [];
        const res = await fetch(
            `/activos/unidades/buscar?activo_id=${activoId}&almacen_id=${almacenSel.value.id}&q=${encodeURIComponent(q)}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal,
            },
        );
        if (!res.ok) return [];
        return (await res.json()).unidades ?? [];
    };
}

function unidadNoEntregable(item: OpcionUnidad): string | false {
    return item.entregable
        ? false
        : (item.motivo_no_entregable ?? 'No disponible');
}

function agregarUnidad(): void {
    form.unidades.push({ activo_id: '', unidad_activo_id: '' });
    unidadesUI.push({ activoSel: null, unidadSel: null });
}

function quitarUnidad(i: number): void {
    form.unidades.splice(i, 1);
    unidadesUI.splice(i, 1);
}

function alElegirActivoUnidad(i: number, o: OpcionActivo | null): void {
    unidadesUI[i].activoSel = o;
    unidadesUI[i].unidadSel = null;
    form.unidades[i].activo_id = o?.id ?? '';
    form.unidades[i].unidad_activo_id = '';
    form.clearErrors(`unidades.${i}.unidad_activo_id`);
}

function alElegirUnidad(i: number, o: OpcionUnidad | null): void {
    unidadesUI[i].unidadSel = o;
    form.unidades[i].unidad_activo_id = o?.id ?? '';
    form.clearErrors(`unidades.${i}.unidad_activo_id`);
}

// --- Conjuntos --------------------------------------------------------
const conjuntosUI = reactive<{ sel: OpcionConjunto | null }[]>([]);

async function buscarConjuntos(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionConjunto[]> {
    if (empresaId.value === null || !almacenSel.value) return [];
    const res = await fetch(
        `/conjuntos/buscar?empresa_id=${empresaId.value}&almacen_id=${almacenSel.value.id}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).conjuntos ?? [];
}

function conjuntoSinDisponibilidad(item: OpcionConjunto): string | false {
    return (item.disponible ?? 0) > 0
        ? false
        : 'Sin disponibilidad en este almacén';
}

function agregarConjunto(): void {
    form.conjuntos.push({ conjunto_id: '', cantidad: 1, variantes: {} });
    conjuntosUI.push({ sel: null });
}

function quitarConjunto(i: number): void {
    form.conjuntos.splice(i, 1);
    conjuntosUI.splice(i, 1);
}

function alElegirConjunto(i: number, o: OpcionConjunto | null): void {
    conjuntosUI[i].sel = o;
    form.conjuntos[i].conjunto_id = o?.id ?? '';
    form.conjuntos[i].variantes = {};
    form.clearErrors(`conjuntos.${i}.conjunto_id`);
}

function enviar(): void {
    form.post('/entregas');
}
</script>

<template>
    <Head title="Nueva entrega" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Registrar entrega"
            descripcion="Empresa → Sucursal → Colaborador → Almacén de origen → activos. Cada paso acota al siguiente; el inventario se descuenta del almacén elegido."
        />

        <form class="space-y-6" @submit.prevent="enviar">
            <section class="grid gap-4 rounded-xl border p-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="empresa">Empresa</Label>
                    <BuscadorAsync
                        id="empresa"
                        :model-value="empresaSel"
                        :buscar="buscarEmpresas"
                        :etiqueta="(e) => (e as OpcionEmpresa).nombre_comercial"
                        :descripcion="(e) => (e as OpcionEmpresa).codigo ?? ''"
                        placeholder="Selecciona una empresa"
                        placeholder-busqueda="Buscar por nombre o código"
                        sin-resultados="No tienes empresas activas autorizadas."
                        @update:model-value="
                            (v) => alElegirEmpresa(v as OpcionEmpresa | null)
                        "
                    />
                </div>

                <div class="grid gap-1.5">
                    <Label for="sucursal">Sucursal</Label>
                    <BuscadorAsync
                        id="sucursal"
                        :model-value="sucursalSel"
                        :buscar="buscarSucursales"
                        :dependencia="empresaId"
                        :disabled="empresaId === null"
                        :etiqueta="(s) => (s as OpcionSucursal).nombre"
                        placeholder="Selecciona una sucursal"
                        placeholder-busqueda="Buscar sucursal por nombre"
                        sin-resultados="Esta empresa no tiene sucursales activas."
                        @update:model-value="
                            (v) => alElegirSucursal(v as OpcionSucursal | null)
                        "
                    />
                    <p
                        v-if="empresaId === null"
                        class="text-muted-foreground text-xs"
                    >
                        Selecciona primero una empresa.
                    </p>
                </div>

                <div class="grid gap-1.5 sm:col-span-2">
                    <Label for="colaborador">Colaborador</Label>
                    <BuscadorAsync
                        id="colaborador"
                        :model-value="colaboradorSel"
                        :buscar="buscarColaboradores"
                        :dependencia="`${empresaId ?? ''}-${sucursalId ?? ''}`"
                        :disabled="empresaId === null || sucursalId === null"
                        :etiqueta="
                            (c) => (c as OpcionColaborador).nombre_completo
                        "
                        :descripcion="
                            (c) =>
                                `N.º ${(c as OpcionColaborador).numero_empleado}`
                        "
                        placeholder="Buscar colaborador por nombre o número de empleado"
                        placeholder-busqueda="Buscar por nombre o número de empleado"
                        sugerencia-busqueda="Escribe para buscar entre todos los colaboradores de esta sucursal."
                        sin-resultados="No hay colaboradores activos en esta sucursal."
                        :invalido="!!form.errors.colaborador_id"
                        @update:model-value="
                            (v) =>
                                alElegirColaborador(
                                    v as OpcionColaborador | null,
                                )
                        "
                    />
                    <InputError :message="form.errors.colaborador_id" />
                    <p
                        v-if="empresaId === null || sucursalId === null"
                        class="text-muted-foreground text-xs"
                    >
                        Selecciona empresa y sucursal.
                    </p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="fecha_entrega">Fecha de entrega</Label>
                    <DatePicker
                        id="fecha_entrega"
                        v-model="form.fecha_entrega"
                        :max="hoy"
                        :invalido="!!form.errors.fecha_entrega"
                    />
                    <InputError :message="form.errors.fecha_entrega" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="almacen">Almacén de origen</Label>
                    <BuscadorAsync
                        id="almacen"
                        :model-value="almacenSel"
                        :buscar="buscarAlmacenes"
                        :dependencia="empresaId"
                        :disabled="empresaId === null"
                        :etiqueta="(a) => (a as OpcionAlmacen).nombre"
                        :descripcion="(a) => (a as OpcionAlmacen).codigo ?? ''"
                        placeholder="Selecciona el almacén"
                        placeholder-busqueda="Buscar almacén por nombre"
                        sin-resultados="Ningún almacén abastece a esta empresa."
                        :invalido="!!form.errors.almacen_id"
                        @update:model-value="
                            (v) => alElegirAlmacen(v as OpcionAlmacen | null)
                        "
                    />
                    <InputError :message="form.errors.almacen_id" />
                    <p
                        v-if="empresaId === null"
                        class="text-muted-foreground text-xs"
                    >
                        Selecciona primero una empresa.
                    </p>
                </div>
            </section>

            <p
                v-if="avisoAlmacenCambiado"
                class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400"
            >
                Se limpiaron los elementos de la entrega porque cambió el
                almacén de origen: la disponibilidad correspondía al almacén
                anterior.
            </p>

            <InputError :message="erroresLaxos['items']" />

            <!-- Activos sueltos -->
            <section class="space-y-3 rounded-xl border p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Activos por cantidad</h2>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="!almacenSel"
                        @click="agregarActivo"
                    >
                        <Plus class="size-4" /> Agregar activo
                    </Button>
                </div>
                <p v-if="!almacenSel" class="text-muted-foreground text-sm">
                    Selecciona empresa y almacén para consultar existencias.
                </p>

                <div
                    v-for="(fila, i) in form.activos"
                    :key="i"
                    class="grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_140px_110px_auto] sm:items-start"
                >
                    <div>
                        <BuscadorAsync
                            :model-value="activosUI[i].sel"
                            :buscar="buscarActivosCantidad"
                            :dependencia="`${empresaId ?? ''}-${almacenSel?.id ?? ''}`"
                            :deshabilitar-opcion="
                                (a) => activoSinExistencias(a as OpcionActivo)
                            "
                            :etiqueta="(a) => (a as OpcionActivo).nombre"
                            :descripcion="
                                (a) =>
                                    (a as OpcionActivo).usa_variantes
                                        ? ((a as OpcionActivo).codigo ?? '')
                                        : `Disponible: ${(a as OpcionActivo).disponible ?? 0}`
                            "
                            placeholder="Buscar activo…"
                            placeholder-busqueda="Buscar por nombre o código"
                            :invalido="!!erroresLaxos[`activos.${i}.activo_id`]"
                            @update:model-value="
                                (v) =>
                                    alElegirActivo(i, v as OpcionActivo | null)
                            "
                        />
                        <InputError
                            :message="erroresLaxos[`activos.${i}.activo_id`]"
                        />
                    </div>
                    <div v-if="activosUI[i].sel?.usa_variantes">
                        <SelectSimple
                            :model-value="fila.talla_id"
                            :opciones="
                                (activosUI[i].sel?.tallas ?? []).map((t) => ({
                                    valor: t.id,
                                    etiqueta: `${t.valor}${(t.disponible ?? 0) > 0 ? ` (${t.disponible})` : ' (sin existencias)'}`,
                                    disabled: (t.disponible ?? 0) <= 0,
                                }))
                            "
                            placeholder="Variante"
                            :invalido="!!erroresLaxos[`activos.${i}.talla_id`]"
                            @update:model-value="
                                (v) => (fila.talla_id = v as number | null)
                            "
                        />
                        <InputError
                            :message="erroresLaxos[`activos.${i}.talla_id`]"
                        />
                    </div>
                    <div>
                        <Input
                            v-model.number="fila.cantidad"
                            type="number"
                            min="1"
                            :max="
                                disponibleDe(fila.activo_id, fila.talla_id) ??
                                undefined
                            "
                            class="h-9"
                        />
                        <p
                            v-if="
                                disponibleDe(fila.activo_id, fila.talla_id) !==
                                null
                            "
                            class="text-muted-foreground mt-0.5 text-[11px]"
                            :class="
                                (disponibleDe(fila.activo_id, fila.talla_id) ??
                                    0) < fila.cantidad
                                    ? 'text-destructive'
                                    : ''
                            "
                        >
                            Disponible:
                            {{ disponibleDe(fila.activo_id, fila.talla_id) }}
                        </p>
                        <InputError
                            :message="erroresLaxos[`activos.${i}.cantidad`]"
                        />
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        @click="quitarActivo(i)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
                <p
                    v-if="almacenSel && !form.activos.length"
                    class="text-muted-foreground text-sm"
                >
                    Sin activos por cantidad agregados.
                </p>
            </section>

            <!-- Unidades de seguimiento individual -->
            <section class="space-y-3 rounded-xl border p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">
                        Unidades identificadas
                    </h2>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="!almacenSel"
                        @click="agregarUnidad"
                    >
                        <Plus class="size-4" /> Agregar unidad
                    </Button>
                </div>

                <div
                    v-for="(fila, i) in form.unidades"
                    :key="i"
                    class="grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-start"
                >
                    <div>
                        <BuscadorAsync
                            :model-value="unidadesUI[i].activoSel"
                            :buscar="buscarActivosIndividual"
                            :dependencia="`${empresaId ?? ''}-${almacenSel?.id ?? ''}`"
                            :deshabilitar-opcion="
                                (a) =>
                                    activoIndividualSinExistencias(
                                        a as OpcionActivo,
                                    )
                            "
                            :etiqueta="(a) => (a as OpcionActivo).nombre"
                            :descripcion="
                                (a) => (a as OpcionActivo).codigo ?? ''
                            "
                            placeholder="Activo…"
                            placeholder-busqueda="Buscar por nombre o código"
                            @update:model-value="
                                (v) =>
                                    alElegirActivoUnidad(
                                        i,
                                        v as OpcionActivo | null,
                                    )
                            "
                        />
                    </div>
                    <div>
                        <BuscadorAsync
                            :model-value="unidadesUI[i].unidadSel"
                            :buscar="buscarUnidades(i)"
                            :dependencia="`${unidadesUI[i].activoSel?.id ?? ''}-${almacenSel?.id ?? ''}`"
                            :disabled="!unidadesUI[i].activoSel"
                            :deshabilitar-opcion="
                                (u) => unidadNoEntregable(u as OpcionUnidad)
                            "
                            :etiqueta="(u) => (u as OpcionUnidad).codigo"
                            placeholder="Unidad (código)…"
                            placeholder-busqueda="Buscar por código"
                            sin-resultados="Sin unidades de este activo en el almacén."
                            :invalido="
                                !!erroresLaxos[`unidades.${i}.unidad_activo_id`]
                            "
                            @update:model-value="
                                (v) =>
                                    alElegirUnidad(i, v as OpcionUnidad | null)
                            "
                        />
                        <InputError
                            :message="
                                erroresLaxos[`unidades.${i}.unidad_activo_id`]
                            "
                        />
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        @click="quitarUnidad(i)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
                <p
                    v-if="almacenSel && !form.unidades.length"
                    class="text-muted-foreground text-sm"
                >
                    Sin unidades identificadas agregadas.
                </p>
            </section>

            <!-- Conjuntos -->
            <section class="space-y-3 rounded-xl border p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Conjuntos</h2>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="!almacenSel"
                        @click="agregarConjunto"
                    >
                        <Plus class="size-4" /> Agregar conjunto
                    </Button>
                </div>

                <div
                    v-for="(fila, i) in form.conjuntos"
                    :key="i"
                    class="space-y-2 rounded-lg border p-3"
                >
                    <div
                        class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_110px_auto] sm:items-start"
                    >
                        <div>
                            <BuscadorAsync
                                :model-value="conjuntosUI[i].sel"
                                :buscar="buscarConjuntos"
                                :dependencia="`${empresaId ?? ''}-${almacenSel?.id ?? ''}`"
                                :deshabilitar-opcion="
                                    (c) =>
                                        conjuntoSinDisponibilidad(
                                            c as OpcionConjunto,
                                        )
                                "
                                :etiqueta="(c) => (c as OpcionConjunto).nombre"
                                :descripcion="
                                    (c) =>
                                        `Disponible: ${(c as OpcionConjunto).disponible ?? 0}`
                                "
                                placeholder="Buscar conjunto…"
                                placeholder-busqueda="Buscar por nombre"
                                :invalido="
                                    !!erroresLaxos[`conjuntos.${i}.conjunto_id`]
                                "
                                @update:model-value="
                                    (v) =>
                                        alElegirConjunto(
                                            i,
                                            v as OpcionConjunto | null,
                                        )
                                "
                            />
                            <InputError
                                :message="
                                    erroresLaxos[`conjuntos.${i}.conjunto_id`]
                                "
                            />
                        </div>
                        <div>
                            <Input
                                v-model.number="fila.cantidad"
                                type="number"
                                min="1"
                                :max="
                                    conjuntosUI[i].sel?.disponible ?? undefined
                                "
                                class="h-9"
                            />
                            <InputError
                                :message="
                                    erroresLaxos[`conjuntos.${i}.cantidad`]
                                "
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            @click="quitarConjunto(i)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>

                    <div
                        v-if="
                            conjuntosUI[i].sel?.componentes_variante_libre
                                .length
                        "
                        class="bg-muted/30 grid gap-2 rounded-md border p-2 sm:grid-cols-2"
                    >
                        <div
                            v-for="comp in conjuntosUI[i].sel
                                ?.componentes_variante_libre ?? []"
                            :key="comp.componente_id"
                            class="grid gap-1"
                        >
                            <Label class="text-xs">
                                Variante de {{ comp.activo_nombre }}
                            </Label>
                            <SelectSimple
                                :model-value="
                                    fila.variantes[comp.componente_id] ?? null
                                "
                                :opciones="
                                    comp.tallas.map((t) => ({
                                        valor: t.id,
                                        etiqueta: t.valor,
                                    }))
                                "
                                placeholder="Selecciona"
                                :invalido="
                                    !!erroresLaxos[
                                        `conjuntos.${i}.variantes.${comp.componente_id}`
                                    ]
                                "
                                @update:model-value="
                                    (v) =>
                                        (fila.variantes[comp.componente_id] =
                                            v as number | null)
                                "
                            />
                            <InputError
                                :message="
                                    erroresLaxos[
                                        `conjuntos.${i}.variantes.${comp.componente_id}`
                                    ]
                                "
                            />
                        </div>
                    </div>
                </div>
                <p
                    v-if="almacenSel && !form.conjuntos.length"
                    class="text-muted-foreground text-sm"
                >
                    Sin conjuntos agregados.
                </p>
            </section>

            <div class="grid gap-1.5">
                <Label for="notas">Notas (opcional)</Label>
                <textarea
                    id="notas"
                    v-model="form.notas"
                    rows="2"
                    class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    Registrar entrega
                </Button>
                <Button variant="ghost" as-child>
                    <Link href="/entregas">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
