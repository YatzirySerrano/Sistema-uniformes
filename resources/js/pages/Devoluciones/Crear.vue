<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type OpcionEntrega = {
    id: number;
    folio: string;
    colaborador: string | null;
    numero_empleado: string | null;
    empresa: string | null;
    fecha_entrega: string;
};

type OpcionAlmacen = { id: number; nombre: string; codigo: string | null };

type Renglon = {
    detalle_entrega_id: number;
    activo: string;
    talla: string | null;
    cantidad: number;
    pendiente: number | null;
    es_unidad: boolean;
    unidad_codigo: string | null;
    unidad_disponible: boolean;
};

type Entrega = {
    id: number;
    folio: string;
    empresa_id: number;
    empresa: string | null;
    colaborador: string | null;
    almacen_id: number | null;
    almacen: string | null;
    renglones: Renglon[];
};

const props = defineProps<{
    entrega: Entrega | null;
    condiciones: { valor: string; etiqueta: string }[];
    condicionesUnidad: { valor: string; etiqueta: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Devoluciones', href: '/devoluciones' },
            { title: 'Nueva devolución', href: '/devoluciones/crear' },
        ],
    },
});

const hoy = new Date().toISOString().slice(0, 10);

// ------------------------------------------------------------------
// Sin entrega precargada: buscarla primero (siempre se origina de una).
// ------------------------------------------------------------------
async function buscarEntregas(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionEntrega[]> {
    const res = await fetch(`/entregas/buscar?q=${encodeURIComponent(q)}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];
    return (await res.json()).entregas ?? [];
}

function elegirEntrega(o: OpcionEntrega | null): void {
    if (!o) return;
    router.get(
        '/devoluciones/crear',
        { entrega_id: o.id },
        { preserveState: false },
    );
}

// ------------------------------------------------------------------
// Entrega precargada: formulario de renglones
// ------------------------------------------------------------------
type FilaCantidad = {
    detalle_entrega_id: number;
    incluir: boolean;
    cantidad: number;
    condicion: string;
};
type FilaUnidad = {
    detalle_entrega_id: number;
    incluir: boolean;
    condicion: string;
};

const filasCantidad = ref<FilaCantidad[]>(
    (props.entrega?.renglones ?? [])
        .filter((r) => !r.es_unidad && (r.pendiente ?? 0) > 0)
        .map((r) => ({
            detalle_entrega_id: r.detalle_entrega_id,
            incluir: false,
            cantidad: r.pendiente ?? 0,
            condicion: 'reutilizable',
        })),
);

const filasUnidad = ref<FilaUnidad[]>(
    (props.entrega?.renglones ?? [])
        .filter((r) => r.es_unidad && r.unidad_disponible)
        .map((r) => ({
            detalle_entrega_id: r.detalle_entrega_id,
            incluir: false,
            condicion: 'funcionando',
        })),
);

function renglonDe(id: number): Renglon | undefined {
    return props.entrega?.renglones.find((r) => r.detalle_entrega_id === id);
}

const almacenSel = ref<OpcionAlmacen | null>(
    props.entrega?.almacen_id && props.entrega.almacen
        ? {
              id: props.entrega.almacen_id,
              nombre: props.entrega.almacen,
              codigo: null,
          }
        : null,
);

const form = useForm<{
    entrega_uniforme_id: number | '';
    almacen_id: number | null;
    fecha: string;
    motivo: string;
    notas: string;
    activos: {
        detalle_entrega_id: number;
        cantidad: number;
        condicion: string;
    }[];
    unidades: { detalle_entrega_id: number; condicion: string }[];
}>({
    entrega_uniforme_id: props.entrega?.id ?? '',
    almacen_id: props.entrega?.almacen_id ?? null,
    fecha: hoy,
    motivo: '',
    notas: '',
    activos: [],
    unidades: [],
});

async function buscarAlmacenes(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionAlmacen[]> {
    if (!props.entrega) return [];
    const res = await fetch(
        `/almacenes/buscar?empresa_id=${props.entrega.empresa_id}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).almacenes ?? [];
}

function alElegirAlmacen(o: OpcionAlmacen | null): void {
    almacenSel.value = o;
    form.almacen_id = o?.id ?? null;
    form.clearErrors('almacen_id');
}

const hayAlgoIncluido = computed(
    () =>
        filasCantidad.value.some((f) => f.incluir) ||
        filasUnidad.value.some((f) => f.incluir),
);

const erroresLaxos = computed(
    () => form.errors as unknown as Record<string, string>,
);

function enviar(): void {
    form.activos = filasCantidad.value
        .filter((f) => f.incluir)
        .map((f) => ({
            detalle_entrega_id: f.detalle_entrega_id,
            cantidad: f.cantidad,
            condicion: f.condicion,
        }));
    form.unidades = filasUnidad.value
        .filter((f) => f.incluir)
        .map((f) => ({
            detalle_entrega_id: f.detalle_entrega_id,
            condicion: f.condicion,
        }));

    form.post('/devoluciones');
}
</script>

<template>
    <Head title="Nueva devolución" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Registrar devolución"
            descripcion="Toda devolución se origina desde una entrega concreta. Sólo los activos reutilizables reingresan al inventario del almacén destino."
        />

        <template v-if="!entrega">
            <div class="grid gap-1.5">
                <Label for="entrega">Entrega de origen</Label>
                <BuscadorAsync
                    id="entrega"
                    :model-value="null"
                    :buscar="buscarEntregas"
                    :etiqueta="
                        (e) =>
                            `${(e as OpcionEntrega).folio} · ${(e as OpcionEntrega).colaborador ?? ''}`
                    "
                    :descripcion="
                        (e) =>
                            `${(e as OpcionEntrega).empresa ?? ''} · ${(e as OpcionEntrega).fecha_entrega}`
                    "
                    placeholder="Busca por folio, colaborador o número de empleado"
                    placeholder-busqueda="Buscar entrega…"
                    @update:model-value="
                        (v) => elegirEntrega(v as OpcionEntrega | null)
                    "
                />
            </div>
            <Button variant="ghost" as-child class="w-fit">
                <Link href="/devoluciones">Cancelar</Link>
            </Button>
        </template>

        <form v-else class="space-y-6" @submit.prevent="enviar">
            <div
                class="bg-muted/40 grid gap-3 rounded-lg border p-3 text-sm sm:grid-cols-2"
            >
                <p>
                    <span class="text-muted-foreground text-xs">Entrega</span
                    ><br />
                    {{ entrega.folio }} · {{ entrega.colaborador }}
                    <span class="text-muted-foreground">
                        ({{ entrega.empresa }})</span
                    >
                </p>
                <div class="grid gap-1.5">
                    <Label for="almacen">Almacén destino</Label>
                    <BuscadorAsync
                        id="almacen"
                        :model-value="almacenSel"
                        :buscar="buscarAlmacenes"
                        :etiqueta="(a) => (a as OpcionAlmacen).nombre"
                        :descripcion="(a) => (a as OpcionAlmacen).codigo ?? ''"
                        placeholder="Selecciona el almacén destino"
                        placeholder-busqueda="Buscar almacén por nombre"
                        :invalido="!!form.errors.almacen_id"
                        @update:model-value="
                            (v) => alElegirAlmacen(v as OpcionAlmacen | null)
                        "
                    />
                    <InputError :message="form.errors.almacen_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="fecha">Fecha</Label>
                    <Input
                        id="fecha"
                        v-model="form.fecha"
                        type="date"
                        :max="hoy"
                        required
                    />
                    <InputError :message="form.errors.fecha" />
                </div>
            </div>

            <InputError :message="erroresLaxos['items']" />
            <InputError :message="erroresLaxos['negocio']" />

            <section class="space-y-3 rounded-xl border p-4">
                <h2 class="text-sm font-semibold">Renglones de la entrega</h2>

                <p
                    v-if="!filasCantidad.length && !filasUnidad.length"
                    class="text-muted-foreground text-sm"
                >
                    Esta entrega no tiene renglones pendientes de devolución.
                </p>

                <div
                    v-for="fila in filasCantidad"
                    :key="`c-${fila.detalle_entrega_id}`"
                    class="grid grid-cols-1 items-start gap-2 rounded-lg border p-3 sm:grid-cols-[auto_1fr_110px_160px]"
                >
                    <input
                        v-model="fila.incluir"
                        type="checkbox"
                        class="mt-2.5 size-4"
                    />
                    <div class="text-sm">
                        <p class="font-medium">
                            {{ renglonDe(fila.detalle_entrega_id)?.activo }}
                            <span
                                v-if="renglonDe(fila.detalle_entrega_id)?.talla"
                                class="text-muted-foreground"
                            >
                                ·
                                {{
                                    renglonDe(fila.detalle_entrega_id)?.talla
                                }}</span
                            >
                        </p>
                        <p class="text-muted-foreground text-xs">
                            Entregado:
                            {{ renglonDe(fila.detalle_entrega_id)?.cantidad }} ·
                            Pendiente:
                            {{ renglonDe(fila.detalle_entrega_id)?.pendiente }}
                        </p>
                    </div>
                    <div>
                        <Input
                            v-model.number="fila.cantidad"
                            type="number"
                            min="1"
                            :max="
                                renglonDe(fila.detalle_entrega_id)?.pendiente ??
                                undefined
                            "
                            class="h-9"
                            :disabled="!fila.incluir"
                        />
                        <InputError
                            :message="
                                erroresLaxos[
                                    `activos.${filasCantidad.filter((f) => f.incluir).indexOf(fila)}.cantidad`
                                ]
                            "
                        />
                    </div>
                    <SelectSimple
                        v-model="fila.condicion"
                        :opciones="
                            condiciones.map((c) => ({
                                valor: c.valor,
                                etiqueta: c.etiqueta,
                            }))
                        "
                        :disabled="!fila.incluir"
                    />
                </div>

                <div
                    v-for="fila in filasUnidad"
                    :key="`u-${fila.detalle_entrega_id}`"
                    class="grid grid-cols-1 items-start gap-2 rounded-lg border p-3 sm:grid-cols-[auto_1fr_160px]"
                >
                    <input
                        v-model="fila.incluir"
                        type="checkbox"
                        class="mt-2.5 size-4"
                    />
                    <div class="text-sm">
                        <p class="font-medium">
                            {{ renglonDe(fila.detalle_entrega_id)?.activo }}
                        </p>
                        <p class="text-muted-foreground font-mono text-xs">
                            {{
                                renglonDe(fila.detalle_entrega_id)
                                    ?.unidad_codigo
                            }}
                        </p>
                    </div>
                    <SelectSimple
                        v-model="fila.condicion"
                        :opciones="
                            condicionesUnidad.map((c) => ({
                                valor: c.valor,
                                etiqueta: c.etiqueta,
                            }))
                        "
                        :disabled="!fila.incluir"
                    />
                </div>

                <p
                    v-for="r in entrega.renglones.filter(
                        (r) => r.es_unidad && !r.unidad_disponible,
                    )"
                    :key="`nd-${r.detalle_entrega_id}`"
                    class="text-muted-foreground text-xs"
                >
                    {{ r.activo }} ({{ r.unidad_codigo }}) ya no está asignada a
                    este colaborador — no se puede devolver desde aquí.
                </p>
            </section>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="motivo">Motivo (opcional)</Label>
                    <Input id="motivo" v-model="form.motivo" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="notas">Notas (opcional)</Label>
                    <Input id="notas" v-model="form.notas" />
                </div>
            </div>

            <div class="flex items-center gap-3">
                <Button
                    type="submit"
                    :disabled="form.processing || !hayAlgoIncluido"
                >
                    Registrar devolución
                </Button>
                <Button variant="ghost" as-child>
                    <Link href="/devoluciones">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
