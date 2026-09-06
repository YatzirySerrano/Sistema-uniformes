<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    Hash,
    MapPin,
    Pencil,
    Phone,
    Power,
    ScrollText,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { SucursalEditable } from '@/components/sucursales/FormularioSucursal.vue';
import FormularioSucursal from '@/components/sucursales/FormularioSucursal.vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import PanelSuspendidos from '@/components/sistema/PanelSuspendidos.vue';
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

type SucursalDetalle = SucursalEditable & {
    activa: boolean;
    empresa: { id: number; nombre_comercial: string };
    colaboradores_total: number;
    colaboradores_activos: number;
};

const props = defineProps<{
    sucursal: SucursalDetalle;
    permisos: { editar: boolean; desactivar: boolean };
    suspendidos: {
        id: number;
        tipo: string;
        nombre: string | null;
        suspendida_en: string;
        puede_reactivarse: boolean;
        motivos: string[];
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Sucursales', href: '/sucursales' },
            { title: 'Detalle', href: '#' },
        ],
    },
});

const modalEditar = ref(false);
const claveFormulario = ref(0);
const modalEstado = ref(false);
const procesandoEstado = ref(false);

const editable = computed<SucursalEditable>(() => ({
    id: props.sucursal.id,
    codigo: props.sucursal.codigo,
    nombre: props.sucursal.nombre,
    direccion: props.sucursal.direccion,
    telefono: props.sucursal.telefono,
}));

const telefonoLegible = computed(() => {
    const t = props.sucursal.telefono;
    if (!t) return null;
    return t.length === 10
        ? `${t.slice(0, 2)} ${t.slice(2, 6)} ${t.slice(6)}`
        : t;
});

const rutaColaboradores = computed(
    () => `/colaboradores?sucursal_id=${props.sucursal.id}`,
);

function abrirEditar(): void {
    claveFormulario.value++;
    modalEditar.value = true;
}

function alGuardar(): void {
    modalEditar.value = false;
}

function alternarEstado(): void {
    if (props.sucursal.activa) {
        modalEstado.value = true; // desactivar => confirmación
    } else {
        router.post(
            `/sucursales/${props.sucursal.id}/estado`,
            {},
            { preserveScroll: true },
        );
    }
}

function confirmarDesactivar(): void {
    procesandoEstado.value = true;
    router.post(
        `/sucursales/${props.sucursal.id}/estado`,
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
</script>

<template>
    <Head :title="sucursal.nombre" />

    <div class="flex w-full flex-col gap-4 p-4">
        <Button variant="ghost" size="sm" as-child class="w-fit">
            <Link href="/sucursales">
                <ArrowLeft class="size-4" /> Volver a sucursales
            </Link>
        </Button>

        <!-- Encabezado -->
        <div
            class="flex flex-col gap-4 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0">
                <h1 class="truncate text-xl font-semibold tracking-tight">
                    {{ sucursal.nombre }}
                </h1>
                <p class="text-muted-foreground font-mono text-sm">
                    {{ sucursal.codigo }}
                </p>
                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    <Badge variant="outline" class="gap-1">
                        <Building2 class="size-3" />
                        {{ sucursal.empresa.nombre_comercial }}
                    </Badge>
                    <Badge :variant="sucursal.activa ? 'success' : 'secondary'">
                        {{ sucursal.activa ? 'Activa' : 'Eliminada' }}
                    </Badge>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="permisos.editar"
                    variant="outline"
                    size="sm"
                    @click="abrirEditar"
                >
                    <Pencil class="size-3.5" /> Editar
                </Button>
                <Button
                    v-if="permisos.desactivar"
                    :variant="sucursal.activa ? 'ghost' : 'default'"
                    size="sm"
                    @click="alternarEstado"
                >
                    <Power class="size-3.5" />
                    {{ sucursal.activa ? 'Eliminar' : 'Restaurar' }}
                </Button>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <!-- Información general -->
            <section class="rounded-xl border p-4">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <ScrollText class="text-muted-foreground size-4" />
                    Información general
                </h2>
                <dl class="grid gap-3 text-sm">
                    <div>
                        <dt class="text-muted-foreground text-xs">Nombre</dt>
                        <dd>{{ sucursal.nombre }}</dd>
                    </div>
                    <div class="flex gap-6">
                        <div>
                            <dt
                                class="text-muted-foreground flex items-center gap-1 text-xs"
                            >
                                <Hash class="size-3" /> Código
                            </dt>
                            <dd class="font-mono">{{ sucursal.codigo }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Empresa
                            </dt>
                            <dd>{{ sucursal.empresa.nombre_comercial }}</dd>
                        </div>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Estado</dt>
                        <dd>
                            <Badge
                                :variant="
                                    sucursal.activa ? 'success' : 'secondary'
                                "
                            >
                                {{ sucursal.activa ? 'Activa' : 'Eliminada' }}
                            </Badge>
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- Contacto / ubicación -->
            <section class="rounded-xl border p-4">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <MapPin class="text-muted-foreground size-4" />
                    Contacto y ubicación
                </h2>
                <dl class="grid gap-3 text-sm">
                    <div>
                        <dt
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <MapPin class="size-3" /> Dirección
                        </dt>
                        <dd>{{ sucursal.direccion ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <Phone class="size-3" /> Teléfono
                        </dt>
                        <dd>{{ telefonoLegible ?? '—' }}</dd>
                    </div>
                </dl>
            </section>

            <!-- Resumen operativo -->
            <section class="rounded-xl border p-4 md:col-span-2">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <Users class="text-muted-foreground size-4" />
                    Resumen operativo
                </h2>

                <template v-if="sucursal.colaboradores_total > 0">
                    <div
                        class="bg-muted/40 flex flex-col gap-3 rounded-lg p-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <p
                                class="text-muted-foreground flex items-center gap-1 text-xs"
                            >
                                <Users class="size-3" /> Colaboradores activos
                                <AyudaTooltip
                                    texto="Colaboradores con estado activo asignados a esta sucursal. Entre paréntesis, el total incluyendo inactivos."
                                    etiqueta="Ayuda sobre colaboradores activos"
                                />
                            </p>
                            <p class="text-2xl font-semibold">
                                {{ sucursal.colaboradores_activos }}
                                <span
                                    class="text-muted-foreground text-sm font-normal"
                                >
                                    / {{ sucursal.colaboradores_total }}
                                </span>
                            </p>
                        </div>
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="rutaColaboradores"
                                >Ver colaboradores</Link
                            >
                        </Button>
                    </div>
                </template>

                <template v-else>
                    <div
                        class="bg-muted/40 flex flex-col gap-3 rounded-lg p-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <p class="text-muted-foreground text-sm">
                            Aún no hay colaboradores registrados en esta
                            sucursal.
                        </p>
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="rutaColaboradores"
                                >Ir a colaboradores</Link
                            >
                        </Button>
                    </div>
                </template>
            </section>
        </div>

        <PanelSuspendidos
            v-if="suspendidos.length"
            :suspendidos="suspendidos"
            :endpoint="`/sucursales/${sucursal.id}/suspendidos/reactivar`"
            :puede-reactivar="permisos.desactivar"
        />

        <!-- Modal editar -->
        <Dialog v-model:open="modalEditar">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Editar sucursal</DialogTitle>
                    <DialogDescription>
                        Actualiza los datos de la sucursal.
                    </DialogDescription>
                </DialogHeader>
                <FormularioSucursal
                    :key="claveFormulario"
                    :sucursal="editable"
                    @guardado="alGuardar"
                    @cancelar="modalEditar = false"
                />
            </DialogContent>
        </Dialog>

        <!-- Confirmar desactivación -->
        <Dialog v-model:open="modalEstado">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Eliminar esta sucursal?</DialogTitle>
                    <DialogDescription>
                        Esta acción retirará la sucursal de los listados y
                        operaciones disponibles. Los registros históricos no se
                        eliminarán y podrás restaurarla cuando quieras.
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
                        @click="confirmarDesactivar"
                    >
                        Eliminar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
