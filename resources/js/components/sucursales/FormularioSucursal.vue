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

export type SucursalEditable = {
    id: number;
    codigo: string;
    nombre: string;
    direccion: string | null;
    telefono: string | null;
    empresa_id?: number;
};

const props = withDefaults(
    defineProps<{
        sucursal: SucursalEditable | null;
        // Sólo se necesita en alta (el selector se oculta al editar).
        empresasAutorizadas?: EmpresaAutorizada[];
    }>(),
    { empresasAutorizadas: () => [] },
);
const emit = defineEmits<{ (e: 'guardado'): void; (e: 'cancelar'): void }>();

const esEdicion = computed(() => props.sucursal !== null);

const form = useForm<{
    empresa_id: number | null;
    nombre: string;
    direccion: string;
    telefono: string;
}>({
    empresa_id:
        props.sucursal?.empresa_id ??
        (props.empresasAutorizadas.length === 1
            ? props.empresasAutorizadas[0].id
            : null),
    nombre: props.sucursal?.nombre ?? '',
    direccion: props.sucursal?.direccion ?? '',
    telefono: props.sucursal?.telefono ?? '',
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

/** El teléfono sólo admite dígitos y como máximo 10 (teléfono mexicano). */
function filtrarTelefono(evento: Event): void {
    const objetivo = evento.target as HTMLInputElement;
    const limpio = objetivo.value.replace(/\D+/g, '').slice(0, 10);
    form.telefono = limpio;
    objetivo.value = limpio;
}

const erroresLocales = computed<Record<string, string>>(() => {
    const e: Record<string, string> = {};

    if (!esEdicion.value && tocado.empresa_id && form.empresa_id === null) {
        e.empresa_id = 'Selecciona la empresa de la sucursal.';
    }
    if (tocado.nombre && form.nombre.trim() === '') {
        e.nombre = 'El nombre de la sucursal es obligatorio.';
    } else if (form.nombre.length > 255) {
        e.nombre = 'Máximo 255 caracteres.';
    }

    if (tocado.telefono && form.telefono.trim() !== '') {
        if (form.telefono.replace(/\D+/g, '').length !== 10) {
            e.telefono = 'El teléfono debe contener 10 dígitos.';
        }
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

// --- Código de sucursal: lo genera el backend, nunca lo escribe el usuario.
// Esto sólo previsualiza (no reserva) el código; el valor definitivo se
// calcula y reserva atómicamente al guardar.
const codigoPreview = ref<string | null>(props.sucursal?.codigo ?? null);
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
            `/sucursales/siguiente-codigo?empresa_id=${form.empresa_id}`,
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

    const opciones = {
        preserveScroll: true,
        onSuccess: () => emit('guardado'),
    };

    if (esEdicion.value) {
        form.put(`/sucursales/${props.sucursal!.id}`, opciones);
    } else {
        form.post('/sucursales', opciones);
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="enviar">
        <div class="grid gap-4 sm:grid-cols-2">
            <div v-if="!esEdicion" class="grid gap-1.5 sm:col-span-2">
                <Label for="sf-empresa" class="flex items-center gap-1.5">
                    Empresa
                    <span class="text-destructive">*</span>
                    <AyudaTooltip
                        texto="Razón social a la que pertenece la sucursal."
                        etiqueta="Ayuda sobre la empresa"
                    />
                </Label>
                <div @focusout="marcar('empresa_id')">
                    <BuscadorAsync
                        id="sf-empresa"
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
                <Label for="sf-nombre" class="flex items-center gap-1.5">
                    Nombre
                    <span class="text-destructive">*</span>
                    <AyudaTooltip
                        texto="Nombre con el que se identifica la sucursal (matriz, planta, bodega, etc.)."
                        etiqueta="Ayuda sobre el nombre"
                    />
                </Label>
                <Input
                    id="sf-nombre"
                    v-model="form.nombre"
                    required
                    @blur="marcar('nombre')"
                />
                <InputError :message="error('nombre')" />
            </div>

            <div class="grid gap-1.5">
                <Label for="sf-codigo" class="flex items-center gap-1.5">
                    Código
                    <AyudaTooltip
                        texto="Lo genera el sistema automáticamente (SUC-0001). No se puede escribir ni editar."
                        etiqueta="Ayuda sobre el código"
                    />
                </Label>
                <div
                    id="sf-codigo"
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
                            ? 'Asignado al crear la sucursal; no se puede modificar.'
                            : 'Selecciona la empresa para ver el código que se asignará al guardar.'
                    }}
                </p>
            </div>

            <div class="grid gap-1.5">
                <Label for="sf-telefono" class="flex items-center gap-1.5">
                    Teléfono
                    <AyudaTooltip
                        texto="Teléfono de contacto de la sucursal a 10 dígitos. Se guardan sólo los números."
                        etiqueta="Ayuda sobre el teléfono"
                    />
                </Label>
                <Input
                    id="sf-telefono"
                    v-model="form.telefono"
                    inputmode="numeric"
                    autocomplete="tel"
                    maxlength="10"
                    pattern="\d{10}"
                    placeholder="10 dígitos"
                    @input="filtrarTelefono"
                    @blur="marcar('telefono')"
                />
                <InputError :message="error('telefono')" />
            </div>

            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="sf-direccion" class="flex items-center gap-1.5">
                    Dirección
                    <AyudaTooltip
                        texto="Domicilio de la sucursal. Se usa como referencia en comprobantes y reportes."
                        etiqueta="Ayuda sobre la dirección"
                    />
                </Label>
                <Input
                    id="sf-direccion"
                    v-model="form.direccion"
                    autocomplete="street-address"
                    maxlength="255"
                />
                <InputError :message="error('direccion')" />
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
                {{ esEdicion ? 'Guardar cambios' : 'Registrar sucursal' }}
            </Button>
        </div>
    </form>
</template>
