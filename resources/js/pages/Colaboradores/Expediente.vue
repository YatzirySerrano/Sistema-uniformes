<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Download,
    Eye,
    FileText,
    History,
    Pencil,
    Plus,
    Power,
    Search,
    UploadCloud,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import SubidaArchivo from '@/components/sistema/SubidaArchivo.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/composables/useInitials';
import { cn } from '@/lib/utils';

type Categoria = { valor: string; etiqueta: string };

type VersionActual = {
    version: number;
    nombre_archivo_original: string;
    mime: string;
    extension: string;
    peso_bytes: number;
    subido_en: string;
    puede_previsualizar: boolean;
};

type Documento = {
    id: number;
    categoria: string;
    nombre: string;
    descripcion: string | null;
    activo: boolean;
    creado_por: string | null;
    total_versiones: number;
    version_actual: VersionActual | null;
};

type VersionHistorial = {
    version: number;
    nombre_archivo_original: string;
    mime: string;
    peso_bytes: number;
    hash_sha256: string;
    comentario: string | null;
    subido_por: string | null;
    subido_en: string;
};

const props = defineProps<{
    colaborador: {
        id: number;
        nombre_completo: string;
        numero_empleado: string;
        foto_url: string | null;
    };
    categorias: Categoria[];
    documentos: Documento[];
    puedeAdministrar: boolean;
    puedeDescargar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Colaboradores', href: '/colaboradores' },
            {
                title: 'Expediente digital',
                href: '#',
            },
        ],
    },
});

const { getInitials } = useInitials();

const base = `/colaboradores/${props.colaborador.id}/expediente`;

const categoriaActiva = ref<string>('todas');
const buscar = ref('');

const opcionesCategoria = computed(() => [
    { valor: 'todas', etiqueta: 'Todas' },
    ...props.categorias,
]);

const conteoPorCategoria = computed(() => {
    const mapa: Record<string, number> = {};
    for (const d of props.documentos) {
        mapa[d.categoria] = (mapa[d.categoria] ?? 0) + 1;
    }
    return mapa;
});

const documentosFiltrados = computed(() => {
    const termino = buscar.value.trim().toLowerCase();

    return props.documentos.filter((d) => {
        const coincideCategoria =
            categoriaActiva.value === 'todas' ||
            d.categoria === categoriaActiva.value;
        const coincideBusqueda =
            termino === '' || d.nombre.toLowerCase().includes(termino);

        return coincideCategoria && coincideBusqueda;
    });
});

function etiquetaCategoria(valor: string): string {
    return props.categorias.find((c) => c.valor === valor)?.etiqueta ?? valor;
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
}>({
    categoria: '',
    nombre: '',
    descripcion: '',
    archivo: null,
});

function abrirSubir(): void {
    formSubir.reset();
    formSubir.clearErrors();
    formSubir.categoria =
        categoriaActiva.value === 'todas'
            ? (props.categorias[0]?.valor ?? '')
            : categoriaActiva.value;
    modalSubir.value = true;
}

function enviarSubir(): void {
    formSubir.post(base, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
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

function abrirNuevaVersion(documento: Documento): void {
    documentoVersion.value = documento;
    formVersion.reset();
    formVersion.clearErrors();
    modalVersion.value = true;
}

function enviarVersion(): void {
    if (!documentoVersion.value) return;
    formVersion.post(`${base}/${documentoVersion.value.id}/version`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
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
}>({
    categoria: '',
    nombre: '',
    descripcion: '',
});

function abrirEditar(documento: Documento): void {
    documentoEditar.value = documento;
    formEditar.categoria = documento.categoria;
    formEditar.nombre = documento.nombre;
    formEditar.descripcion = documento.descripcion ?? '';
    formEditar.clearErrors();
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

// --- Eliminar / restaurar ---
function alternarEstado(documento: Documento): void {
    router.post(`${base}/${documento.id}/estado`, {}, { preserveScroll: true });
}

// --- Historial de versiones ---
const modalHistorial = ref(false);
const documentoHistorial = ref<Documento | null>(null);
const historial = ref<VersionHistorial[]>([]);
const cargandoHistorial = ref(false);

async function abrirHistorial(documento: Documento): Promise<void> {
    documentoHistorial.value = documento;
    modalHistorial.value = true;
    cargandoHistorial.value = true;
    historial.value = [];

    const res = await fetch(`${base}/${documento.id}/versiones`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (res.ok) {
        historial.value = (await res.json()).versiones ?? [];
    }

    cargandoHistorial.value = false;
}
</script>

<template>
    <Head :title="`Expediente de ${colaborador.nombre_completo}`" />

    <div class="flex w-full flex-col gap-4 p-4">
        <Button variant="ghost" size="sm" as-child class="w-fit">
            <Link :href="`/colaboradores/${colaborador.id}`">
                <ArrowLeft class="size-4" /> Volver al perfil
            </Link>
        </Button>

        <div
            class="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex min-w-0 items-center gap-3">
                <Avatar class="size-10 shrink-0">
                    <AvatarImage
                        v-if="colaborador.foto_url"
                        :src="colaborador.foto_url"
                        :alt="colaborador.nombre_completo"
                    />
                    <AvatarFallback>
                        {{ getInitials(colaborador.nombre_completo) }}
                    </AvatarFallback>
                </Avatar>
                <div class="min-w-0">
                    <h1 class="truncate text-lg font-semibold tracking-tight">
                        Expediente digital
                    </h1>
                    <p class="text-muted-foreground truncate text-sm">
                        {{ colaborador.nombre_completo }} ·
                        {{ colaborador.numero_empleado }}
                    </p>
                </div>
            </div>

            <Button v-if="puedeAdministrar" size="sm" @click="abrirSubir">
                <Plus class="size-4" /> Subir documento
            </Button>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative min-w-0 flex-1">
                <Search
                    class="text-muted-foreground absolute top-2.5 left-2.5 size-4"
                />
                <Input
                    v-model="buscar"
                    placeholder="Buscar documento por nombre"
                    class="pl-8"
                />
            </div>
        </div>

        <div
            class="flex flex-wrap gap-1.5"
            role="group"
            aria-label="Carpetas del expediente"
        >
            <button
                v-for="c in opcionesCategoria"
                :key="c.valor"
                type="button"
                :aria-pressed="categoriaActiva === c.valor"
                :class="
                    cn(
                        'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm font-medium transition-colors',
                        categoriaActiva === c.valor
                            ? 'bg-primary text-primary-foreground border-primary'
                            : 'hover:bg-accent text-muted-foreground hover:text-foreground',
                    )
                "
                @click="categoriaActiva = c.valor"
            >
                {{ c.etiqueta }}
                <span v-if="c.valor !== 'todas'" class="text-xs opacity-80">
                    ({{ conteoPorCategoria[c.valor] ?? 0 }})
                </span>
            </button>
        </div>

        <EstadoVacio
            v-if="!documentosFiltrados.length"
            titulo="No hay documentos"
            :descripcion="
                buscar
                    ? 'No encontramos documentos con ese nombre.'
                    : 'Todavía no se han subido documentos en esta carpeta.'
            "
        >
            <template #acciones>
                <Button v-if="puedeAdministrar" size="sm" @click="abrirSubir">
                    Subir documento
                </Button>
            </template>
        </EstadoVacio>

        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="d in documentosFiltrados"
                :key="d.id"
                class="flex flex-col gap-2 rounded-xl border p-4"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="flex min-w-0 items-start gap-2">
                        <FileText
                            class="text-muted-foreground mt-0.5 size-5 shrink-0"
                        />
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ d.nombre }}</p>
                            <p class="text-muted-foreground text-xs">
                                {{ etiquetaCategoria(d.categoria) }}
                            </p>
                        </div>
                    </div>
                    <Badge :variant="d.activo ? 'success' : 'secondary'">
                        {{ d.activo ? 'Activo' : 'Eliminado' }}
                    </Badge>
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
                    <span>{{ formatoPeso(d.version_actual.peso_bytes) }}</span>
                    <span>·</span>
                    <span>v{{ d.version_actual.version }}</span>
                    <span v-if="d.total_versiones > 1"
                        >({{ d.total_versiones }} versiones)</span
                    >
                    <span
                        >· {{ formatoFecha(d.version_actual.subido_en) }}</span
                    >
                </div>
                <p v-else class="text-destructive text-xs">
                    Sin archivo disponible.
                </p>

                <div class="mt-1 flex flex-wrap gap-1.5">
                    <Button
                        v-if="
                            puedeDescargar &&
                            d.version_actual?.puede_previsualizar
                        "
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <a
                            :href="`${base}/${d.id}/ver`"
                            target="_blank"
                            rel="noopener"
                        >
                            <Eye class="size-3.5" /> Ver
                        </a>
                    </Button>
                    <Button
                        v-if="puedeDescargar && d.version_actual"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <a :href="`${base}/${d.id}/descargar`">
                            <Download class="size-3.5" /> Descargar
                        </a>
                    </Button>
                    <Button
                        v-if="puedeAdministrar"
                        variant="outline"
                        size="sm"
                        @click="abrirNuevaVersion(d)"
                    >
                        <UploadCloud class="size-3.5" /> Nueva versión
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="abrirHistorial(d)"
                    >
                        <History class="size-3.5" /> Historial
                    </Button>
                    <Button
                        v-if="puedeAdministrar"
                        variant="ghost"
                        size="sm"
                        @click="abrirEditar(d)"
                    >
                        <Pencil class="size-3.5" /> Editar
                    </Button>
                    <Button
                        v-if="puedeAdministrar"
                        variant="ghost"
                        size="sm"
                        @click="alternarEstado(d)"
                    >
                        <Power class="size-3.5" />
                        {{ d.activo ? 'Eliminar' : 'Restaurar' }}
                    </Button>
                </div>
            </div>
        </div>

        <!-- Subir documento -->
        <Dialog v-model:open="modalSubir">
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
                        <Label for="categoria">Carpeta / categoría</Label>
                        <SelectSimple
                            id="categoria"
                            v-model="formSubir.categoria"
                            :opciones="categorias"
                        />
                        <InputError :message="formSubir.errors.categoria" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="nombre">Nombre del documento</Label>
                        <Input
                            id="nombre"
                            v-model="formSubir.nombre"
                            required
                        />
                        <InputError :message="formSubir.errors.nombre" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="descripcion">Descripción (opcional)</Label>
                        <textarea
                            id="descripcion"
                            v-model="formSubir.descripcion"
                            rows="2"
                            class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        />
                        <InputError :message="formSubir.errors.descripcion" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="archivo">Archivo</Label>
                        <SubidaArchivo
                            id="archivo"
                            v-model="formSubir.archivo"
                            tipo="documento"
                            formatos-etiqueta="PDF, JPG, PNG, WEBP, XLS, XLSX, DOC, DOCX, CSV o TXT"
                            :peso-maximo-mb="10"
                            :invalido="!!formSubir.errors.archivo"
                        />
                        <InputError :message="formSubir.errors.archivo" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="modalSubir = false"
                            >Cancelar</Button
                        >
                        <Button type="submit" :disabled="formSubir.processing"
                            >Subir</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Nueva versión -->
        <Dialog v-model:open="modalVersion">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle
                        >Nueva versión de "{{
                            documentoVersion?.nombre
                        }}"</DialogTitle
                    >
                    <DialogDescription>
                        La versión anterior no se borra: queda disponible en el
                        historial.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="enviarVersion">
                    <div class="grid gap-1.5">
                        <Label for="archivo-version">Archivo nuevo</Label>
                        <SubidaArchivo
                            id="archivo-version"
                            v-model="formVersion.archivo"
                            tipo="documento"
                            formatos-etiqueta="PDF, JPG, PNG, WEBP, XLS, XLSX, DOC, DOCX, CSV o TXT"
                            :peso-maximo-mb="10"
                            :invalido="!!formVersion.errors.archivo"
                        />
                        <InputError :message="formVersion.errors.archivo" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="comentario">Comentario (opcional)</Label>
                        <textarea
                            id="comentario"
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
                            @click="modalVersion = false"
                            >Cancelar</Button
                        >
                        <Button type="submit" :disabled="formVersion.processing"
                            >Guardar versión</Button
                        >
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
                        archivo no se modifica aquí.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="enviarEditar">
                    <div class="grid gap-1.5">
                        <Label for="categoria-editar"
                            >Carpeta / categoría</Label
                        >
                        <SelectSimple
                            id="categoria-editar"
                            v-model="formEditar.categoria"
                            :opciones="categorias"
                        />
                        <InputError :message="formEditar.errors.categoria" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="nombre-editar">Nombre del documento</Label>
                        <Input
                            id="nombre-editar"
                            v-model="formEditar.nombre"
                            required
                        />
                        <InputError :message="formEditar.errors.nombre" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="descripcion-editar"
                            >Descripción (opcional)</Label
                        >
                        <textarea
                            id="descripcion-editar"
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

        <!-- Historial de versiones -->
        <Dialog v-model:open="modalHistorial">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle
                        >Historial de "{{
                            documentoHistorial?.nombre
                        }}"</DialogTitle
                    >
                    <DialogDescription>
                        Ninguna versión anterior se borra al subir una nueva.
                    </DialogDescription>
                </DialogHeader>
                <p
                    v-if="cargandoHistorial"
                    class="text-muted-foreground text-sm"
                >
                    Cargando historial…
                </p>
                <ul v-else class="flex flex-col gap-2">
                    <li
                        v-for="v in historial"
                        :key="v.version"
                        class="flex flex-col gap-1 rounded-lg border p-3 text-sm"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-medium">v{{ v.version }}</span>
                            <Button
                                v-if="puedeDescargar"
                                variant="outline"
                                size="sm"
                                as-child
                            >
                                <a
                                    :href="`${base}/${documentoHistorial?.id}/versiones/${v.version}/descargar`"
                                >
                                    <Download class="size-3.5" /> Descargar
                                </a>
                            </Button>
                        </div>
                        <p class="text-muted-foreground text-xs">
                            {{ v.nombre_archivo_original }} ·
                            {{ formatoPeso(v.peso_bytes) }} ·
                            {{ formatoFecha(v.subido_en) }}
                            <template v-if="v.subido_por">
                                · {{ v.subido_por }}</template
                            >
                        </p>
                        <p v-if="v.comentario" class="text-sm text-pretty">
                            {{ v.comentario }}
                        </p>
                    </li>
                </ul>
            </DialogContent>
        </Dialog>
    </div>
</template>
