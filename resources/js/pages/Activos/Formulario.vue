<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
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
    tipo_control: 'cantidad' | 'serializado';
    activo: boolean;
    imagen_url: string | null;
    tallas: number[];
};

type Variante = { id: number; valor: string };
type Catalogo = { tallas: Variante[] };

const props = defineProps<{
    activo: Activo | null;
    seleccion: { tipo: OpcionTipo | null; categoria: OpcionCategoria | null };
    empresasAutorizadas: EmpresaAutorizada[];
    catalogosPorEmpresa: Record<number, Catalogo>;
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

const empresaId = ref<number | ''>(
    props.activo?.empresa_id ??
        (props.empresasAutorizadas.length === 1
            ? props.empresasAutorizadas[0].id
            : ''),
);

function tallasDe(id: number | ''): Variante[] {
    return (id !== '' && props.catalogosPorEmpresa[id]?.tallas) || [];
}

// Las variantes / tallas siguen viajando por empresa (lista corta y con
// checkboxes). Tipo y categoría se buscan en vivo con la empresa como
// dependencia (BuscadorAsync).
const tallasLocal = ref<Variante[]>([...tallasDe(empresaId.value)]);

// Objeto seleccionado en cada combobox (el id vive en `form`).
const tipoSel = ref<OpcionTipo | null>(props.seleccion.tipo);
const categoriaSel = ref<OpcionCategoria | null>(props.seleccion.categoria);
const avisoCategoria = ref('');

// Al cambiar de empresa: se limpia todo lo dependiente y BuscadorAsync descarta
// sus resultados (prop `dependencia`).
watch(empresaId, (id) => {
    tallasLocal.value = [...tallasDe(id)];
    form.empresa_id = id === '' ? null : id;
    form.tipo_activo_id = '';
    form.categoria_id = '';
    form.tallas = [];
    tipoSel.value = null;
    categoriaSel.value = null;
    avisoCategoria.value = '';
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
});

const esSerializado = computed(() => form.tipo_control === 'serializado');

// Un activo serializado no usa variantes/tallas: no se envían al backend.
watch(esSerializado, (serializado) => {
    if (serializado) form.tallas = [];
});

function xsrf(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

// --- Tipo de activo (combobox con búsqueda + alta inline) --------------------
async function buscarTipos(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionTipo[]> {
    if (empresaId.value === '') return [];
    const res = await fetch(
        `/tipos-activo/buscar?empresa_id=${empresaId.value}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
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
async function buscarCategorias(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionCategoria[]> {
    if (empresaId.value === '') return [];
    const tipo = form.tipo_activo_id
        ? `&tipo_activo_id=${form.tipo_activo_id}`
        : '';
    const res = await fetch(
        `/categorias-activo/buscar?empresa_id=${empresaId.value}&q=${encodeURIComponent(q)}${tipo}`,
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
        body: JSON.stringify({ ...cuerpo, empresa_id: empresaId.value }),
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
        body: JSON.stringify({
            valor: nuevaVariante.value.trim(),
            empresa_id: empresaId.value,
        }),
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

const selectClass =
    'border-input bg-background h-9 min-w-0 rounded-md border px-3 text-sm';
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
                        <select
                            id="empresa_id"
                            v-model="empresaId"
                            :class="selectClass"
                        >
                            <option value="" disabled>
                                Selecciona una empresa
                            </option>
                            <option
                                v-for="e in empresasAutorizadas"
                                :key="e.id"
                                :value="e.id"
                            >
                                {{ e.nombre_comercial }}
                            </option>
                        </select>
                        <p class="text-muted-foreground text-xs">
                            Los tipos, categorías y variantes disponibles
                            dependen de la empresa.
                        </p>
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
                                texto="Qué es el activo dentro de su tipo (Camisola, Pantalón, Laptop, Teléfono celular…). Se elige de un catálogo por empresa. Puedes dejarlo en blanco."
                                etiqueta="Ayuda sobre la categoría"
                            />
                        </Label>
                        <BuscadorAsync
                            id="categoria_id"
                            :model-value="categoriaSel"
                            :buscar="buscarCategorias"
                            :dependencia="`${empresaId}|${form.tipo_activo_id}`"
                            :disabled="empresaId === ''"
                            :etiqueta="(c) => (c as OpcionCategoria).nombre"
                            :descripcion="
                                (c) => (c as OpcionCategoria).tipo ?? 'Sin tipo'
                            "
                            :placeholder="
                                empresaId === ''
                                    ? 'Selecciona primero una empresa'
                                    : 'Sin categoría'
                            "
                            placeholder-busqueda="Buscar categoría por nombre"
                            sin-resultados="No hay categorías activas para esta empresa."
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
                        :dependencia="empresaId"
                        :disabled="empresaId === ''"
                        :etiqueta="(t) => (t as OpcionTipo).nombre"
                        :placeholder="
                            empresaId === ''
                                ? 'Selecciona primero una empresa'
                                : 'Sin tipo'
                        "
                        placeholder-busqueda="Buscar tipo por nombre"
                        sin-resultados="No hay tipos activos para esta empresa."
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
                            texto="«Por cantidad»: se controla por existencias (uniformes, accesorios). «Serializado»: cada unidad se identifica por número de serie / IMEI (laptops, teléfonos). Las series se capturan al ingresar existencias al almacén."
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
                    v-if="esSerializado"
                    class="rounded-lg border border-dashed p-3"
                >
                    <p class="text-sm font-medium">
                        Configuración de activo serializado
                    </p>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Este activo no usa variantes / tallas. Los números de
                        serie, IMEI y etiquetas patrimoniales se registran por
                        unidad al ingresar existencias al almacén (flujo de
                        unidades serializadas — próxima fase).
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
                            :class="
                                form.tallas.includes(t.id)
                                    ? 'border-primary bg-primary/10'
                                    : ''
                            "
                        >
                            <input
                                v-model="form.tallas"
                                type="checkbox"
                                :value="t.id"
                                class="size-3.5"
                            />
                            {{ t.valor }}
                        </label>
                        <p
                            v-if="!tallasLocal.length"
                            class="text-muted-foreground text-sm"
                        >
                            Aún no hay variantes en la empresa.
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
                class="min-w-0 space-y-4 rounded-xl border p-4 lg:col-span-2"
            >
                <h2 class="text-sm font-semibold">Imagen y estado</h2>
                <div class="grid gap-1.5">
                    <Label for="imagen">Imagen (opcional)</Label>
                    <input
                        id="imagen"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="text-sm"
                        @change="
                            form.imagen =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                    <p class="text-muted-foreground text-xs">
                        Formatos aceptados: JPG, PNG o WebP. Peso máximo:
                        {{ PESO_MAXIMO_MB }} MB.
                    </p>
                    <img
                        v-if="activo?.imagen_url"
                        :src="activo.imagen_url"
                        class="mt-1 h-24 w-24 rounded-md object-cover"
                        alt=""
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
                        Se agrega al catálogo de la empresa seleccionada y queda
                        elegido en el formulario.
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
                        Se agrega al catálogo de la empresa seleccionada y queda
                        elegida en el formulario.
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
                            :dependencia="empresaId"
                            :etiqueta="(t) => (t as OpcionTipo).nombre"
                            placeholder="Sin tipo"
                            placeholder-busqueda="Buscar tipo por nombre"
                            sin-resultados="No hay tipos activos para esta empresa."
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
