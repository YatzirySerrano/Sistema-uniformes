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
import type { EmpresaAutorizada } from '@/types/sistema';

type EmpresaAbastecida = {
    id: number;
    codigo: string;
    nombre_comercial: string;
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
    empresas: EmpresaAbastecida[];
    responsable: {
        id: number;
        nombre_completo: string;
        numero_empleado: string;
        area: string | null;
    } | null;
};

type VarianteStock = {
    talla_id: number;
    talla: string | null;
    cantidad: number;
    minimo: number;
    bajo_minimo: boolean;
};
type ActivoStock = {
    activo_id: number;
    activo: string | null;
    tipo: string | null;
    categoria: string | null;
    control: string;
    total: number;
    bajo_minimo: boolean;
    variantes: VarianteStock[];
};
type InventarioEmpresa = {
    empresa: { id: number; nombre_comercial: string | null };
    total: number;
    bajo_minimo: boolean;
    activos: ActivoStock[];
};

const props = defineProps<{
    almacen: AlmacenDetalle;
    empresasAutorizadas: EmpresaAutorizada[];
    resumen: {
        empresas_abastecidas: number;
        tipos_activo: number;
        existencias: number;
        variantes_bajo_minimo: number;
    };
    inventarioPorEmpresa: InventarioEmpresa[];
    permisos: {
        editar: boolean;
        administrar: boolean;
        inventario_ver: boolean;
        inventario_entrada: boolean;
        inventario_ajustar: boolean;
    };
}>();

const buscarInv = ref('');
const inventarioFiltrado = computed<InventarioEmpresa[]>(() => {
    const q = buscarInv.value.trim().toLowerCase();
    if (!q) return props.inventarioPorEmpresa;
    return props.inventarioPorEmpresa
        .map((grupo) => ({
            ...grupo,
            activos: grupo.activos.filter(
                (a) =>
                    (a.activo ?? '').toLowerCase().includes(q) ||
                    (a.categoria ?? '').toLowerCase().includes(q) ||
                    (a.tipo ?? '').toLowerCase().includes(q),
            ),
        }))
        .filter((grupo) => grupo.activos.length > 0);
});

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
    empresas_ids: props.almacen.empresas.map((e) => e.id),
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
                    <Badge
                        v-for="e in almacen.empresas"
                        :key="e.id"
                        variant="outline"
                        class="gap-1"
                    >
                        <Building2 class="size-3" />
                        {{ e.nombre_comercial }}
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
                    <div>
                        <dt
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <Hash class="size-3" /> Código
                        </dt>
                        <dd class="font-mono">{{ almacen.codigo ?? '—' }}</dd>
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
                    <Building2 class="text-muted-foreground size-4" />
                    Empresas abastecidas
                    <AyudaTooltip
                        texto="Razones sociales que se surten desde este almacén. El inventario se mantiene separado por empresa."
                        etiqueta="Ayuda sobre empresas abastecidas"
                    />
                    <Badge variant="secondary">
                        {{ almacen.empresas.length }}
                    </Badge>
                </h2>
                <Button
                    v-if="permisos.administrar"
                    variant="outline"
                    size="sm"
                    @click="abrirEditar"
                >
                    <Pencil class="size-3.5" /> Gestionar empresas
                </Button>
            </div>

            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="e in almacen.empresas"
                    :key="e.id"
                    class="flex items-start justify-between gap-2 rounded-lg border p-3"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">
                            {{ e.nombre_comercial }}
                        </p>
                        <p class="text-muted-foreground font-mono text-xs">
                            {{ e.codigo }}
                        </p>
                    </div>
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="`/empresas/${e.id}`">Ver</Link>
                    </Button>
                </div>
            </div>
        </section>

        <section class="rounded-xl border p-4">
            <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                <ScrollText class="text-muted-foreground size-4" />
                Resumen operativo
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="bg-muted/40 min-w-0 rounded-lg p-3">
                    <p class="text-muted-foreground text-xs">
                        Empresas abastecidas
                    </p>
                    <p class="text-2xl font-semibold">
                        {{ resumen.empresas_abastecidas }}
                    </p>
                </div>
                <div class="bg-muted/40 min-w-0 rounded-lg p-3">
                    <p class="text-muted-foreground text-xs">
                        Tipos de activo con stock
                    </p>
                    <p class="text-2xl font-semibold">
                        {{ resumen.tipos_activo }}
                    </p>
                </div>
                <div class="bg-muted/40 min-w-0 rounded-lg p-3">
                    <p class="text-muted-foreground text-xs">
                        Existencias totales
                    </p>
                    <p class="text-2xl font-semibold">
                        {{ resumen.existencias }}
                    </p>
                </div>
                <div class="bg-muted/40 min-w-0 rounded-lg p-3">
                    <p class="text-muted-foreground text-xs">
                        Variantes bajo mínimo
                    </p>
                    <p
                        class="text-2xl font-semibold"
                        :class="
                            resumen.variantes_bajo_minimo > 0
                                ? 'text-amber-600'
                                : ''
                        "
                    >
                        {{ resumen.variantes_bajo_minimo }}
                    </p>
                </div>
            </div>
        </section>

        <section class="rounded-xl border p-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <Warehouse class="text-muted-foreground size-4" />
                    Inventario del almacén (por empresa)
                </h2>
                <div class="flex flex-wrap items-center gap-2">
                    <input
                        v-model="buscarInv"
                        type="search"
                        placeholder="Buscar activo, tipo o categoría"
                        class="border-input bg-background h-9 min-w-0 rounded-md border px-3 text-sm"
                    />
                    <Button
                        v-if="permisos.inventario_entrada"
                        size="sm"
                        as-child
                    >
                        <Link href="/inventario/entrada"
                            >Registrar entrada</Link
                        >
                    </Button>
                    <Button
                        v-if="permisos.inventario_ver"
                        size="sm"
                        variant="outline"
                        as-child
                    >
                        <Link :href="`/inventario?almacen_id=${almacen.id}`">
                            Ver inventario
                        </Link>
                    </Button>
                    <Button
                        v-if="permisos.inventario_ver"
                        size="sm"
                        variant="ghost"
                        as-child
                    >
                        <Link
                            :href="`/inventario/movimientos?almacen_id=${almacen.id}`"
                        >
                            Movimientos
                        </Link>
                    </Button>
                </div>
            </div>

            <p
                v-if="!inventarioPorEmpresa.length"
                class="text-muted-foreground py-6 text-center text-sm"
            >
                Este almacén todavía no tiene existencias. Registra una entrada
                indicando la empresa y el almacén.
            </p>
            <p
                v-else-if="!inventarioFiltrado.length"
                class="text-muted-foreground py-6 text-center text-sm"
            >
                Sin resultados para «{{ buscarInv }}».
            </p>

            <div v-else class="flex flex-col gap-4">
                <div
                    v-for="grupo in inventarioFiltrado"
                    :key="grupo.empresa.id"
                >
                    <h3
                        class="mb-2 flex items-center gap-2 text-xs font-semibold"
                    >
                        <Building2 class="text-muted-foreground size-3.5" />
                        {{ grupo.empresa.nombre_comercial }}
                        <Badge variant="outline" class="text-xs">
                            {{ grupo.total }}
                        </Badge>
                    </h3>
                    <div class="grid gap-3 md:grid-cols-2">
                        <article
                            v-for="a in grupo.activos"
                            :key="a.activo_id"
                            class="min-w-0 rounded-lg border p-3"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate font-medium">
                                        {{ a.activo }}
                                    </p>
                                    <p
                                        class="text-muted-foreground truncate text-xs"
                                    >
                                        {{
                                            [a.tipo, a.categoria]
                                                .filter(Boolean)
                                                .join(' · ') || '—'
                                        }}
                                    </p>
                                </div>
                                <Badge
                                    :variant="
                                        a.bajo_minimo ? 'secondary' : 'outline'
                                    "
                                    class="shrink-0 text-xs"
                                    :class="
                                        a.bajo_minimo ? 'text-amber-600' : ''
                                    "
                                >
                                    {{ a.total }}
                                </Badge>
                            </div>
                            <ul class="mt-2 flex flex-wrap gap-1.5 text-xs">
                                <li
                                    v-for="v in a.variantes"
                                    :key="v.talla_id"
                                    class="bg-muted/50 rounded px-2 py-0.5"
                                    :class="
                                        v.bajo_minimo ? 'text-amber-600' : ''
                                    "
                                >
                                    {{ v.talla ?? 's/v' }}:
                                    <strong>{{ v.cantidad }}</strong>
                                </li>
                            </ul>
                        </article>
                    </div>
                </div>
            </div>
        </section>

        <Dialog v-model:open="modalEditar">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Editar almacén</DialogTitle>
                    <DialogDescription>
                        Actualiza los datos, el responsable y las empresas
                        abastecidas.
                    </DialogDescription>
                </DialogHeader>
                <FormularioAlmacen
                    :key="claveFormulario"
                    :almacen="editable"
                    :empresas-autorizadas="empresasAutorizadas"
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
                        Este almacén dejará de estar disponible para operaciones
                        (para todas sus empresas). El catálogo de activos y los
                        registros históricos no se modifican, y podrás
                        reactivarlo cuando quieras.
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
