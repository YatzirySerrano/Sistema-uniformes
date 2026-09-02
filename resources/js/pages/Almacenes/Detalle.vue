<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    AtSign,
    Building2,
    Hash,
    MapPin,
    Pencil,
    Phone,
    Power,
    ScrollText,
    Store,
    UserRound,
    Warehouse,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { AlmacenEditable } from '@/components/almacenes/FormularioAlmacen.vue';
import FormularioAlmacen from '@/components/almacenes/FormularioAlmacen.vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
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

type SucursalOpcion = {
    id: number;
    nombre: string;
    codigo: string;
    activa: boolean;
};

type SucursalAbastecida = {
    id: number;
    nombre: string;
    codigo: string;
    direccion: string | null;
    activa: boolean;
};

type AlmacenDetalle = {
    id: number;
    nombre: string;
    codigo: string | null;
    descripcion: string | null;
    direccion: string | null;
    telefono: string | null;
    correo: string | null;
    activo: boolean;
    empresa: { id: number; nombre_comercial: string };
    responsable: {
        id: number;
        nombre_completo: string;
        numero_empleado: string;
        area: string | null;
    } | null;
    sucursales: SucursalAbastecida[];
};

const props = defineProps<{
    almacen: AlmacenDetalle;
    sucursalesDisponibles: SucursalOpcion[];
    permisos: { editar: boolean; administrar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Almacenes', href: '/almacenes' },
            { title: 'Detalle', href: '#' },
        ],
    },
});

const modalEditar = ref(false);
const claveFormulario = ref(0);
const modalEstado = ref(false);
const procesandoEstado = ref(false);

const editable = computed<AlmacenEditable>(() => ({
    id: props.almacen.id,
    nombre: props.almacen.nombre,
    codigo: props.almacen.codigo,
    descripcion: props.almacen.descripcion,
    direccion: props.almacen.direccion,
    telefono: props.almacen.telefono,
    correo: props.almacen.correo,
    responsable: props.almacen.responsable
        ? {
              id: props.almacen.responsable.id,
              nombre_completo: props.almacen.responsable.nombre_completo,
              numero_empleado: props.almacen.responsable.numero_empleado,
          }
        : null,
    sucursales_ids: props.almacen.sucursales.map((s) => s.id),
}));

const telefonoLegible = computed(() => {
    const t = props.almacen.telefono;
    if (!t) return null;
    return t.length === 10
        ? `${t.slice(0, 2)} ${t.slice(2, 6)} ${t.slice(6)}`
        : t;
});

function abrirEditar(): void {
    claveFormulario.value++;
    modalEditar.value = true;
}

function alGuardar(): void {
    modalEditar.value = false;
}

function alternarEstado(): void {
    if (props.almacen.activo) {
        modalEstado.value = true;
    } else {
        router.post(
            `/almacenes/${props.almacen.id}/estado`,
            {},
            { preserveScroll: true },
        );
    }
}

function confirmarDesactivar(): void {
    procesandoEstado.value = true;
    router.post(
        `/almacenes/${props.almacen.id}/estado`,
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
    <Head :title="almacen.nombre" />

    <div class="flex w-full flex-col gap-4 p-4">
        <Button variant="ghost" size="sm" as-child class="w-fit">
            <Link href="/almacenes">
                <ArrowLeft class="size-4" /> Volver a almacenes
            </Link>
        </Button>

        <div
            class="flex flex-col gap-4 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0">
                <h1
                    class="flex items-center gap-2 truncate text-xl font-semibold tracking-tight"
                >
                    <Warehouse class="text-muted-foreground size-5 shrink-0" />
                    {{ almacen.nombre }}
                </h1>
                <p class="text-muted-foreground font-mono text-sm">
                    {{ almacen.codigo ?? '—' }}
                </p>
                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    <Badge variant="outline" class="gap-1">
                        <Building2 class="size-3" />
                        {{ almacen.empresa.nombre_comercial }}
                    </Badge>
                    <Badge :variant="almacen.activo ? 'default' : 'secondary'">
                        {{ almacen.activo ? 'Activo' : 'Inactivo' }}
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
                    v-if="permisos.administrar"
                    :variant="almacen.activo ? 'ghost' : 'default'"
                    size="sm"
                    @click="alternarEstado"
                >
                    <Power class="size-3.5" />
                    {{ almacen.activo ? 'Desactivar' : 'Activar' }}
                </Button>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="rounded-xl border p-4">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <ScrollText class="text-muted-foreground size-4" />
                    Información general
                </h2>
                <dl class="grid gap-3 text-sm">
                    <div>
                        <dt class="text-muted-foreground text-xs">Nombre</dt>
                        <dd>{{ almacen.nombre }}</dd>
                    </div>
                    <div class="flex gap-6">
                        <div>
                            <dt
                                class="text-muted-foreground flex items-center gap-1 text-xs"
                            >
                                <Hash class="size-3" /> Código
                            </dt>
                            <dd class="font-mono">
                                {{ almacen.codigo ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Empresa
                            </dt>
                            <dd>{{ almacen.empresa.nombre_comercial }}</dd>
                        </div>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Estado</dt>
                        <dd>
                            <Badge
                                :variant="
                                    almacen.activo ? 'default' : 'secondary'
                                "
                            >
                                {{ almacen.activo ? 'Activo' : 'Inactivo' }}
                            </Badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Descripción
                        </dt>
                        <dd class="text-pretty">
                            {{ almacen.descripcion ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </section>

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
                        <dd>{{ almacen.direccion ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <Phone class="size-3" /> Teléfono
                        </dt>
                        <dd>{{ telefonoLegible ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <AtSign class="size-3" /> Correo
                        </dt>
                        <dd>{{ almacen.correo ?? '—' }}</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-xl border p-4">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <UserRound class="text-muted-foreground size-4" />
                    Responsable
                </h2>
                <div v-if="almacen.responsable" class="grid gap-3 text-sm">
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Colaborador
                        </dt>
                        <dd>{{ almacen.responsable.nombre_completo }}</dd>
                    </div>
                    <div class="flex gap-6">
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                N.º de empleado
                            </dt>
                            <dd class="font-mono">
                                {{ almacen.responsable.numero_empleado }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">Área</dt>
                            <dd>{{ almacen.responsable.area ?? '—' }}</dd>
                        </div>
                    </div>
                </div>
                <p v-else class="text-muted-foreground text-sm">
                    Este almacén no tiene responsable asignado.
                </p>
            </section>
        </div>

        <section class="rounded-xl border p-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <Store class="text-muted-foreground size-4" />
                    Sucursales abastecidas
                    <AyudaTooltip
                        texto="Sucursales que reciben suministro desde este almacén. Un almacén puede abastecer varias sucursales y una sucursal recibir de varios almacenes."
                        etiqueta="Ayuda sobre sucursales abastecidas"
                    />
                    <Badge variant="secondary">
                        {{ almacen.sucursales.length }}
                    </Badge>
                </h2>
                <Button
                    v-if="permisos.administrar"
                    variant="outline"
                    size="sm"
                    @click="abrirEditar"
                >
                    <Pencil class="size-3.5" />
                    {{
                        almacen.sucursales.length
                            ? 'Gestionar sucursales'
                            : 'Asignar sucursales'
                    }}
                </Button>
            </div>

            <p
                v-if="!almacen.sucursales.length"
                class="text-muted-foreground bg-muted/40 rounded-lg p-3 text-sm"
            >
                Este almacén todavía no abastece sucursales.
            </p>

            <div v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="s in almacen.sucursales"
                    :key="s.id"
                    class="flex items-start justify-between gap-2 rounded-lg border p-3"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">
                            {{ s.nombre }}
                        </p>
                        <p class="text-muted-foreground font-mono text-xs">
                            {{ s.codigo }}
                        </p>
                        <p
                            v-if="s.direccion"
                            class="text-muted-foreground mt-1 line-clamp-1 text-xs"
                        >
                            {{ s.direccion }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <Badge
                            v-if="!s.activa"
                            variant="outline"
                            class="text-xs"
                        >
                            Inactiva
                        </Badge>
                        <Button variant="ghost" size="sm" as-child>
                            <Link :href="`/sucursales/${s.id}`">Ver</Link>
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-xl border p-4">
            <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                <ScrollText class="text-muted-foreground size-4" />
                Resumen operativo
            </h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="bg-muted/40 rounded-lg p-3">
                    <p class="text-muted-foreground text-xs">
                        Sucursales abastecidas
                    </p>
                    <p class="text-2xl font-semibold">
                        {{ almacen.sucursales.length }}
                    </p>
                </div>
                <div class="bg-muted/40 rounded-lg p-3">
                    <p
                        class="text-muted-foreground flex items-center gap-1 text-xs"
                    >
                        Inventario del almacén
                        <AyudaTooltip
                            texto="La administración de inventario por almacén se habilitará en un bloque posterior. Hoy el inventario sigue asociado a la sucursal."
                            etiqueta="Ayuda sobre el inventario del almacén"
                        />
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Disponible próximamente
                    </p>
                </div>
            </div>
        </section>

        <Dialog v-model:open="modalEditar">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Editar almacén</DialogTitle>
                    <DialogDescription>
                        Actualiza los datos, el responsable y las sucursales
                        abastecidas.
                    </DialogDescription>
                </DialogHeader>
                <FormularioAlmacen
                    :key="claveFormulario"
                    :almacen="editable"
                    :sucursales="sucursalesDisponibles"
                    @guardado="alGuardar"
                    @cancelar="modalEditar = false"
                />
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="modalEstado">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Desactivar este almacén?</DialogTitle>
                    <DialogDescription>
                        Este almacén dejará de estar disponible para
                        operaciones. El catálogo de activos y los registros
                        históricos no se modifican, y podrás reactivarlo cuando
                        quieras.
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
                        Desactivar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
