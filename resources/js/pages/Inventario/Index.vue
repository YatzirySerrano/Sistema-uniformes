<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { PackagePlus, Search } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    layout: { breadcrumbs: [{ title: 'Inventario', href: '/inventario' }] },
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

const dialogo = ref<'ajuste' | 'minimo' | null>(null);
const actual = ref<Saldo | null>(null);

const ajuste = useForm({
    empresa_id: 0,
    almacen_id: 0,
    activo_id: 0,
    talla_id: 0,
    existencia_objetivo: 0,
    motivo: '',
});
const minimo = useForm({
    empresa_id: 0,
    almacen_id: 0,
    activo_id: 0,
    talla_id: 0,
    minimo: 0,
});

function abrir(tipo: 'ajuste' | 'minimo', s: Saldo) {
    actual.value = s;
    dialogo.value = tipo;
    if (tipo === 'ajuste') {
        ajuste.defaults({
            empresa_id: s.empresa_id,
            almacen_id: s.almacen_id,
            activo_id: s.activo_id,
            talla_id: s.talla_id,
            existencia_objetivo: s.cantidad,
            motivo: '',
        });
        ajuste.reset();
    } else {
        minimo.defaults({
            empresa_id: s.empresa_id,
            almacen_id: s.almacen_id,
            activo_id: s.activo_id,
            talla_id: s.talla_id,
            minimo: s.minimo,
        });
        minimo.reset();
    }
}

function guardarAjuste() {
    ajuste.post('/inventario/ajuste', {
        preserveScroll: true,
        onSuccess: () => (dialogo.value = null),
    });
}
function guardarMinimo() {
    minimo.post('/inventario/minimos', {
        preserveScroll: true,
        onSuccess: () => (dialogo.value = null),
    });
}
</script>

<template>
    <Head title="Inventario" />

    <div class="flex w-full flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Inventario"
            descripcion="Existencias por EMPRESA + ALMACÉN + ACTIVO + VARIANTE. Un mismo almacén puede abastecer a varias empresas; su stock se mantiene separado por empresa."
        >
            <template #acciones>
                <Button v-if="permisos.entrada" as-child>
                    <Link href="/inventario/entrada">
                        <PackagePlus class="size-4" /> Registrar entrada
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
        </div>

        <EstadoVacio
            v-if="!saldos.data.length"
            titulo="Sin existencias"
            descripcion="No hay inventario con los filtros seleccionados. Registra una entrada indicando la empresa y el almacén."
        />

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
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <button
                                v-if="permisos.ajustar"
                                class="text-primary text-xs hover:underline"
                                @click="abrir('ajuste', s)"
                            >
                                Ajustar
                            </button>
                            <button
                                v-if="permisos.minimos"
                                class="text-primary ml-3 text-xs hover:underline"
                                @click="abrir('minimo', s)"
                            >
                                Mínimo
                            </button>
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
                </DialogHeader>
                <p v-if="actual" class="text-muted-foreground text-sm">
                    {{ actual.activo }} · {{ actual.talla }} ·
                    {{ actual.almacen }} — existencia actual
                    {{ actual.cantidad }}
                </p>
                <div class="grid gap-3">
                    <div class="grid gap-1.5">
                        <Label>Existencia objetivo</Label>
                        <Input
                            v-model.number="ajuste.existencia_objetivo"
                            type="number"
                            min="0"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Motivo (obligatorio)</Label>
                        <Input v-model="ajuste.motivo" />
                        <p
                            v-if="ajuste.errors.motivo"
                            class="text-destructive text-xs"
                        >
                            {{ ajuste.errors.motivo }}
                        </p>
                    </div>
                    <Button
                        :disabled="ajuste.processing"
                        @click="guardarAjuste"
                    >
                        Registrar ajuste
                    </Button>
                </div>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="dialogo === 'minimo'"
            @update:open="(v: boolean) => !v && (dialogo = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Configurar mínimo</DialogTitle>
                </DialogHeader>
                <p v-if="actual" class="text-muted-foreground text-sm">
                    {{ actual.activo }} · {{ actual.talla }} ·
                    {{ actual.almacen }}
                </p>
                <div class="grid gap-3">
                    <div class="grid gap-1.5">
                        <Label>Existencia mínima</Label>
                        <Input
                            v-model.number="minimo.minimo"
                            type="number"
                            min="0"
                        />
                    </div>
                    <Button :disabled="minimo.processing" @click="guardarMinimo"
                        >Guardar mínimo</Button
                    >
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
