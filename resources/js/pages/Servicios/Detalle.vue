<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    FileSignature,
    Hash,
    MapPin,
    Pencil,
    Power,
    ScrollText,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { ServicioEditable } from '@/components/servicios/FormularioServicio.vue';
import FormularioServicio from '@/components/servicios/FormularioServicio.vue';
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

type ServicioDetalle = {
    id: number;
    nombre: string;
    codigo: string | null;
    direccion: string | null;
    descripcion: string | null;
    activo: boolean;
    contrato: { id: number; nombre: string; activo: boolean };
    sucursal: { id: number; nombre: string };
    empresa: { id: number; nombre_comercial: string };
    colaboradores_actuales: number;
};

const props = defineProps<{
    servicio: ServicioDetalle;
    permisos: { editar: boolean; administrar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Servicios', href: '/servicios' },
            { title: 'Detalle', href: '#' },
        ],
    },
});

const modalEditar = ref(false);
const claveFormulario = ref(0);
const modalEstado = ref(false);
const procesandoEstado = ref(false);

const editable = computed<ServicioEditable>(() => ({
    id: props.servicio.id,
    nombre: props.servicio.nombre,
    codigo: props.servicio.codigo,
    direccion: props.servicio.direccion,
    descripcion: props.servicio.descripcion,
    contrato: props.servicio.contrato,
    sucursal: props.servicio.sucursal,
    empresa: props.servicio.empresa,
}));

function abrirEditar(): void {
    claveFormulario.value++;
    modalEditar.value = true;
}

function alGuardar(): void {
    modalEditar.value = false;
}

function alternarEstado(): void {
    if (props.servicio.activo) {
        modalEstado.value = true;
    } else {
        router.post(
            `/servicios/${props.servicio.id}/estado`,
            {},
            { preserveScroll: true },
        );
    }
}

function confirmarDesactivar(): void {
    procesandoEstado.value = true;
    router.post(
        `/servicios/${props.servicio.id}/estado`,
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
    <Head :title="servicio.nombre" />

    <div class="flex w-full flex-col gap-4 p-4">
        <Button variant="ghost" size="sm" as-child class="w-fit">
            <Link href="/servicios">
                <ArrowLeft class="size-4" /> Volver a servicios
            </Link>
        </Button>

        <div
            class="flex flex-col gap-4 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0">
                <h1
                    class="flex items-center gap-2 truncate text-xl font-semibold tracking-tight"
                >
                    <MapPin class="text-muted-foreground size-5 shrink-0" />
                    {{ servicio.nombre }}
                </h1>
                <p class="text-muted-foreground font-mono text-sm">
                    {{ servicio.codigo ?? '—' }}
                </p>
                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    <Badge variant="outline" class="gap-1">
                        <Building2 class="size-3" />
                        {{ servicio.empresa.nombre_comercial }}
                    </Badge>
                    <Badge variant="outline" class="gap-1">
                        <FileSignature class="size-3" />
                        {{ servicio.contrato.nombre }}
                    </Badge>
                    <Badge :variant="servicio.activo ? 'success' : 'secondary'">
                        {{ servicio.activo ? 'Activo' : 'Eliminado' }}
                    </Badge>
                    <Badge v-if="!servicio.contrato.activo" variant="secondary">
                        Contrato inactivo — no puede usarse en nuevas
                        asignaciones
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
                    :variant="servicio.activo ? 'ghost' : 'default'"
                    size="sm"
                    @click="alternarEstado"
                >
                    <Power class="size-3.5" />
                    {{ servicio.activo ? 'Eliminar' : 'Restaurar' }}
                </Button>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <section class="rounded-xl border p-4">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <ScrollText class="text-muted-foreground size-4" />
                    Información general
                </h2>
                <dl class="grid gap-3 text-sm">
                    <div>
                        <dt class="text-muted-foreground text-xs">Nombre</dt>
                        <dd>{{ servicio.nombre }}</dd>
                    </div>
                    <div class="flex gap-6">
                        <div>
                            <dt
                                class="text-muted-foreground flex items-center gap-1 text-xs"
                            >
                                <Hash class="size-3" /> Código
                            </dt>
                            <dd class="font-mono">
                                {{ servicio.codigo ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Sucursal responsable
                            </dt>
                            <dd>{{ servicio.sucursal.nombre }}</dd>
                        </div>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Estado</dt>
                        <dd>
                            <Badge
                                :variant="
                                    servicio.activo ? 'success' : 'secondary'
                                "
                            >
                                {{ servicio.activo ? 'Activo' : 'Eliminado' }}
                            </Badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Dirección</dt>
                        <dd class="text-pretty">
                            {{ servicio.direccion ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Descripción
                        </dt>
                        <dd class="text-pretty">
                            {{ servicio.descripcion ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-xl border p-4">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <Users class="text-muted-foreground size-4" />
                    Colaboradores
                </h2>

                <div class="bg-muted/40 flex flex-col gap-1 rounded-lg p-3">
                    <p
                        class="text-muted-foreground flex items-center gap-1 text-xs"
                    >
                        <Users class="size-3" /> Con este servicio como
                        ubicación actual
                    </p>
                    <p class="text-2xl font-semibold">
                        {{ servicio.colaboradores_actuales }}
                    </p>
                    <p
                        v-if="
                            !servicio.activo &&
                            servicio.colaboradores_actuales > 0
                        "
                        class="text-muted-foreground mt-1 text-xs"
                    >
                        Este servicio está eliminado pero sigue siendo la
                        ubicación vigente de estos colaboradores — no se
                        modificó automáticamente. Usa "Cambiar servicio" en cada
                        colaborador si necesitas reasignarlos.
                    </p>
                </div>
            </section>
        </div>

        <Dialog v-model:open="modalEditar">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Editar servicio</DialogTitle>
                    <DialogDescription>
                        Actualiza los datos del servicio.
                    </DialogDescription>
                </DialogHeader>
                <FormularioServicio
                    :key="claveFormulario"
                    :servicio="editable"
                    @guardado="alGuardar"
                    @cancelar="modalEditar = false"
                />
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="modalEstado">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Eliminar este servicio?</DialogTitle>
                    <DialogDescription>
                        Esta acción lo retirará de los listados y de nuevas
                        asignaciones. Los colaboradores que ya lo tengan como
                        servicio actual NO se modifican automáticamente, y
                        podrás restaurarlo cuando quieras.
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
