<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    Camera,
    FileText,
    FolderOpen,
    Mail,
    Package,
    Pencil,
    Power,
    RefreshCcw,
    ScrollText,
    Truck,
} from '@lucide/vue';
import { ref } from 'vue';
import CambiarFotoDialog from '@/components/colaboradores/CambiarFotoDialog.vue';
import ExpedienteExplorer from '@/components/colaboradores/ExpedienteExplorer.vue';
import FormularioColaborador from '@/components/colaboradores/FormularioColaborador.vue';
import type { ColaboradorEditable } from '@/components/colaboradores/FormularioColaborador.vue';
import type {
    Categoria,
    Documento,
} from '@/components/colaboradores/ExpedienteExplorer.vue';
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
import { useInitials } from '@/composables/useInitials';
import { cn } from '@/lib/utils';

type ColaboradorPerfil = ColaboradorEditable & {
    empresa_nombre: string | null;
};

type ExpedientePayload = {
    id: number;
    nombre_completo: string;
    numero_empleado: string;
    foto_url: string | null;
    categorias: Categoria[];
    documentos: Documento[];
    puedeAdministrar: boolean;
    puedeDescargar: boolean;
    puedeVerEliminados: boolean;
    filtroEstado: string;
};

const props = defineProps<{
    colaborador: ColaboradorPerfil;
    kpis: {
        documentos: number;
        entregas: number;
        devoluciones: number;
        activos_asignados: number;
    };
    puedeEditar: boolean;
    puedeEliminar: boolean;
    puedeVerExpediente: boolean;
    expediente: ExpedientePayload | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Colaboradores', href: '/colaboradores' },
            { title: 'Perfil', href: '#' },
        ],
    },
});

const { getInitials } = useInitials();

const seccion = ref<'resumen' | 'expediente'>('resumen');

// --- Editar (modal, no navega) ---
const modalEditar = ref(false);
const claveFormularioEditar = ref(0);

function abrirEditar(): void {
    claveFormularioEditar.value++;
    modalEditar.value = true;
}

function alGuardarEditar(): void {
    modalEditar.value = false;
}

// --- Eliminar / restaurar ---
const modalEstado = ref(false);
const procesandoEstado = ref(false);

function alternarEstado(): void {
    if (props.colaborador.activo) {
        modalEstado.value = true;
    } else {
        router.post(
            `/colaboradores/${props.colaborador.id}/estado`,
            {},
            { preserveScroll: true },
        );
    }
}

function confirmarEliminar(): void {
    procesandoEstado.value = true;
    router.post(
        `/colaboradores/${props.colaborador.id}/estado`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                procesandoEstado.value = false;
                modalEstado.value = false;
            },
        },
    );
}

// --- Foto de perfil ---
const modalFoto = ref(false);
</script>

<template>
    <Head :title="colaborador.nombre_completo" />

    <div class="flex w-full flex-col gap-4 p-4">
        <Button variant="ghost" size="sm" as-child class="w-fit">
            <Link href="/colaboradores">
                <ArrowLeft class="size-4" /> Volver a colaboradores
            </Link>
        </Button>

        <div
            class="flex flex-col gap-4 rounded-xl border p-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div
                class="flex flex-col items-center gap-3 text-center sm:flex-row sm:items-center sm:text-left"
            >
                <div class="group relative shrink-0">
                    <Avatar class="size-20 sm:size-16">
                        <AvatarImage
                            v-if="colaborador.foto_url"
                            :src="colaborador.foto_url"
                            :alt="colaborador.nombre_completo"
                        />
                        <AvatarFallback class="text-xl">
                            {{ getInitials(colaborador.nombre_completo) }}
                        </AvatarFallback>
                    </Avatar>
                    <button
                        v-if="puedeEditar"
                        type="button"
                        class="absolute inset-0 flex items-center justify-center rounded-full bg-black/50 text-white opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                        aria-label="Cambiar foto de perfil"
                        @click="modalFoto = true"
                    >
                        <Camera class="size-5" />
                    </button>
                </div>
                <div class="min-w-0">
                    <h1 class="truncate text-xl font-semibold tracking-tight">
                        {{ colaborador.nombre_completo }}
                    </h1>
                    <p class="text-muted-foreground font-mono text-sm">
                        {{ colaborador.numero_empleado }}
                    </p>
                    <div
                        class="mt-1.5 flex flex-wrap items-center justify-center gap-2 sm:justify-start"
                    >
                        <Badge
                            v-if="colaborador.empresa_nombre"
                            variant="outline"
                            class="gap-1"
                        >
                            <Building2 class="size-3" />
                            {{ colaborador.empresa_nombre }}
                        </Badge>
                        <Badge
                            :variant="
                                colaborador.activo ? 'success' : 'secondary'
                            "
                        >
                            {{ colaborador.activo ? 'Activo' : 'Eliminado' }}
                        </Badge>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap justify-center gap-2 sm:justify-end">
                <Button
                    v-if="puedeEditar"
                    variant="outline"
                    size="sm"
                    @click="abrirEditar"
                >
                    <Pencil class="size-3.5" /> Editar
                </Button>
                <Button
                    v-if="puedeEliminar"
                    :variant="colaborador.activo ? 'ghost' : 'default'"
                    size="sm"
                    @click="alternarEstado"
                >
                    <Power class="size-3.5" />
                    {{ colaborador.activo ? 'Eliminar' : 'Restaurar' }}
                </Button>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border p-3">
                <p
                    class="text-muted-foreground flex items-center gap-1 text-xs"
                >
                    <FileText class="size-3" /> Documentos
                </p>
                <p class="text-2xl font-semibold">{{ kpis.documentos }}</p>
            </div>
            <div class="rounded-xl border p-3">
                <p
                    class="text-muted-foreground flex items-center gap-1 text-xs"
                >
                    <Truck class="size-3" /> Entregas
                </p>
                <p class="text-2xl font-semibold">{{ kpis.entregas }}</p>
            </div>
            <div class="rounded-xl border p-3">
                <p
                    class="text-muted-foreground flex items-center gap-1 text-xs"
                >
                    <RefreshCcw class="size-3" /> Devoluciones
                </p>
                <p class="text-2xl font-semibold">{{ kpis.devoluciones }}</p>
            </div>
            <div class="rounded-xl border p-3">
                <p
                    class="text-muted-foreground flex items-center gap-1 text-xs"
                >
                    <Package class="size-3" /> Activos asignados
                </p>
                <p class="text-2xl font-semibold">
                    {{ kpis.activos_asignados }}
                </p>
            </div>
        </div>

        <div
            class="flex flex-wrap gap-1.5"
            role="group"
            aria-label="Secciones del perfil"
        >
            <button
                type="button"
                :aria-pressed="seccion === 'resumen'"
                :class="
                    cn(
                        'rounded-md border px-3 py-1.5 text-sm font-medium transition-colors',
                        seccion === 'resumen'
                            ? 'bg-primary text-primary-foreground border-primary'
                            : 'hover:bg-accent text-muted-foreground hover:text-foreground',
                    )
                "
                @click="seccion = 'resumen'"
            >
                Resumen
            </button>
            <button
                v-if="expediente"
                type="button"
                :aria-pressed="seccion === 'expediente'"
                :class="
                    cn(
                        'inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium transition-colors',
                        seccion === 'expediente'
                            ? 'bg-primary text-primary-foreground border-primary'
                            : 'hover:bg-accent text-muted-foreground hover:text-foreground',
                    )
                "
                @click="seccion = 'expediente'"
            >
                <FolderOpen class="size-4" /> Expediente
            </button>
        </div>

        <section v-if="seccion === 'resumen'" class="rounded-xl border p-4">
            <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                <ScrollText class="text-muted-foreground size-4" />
                Información general
            </h2>
            <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground text-xs">Empresa</dt>
                    <dd>{{ colaborador.empresa_nombre ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs">Sucursal</dt>
                    <dd>{{ colaborador.sucursal?.nombre ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs">
                        Área / Departamento
                    </dt>
                    <dd>
                        {{
                            colaborador.area_actual?.nombre ??
                            colaborador.area ??
                            '—'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs">Puesto</dt>
                    <dd>{{ colaborador.puesto ?? '—' }}</dd>
                </div>
                <div>
                    <dt
                        class="text-muted-foreground flex items-center gap-1 text-xs"
                    >
                        <Mail class="size-3" /> Correo
                    </dt>
                    <dd>{{ colaborador.correo ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs">Estado</dt>
                    <dd>
                        <Badge
                            :variant="
                                colaborador.activo ? 'success' : 'secondary'
                            "
                        >
                            {{ colaborador.activo ? 'Activo' : 'Eliminado' }}
                        </Badge>
                    </dd>
                </div>
            </dl>
        </section>

        <section v-else-if="expediente">
            <ExpedienteExplorer
                :colaborador="{
                    id: expediente.id,
                    nombre_completo: expediente.nombre_completo,
                    numero_empleado: expediente.numero_empleado,
                    foto_url: expediente.foto_url,
                }"
                :categorias="expediente.categorias"
                :documentos="expediente.documentos"
                :puede-administrar="expediente.puedeAdministrar"
                :puede-descargar="expediente.puedeDescargar"
                :puede-ver-eliminados="expediente.puedeVerEliminados"
                :filtro-estado="expediente.filtroEstado"
            />
        </section>

        <Dialog v-model:open="modalEditar">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Editar colaborador</DialogTitle>
                    <DialogDescription>
                        Actualiza los datos del colaborador.
                    </DialogDescription>
                </DialogHeader>
                <FormularioColaborador
                    :key="claveFormularioEditar"
                    :colaborador="colaborador"
                    @guardado="alGuardarEditar"
                    @cancelar="modalEditar = false"
                />
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="modalEstado">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Eliminar este colaborador?</DialogTitle>
                    <DialogDescription>
                        Esta acción lo retirará de los listados y nuevas
                        operaciones. Su historial de entregas, acuses y
                        expediente digital no se modifican, y podrás restaurarlo
                        cuando quieras.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        :disabled="procesandoEstado"
                        @click="modalEstado = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="procesandoEstado"
                        @click="confirmarEliminar"
                    >
                        Eliminar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <CambiarFotoDialog
            v-model:open="modalFoto"
            :colaborador-id="colaborador.id"
            :foto-actual-url="colaborador.foto_url"
        />
    </div>
</template>
