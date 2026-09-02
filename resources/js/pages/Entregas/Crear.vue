<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Entregas', href: '/entregas' },
            { title: 'Nueva entrega', href: '/entregas/crear' },
        ],
    },
});

const hoy = new Date().toISOString().slice(0, 10);

const form = useForm<{
    sucursal_id: number | string;
    colaborador_id: number | string;
    fecha_entrega: string;
    notas: string;
    items: {
        activo_id: number | null;
        talla_id: number | null;
        cantidad: number;
    }[];
}>({
    sucursal_id: '',
    colaborador_id: '',
    fecha_entrega: hoy,
    notas: '',
    items: [{ activo_id: null, talla_id: null, cantidad: 1 }],
});

const colaboradoresFiltrados = computed(() =>
    form.sucursal_id
        ? props.colaboradores.filter(
              (c) => c.sucursal_id === Number(form.sucursal_id),
          )
        : props.colaboradores,
);

const disponibles = ref<Record<string, number>>({});

watch(
    () => form.sucursal_id,
    async (id) => {
        form.colaborador_id = '';
        disponibles.value = {};
        if (!id) return;
        const res = await window.fetch(
            `/entregas/disponibilidad?sucursal_id=${id}`,
            { headers: { Accept: 'application/json' } },
        );
        if (!res.ok) return;
        const json = (await res.json()) as {
            saldos: {
                activo_id: number;
                talla_id: number;
                disponible: number;
            }[];
        };
        const mapa: Record<string, number> = {};
        for (const s of json.saldos) {
            mapa[`${s.activo_id}-${s.talla_id}`] = s.disponible;
        }
        disponibles.value = mapa;
    },
);

function enviar() {
    form.post('/entregas');
}
</script>

<template>
    <Head title="Nueva entrega" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Registrar entrega"
            descripcion="Colaborador → activos → confirmar. El inventario se descuenta al registrar."
        />

        <form class="space-y-6" @submit.prevent="enviar">
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
                    <Label for="fecha_entrega">Fecha de entrega</Label>
                    <Input
                        id="fecha_entrega"
                        v-model="form.fecha_entrega"
                        type="date"
                        :max="hoy"
                        required
                    />
                    <InputError :message="form.errors.fecha_entrega" />
                </div>
            </div>

            <div class="grid gap-1.5">
                <Label for="colaborador_id">Colaborador</Label>
                <select
                    id="colaborador_id"
                    v-model="form.colaborador_id"
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    :disabled="!form.sucursal_id"
                    required
                >
                    <option value="" disabled>
                        {{
                            form.sucursal_id
                                ? 'Selecciona un colaborador'
                                : 'Primero elige una sucursal'
                        }}
                    </option>
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
                <Label>Activos a entregar</Label>
                <SelectorItemsActivos
                    v-model="form.items"
                    :activos="activos"
                    :disponibles="disponibles"
                />
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

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    Registrar entrega
                </Button>
                <Button variant="ghost" as-child>
                    <Link href="/entregas">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
