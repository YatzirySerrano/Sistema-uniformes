<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EmpresaAutorizada } from '@/types/sistema';

type OpcionAlmacen = { id: number; nombre: string };

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

const form = useForm<{
    empresa_id: number | null;
    nombre: string;
    almacen_id: number | null;
    observaciones: string;
}>({
    empresa_id:
        props.empresasAutorizadas.length === 1
            ? props.empresasAutorizadas[0].id
            : null,
    nombre: '',
    almacen_id: null,
    observaciones: '',
});

const empresaSel = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.length === 1
        ? props.empresasAutorizadas[0]
        : null,
);
const almacenSel = ref<OpcionAlmacen | null>(null);

const empresaId = computed(() => form.empresa_id ?? '');

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();
    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

async function buscarAlmacenes(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionAlmacen[]> {
    if (!form.empresa_id) return [];
    const res = await fetch(
        `/almacenes/buscar?empresa_id=${form.empresa_id}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).almacenes ?? [];
}

function alElegirEmpresa(e: EmpresaAutorizada | null) {
    empresaSel.value = e;
    form.empresa_id = e?.id ?? null;
    // La empresa cambia el universo: se limpia el almacén dependiente.
    almacenSel.value = null;
    form.almacen_id = null;
}

function alElegirAlmacen(a: OpcionAlmacen | null) {
    almacenSel.value = a;
    form.almacen_id = a?.id ?? null;
}

// Previsualización (no autoritativa) de cuántas unidades entrarán al snapshot.
const universo = ref<number | null>(null);
const cargandoUniverso = ref(false);
let universoToken = 0;

watch(
    () => [form.empresa_id, form.almacen_id],
    async () => {
        universo.value = null;
        if (!form.empresa_id) return;
        const token = ++universoToken;
        cargandoUniverso.value = true;
        try {
            const params = new URLSearchParams({
                empresa_id: String(form.empresa_id),
            });
            if (form.almacen_id)
                params.set('almacen_id', String(form.almacen_id));
            const res = await fetch(`/inventarios-fisicos/universo?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (token === universoToken) universo.value = data.total ?? 0;
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
            descripcion="Al iniciar la ronda se congela el universo de unidades identificadas esperadas. Las unidades registradas después ya no cuentan como esperadas (aparecen como «no esperadas» si se escanean)."
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
                <Label>Almacén (opcional)</Label>
                <BuscadorAsync
                    :model-value="almacenSel"
                    :buscar="buscarAlmacenes"
                    :etiqueta="(a) => String(a.nombre)"
                    :dependencia="empresaId || ''"
                    :disabled="!form.empresa_id"
                    placeholder="Toda la empresa"
                    placeholder-busqueda="Buscar almacén…"
                    @update:model-value="
                        (v) => alElegirAlmacen(v as OpcionAlmacen | null)
                    "
                />
                <p class="text-muted-foreground text-xs">
                    Déjalo vacío para incluir todas las unidades de la empresa.
                    Con un almacén, la ronda se limita a las unidades cuyo
                    almacén de resguardo es ése (las asignadas a un colaborador
                    conservan su almacén, así que también entran).
                </p>
                <InputError :message="form.errors.almacen_id" />
            </div>

            <div class="grid gap-1.5">
                <Label for="observaciones">Observaciones (opcional)</Label>
                <textarea
                    id="observaciones"
                    v-model="form.observaciones"
                    rows="3"
                    class="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-1 focus-visible:outline-none"
                ></textarea>
                <InputError :message="form.errors.observaciones" />
            </div>

            <div
                class="bg-muted/40 rounded-lg border p-3 text-sm"
                aria-live="polite"
            >
                <template v-if="!form.empresa_id">
                    Elige una empresa para ver cuántas unidades entrarán en la
                    ronda.
                </template>
                <template v-else-if="cargandoUniverso">Calculando…</template>
                <template v-else-if="universo !== null">
                    Se incluirán
                    <strong class="tabular-nums">{{ universo }}</strong>
                    unidad(es) identificada(s) en el snapshot inicial (se
                    excluyen las dadas de baja).
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
