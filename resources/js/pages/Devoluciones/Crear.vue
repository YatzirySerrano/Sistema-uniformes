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

type ColaboradorOpcion = {
    id: number;
    nombre_completo: string;
    numero_empleado: string;
    empresa_id: number;
    empresa: string | null;
    sucursal_id: number;
    sucursal: string | null;
};

const props = defineProps<{
    colaboradores: ColaboradorOpcion[];
    activosPorEmpresa: Record<number, ActivoOpcion[]>;
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

const colaboradorSel = computed<ColaboradorOpcion | null>(
    () =>
        props.colaboradores.find((c) => c.id === Number(form.colaborador_id)) ??
        null,
);

// La empresa y la sucursal se DERIVAN del colaborador.
const activos = computed<ActivoOpcion[]>(() =>
    colaboradorSel.value
        ? (props.activosPorEmpresa[colaboradorSel.value.empresa_id] ?? [])
        : [],
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
            descripcion="La empresa y la sucursal se toman del colaborador. Reutilizable reingresa al inventario del almacén que la abastece; dañado o baja, no."
        />

        <form class="space-y-6" @submit.prevent="enviar">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="colaborador_id">Colaborador</Label>
                    <select
                        id="colaborador_id"
                        v-model="form.colaborador_id"
                        class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        required
                    >
                        <option value="" disabled>Selecciona</option>
                        <option
                            v-for="c in colaboradores"
                            :key="c.id"
                            :value="c.id"
                        >
                            {{ c.numero_empleado }} —
                            {{ c.nombre_completo }} ({{ c.empresa }})
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

            <p v-if="colaboradorSel" class="bg-muted/40 rounded-lg p-3 text-sm">
                <span class="text-muted-foreground text-xs">Empresa:</span>
                {{ colaboradorSel.empresa }} ·
                <span class="text-muted-foreground text-xs">Sucursal:</span>
                {{ colaboradorSel.sucursal }}
            </p>

            <div class="grid gap-1.5">
                <Label>Activos devueltos</Label>
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
