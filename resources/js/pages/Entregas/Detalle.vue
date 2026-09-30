<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download, FileSignature, PenLine, Undo2 } from '@lucide/vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { varianteBadgeEstadoEntrega } from '@/lib/estadoEntrega';
import { fechaHora, fechaNegocio } from '@/lib/fecha';
import { varianteBadgeFinalidad } from '@/lib/finalidadCustodia';
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
        /** Redistribución: custodia de la que salieron los bienes. */
        origen_custodia: {
            id: number;
            nombre_completo: string;
            numero_empleado: string;
        } | null;
        sucursal: string;
        encargado: string;
        items: {
            activo: string;
            talla: string | null;
            cantidad: number;
            evidencias: { url: string; mime: string }[];
            unidad_codigo: string | null;
            unidad_estado_visible: string | null;
            unidad_estado_visible_etiqueta: string | null;
            conjunto: string | null;
            finalidad: 'uso_personal' | 'redistribucion' | null;
            finalidad_etiqueta: string;
            recibido_de: {
                entrega_id: number;
                folio: string;
                colaborador: string | null;
            } | null;
            redistribuido_a: {
                entrega_id: number;
                folio: string;
                colaborador: string | null;
                cantidad: number;
                talla: string | null;
                unidad_codigo: string | null;
            }[];
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
        firma_metodo: 'dibujada' | 'archivo';
        firma_archivo: { nombre: string; es_pdf: boolean } | null;
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

// Cadena de custodia visible sólo si algún renglón vino de otra custodia o
// se redistribuyó después (Almacén → custodio → destinatario).
const itemsConTrazabilidad = props.entrega.items.filter(
    (it) => it.recibido_de !== null || it.redistribuido_a.length > 0,
);
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
                <p v-if="entrega.origen_custodia">
                    <span class="text-muted-foreground">Origen:</span>
                    Redistribución desde la custodia de
                    {{ entrega.origen_custodia.nombre_completo }} ({{
                        entrega.origen_custodia.numero_empleado
                    }})
                </p>
                <p v-else>
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
                    {{ fechaNegocio(entrega.fecha_entrega) }}
                </p>
                <p v-if="acuse">
                    <span class="text-muted-foreground"
                        >Fecha y hora de firma:</span
                    >
                    {{ fechaHora(acuse.firmado_en) }}
                </p>
                <p v-if="acuse">
                    <span class="text-muted-foreground">Acuse:</span>
                    {{ acuse.folio }}
                </p>
                <p v-if="acuse">
                    <span class="text-muted-foreground"
                        >Firma de quien recibe:</span
                    >
                    <template v-if="acuse.firma_archivo">
                        archivo subido a distancia ({{
                            acuse.firma_archivo.es_pdf ? 'PDF' : 'imagen'
                        }}: {{ acuse.firma_archivo.nombre }})
                    </template>
                    <template v-else>dibujada en el dispositivo</template>
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
            <CardContent>
                <!-- Móvil: cards apiladas, nunca tabla con scroll horizontal. -->
                <ul class="flex flex-col gap-3 md:hidden">
                    <li
                        v-for="(it, i) in entrega.items"
                        :key="i"
                        class="rounded-lg border p-3 text-sm"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <p class="min-w-0 font-medium break-words">
                                {{ it.activo }}
                            </p>
                            <p class="text-muted-foreground shrink-0 text-xs">
                                Cantidad
                                <span class="text-foreground font-semibold">{{
                                    it.cantidad
                                }}</span>
                            </p>
                        </div>
                        <dl class="mt-2 grid gap-1.5 text-xs">
                            <div
                                v-if="it.unidad_codigo"
                                class="flex items-center gap-1.5"
                            >
                                <dt class="text-muted-foreground">Unidad:</dt>
                                <dd class="flex items-center gap-1.5">
                                    <span class="font-mono">{{
                                        it.unidad_codigo
                                    }}</span>
                                    <Badge
                                        v-if="it.unidad_estado_visible"
                                        variant="outline"
                                        class="text-xs"
                                        :class="
                                            claseEstadoVisibleUnidad(
                                                it.unidad_estado_visible,
                                            )
                                        "
                                    >
                                        {{ it.unidad_estado_visible_etiqueta }}
                                    </Badge>
                                </dd>
                            </div>
                            <div v-else-if="it.talla" class="flex gap-1.5">
                                <dt class="text-muted-foreground">
                                    Talla / variante:
                                </dt>
                                <dd>{{ it.talla }}</dd>
                            </div>
                            <div class="flex gap-1.5">
                                <dt class="text-muted-foreground">Conjunto:</dt>
                                <dd>{{ it.conjunto ?? '—' }}</dd>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <dt class="text-muted-foreground">
                                    Finalidad:
                                </dt>
                                <dd>
                                    <Badge
                                        :variant="
                                            varianteBadgeFinalidad(it.finalidad)
                                        "
                                        class="text-xs"
                                        >{{ it.finalidad_etiqueta }}</Badge
                                    >
                                </dd>
                            </div>
                        </dl>
                        <div
                            v-if="it.evidencias.length"
                            class="mt-2 flex flex-wrap gap-1.5"
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
                                    class="size-12 rounded border object-cover"
                                />
                            </a>
                        </div>
                    </li>
                </ul>

                <!-- Escritorio: tabla. -->
                <!-- md+: tabla con columnas separadas por padding (nunca
                     "ConjuntoFinalidad" pegadas) y scroll horizontal propio
                     si el ancho no alcanza. -->
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead class="text-muted-foreground text-left">
                            <tr
                                class="[&>th]:px-3 [&>th]:py-2 [&>th]:font-medium [&>th]:whitespace-nowrap"
                            >
                                <th>Activo</th>
                                <th>Talla / unidad</th>
                                <th>Conjunto</th>
                                <th>Finalidad</th>
                                <th>Evidencia</th>
                                <th class="text-right">Cantidad</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(it, i) in entrega.items"
                                :key="i"
                                class="border-t align-top [&>td]:px-3 [&>td]:py-2"
                            >
                                <td class="min-w-40">{{ it.activo }}</td>
                                <td class="whitespace-nowrap">
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
                                            {{
                                                it.unidad_estado_visible_etiqueta
                                            }}
                                        </Badge>
                                    </template>
                                    <span v-else>{{ it.talla ?? '—' }}</span>
                                </td>
                                <td class="text-muted-foreground min-w-32">
                                    {{ it.conjunto ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap">
                                    <Badge
                                        :variant="
                                            varianteBadgeFinalidad(it.finalidad)
                                        "
                                        class="text-xs"
                                        >{{ it.finalidad_etiqueta }}</Badge
                                    >
                                </td>
                                <td>
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
                                <td class="text-right tabular-nums">
                                    {{ it.cantidad }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <Card v-if="itemsConTrazabilidad.length">
            <CardHeader>
                <CardTitle class="text-base"
                    >Trazabilidad de custodia</CardTitle
                >
                <p class="text-muted-foreground text-sm">
                    De qué entrega anterior salió cada renglón y a quién se
                    redistribuyó después. Cada paso es una entrega firmada
                    distinta; ninguna modifica a la anterior.
                </p>
            </CardHeader>
            <CardContent>
                <ul class="flex flex-col gap-3 text-sm">
                    <li
                        v-for="(it, i) in itemsConTrazabilidad"
                        :key="i"
                        class="rounded-lg border p-3"
                    >
                        <p class="font-medium">{{ it.activo }}</p>
                        <!-- Código de unidad y variante son conceptos distintos: nunca se muestra el código como si fuera una talla. -->
                        <p
                            v-if="it.unidad_codigo"
                            class="text-muted-foreground text-xs"
                        >
                            Unidad:
                            <span class="text-foreground font-mono">{{
                                it.unidad_codigo
                            }}</span>
                        </p>
                        <p
                            v-else-if="it.talla"
                            class="text-muted-foreground text-xs"
                        >
                            Talla / variante:
                            <span class="text-foreground">{{ it.talla }}</span>
                        </p>
                        <dl v-if="it.recibido_de" class="mt-2 grid gap-0.5">
                            <dt class="text-muted-foreground text-xs">
                                Recibido de la custodia de
                            </dt>
                            <dd>
                                {{ it.recibido_de.colaborador ?? '—' }} ·
                                Entrega
                                <Link
                                    :href="`/entregas/${it.recibido_de.entrega_id}`"
                                    class="underline underline-offset-2"
                                    >{{ it.recibido_de.folio }}</Link
                                >
                            </dd>
                        </dl>
                        <div v-if="it.redistribuido_a.length" class="mt-2">
                            <p class="text-muted-foreground text-xs">
                                Redistribuido después a
                            </p>
                            <ul class="mt-1 flex flex-col gap-1.5">
                                <li
                                    v-for="r in it.redistribuido_a"
                                    :key="`${r.entrega_id}-${r.cantidad}`"
                                    class="bg-muted/40 grid gap-x-4 gap-y-0.5 rounded-md px-2 py-1.5 text-xs sm:grid-cols-3"
                                >
                                    <p>
                                        <span class="text-muted-foreground"
                                            >Destinatario:</span
                                        >
                                        {{ r.colaborador ?? '—' }}
                                    </p>
                                    <p>
                                        <span class="text-muted-foreground">{{
                                            it.unidad_codigo
                                                ? 'Cantidad:'
                                                : 'Cantidad entregada:'
                                        }}</span>
                                        {{ r.cantidad }}
                                        <template v-if="it.unidad_codigo">{{
                                            r.cantidad === 1
                                                ? 'unidad'
                                                : 'unidades'
                                        }}</template>
                                    </p>
                                    <p>
                                        <span class="text-muted-foreground"
                                            >Entrega:</span
                                        >
                                        <Link
                                            :href="`/entregas/${r.entrega_id}`"
                                            class="underline underline-offset-2"
                                            >{{ r.folio }}</Link
                                        >
                                    </p>
                                </li>
                            </ul>
                        </div>
                    </li>
                </ul>
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
                        {{ fechaHora(c.fecha) }}
                    </p>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
