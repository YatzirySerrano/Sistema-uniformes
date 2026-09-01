<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

defineProps<{
    prenda: {
        id: number;
        nombre: string;
        descripcion: string | null;
        categoria: string | null;
        codigo_interno: string | null;
        activa: boolean;
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
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Prendas', href: '/prendas' },
            { title: 'Detalle', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="prenda.nombre" />

    <div class="flex flex-col gap-6 p-4">
        <EncabezadoPagina
            :titulo="prenda.nombre"
            :descripcion="prenda.codigo_interno ?? undefined"
        >
            <template #acciones>
                <Button variant="outline" as-child>
                    <Link :href="`/prendas/${prenda.id}/editar`">Editar</Link>
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="grid gap-6 lg:grid-cols-[280px_1fr]">
            <div class="space-y-3">
                <div
                    class="bg-muted flex h-56 items-center justify-center overflow-hidden rounded-xl border"
                >
                    <img
                        v-if="prenda.imagen_url"
                        :src="prenda.imagen_url"
                        class="h-full w-full object-cover"
                        alt=""
                    />
                    <span v-else class="text-muted-foreground text-xs"
                        >Sin imagen</span
                    >
                </div>
                <p class="text-muted-foreground text-sm">
                    {{ prenda.descripcion ?? 'Sin descripción.' }}
                </p>
                <div class="flex flex-wrap gap-1">
                    <span
                        v-for="t in prenda.tallas"
                        :key="t"
                        class="bg-muted rounded px-1.5 py-0.5 text-[11px]"
                        >{{ t }}</span
                    >
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full min-w-[420px] text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-left">
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
                        <tr v-for="(s, i) in saldos" :key="i" class="border-t">
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
                                Esta prenda todavía no tiene existencias.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
