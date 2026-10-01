<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EmpresaAutorizada } from '@/types/sistema';

const props = defineProps<{
    empresasAutorizadas: EmpresaAutorizada[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inventario físico', href: '/inventarios-fisicos' },
            { title: 'Nueva ronda', href: '/inventarios-fisicos/crear' },
        ],
    },
});

// Una ronda es INTEGRAL por empresa: el backend arma el universo completo
// (existencias por cantidad de sus almacenes + unidades identificadas). No
// hay "alcance" que elegir.
const form = useForm<{
    empresa_id: number | null;
    nombre: string;
    observaciones: string;
}>({
    empresa_id:
        props.empresasAutorizadas.length === 1
            ? props.empresasAutorizadas[0].id
            : null,
    nombre: '',
    observaciones: '',
});

const empresaSel = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.length === 1
        ? props.empresasAutorizadas[0]
        : null,
);

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();
    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

function alElegirEmpresa(e: EmpresaAutorizada | null) {
    empresaSel.value = e;
    form.empresa_id = e?.id ?? null;
}

// Previsualización (no autoritativa) del universo del snapshot: mismas
// definiciones que usa el backend al iniciar la ronda.
const universo = ref<{
    total: number;
    existencias: number;
    almacenes: number;
} | null>(null);
const cargandoUniverso = ref(false);
let universoToken = 0;

watch(
    () => form.empresa_id,
    async () => {
        universo.value = null;
        if (!form.empresa_id) return;
        const token = ++universoToken;
        cargandoUniverso.value = true;
        try {
            const params = new URLSearchParams({
                empresa_id: String(form.empresa_id),
            });
            const res = await fetch(`/inventarios-fisicos/universo?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (token === universoToken)
                universo.value = {
                    total: data.total ?? 0,
                    existencias: data.existencias ?? 0,
                    almacenes: data.almacenes ?? 0,
                };
        } catch {
            if (token === universoToken) universo.value = null;
        } finally {
            if (token === universoToken) cargandoUniverso.value = false;
        }
    },
    { immediate: true },
);

function sugerirNombre(): void {
    const mes = new Date().toLocaleDateString('es-MX', {
        month: 'long',
        year: 'numeric',
    });
    const emp = empresaSel.value?.nombre_comercial;
    form.nombre = `Inventario físico ${mes}${emp ? ` – ${emp}` : ''}`;
}

function enviar(): void {
    form.post('/inventarios-fisicos');
}
</script>

<template>
    <Head title="Nueva ronda de inventario físico" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Nueva ronda de inventario físico"
            descripcion="Una sola ronda por empresa: incluye las existencias por cantidad de todos sus almacenes (cada renglón con su almacén) y todas sus unidades identificadas, estén en almacén o asignadas a alguien. Varios encargados pueden trabajarla al mismo tiempo. Al iniciar se congela ese universo — lo que cambie después no altera la ronda."
        />

        <form class="flex flex-col gap-5" @submit.prevent="enviar">
            <div class="grid gap-1.5">
                <Label>Empresa</Label>
                <BuscadorAsync
                    :model-value="empresaSel"
                    :buscar="buscarEmpresas"
                    :etiqueta="(e) => String(e.nombre_comercial)"
                    :disabled="empresasAutorizadas.length <= 1"
                    placeholder="Selecciona la empresa"
                    placeholder-busqueda="Buscar empresa…"
                    :invalido="!!form.errors.empresa_id"
                    @update:model-value="
                        (v) => alElegirEmpresa(v as EmpresaAutorizada | null)
                    "
                />
                <InputError :message="form.errors.empresa_id" />
            </div>

            <div class="grid gap-1.5">
                <Label for="nombre">Nombre de la ronda</Label>
                <div class="flex gap-2">
                    <Input
                        id="nombre"
                        v-model="form.nombre"
                        placeholder="Ej. Inventario diciembre 2026 – DASTI"
                        :aria-invalid="!!form.errors.nombre"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        @click="sugerirNombre"
                    >
                        Sugerir
                    </Button>
                </div>
                <InputError :message="form.errors.nombre" />
            </div>

            <div class="grid gap-1.5">
                <Label for="observaciones">Observaciones (opcional)</Label>
                <textarea
                    id="observaciones"
                    v-model="form.observaciones"
                    rows="3"
                    class="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-base focus-visible:ring-1 focus-visible:outline-none md:text-sm"
                ></textarea>
                <InputError :message="form.errors.observaciones" />
            </div>

            <div
                class="bg-muted/40 rounded-lg border p-3 text-sm"
                aria-live="polite"
            >
                <template v-if="!form.empresa_id">
                    Elige la empresa para ver qué entrará en la ronda.
                </template>
                <template v-else-if="cargandoUniverso">Calculando…</template>
                <template v-else-if="universo !== null">
                    <p>Se incluirán en el snapshot inicial:</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5">
                        <li>
                            <strong class="tabular-nums">{{
                                universo.total
                            }}</strong>
                            unidad(es) identificada(s) — en almacén, asignadas,
                            en reparación o inservibles.
                        </li>
                        <li>
                            <strong class="tabular-nums">{{
                                universo.existencias
                            }}</strong>
                            renglón(es) de artículos por cantidad, en
                            <strong class="tabular-nums">{{
                                universo.almacenes
                            }}</strong>
                            almacén(es).
                        </li>
                    </ul>
                    <p class="text-muted-foreground mt-1 text-xs">
                        No se esperan unidades perdidas, robadas ni dadas de
                        baja.
                    </p>
                </template>
                <template v-else>
                    No se pudo calcular el universo ahora; podrás iniciar la
                    ronda igualmente.
                </template>
            </div>

            <div class="flex justify-end gap-2">
                <Button type="button" variant="ghost" as-child>
                    <a href="/inventarios-fisicos">Cancelar</a>
                </Button>
                <Button
                    type="submit"
                    :disabled="
                        form.processing ||
                        !form.empresa_id ||
                        !form.nombre.trim()
                    "
                >
                    Iniciar ronda
                </Button>
            </div>
        </form>
    </div>
</template>
