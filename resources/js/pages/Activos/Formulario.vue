<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Opcion = { id: number; nombre: string };

type Activo = {
    id: number;
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

const props = defineProps<{
    activo: Activo | null;
    tallas: { id: number; valor: string }[];
    tiposActivo: Opcion[];
    categorias: Opcion[];
    tiposControl: { valor: string; etiqueta: string }[];
    permisos: { crear_tipo: boolean; crear_categoria: boolean };
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

// Listas locales para poder añadir tipos / categorías creados en línea.
const tiposLocal = ref<Opcion[]>([...props.tiposActivo]);
const categoriasLocal = ref<Opcion[]>([...props.categorias]);

const form = useForm<{
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

// --- Alta rápida en línea (tipo / categoría) ---------------------------------
function xsrf(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

const nuevoTipo = ref('');
const nuevaCategoria = ref('');
const creandoTipo = ref(false);
const creandoCategoria = ref(false);
const mostrarNuevoTipo = ref(false);
const mostrarNuevaCategoria = ref(false);

async function crearRapido(
    url: string,
    nombre: string,
): Promise<Opcion | null> {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ nombre }),
    });
    if (!res.ok) return null;
    const json = await res.json();
    return (json.tipo ?? json.categoria) as Opcion;
}

async function agregarTipo() {
    if (!nuevoTipo.value.trim()) return;
    creandoTipo.value = true;
    const creado = await crearRapido(
        '/tipos-activo/rapido',
        nuevoTipo.value.trim(),
    );
    creandoTipo.value = false;
    if (creado) {
        tiposLocal.value = [...tiposLocal.value, creado].sort((a, b) =>
            a.nombre.localeCompare(b.nombre),
        );
        form.tipo_activo_id = creado.id;
        nuevoTipo.value = '';
        mostrarNuevoTipo.value = false;
    }
}

async function agregarCategoria() {
    if (!nuevaCategoria.value.trim()) return;
    creandoCategoria.value = true;
    const creada = await crearRapido(
        '/categorias-activo/rapido',
        nuevaCategoria.value.trim(),
    );
    creandoCategoria.value = false;
    if (creada) {
        categoriasLocal.value = [...categoriasLocal.value, creada].sort(
            (a, b) => a.nombre.localeCompare(b.nombre),
        );
        form.categoria_id = creada.id;
        nuevaCategoria.value = '';
        mostrarNuevaCategoria.value = false;
    }
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
            descripcion="Los datos pertenecen a la empresa activa. Una PRENDA es un activo individual; un UNIFORME es un conjunto de prendas (módulo Uniformes / Conjuntos)."
        />

        <form class="grid gap-6 lg:grid-cols-2" @submit.prevent="enviar">
            <section class="min-w-0 space-y-4 rounded-xl border p-4">
                <h2 class="text-sm font-semibold">Información</h2>
                <div class="grid gap-4 sm:grid-cols-2">
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
                            <AyudaTooltip
                                texto="Qué es el activo dentro de su tipo (Camisola, Pantalón, Laptop, Teléfono celular…). Se elige de un catálogo por empresa."
                                etiqueta="Ayuda sobre la categoría"
                            />
                        </Label>
                        <div class="flex flex-wrap items-center gap-2">
                            <select
                                id="categoria_id"
                                v-model="form.categoria_id"
                                :class="[selectClass, 'flex-1']"
                            >
                                <option value="">Sin categoría</option>
                                <option
                                    v-for="c in categoriasLocal"
                                    :key="c.id"
                                    :value="c.id"
                                >
                                    {{ c.nombre }}
                                </option>
                            </select>
                            <Button
                                v-if="permisos.crear_categoria"
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="
                                    mostrarNuevaCategoria =
                                        !mostrarNuevaCategoria
                                "
                            >
                                <Plus class="size-4" /> Otra
                            </Button>
                        </div>
                        <div
                            v-if="mostrarNuevaCategoria"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <Input
                                v-model="nuevaCategoria"
                                placeholder="Nombre de la nueva categoría"
                                class="flex-1"
                                @keydown.enter.prevent="agregarCategoria"
                            />
                            <Button
                                type="button"
                                size="sm"
                                :disabled="
                                    creandoCategoria || !nuevaCategoria.trim()
                                "
                                @click="agregarCategoria"
                            >
                                Agregar
                            </Button>
                        </div>
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
                        <AyudaTooltip
                            texto="Naturaleza del activo: Prenda, Equipo de cómputo, Dispositivo móvil, Electrónico, Accesorio, Herramienta / Equipo, Otro. El administrador puede crear tipos nuevos."
                            etiqueta="Ayuda sobre el tipo de activo"
                        />
                    </Label>
                    <div class="flex flex-wrap items-center gap-2">
                        <select
                            id="tipo_activo_id"
                            v-model="form.tipo_activo_id"
                            :class="[selectClass, 'flex-1']"
                        >
                            <option value="">Sin tipo</option>
                            <option
                                v-for="t in tiposLocal"
                                :key="t.id"
                                :value="t.id"
                            >
                                {{ t.nombre }}
                            </option>
                        </select>
                        <Button
                            v-if="permisos.crear_tipo"
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="mostrarNuevoTipo = !mostrarNuevoTipo"
                        >
                            <Plus class="size-4" /> Otro
                        </Button>
                    </div>
                    <div
                        v-if="mostrarNuevoTipo"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <Input
                            v-model="nuevoTipo"
                            placeholder="Nombre del nuevo tipo"
                            class="flex-1"
                            @keydown.enter.prevent="agregarTipo"
                        />
                        <Button
                            type="button"
                            size="sm"
                            :disabled="creandoTipo || !nuevoTipo.trim()"
                            @click="agregarTipo"
                        >
                            Agregar
                        </Button>
                    </div>
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
                            texto="Opcional. No todos los activos usan tallas tradicionales: XS/S/M/L/XL, 28/30/32, 36R/38R, Unitalla… Marca las variantes que aplican a este activo."
                            etiqueta="Ayuda sobre variantes / tallas"
                        />
                    </Label>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="t in tallas"
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
                            v-if="!tallas.length"
                            class="text-muted-foreground text-sm"
                        >
                            No hay variantes / tallas.
                            <Link href="/tallas" class="text-primary underline"
                                >Crea variantes primero</Link
                            >.
                        </p>
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
    </div>
</template>
