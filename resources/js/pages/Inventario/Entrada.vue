<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Plus, Trash2 } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
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
import type { EmpresaAutorizada } from '@/types/sistema';

const props = defineProps<{ empresasAutorizadas: EmpresaAutorizada[] }>();

type AlmacenOpcion = {
    id: number;
    nombre: string;
    codigo: string | null;
    direccion: string | null;
};
type TallaOpcion = { id: number; valor: string };
type ActivoBuscado = {
    id: number;
    nombre: string;
    codigo: string | null;
    tipo: string | null;
    categoria: string | null;
    control: string;
    /** El activo tiene variantes asociadas (independiente de la empresa). */
    usa_variantes: boolean;
    /** Variantes ELEGIBLES: asociadas al activo y habilitadas para esta empresa. */
    tallas: TallaOpcion[];
};

/** Activo con variantes pero ninguna habilitada para la empresa seleccionada. */
function sinVariantesHabilitadas(a: ActivoBuscado | null): boolean {
    return !!a && a.usa_variantes && a.tallas.length === 0;
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inventario', href: '/inventario' },
            { title: 'Registrar entrada', href: '/inventario/entrada' },
        ],
    },
});

const form = useForm<{
    empresa_id: number | null;
    almacen_id: number | null;
    motivo: string;
    notas: string;
    carga_inicial: boolean;
    items: {
        activo_id: number | null;
        talla_id: number | null;
        cantidad: number;
    }[];
}>({
    empresa_id:
        props.empresasAutorizadas.length === 1
            ? props.empresasAutorizadas[0].id
            : null,
    almacen_id: null,
    motivo: '',
    notas: '',
    carga_inicial: false,
    items: [{ activo_id: null, talla_id: null, cantidad: 1 }],
});

// Estado de UI paralelo a form.items (por índice): objeto de almacén/activo
// seleccionados para poder mostrar su etiqueta en el combobox.
const almacenSel = ref<AlmacenOpcion | null>(null);
const activosSel = reactive<Record<number, ActivoBuscado | null>>({ 0: null });

const enviado = ref(false);

// Empresa seleccionada (objeto, para el combobox con buscador).
const empresaSel = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === form.empresa_id) ?? null,
);
const confirmarCambio = ref(false);
const empresaPendiente = ref<EmpresaAutorizada | null>(null);

function buscarEmpresas(q: string): Promise<EmpresaAutorizada[]> {
    const t = q.trim().toLowerCase();
    return Promise.resolve(
        t
            ? props.empresasAutorizadas.filter(
                  (e) =>
                      e.nombre_comercial.toLowerCase().includes(t) ||
                      e.codigo.toLowerCase().includes(t),
              )
            : props.empresasAutorizadas,
    );
}

/** ¿El usuario ya capturó algo que se perdería al cambiar de empresa? */
const hayTrabajoCapturado = computed(
    () =>
        !!form.almacen_id ||
        form.items.some((it) => it.activo_id !== null) ||
        form.motivo.trim() !== '' ||
        form.notas.trim() !== '',
);

/** Limpia de forma determinista todo lo que depende de la empresa. */
function limpiarDependientes(): void {
    almacenSel.value = null;
    form.almacen_id = null;
    form.items = [{ activo_id: null, talla_id: null, cantidad: 1 }];
    Object.keys(activosSel).forEach((k) => delete activosSel[Number(k)]);
    activosSel[0] = null;
    form.clearErrors();
}

/** Aplica la empresa: fija el id del form y limpia todo lo dependiente. */
function aplicarEmpresa(e: EmpresaAutorizada | null): void {
    empresaSel.value = e;
    form.empresa_id = e?.id ?? null;
    limpiarDependientes();
    form.clearErrors('empresa_id');
}

/**
 * Intercepta el cambio de empresa. Si hay datos capturados, pide confirmación
 * ANTES de borrar nada: mientras tanto el combobox vuelve a la empresa vigente.
 * Si no hay nada que perder, cambia directo.
 */
function alElegirEmpresa(e: EmpresaAutorizada | null): void {
    if ((e?.id ?? null) === form.empresa_id) {
        return;
    }

    if (hayTrabajoCapturado.value) {
        empresaPendiente.value = e;
        confirmarCambio.value = true;

        return;
    }

    aplicarEmpresa(e);
}

function confirmarCambioEmpresa(): void {
    aplicarEmpresa(empresaPendiente.value);
    empresaPendiente.value = null;
    confirmarCambio.value = false;
}

function cancelarCambioEmpresa(): void {
    empresaPendiente.value = null;
    confirmarCambio.value = false;
    // `empresaSel` no cambió: el combobox ya muestra la empresa vigente.
}

/** Acceso laxo a errores anidados (`items.0.cantidad`). */
const errores = computed(
    () => form.errors as unknown as Record<string, string>,
);
function errFila(i: number, campo: string): string | undefined {
    return errores.value[`items.${i}.${campo}`];
}

async function buscarAlmacenes(
    q: string,
    signal?: AbortSignal,
): Promise<AlmacenOpcion[]> {
    if (!form.empresa_id) return [];
    const res = await fetch(
        `/almacenes/buscar?empresa_id=${form.empresa_id}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).almacenes ?? [];
}

async function buscarActivos(
    q: string,
    signal?: AbortSignal,
): Promise<ActivoBuscado[]> {
    if (!form.empresa_id) return [];
    const res = await fetch(
        `/activos/buscar?empresa_id=${form.empresa_id}&control=cantidad&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).activos ?? [];
}

function alElegirAlmacen(a: AlmacenOpcion | null) {
    almacenSel.value = a;
    form.almacen_id = a?.id ?? null;
    form.clearErrors('almacen_id');
}

function alElegirActivo(i: number, a: ActivoBuscado | null) {
    activosSel[i] = a;
    form.items[i].activo_id = a?.id ?? null;
    form.items[i].talla_id = null;
    form.clearErrors();
}

function agregarFila() {
    form.items.push({ activo_id: null, talla_id: null, cantidad: 1 });
    activosSel[form.items.length - 1] = null;
}

function quitarFila(i: number) {
    form.items.splice(i, 1);
    // Reindexa el estado de UI.
    const nuevo: Record<number, ActivoBuscado | null> = {};
    form.items.forEach((_, idx) => {
        nuevo[idx] = idx < i ? activosSel[idx] : (activosSel[idx + 1] ?? null);
    });
    Object.keys(activosSel).forEach((k) => delete activosSel[Number(k)]);
    Object.assign(activosSel, nuevo);
}

const duplicados = computed(() => {
    const vistos = new Set<string>();
    const dups = new Set<number>();
    form.items.forEach((it, i) => {
        if (!it.activo_id) return;
        const clave = `${it.activo_id}-${it.talla_id ?? 0}`;
        if (vistos.has(clave)) dups.add(i);
        vistos.add(clave);
    });
    return dups;
});

const incompleto = computed(
    () =>
        !form.empresa_id ||
        !form.almacen_id ||
        !form.motivo.trim() ||
        form.items.length === 0 ||
        form.items.some(
            (it, i) =>
                !it.activo_id ||
                !(it.cantidad > 0) ||
                (activosSel[i]?.tallas.length && !it.talla_id) ||
                sinVariantesHabilitadas(activosSel[i] ?? null),
        ) ||
        duplicados.value.size > 0,
);

const hayErrores = computed(() => Object.keys(form.errors).length > 0);

function enviar() {
    enviado.value = true;
    form.transform((d) => ({
        ...d,
        items: d.items.map((it) => ({
            ...it,
            talla_id: it.talla_id || null,
        })),
    })).post('/inventario/entrada', {
        onError: () => {
            // El resumen y los errores inline se muestran solos.
        },
    });
}
</script>

<template>
    <Head title="Registrar entrada de inventario" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Registrar entrada de inventario"
            descripcion="Suma existencias a un almacén: recepción de compra, traspaso o carga inicial. Cada movimiento queda registrado."
        />

        <div
            v-if="enviado && hayErrores"
            class="border-destructive/40 bg-destructive/10 text-destructive flex items-start gap-2 rounded-lg border px-4 py-3 text-sm"
            role="alert"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            <span
                >No pudimos registrar la entrada. Revisa los campos
                marcados.</span
            >
        </div>

        <form class="space-y-6" @submit.prevent="enviar">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5 sm:col-span-2">
                    <Label for="empresa">Empresa / razón social</Label>
                    <BuscadorAsync
                        id="empresa"
                        :model-value="empresaSel"
                        :buscar="buscarEmpresas"
                        :etiqueta="
                            (e) => (e as EmpresaAutorizada).nombre_comercial
                        "
                        :descripcion="(e) => (e as EmpresaAutorizada).codigo"
                        placeholder="Selecciona una empresa"
                        placeholder-busqueda="Buscar empresa por nombre o código"
                        :invalido="!!form.errors.empresa_id"
                        @update:model-value="
                            (v) =>
                                alElegirEmpresa(v as EmpresaAutorizada | null)
                        "
                    />
                    <p class="text-muted-foreground text-xs">
                        El almacén y los activos se acotan a esta empresa. Sólo
                        se ofrecen las variantes que la empresa tenga
                        habilitadas.
                    </p>
                    <InputError :message="form.errors.empresa_id" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="almacen">Almacén</Label>
                    <BuscadorAsync
                        id="almacen"
                        :model-value="almacenSel"
                        :buscar="buscarAlmacenes"
                        :dependencia="form.empresa_id ?? ''"
                        :etiqueta="(a) => (a as AlmacenOpcion).nombre"
                        :descripcion="
                            (a) =>
                                [
                                    (a as AlmacenOpcion).codigo,
                                    (a as AlmacenOpcion).direccion,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                        "
                        :placeholder="
                            form.empresa_id
                                ? 'Selecciona un almacén'
                                : 'Selecciona primero una empresa'
                        "
                        placeholder-busqueda="Buscar por nombre, código o dirección"
                        sin-resultados="No hay almacenes activos que abastezcan esta empresa."
                        :disabled="!form.empresa_id"
                        :invalido="!!form.errors.almacen_id"
                        @update:model-value="
                            (v) => alElegirAlmacen(v as AlmacenOpcion | null)
                        "
                    />
                    <p class="text-muted-foreground text-xs">
                        Lugar físico donde ingresarán las existencias.
                    </p>
                    <InputError :message="form.errors.almacen_id" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="motivo" class="flex items-center gap-1.5">
                        Motivo / referencia
                        <AyudaTooltip
                            texto="Un solo campo: describe el origen de la entrada. Si tienes una orden de compra o folio, escríbelo aquí."
                            etiqueta="Ayuda sobre el motivo / referencia"
                        />
                    </Label>
                    <Input
                        id="motivo"
                        v-model="form.motivo"
                        placeholder="Compra OC-1042 · Inventario inicial · Traspaso"
                        :aria-invalid="!!form.errors.motivo || undefined"
                    />
                    <p class="text-muted-foreground text-xs">
                        Indica el origen o motivo de esta entrada, por ejemplo:
                        compra OC-1042, inventario inicial o devolución
                        extraordinaria.
                    </p>
                    <InputError :message="form.errors.motivo" />
                </div>
            </div>

            <fieldset class="grid gap-2">
                <legend class="text-sm font-medium">Activos a ingresar</legend>
                <p class="text-muted-foreground text-xs">
                    Sólo activos por cantidad. Los serializados (laptops,
                    teléfonos) se registran unidad por unidad en otra pantalla.
                </p>

                <div
                    v-for="(item, i) in form.items"
                    :key="i"
                    class="grid gap-3 rounded-lg border p-3 sm:grid-cols-[minmax(0,1fr)_160px_110px_auto] sm:items-start"
                    :class="duplicados.has(i) ? 'border-destructive/50' : ''"
                >
                    <div class="grid min-w-0 gap-1.5">
                        <Label :for="`activo-${i}`">Activo</Label>
                        <BuscadorAsync
                            :id="`activo-${i}`"
                            :model-value="activosSel[i] ?? null"
                            :buscar="buscarActivos"
                            :dependencia="form.empresa_id ?? ''"
                            :disabled="!form.empresa_id"
                            :etiqueta="(a) => (a as ActivoBuscado).nombre"
                            :descripcion="
                                (a) =>
                                    [
                                        (a as ActivoBuscado).codigo,
                                        (a as ActivoBuscado).tipo,
                                        (a as ActivoBuscado).categoria,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')
                            "
                            placeholder="Buscar activo…"
                            placeholder-busqueda="Nombre, código, tipo o categoría"
                            :invalido="
                                !!errFila(i, 'activo_id') || duplicados.has(i)
                            "
                            @update:model-value="
                                (v) =>
                                    alElegirActivo(i, v as ActivoBuscado | null)
                            "
                        />
                        <InputError :message="errFila(i, 'activo_id')" />
                        <p
                            v-if="duplicados.has(i)"
                            class="text-destructive text-xs"
                        >
                            Ya agregaste este activo y variante. Ajusta la
                            cantidad en la fila anterior.
                        </p>
                    </div>

                    <div class="grid gap-1.5">
                        <Label :for="`talla-${i}`">Variante / talla</Label>
                        <template
                            v-if="(activosSel[i]?.tallas.length ?? 0) > 0"
                        >
                            <select
                                :id="`talla-${i}`"
                                v-model.number="item.talla_id"
                                class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                :class="
                                    errFila(i, 'talla_id')
                                        ? 'border-destructive'
                                        : ''
                                "
                            >
                                <option :value="null">Selecciona…</option>
                                <option
                                    v-for="t in activosSel[i]!.tallas"
                                    :key="t.id"
                                    :value="t.id"
                                >
                                    {{ t.valor }}
                                </option>
                            </select>
                            <InputError :message="errFila(i, 'talla_id')" />
                        </template>
                        <p
                            v-else-if="
                                sinVariantesHabilitadas(activosSel[i] ?? null)
                            "
                            class="text-destructive pt-2 text-xs"
                        >
                            Este activo usa variantes, pero ninguna está
                            habilitada para la empresa seleccionada. Habilita
                            una en «Variantes / tallas» antes de registrar
                            existencias.
                        </p>
                        <p
                            v-else-if="activosSel[i]"
                            class="text-muted-foreground pt-2 text-xs"
                        >
                            Este activo no utiliza variantes.
                        </p>
                        <p v-else class="text-muted-foreground pt-2 text-xs">
                            Elige un activo primero.
                        </p>
                    </div>

                    <div class="grid gap-1.5">
                        <Label :for="`cantidad-${i}`">Cantidad</Label>
                        <Input
                            :id="`cantidad-${i}`"
                            v-model.number="item.cantidad"
                            type="number"
                            min="1"
                            class="h-9"
                            :class="
                                errFila(i, 'cantidad')
                                    ? 'border-destructive'
                                    : ''
                            "
                        />
                        <InputError :message="errFila(i, 'cantidad')" />
                    </div>

                    <div class="flex items-end sm:pt-6">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            :disabled="form.items.length === 1"
                            :aria-label="`Quitar la fila ${i + 1}`"
                            @click="quitarFila(i)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="w-fit"
                    @click="agregarFila"
                >
                    <Plus class="size-4" /> Agregar activo
                </Button>
                <InputError :message="form.errors.items" />
            </fieldset>

            <div class="grid gap-1.5">
                <Label for="notas">Notas (opcional)</Label>
                <textarea
                    id="notas"
                    v-model="form.notas"
                    rows="2"
                    class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                />
            </div>

            <label class="flex items-start gap-2 text-sm">
                <input
                    v-model="form.carga_inicial"
                    type="checkbox"
                    class="mt-0.5 size-4"
                />
                <span>
                    Marcar como carga inicial
                    <AyudaTooltip
                        texto="Úsalo únicamente al capturar por primera vez las existencias que ya tenía el almacén. No lo uses para compras o reposiciones."
                        etiqueta="Ayuda sobre la carga inicial"
                    />
                </span>
            </label>

            <div class="flex flex-wrap items-center gap-3">
                <Button type="submit" :disabled="form.processing || incompleto">
                    Registrar entrada
                </Button>
                <Button variant="ghost" as-child>
                    <Link href="/inventario">Cancelar</Link>
                </Button>
                <p
                    v-if="incompleto && !form.processing"
                    class="text-muted-foreground text-xs"
                >
                    Completa el almacén, el motivo y al menos una fila con
                    activo, variante (si aplica) y cantidad.
                </p>
            </div>
        </form>

        <Dialog
            :open="confirmarCambio"
            @update:open="(v: boolean) => !v && cancelarCambioEmpresa()"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cambiar de empresa</DialogTitle>
                    <DialogDescription>
                        Cambiar de empresa limpiará el almacén y los activos
                        seleccionados en esta entrada. ¿Deseas continuar?
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="cancelarCambioEmpresa">
                        Cancelar
                    </Button>
                    <Button
                        variant="destructive"
                        @click="confirmarCambioEmpresa"
                    >
                        Sí, cambiar empresa
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
