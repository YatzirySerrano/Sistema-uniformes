<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Boxes,
    Layers,
    Package,
    Pencil,
    ScrollText,
} from '@lucide/vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

defineProps<{
    activo: {
        id: number;
        nombre: string;
        descripcion: string | null;
        categoria: string | null;
        codigo: string | null;
        activo: boolean;
        tipo: string | null;
        tipo_control: 'cantidad' | 'serializado';
        tipo_control_etiqueta: string;
        imagen_url: string | null;
        tallas: string[];
    };
    saldos: {
        sucursal: string;
        talla: string;
        cantidad: number;
        minimo: number;
        bajo_minimo: boolean;
    }[];
    permisos: { editar: boolean; administrar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Activos', href: '/activos' },
            { title: 'Detalle', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="activo.nombre" />

    <div class="flex w-full flex-col gap-4 p-4">
        <Button variant="ghost" size="sm" as-child class="w-fit">
            <Link href="/activos">
                <ArrowLeft class="size-4" /> Volver a activos
            </Link>
        </Button>

        <div
            class="flex flex-col gap-4 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0">
                <h1
                    class="flex items-center gap-2 truncate text-xl font-semibold tracking-tight"
                >
                    <Package class="text-muted-foreground size-5 shrink-0" />
                    {{ activo.nombre }}
                </h1>
                <p class="text-muted-foreground font-mono text-sm">
                    {{ activo.codigo ?? '—' }}
                </p>
                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    <Badge v-if="activo.tipo" variant="outline" class="gap-1">
                        <Layers class="size-3" /> {{ activo.tipo }}
                    </Badge>
                    <Badge variant="outline" class="gap-1">
                        <Boxes class="size-3" />
                        {{ activo.tipo_control_etiqueta }}
                    </Badge>
                    <Badge :variant="activo.activo ? 'default' : 'secondary'">
                        {{ activo.activo ? 'Activo' : 'Inactivo' }}
                    </Badge>
                </div>
            </div>

            <Button v-if="permisos.editar" variant="outline" size="sm" as-child>
                <Link :href="`/activos/${activo.id}/editar`">
                    <Pencil class="size-3.5" /> Editar
                </Link>
            </Button>
        </div>

        <div class="grid gap-4 lg:grid-cols-[320px_1fr]">
            <div class="flex flex-col gap-4">
                <section class="rounded-xl border p-4">
                    <h2
                        class="mb-3 flex items-center gap-2 text-sm font-semibold"
                    >
                        <Package class="text-muted-foreground size-4" /> Imagen
                    </h2>
                    <div
                        class="bg-muted flex h-52 items-center justify-center overflow-hidden rounded-lg border"
                    >
                        <img
                            v-if="activo.imagen_url"
                            :src="activo.imagen_url"
                            class="h-full w-full object-cover"
                            alt=""
                        />
                        <span v-else class="text-muted-foreground text-xs"
                            >Sin imagen</span
                        >
                    </div>
                </section>

                <section class="rounded-xl border p-4">
                    <h2
                        class="mb-3 flex items-center gap-2 text-sm font-semibold"
                    >
                        <ScrollText class="text-muted-foreground size-4" />
                        Información
                    </h2>
                    <dl class="grid gap-3 text-sm">
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Código
                            </dt>
                            <dd class="font-mono">
                                {{ activo.codigo ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Categoría
                            </dt>
                            <dd>{{ activo.categoria ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Descripción
                            </dt>
                            <dd class="text-pretty">
                                {{ activo.descripcion ?? 'Sin descripción.' }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl border p-4">
                    <h2
                        class="mb-3 flex items-center gap-2 text-sm font-semibold"
                    >
                        <Layers class="text-muted-foreground size-4" />
                        Variantes / tallas
                        <AyudaTooltip
                            texto="Un activo puede tener variantes/tallas (uniformes) o ninguna (equipo de cómputo)."
                            etiqueta="Ayuda sobre variantes"
                        />
                    </h2>
                    <div
                        v-if="activo.tallas.length"
                        class="flex flex-wrap gap-1"
                    >
                        <span
                            v-for="t in activo.tallas"
                            :key="t"
                            class="bg-muted rounded px-1.5 py-0.5 font-mono text-[11px]"
                            >{{ t }}</span
                        >
                    </div>
                    <p v-else class="text-muted-foreground text-sm">
                        Este activo no maneja variantes / tallas.
                    </p>
                </section>
            </div>

            <section class="rounded-xl border p-4">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <Boxes class="text-muted-foreground size-4" />
                    Inventario relacionado
                    <AyudaTooltip
                        texto="Existencias actuales por sucursal y talla. La administración de inventario por almacén llegará en un bloque posterior."
                        etiqueta="Ayuda sobre inventario relacionado"
                    />
                </h2>

                <p
                    v-if="activo.tipo_control === 'serializado'"
                    class="text-muted-foreground bg-muted/40 rounded-lg p-3 text-sm"
                >
                    Este activo es serializado. El control de unidades
                    individuales (número de serie / IMEI) se habilitará en un
                    bloque posterior.
                </p>

                <div v-else class="overflow-x-auto rounded-lg border">
                    <table class="w-full min-w-[420px] text-sm">
                        <thead
                            class="bg-muted/50 text-muted-foreground text-left"
                        >
                            <tr>
                                <th class="px-3 py-2 font-medium">Sucursal</th>
                                <th class="px-3 py-2 font-medium">Talla</th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Existencia
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Mínimo
                                </th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(s, i) in saldos"
                                :key="i"
                                class="border-t"
                            >
                                <td class="px-3 py-2">{{ s.sucursal }}</td>
                                <td class="px-3 py-2">{{ s.talla }}</td>
                                <td class="px-3 py-2 text-right font-medium">
                                    {{ s.cantidad }}
                                </td>
                                <td
                                    class="text-muted-foreground px-3 py-2 text-right"
                                >
                                    {{ s.minimo }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <Badge
                                        v-if="s.bajo_minimo"
                                        variant="secondary"
                                        class="text-amber-600"
                                        >Bajo mínimo</Badge
                                    >
                                </td>
                            </tr>
                            <tr v-if="!saldos.length">
                                <td
                                    colspan="5"
                                    class="text-muted-foreground px-3 py-6 text-center"
                                >
                                    Este activo todavía no tiene existencias.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</template>
