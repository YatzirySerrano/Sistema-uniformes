<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import SubidaArchivo from '@/components/sistema/SubidaArchivo.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EmpresaAutorizada } from '@/types/sistema';

type OpcionTipo = { id: number; nombre: string };
type OpcionCategoria = {
    id: number;
    nombre: string;
    tipo_activo_id: number | null;
    tipo?: string | null;
};

type Activo = {
    id: number;
    empresa_id: number;
    nombre: string;
    descripcion: string | null;
    codigo: string | null;
    tipo_activo_id: number | null;
    categoria_id: number | null;
    tipo_control: 'cantidad' | 'individual';
    activo: boolean;
    imagen_url: string | null;
    tallas: number[];
};

type Variante = { id: number; valor: string; deshabilitada?: boolean };
type VarianteAsignada = { id: number; valor: string; habilitada: boolean };
type OpcionAlmacen = { id: number; nombre: string; codigo: string | null };

const props = defineProps<{
    activo: Activo | null;
    seleccion: { tipo: OpcionTipo | null; categoria: OpcionCategoria | null };
    empresasAutorizadas: EmpresaAutorizada[];
    // Catálogo global de variantes activas (no depende de la empresa).
    tallasGlobales: Variante[];
    // En edición: variantes ya asignadas al activo. Las desactivadas
    // globalmente siguen visibles (para poder quitarlas) marcadas como tales.
    tallasAsignadas: VarianteAsignada[];
    tiposControl: { valor: string; etiqueta: string }[];
    permisos: {
        crear_tipo: boolean;
        crear_categoria: boolean;
        crear_variante: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Activos', href: '/activos' },
            { title: 'Formulario', href: '#' },
        ],
    },
});

const esEdicion = !!props.activo;
const PESO_MAXIMO_MB = 4;

const empresaIdInicial =
    props.activo?.empresa_id ??
    (props.empresasAutorizadas.length === 1
        ? props.empresasAutorizadas[0].id
        : '');
const empresaSel = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === empresaIdInicial) ?? null,
);
const empresaId = computed(() => empresaSel.value?.id ?? '');

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

function tallasIniciales(): Variante[] {
    const globales: Variante[] = [...props.tallasGlobales];

    // En edición: añade las variantes ya asignadas que estén desactivadas
    // globalmente, para poder quitarlas.
    if (esEdicion) {
        const ids = new Set(globales.map((t) => t.id));
        const extra = props.tallasAsignadas
            .filter((t) => !t.habilitada && !ids.has(t.id))
            .map((t) => ({ id: t.id, valor: t.valor, deshabilitada: true }));
        return [...globales, ...extra];
    }
    return globales;
}

// Tipo, categoría y variante son catálogos globales: no dependen de la
// empresa elegida ni cambian cuando ésta cambia.
const tallasLocal = ref<Variante[]>(tallasIniciales());

// Objeto seleccionado en cada combobox (el id vive en `form`).
const tipoSel = ref<OpcionTipo | null>(props.seleccion.tipo);
const categoriaSel = ref<OpcionCategoria | null>(props.seleccion.categoria);
const avisoCategoria = ref('');

watch(empresaId, (id) => {
    form.empresa_id = id === '' ? null : id;
    // El almacén sí depende de la empresa (sólo abastece a algunas).
    almacenSel.value = null;
    form.almacen_id = null;
});

const buscarTalla = ref('');
const tallasFiltradas = computed(() => {
    const q = buscarTalla.value.trim().toLowerCase();
    return q
        ? tallasLocal.value.filter((t) => t.valor.toLowerCase().includes(q))
        : tallasLocal.value;
});

const form = useForm<{
    empresa_id: number | null;
    nombre: string;
    descripcion: string;
    categoria_id: number | '';
    codigo: string;
    tipo_activo_id: number | '';
    tipo_control: string;
    activo: boolean;
    tallas: number[];
    imagen: File | null;
    // Existencia inicial (sólo alta): el almacén es el de la ENTRADA
    // INICIAL, no una propiedad permanente del activo.
    almacen_id: number | null;
    cantidad_inicial: number;
    existencias: { talla_id: number; cantidad: number }[];
    // Seguimiento individual: opcional generar etiquetas QR de una vez para
    // las unidades recién creadas.
    generar_qr: boolean;
    _method?: string;
}>({
    empresa_id: empresaId.value === '' ? null : empresaId.value,
    nombre: props.activo?.nombre ?? '',
    descripcion: props.activo?.descripcion ?? '',
    categoria_id: props.activo?.categoria_id ?? '',
    codigo: props.activo?.codigo ?? '',
    tipo_activo_id: props.activo?.tipo_activo_id ?? '',
    tipo_control: props.activo?.tipo_control ?? 'cantidad',
    activo: props.activo?.activo ?? true,
    tallas: props.activo?.tallas ?? [],
    imagen: null,
    almacen_id: null,
    cantidad_inicial: 0,
    existencias: [],
    generar_qr: false,
});

const esSeguimientoIndividual = computed(
    () => form.tipo_control === 'individual',
);

// Un activo de seguimiento individual no usa variantes/tallas: no se envían
// al backend.
watch(esSeguimientoIndividual, (individual) => {
    if (individual) form.tallas = [];
});

// Mantiene `form.existencias` sincronizado 1:1 con `form.tallas`
// (una fila de cantidad inicial por variante seleccionada), conservando la
// cantidad ya capturada si la variante sigue elegida.
watch(
    () => [...form.tallas],
    (ids) => {
        const previas = new Map(
            form.existencias.map((e) => [e.talla_id, e.cantidad]),
        );
        form.existencias = ids.map((id) => ({
            talla_id: id,
            cantidad: previas.get(id) ?? 0,
        }));
    },
);

// --- Almacén de la entrada inicial (combobox con búsqueda) ------------------
const almacenSel = ref<OpcionAlmacen | null>(null);
async function buscarAlmacenes(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionAlmacen[]> {
    if (empresaId.value === '') return [];
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
function alElegirAlmacen(o: OpcionAlmacen | null): void {
    almacenSel.value = o;
    form.almacen_id = o?.id ?? null;
    form.clearErrors('almacen_id');
}

/** Acceso laxo a errores anidados (`existencias.0.cantidad`). */
const erroresLaxos = computed(
    () => form.errors as unknown as Record<string, string>,
);
function errorExistencia(tallaId: number): string | undefined {
    const i = form.tallas.indexOf(tallaId);
    return i === -1
        ? undefined
        : erroresLaxos.value[`existencias.${i}.cantidad`];
}

function xsrf(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

// --- Tipo de activo (combobox con búsqueda + alta inline) --------------------
// Catálogo global: no depende de la empresa elegida.
async function buscarTipos(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionTipo[]> {
    const res = await fetch(`/tipos-activo/buscar?q=${encodeURIComponent(q)}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];
    return (await res.json()).tipos ?? [];
}

function alElegirTipo(o: OpcionTipo | null): void {
    tipoSel.value = o;
    form.tipo_activo_id = o?.id ?? '';
    form.clearErrors('tipo_activo_id');
}

// Coherencia: si el tipo cambia a otro concreto y la categoría elegida está
// ligada a un tipo distinto, se quita la categoría (el backend también lo valida).
watch(
    () => form.tipo_activo_id,
    (nuevo) => {
        const cat = categoriaSel.value;
        if (
            cat &&
            cat.tipo_activo_id != null &&
            nuevo !== '' &&
            cat.tipo_activo_id !== nuevo
        ) {
            categoriaSel.value = null;
            form.categoria_id = '';
            avisoCategoria.value =
                'Se quitó la categoría porque pertenece a otro tipo de activo.';
        }
    },
);

// --- Categoría (combobox con búsqueda + alta inline) ------------------------
// Catálogo global: no depende de la empresa elegida.
async function buscarCategorias(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionCategoria[]> {
    const tipo = form.tipo_activo_id
        ? `&tipo_activo_id=${form.tipo_activo_id}`
        : '';
    const res = await fetch(
        `/categorias-activo/buscar?q=${encodeURIComponent(q)}${tipo}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).categorias ?? [];
}

function alElegirCategoria(o: OpcionCategoria | null): void {
    categoriaSel.value = o;
    form.categoria_id = o?.id ?? '';
    form.clearErrors('categoria_id');
    avisoCategoria.value = '';

    // Si la categoría trae tipo y el activo aún no tiene, se completa solo.
    if (o?.tipo_activo_id != null && form.tipo_activo_id === '') {
        form.tipo_activo_id = o.tipo_activo_id;
        tipoSel.value = { id: o.tipo_activo_id, nombre: o.tipo ?? '' };
    }
}

// --- Diálogos de alta rápida ----------------------------------------------
const dialogoTipo = ref(false);
const nombreNuevoTipo = ref('');
const errorNuevoTipo = ref('');
const creandoTipo = ref(false);

const dialogoCategoria = ref(false);
const nombreNuevaCategoria = ref('');
const tipoNuevaCategoria = ref<OpcionTipo | null>(null);
const errorNuevaCategoria = ref('');
const creandoCategoria = ref(false);

function abrirDialogoTipo(termino: string): void {
    nombreNuevoTipo.value = termino;
    errorNuevoTipo.value = '';
    dialogoTipo.value = true;
}

function abrirDialogoCategoria(termino: string): void {
    nombreNuevaCategoria.value = termino;
    tipoNuevaCategoria.value = tipoSel.value;
    errorNuevaCategoria.value = '';
    dialogoCategoria.value = true;
}

type RespuestaRapida =
    | { ok: true; datos: Record<string, unknown> }
    | { ok: false; errores: Record<string, string[]> };

async function crearRapido(
    url: string,
    cuerpo: Record<string, unknown>,
): Promise<RespuestaRapida> {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(cuerpo),
    });
    const json = (await res.json().catch(() => ({}))) as Record<
        string,
        unknown
    >;
    if (!res.ok) {
        return {
            ok: false,
            errores: (json.errors ?? {}) as Record<string, string[]>,
        };
    }
    return { ok: true, datos: json };
}

async function guardarNuevoTipo(): Promise<void> {
    if (!nombreNuevoTipo.value.trim() || creandoTipo.value) return;
    creandoTipo.value = true;
    errorNuevoTipo.value = '';
    const r = await crearRapido('/tipos-activo/rapido', {
        nombre: nombreNuevoTipo.value.trim(),
    });
    creandoTipo.value = false;

    if (!r.ok) {
        errorNuevoTipo.value =
            r.errores.nombre?.[0] ?? 'No se pudo crear el tipo.';
        return;
    }

    alElegirTipo(r.datos.tipo as OpcionTipo);
    dialogoTipo.value = false;
}

async function guardarNuevaCategoria(): Promise<void> {
    if (!nombreNuevaCategoria.value.trim() || creandoCategoria.value) return;
    creandoCategoria.value = true;
    errorNuevaCategoria.value = '';
    const r = await crearRapido('/categorias-activo/rapido', {
        nombre: nombreNuevaCategoria.value.trim(),
        tipo_activo_id: tipoNuevaCategoria.value?.id ?? null,
    });
    creandoCategoria.value = false;

    if (!r.ok) {
        errorNuevaCategoria.value =
            r.errores.nombre?.[0] ??
            r.errores.tipo_activo_id?.[0] ??
            'No se pudo crear la categoría.';
        return;
    }

    alElegirCategoria(r.datos.categoria as OpcionCategoria);
    dialogoCategoria.value = false;
}

// --- Alta rápida de variante / talla (sin cambios de alcance en este bloque) --
const nuevaVariante = ref('');
const creandoVariante = ref(false);
const mostrarNuevaVariante = ref(false);

async function agregarVariante() {
    if (!nuevaVariante.value.trim()) return;
    creandoVariante.value = true;
    const res = await fetch('/tallas/rapido', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ valor: nuevaVariante.value.trim() }),
    });
    creandoVariante.value = false;
    if (!res.ok) return;
    const creada = (await res.json()).talla as Variante;
    tallasLocal.value = [...tallasLocal.value, creada];
    if (!form.tallas.includes(creada.id)) form.tallas.push(creada.id);
    nuevaVariante.value = '';
    mostrarNuevaVariante.value = false;
}

function enviar() {
    if (esEdicion) {
        form.transform((d) => ({ ...d, _method: 'POST' })).post(
            `/activos/${props.activo!.id}`,
            { forceFormData: true },
        );
    } else {
        form.post('/activos', { forceFormData: true });
    }
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar activo' : 'Nuevo activo'" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            :titulo="esEdicion ? 'Editar activo' : 'Nuevo activo'"
            descripcion="El activo pertenece a una empresa / razón social. Una PRENDA es un activo individual; un UNIFORME es un conjunto de prendas (módulo Uniformes / Conjuntos)."
        />

        <form class="grid gap-6 lg:grid-cols-2" @submit.prevent="enviar">
            <section class="min-w-0 space-y-4 rounded-xl border p-4">
                <h2 class="text-sm font-semibold">Información</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-if="!esEdicion" class="grid gap-1.5 sm:col-span-2">
                        <Label for="empresa_id">Empresa / razón social</Label>
                        <BuscadorAsync
                            id="empresa_id"
                            v-model="empresaSel"
                            :buscar="buscarEmpresas"
                            :etiqueta="(e) => String(e.nombre_comercial)"
                            :invalido="!!form.errors.empresa_id"
                            placeholder="Selecciona una empresa"
                            placeholder-busqueda="Buscar empresa…"
                        />
                        <InputError :message="form.errors.empresa_id" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="nombre">Nombre</Label>
                        <Input id="nombre" v-model="form.nombre" required />
                        <InputError :message="form.errors.nombre" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="codigo" class="flex items-center gap-1.5">
                            Código
                            <AyudaTooltip
                                texto="Identificador interno del activo dentro de la empresa. Si lo dejas vacío se genera automáticamente (ACT-0001)."
                                etiqueta="Ayuda sobre el código"
                            />
                        </Label>
                        <Input
                            id="codigo"
                            v-model="form.codigo"
                            class="uppercase"
                            placeholder="Se genera automáticamente"
                        />
                        <InputError :message="form.errors.codigo" />
                    </div>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label
                            for="categoria_id"
                            class="flex items-center gap-1.5"
                        >
                            Categoría
                            <span class="text-muted-foreground"
                                >(opcional)</span
                            >
                            <AyudaTooltip
                                texto="Qué es el activo dentro de su tipo (Camisola, Pantalón, Laptop, Teléfono celular…). Se elige de un catálogo compartido por toda la plataforma. Puedes dejarlo en blanco."
                                etiqueta="Ayuda sobre la categoría"
                            />
                        </Label>
                        <BuscadorAsync
                            id="categoria_id"
                            :model-value="categoriaSel"
                            :buscar="buscarCategorias"
                            :dependencia="form.tipo_activo_id"
                            :etiqueta="(c) => (c as OpcionCategoria).nombre"
                            :descripcion="
                                (c) => (c as OpcionCategoria).tipo ?? 'Sin tipo'
                            "
                            placeholder="Sin categoría"
                            placeholder-busqueda="Buscar categoría por nombre"
                            sin-resultados="No hay categorías activas."
                            :permite-crear="permisos.crear_categoria"
                            texto-crear="Crear nueva categoría"
                            :invalido="!!form.errors.categoria_id"
                            @update:model-value="
                                (v) =>
                                    alElegirCategoria(
                                        v as OpcionCategoria | null,
                                    )
                            "
                            @crear="abrirDialogoCategoria"
                        />
                        <p class="text-muted-foreground text-xs">
                            Clasificación específica dentro del tipo. Ejemplo:
                            Camisola, Laptop o Teléfono celular.
                        </p>
                        <p
                            v-if="avisoCategoria"
                            class="text-xs text-amber-600 dark:text-amber-500"
                        >
                            {{ avisoCategoria }}
                        </p>
                        <InputError :message="form.errors.categoria_id" />
                    </div>
                </div>
                <div class="grid gap-1.5">
                    <Label for="descripcion">Descripción</Label>
                    <textarea
                        id="descripcion"
                        v-model="form.descripcion"
                        rows="3"
                        class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    />
                    <InputError :message="form.errors.descripcion" />
                </div>
            </section>

            <section class="min-w-0 space-y-4 rounded-xl border p-4">
                <h2 class="text-sm font-semibold">Clasificación</h2>
                <div class="grid gap-1.5">
                    <Label
                        for="tipo_activo_id"
                        class="flex items-center gap-1.5"
                    >
                        Tipo de activo
                        <span class="text-muted-foreground">(opcional)</span>
                        <AyudaTooltip
                            texto="Naturaleza del activo: Prenda, Equipo de cómputo, Dispositivo móvil, Electrónico, Accesorio, Herramienta / Equipo, Otro. El administrador puede crear tipos nuevos. Puedes dejarlo en blanco."
                            etiqueta="Ayuda sobre el tipo de activo"
                        />
                    </Label>
                    <BuscadorAsync
                        id="tipo_activo_id"
                        :model-value="tipoSel"
                        :buscar="buscarTipos"
                        :etiqueta="(t) => (t as OpcionTipo).nombre"
                        placeholder="Sin tipo"
                        placeholder-busqueda="Buscar tipo por nombre"
                        sin-resultados="No hay tipos activos."
                        :permite-crear="permisos.crear_tipo"
                        texto-crear="Crear nuevo tipo"
                        :invalido="!!form.errors.tipo_activo_id"
                        @update:model-value="
                            (v) => alElegirTipo(v as OpcionTipo | null)
                        "
                        @crear="abrirDialogoTipo"
                    />
                    <p class="text-muted-foreground text-xs">
                        Clasificación general del activo. Ejemplo: Prenda,
                        Equipo de cómputo o Dispositivo móvil.
                    </p>
                    <InputError :message="form.errors.tipo_activo_id" />
                </div>

                <div class="grid gap-1.5">
                    <Label class="flex items-center gap-1.5">
                        Tipo de control
                        <AyudaTooltip
                            texto="«Por cantidad»: se controla por existencias totales (uniformes, accesorios, consumibles). «Seguimiento individual»: el sistema genera un código único por cada unidad física para saber dónde está, su estado y a quién está asignada (laptops, teléfonos, sillas, herramientas costosas…). Nunca se captura número de serie, IMEI ni etiqueta manual."
                            etiqueta="Ayuda sobre el tipo de control"
                        />
                    </Label>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="c in tiposControl"
                            :key="c.valor"
                            class="flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm"
                            :class="
                                form.tipo_control === c.valor
                                    ? 'border-primary bg-primary/10'
                                    : ''
                            "
                        >
                            <input
                                v-model="form.tipo_control"
                                type="radio"
                                :value="c.valor"
                                class="size-3.5"
                            />
                            {{ c.etiqueta }}
                        </label>
                    </div>
                    <InputError :message="form.errors.tipo_control" />
                </div>

                <div
                    v-if="esSeguimientoIndividual"
                    class="rounded-lg border border-dashed p-3"
                >
                    <p class="text-sm font-medium">Seguimiento individual</p>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Este activo no usa variantes / tallas: cada unidad es un
                        objeto físico independiente. El sistema genera
                        automáticamente el código de cada unidad al capturar la
                        cantidad inicial abajo — nunca se captura a mano.
                    </p>
                </div>

                <div v-else class="grid gap-1.5">
                    <Label class="flex items-center gap-1.5">
                        Variantes / tallas
                        <AyudaTooltip
                            texto="Opcional. No todos los activos usan tallas: XS/S/M/L/XL, 28/30/32, 36R/38R, Unitalla… Si dejas esto vacío, el activo se controla «sin variante»."
                            etiqueta="Ayuda sobre variantes / tallas"
                        />
                    </Label>
                    <p class="text-muted-foreground text-xs">
                        Marca las variantes que aplican a este activo. Un mouse
                        o un cable no necesitan ninguna.
                    </p>

                    <Input
                        v-if="tallasLocal.length > 8"
                        v-model="buscarTalla"
                        type="search"
                        placeholder="Buscar variante / talla…"
                        aria-label="Buscar variante o talla"
                        class="h-8"
                    />

                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="t in tallasFiltradas"
                            :key="t.id"
                            class="flex cursor-pointer items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm"
                            :class="[
                                form.tallas.includes(t.id)
                                    ? 'border-primary bg-primary/10'
                                    : '',
                                t.deshabilitada
                                    ? 'border-dashed opacity-70'
                                    : '',
                            ]"
                            :title="
                                t.deshabilitada
                                    ? 'Esta variante está desactivada globalmente. Puedes quitarla; no podrás volver a agregarla hasta reactivarla en Variantes / tallas.'
                                    : undefined
                            "
                        >
                            <input
                                v-model="form.tallas"
                                type="checkbox"
                                :value="t.id"
                                class="size-3.5"
                            />
                            {{ t.valor
                            }}<span
                                v-if="t.deshabilitada"
                                class="text-muted-foreground text-xs"
                            >
                                · deshabilitada</span
                            >
                        </label>
                        <p
                            v-if="!tallasLocal.length"
                            class="text-muted-foreground text-sm"
                        >
                            Aún no hay variantes en el catálogo.
                        </p>
                        <p
                            v-else-if="!tallasFiltradas.length"
                            class="text-muted-foreground text-sm"
                        >
                            Sin coincidencias para «{{ buscarTalla }}».
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 pt-1">
                        <Button
                            v-if="permisos.crear_variante"
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="
                                mostrarNuevaVariante = !mostrarNuevaVariante
                            "
                        >
                            <Plus class="size-4" /> Crear nueva variante / talla
                        </Button>
                        <Link
                            href="/tallas"
                            class="text-primary text-xs underline"
                        >
                            Administrar variantes
                        </Link>
                    </div>
                    <div
                        v-if="mostrarNuevaVariante"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <Input
                            v-model="nuevaVariante"
                            placeholder="p. ej. XL, 34, Unitalla"
                            class="flex-1"
                            @keydown.enter.prevent="agregarVariante"
                        />
                        <Button
                            type="button"
                            size="sm"
                            :disabled="creandoVariante || !nuevaVariante.trim()"
                            @click="agregarVariante"
                        >
                            Agregar
                        </Button>
                    </div>
                    <InputError :message="form.errors.tallas" />
                </div>
            </section>

            <section
                v-if="!esEdicion"
                class="min-w-0 space-y-4 rounded-xl border p-4 lg:col-span-2"
            >
                <h2 class="flex items-center gap-1.5 text-sm font-semibold">
                    Existencia inicial
                    <span class="text-muted-foreground font-normal"
                        >(opcional)</span
                    >
                    <AyudaTooltip
                        :texto="
                            esSeguimientoIndividual
                                ? 'Genera de una vez las unidades con las que arranca este activo: el sistema crea un código por cada una. Elige el almacén donde quedarán; si lo dejas en blanco y en 0, el activo se crea sin unidades y puedes agregarlas después desde su detalle.'
                                : 'Registra de una vez el stock con el que arranca este activo. Elige el almacén donde va a quedar esa existencia; si lo dejas en blanco y en 0, el activo se crea sin stock y puedes agregar existencias después desde su detalle.'
                        "
                        etiqueta="Ayuda sobre existencia inicial"
                    />
                </h2>

                <div class="grid gap-1.5 sm:max-w-sm">
                    <Label for="almacen_id">Almacén de entrada</Label>
                    <BuscadorAsync
                        id="almacen_id"
                        :model-value="almacenSel"
                        :buscar="buscarAlmacenes"
                        :dependencia="empresaId"
                        :disabled="empresaId === ''"
                        :etiqueta="(a) => (a as OpcionAlmacen).nombre"
                        :descripcion="(a) => (a as OpcionAlmacen).codigo ?? ''"
                        :placeholder="
                            empresaId === ''
                                ? 'Selecciona primero una empresa'
                                : 'Sin existencia inicial'
                        "
                        placeholder-busqueda="Buscar almacén por nombre"
                        sin-resultados="Este almacén no abastece a la empresa elegida."
                        :invalido="!!form.errors.almacen_id"
                        @update:model-value="
                            (v) => alElegirAlmacen(v as OpcionAlmacen | null)
                        "
                    />
                    <InputError :message="form.errors.almacen_id" />
                </div>

                <div
                    v-if="form.tallas.length === 0"
                    class="grid gap-1.5 sm:max-w-xs"
                >
                    <Label for="cantidad_inicial">
                        {{
                            esSeguimientoIndividual
                                ? 'Cantidad de unidades a crear'
                                : 'Cantidad inicial'
                        }}
                    </Label>
                    <Input
                        id="cantidad_inicial"
                        v-model.number="form.cantidad_inicial"
                        type="number"
                        min="0"
                        step="1"
                    />
                    <InputError :message="form.errors.cantidad_inicial" />

                    <label
                        v-if="esSeguimientoIndividual"
                        class="mt-1 flex items-center gap-2 text-sm"
                    >
                        <input
                            v-model="form.generar_qr"
                            type="checkbox"
                            class="size-4"
                        />
                        Generar etiquetas QR para estas unidades
                        <AyudaTooltip
                            texto="Al guardar, se abrirá el PDF de etiquetas (código + QR) listo para imprimir. Puedes generarlas después desde el listado de unidades si prefieres no hacerlo ahora."
                            etiqueta="Ayuda sobre etiquetas QR"
                        />
                    </label>
                </div>

                <div v-else-if="!esSeguimientoIndividual" class="grid gap-1.5">
                    <Label>Cantidad inicial por variante</Label>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="fila in form.existencias"
                            :key="fila.talla_id"
                            class="flex items-center gap-2"
                        >
                            <span
                                class="text-muted-foreground w-14 shrink-0 text-sm"
                            >
                                {{
                                    tallasLocal.find(
                                        (t) => t.id === fila.talla_id,
                                    )?.valor ?? fila.talla_id
                                }}
                            </span>
                            <Input
                                v-model.number="fila.cantidad"
                                type="number"
                                min="0"
                                step="1"
                                class="h-8"
                                :aria-label="`Cantidad inicial para la variante ${fila.talla_id}`"
                            />
                            <InputError
                                :message="errorExistencia(fila.talla_id)"
                            />
                        </div>
                    </div>
                </div>
            </section>

            <section
                class="min-w-0 space-y-4 rounded-xl border p-4 lg:col-span-2"
            >
                <h2 class="text-sm font-semibold">Imagen y estado</h2>
                <div class="grid gap-1.5">
                    <Label for="imagen">Imagen (opcional)</Label>
                    <SubidaArchivo
                        id="imagen"
                        v-model="form.imagen"
                        tipo="imagen"
                        accept="image/jpeg,image/png,image/webp"
                        formatos-etiqueta="Formatos aceptados: JPG, PNG o WebP."
                        :peso-maximo-mb="PESO_MAXIMO_MB"
                        :archivo-actual-url="activo?.imagen_url"
                        :invalido="!!form.errors.imagen"
                    />
                    <InputError :message="form.errors.imagen" />
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.activo"
                        type="checkbox"
                        class="size-4 rounded border"
                    />
                    Activo disponible para operaciones
                </label>
            </section>

            <div class="flex items-center gap-3 lg:col-span-2">
                <Button type="submit" :disabled="form.processing">
                    {{ esEdicion ? 'Guardar cambios' : 'Crear activo' }}
                </Button>
                <Button variant="ghost" as-child>
                    <Link href="/activos">Cancelar</Link>
                </Button>
            </div>
        </form>

        <!-- Alta rápida de tipo -->
        <Dialog
            :open="dialogoTipo"
            @update:open="(v: boolean) => (dialogoTipo = v)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Nuevo tipo de activo</DialogTitle>
                    <DialogDescription>
                        Se agrega al catálogo compartido de la plataforma y
                        queda elegido en el formulario.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-1.5">
                    <Label for="nuevo-tipo-nombre">Nombre</Label>
                    <Input
                        id="nuevo-tipo-nombre"
                        v-model="nombreNuevoTipo"
                        placeholder="p. ej. Equipo de protección"
                        @keydown.enter.prevent="guardarNuevoTipo"
                    />
                    <p v-if="errorNuevoTipo" class="text-destructive text-xs">
                        {{ errorNuevoTipo }}
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="dialogoTipo = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        :disabled="creandoTipo || !nombreNuevoTipo.trim()"
                        @click="guardarNuevoTipo"
                    >
                        Crear y seleccionar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Alta rápida de categoría -->
        <Dialog
            :open="dialogoCategoria"
            @update:open="(v: boolean) => (dialogoCategoria = v)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Nueva categoría</DialogTitle>
                    <DialogDescription>
                        Se agrega al catálogo compartido de la plataforma y
                        queda elegida en el formulario.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-3">
                    <div class="grid gap-1.5">
                        <Label for="nueva-cat-nombre">Nombre</Label>
                        <Input
                            id="nueva-cat-nombre"
                            v-model="nombreNuevaCategoria"
                            placeholder="p. ej. Camisola"
                            @keydown.enter.prevent="guardarNuevaCategoria"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Tipo relacionado (opcional)</Label>
                        <BuscadorAsync
                            :model-value="tipoNuevaCategoria"
                            :buscar="buscarTipos"
                            :etiqueta="(t) => (t as OpcionTipo).nombre"
                            placeholder="Sin tipo"
                            placeholder-busqueda="Buscar tipo por nombre"
                            sin-resultados="No hay tipos activos."
                            @update:model-value="
                                (v) =>
                                    (tipoNuevaCategoria =
                                        v as OpcionTipo | null)
                            "
                        />
                    </div>
                    <p
                        v-if="errorNuevaCategoria"
                        class="text-destructive text-xs"
                    >
                        {{ errorNuevaCategoria }}
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="dialogoCategoria = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        :disabled="
                            creandoCategoria || !nombreNuevaCategoria.trim()
                        "
                        @click="guardarNuevaCategoria"
                    >
                        Crear y seleccionar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
