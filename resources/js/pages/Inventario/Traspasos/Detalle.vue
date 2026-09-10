<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Renglon = {
    id: number;
    control: 'cantidad' | 'individual';
    activo_origen: string;
    activo_destino: string;
    activo_destino_codigo: string | null;
    activo_destino_creado: boolean;
    talla: string | null;
    cantidad: number;
    unidad_codigo: string | null;
    movimiento_salida_id: number | null;
    movimiento_entrada_id: number | null;
};

defineProps<{
    traspaso: {
        id: number;
        folio: string;
        tipo: 'misma_empresa' | 'interempresa';
        estado: string;
        motivo: string | null;
        notas: string | null;
        ocurrido_en: string;
        realizado_por: string | null;
        empresa_origen: string | null;
        almacen_origen: string | null;
        empresa_destino: string | null;
        almacen_destino: string | null;
        renglones: Renglon[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inventario', href: '/inventario' },
            { title: 'Movimientos', href: '/inventario/movimientos' },
            { title: 'Traspaso', href: '#' },
        ],
    },
});

function fecha(iso: string): string {
    return new Date(iso).toLocaleString('es-MX');
}
</script>

<template>
    <Head :title="`Traspaso ${traspaso.folio}`" />

    <div class="flex w-full flex-col gap-4 p-4">
        <Button variant="ghost" size="sm" as-child class="w-fit">
            <Link href="/inventario/movimientos">
                <ArrowLeft class="size-4" /> Volver a movimientos
            </Link>
        </Button>

        <div class="rounded-xl border p-4">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ traspaso.folio }}
                </h1>
                <Badge
                    :variant="
                        traspaso.tipo === 'interempresa'
                            ? 'default'
                            : 'secondary'
                    "
                >
                    {{
                        traspaso.tipo === 'interempresa'
                            ? 'Entre empresas'
                            : 'Misma empresa'
                    }}
                </Badge>
            </div>
            <div
                class="text-muted-foreground mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"
            >
                <span class="text-foreground font-medium">{{
                    traspaso.empresa_origen
                }}</span>
                <span>{{ traspaso.almacen_origen }}</span>
                <ArrowRight class="size-4" />
                <span class="text-foreground font-medium">{{
                    traspaso.empresa_destino
                }}</span>
                <span>{{ traspaso.almacen_destino }}</span>
            </div>
            <dl
                class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3"
            >
                <div>
                    <dt class="text-muted-foreground text-xs">Fecha y hora</dt>
                    <dd>{{ fecha(traspaso.ocurrido_en) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs">Realizó</dt>
                    <dd>{{ traspaso.realizado_por ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs">Motivo</dt>
                    <dd>{{ traspaso.motivo ?? '—' }}</dd>
                </div>
                <div v-if="traspaso.notas" class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-muted-foreground text-xs">Notas</dt>
                    <dd class="text-pretty">{{ traspaso.notas }}</dd>
                </div>
            </dl>
        </div>

        <section class="rounded-xl border p-4">
            <h2 class="mb-3 text-sm font-semibold">
                Renglones ({{ traspaso.renglones.length }})
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead
                        class="text-muted-foreground border-b text-left text-xs"
                    >
                        <tr>
                            <th class="py-2 pr-3 font-medium">Activo origen</th>
                            <th class="py-2 pr-3 font-medium">
                                Activo destino
                            </th>
                            <th class="py-2 pr-3 font-medium">Variante</th>
                            <th class="py-2 pr-3 font-medium">
                                Cantidad / unidad
                            </th>
                            <th class="py-2 pr-3 font-medium">Movimientos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in traspaso.renglones"
                            :key="r.id"
                            class="border-b last:border-0"
                        >
                            <td class="py-2 pr-3">{{ r.activo_origen }}</td>
                            <td class="py-2 pr-3">
                                {{ r.activo_destino }}
                                <span
                                    v-if="r.activo_destino_codigo"
                                    class="text-muted-foreground"
                                    >· {{ r.activo_destino_codigo }}</span
                                >
                                <Badge
                                    v-if="r.activo_destino_creado"
                                    variant="outline"
                                    class="ml-1"
                                    >creado</Badge
                                >
                            </td>
                            <td class="py-2 pr-3">{{ r.talla ?? '—' }}</td>
                            <td class="py-2 pr-3">
                                <template v-if="r.control === 'individual'">{{
                                    r.unidad_codigo
                                }}</template>
                                <template v-else>{{ r.cantidad }}</template>
                            </td>
                            <td class="text-muted-foreground py-2 pr-3 text-xs">
                                salida #{{ r.movimiento_salida_id ?? '—' }} ·
                                entrada #{{ r.movimiento_entrada_id ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
