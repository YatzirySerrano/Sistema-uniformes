<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type OpcionColaborador = {
    id: number;
    nombre_completo: string;
    numero_empleado: string;
    empresa_id: number;
    empresa: string | null;
    sucursal_id: number;
    sucursal: string | null;
};

type OpcionAlmacen = { id: number; nombre: string; codigo: string | null };

type OpcionActivo = {
    id: number;
    nombre: string;
    codigo: string | null;
    tipo: string | null;
    categoria: string | null;
    control: 'cantidad' | 'individual';
    usa_variantes: boolean;
    tallas: { id: number; valor: string }[];
};

type OpcionUnidad = { id: number; codigo: string };

type ComponenteVarianteLibre = {
    componente_id: number;
    activo_id: number;
    activo_nombre: string | null;
    tallas: { id: number; valor: string }[];
};

type OpcionConjunto = {
    id: number;
    nombre: string;
    codigo: string | null;
    componentes_variante_libre: ComponenteVarianteLibre[];
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Entregas', href: '/entregas' },
            { title: 'Nueva entrega', href: '/entregas/crear' },
        ],
    },
});

const hoy = new Date().toISOString().slice(0, 10);

// ------------------------------------------------------------------
// Colaborador → empresa/sucursal derivadas, almacén de origen explícito
// ------------------------------------------------------------------
const colaboradorSel = ref<OpcionColaborador | null>(null);
const almacenSel = ref<OpcionAlmacen | null>(null);
const empresaId = computed(() => colaboradorSel.value?.empresa_id ?? null);

async function buscarColaboradores(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionColaborador[]> {
    const res = await fetch(
        `/colaboradores/buscar?q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).colaboradores ?? [];
}

async function buscarAlmacenes(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionAlmacen[]> {
    if (empresaId.value === null) return [];
    const res = await fetch(
        `/almacenes/buscar?empresa_id=${empresaId.value}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).almacenes ?? [];
}

function alElegirColaborador(o: OpcionColaborador | null): void {
    colaboradorSel.value = o;
    almacenSel.value = null;
    form.colaborador_id = o?.id ?? '';
    form.almacen_id = null;
    form.clearErrors('colaborador_id');
}

const disponibilidad = ref<Record<string, number>>({});

async function cargarDisponibilidad(): Promise<void> {
    disponibilidad.value = {};
    if (empresaId.value === null || !almacenSel.value) return;
    const res = await fetch(
        `/entregas/disponibilidad?empresa_id=${empresaId.value}&almacen_id=${almacenSel.value.id}`,
        { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
    );
    if (!res.ok) return;
    const json = (await res.json()) as {
        saldos: {
            activo_id: number;
            talla_id: number | null;
            disponible: number;
        }[];
    };
    const mapa: Record<string, number> = {};
    for (const s of json.saldos) {
        mapa[`${s.activo_id}-${s.talla_id ?? '0'}`] = s.disponible;
    }
    disponibilidad.value = mapa;
}

function alElegirAlmacen(o: OpcionAlmacen | null): void {
    almacenSel.value = o;
    form.almacen_id = o?.id ?? null;
    form.clearErrors('almacen_id');
    void cargarDisponibilidad();
}

// ------------------------------------------------------------------
// Formulario
// ------------------------------------------------------------------
type FilaActivo = {
    activo_id: number | '';
    talla_id: number | null;
    cantidad: number;
};
type FilaUnidad = { activo_id: number | ''; unidad_activo_id: number | '' };
type FilaConjunto = {
    conjunto_id: number | '';
    cantidad: number;
    variantes: Record<number, number | null>;
};

const form = useForm<{
    colaborador_id: number | '';
    almacen_id: number | null;
    fecha_entrega: string;
    notas: string;
    activos: FilaActivo[];
    unidades: FilaUnidad[];
    conjuntos: FilaConjunto[];
}>({
    colaborador_id: '',
    almacen_id: null,
    fecha_entrega: hoy,
    notas: '',
    activos: [],
    unidades: [],
    conjuntos: [],
});

/** Acceso laxo a errores anidados (`activos.0.talla_id`, `unidades.0.unidad_activo_id`…). */
const erroresLaxos = computed(
    () => form.errors as unknown as Record<string, string>,
);

// --- Activos sueltos (por cantidad) --------------------------------
const activosUI = reactive<{ sel: OpcionActivo | null }[]>([]);

async function buscarActivosCantidad(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionActivo[]> {
    if (empresaId.value === null) return [];
    const res = await fetch(
        `/activos/buscar?empresa_id=${empresaId.value}&control=cantidad&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).activos ?? [];
}

function agregarActivo(): void {
    form.activos.push({ activo_id: '', talla_id: null, cantidad: 1 });
    activosUI.push({ sel: null });
}

function quitarActivo(i: number): void {
    form.activos.splice(i, 1);
    activosUI.splice(i, 1);
}

function alElegirActivo(i: number, o: OpcionActivo | null): void {
    activosUI[i].sel = o;
    form.activos[i].activo_id = o?.id ?? '';
    form.activos[i].talla_id = null;
    form.clearErrors(`activos.${i}.activo_id`, `activos.${i}.talla_id`);
}

function disponibleDe(
    activoId: number | '',
    tallaId: number | null,
): number | null {
    if (!activoId) return null;
    const clave = `${activoId}-${tallaId ?? '0'}`;
    return clave in disponibilidad.value ? disponibilidad.value[clave] : null;
}

// --- Unidades de seguimiento individual -----------------------------
const unidadesUI = reactive<
    { activoSel: OpcionActivo | null; unidadSel: OpcionUnidad | null }[]
>([]);

async function buscarActivosIndividual(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionActivo[]> {
    if (empresaId.value === null) return [];
    const res = await fetch(
        `/activos/buscar?empresa_id=${empresaId.value}&control=individual&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).activos ?? [];
}

function buscarUnidades(i: number) {
    return async (q: string, signal?: AbortSignal): Promise<OpcionUnidad[]> => {
        const activoId = unidadesUI[i].activoSel?.id;
        if (!activoId || !almacenSel.value) return [];
        const res = await fetch(
            `/activos/unidades/buscar?activo_id=${activoId}&almacen_id=${almacenSel.value.id}&q=${encodeURIComponent(q)}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal,
            },
        );
        if (!res.ok) return [];
        return (await res.json()).unidades ?? [];
    };
}

function agregarUnidad(): void {
    form.unidades.push({ activo_id: '', unidad_activo_id: '' });
    unidadesUI.push({ activoSel: null, unidadSel: null });
}

function quitarUnidad(i: number): void {
    form.unidades.splice(i, 1);
    unidadesUI.splice(i, 1);
}

function alElegirActivoUnidad(i: number, o: OpcionActivo | null): void {
    unidadesUI[i].activoSel = o;
    unidadesUI[i].unidadSel = null;
    form.unidades[i].activo_id = o?.id ?? '';
    form.unidades[i].unidad_activo_id = '';
    form.clearErrors(`unidades.${i}.unidad_activo_id`);
}

function alElegirUnidad(i: number, o: OpcionUnidad | null): void {
    unidadesUI[i].unidadSel = o;
    form.unidades[i].unidad_activo_id = o?.id ?? '';
    form.clearErrors(`unidades.${i}.unidad_activo_id`);
}

// --- Conjuntos --------------------------------------------------------
const conjuntosUI = reactive<{ sel: OpcionConjunto | null }[]>([]);

async function buscarConjuntos(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionConjunto[]> {
    if (empresaId.value === null) return [];
    const res = await fetch(
        `/conjuntos/buscar?empresa_id=${empresaId.value}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).conjuntos ?? [];
}

function agregarConjunto(): void {
    form.conjuntos.push({ conjunto_id: '', cantidad: 1, variantes: {} });
    conjuntosUI.push({ sel: null });
}

function quitarConjunto(i: number): void {
    form.conjuntos.splice(i, 1);
    conjuntosUI.splice(i, 1);
}

function alElegirConjunto(i: number, o: OpcionConjunto | null): void {
    conjuntosUI[i].sel = o;
    form.conjuntos[i].conjunto_id = o?.id ?? '';
    form.conjuntos[i].variantes = {};
    form.clearErrors(`conjuntos.${i}.conjunto_id`);
}

function enviar(): void {
    form.post('/entregas');
}
</script>

<template>
    <Head title="Nueva entrega" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Registrar entrega"
            descripcion="La empresa y la sucursal se toman del colaborador; el almacén de origen se elige explícitamente. Combina, en cualquier mezcla, activos sueltos, unidades identificadas y conjuntos."
        />

        <form class="space-y-6" @submit.prevent="enviar">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="colaborador">Colaborador</Label>
                    <BuscadorAsync
                        id="colaborador"
                        :model-value="colaboradorSel"
                        :buscar="buscarColaboradores"
                        :etiqueta="
                            (c) => (c as OpcionColaborador).nombre_completo
                        "
                        :descripcion="
                            (c) =>
                                `${(c as OpcionColaborador).numero_empleado} · ${(c as OpcionColaborador).empresa ?? ''}`
                        "
                        placeholder="Selecciona un colaborador"
                        placeholder-busqueda="Buscar por nombre o número de empleado"
                        :invalido="!!form.errors.colaborador_id"
                        @update:model-value="
                            (v) =>
                                alElegirColaborador(
                                    v as OpcionColaborador | null,
                                )
                        "
                    />
                    <InputError :message="form.errors.colaborador_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="fecha_entrega">Fecha de entrega</Label>
                    <Input
                        id="fecha_entrega"
                        v-model="form.fecha_entrega"
                        type="date"
                        :max="hoy"
                        required
                    />
                    <InputError :message="form.errors.fecha_entrega" />
                </div>
            </div>

            <div
                v-if="colaboradorSel"
                class="bg-muted/40 grid gap-3 rounded-lg border p-3 text-sm sm:grid-cols-2"
            >
                <p>
                    <span class="text-muted-foreground text-xs">Empresa</span
                    ><br />{{ colaboradorSel.empresa }}
                    <span class="text-muted-foreground"> · Sucursal: </span
                    >{{ colaboradorSel.sucursal }}
                </p>
                <div class="grid gap-1.5">
                    <Label for="almacen">Almacén de origen</Label>
                    <BuscadorAsync
                        id="almacen"
                        :model-value="almacenSel"
                        :buscar="buscarAlmacenes"
                        :dependencia="empresaId"
                        :etiqueta="(a) => (a as OpcionAlmacen).nombre"
                        :descripcion="(a) => (a as OpcionAlmacen).codigo ?? ''"
                        placeholder="Selecciona el almacén"
                        placeholder-busqueda="Buscar almacén por nombre"
                        sin-resultados="Ningún almacén abastece a esta empresa."
                        :invalido="!!form.errors.almacen_id"
                        @update:model-value="
                            (v) => alElegirAlmacen(v as OpcionAlmacen | null)
                        "
                    />
                    <InputError :message="form.errors.almacen_id" />
                </div>
            </div>

            <InputError :message="erroresLaxos['items']" />

            <!-- Activos sueltos -->
            <section class="space-y-3 rounded-xl border p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Activos por cantidad</h2>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="!colaboradorSel"
                        @click="agregarActivo"
                    >
                        <Plus class="size-4" /> Agregar activo
                    </Button>
                </div>

                <div
                    v-for="(fila, i) in form.activos"
                    :key="i"
                    class="grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_140px_110px_auto] sm:items-start"
                >
                    <div>
                        <BuscadorAsync
                            :model-value="activosUI[i].sel"
                            :buscar="buscarActivosCantidad"
                            :dependencia="empresaId"
                            :etiqueta="(a) => (a as OpcionActivo).nombre"
                            :descripcion="
                                (a) => (a as OpcionActivo).codigo ?? ''
                            "
                            placeholder="Buscar activo…"
                            placeholder-busqueda="Buscar por nombre o código"
                            :invalido="!!erroresLaxos[`activos.${i}.activo_id`]"
                            @update:model-value="
                                (v) =>
                                    alElegirActivo(i, v as OpcionActivo | null)
                            "
                        />
                        <InputError
                            :message="erroresLaxos[`activos.${i}.activo_id`]"
                        />
                    </div>
                    <div v-if="activosUI[i].sel?.usa_variantes">
                        <select
                            v-model="fila.talla_id"
                            class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                        >
                            <option :value="null" disabled>Variante</option>
                            <option
                                v-for="t in activosUI[i].sel?.tallas ?? []"
                                :key="t.id"
                                :value="t.id"
                            >
                                {{ t.valor }}
                            </option>
                        </select>
                        <InputError
                            :message="erroresLaxos[`activos.${i}.talla_id`]"
                        />
                    </div>
                    <div>
                        <Input
                            v-model.number="fila.cantidad"
                            type="number"
                            min="1"
                            class="h-9"
                        />
                        <p
                            v-if="
                                disponibleDe(fila.activo_id, fila.talla_id) !==
                                null
                            "
                            class="text-muted-foreground mt-0.5 text-[11px]"
                            :class="
                                (disponibleDe(fila.activo_id, fila.talla_id) ??
                                    0) < fila.cantidad
                                    ? 'text-destructive'
                                    : ''
                            "
                        >
                            Disp.:
                            {{ disponibleDe(fila.activo_id, fila.talla_id) }}
                        </p>
                        <InputError
                            :message="erroresLaxos[`activos.${i}.cantidad`]"
                        />
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        @click="quitarActivo(i)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
                <p
                    v-if="!form.activos.length"
                    class="text-muted-foreground text-sm"
                >
                    Sin activos por cantidad agregados.
                </p>
            </section>

            <!-- Unidades de seguimiento individual -->
            <section class="space-y-3 rounded-xl border p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">
                        Unidades identificadas
                    </h2>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="!colaboradorSel || !almacenSel"
                        @click="agregarUnidad"
                    >
                        <Plus class="size-4" /> Agregar unidad
                    </Button>
                </div>

                <div
                    v-for="(fila, i) in form.unidades"
                    :key="i"
                    class="grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-start"
                >
                    <div>
                        <BuscadorAsync
                            :model-value="unidadesUI[i].activoSel"
                            :buscar="buscarActivosIndividual"
                            :dependencia="empresaId"
                            :etiqueta="(a) => (a as OpcionActivo).nombre"
                            :descripcion="
                                (a) => (a as OpcionActivo).codigo ?? ''
                            "
                            placeholder="Activo…"
                            placeholder-busqueda="Buscar por nombre o código"
                            @update:model-value="
                                (v) =>
                                    alElegirActivoUnidad(
                                        i,
                                        v as OpcionActivo | null,
                                    )
                            "
                        />
                    </div>
                    <div>
                        <BuscadorAsync
                            :model-value="unidadesUI[i].unidadSel"
                            :buscar="buscarUnidades(i)"
                            :dependencia="`${unidadesUI[i].activoSel?.id ?? ''}-${almacenSel?.id ?? ''}`"
                            :disabled="!unidadesUI[i].activoSel"
                            :etiqueta="(u) => (u as OpcionUnidad).codigo"
                            placeholder="Unidad (código)…"
                            placeholder-busqueda="Buscar por código"
                            sin-resultados="Sin unidades disponibles de este activo en el almacén."
                            :invalido="
                                !!erroresLaxos[`unidades.${i}.unidad_activo_id`]
                            "
                            @update:model-value="
                                (v) =>
                                    alElegirUnidad(i, v as OpcionUnidad | null)
                            "
                        />
                        <InputError
                            :message="
                                erroresLaxos[`unidades.${i}.unidad_activo_id`]
                            "
                        />
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        @click="quitarUnidad(i)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
                <p
                    v-if="!form.unidades.length"
                    class="text-muted-foreground text-sm"
                >
                    Sin unidades identificadas agregadas.
                </p>
            </section>

            <!-- Conjuntos -->
            <section class="space-y-3 rounded-xl border p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Conjuntos</h2>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="!colaboradorSel"
                        @click="agregarConjunto"
                    >
                        <Plus class="size-4" /> Agregar conjunto
                    </Button>
                </div>

                <div
                    v-for="(fila, i) in form.conjuntos"
                    :key="i"
                    class="space-y-2 rounded-lg border p-3"
                >
                    <div
                        class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_110px_auto] sm:items-start"
                    >
                        <div>
                            <BuscadorAsync
                                :model-value="conjuntosUI[i].sel"
                                :buscar="buscarConjuntos"
                                :dependencia="empresaId"
                                :etiqueta="(c) => (c as OpcionConjunto).nombre"
                                :descripcion="
                                    (c) => (c as OpcionConjunto).codigo ?? ''
                                "
                                placeholder="Buscar conjunto…"
                                placeholder-busqueda="Buscar por nombre"
                                :invalido="
                                    !!erroresLaxos[`conjuntos.${i}.conjunto_id`]
                                "
                                @update:model-value="
                                    (v) =>
                                        alElegirConjunto(
                                            i,
                                            v as OpcionConjunto | null,
                                        )
                                "
                            />
                            <InputError
                                :message="
                                    erroresLaxos[`conjuntos.${i}.conjunto_id`]
                                "
                            />
                        </div>
                        <Input
                            v-model.number="fila.cantidad"
                            type="number"
                            min="1"
                            class="h-9"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            @click="quitarConjunto(i)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>

                    <div
                        v-if="
                            conjuntosUI[i].sel?.componentes_variante_libre
                                .length
                        "
                        class="bg-muted/30 grid gap-2 rounded-md border p-2 sm:grid-cols-2"
                    >
                        <div
                            v-for="comp in conjuntosUI[i].sel
                                ?.componentes_variante_libre ?? []"
                            :key="comp.componente_id"
                            class="grid gap-1"
                        >
                            <Label class="text-xs">
                                Variante de {{ comp.activo_nombre }}
                            </Label>
                            <select
                                v-model="fila.variantes[comp.componente_id]"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            >
                                <option :value="null" disabled>
                                    Selecciona
                                </option>
                                <option
                                    v-for="t in comp.tallas"
                                    :key="t.id"
                                    :value="t.id"
                                >
                                    {{ t.valor }}
                                </option>
                            </select>
                            <InputError
                                :message="
                                    erroresLaxos[
                                        `conjuntos.${i}.variantes.${comp.componente_id}`
                                    ]
                                "
                            />
                        </div>
                    </div>
                </div>
                <p
                    v-if="!form.conjuntos.length"
                    class="text-muted-foreground text-sm"
                >
                    Sin conjuntos agregados.
                </p>
            </section>

            <div class="grid gap-1.5">
                <Label for="notas">Notas (opcional)</Label>
                <textarea
                    id="notas"
                    v-model="form.notas"
                    rows="2"
                    class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    Registrar entrega
                </Button>
                <Button variant="ghost" as-child>
                    <Link href="/entregas">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
