<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowUpRight,
    Boxes,
    Building,
    ChevronDown,
    Layers,
    Package,
    PackagePlus,
    ScrollText,
    Settings2,
    Wrench,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AgregarExistenciasDialog from '@/components/sistema/AgregarExistenciasDialog.vue';
import AjustarExistenciaDialog from '@/components/sistema/AjustarExistenciaDialog.vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import BotonEditar from '@/components/sistema/BotonEditar.vue';
import GestionarUnidadDialog from '@/components/sistema/GestionarUnidadDialog.vue';
import MenuAccionesExistencia from '@/components/sistema/MenuAccionesExistencia.vue';
import PanelSuspendidos from '@/components/sistema/PanelSuspendidos.vue';
import RegistrarCondicionInventarioDialog from '@/components/sistema/RegistrarCondicionInventarioDialog.vue';
import RestaurarCondicionInventarioDialog from '@/components/sistema/RestaurarCondicionInventarioDialog.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Saldo = {
    empresa_id: number;
    almacen_id: number;
    activo_id: number;
    talla_id: number | null;
    almacen: string | null;
    talla: string | null;
    cantidad: number;
    minimo: number;
    bajo_minimo: boolean;
};

type EstadoCantidad =
    | 'disponible'
    | 'asignado'
    | 'danado'
    | 'baja'
    | 'robo_extravio';

type VarianteEstadoCantidad = Record<EstadoCantidad, number> & {
    talla_id: number | null;
    talla: string | null;
};

type AlmacenEstadoCantidad = {
    almacen_id: number;
    almacen: string;
    variantes: VarianteEstadoCantidad[];
};

const props = defineProps<{
    activo: {
        id: number;
        nombre: string;
        descripcion: string | null;
        categoria: string | null;
        codigo: string | null;
        activo: boolean;
        empresa: { id: number; nombre_comercial: string | null };
        tipo: string | null;
        tipo_control: 'cantidad' | 'individual';
        tipo_control_etiqueta: string;
        perfil_tecnico: 'celular' | 'computadora' | 'tablet' | null;
        perfil_tecnico_etiqueta: string | null;
        imagen_url: string | null;
        tallas: string[];
    };
    saldos: Saldo[];
    usaVariantes: boolean;
    resumenCantidades: Record<EstadoCantidad, number> | null;
    desgloseCantidades: AlmacenEstadoCantidad[] | null;
    resumenUnidades: {
        en_almacen: number;
        no_disponibles: number;
        asignada: number;
        baja: number;
    } | null;
    condicionesIncidencia: { valor: string; etiqueta: string }[] | null;
    condicionesNoIncidencia: { valor: string; etiqueta: string }[] | null;
    permisos: {
        editar: boolean;
        administrar: boolean;
        agregar_existencias: boolean;
        minimos: boolean;
        ajustar_inventario: boolean;
        gestionar_unidades: boolean;
    };
    suspendidos: {
        id: number;
        tipo: string;
        nombre: string | null;
        suspendida_en: string;
        puede_reactivarse: boolean;
        motivos: string[];
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Activos', href: '/activos' },
            { title: 'Detalle', href: '#' },
        ],
    },
});

const dialogoExistencias = ref(false);
const dialogoAjuste = ref(false);
const dialogoCondicion = ref(false);
const dialogoRestaurar = ref(false);
const dialogoGestionarUnidad = ref(false);

// "Corregir existencia"/"Cambiar condición" siempre se abren desde una fila
// concreta de "Existencias por almacén" (el menú "Gestionar" de esa fila):
// almacén, variante y existencia actual ya se conocen y se pasan como
// contexto fijo — el diálogo nunca vuelve a preguntarlos.
type ContextoFijo = {
    almacenId: number;
    almacenNombre: string;
    tallaId: number | null;
    tallaValor: string | null;
    cantidadActual: number;
};
const filaContexto = ref<ContextoFijo | null>(null);

function abrirAjuste(s: Saldo): void {
    filaContexto.value = {
        almacenId: s.almacen_id,
        almacenNombre: s.almacen ?? '—',
        tallaId: s.talla_id,
        tallaValor: s.talla,
        cantidadActual: s.cantidad,
    };
    dialogoAjuste.value = true;
}

function abrirCondicion(s: Saldo): void {
    filaContexto.value = {
        almacenId: s.almacen_id,
        almacenNombre: s.almacen ?? '—',
        tallaId: s.talla_id,
        tallaValor: s.talla,
        cantidadActual: s.cantidad,
    };
    dialogoCondicion.value = true;
}

const contextoFijoCondicion = computed(() =>
    filaContexto.value
        ? {
              almacenId: filaContexto.value.almacenId,
              almacenNombre: filaContexto.value.almacenNombre,
              tallaId: filaContexto.value.tallaId,
              tallaValor: filaContexto.value.tallaValor,
          }
        : null,
);

// "Restaurar a disponible" se abre SIEMPRE desde una fila del desglose
// "Existencias por estado → Dañado" (nunca desde "Existencias por almacén"):
// ahí ya se conoce cuántas piezas están dañadas ahora mismo para esa
// combinación exacta de almacén + variante, dato que el backend vuelve a
// validar de todos modos antes de aplicar nada.
type ContextoRestaurar = {
    almacenId: number;
    almacenNombre: string;
    tallaId: number | null;
    tallaValor: string | null;
    danadasActuales: number;
};
const filaRestaurarContexto = ref<ContextoRestaurar | null>(null);

function abrirRestaurar(
    almacenId: number,
    almacenNombre: string,
    tallaId: number | null,
    tallaValor: string | null,
    danadasActuales: number,
): void {
    filaRestaurarContexto.value = {
        almacenId,
        almacenNombre,
        tallaId,
        tallaValor,
        danadasActuales,
    };
    dialogoRestaurar.value = true;
}

// --- Existencias por estado (Disponible/Asignado/Dañado/Baja/Robo o
// extravío): 5 tarjetas con desglose desplegable por almacén (+ variante si
// el activo las usa). No se muestra "Devueltos": una devolución termina en
// Disponible, Dañado o Baja, nunca es un estado en sí mismo. ---
const tarjetasEstadoCantidad: { clave: EstadoCantidad; etiqueta: string }[] = [
    { clave: 'disponible', etiqueta: 'Disponible' },
    { clave: 'asignado', etiqueta: 'Asignado' },
    { clave: 'danado', etiqueta: 'Dañado' },
    { clave: 'baja', etiqueta: 'Baja' },
    { clave: 'robo_extravio', etiqueta: 'Robo / extravío' },
];

const estadoCantidadExpandido = ref<EstadoCantidad | null>(null);

function alternarEstadoCantidad(clave: EstadoCantidad): void {
    estadoCantidadExpandido.value =
        estadoCantidadExpandido.value === clave ? null : clave;
}

function valorEstado(fila: Record<EstadoCantidad, number>): number {
    return estadoCantidadExpandido.value
        ? fila[estadoCantidadExpandido.value]
        : 0;
}

const desgloseEstadoCantidadActual = computed(() => {
    if (!estadoCantidadExpandido.value || !props.desgloseCantidades) return [];

    return props.desgloseCantidades
        .map((a) => ({
            almacen_id: a.almacen_id,
            almacen: a.almacen,
            variantes: a.variantes.filter((v) => valorEstado(v) > 0),
        }))
        .filter((a) => a.variantes.length > 0);
});

// --- Mínimo individual (una fila = una combinación empresa+almacén+variante) ---
const dialogoMinimo = ref(false);
const filaMinimo = ref<Saldo | null>(null);
const formMinimo = useForm({
    empresa_id: 0,
    almacen_id: 0,
    activo_id: 0,
    talla_id: null as number | null,
    minimo: 0,
});
// "Sin variante" se representa como `talla_id: null` en toda la app (nunca un
// comodín 0): forzarlo a 0 aquí rompía la validación del backend
// (`Rule::exists('activo_talla', 'talla_id')` nunca encuentra una fila con
// talla_id=0) y el error resultante quedaba invisible porque el diálogo sólo
// mostraba `formMinimo.errors.minimo`, no `errors.talla_id`.
const hayErroresMinimo = computed(
    () => Object.keys(formMinimo.errors).length > 0,
);

function abrirMinimoIndividual(s: Saldo): void {
    filaMinimo.value = s;
    formMinimo.clearErrors();
    formMinimo.defaults({
        empresa_id: s.empresa_id,
        almacen_id: s.almacen_id,
        activo_id: s.activo_id,
        talla_id: s.talla_id,
        minimo: s.minimo,
    });
    formMinimo.reset();
    dialogoMinimo.value = true;
}

function guardarMinimoIndividual(): void {
    formMinimo.post('/inventario/minimos', {
        preserveScroll: true,
        onSuccess: () => (dialogoMinimo.value = false),
    });
}

// --- Mínimo masivo: "aplicar el mismo mínimo a todas las variantes" de este
// activo en UN almacén elegido (la empresa es siempre la del activo). ---
const almacenesDelActivo = computed(() => {
    const vistos = new Map<number, string>();
    for (const s of props.saldos) {
        if (!vistos.has(s.almacen_id))
            vistos.set(s.almacen_id, s.almacen ?? '—');
    }

    return Array.from(vistos, ([id, nombre]) => ({
        valor: id,
        etiqueta: nombre,
    }));
});

const dialogoMasivo = ref(false);
const previsualizacion = ref<number | null>(null);
const previsualizando = ref(false);
const formMasivo = useForm({
    empresa_id: props.activo.empresa.id,
    almacen_id: 0 as number | string,
    activo_id: props.activo.id,
    minimo: 0,
});

// Cambiar de almacén invalida la previsualización anterior: nunca se aplica
// un conteo calculado para un alcance distinto al que se va a confirmar.
watch(
    () => formMasivo.almacen_id,
    () => (previsualizacion.value = null),
);

const puedeConfirmarMasivo = computed(
    () => previsualizacion.value !== null && !previsualizando.value,
);

function abrirMinimoMasivo(): void {
    formMasivo.reset();
    formMasivo.almacen_id =
        almacenesDelActivo.value.length === 1
            ? almacenesDelActivo.value[0].valor
            : '';
    previsualizacion.value = null;
    dialogoMasivo.value = true;
}

async function previsualizarMasivo(): Promise<void> {
    if (!formMasivo.almacen_id) return;
    previsualizando.value = true;
    previsualizacion.value = null;
    try {
        const params = new URLSearchParams({
            empresa_id: String(props.activo.empresa.id),
            almacen_id: String(formMasivo.almacen_id),
            activo_id: String(props.activo.id),
        });
        const res = await fetch(
            `/inventario/minimos/masivo?${params.toString()}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );
        if (res.ok) previsualizacion.value = (await res.json()).combinaciones;
    } finally {
        previsualizando.value = false;
    }
}

function confirmarMinimoMasivo(): void {
    formMasivo.post('/inventario/minimos/masivo', {
        preserveScroll: true,
        onSuccess: () => (dialogoMasivo.value = false),
    });
}
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
                    <Badge variant="outline" class="gap-1">
                        <Building class="size-3" />
                        {{ activo.empresa.nombre_comercial ?? '—' }}
                    </Badge>
                    <Badge v-if="activo.tipo" variant="outline" class="gap-1">
                        <Layers class="size-3" /> {{ activo.tipo }}
                    </Badge>
                    <Badge variant="outline" class="gap-1">
                        <Boxes class="size-3" />
                        {{ activo.tipo_control_etiqueta }}
                    </Badge>
                    <Badge :variant="activo.activo ? 'success' : 'secondary'">
                        {{ activo.activo ? 'Activo' : 'Eliminado' }}
                    </Badge>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="
                        permisos.agregar_existencias &&
                        activo.tipo_control === 'individual'
                    "
                    variant="outline"
                    size="sm"
                    @click="dialogoExistencias = true"
                >
                    <PackagePlus class="size-3.5" />
                    Agregar unidades
                </Button>
                <BotonEditar
                    v-if="permisos.editar"
                    :href="`/activos/${activo.id}/editar`"
                />
            </div>
        </div>

        <div class="grid min-w-0 gap-4 lg:grid-cols-[320px_1fr]">
            <div class="flex min-w-0 flex-col gap-4">
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

            <section
                v-if="activo.tipo_control === 'individual'"
                class="min-w-0 rounded-xl border p-4"
            >
                <div
                    class="mb-3 flex flex-wrap items-center justify-between gap-2"
                >
                    <h2 class="flex items-center gap-2 text-sm font-semibold">
                        <Boxes class="text-muted-foreground size-4" />
                        Unidades
                        <AyudaTooltip
                            texto="Cada unidad de este activo tiene su propio código generado por el sistema y su propio QR. «En almacén» son las que están físicamente en el almacén y disponibles para entrega; «No disponibles» están en el almacén pero no pueden asignarse ahora (en reparación, inservibles, perdidas o robadas sin haberse asignado). Ninguna unidad cuenta en ambas a la vez. El estado de posesión y la condición física se gestionan por unidad."
                            etiqueta="Ayuda sobre unidades"
                        />
                    </h2>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="permisos.gestionar_unidades"
                            variant="outline"
                            size="sm"
                            @click="dialogoGestionarUnidad = true"
                        >
                            <Wrench class="size-3.5" />
                            Gestionar unidad
                        </Button>
                        <Button variant="outline" size="sm" as-child>
                            <Link
                                :href="`/activos/unidades?activo_id=${activo.id}`"
                            >
                                <Layers class="size-3.5" />
                                Ver todas las unidades</Link
                            >
                        </Button>
                    </div>
                </div>

                <div
                    v-if="resumenUnidades"
                    class="grid grid-cols-2 gap-3 text-center sm:grid-cols-4"
                >
                    <Link
                        :href="`/activos/unidades?activo_id=${activo.id}&estado_visible=disponible`"
                        class="bg-muted/40 hover:bg-muted focus-visible:ring-ring rounded-lg p-3 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <p class="text-2xl font-semibold">
                            {{ resumenUnidades.en_almacen }}
                        </p>
                        <p
                            class="text-muted-foreground flex items-center justify-center gap-1 text-xs"
                        >
                            En almacén
                            <ArrowUpRight
                                class="text-muted-foreground/70 size-3"
                            />
                        </p>
                    </Link>
                    <Link
                        :href="`/activos/unidades?activo_id=${activo.id}&no_disponible=1`"
                        class="bg-muted/40 hover:bg-muted focus-visible:ring-ring rounded-lg p-3 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <p class="text-2xl font-semibold">
                            {{ resumenUnidades.no_disponibles }}
                        </p>
                        <p
                            class="text-muted-foreground flex items-center justify-center gap-1 text-xs"
                        >
                            No disponibles
                            <ArrowUpRight
                                class="text-muted-foreground/70 size-3"
                            />
                        </p>
                    </Link>
                    <Link
                        :href="`/activos/unidades?activo_id=${activo.id}&estado=asignada`"
                        class="bg-muted/40 hover:bg-muted focus-visible:ring-ring rounded-lg p-3 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <p class="text-2xl font-semibold">
                            {{ resumenUnidades.asignada }}
                        </p>
                        <p
                            class="text-muted-foreground flex items-center justify-center gap-1 text-xs"
                        >
                            Asignadas
                            <ArrowUpRight
                                class="text-muted-foreground/70 size-3"
                            />
                        </p>
                    </Link>
                    <Link
                        :href="`/activos/unidades?activo_id=${activo.id}&estado=baja`"
                        class="bg-muted/40 hover:bg-muted focus-visible:ring-ring rounded-lg p-3 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <p class="text-2xl font-semibold">
                            {{ resumenUnidades.baja }}
                        </p>
                        <p
                            class="text-muted-foreground flex items-center justify-center gap-1 text-xs"
                        >
                            Baja
                            <ArrowUpRight
                                class="text-muted-foreground/70 size-3"
                            />
                        </p>
                    </Link>
                </div>
            </section>

            <div v-else class="flex min-w-0 flex-col gap-4">
                <section
                    v-if="resumenCantidades"
                    class="min-w-0 rounded-xl border p-4"
                >
                    <h2
                        class="mb-3 flex items-center gap-2 text-sm font-semibold"
                    >
                        <Boxes class="text-muted-foreground size-4" />
                        Existencias por estado
                        <AyudaTooltip
                            texto="Disponible: listo para entregar ahora mismo. Asignado: en posesión de colaboradores (entregado y no devuelto). Dañado: en condición no utilizable. Baja: retirado de forma permanente. Robo / extravío: reportado como robado o extraviado directamente del almacén. Toca una tarjeta para ver el desglose por almacén."
                            etiqueta="Ayuda sobre estados de existencias"
                        />
                    </h2>

                    <div
                        class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5"
                    >
                        <button
                            v-for="t in tarjetasEstadoCantidad"
                            :key="t.clave"
                            type="button"
                            :aria-expanded="estadoCantidadExpandido === t.clave"
                            :aria-label="`Ver desglose de ${t.etiqueta}`"
                            class="bg-muted/40 hover:bg-muted focus-visible:ring-ring flex flex-col items-center gap-1 rounded-lg border p-3 text-center transition-colors focus-visible:ring-2 focus-visible:outline-none"
                            :class="
                                estadoCantidadExpandido === t.clave
                                    ? 'ring-ring bg-muted ring-2'
                                    : ''
                            "
                            @click="alternarEstadoCantidad(t.clave)"
                        >
                            <p class="text-2xl font-semibold">
                                {{ resumenCantidades[t.clave] }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                {{ t.etiqueta }}
                            </p>
                            <ChevronDown
                                class="text-muted-foreground size-3.5 transition-transform"
                                :class="
                                    estadoCantidadExpandido === t.clave
                                        ? 'rotate-180'
                                        : ''
                                "
                            />
                        </button>
                    </div>

                    <div
                        v-if="estadoCantidadExpandido"
                        class="mt-3 rounded-lg border p-3"
                    >
                        <p
                            class="text-muted-foreground mb-2 text-xs font-medium"
                        >
                            Desglose de "{{
                                tarjetasEstadoCantidad.find(
                                    (t) => t.clave === estadoCantidadExpandido,
                                )?.etiqueta
                            }}" por almacén<template v-if="usaVariantes">
                                y variante</template
                            >
                        </p>

                        <p
                            v-if="!desgloseEstadoCantidadActual.length"
                            class="text-muted-foreground text-sm"
                        >
                            Sin existencia en este estado.
                        </p>

                        <ul v-else class="grid gap-2">
                            <li
                                v-for="a in desgloseEstadoCantidadActual"
                                :key="a.almacen_id"
                                class="rounded-md border p-2 text-sm"
                            >
                                <p class="truncate font-medium">
                                    {{ a.almacen }}
                                </p>
                                <ul
                                    v-if="usaVariantes"
                                    class="mt-1 grid gap-1 pl-3"
                                >
                                    <li
                                        v-for="v in a.variantes"
                                        :key="v.talla_id ?? 'sin-variante'"
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <span class="text-muted-foreground">{{
                                            v.talla ?? 'Sin variante'
                                        }}</span>
                                        <span class="flex items-center gap-2">
                                            <span class="font-medium">{{
                                                valorEstado(v)
                                            }}</span>
                                            <Button
                                                v-if="
                                                    estadoCantidadExpandido ===
                                                        'danado' &&
                                                    permisos.ajustar_inventario
                                                "
                                                variant="outline"
                                                size="sm"
                                                @click="
                                                    abrirRestaurar(
                                                        a.almacen_id,
                                                        a.almacen,
                                                        v.talla_id,
                                                        v.talla,
                                                        valorEstado(v),
                                                    )
                                                "
                                            >
                                                Restaurar a disponible
                                            </Button>
                                        </span>
                                    </li>
                                </ul>
                                <p
                                    v-else
                                    class="text-muted-foreground flex items-center justify-between gap-2"
                                >
                                    <span>Cantidad</span>
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="text-foreground font-medium"
                                            >{{
                                                a.variantes[0]
                                                    ? valorEstado(
                                                          a.variantes[0],
                                                      )
                                                    : 0
                                            }}</span
                                        >
                                        <Button
                                            v-if="
                                                estadoCantidadExpandido ===
                                                    'danado' &&
                                                permisos.ajustar_inventario
                                            "
                                            variant="outline"
                                            size="sm"
                                            @click="
                                                abrirRestaurar(
                                                    a.almacen_id,
                                                    a.almacen,
                                                    a.variantes[0]?.talla_id ??
                                                        null,
                                                    a.variantes[0]?.talla ??
                                                        null,
                                                    a.variantes[0]
                                                        ? valorEstado(
                                                              a.variantes[0],
                                                          )
                                                        : 0,
                                                )
                                            "
                                        >
                                            Restaurar a disponible
                                        </Button>
                                    </span>
                                </p>
                            </li>
                        </ul>
                    </div>
                </section>

                <section class="min-w-0 rounded-xl border p-4">
                    <div
                        class="mb-3 flex flex-wrap items-center justify-between gap-2"
                    >
                        <h2
                            class="flex items-center gap-2 text-sm font-semibold"
                        >
                            <Boxes class="text-muted-foreground size-4" />
                            Existencias por almacén
                            <AyudaTooltip
                                texto="Existencias actuales de este activo en cada almacén y variante. El almacén elegido al crear el activo fue sólo el de la entrada inicial; puede tener existencia en varios."
                                etiqueta="Ayuda sobre existencias"
                            />
                        </h2>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-if="
                                    usaVariantes &&
                                    permisos.minimos &&
                                    saldos.length
                                "
                                variant="outline"
                                size="sm"
                                @click="abrirMinimoMasivo"
                            >
                                <Settings2 class="size-3.5" />
                                Aplicar mismo mínimo a todas las variantes
                            </Button>
                            <Button
                                v-if="
                                    usaVariantes === false &&
                                    permisos.administrar
                                "
                                variant="ghost"
                                size="sm"
                                as-child
                            >
                                <Link
                                    :href="`/inventario/movimientos?activo_id=${activo.id}`"
                                    >Ver movimientos</Link
                                >
                            </Button>
                        </div>
                    </div>

                    <div
                        v-if="!saldos.length"
                        class="text-muted-foreground rounded-lg border px-3 py-6 text-center text-sm"
                    >
                        Este activo todavía no tiene existencias. Regístralas
                        desde Existencias globales → Registrar ingreso de stock.
                    </div>

                    <template v-else>
                        <!-- Escritorio / tablet ancha: tabla -->
                        <div
                            class="hidden overflow-x-auto rounded-lg border md:block"
                        >
                            <table class="w-full min-w-[560px] text-sm">
                                <thead
                                    class="bg-muted/50 text-muted-foreground text-left"
                                >
                                    <tr>
                                        <th class="px-3 py-2 font-medium">
                                            Almacén
                                        </th>
                                        <th class="px-3 py-2 font-medium">
                                            Variante
                                        </th>
                                        <th
                                            class="px-3 py-2 text-right font-medium"
                                        >
                                            Existencia
                                        </th>
                                        <th
                                            class="px-3 py-2 text-right font-medium"
                                        >
                                            Mínimo
                                        </th>
                                        <th class="px-3 py-2 font-medium">
                                            Estado
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
                                        <td class="px-3 py-2">
                                            {{ s.almacen ?? '—' }}
                                        </td>
                                        <td class="px-3 py-2">
                                            {{ s.talla ?? 'Sin variante' }}
                                        </td>
                                        <td
                                            class="px-3 py-2 text-right font-medium"
                                        >
                                            {{ s.cantidad }}
                                        </td>
                                        <td
                                            class="text-muted-foreground px-3 py-2 text-right"
                                        >
                                            {{ s.minimo }}
                                        </td>
                                        <td class="px-3 py-2">
                                            <Badge
                                                v-if="s.bajo_minimo"
                                                variant="secondary"
                                                class="text-amber-600"
                                                >Bajo mínimo</Badge
                                            >
                                            <span
                                                v-else
                                                class="text-muted-foreground"
                                                >OK</span
                                            >
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <MenuAccionesExistencia
                                                :puede-ajustar="
                                                    permisos.ajustar_inventario
                                                "
                                                :puede-minimos="
                                                    permisos.minimos
                                                "
                                                @corregir-existencia="
                                                    abrirAjuste(s)
                                                "
                                                @cambiar-condicion="
                                                    abrirCondicion(s)
                                                "
                                                @configurar-minimo="
                                                    abrirMinimoIndividual(s)
                                                "
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Móvil: una card por combinación almacén + variante -->
                        <div class="grid gap-3 md:hidden">
                            <div
                                v-for="(s, i) in saldos"
                                :key="i"
                                class="flex flex-col gap-2 rounded-lg border p-3 text-sm"
                            >
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <div class="min-w-0">
                                        <p class="truncate font-medium">
                                            {{ s.almacen ?? '—' }}
                                        </p>
                                        <p
                                            class="text-muted-foreground text-xs"
                                        >
                                            {{ s.talla ?? 'Sin variante' }}
                                        </p>
                                    </div>
                                    <Badge
                                        v-if="s.bajo_minimo"
                                        variant="secondary"
                                        class="shrink-0 text-amber-600"
                                        >Bajo mínimo</Badge
                                    >
                                    <span
                                        v-else
                                        class="text-muted-foreground shrink-0 text-xs"
                                        >OK</span
                                    >
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <p
                                            class="text-muted-foreground text-xs"
                                        >
                                            Existencia
                                        </p>
                                        <p class="font-medium">
                                            {{ s.cantidad }}
                                        </p>
                                    </div>
                                    <div>
                                        <p
                                            class="text-muted-foreground text-xs"
                                        >
                                            Mínimo
                                        </p>
                                        <p class="text-muted-foreground">
                                            {{ s.minimo }}
                                        </p>
                                    </div>
                                </div>
                                <MenuAccionesExistencia
                                    :puede-ajustar="permisos.ajustar_inventario"
                                    :puede-minimos="permisos.minimos"
                                    @corregir-existencia="abrirAjuste(s)"
                                    @cambiar-condicion="abrirCondicion(s)"
                                    @configurar-minimo="
                                        abrirMinimoIndividual(s)
                                    "
                                />
                            </div>
                        </div>
                    </template>
                </section>
            </div>
        </div>

        <PanelSuspendidos
            v-if="suspendidos.length"
            :suspendidos="suspendidos"
            :endpoint="`/activos/${activo.id}/suspendidos/reactivar`"
            :puede-reactivar="permisos.administrar"
        />

        <AgregarExistenciasDialog
            v-model:open="dialogoExistencias"
            :activo-id="activo.id"
            :empresa-id="activo.empresa.id"
            :usa-variantes="usaVariantes"
            :es-seguimiento-individual="activo.tipo_control === 'individual'"
            :perfil-tecnico="activo.perfil_tecnico"
        />

        <AjustarExistenciaDialog
            v-if="activo.tipo_control === 'cantidad'"
            v-model:open="dialogoAjuste"
            :activo-id="activo.id"
            :activo-nombre="activo.nombre"
            :activo-codigo="activo.codigo"
            :empresa-id="activo.empresa.id"
            :empresa-nombre="activo.empresa.nombre_comercial"
            :contexto-fijo="filaContexto"
        />

        <RegistrarCondicionInventarioDialog
            v-if="activo.tipo_control === 'cantidad'"
            v-model:open="dialogoCondicion"
            :activo-id="activo.id"
            :activo-nombre="activo.nombre"
            :activo-codigo="activo.codigo"
            :empresa-id="activo.empresa.id"
            :empresa-nombre="activo.empresa.nombre_comercial"
            :contexto-fijo="contextoFijoCondicion"
        />

        <RestaurarCondicionInventarioDialog
            v-if="activo.tipo_control === 'cantidad'"
            v-model:open="dialogoRestaurar"
            :activo-id="activo.id"
            :activo-nombre="activo.nombre"
            :activo-codigo="activo.codigo"
            :empresa-id="activo.empresa.id"
            :empresa-nombre="activo.empresa.nombre_comercial"
            :contexto-fijo="filaRestaurarContexto"
        />

        <GestionarUnidadDialog
            v-if="activo.tipo_control === 'individual'"
            v-model:open="dialogoGestionarUnidad"
            :activo-id="activo.id"
            :empresa-id="activo.empresa.id"
            :condiciones-incidencia="condicionesIncidencia ?? []"
            :condiciones-no-incidencia="condicionesNoIncidencia ?? []"
        />

        <Dialog v-model:open="dialogoMinimo">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Configurar mínimo</DialogTitle>
                    <DialogDescription>
                        Debajo de este mínimo, esta combinación se marca "bajo
                        mínimo" en el inventario y en los reportes. Un mínimo de
                        0 desactiva la alerta para esta fila.
                    </DialogDescription>
                </DialogHeader>
                <dl
                    v-if="filaMinimo"
                    class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm"
                >
                    <div>
                        <dt class="text-muted-foreground text-xs">Activo</dt>
                        <dd>{{ activo.nombre }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Empresa</dt>
                        <dd>{{ activo.empresa.nombre_comercial }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Almacén</dt>
                        <dd>{{ filaMinimo.almacen ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Variante</dt>
                        <dd>{{ filaMinimo.talla ?? 'Sin variante' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Existencia actual
                        </dt>
                        <dd class="font-medium">{{ filaMinimo.cantidad }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Mínimo actual
                        </dt>
                        <dd class="font-medium">{{ filaMinimo.minimo }}</dd>
                    </div>
                </dl>
                <div class="grid gap-3">
                    <div class="grid gap-1.5">
                        <Label for="activo-minimo-nuevo">Nuevo mínimo</Label>
                        <Input
                            id="activo-minimo-nuevo"
                            v-model.number="formMinimo.minimo"
                            type="number"
                            min="0"
                        />
                        <p
                            v-if="formMinimo.errors.minimo"
                            class="text-destructive text-xs"
                        >
                            {{ formMinimo.errors.minimo }}
                        </p>
                    </div>
                    <p
                        v-if="hayErroresMinimo && !formMinimo.errors.minimo"
                        class="text-destructive text-xs"
                    >
                        No pudimos guardar el mínimo. Revisa los datos e intenta
                        de nuevo.
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        :disabled="formMinimo.processing"
                        @click="dialogoMinimo = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        :disabled="formMinimo.processing"
                        @click="guardarMinimoIndividual"
                    >
                        {{ formMinimo.processing ? 'Guardando…' : 'Guardar' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="dialogoMasivo">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >Aplicar mismo mínimo a todas las variantes</DialogTitle
                    >
                    <DialogDescription>
                        Aplica un solo mínimo a TODAS las combinaciones de
                        variante que ya tienen existencia de este activo, en UN
                        almacén. No afecta otros almacenes ni otros activos.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-3">
                    <div class="grid gap-1.5">
                        <Label>Empresa</Label>
                        <p class="text-muted-foreground text-sm">
                            {{ activo.empresa.nombre_comercial }}
                        </p>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="masivo-almacen">Almacén</Label>
                        <SelectSimple
                            id="masivo-almacen"
                            v-model="formMasivo.almacen_id"
                            :opciones="almacenesDelActivo"
                            placeholder="Elige un almacén"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="masivo-minimo">Nuevo mínimo</Label>
                        <Input
                            id="masivo-minimo"
                            v-model.number="formMasivo.minimo"
                            type="number"
                            min="0"
                        />
                        <p
                            v-if="formMasivo.errors.minimo"
                            class="text-destructive text-xs"
                        >
                            {{ formMasivo.errors.minimo }}
                        </p>
                    </div>

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="w-fit"
                        :disabled="!formMasivo.almacen_id || previsualizando"
                        @click="previsualizarMasivo"
                    >
                        {{
                            previsualizando
                                ? 'Calculando…'
                                : 'Ver a cuántas variantes afecta'
                        }}
                    </Button>

                    <p
                        v-if="previsualizacion !== null"
                        class="text-sm"
                        :class="
                            previsualizacion > 0
                                ? 'text-foreground'
                                : 'text-muted-foreground'
                        "
                    >
                        <template v-if="previsualizacion > 0">
                            Se aplicará el mínimo a
                            <strong>{{ previsualizacion }}</strong>
                            combinación(es) de variante en ese almacén.
                        </template>
                        <template v-else>
                            Ninguna combinación existente de este activo tiene
                            existencia en ese almacén todavía.
                        </template>
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        :disabled="formMasivo.processing"
                        @click="dialogoMasivo = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        :disabled="
                            !puedeConfirmarMasivo ||
                            !previsualizacion ||
                            formMasivo.processing
                        "
                        @click="confirmarMinimoMasivo"
                    >
                        {{
                            formMasivo.processing
                                ? 'Aplicando…'
                                : 'Confirmar y aplicar'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
