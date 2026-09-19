<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
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

// Siempre se abre desde una fila/card concreta (Existencias globales, o
// "Existencias por almacén" del propio activo): empresa, almacén, activo y
// variante ya se conocen y NUNCA se vuelven a preguntar — sólo se muestran
// como contexto de sólo lectura. El usuario únicamente decide la nueva
// existencia y el motivo.
type ContextoFijo = {
    almacenId: number;
    almacenNombre: string;
    tallaId: number | null;
    tallaValor: string | null;
    cantidadActual: number;
};

const props = defineProps<{
    open: boolean;
    activoId: number;
    activoNombre: string;
    activoCodigo?: string | null;
    empresaId: number;
    empresaNombre?: string | null;
    contextoFijo: ContextoFijo | null;
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

const existenciaActual = computed<number>(
    () => props.contextoFijo?.cantidadActual ?? 0,
);

const diferencia = computed<number>(
    () => form.existencia_objetivo - existenciaActual.value,
);

watch(
    () => props.open,
    (abierto) => {
        if (!abierto || !props.contextoFijo) return;

        form.reset();
        form.clearErrors();
        form.almacen_id = props.contextoFijo.almacenId;
        form.talla_id = props.contextoFijo.tallaId;
        form.existencia_objetivo = props.contextoFijo.cantidadActual;
    },
);

function enviar(): void {
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
                <DialogTitle>Corregir existencia</DialogTitle>
                <DialogDescription>
                    Actualiza la cantidad cuando el conteo físico no coincide
                    con el sistema. El cambio quedará registrado en el
                    historial.
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="enviar">
                <dl
                    v-if="contextoFijo"
                    class="bg-muted/40 grid gap-1.5 rounded-lg border p-3 text-sm"
                >
                    <div>
                        <dt class="text-muted-foreground text-xs">Activo</dt>
                        <dd class="font-medium">
                            {{ activoNombre
                            }}<span
                                v-if="activoCodigo"
                                class="text-muted-foreground font-mono"
                            >
                                · {{ activoCodigo }}</span
                            >
                        </dd>
                    </div>
                    <div v-if="empresaNombre">
                        <dt class="text-muted-foreground text-xs">Empresa</dt>
                        <dd>{{ empresaNombre }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Almacén</dt>
                        <dd>{{ contextoFijo.almacenNombre }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Variante</dt>
                        <dd>{{ contextoFijo.tallaValor ?? 'Sin variante' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Existencia actual
                        </dt>
                        <dd class="font-medium">{{ existenciaActual }}</dd>
                    </div>
                </dl>

                <div class="grid gap-1.5">
                    <Label for="ajuste-nueva" class="flex items-center gap-1">
                        Nueva existencia
                        <span class="text-destructive">*</span>
                    </Label>
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
                    <Label for="ajuste-motivo" class="flex items-center gap-1">
                        Motivo
                        <span class="text-destructive">*</span>
                    </Label>
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
                        Corregir
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
