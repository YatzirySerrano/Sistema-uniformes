<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download, FileSignature, PenLine, Pencil, Undo2 } from '@lucide/vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { varianteBadgeEstadoEntrega } from '@/lib/estadoEntrega';
import { claseEstadoVisibleUnidad } from '@/lib/estadoVisibleUnidad';

const props = defineProps<{
    entrega: {
        id: number;
        folio: string;
        estado: string;
        estado_etiqueta: string;
        fecha_entrega: string;
        confirmada_en: string | null;
        notas: string | null;
        empresa: string | null;
        almacen: string | null;
        servicio: { nombre: string; contrato: string } | null;
        colaborador: {
            nombre_completo: string;
            numero_empleado: string;
        } | null;
        sucursal: string;
        encargado: string;
        items: {
            activo: string;
            talla: string | null;
            cantidad: number;
            unidad_codigo: string | null;
            unidad_estado_visible: string | null;
            unidad_estado_visible_etiqueta: string | null;
            conjunto: string | null;
        }[];
        correcciones: {
            id: number;
            motivo: string;
            por: string;
            fecha: string;
        }[];
    };
    acuse: {
        id: number;
        folio: string;
        firmado_en: string;
        tiene_pdf: boolean;
    } | null;
    permisos: {
        firmar: boolean;
        corregir: boolean;
        ver_pdf: boolean;
        ver_firma: boolean;
        devolver: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Entregas', href: '/entregas' },
            { title: 'Detalle', href: '#' },
        ],
    },
});

const pendiente = props.entrega.estado === 'pendiente_firma';
</script>

<template>
    <Head :title="`Entrega ${entrega.folio}`" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            :titulo="`Entrega ${entrega.folio}`"
            :descripcion="`${entrega.colaborador?.nombre_completo} · ${entrega.sucursal}`"
        >
            <template #acciones>
                <Badge :variant="varianteBadgeEstadoEntrega(entrega.estado)">{{
                    entrega.estado_etiqueta
                }}</Badge>
            </template>
        </EncabezadoPagina>

        <div class="flex flex-wrap gap-2">
            <Button v-if="pendiente && permisos.firmar" as-child>
                <Link :href="`/entregas/${entrega.id}/firmar`">
                    <PenLine class="size-4" /> Capturar firma de recepción
                </Link>
            </Button>
            <Button
                v-if="acuse?.tiene_pdf && permisos.ver_pdf"
                variant="outline"
                as-child
            >
                <a :href="`/acuses/${acuse.id}/pdf`" target="_blank">
                    <Download class="size-4" /> Ver comprobante PDF
                </a>
            </Button>
            <Button
                v-if="acuse && permisos.ver_firma"
                variant="outline"
                as-child
            >
                <a :href="`/acuses/${acuse.id}/firma`" target="_blank">
                    <FileSignature class="size-4" /> Firma de quien recibe
                </a>
            </Button>
            <Button
                v-if="acuse && permisos.ver_firma"
                variant="outline"
                as-child
            >
                <a :href="`/acuses/${acuse.id}/firma-operador`" target="_blank">
                    <FileSignature class="size-4" /> Firma de quien entrega
                </a>
            </Button>
            <Button
                v-if="!pendiente && permisos.corregir"
                variant="outline"
                as-child
            >
                <Link :href="`/entregas/${entrega.id}/corregir`">
                    <Pencil class="size-4" /> Corregir entrega
                </Link>
            </Button>
            <Button v-if="permisos.devolver" variant="outline" as-child>
                <Link :href="`/devoluciones/crear?entrega_id=${entrega.id}`">
                    <Undo2 class="size-4" /> Devolver
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Datos de la entrega</CardTitle>
            </CardHeader>
            <CardContent class="grid gap-2 text-sm sm:grid-cols-2">
                <p>
                    <span class="text-muted-foreground">Colaborador:</span>
                    {{ entrega.colaborador?.nombre_completo }} ({{
                        entrega.colaborador?.numero_empleado
                    }})
                </p>
                <p>
                    <span class="text-muted-foreground">Sucursal:</span>
                    {{ entrega.sucursal }}
                </p>
                <p>
                    <span class="text-muted-foreground">Responsable:</span>
                    {{ entrega.encargado }}
                </p>
                <p>
                    <span class="text-muted-foreground"
                        >Almacén de origen:</span
                    >
                    {{ entrega.almacen }}
                </p>
                <p>
                    <span class="text-muted-foreground"
                        >Servicio (al momento de la entrega):</span
                    >
                    <template v-if="entrega.servicio">
                        {{ entrega.servicio.contrato }} —
                        {{ entrega.servicio.nombre }}
                    </template>
                    <span v-else class="text-muted-foreground italic"
                        >Servicio no registrado</span
                    >
                </p>
                <p>
                    <span class="text-muted-foreground">Fecha de entrega:</span>
                    {{ entrega.fecha_entrega }}
                </p>
                <p v-if="acuse">
                    <span class="text-muted-foreground">Acuse:</span>
                    {{ acuse.folio }}
                </p>
                <p v-if="entrega.notas" class="sm:col-span-2">
                    <span class="text-muted-foreground">Notas:</span>
                    {{ entrega.notas }}
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Activos</CardTitle>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-muted-foreground text-left">
                        <tr>
                            <th class="py-1.5">Activo</th>
                            <th class="py-1.5">Talla / unidad</th>
                            <th class="py-1.5">Conjunto</th>
                            <th class="py-1.5 text-right">Cantidad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(it, i) in entrega.items"
                            :key="i"
                            class="border-t"
                        >
                            <td class="py-1.5">{{ it.activo }}</td>
                            <td class="py-1.5">
                                <template v-if="it.unidad_codigo">
                                    <span class="font-mono text-xs">{{
                                        it.unidad_codigo
                                    }}</span>
                                    <Badge
                                        v-if="it.unidad_estado_visible"
                                        variant="outline"
                                        class="ml-1.5 text-xs"
                                        :class="
                                            claseEstadoVisibleUnidad(
                                                it.unidad_estado_visible,
                                            )
                                        "
                                    >
                                        {{ it.unidad_estado_visible_etiqueta }}
                                    </Badge>
                                </template>
                                <span v-else>{{ it.talla ?? '—' }}</span>
                            </td>
                            <td class="text-muted-foreground py-1.5">
                                {{ it.conjunto ?? '—' }}
                            </td>
                            <td class="py-1.5 text-right">{{ it.cantidad }}</td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <Card v-if="entrega.correcciones.length">
            <CardHeader>
                <CardTitle class="text-base">Correcciones</CardTitle>
            </CardHeader>
            <CardContent class="space-y-2 text-sm">
                <div
                    v-for="c in entrega.correcciones"
                    :key="c.id"
                    class="border-b pb-2 last:border-0"
                >
                    <p>{{ c.motivo }}</p>
                    <p class="text-muted-foreground text-xs">
                        {{ c.por }} ·
                        {{ new Date(c.fecha).toLocaleString('es-MX') }}
                    </p>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
