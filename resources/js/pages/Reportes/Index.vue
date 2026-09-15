<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Building2,
    Calendar,
    Package,
    User,
    UserCog,
    Warehouse,
    X,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import DatePicker from '@/components/sistema/DatePicker.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

const props = defineProps<{
    tab: 'entregas' | 'inventario';
    filtros: Record<string, string | number | boolean | undefined>;
    totales?: { entregas: number; activos: number };
    entregas?: Paginado<{
        folio: string;
        fecha_entrega: string;
        empresa: string | null;
        sucursal: string;
        colaborador: string;
        numero_empleado: string;
        encargado: string;
        estado_etiqueta: string;
        activos: number;
    }>;
    inventario?: Paginado<{
        empresa: string | null;
        almacen: string | null;
        activo: string;
        talla: string;
        cantidad: number;
        minimo: number;
        bajo_minimo: boolean;
    }>;
    catalogos: {
        empresas: EmpresaAutorizada[];
        estados: { valor: string; etiqueta: string }[];
    };
    puedeExportar: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Reportes', href: '/reportes' }] },
});

const f = reactive({
    tab: props.tab,
    empresa_id: props.filtros.empresa_id ?? '',
    estado: String(props.filtros.estado ?? ''),
    desde: String(props.filtros.desde ?? ''),
    hasta: String(props.filtros.hasta ?? ''),
    solo_bajo_minimo: !!props.filtros.solo_bajo_minimo,
});

const empresaSel = ref<EmpresaAutorizada | null>(
    props.catalogos.empresas.find((e) => e.id === Number(f.empresa_id)) ?? null,
);
async function buscarEmpresas(q: string): Promise<EmpresaAutorizada[]> {
    const t = q.trim().toLowerCase();

    return props.catalogos.empresas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}
watch(empresaSel, (e) => {
    f.empresa_id = e?.id ?? '';
});

const hayFiltrosActivos = computed(
    () =>
        f.empresa_id !== '' ||
        (f.tab === 'entregas' &&
            (f.estado !== '' || f.desde !== '' || f.hasta !== '')) ||
        (f.tab === 'inventario' && f.solo_bajo_minimo),
);

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch(f, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get(
            '/reportes',
            { ...f, solo_bajo_minimo: f.solo_bajo_minimo ? 1 : undefined },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }, 300);
});

function cambiarTab(t: 'entregas' | 'inventario') {
    f.tab = t;
}

function limpiarFiltros(): void {
    empresaSel.value = null;
    f.empresa_id = '';
    f.estado = '';
    f.desde = '';
    f.hasta = '';
    f.solo_bajo_minimo = false;
}

// Mismos filtros que ve la pantalla (sin `tab`, que no es un filtro de la
// consulta): `BotonesExportar` arma `?<filtros>&formato=xlsx|pdf` contra el
// endpoint del tab activo.
const endpointExportar = computed(() =>
    f.tab === 'inventario'
        ? '/reportes/inventario/exportar'
        : '/reportes/entregas/exportar',
);
const filtrosExportar = computed(() => {
    const { tab: _tab, solo_bajo_minimo, ...resto } = f;
    // El backend interpreta CUALQUIER string no vacío (incluido "false") como
    // verdadero (`$filtros['solo_bajo_minimo'] ?? false`), así que igual que
    // en el `watch` de abajo, el filtro se omite por completo cuando está
    // desmarcado — nunca se manda `solo_bajo_minimo=false`.
    return { ...resto, solo_bajo_minimo: solo_bajo_minimo ? 1 : undefined };
});

const vista = useVistaPreferida('reportes', 'tabla');

function estadoInventario(s: { cantidad: number; bajo_minimo: boolean }): {
    texto: string;
    clase: string;
} {
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
    <Head title="Reportes" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Reportes"
            descripcion="Consulta y exporta información de entregas e inventario. Filtra por empresa cuando lo necesites."
        />

        <div class="flex gap-2">
            <Button
                :variant="f.tab === 'entregas' ? 'default' : 'outline'"
                size="sm"
                @click="cambiarTab('entregas')"
                >Entregas</Button
            >
            <Button
                :variant="f.tab === 'inventario' ? 'default' : 'outline'"
                size="sm"
                @click="cambiarTab('inventario')"
                >Inventario</Button
            >
        </div>

        <Card>
            <CardContent class="flex flex-wrap items-end gap-2 pt-6">
                <div v-if="catalogos.empresas.length > 1" class="w-56">
                    <BuscadorAsync
                        v-model="empresaSel"
                        :buscar="buscarEmpresas"
                        :etiqueta="(e) => String(e.nombre_comercial)"
                        placeholder="Todas las empresas"
                        placeholder-busqueda="Buscar empresa…"
                    />
                </div>
                <template v-if="f.tab === 'entregas'">
                    <div class="w-52">
                        <SelectSimple
                            v-model="f.estado"
                            :opciones="[
                                { valor: '', etiqueta: 'Todos los estados' },
                                ...catalogos.estados.map((e) => ({
                                    valor: e.valor,
                                    etiqueta: e.etiqueta,
                                })),
                            ]"
                        />
                    </div>
                    <div class="w-40">
                        <DatePicker v-model="f.desde" placeholder="Desde" />
                    </div>
                    <div class="w-40">
                        <DatePicker v-model="f.hasta" placeholder="Hasta" />
                    </div>
                </template>
                <label v-else class="flex items-center gap-2 text-sm">
                    <input
                        v-model="f.solo_bajo_minimo"
                        type="checkbox"
                        class="size-4"
                    />
                    Solo bajo mínimo
                </label>
                <Button
                    v-if="hayFiltrosActivos"
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="limpiarFiltros"
                >
                    <X class="size-3.5" /> Limpiar filtros
                </Button>
                <BotonesExportar
                    v-if="puedeExportar"
                    :endpoint="endpointExportar"
                    :filtros="filtrosExportar"
                />
                <SelectorVista v-model="vista" class="ml-auto" />
            </CardContent>
        </Card>

        <div
            v-if="f.tab === 'entregas' && totales"
            class="grid gap-3 sm:grid-cols-2"
        >
            <Card
                ><CardContent class="pt-6">
                    <p class="text-2xl font-semibold">{{ totales.entregas }}</p>
                    <p class="text-muted-foreground text-xs">Entregas</p>
                </CardContent></Card
            >
            <Card
                ><CardContent class="pt-6">
                    <p class="text-2xl font-semibold">{{ totales.activos }}</p>
                    <p class="text-muted-foreground text-xs">
                        Activos entregadas
                    </p>
                </CardContent></Card
            >
        </div>

        <div
            v-if="f.tab === 'entregas' && entregas && vista === 'cards'"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
        >
            <div
                v-for="(e, i) in entregas.data"
                :key="i"
                class="flex min-w-0 flex-col gap-2 rounded-xl border p-4 text-sm"
            >
                <div class="flex items-start justify-between gap-2">
                    <p class="min-w-0 truncate font-medium">{{ e.folio }}</p>
                    <Badge variant="outline" class="shrink-0 text-xs">
                        {{ e.estado_etiqueta }}
                    </Badge>
                </div>
                <p class="flex min-w-0 items-center gap-1.5 truncate">
                    <User class="text-muted-foreground size-3.5 shrink-0" />
                    {{ e.colaborador }}
                    <span class="text-muted-foreground"
                        >· {{ e.numero_empleado }}</span
                    >
                </p>
                <div class="text-muted-foreground grid gap-1 text-xs">
                    <span class="flex min-w-0 items-center gap-1.5 truncate">
                        <Building2 class="size-3.5 shrink-0" />
                        {{ e.empresa ?? '—' }}
                        <span v-if="e.sucursal">· {{ e.sucursal }}</span>
                    </span>
                    <span class="flex min-w-0 items-center gap-1.5 truncate">
                        <UserCog class="size-3.5 shrink-0" />
                        {{ e.encargado }}
                    </span>
                </div>
                <div
                    class="text-muted-foreground mt-auto flex items-center justify-between gap-2 pt-1 text-xs"
                >
                    <span class="flex items-center gap-1">
                        <Package class="size-3.5" />
                        {{ e.activos }} activo(s)
                    </span>
                    <span class="flex items-center gap-1">
                        <Calendar class="size-3.5" />
                        {{ e.fecha_entrega }}
                    </span>
                </div>
            </div>
        </div>

        <div
            v-if="f.tab === 'entregas' && entregas && vista === 'tabla'"
            class="overflow-x-auto rounded-xl border"
        >
            <table class="w-full min-w-[760px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Folio</th>
                        <th class="px-3 py-2 font-medium">Fecha</th>
                        <th class="px-3 py-2 font-medium">Empresa</th>
                        <th class="px-3 py-2 font-medium">Sucursal</th>
                        <th class="px-3 py-2 font-medium">Colaborador</th>
                        <th class="px-3 py-2 font-medium">Responsable</th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Activos
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(e, i) in entregas.data"
                        :key="i"
                        class="border-t"
                    >
                        <td class="px-3 py-2 font-medium">{{ e.folio }}</td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ e.fecha_entrega }}
                        </td>
                        <td class="px-3 py-2">{{ e.empresa }}</td>
                        <td class="px-3 py-2">{{ e.sucursal }}</td>
                        <td class="px-3 py-2">
                            {{ e.colaborador }}
                            <span class="text-muted-foreground"
                                >· {{ e.numero_empleado }}</span
                            >
                        </td>
                        <td class="px-3 py-2">{{ e.encargado }}</td>
                        <td class="px-3 py-2">{{ e.estado_etiqueta }}</td>
                        <td class="px-3 py-2 text-right">{{ e.activos }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="f.tab === 'inventario' && inventario && vista === 'cards'"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
        >
            <div
                v-for="(s, i) in inventario.data"
                :key="i"
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
                        :class="estadoInventario(s).clase"
                    >
                        {{ estadoInventario(s).texto }}
                    </Badge>
                </div>
                <div class="text-muted-foreground grid gap-1 text-xs">
                    <span class="flex min-w-0 items-center gap-1.5 truncate">
                        <Building2 class="size-3.5 shrink-0" />
                        {{ s.empresa ?? '—' }}
                    </span>
                    <span class="flex min-w-0 items-center gap-1.5 truncate">
                        <Warehouse class="size-3.5 shrink-0" />
                        {{ s.almacen ?? '—' }}
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
            </div>
        </div>

        <div
            v-if="f.tab === 'inventario' && inventario && vista === 'tabla'"
            class="overflow-x-auto rounded-xl border"
        >
            <table class="w-full min-w-[560px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Empresa</th>
                        <th class="px-3 py-2 font-medium">Almacén</th>
                        <th class="px-3 py-2 font-medium">Activo</th>
                        <th class="px-3 py-2 font-medium">Talla</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Existencia
                        </th>
                        <th class="px-3 py-2 text-right font-medium">Mínimo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(s, i) in inventario.data"
                        :key="i"
                        class="border-t"
                    >
                        <td class="px-3 py-2">{{ s.empresa }}</td>
                        <td class="px-3 py-2">{{ s.almacen }}</td>
                        <td class="px-3 py-2">{{ s.activo }}</td>
                        <td class="px-3 py-2">{{ s.talla }}</td>
                        <td
                            class="px-3 py-2 text-right font-medium"
                            :class="s.bajo_minimo ? 'text-amber-600' : ''"
                        >
                            {{ s.cantidad }}
                        </td>
                        <td class="text-muted-foreground px-3 py-2 text-right">
                            {{ s.minimo }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion
            v-if="f.tab === 'entregas' && entregas"
            :links="entregas.links"
            :total="entregas.total"
        />
        <Paginacion
            v-else-if="inventario"
            :links="inventario.links"
            :total="inventario.total"
        />
    </div>
</template>
