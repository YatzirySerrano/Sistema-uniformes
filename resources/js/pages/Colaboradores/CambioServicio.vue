<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowRight, CheckCircle2, CircleAlert, Clock } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { fechaHora } from '@/lib/fecha';

type Destinatario = {
    id: number;
    nombre_completo: string;
    numero_empleado: string;
};

type Operacion = {
    tipo: 'devolucion' | 'redistribucion';
    folio: string;
    id: number;
    detalle: string;
};

type Renglon = {
    id: number;
    finalidad_etiqueta: string;
    es_unidad: boolean;
    activo: string;
    talla: string | null;
    codigo: string | null;
    cantidad_revisada: number;
    solo_mantener: boolean;
    mantener: number;
    devolver: number;
    redistribuir: number;
    destinatario: Destinatario | null;
    estado: 'sin_decision' | 'pendiente' | 'resuelto' | 'no_coincide';
    explicacion: string;
    faltante_devolver: number;
    faltante_redistribuir: number;
    devolucion_pendiente_firma: boolean;
    entrega_id: number | null;
    operaciones: Operacion[];
};

/**
 * Revisión de custodia de un cambio de servicio: por cada bien se decide si
 * se MANTIENE con el colaborador, se DEVUELVE al almacén o se REDISTRIBUYE a
 * otro colaborador. Las devoluciones y redistribuciones se registran (y
 * firman) con sus flujos reales; esta pantalla muestra si cada bien ya quedó
 * resuelto — lo calcula el backend a partir de las operaciones reales — y
 * sólo deja completar el cambio cuando todo coincide.
 */
const props = defineProps<{
    cambio: {
        id: number;
        estado: 'pendiente' | 'completado' | 'cancelado';
        motivo: string | null;
        colaborador: {
            id: number;
            nombre_completo: string;
            numero_empleado: string;
            empresa_id: number;
            empresa: string | null;
            sucursal: string | null;
        };
        servicio_origen: string;
        servicio_destino: string;
        iniciado_por: string | null;
        iniciado_en: string | null;
        completado_por: string | null;
        completado_en: string | null;
    };
    renglones: Renglon[];
    nuevos: {
        activo: string;
        talla: string | null;
        referencia: string | null;
        cantidad: number;
    }[];
    completable: boolean;
    permisos: { gestionar: boolean; devolver: boolean; redistribuir: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Colaboradores', href: '/colaboradores' },
            { title: 'Cambio de servicio', href: '#' },
        ],
    },
});

const editable = computed(
    () => props.permisos.gestionar && props.cambio.estado === 'pendiente',
);

type Decision = 'mantener' | 'devolver' | 'redistribuir';

// Borrador local de las decisiones: parte siempre de lo guardado.
const borrador = reactive<
    Record<
        number,
        {
            mantener: number;
            devolver: number;
            redistribuir: number;
            destinatario: Destinatario | null;
        }
    >
>(
    Object.fromEntries(
        props.renglones.map((r) => [
            r.id,
            {
                mantener: r.mantener,
                devolver: r.devolver,
                redistribuir: r.redistribuir,
                destinatario: r.destinatario,
            },
        ]),
    ),
);

function decisionUnidad(id: number): Decision | null {
    const b = borrador[id];
    if (b.mantener === 1) return 'mantener';
    if (b.devolver === 1) return 'devolver';
    if (b.redistribuir === 1) return 'redistribuir';
    return null;
}

function elegirDecisionUnidad(id: number, valor: string | number | null): void {
    const b = borrador[id];
    b.mantener = valor === 'mantener' ? 1 : 0;
    b.devolver = valor === 'devolver' ? 1 : 0;
    b.redistribuir = valor === 'redistribuir' ? 1 : 0;
    if (valor !== 'redistribuir') b.destinatario = null;
}

function opcionesUnidad(r: Renglon) {
    return [
        { valor: 'mantener', etiqueta: 'Mantener con el colaborador' },
        {
            valor: 'devolver',
            etiqueta: 'Devolver al almacén',
            disabled: r.solo_mantener,
        },
        {
            valor: 'redistribuir',
            etiqueta: 'Redistribuir a otro colaborador',
            disabled: r.solo_mantener,
        },
    ];
}

function sumaDe(id: number): number {
    const b = borrador[id];
    return (
        (Number(b.mantener) || 0) +
        (Number(b.devolver) || 0) +
        (Number(b.redistribuir) || 0)
    );
}

function mantenerTodos(): void {
    for (const r of props.renglones) {
        borrador[r.id].mantener = r.cantidad_revisada;
        borrador[r.id].devolver = 0;
        borrador[r.id].redistribuir = 0;
        borrador[r.id].destinatario = null;
    }
}

// Vista previa de lo que acompañará al colaborador con las decisiones
// actuales del borrador (lo que el usuario está autorizando).
const acompanan = computed(() =>
    props.renglones
        .filter((r) => (Number(borrador[r.id].mantener) || 0) > 0)
        .map((r) =>
            r.es_unidad
                ? `${r.activo} ${r.codigo ?? ''}`
                : `${r.activo}${r.talla ? ` talla ${r.talla}` : ''} x${borrador[r.id].mantener}`,
        ),
);

async function buscarDestinatarios(
    q: string,
    signal?: AbortSignal,
): Promise<Destinatario[]> {
    const res = await fetch(
        `/colaboradores/buscar?empresa_id=${props.cambio.colaborador.empresa_id}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    const lista = ((await res.json()).colaboradores ?? []) as Destinatario[];
    return lista.filter((c) => c.id !== props.cambio.colaborador.id);
}

const form = useForm<{
    renglones: Record<
        number,
        {
            mantener: number;
            devolver: number;
            redistribuir: number;
            destinatario_id: number | null;
        }
    >;
}>({ renglones: {} });

const errores = computed(
    () => form.errors as unknown as Record<string, string | undefined>,
);

function guardar(): void {
    form.renglones = Object.fromEntries(
        props.renglones.map((r) => [
            r.id,
            {
                mantener: Number(borrador[r.id].mantener) || 0,
                devolver: Number(borrador[r.id].devolver) || 0,
                redistribuir: Number(borrador[r.id].redistribuir) || 0,
                destinatario_id: borrador[r.id].destinatario?.id ?? null,
            },
        ]),
    );
    form.put(`/cambios-servicio/${props.cambio.id}/decisiones`, {
        preserveScroll: true,
    });
}

const procesando = ref(false);
const confirmarCancelacion = ref(false);

function accion(ruta: 'actualizar' | 'completar' | 'cancelar'): void {
    router.post(
        `/cambios-servicio/${props.cambio.id}/${ruta}`,
        {},
        {
            preserveScroll: true,
            onStart: () => (procesando.value = true),
            onFinish: () => (procesando.value = false),
        },
    );
}

const etiquetaEstado: Record<Renglon['estado'], string> = {
    sin_decision: 'Sin decisión',
    pendiente: 'Pendiente',
    resuelto: 'Resuelto',
    no_coincide: 'No coincide',
};

function varianteEstado(e: Renglon['estado']) {
    if (e === 'resuelto') return 'success' as const;
    if (e === 'no_coincide') return 'destructive' as const;
    if (e === 'pendiente') return 'warning' as const;
    return 'secondary' as const;
}
</script>

<template>
    <Head
        :title="`Cambio de servicio · ${cambio.colaborador.nombre_completo}`"
    />

    <div class="flex w-full flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Cambio de servicio"
            descripcion="Antes de cambiar el servicio, decide qué pasa con cada bien bajo custodia: se mantiene con el colaborador, se devuelve al almacén o se entrega a quien quede como responsable."
        />

        <section class="rounded-xl border p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="min-w-0">
                    <Link
                        :href="`/colaboradores/${cambio.colaborador.id}`"
                        class="font-medium underline-offset-2 hover:underline"
                    >
                        {{ cambio.colaborador.nombre_completo }}
                    </Link>
                    <p class="text-muted-foreground text-xs">
                        N.º {{ cambio.colaborador.numero_empleado }} ·
                        {{ cambio.colaborador.empresa ?? '—' }} ·
                        {{ cambio.colaborador.sucursal ?? '—' }}
                    </p>
                </div>
                <Badge
                    :variant="
                        cambio.estado === 'completado'
                            ? 'success'
                            : cambio.estado === 'cancelado'
                              ? 'secondary'
                              : 'warning'
                    "
                >
                    {{
                        cambio.estado === 'completado'
                            ? 'Completado'
                            : cambio.estado === 'cancelado'
                              ? 'Cancelado'
                              : 'En revisión'
                    }}
                </Badge>
            </div>
            <p class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                <span>{{ cambio.servicio_origen }}</span>
                <ArrowRight class="text-muted-foreground size-4" />
                <span class="font-medium">{{ cambio.servicio_destino }}</span>
            </p>
            <p v-if="cambio.motivo" class="text-muted-foreground mt-1 text-sm">
                Motivo: {{ cambio.motivo }}
            </p>
            <p class="text-muted-foreground mt-1 text-xs">
                Iniciado por {{ cambio.iniciado_por ?? '—' }}
                <template v-if="cambio.iniciado_en">
                    · {{ fechaHora(cambio.iniciado_en) }}</template
                >
                <template v-if="cambio.completado_en">
                    · Completado por {{ cambio.completado_por ?? '—' }} ·
                    {{ fechaHora(cambio.completado_en) }}</template
                >
            </p>
        </section>

        <div
            v-if="nuevos.length && cambio.estado === 'pendiente'"
            class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400"
            role="status"
        >
            <p class="font-medium">
                El colaborador recibió bienes después de iniciar la revisión:
            </p>
            <ul class="mt-1 list-disc pl-5">
                <li v-for="(n, i) in nuevos" :key="i">
                    {{ n.activo }}{{ n.talla ? ` talla ${n.talla}` : '' }}
                    {{ n.referencia ? n.referencia : `x${n.cantidad}` }}
                </li>
            </ul>
            <Button
                v-if="editable"
                variant="outline"
                size="sm"
                class="mt-2"
                :disabled="procesando"
                @click="accion('actualizar')"
            >
                Agregar a la revisión
            </Button>
        </div>

        <section class="space-y-3 rounded-xl border p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">Bienes bajo custodia</h2>
                <Button
                    v-if="editable"
                    variant="outline"
                    size="sm"
                    @click="mantenerTodos"
                >
                    Mantener todos
                </Button>
            </div>
            <InputError :message="errores['negocio']" />

            <article
                v-for="r in renglones"
                :key="r.id"
                class="space-y-3 rounded-lg border p-3"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-medium">
                            {{ r.activo }}
                            <span
                                v-if="r.codigo"
                                class="text-muted-foreground font-mono text-xs"
                                >{{ r.codigo }}</span
                            >
                        </p>
                        <p class="text-muted-foreground text-xs">
                            <Badge variant="outline" class="mr-1 text-xs">{{
                                r.finalidad_etiqueta
                            }}</Badge>
                            {{
                                r.es_unidad
                                    ? 'Unidad identificada'
                                    : `${r.talla ? `Talla ${r.talla} · ` : ''}Cantidad revisada: ${r.cantidad_revisada}`
                            }}
                        </p>
                    </div>
                    <Badge :variant="varianteEstado(r.estado)">
                        <CheckCircle2
                            v-if="r.estado === 'resuelto'"
                            class="size-3"
                        />
                        <Clock
                            v-else-if="r.estado === 'pendiente'"
                            class="size-3"
                        />
                        <CircleAlert v-else class="size-3" />
                        {{ etiquetaEstado[r.estado] }}
                    </Badge>
                </div>

                <!-- Decisión -->
                <div v-if="editable" class="grid gap-2">
                    <div v-if="r.es_unidad" class="sm:max-w-sm">
                        <SelectSimple
                            :id="`decision-${r.id}`"
                            :model-value="decisionUnidad(r.id)"
                            :opciones="opcionesUnidad(r)"
                            placeholder="Elige qué pasa con esta unidad"
                            @update:model-value="
                                (v) => elegirDecisionUnidad(r.id, v)
                            "
                        />
                        <p
                            v-if="r.solo_mantener"
                            class="text-muted-foreground mt-1 text-xs"
                        >
                            Reportada como dañada, perdida o robada: sólo puede
                            mantenerse con el colaborador hasta resolverse.
                        </p>
                    </div>
                    <div v-else class="grid grid-cols-3 gap-2 sm:max-w-md">
                        <label class="grid gap-1 text-xs">
                            Mantener
                            <Input
                                v-model.number="borrador[r.id].mantener"
                                type="number"
                                min="0"
                                :max="r.cantidad_revisada"
                                class="h-9"
                            />
                        </label>
                        <label class="grid gap-1 text-xs">
                            Devolver
                            <Input
                                v-model.number="borrador[r.id].devolver"
                                type="number"
                                min="0"
                                :max="r.cantidad_revisada"
                                class="h-9"
                            />
                        </label>
                        <label class="grid gap-1 text-xs">
                            Redistribuir
                            <Input
                                v-model.number="borrador[r.id].redistribuir"
                                type="number"
                                min="0"
                                :max="r.cantidad_revisada"
                                class="h-9"
                            />
                        </label>
                        <p
                            class="col-span-3 text-xs"
                            :class="
                                sumaDe(r.id) === r.cantidad_revisada
                                    ? 'text-muted-foreground'
                                    : 'text-destructive'
                            "
                        >
                            Repartidas {{ sumaDe(r.id) }} de
                            {{ r.cantidad_revisada }}.
                        </p>
                    </div>
                    <InputError
                        :message="errores[`renglones.${r.id}.mantener`]"
                    />

                    <div
                        v-if="(Number(borrador[r.id].redistribuir) || 0) > 0"
                        class="sm:max-w-md"
                    >
                        <BuscadorAsync
                            v-model="borrador[r.id].destinatario"
                            :buscar="buscarDestinatarios"
                            :etiqueta="
                                (c) => (c as Destinatario).nombre_completo
                            "
                            :descripcion="
                                (c) =>
                                    `N.º ${(c as Destinatario).numero_empleado}`
                            "
                            placeholder="¿A quién se entrega?"
                            placeholder-busqueda="Buscar colaborador"
                            :invalido="
                                !!errores[`renglones.${r.id}.destinatario_id`]
                            "
                        />
                        <InputError
                            :message="
                                errores[`renglones.${r.id}.destinatario_id`]
                            "
                        />
                    </div>
                </div>
                <p v-else class="text-sm">
                    <template v-if="r.mantener"
                        >Mantener: {{ r.es_unidad ? 'Sí' : r.mantener }}.
                    </template>
                    <template v-if="r.devolver"
                        >Devolver: {{ r.es_unidad ? 'Sí' : r.devolver }}.
                    </template>
                    <template v-if="r.redistribuir"
                        >Redistribuir{{
                            r.es_unidad ? '' : `: ${r.redistribuir}`
                        }}
                        a {{ r.destinatario?.nombre_completo ?? '—' }}.
                    </template>
                </p>

                <!-- Estado real y qué falta -->
                <p class="text-muted-foreground text-xs">{{ r.explicacion }}</p>

                <div
                    v-if="
                        cambio.estado === 'pendiente' &&
                        r.estado === 'pendiente'
                    "
                    class="flex flex-wrap gap-2 text-sm"
                >
                    <template
                        v-if="
                            r.faltante_devolver > 0 &&
                            !r.devolucion_pendiente_firma
                        "
                    >
                        <Button
                            v-if="permisos.devolver && r.entrega_id"
                            as-child
                            variant="outline"
                            size="sm"
                        >
                            <Link
                                :href="`/devoluciones/crear?entrega_id=${r.entrega_id}`"
                                >Registrar devolución</Link
                            >
                        </Button>
                        <p
                            v-else-if="!permisos.devolver"
                            class="text-amber-700 dark:text-amber-400"
                        >
                            Este bien debe devolverse antes de completar el
                            cambio, pero tu usuario no tiene permiso para
                            registrar devoluciones.
                        </p>
                    </template>
                    <template
                        v-if="r.faltante_redistribuir > 0 && r.destinatario"
                    >
                        <Button
                            v-if="permisos.redistribuir"
                            as-child
                            variant="outline"
                            size="sm"
                        >
                            <Link
                                :href="`/entregas/crear?cambio_servicio=${cambio.id}&destinatario=${r.destinatario.id}`"
                                >Registrar redistribución a
                                {{ r.destinatario.nombre_completo }}</Link
                            >
                        </Button>
                        <p v-else class="text-amber-700 dark:text-amber-400">
                            Este bien debe redistribuirse antes de completar el
                            cambio, pero tu usuario no tiene permiso para
                            redistribuir custodia.
                        </p>
                    </template>
                </div>

                <ul
                    v-if="r.operaciones.length"
                    class="text-muted-foreground space-y-0.5 text-xs"
                >
                    <li
                        v-for="op in r.operaciones"
                        :key="`${op.tipo}-${op.id}`"
                    >
                        {{
                            op.tipo === 'devolucion'
                                ? 'Devolución'
                                : 'Redistribución'
                        }}
                        <Link
                            :href="
                                op.tipo === 'devolucion'
                                    ? `/devoluciones/${op.id}`
                                    : `/entregas/${op.id}`
                            "
                            class="underline underline-offset-2"
                            >{{ op.folio }}</Link
                        >
                        · {{ op.detalle }}
                    </li>
                </ul>
            </article>

            <div v-if="editable" class="flex flex-wrap gap-2">
                <Button :disabled="form.processing" @click="guardar">
                    Guardar decisiones
                </Button>
                <p class="text-muted-foreground self-center text-xs">
                    Guarda antes de registrar devoluciones o redistribuciones.
                </p>
            </div>
        </section>

        <section
            v-if="cambio.estado === 'pendiente'"
            class="space-y-3 rounded-xl border p-4"
        >
            <h2 class="text-sm font-semibold">
                Acompañarán al colaborador a su nuevo servicio
            </h2>
            <ul v-if="acompanan.length" class="list-disc pl-5 text-sm">
                <li v-for="(a, i) in acompanan" :key="i">{{ a }}</li>
            </ul>
            <p v-else class="text-muted-foreground text-sm">
                Ningún bien (según las decisiones actuales).
            </p>

            <div v-if="editable" class="flex flex-wrap gap-2">
                <Button
                    :disabled="!completable || procesando"
                    @click="accion('completar')"
                >
                    Completar cambio de servicio
                </Button>
                <template v-if="!confirmarCancelacion">
                    <Button
                        variant="ghost"
                        :disabled="procesando"
                        @click="confirmarCancelacion = true"
                    >
                        Cancelar revisión
                    </Button>
                </template>
                <template v-else>
                    <Button
                        variant="destructive"
                        :disabled="procesando"
                        @click="accion('cancelar')"
                    >
                        Sí, cancelar
                    </Button>
                    <Button
                        variant="ghost"
                        :disabled="procesando"
                        @click="confirmarCancelacion = false"
                    >
                        No
                    </Button>
                </template>
            </div>
            <p v-if="!completable" class="text-muted-foreground text-xs">
                Se podrá completar cuando todos los bienes estén en «Resuelto»:
                decisiones guardadas, devoluciones confirmadas y
                redistribuciones firmadas.
            </p>
        </section>
    </div>
</template>
