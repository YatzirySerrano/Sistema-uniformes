<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import SelectorItemsActivos from '@/components/sistema/SelectorItemsActivos.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ActivoOpcion } from '@/types/sistema';

const props = defineProps<{
    sucursales: { id: number; nombre: string }[];
    colaboradores: {
        id: number;
        nombre_completo: string;
        numero_empleado: string;
        sucursal_id: number;
    }[];
    activos: ActivoOpcion[];
    condiciones: { valor: string; etiqueta: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Devoluciones', href: '/devoluciones' },
            { title: 'Nueva devolución', href: '/devoluciones/crear' },
        ],
    },
});

const hoy = new Date().toISOString().slice(0, 10);

const form = useForm<{
    sucursal_id: number | string;
    colaborador_id: number | string;
    entrega_uniforme_id: string;
    fecha: string;
    motivo: string;
    notas: string;
    items: {
        activo_id: number | null;
        talla_id: number | null;
        cantidad: number;
        condicion: string;
    }[];
}>({
    sucursal_id: '',
    colaborador_id: '',
    entrega_uniforme_id: '',
    fecha: hoy,
    motivo: '',
    notas: '',
    items: [
        {
            activo_id: null,
            talla_id: null,
            cantidad: 1,
            condicion: 'reutilizable',
        },
    ],
});

const colaboradoresFiltrados = computed(() =>
    form.sucursal_id
        ? props.colaboradores.filter(
              (c) => c.sucursal_id === Number(form.sucursal_id),
          )
        : props.colaboradores,
);

function enviar() {
    form.post('/devoluciones');
}
</script>

<template>
    <Head title="Nueva devolución" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Registrar devolución"
            descripcion="Indica la condición de cada activo. Reutilizable reingresa al inventario; dañado o baja, no."
        />

        <form class="space-y-6" @submit.prevent="enviar">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-1.5">
                    <Label for="sucursal_id">Sucursal</Label>
                    <select
                        id="sucursal_id"
                        v-model="form.sucursal_id"
                        class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        required
                    >
                        <option value="" disabled>Selecciona</option>
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
                    <Label for="colaborador_id">Colaborador</Label>
                    <select
                        id="colaborador_id"
                        v-model="form.colaborador_id"
                        :disabled="!form.sucursal_id"
                        class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        required
                    >
                        <option value="" disabled>Selecciona</option>
                        <option
                            v-for="c in colaboradoresFiltrados"
                            :key="c.id"
                            :value="c.id"
                        >
                            {{ c.numero_empleado }} — {{ c.nombre_completo }}
                        </option>
                    </select>
                    <InputError :message="form.errors.colaborador_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="fecha">Fecha</Label>
                    <Input
                        id="fecha"
                        v-model="form.fecha"
                        type="date"
                        :max="hoy"
                        required
                    />
                    <InputError :message="form.errors.fecha" />
                </div>
            </div>

            <div class="grid gap-1.5">
                <Label>Activos devueltas</Label>
                <SelectorItemsActivos
                    v-model="form.items"
                    :activos="activos"
                    con-condicion
                    :condiciones="condiciones"
                />
                <InputError :message="form.errors.items" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="motivo">Motivo (opcional)</Label>
                    <Input id="motivo" v-model="form.motivo" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="notas">Notas (opcional)</Label>
                    <Input id="notas" v-model="form.notas" />
                </div>
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing"
                    >Registrar devolución</Button
                >
                <Button variant="ghost" as-child>
                    <Link href="/devoluciones">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
