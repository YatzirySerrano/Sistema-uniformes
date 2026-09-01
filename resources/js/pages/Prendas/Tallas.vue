<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{
    tallas: { id: number; valor: string; orden: number; activa: boolean }[];
    puedeAdministrar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Prendas', href: '/prendas' },
            { title: 'Tallas', href: '/tallas' },
        ],
    },
});

const form = useForm({ valor: '', orden: 0 });

function agregar() {
    form.post('/tallas', { onSuccess: () => form.reset() });
}

function actualizar(t: {
    id: number;
    valor: string;
    orden: number;
    activa: boolean;
}) {
    router.put(`/tallas/${t.id}`, t, { preserveScroll: true });
}

function eliminar(id: number) {
    router.delete(`/tallas/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Tallas" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Tallas"
            descripcion="Configura las tallas disponibles para las prendas. Admite letras y numeración."
        />

        <form
            v-if="puedeAdministrar"
            class="flex items-end gap-2"
            @submit.prevent="agregar"
        >
            <div class="grid gap-1.5">
                <Label for="valor">Nueva talla</Label>
                <Input
                    id="valor"
                    v-model="form.valor"
                    placeholder="M, 32, ..."
                    class="w-32"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="orden">Orden</Label>
                <Input
                    id="orden"
                    v-model.number="form.orden"
                    type="number"
                    class="w-24"
                />
            </div>
            <Button type="submit" :disabled="form.processing">Agregar</Button>
        </form>
        <InputError :message="form.errors.valor" />

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Talla</th>
                        <th class="px-3 py-2 font-medium">Orden</th>
                        <th class="px-3 py-2 font-medium">Activa</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in tallas" :key="t.id" class="border-t">
                        <td class="px-3 py-2">
                            <Input
                                v-model="t.valor"
                                :disabled="!puedeAdministrar"
                                class="h-8 w-24"
                                @blur="actualizar(t)"
                            />
                        </td>
                        <td class="px-3 py-2">
                            <Input
                                v-model.number="t.orden"
                                :disabled="!puedeAdministrar"
                                type="number"
                                class="h-8 w-20"
                                @blur="actualizar(t)"
                            />
                        </td>
                        <td class="px-3 py-2">
                            <input
                                v-model="t.activa"
                                type="checkbox"
                                :disabled="!puedeAdministrar"
                                class="size-4"
                                @change="actualizar(t)"
                            />
                        </td>
                        <td class="px-3 py-2 text-right">
                            <Button
                                v-if="puedeAdministrar"
                                variant="ghost"
                                size="icon-sm"
                                @click="eliminar(t.id)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="!tallas.length">
                        <td
                            colspan="4"
                            class="text-muted-foreground px-3 py-6 text-center"
                        >
                            No hay tallas registradas.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
