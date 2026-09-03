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
    colaborador_id: number | string;
    fecha_entrega: string;
    notas: string;
    items: {
        activo_id: number | null;
        talla_id: number | null;
        cantidad: number;
    }[];
}>({
    colaborador_id: '',
    fecha_entrega: hoy,
    notas: '',
    items: [{ activo_id: null, talla_id: null, cantidad: 1 }],
});

const colaboradorSel = computed<ColaboradorOpcion | null>(
    () =>
        props.colaboradores.find((c) => c.id === Number(form.colaborador_id)) ??
        null,
);

// La empresa y la sucursal se DERIVAN del colaborador (contexto, no dimensión
// de inventario). Los activos disponibles son los de esa empresa.
const activos = computed<ActivoOpcion[]>(() =>
    colaboradorSel.value
        ? (props.activosPorEmpresa[colaboradorSel.value.empresa_id] ?? [])
        : [],
);

const disponibles = ref<Record<string, number>>({});
const almacenOrigen = ref<string | null>(null);

watch(
    () => form.colaborador_id,
    async (id) => {
        disponibles.value = {};
        almacenOrigen.value = null;
        form.items = [{ activo_id: null, talla_id: null, cantidad: 1 }];
        if (!id) return;
        const res = await window.fetch(
            `/entregas/disponibilidad?colaborador_id=${id}`,
            { headers: { Accept: 'application/json' } },
        );
        if (!res.ok) return;
        const json = (await res.json()) as {
            saldos: {
                activo_id: number;
                talla_id: number;
                disponible: number;
            }[];
            almacen?: { id: number; nombre: string };
        };
        const mapa: Record<string, number> = {};
        for (const s of json.saldos) {
            mapa[`${s.activo_id}-${s.talla_id}`] = s.disponible;
        }
        disponibles.value = mapa;
        almacenOrigen.value = json.almacen?.nombre ?? null;
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
            descripcion="Colaborador → activos → confirmar. La empresa y la sucursal se toman del colaborador; el inventario se descuenta del almacén que abastece a esa empresa."
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
                        <option value="" disabled>
                            Selecciona un colaborador
                        </option>
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

            <div
                v-if="colaboradorSel"
                class="bg-muted/40 grid gap-1 rounded-lg p-3 text-sm sm:grid-cols-3"
            >
                <p>
                    <span class="text-muted-foreground text-xs">Empresa</span
                    ><br />{{ colaboradorSel.empresa }}
                </p>
                <p>
                    <span class="text-muted-foreground text-xs"
                        >Sucursal actual</span
                    ><br />{{ colaboradorSel.sucursal }}
                </p>
                <p v-if="almacenOrigen">
                    <span class="text-muted-foreground text-xs"
                        >Almacén de origen</span
                    ><br />{{ almacenOrigen }}
                </p>
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
