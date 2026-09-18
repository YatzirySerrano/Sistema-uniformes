<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
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

type OpcionAlmacen = { id: number; nombre: string; codigo: string | null };
type OpcionVariante = { id: number; valor: string };
type Saldo = {
    almacen_id: number;
    talla_id: number | null;
    cantidad: number;
};

const props = defineProps<{
    open: boolean;
    activoId: number;
    empresaId: number;
    usaVariantes: boolean;
    saldos: Saldo[];
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();

const form = useForm<{
    empresa_id: number;
    almacen_id: number | null;
    activo_id: number;
    talla_id: number | null;
    existencia_objetivo: number;
    motivo: string;
}>({
    empresa_id: props.empresaId,
    almacen_id: null,
    activo_id: props.activoId,
    talla_id: null,
    existencia_objetivo: 0,
    motivo: '',
});

const almacenSel = ref<OpcionAlmacen | null>(null);
const tallaSel = ref<OpcionVariante | null>(null);

// Existencia actual de la combinación almacén + variante elegida: se lee de
// los saldos YA cargados en el detalle del activo (no hace falta otra
// consulta). Una combinación sin saldo todavía cuenta como 0 — "ajustar" a
// partir de 0 es válido (establece la primera existencia formal).
const existenciaActual = computed<number>(() => {
    if (form.almacen_id === null) return 0;
    const saldo = props.saldos.find(
        (s) =>
            s.almacen_id === form.almacen_id && s.talla_id === form.talla_id,
    );
    return saldo?.cantidad ?? 0;
});

// El campo "nueva existencia" arranca en la existencia actual de la
// combinación elegida (punto de partida cómodo: el usuario sólo corrige el
// número, no lo escribe desde cero) y se resincroniza si cambia el almacén
// o la variante.
watch(existenciaActual, (v) => {
    form.existencia_objetivo = v;
});

const diferencia = computed<number>(
    () => form.existencia_objetivo - existenciaActual.value,
);

async function buscarAlmacenes(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionAlmacen[]> {
    const res = await fetch(
        `/almacenes/buscar?empresa_id=${props.empresaId}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).almacenes ?? [];
}

async function buscarVariantes(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionVariante[]> {
    const params = new URLSearchParams({ activo_id: String(props.activoId), q });
    const res = await fetch(`/tallas/buscar?${params.toString()}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];
    return (await res.json()).tallas ?? [];
}

watch(
    () => props.open,
    (abierto) => {
        if (abierto) {
            form.reset();
            form.clearErrors();
            almacenSel.value = null;
            tallaSel.value = null;
        }
    },
);

function enviar(): void {
    // `AjustarInventario` es la única lógica de ajuste (fija la existencia
    // objetivo y genera su propio movimiento/auditoría) — este diálogo sólo
    // envía al MISMO endpoint que ya usa el módulo de Inventario, nunca
    // escribe el saldo directamente.
    form.post('/inventario/ajuste', {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Ajustar existencia</DialogTitle>
                <DialogDescription>
                    Corrige la existencia de este activo a lo que muestre un
                    conteo físico real. Genera movimiento y auditoría —
                    reutiliza exactamente la misma lógica que "Ajustar
                    existencias" en Inventario.
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="enviar">
                <div class="grid gap-1.5">
                    <Label for="ajuste-almacen">Almacén</Label>
                    <BuscadorAsync
                        id="ajuste-almacen"
                        :model-value="almacenSel"
                        :buscar="buscarAlmacenes"
                        :etiqueta="(a) => (a as OpcionAlmacen).nombre"
                        :descripcion="(a) => (a as OpcionAlmacen).codigo ?? ''"
                        placeholder="Selecciona un almacén"
                        placeholder-busqueda="Buscar almacén por nombre"
                        :invalido="!!form.errors.almacen_id"
                        @update:model-value="
                            (v) => {
                                almacenSel = v as OpcionAlmacen | null;
                                form.almacen_id =
                                    (v as OpcionAlmacen | null)?.id ?? null;
                                form.clearErrors('almacen_id');
                            }
                        "
                    />
                    <InputError :message="form.errors.almacen_id" />
                </div>

                <div v-if="usaVariantes" class="grid gap-1.5">
                    <Label for="ajuste-variante">Variante / talla</Label>
                    <BuscadorAsync
                        id="ajuste-variante"
                        :model-value="tallaSel"
                        :buscar="buscarVariantes"
                        :etiqueta="(t) => (t as OpcionVariante).valor"
                        placeholder="Selecciona la variante"
                        placeholder-busqueda="Buscar variante"
                        :invalido="!!form.errors.talla_id"
                        @update:model-value="
                            (v) => {
                                tallaSel = v as OpcionVariante | null;
                                form.talla_id =
                                    (v as OpcionVariante | null)?.id ?? null;
                                form.clearErrors('talla_id');
                            }
                        "
                    />
                    <InputError :message="form.errors.talla_id" />
                </div>

                <div v-if="form.almacen_id !== null" class="grid gap-1.5">
                    <Label>Existencia actual</Label>
                    <p class="text-lg font-semibold">
                        {{ existenciaActual }}
                    </p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="ajuste-nueva">Nueva existencia</Label>
                    <Input
                        id="ajuste-nueva"
                        v-model.number="form.existencia_objetivo"
                        type="number"
                        min="0"
                        step="1"
                    />
                    <InputError :message="form.errors.existencia_objetivo" />
                </div>

                <p
                    v-if="diferencia !== 0"
                    class="text-sm"
                    :class="
                        diferencia > 0 ? 'text-emerald-600' : 'text-destructive'
                    "
                >
                    Diferencia: {{ diferencia > 0 ? '+' : '' }}{{ diferencia }}
                </p>

                <div class="grid gap-1.5">
                    <Label for="ajuste-motivo">Motivo</Label>
                    <Input
                        id="ajuste-motivo"
                        v-model="form.motivo"
                        placeholder="p. ej. Diferencia detectada en inventario físico"
                    />
                    <InputError :message="form.errors.motivo" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="emit('update:open', false)"
                    >
                        Cancelar
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        Ajustar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
