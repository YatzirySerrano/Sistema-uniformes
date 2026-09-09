<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EmpresaAutorizada } from '@/types/sistema';

export type ContratoEditable = {
    id: number;
    nombre: string;
    codigo: string | null;
    descripcion: string | null;
    fecha_inicio: string | null;
    fecha_fin: string | null;
    empresa_id?: number;
};

const props = withDefaults(
    defineProps<{
        contrato: ContratoEditable | null;
        // Sólo se necesita en alta (el selector se oculta al editar).
        empresasAutorizadas?: EmpresaAutorizada[];
    }>(),
    { empresasAutorizadas: () => [] },
);
const emit = defineEmits<{ (e: 'guardado'): void; (e: 'cancelar'): void }>();

const esEdicion = computed(() => props.contrato !== null);

const form = useForm<{
    empresa_id: number | null;
    nombre: string;
    descripcion: string;
    fecha_inicio: string;
    fecha_fin: string;
}>({
    empresa_id:
        props.contrato?.empresa_id ??
        (props.empresasAutorizadas.length === 1
            ? props.empresasAutorizadas[0].id
            : null),
    nombre: props.contrato?.nombre ?? '',
    descripcion: props.contrato?.descripcion ?? '',
    fecha_inicio: props.contrato?.fecha_inicio ?? '',
    fecha_fin: props.contrato?.fecha_fin ?? '',
});

const empresaSel = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === form.empresa_id) ?? null,
);
async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}
watch(empresaSel, (e) => {
    form.empresa_id = e?.id ?? null;
});

const tocado = reactive<Record<string, boolean>>({});
function marcar(campo: string): void {
    tocado[campo] = true;
}

const erroresLocales = computed<Record<string, string>>(() => {
    const e: Record<string, string> = {};

    if (!esEdicion.value && tocado.empresa_id && form.empresa_id === null) {
        e.empresa_id = 'Selecciona la empresa del contrato.';
    }
    if (tocado.nombre && form.nombre.trim() === '') {
        e.nombre = 'El nombre del contrato es obligatorio.';
    } else if (form.nombre.length > 255) {
        e.nombre = 'Máximo 255 caracteres.';
    }
    if (
        form.fecha_inicio &&
        form.fecha_fin &&
        form.fecha_fin < form.fecha_inicio
    ) {
        e.fecha_fin = 'No puede ser anterior a la fecha de inicio.';
    }
    return e;
});

function error(campo: string): string | undefined {
    return (
        (form.errors as Record<string, string>)[campo] ??
        erroresLocales.value[campo]
    );
}

const hayErroresLocales = computed(
    () => Object.keys(erroresLocales.value).length > 0,
);

// --- Código de contrato: lo genera el backend, nunca lo escribe el usuario.
const codigoPreview = ref<string | null>(props.contrato?.codigo ?? null);
const cargandoPreview = ref(false);
let controladorPreview: AbortController | undefined;
let temporizadorPreview: ReturnType<typeof setTimeout> | undefined;

async function actualizarPreview(): Promise<void> {
    if (form.empresa_id === null) {
        codigoPreview.value = null;
        cargandoPreview.value = false;
        return;
    }

    controladorPreview?.abort();
    controladorPreview = new AbortController();
    cargandoPreview.value = true;

    try {
        const res = await fetch(
            `/contratos/siguiente-codigo?empresa_id=${form.empresa_id}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controladorPreview.signal,
            },
        );
        if (!res.ok) return;
        codigoPreview.value = (await res.json()).codigo ?? null;
    } catch {
        // Petición abortada o de red: se ignora.
    } finally {
        cargandoPreview.value = false;
    }
}

if (!esEdicion.value) {
    watch(
        () => form.empresa_id,
        () => {
            clearTimeout(temporizadorPreview);
            temporizadorPreview = setTimeout(actualizarPreview, 300);
        },
        { immediate: true },
    );
}

onBeforeUnmount(() => {
    clearTimeout(temporizadorPreview);
    controladorPreview?.abort();
});

function enviar(): void {
    tocado.nombre = true;
    tocado.empresa_id = true;
    if (form.nombre.trim() === '') return;
    if (!esEdicion.value && form.empresa_id === null) return;
    if (hayErroresLocales.value) return;

    const opciones = {
        preserveScroll: true,
        onSuccess: () => emit('guardado'),
    };

    if (esEdicion.value) {
        form.put(`/contratos/${props.contrato!.id}`, opciones);
    } else {
        form.post('/contratos', opciones);
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="enviar">
        <div class="grid gap-4 sm:grid-cols-2">
            <div v-if="!esEdicion" class="grid gap-1.5 sm:col-span-2">
                <Label for="cf-empresa" class="flex items-center gap-1.5">
                    Empresa
                    <span class="text-destructive">*</span>
                    <AyudaTooltip
                        texto="Razón social a la que pertenece el contrato."
                        etiqueta="Ayuda sobre la empresa"
                    />
                </Label>
                <div @focusout="marcar('empresa_id')">
                    <BuscadorAsync
                        id="cf-empresa"
                        v-model="empresaSel"
                        :buscar="buscarEmpresas"
                        :etiqueta="(e) => String(e.nombre_comercial)"
                        :invalido="!!error('empresa_id')"
                        placeholder="Selecciona una empresa"
                        placeholder-busqueda="Buscar empresa…"
                    />
                </div>
                <InputError :message="error('empresa_id')" />
            </div>

            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="cf-nombre" class="flex items-center gap-1.5">
                    Nombre
                    <span class="text-destructive">*</span>
                    <AyudaTooltip
                        texto="Nombre comercial del contrato (p. ej. Laboratorios Clínicos Polab)."
                        etiqueta="Ayuda sobre el nombre"
                    />
                </Label>
                <Input
                    id="cf-nombre"
                    v-model="form.nombre"
                    required
                    maxlength="255"
                    @blur="marcar('nombre')"
                />
                <InputError :message="error('nombre')" />
            </div>

            <div class="grid gap-1.5">
                <Label for="cf-codigo" class="flex items-center gap-1.5">
                    Código
                    <AyudaTooltip
                        texto="Lo genera el sistema automáticamente (CON-0001). No se puede escribir ni editar."
                        etiqueta="Ayuda sobre el código"
                    />
                </Label>
                <div
                    id="cf-codigo"
                    class="bg-muted/50 text-muted-foreground flex h-9 items-center rounded-md border px-3 font-mono text-sm"
                >
                    <span v-if="codigoPreview" class="text-foreground">{{
                        codigoPreview
                    }}</span>
                    <span v-else-if="cargandoPreview">Calculando…</span>
                    <span v-else class="italic"
                        >Se generará automáticamente</span
                    >
                </div>
                <p class="text-muted-foreground text-xs">
                    {{
                        esEdicion
                            ? 'Asignado al crear el contrato; no se puede modificar.'
                            : 'Selecciona la empresa para ver el código que se asignará al guardar.'
                    }}
                </p>
            </div>

            <div class="grid grid-cols-2 gap-4 sm:col-span-2 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="cf-fecha-inicio">Fecha de inicio</Label>
                    <Input
                        id="cf-fecha-inicio"
                        v-model="form.fecha_inicio"
                        type="date"
                    />
                    <InputError :message="error('fecha_inicio')" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="cf-fecha-fin">Fecha de fin</Label>
                    <Input
                        id="cf-fecha-fin"
                        v-model="form.fecha_fin"
                        type="date"
                    />
                    <InputError :message="error('fecha_fin')" />
                </div>
            </div>

            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="cf-descripcion" class="flex items-center gap-1.5">
                    Descripción
                    <AyudaTooltip
                        texto="Detalle opcional sobre el contrato. Se muestra en su detalle."
                        etiqueta="Ayuda sobre la descripción"
                    />
                </Label>
                <textarea
                    id="cf-descripcion"
                    v-model="form.descripcion"
                    rows="3"
                    maxlength="1000"
                    class="border-input bg-background focus-visible:ring-ring min-h-[72px] rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
                ></textarea>
                <InputError :message="error('descripcion')" />
            </div>
        </div>

        <div class="flex items-center justify-end gap-2 pt-1">
            <Button
                type="button"
                variant="ghost"
                :disabled="form.processing"
                @click="emit('cancelar')"
            >
                Cancelar
            </Button>
            <Button
                type="submit"
                :disabled="form.processing || hayErroresLocales"
            >
                {{ esEdicion ? 'Guardar cambios' : 'Registrar contrato' }}
            </Button>
        </div>
    </form>
</template>
