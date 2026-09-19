<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { fechaHora } from '@/lib/fecha';

defineProps<{
    movimiento: {
        id: number;
        tipo: string;
        tipo_etiqueta: string;
        direccion: 'entrada' | 'salida';
        cantidad: number;
        existencia_anterior: number;
        existencia_resultante: number;
        empresa: string | null;
        almacen: string | null;
        sucursal: string | null;
        activo: string | null;
        activo_codigo: string | null;
        talla: string | null;
        unidad_codigo: string | null;
        motivo: string | null;
        notas: string | null;
        realizado_por: string | null;
        ocurrido_en: string;
        referencia: { etiqueta: string | null; url: string | null };
        colaborador: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inventario', href: '/inventario' },
            { title: 'Movimientos', href: '/inventario/movimientos' },
            { title: 'Detalle', href: '#' },
        ],
    },
});

function fecha(iso: string): string {
    return fechaHora(iso);
}
</script>

<template>
    <Head :title="`Movimiento — ${movimiento.tipo_etiqueta}`" />

    <div class="flex w-full flex-col gap-4 p-4">
        <Button variant="ghost" size="sm" as-child class="w-fit">
            <Link href="/inventario/movimientos">
                <ArrowLeft class="size-4" /> Volver a movimientos
            </Link>
        </Button>

        <div class="rounded-xl border p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-semibold tracking-tight">
                        {{ movimiento.tipo_etiqueta }}
                    </h1>
                    <Badge
                        :variant="
                            movimiento.direccion === 'entrada'
                                ? 'default'
                                : 'secondary'
                        "
                    >
                        {{
                            movimiento.direccion === 'entrada'
                                ? 'Entrada'
                                : 'Salida'
                        }}
                    </Badge>
                </div>
                <span
                    class="text-lg font-semibold whitespace-nowrap"
                    :class="
                        movimiento.direccion === 'entrada'
                            ? 'text-emerald-600'
                            : 'text-rose-600'
                    "
                >
                    {{ movimiento.direccion === 'entrada' ? '+' : '−'
                    }}{{ movimiento.cantidad }}
                </span>
            </div>

            <dl
                class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3"
            >
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-muted-foreground text-xs">Activo</dt>
                    <dd class="text-base font-semibold">
                        {{ movimiento.activo ?? '—' }}
                    </dd>
                </div>
                <div v-if="movimiento.activo_codigo">
                    <dt class="text-muted-foreground text-xs">Código</dt>
                    <dd class="font-mono">{{ movimiento.activo_codigo }}</dd>
                </div>
                <div v-if="!movimiento.unidad_codigo">
                    <dt class="text-muted-foreground text-xs">
                        Variante / talla
                    </dt>
                    <dd>{{ movimiento.talla ?? 'Sin variante' }}</dd>
                </div>
                <div v-if="movimiento.unidad_codigo">
                    <dt class="text-muted-foreground text-xs">Unidad</dt>
                    <dd class="font-mono">{{ movimiento.unidad_codigo }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs">Fecha y hora</dt>
                    <dd>{{ fecha(movimiento.ocurrido_en) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs">Empresa</dt>
                    <dd>{{ movimiento.empresa ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs">Almacén</dt>
                    <dd>
                        {{ movimiento.almacen ?? '—'
                        }}<span v-if="movimiento.sucursal">
                            · {{ movimiento.sucursal }}</span
                        >
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs">
                        Existencia antes → después
                    </dt>
                    <dd>
                        {{ movimiento.existencia_anterior }} →
                        {{ movimiento.existencia_resultante }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs">Realizó</dt>
                    <dd>{{ movimiento.realizado_por ?? '—' }}</dd>
                </div>
                <div v-if="movimiento.colaborador">
                    <dt class="text-muted-foreground text-xs">Colaborador</dt>
                    <dd>{{ movimiento.colaborador }}</dd>
                </div>
                <div v-if="movimiento.referencia.etiqueta">
                    <dt class="text-muted-foreground text-xs">
                        Documento de origen
                    </dt>
                    <dd>
                        <Link
                            v-if="movimiento.referencia.url"
                            :href="movimiento.referencia.url"
                            class="text-primary underline"
                        >
                            {{ movimiento.referencia.etiqueta }}
                        </Link>
                        <span v-else>{{ movimiento.referencia.etiqueta }}</span>
                    </dd>
                </div>
                <div
                    v-if="movimiento.motivo"
                    class="sm:col-span-2 lg:col-span-3"
                >
                    <dt class="text-muted-foreground text-xs">Motivo</dt>
                    <dd class="text-pretty">{{ movimiento.motivo }}</dd>
                </div>
                <div
                    v-if="movimiento.notas"
                    class="sm:col-span-2 lg:col-span-3"
                >
                    <dt class="text-muted-foreground text-xs">Notas</dt>
                    <dd class="text-pretty">{{ movimiento.notas }}</dd>
                </div>
            </dl>
        </div>
    </div>
</template>
