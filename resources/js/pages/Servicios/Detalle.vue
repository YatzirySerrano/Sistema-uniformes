<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    ChevronLeft,
    ChevronRight,
    Eye,
    FileSignature,
    Hash,
    MapPin,
    Pencil,
    Power,
    ScrollText,
    Search,
    UserMinus,
    UserPlus,
    Users,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AsignarColaboradoresDialog from '@/components/servicios/AsignarColaboradoresDialog.vue';
import type { ServicioEditable } from '@/components/servicios/FormularioServicio.vue';
import FormularioServicio from '@/components/servicios/FormularioServicio.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
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
import { useVistaPreferida } from '@/composables/useVistaPreferida';

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

type ColaboradorAsignado = {
    id: number;
    nombre_completo: string;
    numero_empleado: string;
    sucursal: string | null;
    activo: boolean;
};

type Paginado<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    servicio: ServicioDetalle;
    colaboradoresAsignados: Paginado<ColaboradorAsignado>;
    filtrosColaboradores: { buscar: string };
    permisos: {
        editar: boolean;
        administrar: boolean;
        asignarColaboradores: boolean;
    };
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

// ------------------------------------------------------------------
// Colaboradores asignados: búsqueda + paginación server-side
// ------------------------------------------------------------------
const vista = useVistaPreferida('servicio-colaboradores', 'tabla');
const buscarColab = ref(props.filtrosColaboradores.buscar);
let temporizadorBusqueda: ReturnType<typeof setTimeout> | undefined;

function recargarColaboradores(pagina?: number): void {
    router.get(
        `/servicios/${props.servicio.id}`,
        {
            colab_buscar: buscarColab.value || undefined,
            colab_page: pagina && pagina > 1 ? pagina : undefined,
        },
        {
            only: ['colaboradoresAsignados', 'filtrosColaboradores'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watch(buscarColab, () => {
    clearTimeout(temporizadorBusqueda);
    temporizadorBusqueda = setTimeout(() => recargarColaboradores(1), 350);
});

const paginacion = computed(() => props.colaboradoresAsignados);

// ------------------------------------------------------------------
// Asignar colaboradores (lote) + quitar del servicio
// ------------------------------------------------------------------
const modalAsignar = ref(false);
const colaboradorAQuitar = ref<ColaboradorAsignado | null>(null);
const procesandoQuitar = ref(false);

function alAsignar(): void {
    modalAsignar.value = false;
    // Recarga completa: el contador y la lista deben reflejar el lote.
    router.reload();
}

function confirmarQuitar(): void {
    if (colaboradorAQuitar.value === null) return;
    procesandoQuitar.value = true;
    router.post(
        `/colaboradores/${colaboradorAQuitar.value.id}/servicio`,
        { servicio_id: null },
        {
            preserveScroll: true,
            onFinish: () => {
                procesandoQuitar.value = false;
                colaboradorAQuitar.value = null;
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

        <!-- ============ Colaboradores asignados ============ -->
        <section class="rounded-xl border p-4">
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0">
                    <h2 class="flex items-center gap-2 text-sm font-semibold">
                        <Users class="text-muted-foreground size-4" />
                        Colaboradores asignados
                    </h2>
                    <p class="text-muted-foreground mt-0.5 text-xs">
                        Personas cuya ubicación operativa vigente es este
                        servicio. Editarlas aquí o desde su ficha modifica la
                        misma información.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <SelectorVista
                        v-model="vista"
                        class="hidden sm:inline-flex"
                    />
                    <Button
                        v-if="permisos.asignarColaboradores"
                        size="sm"
                        @click="modalAsignar = true"
                    >
                        <UserPlus class="size-4" /> Asignar colaboradores
                    </Button>
                </div>
            </div>

            <div class="relative mt-3 max-w-sm">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="buscarColab"
                    type="search"
                    placeholder="Buscar por nombre o número de empleado"
                    class="pl-8"
                    aria-label="Buscar colaboradores asignados"
                />
            </div>

            <p
                v-if="paginacion.total === 0"
                class="text-muted-foreground mt-4 text-sm"
            >
                {{
                    buscarColab
                        ? 'Ningún colaborador coincide con la búsqueda.'
                        : 'Todavía no hay colaboradores con este servicio como ubicación actual.'
                }}
            </p>

            <template v-else>
                <!-- Cards: siempre en móvil; en sm+ sólo si vista = cards -->
                <ul
                    :class="[
                        'mt-4 grid gap-2 sm:grid-cols-2',
                        vista === 'tabla' ? 'sm:hidden' : '',
                    ]"
                >
                    <li
                        v-for="c in paginacion.data"
                        :key="c.id"
                        class="flex flex-col gap-2 rounded-lg border p-3"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ c.nombre_completo }}
                                <Badge
                                    v-if="!c.activo"
                                    variant="secondary"
                                    class="ml-1 align-middle"
                                >
                                    Inactivo
                                </Badge>
                            </p>
                            <p class="text-muted-foreground font-mono text-xs">
                                {{ c.numero_empleado }}
                            </p>
                            <p class="text-muted-foreground mt-1 text-xs">
                                Sucursal: {{ c.sucursal ?? '—' }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" as-child>
                                <Link :href="`/colaboradores/${c.id}`">
                                    <Eye class="size-3.5" /> Ver
                                </Link>
                            </Button>
                            <Button
                                v-if="permisos.asignarColaboradores"
                                variant="ghost"
                                size="sm"
                                @click="colaboradorAQuitar = c"
                            >
                                <UserMinus class="size-3.5" /> Quitar del
                                servicio
                            </Button>
                        </div>
                    </li>
                </ul>

                <!-- Tabla: sólo sm+ y vista = tabla -->
                <div
                    v-if="vista === 'tabla'"
                    class="mt-4 hidden overflow-x-auto sm:block"
                >
                    <table class="w-full text-sm">
                        <thead
                            class="text-muted-foreground border-b text-left text-xs"
                        >
                            <tr>
                                <th class="py-2 pr-3 font-medium">
                                    Nombre completo
                                </th>
                                <th class="py-2 pr-3 font-medium">
                                    N.º empleado
                                </th>
                                <th class="py-2 pr-3 font-medium">Sucursal</th>
                                <th class="py-2 pr-3 font-medium">Estado</th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    Acciones
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="c in paginacion.data"
                                :key="c.id"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-3">
                                    {{ c.nombre_completo }}
                                </td>
                                <td class="py-2 pr-3 font-mono text-xs">
                                    {{ c.numero_empleado }}
                                </td>
                                <td class="py-2 pr-3">
                                    {{ c.sucursal ?? '—' }}
                                </td>
                                <td class="py-2 pr-3">
                                    <Badge
                                        :variant="
                                            c.activo ? 'success' : 'secondary'
                                        "
                                    >
                                        {{ c.activo ? 'Activo' : 'Inactivo' }}
                                    </Badge>
                                </td>
                                <td class="py-2 pr-3">
                                    <div
                                        class="flex justify-end gap-1.5 whitespace-nowrap"
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            as-child
                                        >
                                            <Link
                                                :href="`/colaboradores/${c.id}`"
                                            >
                                                <Eye class="size-3.5" /> Ver
                                            </Link>
                                        </Button>
                                        <Button
                                            v-if="permisos.asignarColaboradores"
                                            variant="ghost"
                                            size="sm"
                                            @click="colaboradorAQuitar = c"
                                        >
                                            <UserMinus class="size-3.5" />
                                            Quitar
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="paginacion.last_page > 1"
                    class="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm"
                >
                    <p class="text-muted-foreground">
                        {{ paginacion.from ?? 0 }}–{{ paginacion.to ?? 0 }} de
                        {{ paginacion.total }}
                    </p>
                    <div class="flex items-center gap-1.5">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="paginacion.current_page <= 1"
                            @click="
                                recargarColaboradores(
                                    paginacion.current_page - 1,
                                )
                            "
                        >
                            <ChevronLeft class="size-4" /> Anterior
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="
                                paginacion.current_page >= paginacion.last_page
                            "
                            @click="
                                recargarColaboradores(
                                    paginacion.current_page + 1,
                                )
                            "
                        >
                            Siguiente <ChevronRight class="size-4" />
                        </Button>
                    </div>
                </div>
            </template>
        </section>

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

        <Dialog
            :open="colaboradorAQuitar !== null"
            @update:open="(v) => !v && (colaboradorAQuitar = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Quitar del servicio?</DialogTitle>
                    <DialogDescription>
                        {{ colaboradorAQuitar?.nombre_completo }} quedará sin
                        servicio operativo asignado. Los activos que conserve
                        seguirán asignados a él y aparecerán como "Sin servicio
                        asignado". No se modifican entregas, devoluciones ni
                        inventario.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        :disabled="procesandoQuitar"
                        @click="colaboradorAQuitar = null"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="procesandoQuitar"
                        @click="confirmarQuitar"
                    >
                        Quitar del servicio
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <AsignarColaboradoresDialog
            v-model:open="modalAsignar"
            :servicio-id="servicio.id"
            :empresa-id="servicio.empresa.id"
            :servicio-nombre="servicio.nombre"
            :contrato-nombre="servicio.contrato.nombre"
            @guardado="alAsignar"
        />
    </div>
</template>
