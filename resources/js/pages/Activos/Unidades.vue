<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { QrCode, Search, SquareArrowOutUpRight, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { EmpresaAutorizada } from '@/types/sistema';

type Unidad = {
    id: number;
    public_token: string;
    codigo: string;
    activo: string | null;
    almacen: string | null;
    colaborador: string | null;
    estado: string;
    estado_etiqueta: string;
    condicion: string;
    condicion_etiqueta: string;
    entregable: boolean;
};

const props = defineProps<{
    unidades: {
        data: Unidad[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    empresasAutorizadas: EmpresaAutorizada[];
    filtros: {
        buscar: string;
        empresa_id: number | null;
        activo_id: number | '';
        almacen_id: number | '';
        estado: string;
        condicion: string;
    };
    permisos: { administrar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Activos', href: '/activos' },
            { title: 'Unidades', href: '/activos/unidades' },
        ],
    },
});

const buscar = ref(props.filtros.buscar);
const empresaId = ref<number | ''>(props.filtros.empresa_id ?? '');
const estado = ref(props.filtros.estado);
const condicion = ref(props.filtros.condicion);

const hayFiltros = computed(
    () =>
        buscar.value !== '' ||
        empresaId.value !== '' ||
        estado.value !== '' ||
        condicion.value !== '',
);

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch([buscar, empresaId, estado, condicion], () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get(
            '/activos/unidades',
            {
                buscar: buscar.value || undefined,
                empresa_id: empresaId.value || undefined,
                estado: estado.value || undefined,
                condicion: condicion.value || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['unidades', 'filtros'],
            },
        );
    }, 300);
});

function limpiarFiltros(): void {
    buscar.value = '';
    empresaId.value = '';
    estado.value = '';
    condicion.value = '';
}

const claseSelect =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-2.5 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none';

const idsSeleccionados = ref<number[]>([]);
function alternarSeleccion(id: number): void {
    const i = idsSeleccionados.value.indexOf(id);
    if (i === -1) idsSeleccionados.value.push(id);
    else idsSeleccionados.value.splice(i, 1);
}
function generarEtiquetas(): void {
    if (!idsSeleccionados.value.length) return;
    window.open(
        `/activos/unidades/etiquetas?ids=${idsSeleccionados.value.join(',')}`,
        '_blank',
    );
}
</script>

<template>
    <Head title="Unidades" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Unidades de seguimiento individual"
            descripcion="Cada fila es un objeto físico con código propio generado por el sistema. Selecciona una o varias para generar sus etiquetas QR."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/activos/unidades/exportar"
                    :filtros="filtros"
                />
                <Button
                    v-if="idsSeleccionados.length"
                    variant="outline"
                    @click="generarEtiquetas"
                >
                    <QrCode class="size-4" /> Generar etiquetas ({{
                        idsSeleccionados.length
                    }})
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="flex flex-col gap-3">
            <div class="relative w-full sm:w-[380px]">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="buscar"
                    class="pl-8"
                    placeholder="Buscar por código o activo"
                    aria-label="Buscar unidades"
                />
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <label
                    v-if="empresasAutorizadas.length > 1"
                    class="flex items-center gap-1.5 text-sm"
                >
                    <span class="text-muted-foreground">Empresa</span>
                    <select
                        v-model="empresaId"
                        :class="claseSelect"
                        aria-label="Filtrar por empresa"
                    >
                        <option value="">Todas</option>
                        <option
                            v-for="e in empresasAutorizadas"
                            :key="e.id"
                            :value="e.id"
                        >
                            {{ e.nombre_comercial }}
                        </option>
                    </select>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Estado</span>
                    <select
                        v-model="estado"
                        :class="claseSelect"
                        aria-label="Filtrar por estado"
                    >
                        <option value="">Todos</option>
                        <option value="en_almacen">En almacén</option>
                        <option value="asignada">Asignada</option>
                        <option value="baja">Baja</option>
                    </select>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Condición</span>
                    <select
                        v-model="condicion"
                        :class="claseSelect"
                        aria-label="Filtrar por condición"
                    >
                        <option value="">Todas</option>
                        <option value="funcionando">Funcionando</option>
                        <option value="en_reparacion">En reparación</option>
                        <option value="inservible">Inservible</option>
                        <option value="perdido">Perdido</option>
                        <option value="robado">Robado</option>
                    </select>
                </label>

                <Button
                    v-if="hayFiltros"
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="limpiarFiltros"
                >
                    <X class="size-3.5" /> Limpiar filtros
                </Button>
            </div>
        </div>

        <EstadoVacio
            v-if="!unidades.data.length"
            titulo="No hay unidades para mostrar"
            :descripcion="
                hayFiltros
                    ? 'Ninguna unidad coincide con la búsqueda o los filtros aplicados.'
                    : 'Las unidades se crean desde el alta de un activo de seguimiento individual.'
            "
        />

        <div
            v-else
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <div
                v-for="u in unidades.data"
                :key="u.id"
                class="flex flex-col gap-2 rounded-xl border p-3"
            >
                <div class="flex items-start justify-between gap-2">
                    <label class="flex items-start gap-2">
                        <input
                            type="checkbox"
                            class="mt-1 size-4"
                            :aria-label="`Seleccionar unidad ${u.codigo}`"
                            :checked="idsSeleccionados.includes(u.id)"
                            @change="alternarSeleccion(u.id)"
                        />
                        <div class="min-w-0">
                            <p class="truncate font-mono text-sm font-medium">
                                {{ u.codigo }}
                            </p>
                            <p class="text-muted-foreground truncate text-xs">
                                {{ u.activo ?? '—' }}
                            </p>
                        </div>
                    </label>
                </div>

                <div class="flex flex-wrap gap-1.5">
                    <Badge
                        :variant="u.entregable ? 'default' : 'secondary'"
                        class="text-xs"
                    >
                        {{ u.estado_etiqueta }}
                    </Badge>
                    <Badge variant="outline" class="text-xs">
                        {{ u.condicion_etiqueta }}
                    </Badge>
                </div>

                <p class="text-muted-foreground text-xs">
                    {{ u.almacen ?? 'Sin almacén' }}
                </p>
                <p v-if="u.colaborador" class="text-muted-foreground text-xs">
                    Con: {{ u.colaborador }}
                </p>

                <Button variant="outline" size="sm" class="mt-auto" as-child>
                    <Link :href="`/activos/unidades/${u.public_token}`">
                        <SquareArrowOutUpRight class="size-3.5" /> Ver detalle
                    </Link>
                </Button>
            </div>
        </div>

        <Paginacion :links="unidades.links" :total="unidades.total" />
    </div>
</template>
