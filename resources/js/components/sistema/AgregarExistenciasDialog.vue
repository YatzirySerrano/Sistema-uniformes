<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type OpcionAlmacen = { id: number; nombre: string; codigo: string | null };
type OpcionVariante = { id: number; valor: string };

const props = defineProps<{
    open: boolean;
    activoId: number;
    empresaId: number;
    usaVariantes: boolean;
    esSeguimientoIndividual: boolean;
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();

const form = useForm<{
    almacen_id: number | null;
    talla_id: number | null;
    cantidad: number;
    motivo: string;
    generar_qr: boolean;
}>({
    almacen_id: null,
    talla_id: null,
    cantidad: 1,
    motivo: '',
    generar_qr: false,
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
    const res = await fetch(`/tallas/buscar?q=${encodeURIComponent(q)}`, {
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
    form.post(`/activos/${props.activoId}/existencias`, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>
                    {{
                        esSeguimientoIndividual
                            ? 'Agregar unidades'
                            : 'Agregar existencias'
                    }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="esSeguimientoIndividual">
                        Genera más unidades para este activo: el sistema crea un
                        código nuevo por cada una.
                    </template>
                    <template v-else>
                        Registra una entrada de inventario para este activo sin
                        salir de su detalle. Queda igual que "Registrar
                        entrada": genera movimiento y auditoría.
                    </template>
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="enviar">
                <div class="grid gap-1.5">
                    <Label for="existencias-almacen">Almacén</Label>
                    <BuscadorAsync
                        id="existencias-almacen"
                        :model-value="almacenSel"
                        :buscar="buscarAlmacenes"
                        :etiqueta="(a) => (a as OpcionAlmacen).nombre"
                        :descripcion="(a) => (a as OpcionAlmacen).codigo ?? ''"
                        placeholder="Selecciona un almacén"
                        placeholder-busqueda="Buscar almacén por nombre"
                        sin-resultados="Este almacén no abastece a la empresa del activo."
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
                    <Label for="existencias-variante">Variante / talla</Label>
                    <BuscadorAsync
                        id="existencias-variante"
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
                    <Label for="existencias-cantidad">
                        {{
                            esSeguimientoIndividual
                                ? 'Cantidad de unidades a agregar'
                                : 'Cantidad'
                        }}
                    </Label>
                    <Input
                        id="existencias-cantidad"
                        v-model.number="form.cantidad"
                        type="number"
                        min="1"
                        step="1"
                    />
                    <InputError :message="form.errors.cantidad" />
                </div>

                <label
                    v-if="esSeguimientoIndividual"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        v-model="form.generar_qr"
                        type="checkbox"
                        class="size-4"
                    />
                    Generar etiquetas QR para estas unidades
                </label>

                <div class="grid gap-1.5">
                    <Label for="existencias-motivo"
                        >Motivo
                        <span class="text-muted-foreground"
                            >(opcional)</span
                        ></Label
                    >
                    <Input
                        id="existencias-motivo"
                        v-model="form.motivo"
                        placeholder="p. ej. Compra, reposición…"
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
                        Agregar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
