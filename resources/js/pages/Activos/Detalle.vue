<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Boxes,
    Building,
    Layers,
    Package,
    PackagePlus,
    ScrollText,
    Settings2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AgregarExistenciasDialog from '@/components/sistema/AgregarExistenciasDialog.vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import BotonEditar from '@/components/sistema/BotonEditar.vue';
import PanelSuspendidos from '@/components/sistema/PanelSuspendidos.vue';
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
    resumenUnidades: {
        en_almacen: number;
        no_disponibles: number;
        asignada: number;
        baja: number;
    } | null;
    permisos: {
        editar: boolean;
        administrar: boolean;
        agregar_existencias: boolean;
        minimos: boolean;
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
                    v-if="permisos.agregar_existencias"
                    variant="outline"
                    size="sm"
                    @click="dialogoExistencias = true"
                >
                    <PackagePlus class="size-3.5" />
                    {{
                        activo.tipo_control === 'individual'
                            ? 'Agregar unidades'
                            : 'Agregar existencias'
                    }}
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
                            texto="Cada unidad de este activo tiene su propio código generado por el sistema y su propio QR. «En almacén» cuenta presencia física (incluye unidades no disponibles); «No disponibles» son las que están en almacén pero no pueden asignarse (en reparación / inservibles). El estado de posesión y la condición física se gestionan por unidad."
                            etiqueta="Ayuda sobre unidades"
                        />
                    </h2>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="`/activos/unidades?activo_id=${activo.id}`"
                            >Ver todas las unidades</Link
                        >
                    </Button>
                </div>

                <div
                    v-if="resumenUnidades"
                    class="grid grid-cols-2 gap-3 text-center sm:grid-cols-4"
                >
                    <Link
                        :href="`/activos/unidades?activo_id=${activo.id}&estado=en_almacen`"
                        class="bg-muted/40 hover:bg-muted focus-visible:ring-ring rounded-lg p-3 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <p class="text-2xl font-semibold">
                            {{ resumenUnidades.en_almacen }}
                        </p>
                        <p class="text-muted-foreground text-xs">En almacén</p>
                    </Link>
                    <Link
                        :href="`/activos/unidades?activo_id=${activo.id}&estado=en_almacen&estado_visible=reparacion`"
                        class="bg-muted/40 hover:bg-muted focus-visible:ring-ring rounded-lg p-3 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <p class="text-2xl font-semibold">
                            {{ resumenUnidades.no_disponibles }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            No disponibles
                        </p>
                    </Link>
                    <Link
                        :href="`/activos/unidades?activo_id=${activo.id}&estado=asignada`"
                        class="bg-muted/40 hover:bg-muted focus-visible:ring-ring rounded-lg p-3 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <p class="text-2xl font-semibold">
                            {{ resumenUnidades.asignada }}
                        </p>
                        <p class="text-muted-foreground text-xs">Asignadas</p>
                    </Link>
                    <Link
                        :href="`/activos/unidades?activo_id=${activo.id}&estado=baja`"
                        class="bg-muted/40 hover:bg-muted focus-visible:ring-ring rounded-lg p-3 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <p class="text-2xl font-semibold">
                            {{ resumenUnidades.baja }}
                        </p>
                        <p class="text-muted-foreground text-xs">Baja</p>
                    </Link>
                </div>
            </section>

            <section v-else class="min-w-0 rounded-xl border p-4">
                <div
                    class="mb-3 flex flex-wrap items-center justify-between gap-2"
                >
                    <h2 class="flex items-center gap-2 text-sm font-semibold">
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
                                usaVariantes === false && permisos.administrar
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
                    Este activo todavía no tiene existencias.
                    <span v-if="permisos.agregar_existencias">
                        Usa "Agregar existencias" para registrar la primera
                        entrada.</span
                    >
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
                                        <Button
                                            v-if="permisos.minimos"
                                            variant="ghost"
                                            size="sm"
                                            @click="abrirMinimoIndividual(s)"
                                        >
                                            <Settings2 class="size-3.5" />
                                            Configurar mínimo
                                        </Button>
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
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate font-medium">
                                        {{ s.almacen ?? '—' }}
                                    </p>
                                    <p class="text-muted-foreground text-xs">
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
                                    <p class="text-muted-foreground text-xs">
                                        Existencia
                                    </p>
                                    <p class="font-medium">{{ s.cantidad }}</p>
                                </div>
                                <div>
                                    <p class="text-muted-foreground text-xs">
                                        Mínimo
                                    </p>
                                    <p class="text-muted-foreground">
                                        {{ s.minimo }}
                                    </p>
                                </div>
                            </div>
                            <Button
                                v-if="permisos.minimos"
                                variant="outline"
                                size="sm"
                                class="w-fit"
                                @click="abrirMinimoIndividual(s)"
                            >
                                <Settings2 class="size-3.5" />
                                Configurar mínimo
                            </Button>
                        </div>
                    </div>
                </template>
            </section>
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
