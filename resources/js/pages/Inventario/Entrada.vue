<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import SelectorItemsPrendas from '@/components/sistema/SelectorItemsPrendas.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { PrendaOpcion } from '@/types/sistema';

defineProps<{
    sucursales: { id: number; nombre: string }[];
    prendas: PrendaOpcion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inventario', href: '/inventario' },
            { title: 'Entrada', href: '/inventario/entrada' },
        ],
    },
});

const form = useForm<{
    sucursal_id: number | string;
    motivo: string;
    notas: string;
    carga_inicial: boolean;
    items: {
        prenda_id: number | null;
        talla_id: number | null;
        cantidad: number;
    }[];
}>({
    sucursal_id: '',
    motivo: '',
    notas: '',
    carga_inicial: false,
    items: [{ prenda_id: null, talla_id: null, cantidad: 1 }],
});

function enviar() {
    form.post('/inventario/entrada');
}
</script>

<template>
    <Head title="Registrar entrada de inventario" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Registrar entrada de inventario"
            descripcion="Suma existencias por recepción de almacén o carga inicial."
        />

        <form class="space-y-5" @submit.prevent="enviar">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="sucursal_id">Sucursal</Label>
                    <select
                        id="sucursal_id"
                        v-model="form.sucursal_id"
                        class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        required
                    >
                        <option value="" disabled>
                            Selecciona una sucursal
                        </option>
                        <option
                            v-for="s in sucursales"
                            :key="s.id"
                            :value="s.id"
                        >
                            {{ s.nombre }}
                        </option>
                    </select>
                    <InputError :message="form.errors.sucursal_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="motivo">Motivo / referencia</Label>
                    <Input id="motivo" v-model="form.motivo" required />
                    <InputError :message="form.errors.motivo" />
                </div>
            </div>

            <div class="grid gap-1.5">
                <Label>Prendas</Label>
                <SelectorItemsPrendas v-model="form.items" :prendas="prendas" />
                <InputError :message="form.errors.items" />
            </div>

            <div class="grid gap-1.5">
                <Label for="notas">Notas (opcional)</Label>
                <textarea
                    id="notas"
                    v-model="form.notas"
                    rows="2"
                    class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                />
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input
                    v-model="form.carga_inicial"
                    type="checkbox"
                    class="size-4"
                />
                Marcar como carga inicial
            </label>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing"
                    >Registrar entrada</Button
                >
                <Button variant="ghost" as-child>
                    <Link href="/inventario">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
