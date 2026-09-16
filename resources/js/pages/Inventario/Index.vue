<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Building2,
    Layers,
    PackagePlus,
    Search,
    Settings2,
    SlidersHorizontal,
    Warehouse as WarehouseIcon,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
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
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

type Saldo = {
    id: number;
    empresa_id: number;
    empresa: string | null;
    almacen_id: number;
    activo_id: number;
    talla_id: number;
    almacen: string;
    activo: string;
    talla: string;
    control: string;
    cantidad: number;
    minimo: number;
    bajo_minimo: boolean;
};

type Opcion = { id: number; nombre: string };

type AlmacenOpcion = {
    id: number;
    nombre: string;
    codigo: string | null;
    direccion: string | null;
};

const props = defineProps<{
    saldos: Paginado<Saldo>;
    filtros: Record<string, string | number | undefined>;
    empresasAutorizadas: EmpresaAutorizada[];
    almacenes: AlmacenOpcion[];
    tiposActivo: Opcion[];
    categorias: Opcion[];
    tiposControl: { valor: string; etiqueta: string }[];
    permisos: {
        entrada: boolean;
        ajustar: boolean;
        minimos: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Activos', href: '/activos' },
            { title: 'Existencias globales', href: '/inventario' },
        ],
    },
});

const filtros = reactive({
    buscar: props.filtros.buscar ?? '',
    empresa_id: props.filtros.empresa_id ?? '',
    almacen_id: props.filtros.almacen_id ?? '',
    tipo_activo_id: props.filtros.tipo_activo_id ?? '',
    categoria_id: props.filtros.categoria_id ?? '',
    control: props.filtros.control ?? '',
    estado_stock: props.filtros.estado_stock ?? '',
});

// Almacén seleccionado para el combobox (el filtro guarda sólo el id).
const almacenSel = ref<AlmacenOpcion | null>(
    props.almacenes.find((a) => a.id === Number(props.filtros.almacen_id)) ??
        null,
);
async function buscarAlmacenes(q: string): Promise<AlmacenOpcion[]> {
    const empresa = filtros.empresa_id
        ? `&empresa_id=${filtros.empresa_id}`
        : '';
    const res = await fetch(
        `/almacenes/buscar?q=${encodeURIComponent(q)}${empresa}`,
        { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
    );
    if (!res.ok) return props.almacenes;
    return (await res.json()).almacenes ?? [];
}
function alElegirAlmacen(a: AlmacenOpcion | null) {
    almacenSel.value = a;
    filtros.almacen_id = a?.id ?? '';
}

const empresaSel = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find(
        (e) => e.id === Number(props.filtros.empresa_id),
    ) ?? null,
);
async function buscarEmpresas(q: string): Promise<EmpresaAutorizada[]> {
    const t = q.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

// Resincroniza el filtro si el backend resuelve una empresa distinta a la
// que ya tenía este ref local — nunca se queda con un valor obsoleto ni
// "inventa" la primera empresa de la lista.
watch(
    () => props.filtros.empresa_id,
    (nuevoId) => {
        const id = nuevoId ? Number(nuevoId) : null;
        if (id !== (empresaSel.value?.id ?? null)) {
            empresaSel.value =
                props.empresasAutorizadas.find((e) => e.id === id) ?? null;
        }
    },
);

const tipoActivoSel = ref<Opcion | null>(
    props.tiposActivo.find(
        (t) => t.id === Number(props.filtros.tipo_activo_id),
    ) ?? null,
);
async function buscarTiposActivo(q: string): Promise<Opcion[]> {
    const t = q.trim().toLowerCase();

    return props.tiposActivo.filter((o) => o.nombre.toLowerCase().includes(t));
}

const categoriaSel = ref<Opcion | null>(
    props.categorias.find((c) => c.id === Number(props.filtros.categoria_id)) ??
        null,
);
async function buscarCategorias(q: string): Promise<Opcion[]> {
    const t = q.trim().toLowerCase();

    return props.categorias.filter((o) => o.nombre.toLowerCase().includes(t));
}

// Al cambiar la empresa, el almacén elegido puede pertenecer a otra empresa:
// se limpia para no filtrar por un almacén ajeno.
watch(
    () => filtros.empresa_id,
    () => {
        almacenSel.value = null;
        filtros.almacen_id = '';
    },
);
watch(empresaSel, (e) => {
    filtros.empresa_id = e?.id ?? '';
});
watch(tipoActivoSel, (t) => {
    filtros.tipo_activo_id = t?.id ?? '';
});
watch(categoriaSel, (c) => {
    filtros.categoria_id = c?.id ?? '';
});

const hayFiltros = computed(() =>
    Object.values(filtros).some((v) => v !== '' && v !== undefined),
);

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch(
    filtros,
    () => {
        clearTimeout(temporizador);
        temporizador = setTimeout(() => {
            router.get(
                '/inventario',
                Object.fromEntries(
                    Object.entries(filtros).filter(
                        ([, v]) => v !== '' && v !== undefined,
                    ),
                ),
                { preserveState: true, replace: true, preserveScroll: true },
            );
        }, 300);
    },
    { deep: true },
);

function limpiarFiltros() {
    Object.keys(filtros).forEach(
        (k) => (filtros[k as keyof typeof filtros] = ''),
    );
    empresaSel.value = null;
    almacenSel.value = null;
    tipoActivoSel.value = null;
    categoriaSel.value = null;
}

// La configuración de mínimo POR RENGLÓN vive en el Detalle del activo
// (`Activos/Detalle.vue`) — aquí sólo queda el ajuste de existencia (que no
// existe en ningún otro lugar) y, más abajo, "Aplicar mínimo general" (un
// alcance más amplio, empresa+almacén completos, que tampoco existe en
// Activo Detalle). Evita duplicar el mismo diálogo de mínimo individual en
// dos pantallas.
const dialogo = ref<'ajuste' | null>(null);
const actual = ref<Saldo | null>(null);

const ajuste = useForm({
    empresa_id: 0,
    almacen_id: 0,
    activo_id: 0,
    talla_id: 0,
    existencia_objetivo: 0,
    motivo: '',
});

function abrir(tipo: 'ajuste', s: Saldo) {
    actual.value = s;
    dialogo.value = tipo;
    ajuste.defaults({
        empresa_id: s.empresa_id,
        almacen_id: s.almacen_id,
        activo_id: s.activo_id,
        talla_id: s.talla_id,
        existencia_objetivo: s.cantidad,
        motivo: '',
    });
    ajuste.reset();
}

function guardarAjuste() {
    ajuste.post('/inventario/ajuste', {
        preserveScroll: true,
        onSuccess: () => (dialogo.value = null),
    });
}

// --- "Aplicar mínimo general": mismo mínimo a TODA una empresa + almacén ---
// Sólo tiene sentido (y sólo se ofrece) cuando el listado ya está acotado a
// una empresa y un almacén concretos — nunca "todas las empresas" ni "todos
// los almacenes": el alcance visible en los filtros ES el alcance real de la
// operación, así el usuario nunca aplica algo más amplio de lo que ve.
const dialogoMasivo = ref(false);
const previsualizacionMasiva = ref<number | null>(null);
const previsualizandoMasiva = ref(false);
const formMasivo = useForm({
    empresa_id: 0,
    almacen_id: 0,
    minimo: 0,
});

function abrirMinimoMasivo(): void {
    formMasivo.reset();
    formMasivo.empresa_id = Number(filtros.empresa_id);
    formMasivo.almacen_id = Number(filtros.almacen_id);
    previsualizacionMasiva.value = null;
    dialogoMasivo.value = true;
}

async function previsualizarMasivo(): Promise<void> {
    previsualizandoMasiva.value = true;
    previsualizacionMasiva.value = null;
    try {
        const params = new URLSearchParams({
            empresa_id: String(formMasivo.empresa_id),
            almacen_id: String(formMasivo.almacen_id),
        });
        const res = await fetch(
            `/inventario/minimos/masivo?${params.toString()}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );
        if (res.ok)
            previsualizacionMasiva.value = (await res.json()).combinaciones;
    } finally {
        previsualizandoMasiva.value = false;
    }
}

function confirmarMinimoMasivo(): void {
    formMasivo.post('/inventario/minimos/masivo', {
        preserveScroll: true,
        onSuccess: () => (dialogoMasivo.value = false),
    });
}

const vista = useVistaPreferida('existencias-globales', 'tabla');

function estadoStock(s: Saldo): { texto: string; clase: string } {
    if (s.cantidad <= 0) {
        return {
            texto: 'Sin existencias',
            clase: 'text-rose-600 border-rose-200 dark:border-rose-900',
        };
    }
    if (s.bajo_minimo) {
        return {
            texto: 'Bajo mínimo',
            clase: 'text-amber-600 border-amber-200 dark:border-amber-900',
        };
    }
    return {
        texto: 'OK',
        clase: 'text-emerald-600 border-emerald-200 dark:border-emerald-900',
    };
}
</script>

<template>
    <Head title="Existencias globales" />

    <div class="flex w-full flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Existencias globales"
            descripcion="Consulta las existencias de todos los activos por empresa, almacén y variante, e identifica faltantes o niveles bajos. El mínimo de un activo puntual se configura desde su propio detalle."
        >
            <template #acciones>
                <Button v-if="permisos.entrada" as-child>
                    <Link href="/inventario/entrada">
                        <PackagePlus class="size-4" /> Registrar ingreso de
                        stock
                    </Link>
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="relative w-full sm:max-w-sm">
            <Search
                class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
            />
            <Input
                v-model="filtros.buscar"
                class="pl-8"
                placeholder="Buscar en inventario: activo, código, categoría, tipo, variante…"
                aria-label="Buscar en el inventario"
            />
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div v-if="empresasAutorizadas.length > 1" class="w-full sm:w-56">
                <BuscadorAsync
                    v-model="empresaSel"
                    :buscar="buscarEmpresas"
                    :etiqueta="(e) => String(e.nombre_comercial)"
                    placeholder="Todas las empresas"
                    placeholder-busqueda="Buscar empresa…"
                />
            </div>
            <div class="w-full sm:w-56">
                <BuscadorAsync
                    :model-value="almacenSel"
                    :buscar="buscarAlmacenes"
                    :dependencia="filtros.empresa_id"
                    :etiqueta="(a) => (a as AlmacenOpcion).nombre"
                    :descripcion="
                        (a) =>
                            [
                                (a as AlmacenOpcion).codigo,
                                (a as AlmacenOpcion).direccion,
                            ]
                                .filter(Boolean)
                                .join(' · ')
                    "
                    placeholder="Todos los almacenes"
                    placeholder-busqueda="Buscar almacén por nombre o código"
                    @update:model-value="
                        (v) => alElegirAlmacen(v as AlmacenOpcion | null)
                    "
                />
            </div>
            <div class="w-full sm:w-48">
                <BuscadorAsync
                    v-model="tipoActivoSel"
                    :buscar="buscarTiposActivo"
                    :etiqueta="(t) => String(t.nombre)"
                    placeholder="Todos los tipos"
                    placeholder-busqueda="Buscar tipo…"
                />
            </div>
            <div class="w-full sm:w-48">
                <BuscadorAsync
                    v-model="categoriaSel"
                    :buscar="buscarCategorias"
                    :etiqueta="(c) => String(c.nombre)"
                    placeholder="Todas las categorías"
                    placeholder-busqueda="Buscar categoría…"
                />
            </div>
            <div class="w-full sm:w-48">
                <SelectSimple
                    v-model="filtros.control"
                    :opciones="[
                        { valor: '', etiqueta: 'Cualquier control' },
                        ...tiposControl.map((c) => ({
                            valor: c.valor,
                            etiqueta: c.etiqueta,
                        })),
                    ]"
                />
            </div>
            <div class="w-full sm:w-48">
                <SelectSimple
                    v-model="filtros.estado_stock"
                    :opciones="[
                        { valor: '', etiqueta: 'Cualquier estado' },
                        { valor: 'bajo_minimo', etiqueta: 'Bajo mínimo' },
                        { valor: 'sin_stock', etiqueta: 'Sin stock' },
                        { valor: 'con_stock', etiqueta: 'Con stock' },
                    ]"
                />
            </div>
            <Button
                v-if="hayFiltros"
                variant="ghost"
                size="sm"
                @click="limpiarFiltros"
            >
                Limpiar filtros
            </Button>
            <SelectorVista v-model="vista" class="ml-auto" />
        </div>

        <div
            v-if="permisos.minimos && filtros.empresa_id && filtros.almacen_id"
            class="bg-muted/40 flex flex-wrap items-center gap-2 rounded-lg border p-3 text-sm"
        >
            <span class="text-muted-foreground">
                Con Empresa y Almacén filtrados puedes fijar un mínimo general
                para todo ese alcance.
            </span>
            <Button
                variant="outline"
                size="sm"
                class="ml-auto"
                @click="abrirMinimoMasivo"
            >
                <Settings2 class="size-3.5" />
                Aplicar mínimo general
            </Button>
        </div>

        <EstadoVacio
            v-if="!saldos.data.length"
            titulo="Sin existencias"
            descripcion="No hay inventario con los filtros seleccionados. Registra una entrada indicando la empresa y el almacén."
        />

        <div
            v-else-if="vista === 'cards'"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
        >
            <div
                v-for="s in saldos.data"
                :key="s.id"
                class="flex min-w-0 flex-col gap-2 rounded-xl border p-4 text-sm"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ s.activo }}</p>
                        <p
                            v-if="s.talla"
                            class="text-muted-foreground truncate text-xs"
                        >
                            Variante {{ s.talla }}
                        </p>
                    </div>
                    <Badge
                        variant="outline"
                        class="shrink-0 text-xs"
                        :class="estadoStock(s).clase"
                    >
                        {{ estadoStock(s).texto }}
                    </Badge>
                </div>

                <div class="text-muted-foreground grid gap-1 text-xs">
                    <span class="flex min-w-0 items-center gap-1.5 truncate">
                        <Building2 class="size-3.5 shrink-0" />
                        {{ s.empresa ?? '—' }}
                    </span>
                    <span class="flex min-w-0 items-center gap-1.5 truncate">
                        <WarehouseIcon class="size-3.5 shrink-0" />
                        {{ s.almacen }}
                    </span>
                </div>

                <div
                    class="bg-muted/40 grid grid-cols-2 divide-x rounded-lg text-center"
                >
                    <div class="px-2 py-1.5">
                        <p class="text-muted-foreground text-[11px]">
                            Existencia
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ s.cantidad }}
                        </p>
                    </div>
                    <div class="px-2 py-1.5">
                        <p class="text-muted-foreground text-[11px]">Mínimo</p>
                        <p class="font-semibold tabular-nums">
                            {{ s.minimo }}
                        </p>
                    </div>
                </div>

                <div class="mt-auto flex flex-wrap gap-2 pt-1">
                    <Button
                        v-if="permisos.ajustar"
                        variant="outline"
                        size="sm"
                        @click="abrir('ajuste', s)"
                    >
                        <SlidersHorizontal class="size-3.5" />
                        Ajustar
                    </Button>
                    <Button
                        v-if="permisos.minimos"
                        variant="ghost"
                        size="sm"
                        as-child
                    >
                        <Link :href="`/activos/${s.activo_id}`">
                            <Settings2 class="size-3.5" />
                            Configurar mínimo
                        </Link>
                    </Button>
                    <Button v-else variant="ghost" size="sm" as-child>
                        <Link :href="`/activos/${s.activo_id}`">
                            <Layers class="size-3.5" />
                            Ver desglose
                        </Link>
                    </Button>
                </div>
            </div>
        </div>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Empresa</th>
                        <th class="px-3 py-2 font-medium">Almacén</th>
                        <th class="px-3 py-2 font-medium">Activo</th>
                        <th class="px-3 py-2 font-medium">Variante</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Existencia
                        </th>
                        <th class="px-3 py-2 text-right font-medium">Mínimo</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in saldos.data" :key="s.id" class="border-t">
                        <td class="px-3 py-2">{{ s.empresa }}</td>
                        <td class="px-3 py-2">{{ s.almacen }}</td>
                        <td class="px-3 py-2">{{ s.activo }}</td>
                        <td class="px-3 py-2">{{ s.talla }}</td>
                        <td class="px-3 py-2 text-right font-medium">
                            {{ s.cantidad }}
                            <Badge
                                v-if="s.bajo_minimo"
                                variant="secondary"
                                class="ml-1 text-amber-600"
                                >mín.</Badge
                            >
                        </td>
                        <td class="text-muted-foreground px-3 py-2 text-right">
                            {{ s.minimo }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            <div class="flex flex-wrap justify-end gap-1.5">
                                <Button
                                    v-if="permisos.ajustar"
                                    variant="outline"
                                    size="sm"
                                    @click="abrir('ajuste', s)"
                                >
                                    <SlidersHorizontal class="size-3.5" />
                                    Ajustar
                                </Button>
                                <Button
                                    v-if="permisos.minimos"
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                >
                                    <Link :href="`/activos/${s.activo_id}`">
                                        <Settings2 class="size-3.5" />
                                        Configurar mínimo
                                    </Link>
                                </Button>
                                <Button
                                    v-else
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                >
                                    <Link :href="`/activos/${s.activo_id}`">
                                        <Layers class="size-3.5" />
                                        Ver desglose
                                    </Link>
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="saldos.links" :total="saldos.total" />

        <Dialog
            :open="dialogo === 'ajuste'"
            @update:open="(v: boolean) => !v && (dialogo = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Ajustar existencia</DialogTitle>
                    <DialogDescription>
                        Corrige la existencia real de esta fila de inventario.
                        Queda registrado en el historial de movimientos.
                    </DialogDescription>
                </DialogHeader>
                <dl
                    v-if="actual"
                    class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm"
                >
                    <div>
                        <dt class="text-muted-foreground text-xs">Empresa</dt>
                        <dd>{{ actual.empresa }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Almacén</dt>
                        <dd>{{ actual.almacen }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Activo</dt>
                        <dd>{{ actual.activo }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Variante</dt>
                        <dd>{{ actual.talla || 'Sin variante' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Existencia actual
                        </dt>
                        <dd class="font-medium">{{ actual.cantidad }}</dd>
                    </div>
                </dl>
                <div class="grid gap-3">
                    <div class="grid gap-1.5">
                        <Label for="ajuste-objetivo">Existencia objetivo</Label>
                        <Input
                            id="ajuste-objetivo"
                            v-model.number="ajuste.existencia_objetivo"
                            type="number"
                            min="0"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="ajuste-motivo">Motivo (obligatorio)</Label>
                        <Input id="ajuste-motivo" v-model="ajuste.motivo" />
                        <p
                            v-if="ajuste.errors.motivo"
                            class="text-destructive text-xs"
                        >
                            {{ ajuste.errors.motivo }}
                        </p>
                    </div>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        :disabled="ajuste.processing"
                        @click="dialogo = null"
                    >
                        Cancelar
                    </Button>
                    <Button
                        :disabled="ajuste.processing"
                        @click="guardarAjuste"
                    >
                        {{
                            ajuste.processing
                                ? 'Guardando…'
                                : 'Registrar ajuste'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="dialogoMasivo">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Aplicar mínimo general</DialogTitle>
                    <DialogDescription>
                        Aplica un solo mínimo a TODAS las combinaciones de
                        activo + variante que ya tienen existencia en esta
                        empresa y este almacén. Nunca toca otra empresa ni otro
                        almacén.
                    </DialogDescription>
                </DialogHeader>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <div>
                        <dt class="text-muted-foreground text-xs">Empresa</dt>
                        <dd class="font-medium">
                            {{ empresaSel?.nombre_comercial ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Almacén</dt>
                        <dd class="font-medium">
                            {{ almacenSel?.nombre ?? '—' }}
                        </dd>
                    </div>
                </dl>
                <div class="grid gap-3">
                    <div class="grid gap-1.5">
                        <Label for="masivo-minimo-general">Nuevo mínimo</Label>
                        <Input
                            id="masivo-minimo-general"
                            v-model.number="formMasivo.minimo"
                            type="number"
                            min="0"
                        />
                        <p
                            v-if="formMasivo.errors.minimo"
                            class="text-destructive text-xs"
                        >
                            {{ formMasivo.errors.minimo }}
                        </p>
                    </div>

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="w-fit"
                        :disabled="previsualizandoMasiva"
                        @click="previsualizarMasivo"
                    >
                        {{
                            previsualizandoMasiva
                                ? 'Calculando…'
                                : 'Ver a cuántas combinaciones afecta'
                        }}
                    </Button>

                    <p
                        v-if="previsualizacionMasiva !== null"
                        class="text-sm"
                        :class="
                            previsualizacionMasiva > 0
                                ? 'text-foreground'
                                : 'text-muted-foreground'
                        "
                    >
                        <template v-if="previsualizacionMasiva > 0">
                            Se aplicará el mínimo a
                            <strong>{{ previsualizacionMasiva }}</strong>
                            combinación(es) de esta empresa y almacén.
                        </template>
                        <template v-else>
                            No hay combinaciones con existencia en esa empresa y
                            almacén todavía.
                        </template>
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        :disabled="formMasivo.processing"
                        @click="dialogoMasivo = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        :disabled="
                            !previsualizacionMasiva || formMasivo.processing
                        "
                        @click="confirmarMinimoMasivo"
                    >
                        {{
                            formMasivo.processing
                                ? 'Aplicando…'
                                : 'Confirmar y aplicar'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
