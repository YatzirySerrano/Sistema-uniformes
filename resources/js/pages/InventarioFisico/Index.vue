<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, ScanLine, Search, SquareArrowOutUpRight, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import { fechaHora } from '@/lib/fecha';
import type { EmpresaAutorizada } from '@/types/sistema';

type Ronda = {
    id: number;
    folio: string;
    nombre: string;
    empresa: string | null;
    almacen: string | null;
    responsable: string | null;
    estado: string;
    estado_etiqueta: string;
    iniciado_en: string | null;
    finalizado_en: string | null;
    esperados: number;
    escaneados: number;
    faltantes: number;
    no_esperados: number;
};

const props = defineProps<{
    rondas: {
        data: Ronda[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    empresasAutorizadas: EmpresaAutorizada[];
    filtros: {
        buscar: string;
        empresa_id: number | null;
        estado: string;
    };
    permisos: { crear: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inventario físico', href: '/inventarios-fisicos' },
        ],
    },
});

const buscar = ref(props.filtros.buscar);
const estado = ref(props.filtros.estado);
const empresaSel = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === props.filtros.empresa_id) ??
        null,
);
const empresaId = computed(() => empresaSel.value?.id ?? '');

watch(
    () => props.filtros.empresa_id,
    (nuevo) => {
        if (nuevo !== (empresaSel.value?.id ?? null)) {
            empresaSel.value =
                props.empresasAutorizadas.find((e) => e.id === nuevo) ?? null;
        }
    },
);

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();
    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

const hayFiltros = computed(
    () => buscar.value !== '' || empresaId.value !== '' || estado.value !== '',
);

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch([buscar, empresaId, estado], () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get(
            '/inventarios-fisicos',
            {
                buscar: buscar.value || undefined,
                empresa_id: empresaId.value || undefined,
                estado: estado.value || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['rondas', 'filtros'],
            },
        );
    }, 300);
});

function limpiarFiltros(): void {
    buscar.value = '';
    estado.value = '';
    empresaSel.value = null;
}

function fecha(valor: string | null): string {
    return fechaHora(valor);
}

// Tabla ↔ Tarjetas: sólo cambia la presentación; el dataset (misma query,
// paginación y filtros) es el mismo. Preferencia recordada por dispositivo.
const vista = useVistaPreferida('inventario-fisico', 'tabla');
</script>

<template>
    <Head title="Inventario físico" />

    <div class="flex w-full flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Inventario físico"
            descripcion="Rondas de corte físico por escaneo de QR: recorres las instalaciones, escaneas cada unidad identificada y el sistema compara lo encontrado contra lo que tiene registrado. Es un módulo de verificación: no mueve stock ni cambia asignaciones."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/inventarios-fisicos/exportar"
                    :filtros="filtros"
                />
                <Button v-if="permisos.crear" as-child>
                    <Link href="/inventarios-fisicos/crear">
                        <Plus class="size-4" /> Nueva ronda
                    </Link>
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <div class="relative w-full sm:w-[320px]">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="buscar"
                    class="pl-8"
                    placeholder="Buscar por folio o nombre"
                    aria-label="Buscar rondas"
                />
            </div>

            <label
                v-if="empresasAutorizadas.length > 1"
                class="flex items-center gap-1.5 text-sm"
            >
                <span class="text-muted-foreground">Empresa</span>
                <BuscadorAsync
                    v-model="empresaSel"
                    :buscar="buscarEmpresas"
                    :etiqueta="(e) => String(e.nombre_comercial)"
                    placeholder="Todas"
                    placeholder-busqueda="Buscar empresa…"
                    class="w-56"
                />
            </label>

            <label class="flex items-center gap-1.5 text-sm">
                <span class="text-muted-foreground">Estado</span>
                <div class="w-40">
                    <SelectSimple
                        v-model="estado"
                        :opciones="[
                            { valor: '', etiqueta: 'Todos' },
                            { valor: 'en_proceso', etiqueta: 'En proceso' },
                            { valor: 'finalizado', etiqueta: 'Finalizado' },
                        ]"
                    />
                </div>
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

            <SelectorVista v-model="vista" class="ml-auto" />
        </div>

        <EstadoVacio
            v-if="!rondas.data.length"
            titulo="Todavía no hay rondas de inventario físico"
            :descripcion="
                hayFiltros
                    ? 'Ninguna ronda coincide con la búsqueda o los filtros.'
                    : 'Inicia una ronda para comparar físicamente las unidades identificadas de una empresa contra lo registrado en el sistema.'
            "
        >
            <template #icono><ScanLine class="size-6" /></template>
        </EstadoVacio>

        <div
            v-else-if="vista === 'tabla'"
            class="overflow-x-auto rounded-xl border"
        >
            <table class="w-full min-w-[900px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Folio / Nombre</th>
                        <th class="px-3 py-2 font-medium">Empresa</th>
                        <th class="px-3 py-2 font-medium">Inicio</th>
                        <th class="px-3 py-2 font-medium">Responsable</th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Esperados
                        </th>
                        <th class="px-3 py-2 text-right font-medium">
                            Escaneados
                        </th>
                        <th class="px-3 py-2 text-right font-medium">
                            Faltantes
                        </th>
                        <th class="px-3 py-2 text-right font-medium">
                            No esperados
                        </th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in rondas.data"
                        :key="r.id"
                        class="hover:bg-muted/40 border-t transition-colors"
                    >
                        <td class="px-3 py-2">
                            <p class="font-mono text-xs">{{ r.folio }}</p>
                            <p class="font-medium">{{ r.nombre }}</p>
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ r.empresa ?? '—' }}
                            <span v-if="r.almacen" class="block text-xs"
                                >Almacén: {{ r.almacen }}</span
                            >
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ fecha(r.iniciado_en) }}
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ r.responsable ?? '—' }}
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                variant="outline"
                                :class="
                                    r.estado === 'en_proceso'
                                        ? 'border-amber-500/40 text-amber-700 dark:text-amber-400'
                                        : 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400'
                                "
                            >
                                {{ r.estado_etiqueta }}
                            </Badge>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ r.esperados }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ r.escaneados }}
                        </td>
                        <td
                            class="px-3 py-2 text-right tabular-nums"
                            :class="
                                r.faltantes > 0
                                    ? 'font-semibold text-red-600 dark:text-red-400'
                                    : ''
                            "
                        >
                            {{ r.faltantes }}
                        </td>
                        <td
                            class="px-3 py-2 text-right tabular-nums"
                            :class="
                                r.no_esperados > 0
                                    ? 'font-semibold text-amber-600 dark:text-amber-400'
                                    : ''
                            "
                        >
                            {{ r.no_esperados }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            <Button variant="outline" size="sm" as-child>
                                <Link :href="`/inventarios-fisicos/${r.id}`">
                                    <SquareArrowOutUpRight class="size-3.5" />
                                    Ver
                                </Link>
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-else
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <div
                v-for="r in rondas.data"
                :key="r.id"
                class="flex flex-col gap-3 rounded-xl border p-4"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-mono text-xs">{{ r.folio }}</p>
                        <p class="truncate font-medium">{{ r.nombre }}</p>
                    </div>
                    <Badge
                        variant="outline"
                        class="shrink-0"
                        :class="
                            r.estado === 'en_proceso'
                                ? 'border-amber-500/40 text-amber-700 dark:text-amber-400'
                                : 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400'
                        "
                    >
                        {{ r.estado_etiqueta }}
                    </Badge>
                </div>

                <div class="text-muted-foreground text-sm">
                    <p>{{ r.empresa ?? '—' }}</p>
                    <p v-if="r.almacen">Almacén: {{ r.almacen }}</p>
                </div>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    <dt class="text-muted-foreground">Esperados</dt>
                    <dd class="text-right font-medium tabular-nums">
                        {{ r.esperados }}
                    </dd>
                    <dt class="text-muted-foreground">Escaneados</dt>
                    <dd class="text-right font-medium tabular-nums">
                        {{ r.escaneados }}
                    </dd>
                    <dt class="text-muted-foreground">Faltantes</dt>
                    <dd
                        class="text-right font-medium tabular-nums"
                        :class="
                            r.faltantes > 0
                                ? 'text-red-600 dark:text-red-400'
                                : ''
                        "
                    >
                        {{ r.faltantes }}
                    </dd>
                    <dt class="text-muted-foreground">No esperados</dt>
                    <dd
                        class="text-right font-medium tabular-nums"
                        :class="
                            r.no_esperados > 0
                                ? 'text-amber-600 dark:text-amber-400'
                                : ''
                        "
                    >
                        {{ r.no_esperados }}
                    </dd>
                </dl>

                <div class="text-muted-foreground text-xs">
                    <p>Responsable: {{ r.responsable ?? '—' }}</p>
                    <p>Inicio: {{ fecha(r.iniciado_en) }}</p>
                </div>

                <Button
                    variant="outline"
                    size="sm"
                    class="mt-auto self-end"
                    as-child
                >
                    <Link :href="`/inventarios-fisicos/${r.id}`">
                        <SquareArrowOutUpRight class="size-3.5" /> Ver
                    </Link>
                </Button>
            </div>
        </div>

        <Paginacion :links="rondas.links" :total="rondas.total" />
    </div>
</template>
