<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    FolderOpen,
    Mail,
    Pencil,
    Power,
    ScrollText,
} from '@lucide/vue';
import { ref } from 'vue';
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

type ColaboradorDetalle = {
    id: number;
    numero_empleado: string;
    nombre_completo: string;
    puesto: string | null;
    correo: string | null;
    activo: boolean;
    empresa: string | null;
    sucursal: string | null;
    area: string | null;
    foto_url: string | null;
};

const props = defineProps<{
    colaborador: ColaboradorDetalle;
    puedeEditar: boolean;
    puedeEliminar: boolean;
    puedeVerExpediente: boolean;
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
            class="flex flex-col gap-4 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex min-w-0 items-center gap-3">
                <Avatar class="size-16 shrink-0">
                    <AvatarImage
                        v-if="colaborador.foto_url"
                        :src="colaborador.foto_url"
                        :alt="colaborador.nombre_completo"
                    />
                    <AvatarFallback class="text-lg">
                        {{ getInitials(colaborador.nombre_completo) }}
                    </AvatarFallback>
                </Avatar>
                <div class="min-w-0">
                    <h1 class="truncate text-xl font-semibold tracking-tight">
                        {{ colaborador.nombre_completo }}
                    </h1>
                    <p class="text-muted-foreground font-mono text-sm">
                        {{ colaborador.numero_empleado }}
                    </p>
                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                        <Badge
                            v-if="colaborador.empresa"
                            variant="outline"
                            class="gap-1"
                        >
                            <Building2 class="size-3" />
                            {{ colaborador.empresa }}
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

            <div class="flex flex-wrap gap-2">
                <Button v-if="puedeVerExpediente" size="sm" as-child>
                    <Link :href="`/colaboradores/${colaborador.id}/expediente`">
                        <FolderOpen class="size-3.5" /> Ver expediente digital
                    </Link>
                </Button>
                <Button v-if="puedeEditar" variant="outline" size="sm" as-child>
                    <Link :href="`/colaboradores/${colaborador.id}/editar`">
                        <Pencil class="size-3.5" /> Editar
                    </Link>
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

        <section class="rounded-xl border p-4">
            <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                <ScrollText class="text-muted-foreground size-4" />
                Información general
            </h2>
            <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground text-xs">Empresa</dt>
                    <dd>{{ colaborador.empresa ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs">Sucursal</dt>
                    <dd>{{ colaborador.sucursal ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs">
                        Área / Departamento
                    </dt>
                    <dd>{{ colaborador.area ?? '—' }}</dd>
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
    </div>
</template>
