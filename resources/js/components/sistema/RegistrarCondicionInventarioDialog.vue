<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
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

type OpcionAlmacen = { id: number; nombre: string; codigo: string | null };
type OpcionVariante = { id: number; valor: string };
type Modo = 'danado' | 'baja' | 'restaurar';

const props = defineProps<{
    open: boolean;
    activoId: number;
    empresaId: number;
    usaVariantes: boolean;
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();

const modo = ref<Modo>('danado');
const opcionesModo = [
    { valor: 'danado', etiqueta: 'Marcar como dañado' },
    { valor: 'baja', etiqueta: 'Dar de baja' },
    { valor: 'restaurar', etiqueta: 'Restaurar dañado a disponible' },
];

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
    cantidad: 1,
    motivo: '',
});

const almacenSel = ref<OpcionAlmacen | null>(null);
const tallaSel = ref<OpcionVariante | null>(null);

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
            modo.value = 'danado';
            form.reset();
            form.clearErrors();
            almacenSel.value = null;
            tallaSel.value = null;
        }
    },
);

const tituloDialogo = computed(() => {
    if (modo.value === 'restaurar') return 'Restaurar condición';
    return 'Registrar condición';
});

function enviar(): void {
    // Reutiliza `MarcarCondicionInventario` / `RestaurarCondicionInventario`
    // (backend) — este diálogo sólo envía al endpoint correspondiente, nunca
    // toca `saldos_inventario` directamente.
    if (modo.value === 'restaurar') {
        form.post('/inventario/condicion/restaurar', {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        });
        return;
    }

    form
        .transform((datos) => ({ ...datos, condicion: modo.value }))
        .post('/inventario/condicion', {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>{{ tituloDialogo }}</DialogTitle>
                <DialogDescription>
                    <template v-if="modo === 'restaurar'">
                        Regresa a "Disponible" piezas que se habían marcado
                        como dañadas (se repararon o el conteo estaba mal).
                        Nunca aplica a piezas dadas de baja.
                    </template>
                    <template v-else>
                        Marca piezas que físicamente ya no están en
                        condiciones de entregarse. Resta de "Disponible" y
                        queda trazable — nunca desaparecen del sistema.
                    </template>
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="enviar">
                <div class="grid gap-1.5">
                    <Label for="condicion-modo">Acción</Label>
                    <SelectSimple
                        id="condicion-modo"
                        v-model="modo"
                        :opciones="opcionesModo"
                    />
                </div>

                <div class="grid gap-1.5">
                    <Label for="condicion-almacen">Almacén</Label>
                    <BuscadorAsync
                        id="condicion-almacen"
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
                    <Label for="condicion-variante">Variante / talla</Label>
                    <BuscadorAsync
                        id="condicion-variante"
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

                <div class="grid gap-1.5">
                    <Label for="condicion-cantidad">Cantidad</Label>
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
                    <Label for="condicion-motivo">Motivo</Label>
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
