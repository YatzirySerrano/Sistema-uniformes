<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    IdCard,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import PadFirma from '@/components/sistema/PadFirma.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import DatePicker from '@/components/sistema/DatePicker.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type OpcionEmpresa = {
    id: number;
    nombre_comercial: string;
    codigo: string | null;
};
type OpcionSucursal = { id: number; nombre: string };

type OpcionServicioActual = {
    id: number;
    nombre: string;
    contrato: { id: number; nombre: string };
};

type OpcionColaborador = {
    id: number;
    nombre_completo: string;
    numero_empleado: string;
    empresa_id: number;
    sucursal_id: number;
    servicio_actual: OpcionServicioActual | null;
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

type DocIdentidad = {
    disponible: boolean;
    nombre?: string;
    mime?: string;
    previsualizable?: boolean;
    actualizado_en?: string | null;
    url?: string;
};

const props = defineProps<{
    encargado: { name: string; email: string };
    textoConsentimiento: string;
}>();

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
// Pasos del flujo: la entrega NO termina hasta firmar.
// ------------------------------------------------------------------
const PASOS = [
    { n: 1, titulo: 'Datos de la entrega' },
    { n: 2, titulo: 'Artículos, unidades y conjuntos' },
    { n: 3, titulo: 'Revisión y firmas' },
] as const;
const paso = ref<1 | 2 | 3>(1);

// ------------------------------------------------------------------
// Empresa → Sucursal → Colaborador → Almacén de origen
// ------------------------------------------------------------------
const empresaSel = ref<OpcionEmpresa | null>(null);
const sucursalSel = ref<OpcionSucursal | null>(null);
const colaboradorSel = ref<OpcionColaborador | null>(null);
const almacenSel = ref<OpcionAlmacen | null>(null);
const empresaId = computed(() => empresaSel.value?.id ?? null);
const sucursalId = computed(() => sucursalSel.value?.id ?? null);

// --- Servicio operativo de destino de ESTA entrega (snapshot histórico) ---
// NO es una elección del formulario: se toma automáticamente del servicio
// operativo VIGENTE del colaborador y el backend lo vuelve a resolver al
// guardar. Se muestra sólo como bloque informativo de lectura.
const servicioActualColab = computed(
    () => colaboradorSel.value?.servicio_actual ?? null,
);

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
    docIdentidad.value = null;
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
    firma: string;
    firma_operador: string;
    aceptacion: boolean;
    idempotency_key: string;
}>({
    colaborador_id: '',
    almacen_id: null,
    fecha_entrega: hoy,
    notas: '',
    activos: [],
    unidades: [],
    conjuntos: [],
    firma: '',
    firma_operador: '',
    aceptacion: false,
    // Una clave por intento de alta: evita que un doble submit registre dos
    // entregas (el backend la rechaza si ya la vio).
    idempotency_key:
        typeof crypto !== 'undefined' && 'randomUUID' in crypto
            ? crypto.randomUUID()
            : `${Date.now()}-${Math.random().toString(16).slice(2)}`,
});

/** Acceso laxo a errores anidados (`activos.0.talla_id`, `unidades.0.unidad_activo_id`…). */
const erroresLaxos = computed(
    () => form.errors as unknown as Record<string, string>,
);

const totalRenglones = computed(
    () =>
        form.activos.filter((f) => f.activo_id !== '').length +
        form.unidades.filter((f) => f.unidad_activo_id !== '').length +
        form.conjuntos.filter((f) => f.conjunto_id !== '').length,
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

// ------------------------------------------------------------------
// Paso 3 — Documento de identidad + firmas
// ------------------------------------------------------------------
const padColaborador = ref<InstanceType<typeof PadFirma> | null>(null);
const padOperador = ref<InstanceType<typeof PadFirma> | null>(null);
const firmaColaboradorVacia = ref(true);
const firmaOperadorVacia = ref(true);

const docIdentidad = ref<DocIdentidad | null>(null);
const docIdentidadCargando = ref(false);
const inePreviewAbierto = ref(false);

async function cargarDocIdentidad(): Promise<void> {
    if (form.colaborador_id === '') return;
    docIdentidadCargando.value = true;
    docIdentidad.value = null;
    try {
        const res = await fetch(
            `/entregas/documento-identidad/${form.colaborador_id}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );
        docIdentidad.value = res.ok ? await res.json() : { disponible: false };
    } catch {
        docIdentidad.value = { disponible: false };
    } finally {
        docIdentidadCargando.value = false;
    }
}

watch(paso, (p) => {
    if (p === 3 && docIdentidad.value === null && !docIdentidadCargando.value) {
        void cargarDocIdentidad();
    }

    // Los PadFirma viven dentro del contenedor del paso 3 (`v-show`), así que
    // se montan ocultos. Al entrar al paso hay que recalibrar el canvas ya
    // con el ancho real (el ResizeObserver interno también lo hace, esto lo
    // fuerza de inmediato tras el repintado). No borra la firma existente.
    if (p === 3) {
        void nextTick(() => {
            padColaborador.value?.recalibrar();
            padOperador.value?.recalibrar();
        });
    }
});

const esImagenIne = computed(() =>
    (docIdentidad.value?.mime ?? '').startsWith('image/'),
);
const esPdfIne = computed(() => docIdentidad.value?.mime === 'application/pdf');

// ------------------------------------------------------------------
// Navegación entre pasos + envío
// ------------------------------------------------------------------
const puedeAvanzarPaso1 = computed(
    () => form.colaborador_id !== '' && form.almacen_id !== null,
);
const puedeAvanzarPaso2 = computed(() => totalRenglones.value > 0);

const faltantesFirma = computed<string[]>(() => {
    const faltan: string[] = [];
    if (firmaColaboradorVacia.value)
        faltan.push('Solicita la firma del colaborador para continuar.');
    if (firmaOperadorVacia.value)
        faltan.push('Falta la firma del encargado que realiza la entrega.');
    if (!form.aceptacion)
        faltan.push(
            'Debes confirmar la aceptación antes de finalizar la entrega.',
        );
    return faltan;
});

const puedeConfirmar = computed(
    () =>
        totalRenglones.value > 0 &&
        faltantesFirma.value.length === 0 &&
        !form.processing,
);

function irA(n: 1 | 2 | 3): void {
    if (n === 2 && !puedeAvanzarPaso1.value) return;
    if (n === 3 && (!puedeAvanzarPaso1.value || !puedeAvanzarPaso2.value))
        return;
    paso.value = n;
}

function irAPasoConError(): void {
    const claves = Object.keys(form.errors);
    if (
        claves.some(
            (k) =>
                k === 'firma' || k === 'firma_operador' || k === 'aceptacion',
        )
    ) {
        paso.value = 3;
    } else if (
        claves.some((k) => /^(activos|unidades|conjuntos)\b/.test(k)) ||
        claves.includes('items')
    ) {
        paso.value = 2;
    } else {
        paso.value = 1;
    }
}

function enviar(): void {
    form.firma = padColaborador.value?.obtenerDataUrl() ?? '';
    form.firma_operador = padOperador.value?.obtenerDataUrl() ?? '';

    if (!puedeConfirmar.value) {
        return;
    }

    form.transform((datos) => ({
        ...datos,
        activos: datos.activos.filter((fila) => fila.activo_id !== ''),
        unidades: datos.unidades.filter((fila) => fila.unidad_activo_id !== ''),
        conjuntos: datos.conjuntos.filter((fila) => fila.conjunto_id !== ''),
    })).post('/entregas', {
        preserveScroll: true,
        onError: () => irAPasoConError(),
    });
}
</script>

<template>
    <Head title="Nueva entrega" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Registrar entrega"
            descripcion="Registrar y firmar son un solo proceso: la entrega no queda concluida hasta que el colaborador y el encargado firman la recepción."
        />

        <!-- Indicador de pasos -->
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
                    :aria-current="paso === p.n ? 'step' : undefined"
                    @click="irA(p.n)"
                >
                    <span
                        class="flex size-5 items-center justify-center rounded-full border text-xs font-semibold"
                        :class="
                            paso >= p.n
                                ? 'border-current'
                                : 'border-muted-foreground/40'
                        "
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
            <!-- ============ PASO 1 · Datos ============ -->
            <section
                v-show="paso === 1"
                class="grid gap-4 rounded-xl border p-4 sm:grid-cols-2"
            >
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

                <div class="grid gap-1.5 sm:col-span-2">
                    <Label>Servicio operativo (destino de la entrega)</Label>
                    <div
                        class="bg-muted/40 rounded-md border px-3 py-2 text-sm"
                        aria-live="polite"
                    >
                        <template v-if="!colaboradorSel">
                            <span class="text-muted-foreground">
                                Selecciona un colaborador para ver su servicio
                                operativo vigente.
                            </span>
                        </template>
                        <template v-else-if="servicioActualColab">
                            <p class="font-medium">
                                {{ servicioActualColab.contrato.nombre }} —
                                {{ servicioActualColab.nombre }}
                            </p>
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                Se toma automáticamente del servicio vigente del
                                colaborador y no puede cambiarse aquí. Para
                                reasignarlo, usa «Cambiar servicio» en su ficha.
                            </p>
                        </template>
                        <template v-else>
                            <p class="font-medium">
                                Sin servicio operativo asignado
                            </p>
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                Esta entrega se registrará sin servicio de
                                destino (personal administrativo o interno).
                            </p>
                        </template>
                    </div>
                    <InputError :message="erroresLaxos['servicio_id']" />
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

                <div class="grid gap-1.5 sm:col-span-2">
                    <Label for="notas">Notas (opcional)</Label>
                    <textarea
                        id="notas"
                        v-model="form.notas"
                        rows="2"
                        class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    />
                </div>
            </section>

            <!-- ============ PASO 2 · Elementos ============ -->
            <div v-show="paso === 2" class="space-y-6">
                <p
                    v-if="avisoAlmacenCambiado"
                    class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400"
                >
                    Se limpiaron los elementos de la entrega porque cambió el
                    almacén de origen: la disponibilidad correspondía al almacén
                    anterior.
                </p>

                <InputError :message="erroresLaxos['items']" />

                <!-- Artículos por cantidad -->
                <section class="space-y-3 rounded-xl border p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold">
                                Artículos por cantidad
                            </h2>
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                Para prendas u otros artículos controlados por
                                existencias. Selecciona el artículo, la talla o
                                variante cuando aplique y la cantidad a
                                entregar.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="shrink-0"
                            :disabled="!almacenSel"
                            @click="agregarActivo"
                        >
                            <Plus class="size-4" /> Agregar artículo
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
                                    (a) =>
                                        activoSinExistencias(a as OpcionActivo)
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
                                :invalido="
                                    !!erroresLaxos[`activos.${i}.activo_id`]
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
                                    erroresLaxos[`activos.${i}.activo_id`]
                                "
                            />
                        </div>
                        <div v-if="activosUI[i].sel?.usa_variantes">
                            <SelectSimple
                                :model-value="fila.talla_id"
                                :opciones="
                                    (activosUI[i].sel?.tallas ?? []).map(
                                        (t) => ({
                                            valor: t.id,
                                            etiqueta: `${t.valor}${(t.disponible ?? 0) > 0 ? ` (${t.disponible})` : ' (sin existencias)'}`,
                                            disabled: (t.disponible ?? 0) <= 0,
                                        }),
                                    )
                                "
                                placeholder="Variante"
                                :invalido="
                                    !!erroresLaxos[`activos.${i}.talla_id`]
                                "
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
                                    disponibleDe(
                                        fila.activo_id,
                                        fila.talla_id,
                                    ) ?? undefined
                                "
                                class="h-9"
                            />
                            <p
                                v-if="
                                    disponibleDe(
                                        fila.activo_id,
                                        fila.talla_id,
                                    ) !== null
                                "
                                class="text-muted-foreground mt-0.5 text-[11px]"
                                :class="
                                    (disponibleDe(
                                        fila.activo_id,
                                        fila.talla_id,
                                    ) ?? 0) < fila.cantidad
                                        ? 'text-destructive'
                                        : ''
                                "
                            >
                                Disponible:
                                {{
                                    disponibleDe(fila.activo_id, fila.talla_id)
                                }}
                            </p>
                            <InputError
                                :message="erroresLaxos[`activos.${i}.cantidad`]"
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="`Quitar artículo ${i + 1}`"
                            @click="quitarActivo(i)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                    <p
                        v-if="almacenSel && !form.activos.length"
                        class="text-muted-foreground text-sm"
                    >
                        Sin artículos por cantidad agregados.
                    </p>
                </section>

                <!-- Equipos y unidades identificadas -->
                <section class="space-y-3 rounded-xl border p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold">
                                Equipos y unidades identificadas
                            </h2>
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                Para equipos u otros activos con seguimiento
                                individual mediante un código o identificador
                                único, como computadoras, celulares o
                                herramientas. Aquí eliges una unidad específica.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="shrink-0"
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
                                    !!erroresLaxos[
                                        `unidades.${i}.unidad_activo_id`
                                    ]
                                "
                                @update:model-value="
                                    (v) =>
                                        alElegirUnidad(
                                            i,
                                            v as OpcionUnidad | null,
                                        )
                                "
                            />
                            <InputError
                                :message="
                                    erroresLaxos[
                                        `unidades.${i}.unidad_activo_id`
                                    ]
                                "
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="`Quitar unidad ${i + 1}`"
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
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold">Conjuntos</h2>
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                Para kits o grupos de artículos que se entregan
                                juntos, como un uniforme completo o un kit de
                                equipo.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="shrink-0"
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
                                    :etiqueta="
                                        (c) => (c as OpcionConjunto).nombre
                                    "
                                    :descripcion="
                                        (c) =>
                                            `Disponible: ${(c as OpcionConjunto).disponible ?? 0}`
                                    "
                                    placeholder="Buscar conjunto…"
                                    placeholder-busqueda="Buscar por nombre"
                                    :invalido="
                                        !!erroresLaxos[
                                            `conjuntos.${i}.conjunto_id`
                                        ]
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
                                        erroresLaxos[
                                            `conjuntos.${i}.conjunto_id`
                                        ]
                                    "
                                />
                            </div>
                            <div>
                                <Input
                                    v-model.number="fila.cantidad"
                                    type="number"
                                    min="1"
                                    :max="
                                        conjuntosUI[i].sel?.disponible ??
                                        undefined
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
                                :aria-label="`Quitar conjunto ${i + 1}`"
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
                                        fila.variantes[comp.componente_id] ??
                                        null
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
                                            (fila.variantes[
                                                comp.componente_id
                                            ] = v as number | null)
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
            </div>

            <!-- ============ PASO 3 · Revisión y firmas ============ -->
            <div v-show="paso === 3" class="space-y-6">
                <!-- Resumen -->
                <section class="rounded-xl border p-4">
                    <h2 class="mb-3 text-sm font-semibold">
                        Revisión de la entrega
                    </h2>
                    <dl
                        class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Colaborador
                            </dt>
                            <dd>
                                {{ colaboradorSel?.nombre_completo ?? '—' }}
                                <span class="text-muted-foreground"
                                    >· N.º
                                    {{
                                        colaboradorSel?.numero_empleado ?? '—'
                                    }}</span
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Empresa
                            </dt>
                            <dd>{{ empresaSel?.nombre_comercial ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Sucursal
                            </dt>
                            <dd>{{ sucursalSel?.nombre ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Almacén de origen
                            </dt>
                            <dd>{{ almacenSel?.nombre ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Servicio (ubicación operativa de esta entrega)
                            </dt>
                            <dd>
                                <template v-if="servicioActualColab">
                                    {{ servicioActualColab.contrato.nombre }} —
                                    {{ servicioActualColab.nombre }}
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >Sin servicio (entrega interna)</span
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">Fecha</dt>
                            <dd>{{ form.fecha_entrega }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Elementos a entregar
                            </dt>
                            <dd>
                                {{ totalRenglones }}
                                {{
                                    totalRenglones === 1
                                        ? 'renglón'
                                        : 'renglones'
                                }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Firma de quien recibe -->
                <section class="space-y-4 rounded-xl border p-4">
                    <div>
                        <h2 class="text-sm font-semibold">
                            Firma de quien recibe
                        </h2>
                        <dl
                            class="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2"
                        >
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Nombre
                                </dt>
                                <dd>
                                    {{ colaboradorSel?.nombre_completo ?? '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Número de empleado
                                </dt>
                                <dd>
                                    {{ colaboradorSel?.numero_empleado ?? '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Empresa
                                </dt>
                                <dd>
                                    {{ empresaSel?.nombre_comercial ?? '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Sucursal
                                </dt>
                                <dd>{{ sucursalSel?.nombre ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Documento de identidad -->
                    <div class="bg-muted/30 rounded-lg border p-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <IdCard class="text-muted-foreground size-4" />
                            <span class="text-sm font-medium"
                                >Documento de identidad</span
                            >
                            <Button
                                v-if="docIdentidad?.disponible"
                                type="button"
                                variant="outline"
                                size="sm"
                                class="ml-auto"
                                @click="inePreviewAbierto = true"
                            >
                                <ExternalLink class="size-3.5" /> Ver INE
                            </Button>
                        </div>
                        <p
                            v-if="docIdentidadCargando"
                            class="text-muted-foreground mt-2 text-xs"
                        >
                            Buscando el documento en el expediente…
                        </p>
                        <template v-else-if="docIdentidad?.disponible">
                            <p class="text-muted-foreground mt-2 text-xs">
                                Consulta el documento registrado en el
                                expediente del colaborador para realizar una
                                verificación visual antes de solicitar su firma.
                            </p>
                            <p
                                class="text-muted-foreground mt-1 text-[11px] italic"
                            >
                                La revisión de identidad y firma es
                                responsabilidad del encargado que realiza la
                                entrega.
                            </p>
                        </template>
                        <p
                            v-else
                            class="mt-2 flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-500"
                        >
                            No hay un documento de identidad disponible en el
                            expediente de este colaborador.
                        </p>
                    </div>

                    <div class="grid gap-1.5">
                        <Label>Firma del colaborador</Label>
                        <PadFirma
                            ref="padColaborador"
                            @cambio="
                                (v: boolean) => (firmaColaboradorVacia = v)
                            "
                        />
                        <InputError :message="form.errors.firma" />
                    </div>
                </section>

                <!-- Firma del encargado -->
                <section class="space-y-4 rounded-xl border p-4">
                    <div>
                        <h2 class="text-sm font-semibold">
                            Firma del encargado que realiza la entrega
                        </h2>
                        <dl
                            class="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2"
                        >
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Nombre
                                </dt>
                                <dd>{{ encargado.name }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Correo
                                </dt>
                                <dd>{{ encargado.email }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Firma del encargado</Label>
                        <PadFirma
                            ref="padOperador"
                            @cambio="(v: boolean) => (firmaOperadorVacia = v)"
                        />
                        <InputError :message="form.errors.firma_operador" />
                    </div>
                </section>

                <!-- Aceptación -->
                <label
                    class="bg-muted/40 flex items-start gap-2 rounded-lg border p-3 text-sm"
                >
                    <input
                        v-model="form.aceptacion"
                        type="checkbox"
                        class="mt-0.5 size-4 shrink-0"
                    />
                    <span>{{ textoConsentimiento }}</span>
                </label>
                <InputError :message="form.errors.aceptacion" />

                <!-- Faltantes para finalizar -->
                <ul
                    v-if="faltantesFirma.length"
                    class="text-muted-foreground space-y-1 text-sm"
                >
                    <li
                        v-for="msg in faltantesFirma"
                        :key="msg"
                        class="flex items-center gap-1.5"
                    >
                        <span
                            class="bg-muted-foreground/50 inline-block size-1.5 rounded-full"
                        />
                        {{ msg }}
                    </li>
                </ul>
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
                    :disabled="!puedeAvanzarPaso1"
                    @click="irA(2)"
                >
                    Siguiente <ChevronRight class="size-4" />
                </Button>
                <Button
                    v-else-if="paso === 2"
                    type="button"
                    :disabled="!puedeAvanzarPaso2"
                    @click="irA(3)"
                >
                    Continuar a confirmación y firma
                    <ChevronRight class="size-4" />
                </Button>
                <Button
                    v-else
                    type="submit"
                    :disabled="!puedeConfirmar"
                    :class="
                        puedeConfirmar &&
                        'shadow-success/30 shadow-lg transition-shadow duration-300'
                    "
                >
                    Confirmar entrega
                </Button>

                <Button variant="ghost" as-child>
                    <Link href="/entregas">Cancelar</Link>
                </Button>
            </div>
        </form>

        <!-- Diálogo de previsualización del INE -->
        <Dialog v-model:open="inePreviewAbierto">
            <DialogContent
                class="max-h-[90dvh] w-[calc(100vw-2rem)] overflow-auto sm:max-w-3xl"
            >
                <DialogHeader>
                    <DialogTitle>Documento de identidad</DialogTitle>
                    <DialogDescription>
                        Verificación visual a cargo del encargado. Este
                        documento no se adjunta al acuse ni al correo.
                    </DialogDescription>
                </DialogHeader>

                <div class="mt-2">
                    <img
                        v-if="
                            docIdentidad?.disponible &&
                            docIdentidad.url &&
                            esImagenIne
                        "
                        :src="docIdentidad.url"
                        alt="Documento de identidad del colaborador"
                        class="mx-auto max-h-[70dvh] w-auto rounded-md border"
                    />
                    <iframe
                        v-else-if="
                            docIdentidad?.disponible &&
                            docIdentidad.url &&
                            esPdfIne
                        "
                        :src="docIdentidad.url"
                        title="Documento de identidad del colaborador"
                        class="h-[70dvh] w-full rounded-md border"
                    />
                    <p
                        v-else
                        class="text-muted-foreground py-8 text-center text-sm"
                    >
                        Este documento no puede previsualizarse aquí. Consúltalo
                        desde el expediente del colaborador.
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
