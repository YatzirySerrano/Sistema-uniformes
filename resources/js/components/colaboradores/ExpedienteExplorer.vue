<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Download,
    Eye,
    History,
    MoreHorizontal,
    Pencil,
    Plus,
    Power,
    Search,
    UploadCloud,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import HistorialVersionesSheet from '@/components/colaboradores/HistorialVersionesSheet.vue';
import PreviewDocumentoDialog from '@/components/colaboradores/PreviewDocumentoDialog.vue';
import InputError from '@/components/InputError.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import SubidaArchivo from '@/components/sistema/SubidaArchivo.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { estiloCategoria, iconoDocumento } from '@/lib/categoriasExpediente';
import { useVistaPreferida } from '@/composables/useVistaPreferida';

export type Categoria = { valor: string; etiqueta: string };

export type VersionActual = {
    version: number;
    nombre_archivo_original: string;
    mime: string;
    extension: string;
    peso_bytes: number;
    subido_en: string;
    puede_previsualizar: boolean;
};

export type Documento = {
    id: number;
    categoria: string;
    nombre: string;
    descripcion: string | null;
    activo: boolean;
    creado_por: string | null;
    total_versiones: number;
    version_actual: VersionActual | null;
};

export type ColaboradorMini = {
    id: number;
    nombre_completo: string;
    numero_empleado: string;
    foto_url: string | null;
};

const props = defineProps<{
    colaborador: ColaboradorMini;
    categorias: Categoria[];
    documentos: Documento[];
    puedeAdministrar: boolean;
    puedeDescargar: boolean;
    /** Permite ocultar el encabezado con foto/nombre cuando ya se muestra en la página contenedora (perfil). */
    mostrarEncabezado?: boolean;
}>();

const base = `/colaboradores/${props.colaborador.id}/expediente`;

const carpetaActiva = ref<string | null>(null);
const buscar = ref('');
const orden = ref<string>('reciente');
const vista = useVistaPreferida('expediente-documentos', 'cards');

const opcionesOrden = [
    { valor: 'reciente', etiqueta: 'Más reciente' },
    { valor: 'antiguo', etiqueta: 'Más antiguo' },
    { valor: 'nombre-asc', etiqueta: 'Nombre A-Z' },
    { valor: 'nombre-desc', etiqueta: 'Nombre Z-A' },
];

const conteoPorCategoria = computed(() => {
    const mapa: Record<string, number> = {};
    for (const d of props.documentos) {
        mapa[d.categoria] = (mapa[d.categoria] ?? 0) + 1;
    }
    return mapa;
});

function etiquetaCategoria(valor: string): string {
    return props.categorias.find((c) => c.valor === valor)?.etiqueta ?? valor;
}

function coincide(d: Documento, termino: string): boolean {
    return (
        d.nombre.toLowerCase().includes(termino) ||
        (d.descripcion ?? '').toLowerCase().includes(termino) ||
        (d.creado_por ?? '').toLowerCase().includes(termino) ||
        (d.version_actual?.nombre_archivo_original ?? '')
            .toLowerCase()
            .includes(termino)
    );
}

function ordenar(lista: Documento[]): Documento[] {
    const copia = [...lista];
    copia.sort((a, b) => {
        switch (orden.value) {
            case 'nombre-asc':
                return a.nombre.localeCompare(b.nombre);
            case 'nombre-desc':
                return b.nombre.localeCompare(a.nombre);
            case 'antiguo':
                return (a.version_actual?.subido_en ?? '').localeCompare(
                    b.version_actual?.subido_en ?? '',
                );
            case 'reciente':
            default:
                return (b.version_actual?.subido_en ?? '').localeCompare(
                    a.version_actual?.subido_en ?? '',
                );
        }
    });
    return copia;
}

// Con una carpeta abierta: sus documentos (filtrados por búsqueda). Sin
// carpeta abierta pero con texto en el buscador: resultados de TODO el
// expediente (no sólo de una carpeta).
const documentosMostrados = computed(() => {
    const termino = buscar.value.trim().toLowerCase();

    if (carpetaActiva.value) {
        let lista = props.documentos.filter(
            (d) => d.categoria === carpetaActiva.value,
        );
        if (termino) lista = lista.filter((d) => coincide(d, termino));
        return ordenar(lista);
    }

    if (termino) {
        return ordenar(props.documentos.filter((d) => coincide(d, termino)));
    }

    return [];
});

const mostrandoCarpetas = computed(
    () => !carpetaActiva.value && buscar.value.trim() === '',
);

function abrirCarpeta(valor: string): void {
    carpetaActiva.value = valor;
    buscar.value = '';
}

function formatoPeso(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function formatoFecha(iso: string): string {
    return new Date(iso).toLocaleString('es-MX', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

// --- Subir documento ---
const modalSubir = ref(false);
const formSubir = useForm<{
    categoria: string;
    nombre: string;
    descripcion: string;
    archivo: File | null;
}>({ categoria: '', nombre: '', descripcion: '', archivo: null });

function resetSubida(): void {
    formSubir.reset();
    formSubir.clearErrors();
    formSubir.categoria =
        carpetaActiva.value ?? props.categorias[0]?.valor ?? '';
}

function abrirSubir(): void {
    resetSubida();
    modalSubir.value = true;
}

function alCambiarAperturaSubir(abierto: boolean): void {
    modalSubir.value = abierto;
    if (!abierto) resetSubida();
}

function enviarSubir(): void {
    formSubir.post(base, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            resetSubida();
            modalSubir.value = false;
        },
    });
}

// --- Nueva versión ---
const modalVersion = ref(false);
const documentoVersion = ref<Documento | null>(null);
const formVersion = useForm<{ archivo: File | null; comentario: string }>({
    archivo: null,
    comentario: '',
});

function resetVersion(): void {
    formVersion.reset();
    formVersion.clearErrors();
}

function abrirNuevaVersion(documento: Documento): void {
    documentoVersion.value = documento;
    resetVersion();
    modalVersion.value = true;
}

function alCambiarAperturaVersion(abierto: boolean): void {
    modalVersion.value = abierto;
    if (!abierto) resetVersion();
}

function enviarVersion(): void {
    if (!documentoVersion.value) return;
    formVersion.post(`${base}/${documentoVersion.value.id}/version`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            resetVersion();
            modalVersion.value = false;
        },
    });
}

// --- Editar metadatos ---
const modalEditar = ref(false);
const documentoEditar = ref<Documento | null>(null);
const formEditar = useForm<{
    categoria: string;
    nombre: string;
    descripcion: string;
}>({ categoria: '', nombre: '', descripcion: '' });

function abrirEditar(documento: Documento): void {
    documentoEditar.value = documento;
    formEditar.clearErrors();
    formEditar.categoria = documento.categoria;
    formEditar.nombre = documento.nombre;
    formEditar.descripcion = documento.descripcion ?? '';
    modalEditar.value = true;
}

function enviarEditar(): void {
    if (!documentoEditar.value) return;
    formEditar.put(`${base}/${documentoEditar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            modalEditar.value = false;
        },
    });
}

// --- Eliminar / restaurar (con confirmación sólo para eliminar) ---
const modalEliminar = ref(false);
const documentoAEliminar = ref<Documento | null>(null);
const procesandoEliminar = ref(false);

function alternarEstado(documento: Documento): void {
    if (documento.activo) {
        documentoAEliminar.value = documento;
        modalEliminar.value = true;
    } else {
        router.post(
            `${base}/${documento.id}/estado`,
            {},
            { preserveScroll: true },
        );
    }
}

function confirmarEliminar(): void {
    if (!documentoAEliminar.value) return;
    procesandoEliminar.value = true;
    router.post(
        `${base}/${documentoAEliminar.value.id}/estado`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                procesandoEliminar.value = false;
                modalEliminar.value = false;
            },
        },
    );
}

// --- Preview ---
const modalPreview = ref(false);
const documentoPreview = ref<Documento | null>(null);

function abrirPreview(documento: Documento): void {
    documentoPreview.value = documento;
    modalPreview.value = true;
}

// --- Historial ---
const modalHistorial = ref(false);
const documentoHistorial = ref<Documento | null>(null);

function abrirHistorial(documento: Documento): void {
    documentoHistorial.value = documento;
    modalHistorial.value = true;
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div
            v-if="mostrarEncabezado"
            class="flex flex-wrap items-center justify-between gap-2"
        >
            <p class="text-muted-foreground text-sm">
                📁 {{ colaborador.nombre_completo }} / Expediente
            </p>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative min-w-0 flex-1">
                <Search
                    class="text-muted-foreground absolute top-2.5 left-2.5 size-4"
                />
                <Input
                    v-model="buscar"
                    placeholder="Buscar documento por nombre, descripción o quién lo subió…"
                    class="pl-8"
                />
            </div>
            <div v-if="!mostrandoCarpetas" class="w-full sm:w-48">
                <SelectSimple v-model="orden" :opciones="opcionesOrden" />
            </div>
            <SelectorVista v-model="vista" />
            <Button v-if="puedeAdministrar" size="sm" @click="abrirSubir">
                <Plus class="size-4" /> Subir documento
            </Button>
        </div>

        <!-- Carpetas -->
        <div
            v-if="mostrandoCarpetas"
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6"
        >
            <button
                v-for="c in categorias"
                :key="c.valor"
                type="button"
                :class="[
                    'flex flex-col items-start gap-2 rounded-xl border p-4 text-left transition-all hover:-translate-y-0.5 hover:shadow-md',
                    estiloCategoria(c.valor).clases,
                ]"
                @click="abrirCarpeta(c.valor)"
            >
                <component
                    :is="estiloCategoria(c.valor).icono"
                    class="size-6"
                />
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ c.etiqueta }}</p>
                    <p class="text-xs opacity-80">
                        {{ conteoPorCategoria[c.valor] ?? 0 }}
                        {{
                            (conteoPorCategoria[c.valor] ?? 0) === 1
                                ? 'documento'
                                : 'documentos'
                        }}
                    </p>
                </div>
            </button>
        </div>

        <!-- Breadcrumb de carpeta abierta -->
        <div v-else-if="carpetaActiva" class="flex items-center gap-2 text-sm">
            <Button variant="ghost" size="sm" @click="carpetaActiva = null">
                <ArrowLeft class="size-4" /> Expediente
            </Button>
            <span class="text-muted-foreground">/</span>
            <component
                :is="estiloCategoria(carpetaActiva).icono"
                class="size-4"
            />
            <span class="font-medium">{{
                etiquetaCategoria(carpetaActiva)
            }}</span>
            <span class="text-muted-foreground"
                >({{ documentosMostrados.length }})</span
            >
        </div>

        <!-- Documentos -->
        <template v-if="!mostrandoCarpetas">
            <EstadoVacio
                v-if="!documentosMostrados.length"
                titulo="No hay documentos"
                :descripcion="
                    buscar.trim()
                        ? 'No encontramos documentos con ese criterio.'
                        : 'Todavía no se han subido documentos en esta carpeta.'
                "
            >
                <template #acciones>
                    <Button
                        v-if="puedeAdministrar"
                        size="sm"
                        @click="abrirSubir"
                    >
                        Subir documento
                    </Button>
                </template>
            </EstadoVacio>

            <div
                v-else-if="vista === 'cards'"
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <div
                    v-for="d in documentosMostrados"
                    :key="d.id"
                    :class="[
                        'flex flex-col gap-2 rounded-xl border p-4 transition-shadow hover:shadow-sm',
                        d.activo ? '' : 'bg-muted/40 opacity-70',
                    ]"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 items-start gap-2">
                            <component
                                :is="
                                    iconoDocumento(d.version_actual?.mime ?? '')
                                "
                                class="text-muted-foreground mt-0.5 size-5 shrink-0"
                            />
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ d.nombre }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    {{ etiquetaCategoria(d.categoria) }}
                                </p>
                            </div>
                        </div>
                        <Badge v-if="!d.activo" variant="secondary"
                            >Eliminado</Badge
                        >
                    </div>

                    <p
                        v-if="d.descripcion"
                        class="text-muted-foreground text-sm text-pretty"
                    >
                        {{ d.descripcion }}
                    </p>

                    <div
                        v-if="d.version_actual"
                        class="text-muted-foreground flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs"
                    >
                        <span class="font-mono uppercase">{{
                            d.version_actual.extension
                        }}</span>
                        <span>·</span>
                        <span>{{
                            formatoPeso(d.version_actual.peso_bytes)
                        }}</span>
                        <span>·</span>
                        <span
                            >v{{ d.version_actual.version
                            }}<template v-if="d.total_versiones > 1">
                                ({{ d.total_versiones }})</template
                            ></span
                        >
                        <span
                            >·
                            {{ formatoFecha(d.version_actual.subido_en) }}</span
                        >
                    </div>
                    <p v-else class="text-destructive text-xs">
                        Sin archivo disponible.
                    </p>
                    <p class="text-muted-foreground text-xs">
                        Subido por: {{ d.creado_por ?? '—' }}
                    </p>

                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                        <Button
                            v-if="
                                puedeDescargar &&
                                d.version_actual?.puede_previsualizar
                            "
                            size="sm"
                            @click="abrirPreview(d)"
                        >
                            <Eye class="size-3.5" /> Ver
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    aria-label="Más acciones del documento"
                                >
                                    <MoreHorizontal class="size-3.5" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    v-if="puedeDescargar && d.version_actual"
                                    as-child
                                >
                                    <a
                                        :href="`${base}/${d.id}/descargar`"
                                        class="flex w-full cursor-pointer items-center"
                                    >
                                        <Download class="mr-2 size-4" />
                                        Descargar
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="puedeAdministrar"
                                    @click="abrirNuevaVersion(d)"
                                >
                                    <UploadCloud class="mr-2 size-4" /> Nueva
                                    versión
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="puedeAdministrar"
                                    @click="abrirEditar(d)"
                                >
                                    <Pencil class="mr-2 size-4" /> Editar
                                    información
                                </DropdownMenuItem>
                                <DropdownMenuItem @click="abrirHistorial(d)">
                                    <History class="mr-2 size-4" /> Historial
                                </DropdownMenuItem>
                                <DropdownMenuSeparator
                                    v-if="puedeAdministrar"
                                />
                                <DropdownMenuItem
                                    v-if="puedeAdministrar"
                                    variant="destructive"
                                    @click="alternarEstado(d)"
                                >
                                    <Power class="mr-2 size-4" />
                                    {{ d.activo ? 'Eliminar' : 'Restaurar' }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
            </div>

            <div v-else class="flex flex-col divide-y rounded-xl border">
                <div
                    v-for="d in documentosMostrados"
                    :key="d.id"
                    :class="[
                        'flex flex-wrap items-center gap-3 px-3 py-2.5 text-sm',
                        d.activo ? '' : 'bg-muted/30 opacity-70',
                    ]"
                >
                    <component
                        :is="iconoDocumento(d.version_actual?.mime ?? '')"
                        class="text-muted-foreground size-5 shrink-0"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ d.nombre }}</p>
                        <p class="text-muted-foreground truncate text-xs">
                            v{{ d.version_actual?.version ?? '—' }} ·
                            {{
                                d.version_actual
                                    ? formatoPeso(d.version_actual.peso_bytes)
                                    : '—'
                            }}
                            ·
                            {{
                                d.version_actual
                                    ? formatoFecha(d.version_actual.subido_en)
                                    : '—'
                            }}
                            · Subido por {{ d.creado_por ?? '—' }}
                        </p>
                    </div>
                    <Badge v-if="!d.activo" variant="secondary"
                        >Eliminado</Badge
                    >
                    <Button
                        v-if="
                            puedeDescargar &&
                            d.version_actual?.puede_previsualizar
                        "
                        variant="outline"
                        size="sm"
                        @click="abrirPreview(d)"
                    >
                        <Eye class="size-3.5" />
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="outline"
                                size="sm"
                                aria-label="Más acciones del documento"
                            >
                                <MoreHorizontal class="size-3.5" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                v-if="puedeDescargar && d.version_actual"
                                as-child
                            >
                                <a
                                    :href="`${base}/${d.id}/descargar`"
                                    class="flex w-full cursor-pointer items-center"
                                >
                                    <Download class="mr-2 size-4" /> Descargar
                                </a>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="puedeAdministrar"
                                @click="abrirNuevaVersion(d)"
                            >
                                <UploadCloud class="mr-2 size-4" /> Nueva
                                versión
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="puedeAdministrar"
                                @click="abrirEditar(d)"
                            >
                                <Pencil class="mr-2 size-4" /> Editar
                                información
                            </DropdownMenuItem>
                            <DropdownMenuItem @click="abrirHistorial(d)">
                                <History class="mr-2 size-4" /> Historial
                            </DropdownMenuItem>
                            <DropdownMenuSeparator v-if="puedeAdministrar" />
                            <DropdownMenuItem
                                v-if="puedeAdministrar"
                                variant="destructive"
                                @click="alternarEstado(d)"
                            >
                                <Power class="mr-2 size-4" />
                                {{ d.activo ? 'Eliminar' : 'Restaurar' }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </template>

        <!-- Subir documento -->
        <Dialog :open="modalSubir" @update:open="alCambiarAperturaSubir">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Subir documento</DialogTitle>
                    <DialogDescription>
                        El documento se guarda en la carpeta elegida y queda
                        disponible sólo para quien tenga permiso de verlo.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="enviarSubir">
                    <div class="grid gap-1.5">
                        <Label for="ee-categoria">Carpeta / categoría</Label>
                        <SelectSimple
                            id="ee-categoria"
                            v-model="formSubir.categoria"
                            :opciones="categorias"
                        />
                        <InputError :message="formSubir.errors.categoria" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="ee-nombre">Nombre del documento</Label>
                        <Input
                            id="ee-nombre"
                            v-model="formSubir.nombre"
                            required
                        />
                        <InputError :message="formSubir.errors.nombre" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="ee-descripcion"
                            >Descripción (opcional)</Label
                        >
                        <textarea
                            id="ee-descripcion"
                            v-model="formSubir.descripcion"
                            rows="2"
                            class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        />
                        <InputError :message="formSubir.errors.descripcion" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="ee-archivo">Archivo</Label>
                        <SubidaArchivo
                            id="ee-archivo"
                            v-model="formSubir.archivo"
                            tipo="documento"
                            formatos-etiqueta="PDF, JPG, PNG, WEBP, XLS, XLSX, DOC, DOCX, CSV o TXT"
                            :peso-maximo-mb="10"
                            :cargando="formSubir.processing"
                            :invalido="!!formSubir.errors.archivo"
                        />
                        <InputError :message="formSubir.errors.archivo" />
                        <div
                            v-if="formSubir.progress"
                            class="bg-muted h-1.5 w-full overflow-hidden rounded-full"
                        >
                            <div
                                class="bg-primary h-full transition-all"
                                :style="{
                                    width:
                                        (formSubir.progress.percentage ?? 0) +
                                        '%',
                                }"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            :disabled="formSubir.processing"
                            @click="alCambiarAperturaSubir(false)"
                            >Cancelar</Button
                        >
                        <Button type="submit" :disabled="formSubir.processing">
                            {{ formSubir.processing ? 'Subiendo…' : 'Subir' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Nueva versión -->
        <Dialog :open="modalVersion" @update:open="alCambiarAperturaVersion">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle
                        >Nueva versión de "{{
                            documentoVersion?.nombre
                        }}"</DialogTitle
                    >
                    <DialogDescription>
                        Se conservarán las versiones anteriores: no se borra ni
                        se reemplaza nada del historial.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="enviarVersion">
                    <div class="grid gap-1.5">
                        <Label for="ee-archivo-version">Archivo nuevo</Label>
                        <SubidaArchivo
                            id="ee-archivo-version"
                            v-model="formVersion.archivo"
                            tipo="documento"
                            formatos-etiqueta="PDF, JPG, PNG, WEBP, XLS, XLSX, DOC, DOCX, CSV o TXT"
                            :peso-maximo-mb="10"
                            :cargando="formVersion.processing"
                            :invalido="!!formVersion.errors.archivo"
                        />
                        <InputError :message="formVersion.errors.archivo" />
                        <div
                            v-if="formVersion.progress"
                            class="bg-muted h-1.5 w-full overflow-hidden rounded-full"
                        >
                            <div
                                class="bg-primary h-full transition-all"
                                :style="{
                                    width:
                                        (formVersion.progress.percentage ?? 0) +
                                        '%',
                                }"
                            />
                        </div>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="ee-comentario">Comentario (opcional)</Label>
                        <textarea
                            id="ee-comentario"
                            v-model="formVersion.comentario"
                            rows="2"
                            class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        />
                        <InputError :message="formVersion.errors.comentario" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            :disabled="formVersion.processing"
                            @click="alCambiarAperturaVersion(false)"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            :disabled="formVersion.processing"
                        >
                            {{
                                formVersion.processing
                                    ? 'Guardando…'
                                    : 'Guardar versión'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Editar metadatos -->
        <Dialog v-model:open="modalEditar">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Editar documento</DialogTitle>
                    <DialogDescription>
                        Cambia el nombre, la carpeta o la descripción. El
                        archivo actual no se modifica aquí.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="enviarEditar">
                    <div class="grid gap-1.5">
                        <Label for="ee-categoria-editar"
                            >Carpeta / categoría</Label
                        >
                        <SelectSimple
                            id="ee-categoria-editar"
                            v-model="formEditar.categoria"
                            :opciones="categorias"
                        />
                        <InputError :message="formEditar.errors.categoria" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="ee-nombre-editar"
                            >Nombre del documento</Label
                        >
                        <Input
                            id="ee-nombre-editar"
                            v-model="formEditar.nombre"
                            required
                        />
                        <InputError :message="formEditar.errors.nombre" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="ee-descripcion-editar"
                            >Descripción (opcional)</Label
                        >
                        <textarea
                            id="ee-descripcion-editar"
                            v-model="formEditar.descripcion"
                            rows="2"
                            class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        />
                        <InputError :message="formEditar.errors.descripcion" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            :disabled="formEditar.processing"
                            @click="modalEditar = false"
                            >Cancelar</Button
                        >
                        <Button type="submit" :disabled="formEditar.processing"
                            >Guardar cambios</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Eliminar documento (confirmación) -->
        <Dialog v-model:open="modalEliminar">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >¿Eliminar "{{
                            documentoAEliminar?.nombre
                        }}"?</DialogTitle
                    >
                    <DialogDescription>
                        El documento dejará de mostrarse en el expediente
                        activo. Podrás restaurarlo cuando quieras y su historial
                        de versiones se conserva completo.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        :disabled="procesandoEliminar"
                        @click="modalEliminar = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="procesandoEliminar"
                        @click="confirmarEliminar"
                    >
                        Eliminar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <PreviewDocumentoDialog
            v-model:open="modalPreview"
            :nombre="documentoPreview?.nombre ?? ''"
            :version="documentoPreview?.version_actual ?? null"
            :ver-url="`${base}/${documentoPreview?.id}/ver`"
            :descargar-url="`${base}/${documentoPreview?.id}/descargar`"
        />

        <HistorialVersionesSheet
            v-model:open="modalHistorial"
            :nombre="documentoHistorial?.nombre ?? ''"
            :versiones-url="`${base}/${documentoHistorial?.id}/versiones`"
            :descargar-version-url-base="`${base}/${documentoHistorial?.id}/versiones`"
        />
    </div>
</template>
