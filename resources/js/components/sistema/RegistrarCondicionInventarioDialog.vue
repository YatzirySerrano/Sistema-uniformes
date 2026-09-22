<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
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

type Modo = 'danado' | 'baja' | 'robo_extravio';

// Siempre se abre desde una fila/card concreta (Existencias globales, o
// "Existencias por almacén" del propio activo): empresa, almacén, activo y
// variante ya se conocen y NUNCA se vuelven a preguntar. Sólo cubre las
// condiciones que RESTAN de "Disponible" — "Restaurar a disponible" tiene su
// propio diálogo (`RestaurarCondicionInventarioDialog.vue`), abierto desde el
// desglose "Existencias por estado → Dañado": no tenía sentido ofrecerla aquí
// (nunca se restaura algo que, en este flujo, todavía no se ha dañado).
type ContextoFijo = {
    almacenId: number;
    almacenNombre: string;
    tallaId: number | null;
    tallaValor: string | null;
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

const opcionesModo: { valor: Modo; etiqueta: string }[] = [
    { valor: 'danado', etiqueta: 'Dañado' },
    { valor: 'baja', etiqueta: 'Baja' },
    { valor: 'robo_extravio', etiqueta: 'Robo / extravío' },
];

const form = useForm<{
    empresa_id: number;
    almacen_id: number | null;
    activo_id: number;
    talla_id: number | null;
    condicion: Modo;
    cantidad: number | '';
    motivo: string;
}>({
    empresa_id: props.empresaId,
    almacen_id: null,
    activo_id: props.activoId,
    talla_id: null,
    condicion: 'danado',
    // Vacío a propósito: la cantidad debe ser capturada por el usuario.
    cantidad: '',
    motivo: '',
});

// Reescribe CADA campo editable de forma explícita en cada apertura — nunca
// depende de que `form.reset()` "adivine" el estado correcto. Corrige el bug
// real detectado en pruebas manuales: al reabrir el diálogo (misma fila u
// otra variante) podían verse cantidad/motivo de la operación anterior. Se
// dispara con la transición de `open`, nunca con sólo cambiar `contextoFijo`
// (el diálogo siempre se cierra antes de reabrirse para otra fila).
watch(
    () => props.open,
    (abierto) => {
        if (!abierto || !props.contextoFijo) return;

        form.clearErrors();
        form.condicion = 'danado';
        form.cantidad = '';
        form.motivo = '';
        form.almacen_id = props.contextoFijo.almacenId;
        form.talla_id = props.contextoFijo.tallaId;
    },
);

function enviar(): void {
    form.post('/inventario/condicion', {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Cambiar condición</DialogTitle>
                <DialogDescription>
                    Registra piezas dañadas o que ya no estarán disponibles. Se
                    descuentan de "Disponible" y el cambio queda registrado.
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
                </dl>

                <div class="grid gap-1.5">
                    <Label for="condicion-modo" class="flex items-center gap-1">
                        Condición
                        <span class="text-destructive">*</span>
                    </Label>
                    <SelectSimple
                        id="condicion-modo"
                        v-model="form.condicion"
                        :opciones="opcionesModo"
                    />
                </div>

                <div class="grid gap-1.5">
                    <Label
                        for="condicion-cantidad"
                        class="flex items-center gap-1"
                    >
                        Cantidad
                        <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="condicion-cantidad"
                        v-model.number="form.cantidad"
                        type="number"
                        min="1"
                        step="1"
                    />
                    <InputError :message="form.errors.cantidad" />
                </div>

                <div class="grid gap-1.5">
                    <Label
                        for="condicion-motivo"
                        class="flex items-center gap-1"
                    >
                        Motivo
                        <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="condicion-motivo"
                        v-model="form.motivo"
                        placeholder="p. ej. Se detectó humedad en el almacén"
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
