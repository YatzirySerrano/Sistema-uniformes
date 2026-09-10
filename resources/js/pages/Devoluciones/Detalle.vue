<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download, FileSignature, PenLine } from '@lucide/vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { varianteBadgeEstadoDevolucion } from '@/lib/estadoDevolucion';
import { fechaHora } from '@/lib/fecha';

type Item = {
    activo: string | null;
    talla: string | null;
    cantidad: number;
    condicion: string | null;
    unidad_codigo: string | null;
    reingresa_inventario: boolean;
    evidencias: { url: string; mime: string }[];
};

const props = defineProps<{
    devolucion: {
        id: number;
        folio: string;
        estado: string;
        estado_etiqueta: string;
        empresa: string | null;
        sucursal: string | null;
        colaborador: string | null;
        numero_empleado: string | null;
        entrega_id: number | null;
        entrega_folio: string | null;
        almacen: string | null;
        fecha: string;
        motivo: string | null;
        notas: string | null;
        registrada_por: string | null;
        registrada_en: string | null;
        confirmada_en: string | null;
        items: Item[];
    };
    acuse: {
        id: number;
        folio: string;
        firmado_en: string;
        tiene_pdf: boolean;
        ver_pdf: boolean;
        ver_firma: boolean;
    } | null;
    permisos: { firmar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Devoluciones', href: '/devoluciones' },
            { title: 'Detalle', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`Devolución ${devolucion.folio}`" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            :titulo="`Devolución ${devolucion.folio}`"
            :descripcion="`${devolucion.colaborador ?? ''} · ${devolucion.sucursal ?? ''}`"
        >
            <template #acciones>
                <Badge
                    :variant="varianteBadgeEstadoDevolucion(devolucion.estado)"
                    >{{ devolucion.estado_etiqueta }}</Badge
                >
            </template>
        </EncabezadoPagina>

        <div class="flex flex-wrap gap-2">
            <Button v-if="permisos.firmar" as-child>
                <Link :href="`/devoluciones/${devolucion.id}/firmar`">
                    <PenLine class="size-4" /> Firmar devolución
                </Link>
            </Button>
            <Button
                v-if="acuse?.tiene_pdf && acuse.ver_pdf"
                variant="outline"
                as-child
            >
                <a
                    :href="`/acuses-devolucion/${acuse.id}/pdf`"
                    target="_blank"
                    rel="noopener"
                >
                    <Download class="size-4" /> Descargar comprobante PDF
                </a>
            </Button>
            <Button v-if="acuse && acuse.ver_firma" variant="outline" as-child>
                <a
                    :href="`/acuses-devolucion/${acuse.id}/firma`"
                    target="_blank"
                    rel="noopener"
                >
                    <FileSignature class="size-4" /> Firma de quien devuelve
                </a>
            </Button>
            <Button v-if="acuse && acuse.ver_firma" variant="outline" as-child>
                <a
                    :href="`/acuses-devolucion/${acuse.id}/firma-operador`"
                    target="_blank"
                    rel="noopener"
                >
                    <FileSignature class="size-4" /> Firma de quien recibe
                </a>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Información general</CardTitle>
            </CardHeader>
            <CardContent class="grid gap-2 text-sm sm:grid-cols-2">
                <p>
                    <span class="text-muted-foreground">Empresa:</span>
                    {{ devolucion.empresa ?? '—' }}
                </p>
                <p>
                    <span class="text-muted-foreground">Sucursal:</span>
                    {{ devolucion.sucursal ?? '—' }}
                </p>
                <p>
                    <span class="text-muted-foreground">Colaborador:</span>
                    {{ devolucion.colaborador ?? '—' }}
                    <span class="text-muted-foreground"
                        >· N.º {{ devolucion.numero_empleado ?? '—' }}</span
                    >
                </p>
                <p>
                    <span class="text-muted-foreground"
                        >Entrega de origen:</span
                    >
                    <Link
                        v-if="devolucion.entrega_id"
                        :href="`/entregas/${devolucion.entrega_id}`"
                        class="text-primary underline-offset-2 hover:underline"
                    >
                        {{ devolucion.entrega_folio }}
                    </Link>
                    <span v-else>—</span>
                </p>
                <p>
                    <span class="text-muted-foreground">Almacén destino:</span>
                    {{ devolucion.almacen ?? '—' }}
                </p>
                <p>
                    <span class="text-muted-foreground">Fecha:</span>
                    {{ devolucion.fecha }}
                </p>
                <p>
                    <span class="text-muted-foreground">Motivo:</span>
                    {{ devolucion.motivo ?? '—' }}
                </p>
                <p>
                    <span class="text-muted-foreground">Notas:</span>
                    {{ devolucion.notas ?? '—' }}
                </p>
                <p>
                    <span class="text-muted-foreground">Registró:</span>
                    {{ devolucion.registrada_por ?? '—' }}
                </p>
                <p>
                    <span class="text-muted-foreground">Registrada el:</span>
                    {{ fechaHora(devolucion.registrada_en) }}
                </p>
                <p v-if="devolucion.confirmada_en">
                    <span class="text-muted-foreground">Confirmada el:</span>
                    {{ fechaHora(devolucion.confirmada_en) }}
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Activos devueltos</CardTitle>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-muted-foreground text-left">
                        <tr>
                            <th class="py-1.5">Activo</th>
                            <th class="py-1.5">Talla / unidad</th>
                            <th class="py-1.5">Condición</th>
                            <th class="py-1.5">Reingresa</th>
                            <th class="py-1.5">Evidencia</th>
                            <th class="py-1.5 text-right">Cantidad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(it, i) in devolucion.items"
                            :key="i"
                            class="border-t"
                        >
                            <td class="py-1.5">{{ it.activo ?? '—' }}</td>
                            <td class="py-1.5">
                                <span
                                    v-if="it.unidad_codigo"
                                    class="font-mono text-xs"
                                    >{{ it.unidad_codigo }}</span
                                >
                                <span v-else>{{ it.talla ?? '—' }}</span>
                            </td>
                            <td class="py-1.5">{{ it.condicion ?? '—' }}</td>
                            <td class="text-muted-foreground py-1.5">
                                {{ it.reingresa_inventario ? 'Sí' : 'No' }}
                            </td>
                            <td class="py-1.5">
                                <div
                                    v-if="it.evidencias.length"
                                    class="flex flex-wrap gap-1"
                                >
                                    <a
                                        v-for="(ev, k) in it.evidencias"
                                        :key="k"
                                        :href="ev.url"
                                        target="_blank"
                                        rel="noopener"
                                        class="block"
                                    >
                                        <img
                                            :src="ev.url"
                                            alt="Evidencia del renglón"
                                            class="size-10 rounded border object-cover"
                                        />
                                    </a>
                                </div>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="py-1.5 text-right">{{ it.cantidad }}</td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <Card v-if="acuse">
            <CardHeader>
                <CardTitle class="text-base">Acuse firmado</CardTitle>
            </CardHeader>
            <CardContent class="grid gap-2 text-sm sm:grid-cols-2">
                <p>
                    <span class="text-muted-foreground">Folio de acuse:</span>
                    {{ acuse.folio }}
                </p>
                <p>
                    <span class="text-muted-foreground">Firmado el:</span>
                    {{ fechaHora(acuse.firmado_en) }}
                </p>
            </CardContent>
        </Card>
    </div>
</template>
