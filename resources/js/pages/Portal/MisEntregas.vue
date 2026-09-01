<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download, PenLine } from '@lucide/vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

defineProps<{
    entregas: {
        id: number;
        folio: string;
        empresa: string;
        sucursal: string;
        fecha_entrega: string;
        estado_etiqueta: string;
        pendiente_firma: boolean;
        acuse_id: number | null;
        items: { prenda: string; talla: string; cantidad: number }[];
    }[];
    sinRegistro: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mis entregas', href: '/portal/mis-entregas' }],
    },
});
</script>

<template>
    <Head title="Mis entregas" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Mis entregas de uniformes"
            descripcion="Consulta tus entregas, firma las pendientes y descarga tus comprobantes."
        />

        <EstadoVacio
            v-if="sinRegistro"
            titulo="Sin colaborador vinculado"
            descripcion="Tu cuenta no está asociada a un registro de colaborador. Contacta a tu administrador."
        />
        <EstadoVacio
            v-else-if="!entregas.length"
            titulo="No tienes entregas"
            descripcion="Todavía no se te ha registrado ninguna entrega de uniformes."
        />

        <Card v-for="e in entregas" :key="e.id">
            <CardHeader class="flex flex-row items-start justify-between">
                <div>
                    <CardTitle class="text-base">{{ e.folio }}</CardTitle>
                    <p class="text-muted-foreground text-xs">
                        {{ e.empresa }} · {{ e.sucursal }} ·
                        {{ e.fecha_entrega }}
                    </p>
                </div>
                <Badge :variant="e.pendiente_firma ? 'secondary' : 'default'">{{
                    e.estado_etiqueta
                }}</Badge>
            </CardHeader>
            <CardContent class="space-y-3">
                <ul class="text-sm">
                    <li
                        v-for="(it, i) in e.items"
                        :key="i"
                        class="flex justify-between border-b py-1 last:border-0"
                    >
                        <span>{{ it.prenda }} · {{ it.talla }}</span>
                        <span class="font-medium">× {{ it.cantidad }}</span>
                    </li>
                </ul>
                <div class="flex gap-2">
                    <Button v-if="e.pendiente_firma" size="sm" as-child>
                        <Link :href="`/entregas/${e.id}/firmar`"
                            ><PenLine class="size-4" /> Firmar recepción</Link
                        >
                    </Button>
                    <Button
                        v-else-if="e.acuse_id"
                        size="sm"
                        variant="outline"
                        as-child
                    >
                        <a :href="`/acuses/${e.acuse_id}/pdf`" target="_blank"
                            ><Download class="size-4" /> Comprobante</a
                        >
                    </Button>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
