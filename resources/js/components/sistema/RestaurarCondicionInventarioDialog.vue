<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
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

// Se abre SIEMPRE desde una fila del desglose "Existencias por estado →
// Dañado" del detalle del activo: empresa, almacén, activo y variante ya se
// conocen, igual que cuántas piezas están dañadas AHORA MISMO en esa
// combinación exacta — el usuario sólo decide cuántas restaurar y por qué.
// Nunca aparece para "Baja": es terminal (no se "revive" una baja).
type ContextoFijo = {
    almacenId: number;
    almacenNombre: string;
    tallaId: number | null;
    tallaValor: string | null;
    danadasActuales: number;
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
    cantidad: number;
    motivo: string;
}>({
    empresa_id: props.empresaId,
    almacen_id: null,
    activo_id: props.activoId,
    talla_id: null,
    cantidad: 0,
    motivo: '',
});

watch(
    () => props.open,
    (abierto) => {
        if (!abierto || !props.contextoFijo) return;

        form.clearErrors();
        form.cantidad = 0;
        form.motivo = '';
        form.almacen_id = props.contextoFijo.almacenId;
        form.talla_id = props.contextoFijo.tallaId;
    },
);

function enviar(): void {
    form.post('/inventario/condicion/restaurar', {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Restaurar a disponible</DialogTitle>
                <DialogDescription>
                    Regresa a "Disponible" piezas que se habían marcado como
                    dañadas (se repararon o el conteo estaba mal).
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
                            Dañadas actualmente
                        </dt>
                        <dd class="font-medium">
                            {{ contextoFijo.danadasActuales }}
                        </dd>
                    </div>
                </dl>

                <div class="grid gap-1.5">
                    <Label
                        for="restaurar-cantidad"
                        class="flex items-center gap-1"
                    >
                        Cantidad a restaurar
                        <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="restaurar-cantidad"
                        v-model.number="form.cantidad"
                        type="number"
                        min="1"
                        :max="contextoFijo?.danadasActuales ?? undefined"
                        step="1"
                    />
                    <InputError :message="form.errors.cantidad" />
                </div>

                <div class="grid gap-1.5">
                    <Label
                        for="restaurar-motivo"
                        class="flex items-center gap-1"
                    >
                        Motivo
                        <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="restaurar-motivo"
                        v-model="form.motivo"
                        placeholder="p. ej. Se reparó la pieza"
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
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
