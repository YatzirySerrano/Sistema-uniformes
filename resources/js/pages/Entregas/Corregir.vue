<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import SelectorItemsActivos from '@/components/sistema/SelectorItemsActivos.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { ActivoOpcion } from '@/types/sistema';

const props = defineProps<{
    entrega: {
        id: number;
        folio: string;
        estado_etiqueta: string;
        colaborador: {
            nombre_completo: string;
            numero_empleado: string;
        } | null;
        items: {
            activo_id: number;
            talla_id: number;
            activo: string;
            talla: string;
            cantidad: number;
        }[];
    };
    activos: ActivoOpcion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Entregas', href: '/entregas' },
            { title: 'Corregir', href: '#' },
        ],
    },
});

const form = useForm<{
    motivo: string;
    items: {
        activo_id: number | null;
        talla_id: number | null;
        cantidad: number;
    }[];
}>({
    motivo: '',
    items: props.entrega.items.map((i) => ({
        activo_id: i.activo_id,
        talla_id: i.talla_id,
        cantidad: i.cantidad,
    })),
});

function enviar() {
    form.post(`/entregas/${props.entrega.id}/corregir`);
}
</script>

<template>
    <Head :title="`Corregir entrega ${entrega.folio}`" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            :titulo="`Corregir entrega ${entrega.folio}`"
            descripcion="La entrega original y su acuse firmado no se modifican. La corrección compensa el inventario y queda en auditoría."
        />

        <div class="bg-muted/40 rounded-lg border p-3 text-sm">
            <p class="font-medium">Contenido actual</p>
            <ul class="text-muted-foreground mt-1 list-disc pl-5">
                <li v-for="(it, i) in entrega.items" :key="i">
                    {{ it.activo }} · {{ it.talla }} × {{ it.cantidad }}
                </li>
            </ul>
        </div>

        <form class="space-y-5" @submit.prevent="enviar">
            <div class="grid gap-1.5">
                <Label for="motivo">Motivo de la corrección</Label>
                <textarea
                    id="motivo"
                    v-model="form.motivo"
                    rows="3"
                    required
                    class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    placeholder="Explica el motivo. Quedará registrado en la auditoría."
                />
                <InputError :message="form.errors.motivo" />
            </div>

            <div class="grid gap-1.5">
                <Label>Contenido corregido</Label>
                <SelectorItemsActivos v-model="form.items" :activos="activos" />
                <InputError :message="form.errors.items" />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing"
                    >Aplicar corrección</Button
                >
                <Button variant="ghost" as-child>
                    <Link :href="`/entregas/${entrega.id}`">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
