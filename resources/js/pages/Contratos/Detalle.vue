<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    CalendarRange,
    FileSignature,
    Hash,
    MapPin,
    Pencil,
    Power,
    ScrollText,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { ContratoEditable } from '@/components/contratos/FormularioContrato.vue';
import FormularioContrato from '@/components/contratos/FormularioContrato.vue';
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

type ContratoDetalle = ContratoEditable & {
    activo: boolean;
    empresa: { id: number; nombre_comercial: string };
    servicios_total: number;
    servicios_activos: number;
};

const props = defineProps<{
    contrato: ContratoDetalle;
    permisos: { editar: boolean; administrar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Contratos', href: '/contratos' },
            { title: 'Detalle', href: '#' },
        ],
    },
});

const modalEditar = ref(false);
const claveFormulario = ref(0);
const modalEstado = ref(false);
const procesandoEstado = ref(false);

const editable = computed<ContratoEditable>(() => ({
    id: props.contrato.id,
    nombre: props.contrato.nombre,
    codigo: props.contrato.codigo,
    descripcion: props.contrato.descripcion,
    fecha_inicio: props.contrato.fecha_inicio,
    fecha_fin: props.contrato.fecha_fin,
}));

const rutaServicios = computed(
    () => `/servicios?contrato_id=${props.contrato.id}`,
);

function abrirEditar(): void {
    claveFormulario.value++;
    modalEditar.value = true;
}

function alGuardar(): void {
    modalEditar.value = false;
}

function alternarEstado(): void {
    if (props.contrato.activo) {
        modalEstado.value = true;
    } else {
        router.post(
            `/contratos/${props.contrato.id}/estado`,
            {},
            { preserveScroll: true },
        );
    }
}

function confirmarDesactivar(): void {
    procesandoEstado.value = true;
    router.post(
        `/contratos/${props.contrato.id}/estado`,
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
    <Head :title="contrato.nombre" />

    <div class="flex w-full flex-col gap-4 p-4">
        <Button variant="ghost" size="sm" as-child class="w-fit">
            <Link href="/contratos">
                <ArrowLeft class="size-4" /> Volver a contratos
            </Link>
        </Button>

        <div
            class="flex flex-col gap-4 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0">
                <h1
                    class="flex items-center gap-2 truncate text-xl font-semibold tracking-tight"
                >
                    <FileSignature
                        class="text-muted-foreground size-5 shrink-0"
                    />
                    {{ contrato.nombre }}
                </h1>
                <p class="text-muted-foreground font-mono text-sm">
                    {{ contrato.codigo ?? '—' }}
                </p>
                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    <Badge variant="outline" class="gap-1">
                        <Building2 class="size-3" />
                        {{ contrato.empresa.nombre_comercial }}
                    </Badge>
                    <Badge :variant="contrato.activo ? 'success' : 'secondary'">
                        {{ contrato.activo ? 'Activo' : 'Eliminado' }}
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
                    :variant="contrato.activo ? 'ghost' : 'default'"
                    size="sm"
                    @click="alternarEstado"
                >
                    <Power class="size-3.5" />
                    {{ contrato.activo ? 'Eliminar' : 'Restaurar' }}
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
                        <dd>{{ contrato.nombre }}</dd>
                    </div>
                    <div class="flex gap-6">
                        <div>
                            <dt
                                class="text-muted-foreground flex items-center gap-1 text-xs"
                            >
                                <Hash class="size-3" /> Código
                            </dt>
                            <dd class="font-mono">
                                {{ contrato.codigo ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Empresa
                            </dt>
                            <dd>{{ contrato.empresa.nombre_comercial }}</dd>
                        </div>
                    </div>
                    <div class="flex gap-6">
                        <div>
                            <dt
                                class="text-muted-foreground flex items-center gap-1 text-xs"
                            >
                                <CalendarRange class="size-3" /> Vigencia
                            </dt>
                            <dd>
                                {{ contrato.fecha_inicio ?? 'Sin definir' }} —
                                {{ contrato.fecha_fin ?? 'Sin definir' }}
                            </dd>
                        </div>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Estado</dt>
                        <dd>
                            <Badge
                                :variant="
                                    contrato.activo ? 'success' : 'secondary'
                                "
                            >
                                {{ contrato.activo ? 'Activo' : 'Eliminado' }}
                            </Badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Descripción
                        </dt>
                        <dd class="text-pretty">
                            {{ contrato.descripcion ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-xl border p-4">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <MapPin class="text-muted-foreground size-4" />
                    Servicios
                </h2>

                <div
                    class="bg-muted/40 flex flex-col gap-3 rounded-lg p-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <p
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <MapPin class="size-3" /> Servicios activos
                        </p>
                        <p class="text-2xl font-semibold">
                            {{ contrato.servicios_activos }}
                            <span
                                class="text-muted-foreground text-sm font-normal"
                            >
                                / {{ contrato.servicios_total }}
                            </span>
                        </p>
                    </div>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="rutaServicios">Ver servicios</Link>
                    </Button>
                </div>
            </section>
        </div>

        <Dialog v-model:open="modalEditar">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Editar contrato</DialogTitle>
                    <DialogDescription>
                        Actualiza los datos del contrato.
                    </DialogDescription>
                </DialogHeader>
                <FormularioContrato
                    :key="claveFormulario"
                    :contrato="editable"
                    @guardado="alGuardar"
                    @cancelar="modalEditar = false"
                />
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="modalEstado">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Eliminar este contrato?</DialogTitle>
                    <DialogDescription>
                        Esta acción lo retirará de los listados y de nuevas
                        asignaciones de servicio y entregas. Sus servicios y
                        registros históricos no se modifican, y podrás
                        restaurarlo cuando quieras.
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
