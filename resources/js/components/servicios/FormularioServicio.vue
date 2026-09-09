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

type ContratoOpcion = { id: number; nombre: string; codigo?: string };
type SucursalOpcion = { id: number; nombre: string };

export type ServicioEditable = {
    id: number;
    nombre: string;
    codigo: string | null;
    direccion: string | null;
    descripcion: string | null;
    contrato: ContratoOpcion;
    sucursal: SucursalOpcion;
    empresa: { id: number; nombre_comercial: string };
};

const props = withDefaults(
    defineProps<{
        servicio: ServicioEditable | null;
        empresasAutorizadas?: EmpresaAutorizada[];
    }>(),
    { empresasAutorizadas: () => [] },
);
const emit = defineEmits<{ (e: 'guardado'): void; (e: 'cancelar'): void }>();

const esEdicion = computed(() => props.servicio !== null);

const form = useForm<{
    contrato_id: number | null;
    sucursal_id: number | null;
    nombre: string;
    direccion: string;
    descripcion: string;
}>({
    contrato_id: props.servicio?.contrato.id ?? null,
    sucursal_id: props.servicio?.sucursal.id ?? null,
    nombre: props.servicio?.nombre ?? '',
    direccion: props.servicio?.direccion ?? '',
    descripcion: props.servicio?.descripcion ?? '',
});

// Empresa es sólo un FILTRO local para acotar Contrato/Sucursal — nunca se
// envía al backend (`Servicio` no tiene `empresa_id` propio, se deriva del
// contrato). Se preselecciona al editar (empresa del servicio existente) o
// si sólo hay una empresa autorizada.
const empresaIdInicial =
    props.servicio?.empresa.id ??
    (props.empresasAutorizadas.length === 1
        ? props.empresasAutorizadas[0].id
        : null);
const empresaSel = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === empresaIdInicial) ?? null,
);
async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

const contratoSel = ref<ContratoOpcion | null>(
    props.servicio?.contrato ?? null,
);
const sucursalSel = ref<SucursalOpcion | null>(
    props.servicio?.sucursal ?? null,
);

watch(contratoSel, (c) => {
    form.contrato_id = c?.id ?? null;
});
watch(sucursalSel, (s) => {
    form.sucursal_id = s?.id ?? null;
});

// Cambiar la empresa limpia Contrato/Sucursal — ambos dependen de ella y
// nunca deben quedar mostrando una opción de la empresa anterior. `watch`
// sin `immediate` no dispara con el valor inicial, sólo ante cambios reales
// del usuario, así que la preselección de arriba nunca se limpia sola.
watch(empresaSel, () => {
    contratoSel.value = null;
    sucursalSel.value = null;
});

async function buscarContratos(termino: string, signal?: AbortSignal) {
    if (empresaSel.value === null) return [];

    const params = new URLSearchParams({
        empresa_id: String(empresaSel.value.id),
        q: termino,
    });
    const res = await fetch(`/contratos/buscar?${params}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];

    return ((await res.json()).contratos ?? []) as ContratoOpcion[];
}

async function buscarSucursales(termino: string, signal?: AbortSignal) {
    if (empresaSel.value === null) return [];

    const params = new URLSearchParams({
        empresa_id: String(empresaSel.value.id),
        q: termino,
    });
    const res = await fetch(`/sucursales/buscar?${params}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];

    return ((await res.json()).sucursales ?? []) as SucursalOpcion[];
}

const tocado = reactive<Record<string, boolean>>({});
function marcar(campo: string): void {
    tocado[campo] = true;
}

const erroresLocales = computed<Record<string, string>>(() => {
    const e: Record<string, string> = {};

    if (tocado.empresa_id && empresaSel.value === null) {
        e.empresa_id = 'Selecciona la empresa para acotar contrato y sucursal.';
    }
    if (tocado.contrato_id && form.contrato_id === null) {
        e.contrato_id = 'Selecciona el contrato del servicio.';
    }
    if (tocado.sucursal_id && form.sucursal_id === null) {
        e.sucursal_id = 'Selecciona la sucursal responsable del servicio.';
    }
    if (tocado.nombre && form.nombre.trim() === '') {
        e.nombre = 'El nombre del servicio es obligatorio.';
    } else if (form.nombre.length > 255) {
        e.nombre = 'Máximo 255 caracteres.';
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

// --- Código de servicio: lo genera el backend (único de plataforma), nunca
// lo escribe el usuario. No depende de la empresa (generador global).
const codigoPreview = ref<string | null>(props.servicio?.codigo ?? null);
const cargandoPreview = ref(false);
let controladorPreview: AbortController | undefined;

async function actualizarPreview(): Promise<void> {
    controladorPreview?.abort();
    controladorPreview = new AbortController();
    cargandoPreview.value = true;

    try {
        const res = await fetch('/servicios/siguiente-codigo', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controladorPreview.signal,
        });
        if (!res.ok) return;
        codigoPreview.value = (await res.json()).codigo ?? null;
    } catch {
        // Petición abortada o de red: se ignora.
    } finally {
        cargandoPreview.value = false;
    }
}

if (!esEdicion.value) {
    void actualizarPreview();
}

onBeforeUnmount(() => {
    controladorPreview?.abort();
});

function enviar(): void {
    tocado.empresa_id = true;
    tocado.contrato_id = true;
    tocado.sucursal_id = true;
    tocado.nombre = true;
    if (hayErroresLocales.value) return;

    const opciones = {
        preserveScroll: true,
        onSuccess: () => emit('guardado'),
    };

    if (esEdicion.value) {
        form.put(`/servicios/${props.servicio!.id}`, opciones);
    } else {
        form.post('/servicios', opciones);
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="enviar">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="sf-empresa" class="flex items-center gap-1.5">
                    Empresa
                    <span class="text-destructive">*</span>
                    <AyudaTooltip
                        texto="Sólo filtra los contratos y sucursales disponibles abajo; el servicio no guarda la empresa directamente, se deriva del contrato."
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

            <div class="grid gap-1.5">
                <Label for="sf-contrato" class="flex items-center gap-1.5">
                    Contrato
                    <span class="text-destructive">*</span>
                    <AyudaTooltip
                        texto="Contrato comercial del que depende este servicio."
                        etiqueta="Ayuda sobre el contrato"
                    />
                </Label>
                <div @focusout="marcar('contrato_id')">
                    <BuscadorAsync
                        id="sf-contrato"
                        v-model="contratoSel"
                        :buscar="buscarContratos"
                        :etiqueta="(c) => String(c.nombre)"
                        :dependencia="empresaSel?.id ?? null"
                        :disabled="empresaSel === null"
                        :invalido="!!error('contrato_id')"
                        placeholder="Selecciona un contrato"
                        placeholder-busqueda="Buscar contrato…"
                        sin-resultados="No hay contratos activos para esta empresa."
                    />
                </div>
                <InputError :message="error('contrato_id')" />
            </div>

            <div class="grid gap-1.5">
                <Label for="sf-sucursal" class="flex items-center gap-1.5">
                    Sucursal responsable
                    <span class="text-destructive">*</span>
                    <AyudaTooltip
                        texto="Sucursal administrativa que respalda este servicio. No tiene que coincidir con la sucursal de los colaboradores asignados: un guardia de Sucursal Cuernavaca puede trabajar en un servicio anclado a otra sucursal."
                        etiqueta="Ayuda sobre la sucursal"
                    />
                </Label>
                <div @focusout="marcar('sucursal_id')">
                    <BuscadorAsync
                        id="sf-sucursal"
                        v-model="sucursalSel"
                        :buscar="buscarSucursales"
                        :etiqueta="(s) => String(s.nombre)"
                        :dependencia="empresaSel?.id ?? null"
                        :disabled="empresaSel === null"
                        :invalido="!!error('sucursal_id')"
                        placeholder="Selecciona una sucursal"
                        placeholder-busqueda="Buscar sucursal…"
                    />
                </div>
                <InputError :message="error('sucursal_id')" />
            </div>

            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="sf-nombre" class="flex items-center gap-1.5">
                    Nombre
                    <span class="text-destructive">*</span>
                    <AyudaTooltip
                        texto="Nombre del servicio o puesto operativo (p. ej. Polab Cuernavaca, Acceso principal)."
                        etiqueta="Ayuda sobre el nombre"
                    />
                </Label>
                <Input
                    id="sf-nombre"
                    v-model="form.nombre"
                    required
                    maxlength="255"
                    @blur="marcar('nombre')"
                />
                <InputError :message="error('nombre')" />
            </div>

            <div class="grid gap-1.5">
                <Label for="sf-codigo" class="flex items-center gap-1.5">
                    Código
                    <AyudaTooltip
                        texto="Lo genera el sistema automáticamente (SER-0001), único en toda la plataforma. No se puede escribir ni editar."
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
            </div>

            <div class="grid gap-1.5">
                <Label for="sf-direccion">Dirección</Label>
                <Input
                    id="sf-direccion"
                    v-model="form.direccion"
                    maxlength="255"
                />
                <InputError :message="error('direccion')" />
            </div>

            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="sf-descripcion">Descripción</Label>
                <textarea
                    id="sf-descripcion"
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
                {{ esEdicion ? 'Guardar cambios' : 'Registrar servicio' }}
            </Button>
        </div>
    </form>
</template>
