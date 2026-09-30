<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Building2, ScanLine, Warehouse as WarehouseIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { claseEstadoVisibleUnidad } from '@/lib/estadoVisibleUnidad';
import type { EstadoVisibleUnidadValor } from '@/lib/estadoVisibleUnidad';

/**
 * Resumen de "Existencias globales" para activos de SEGUIMIENTO INDIVIDUAL:
 * una fila por empresa + almacén de procedencia + activo, con el total de
 * unidades y su desglose por estado visible (misma regla que Unidades,
 * resuelta en el backend). No simula existencias por cantidad: el detalle
 * pieza por pieza vive en Unidades ("Ver unidades").
 */
export type FilaSeguimientoIndividual = {
    empresa_id: number;
    empresa: string | null;
    almacen_id: number;
    almacen: string | null;
    activo_id: number;
    activo: string | null;
    activo_codigo: string | null;
    total: number;
    estados: Record<EstadoVisibleUnidadValor, number>;
};

defineProps<{
    filas: FilaSeguimientoIndividual[];
    vista: 'cards' | 'tabla';
}>();

/** Orden y rótulos de las columnas de estado (etiquetas de negocio). */
const COLUMNAS: { valor: EstadoVisibleUnidadValor; etiqueta: string }[] = [
    { valor: 'disponible', etiqueta: 'En almacén' },
    { valor: 'asignado', etiqueta: 'Asignadas' },
    { valor: 'reparacion', etiqueta: 'En reparación' },
    { valor: 'inservible', etiqueta: 'Inservibles' },
    { valor: 'perdido', etiqueta: 'Perdidas' },
    { valor: 'robado', etiqueta: 'Robadas' },
    { valor: 'baja', etiqueta: 'Baja' },
];

function urlUnidades(f: FilaSeguimientoIndividual): string {
    const params = new URLSearchParams({
        empresa_id: String(f.empresa_id),
        activo_id: String(f.activo_id),
        almacen_id: String(f.almacen_id),
    });
    return `/activos/unidades?${params}`;
}
</script>

<template>
    <div
        v-if="vista === 'cards'"
        class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
    >
        <div
            v-for="f in filas"
            :key="`${f.empresa_id}-${f.almacen_id}-${f.activo_id}`"
            class="flex min-w-0 flex-col gap-2 rounded-xl border p-4 text-sm"
        >
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ f.activo ?? '—' }}</p>
                    <p class="text-muted-foreground truncate text-xs">
                        Seguimiento individual · {{ f.total }}
                        {{ f.total === 1 ? 'unidad' : 'unidades' }}
                    </p>
                </div>
            </div>
            <div class="text-muted-foreground grid gap-1 text-xs">
                <span class="flex min-w-0 items-center gap-1.5 truncate">
                    <Building2 class="size-3.5 shrink-0" />
                    {{ f.empresa ?? '—' }}
                </span>
                <span class="flex min-w-0 items-center gap-1.5 truncate">
                    <WarehouseIcon class="size-3.5 shrink-0" />
                    {{ f.almacen ?? '—' }}
                </span>
            </div>
            <dl class="grid grid-cols-2 gap-1.5 text-xs">
                <div
                    v-for="c in COLUMNAS"
                    :key="c.valor"
                    class="flex items-center justify-between gap-2 rounded-md border px-2 py-1"
                    :class="
                        f.estados[c.valor] > 0
                            ? claseEstadoVisibleUnidad(c.valor)
                            : 'text-muted-foreground'
                    "
                >
                    <dt>{{ c.etiqueta }}</dt>
                    <dd class="font-semibold tabular-nums">
                        {{ f.estados[c.valor] }}
                    </dd>
                </div>
            </dl>
            <div class="mt-auto pt-1">
                <Button as-child variant="outline" size="sm">
                    <Link :href="urlUnidades(f)">
                        <ScanLine class="size-3.5" /> Ver unidades
                    </Link>
                </Button>
            </div>
        </div>
    </div>

    <div v-else class="overflow-x-auto rounded-xl border">
        <table class="w-full min-w-[960px] text-sm">
            <thead class="bg-muted/50 text-muted-foreground text-left">
                <tr>
                    <th class="px-3 py-2 font-medium">Empresa</th>
                    <th class="px-3 py-2 font-medium">Almacén</th>
                    <th class="px-3 py-2 font-medium">Activo</th>
                    <th class="px-3 py-2 text-right font-medium">Total</th>
                    <th
                        v-for="c in COLUMNAS"
                        :key="c.valor"
                        class="px-3 py-2 text-right font-medium whitespace-nowrap"
                    >
                        {{ c.etiqueta }}
                    </th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="f in filas"
                    :key="`${f.empresa_id}-${f.almacen_id}-${f.activo_id}`"
                    class="border-t"
                >
                    <td class="px-3 py-2">{{ f.empresa ?? '—' }}</td>
                    <td class="px-3 py-2">{{ f.almacen ?? '—' }}</td>
                    <td class="px-3 py-2">{{ f.activo ?? '—' }}</td>
                    <td class="px-3 py-2 text-right font-medium tabular-nums">
                        {{ f.total }}
                    </td>
                    <td
                        v-for="c in COLUMNAS"
                        :key="c.valor"
                        class="px-3 py-2 text-right tabular-nums"
                        :class="
                            f.estados[c.valor] > 0
                                ? ''
                                : 'text-muted-foreground'
                        "
                    >
                        {{ f.estados[c.valor] }}
                    </td>
                    <td class="px-3 py-2 text-right">
                        <Button as-child variant="ghost" size="sm">
                            <Link :href="urlUnidades(f)">Ver unidades</Link>
                        </Button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
