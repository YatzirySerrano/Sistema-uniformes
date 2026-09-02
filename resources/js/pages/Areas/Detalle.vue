<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    Hash,
    Network,
    Pencil,
    Power,
    ScrollText,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { AreaEditable } from '@/components/areas/FormularioArea.vue';
import FormularioArea from '@/components/areas/FormularioArea.vue';
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

type AreaDetalle = AreaEditable & {
    activa: boolean;
    empresa: { id: number; nombre_comercial: string };
    colaboradores_total: number;
    colaboradores_activos: number;
};

const props = defineProps<{
    area: AreaDetalle;
    permisos: { editar: boolean; desactivar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Áreas / Departamentos', href: '/areas' },
            { title: 'Detalle', href: '#' },
        ],
    },
});

const modalEditar = ref(false);
const claveFormulario = ref(0);
const modalEstado = ref(false);
const procesandoEstado = ref(false);

const editable = computed<AreaEditable>(() => ({
    id: props.area.id,
    nombre: props.area.nombre,
    codigo: props.area.codigo,
    descripcion: props.area.descripcion,
}));

const rutaColaboradores = computed(
    () => `/colaboradores?area_id=${props.area.id}`,
);

function abrirEditar(): void {
    claveFormulario.value++;
    modalEditar.value = true;
}

function alGuardar(): void {
    modalEditar.value = false;
}

function alternarEstado(): void {
    if (props.area.activa) {
        modalEstado.value = true;
    } else {
        router.post(
            `/areas/${props.area.id}/estado`,
            {},
            { preserveScroll: true },
        );
    }
}

function confirmarDesactivar(): void {
    procesandoEstado.value = true;
    router.post(
        `/areas/${props.area.id}/estado`,
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
    <Head :title="area.nombre" />

    <div class="flex w-full flex-col gap-4 p-4">
        <Button variant="ghost" size="sm" as-child class="w-fit">
            <Link href="/areas">
                <ArrowLeft class="size-4" /> Volver a áreas
            </Link>
        </Button>

        <div
            class="flex flex-col gap-4 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0">
                <h1
                    class="flex items-center gap-2 truncate text-xl font-semibold tracking-tight"
                >
                    <Network class="text-muted-foreground size-5 shrink-0" />
                    {{ area.nombre }}
                </h1>
                <p class="text-muted-foreground font-mono text-sm">
                    {{ area.codigo ?? '—' }}
                </p>
                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    <Badge variant="outline" class="gap-1">
                        <Building2 class="size-3" />
                        {{ area.empresa.nombre_comercial }}
                    </Badge>
                    <Badge :variant="area.activa ? 'default' : 'secondary'">
                        {{ area.activa ? 'Activa' : 'Inactiva' }}
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
                    :variant="area.activa ? 'ghost' : 'default'"
                    size="sm"
                    @click="alternarEstado"
                >
                    <Power class="size-3.5" />
                    {{ area.activa ? 'Desactivar' : 'Activar' }}
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
                        <dd>{{ area.nombre }}</dd>
                    </div>
                    <div class="flex gap-6">
                        <div>
                            <dt
                                class="text-muted-foreground flex items-center gap-1 text-xs"
                            >
                                <Hash class="size-3" /> Código
                            </dt>
                            <dd class="font-mono">{{ area.codigo ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Empresa
                            </dt>
                            <dd>{{ area.empresa.nombre_comercial }}</dd>
                        </div>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Estado</dt>
                        <dd>
                            <Badge
                                :variant="area.activa ? 'default' : 'secondary'"
                            >
                                {{ area.activa ? 'Activa' : 'Inactiva' }}
                            </Badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Descripción
                        </dt>
                        <dd class="text-pretty">
                            {{ area.descripcion ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-xl border p-4">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <Users class="text-muted-foreground size-4" />
                    Colaboradores
                </h2>

                <div
                    class="bg-muted/40 flex flex-col gap-3 rounded-lg p-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <p
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <Users class="size-3" /> Colaboradores activos
                            <AyudaTooltip
                                texto="Colaboradores con estado activo asignados a esta área. Entre paréntesis, el total incluyendo inactivos."
                                etiqueta="Ayuda sobre colaboradores activos"
                            />
                        </p>
                        <p class="text-2xl font-semibold">
                            {{ area.colaboradores_activos }}
                            <span
                                class="text-muted-foreground text-sm font-normal"
                            >
                                / {{ area.colaboradores_total }}
                            </span>
                        </p>
                    </div>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="rutaColaboradores">Ver colaboradores</Link>
                    </Button>
                </div>
            </section>
        </div>

        <Dialog v-model:open="modalEditar">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Editar área</DialogTitle>
                    <DialogDescription>
                        Actualiza los datos del área.
                    </DialogDescription>
                </DialogHeader>
                <FormularioArea
                    :key="claveFormulario"
                    :area="editable"
                    @guardado="alGuardar"
                    @cancelar="modalEditar = false"
                />
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="modalEstado">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Desactivar esta área?</DialogTitle>
                    <DialogDescription>
                        Esta área dejará de estar disponible para nuevas
                        asignaciones. Los colaboradores ya asignados y los
                        registros históricos no se modifican, y podrás
                        reactivarla cuando quieras.
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
