<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import SubidaArchivo from '@/components/sistema/SubidaArchivo.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EmpresaAutorizada } from '@/types/sistema';

type Opcion = { id: number; nombre: string };

export type ColaboradorEditable = {
    id: number;
    empresa_id: number;
    numero_empleado: string;
    nombre_completo: string;
    sucursal_id: number;
    sucursal: Opcion | null;
    puesto: string | null;
    area: string | null;
    area_id: number | null;
    area_actual: Opcion | null;
    correo: string | null;
    foto_url: string | null;
    activo: boolean;
};

const props = withDefaults(
    defineProps<{
        colaborador: ColaboradorEditable | null;
        empresasAutorizadas?: EmpresaAutorizada[];
        sucursalPreseleccionada?: Opcion | null;
        empresaPreseleccionadaId?: number | null;
    }>(),
    { empresasAutorizadas: () => [] },
);

const emit = defineEmits<{ (e: 'guardado'): void; (e: 'cancelar'): void }>();

const esEdicion = computed(() => props.colaborador !== null);

const empresaIdInicial =
    props.colaborador?.empresa_id ??
    props.empresaPreseleccionadaId ??
    (props.empresasAutorizadas.length === 1
        ? props.empresasAutorizadas[0].id
        : '');
const empresaSel = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === empresaIdInicial) ?? null,
);
const empresaId = computed(() => empresaSel.value?.id ?? '');

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

const sucursalSel = ref<Opcion | null>(
    props.colaborador?.sucursal ?? props.sucursalPreseleccionada ?? null,
);
const areaSel = ref<Opcion | null>(props.colaborador?.area_actual ?? null);

const form = useForm<{
    empresa_id: number | null;
    nombre_completo: string;
    sucursal_id: number | '';
    puesto: string;
    area_id: number | '';
    correo: string;
    foto: File | null;
    eliminar_foto: boolean;
    activo: boolean;
}>({
    empresa_id: empresaId.value === '' ? null : empresaId.value,
    nombre_completo: props.colaborador?.nombre_completo ?? '',
    sucursal_id: sucursalSel.value?.id ?? '',
    puesto: props.colaborador?.puesto ?? '',
    area_id: areaSel.value?.id ?? '',
    correo: props.colaborador?.correo ?? '',
    foto: null,
    eliminar_foto: false,
    activo: props.colaborador?.activo ?? true,
});

// Al cambiar de empresa se limpia la selección; sucursal/área se recargan
// acotadas a la empresa elegida (BuscadorAsync, nunca "traer todo y ocultar").
watch(empresaId, (id) => {
    form.empresa_id = id === '' ? null : id;
    form.sucursal_id = '';
    form.area_id = '';
    sucursalSel.value = null;
    areaSel.value = null;
});

async function buscarSucursales(
    q: string,
    signal?: AbortSignal,
): Promise<Opcion[]> {
    if (empresaId.value === '') return [];
    const res = await fetch(
        `/sucursales/buscar?empresa_id=${empresaId.value}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).sucursales ?? [];
}

async function buscarAreas(q: string, signal?: AbortSignal): Promise<Opcion[]> {
    if (empresaId.value === '') return [];
    const res = await fetch(
        `/areas/buscar?empresa_id=${empresaId.value}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).areas ?? [];
}

// --- Número de empleado: lo genera el backend, nunca lo escribe el usuario.
// Esto sólo previsualiza (no reserva) el consecutivo; el valor definitivo se
// calcula y reserva atómicamente al guardar (ver ColaboradorController::store()).
const numeroEmpleadoPreview = ref<string | null>(
    props.colaborador?.numero_empleado ?? null,
);
const cargandoPreview = ref(false);
let controladorPreview: AbortController | undefined;
let temporizadorPreview: ReturnType<typeof setTimeout> | undefined;

async function actualizarPreview(): Promise<void> {
    const nombre = form.nombre_completo.trim();

    if (empresaId.value === '' || nombre === '') {
        numeroEmpleadoPreview.value = null;
        cargandoPreview.value = false;
        return;
    }

    controladorPreview?.abort();
    controladorPreview = new AbortController();
    cargandoPreview.value = true;

    try {
        const res = await fetch(
            `/colaboradores/siguiente-numero?empresa_id=${empresaId.value}&nombre_completo=${encodeURIComponent(nombre)}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controladorPreview.signal,
            },
        );
        if (!res.ok) return;
        numeroEmpleadoPreview.value =
            (await res.json()).numero_empleado ?? null;
    } catch {
        // Petición abortada o de red: se ignora, el usuario puede seguir escribiendo.
    } finally {
        cargandoPreview.value = false;
    }
}

if (!esEdicion.value) {
    watch([empresaId, () => form.nombre_completo], () => {
        clearTimeout(temporizadorPreview);
        temporizadorPreview = setTimeout(actualizarPreview, 400);
    });
}

onBeforeUnmount(() => {
    clearTimeout(temporizadorPreview);
    controladorPreview?.abort();
});

function enviar(): void {
    const opciones = {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit('guardado'),
    };

    if (esEdicion.value) {
        // Inertia convierte automáticamente a POST + `_method=PUT` cuando el
        // payload trae un `File` (multipart), sin necesidad de transformarlo.
        form.put(`/colaboradores/${props.colaborador!.id}`, opciones);
    } else {
        form.post('/colaboradores', opciones);
    }
}
</script>

<template>
    <form class="space-y-5" @submit.prevent="enviar">
        <div v-if="!esEdicion" class="grid gap-1.5">
            <Label for="fc-empresa_id">Empresa / razón social</Label>
            <BuscadorAsync
                id="fc-empresa_id"
                v-model="empresaSel"
                :buscar="buscarEmpresas"
                :etiqueta="(e) => String(e.nombre_comercial)"
                :invalido="!!form.errors.empresa_id"
                placeholder="Selecciona una empresa"
                placeholder-busqueda="Buscar empresa…"
            />
            <InputError :message="form.errors.empresa_id" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-1.5">
                <Label
                    for="fc-numero_empleado"
                    class="flex items-center gap-1.5"
                >
                    Número de empleado
                    <AyudaTooltip
                        texto="Lo genera el sistema automáticamente a partir del nombre y la empresa. No se puede escribir ni editar."
                        etiqueta="Ayuda sobre el número de empleado"
                    />
                </Label>
                <div
                    id="fc-numero_empleado"
                    class="bg-muted/50 text-muted-foreground flex h-9 items-center rounded-md border px-3 font-mono text-sm"
                >
                    <span
                        v-if="numeroEmpleadoPreview"
                        class="text-foreground"
                        >{{ numeroEmpleadoPreview }}</span
                    >
                    <span v-else-if="cargandoPreview">Calculando…</span>
                    <span v-else class="italic"
                        >Se generará automáticamente</span
                    >
                </div>
                <p class="text-muted-foreground text-xs">
                    {{
                        esEdicion
                            ? 'Asignado al crear el colaborador; no se puede modificar.'
                            : 'Captura la empresa y el nombre completo para ver el número que se asignará al guardar.'
                    }}
                </p>
            </div>
            <div class="grid gap-1.5">
                <Label for="fc-sucursal_id">Sucursal</Label>
                <BuscadorAsync
                    id="fc-sucursal_id"
                    :model-value="sucursalSel"
                    :buscar="buscarSucursales"
                    :dependencia="empresaId"
                    :disabled="empresaId === ''"
                    :etiqueta="(s) => (s as Opcion).nombre"
                    placeholder="Selecciona una sucursal"
                    placeholder-busqueda="Buscar sucursal por nombre"
                    sin-resultados="Esta empresa no tiene sucursales registradas."
                    :invalido="!!form.errors.sucursal_id"
                    @update:model-value="
                        (v) => {
                            sucursalSel = v as Opcion | null;
                            form.sucursal_id = (v as Opcion | null)?.id ?? '';
                            form.clearErrors('sucursal_id');
                        }
                    "
                />
                <InputError :message="form.errors.sucursal_id" />
            </div>
        </div>

        <div class="grid gap-1.5">
            <Label for="fc-nombre_completo">Nombre completo</Label>
            <Input
                id="fc-nombre_completo"
                v-model="form.nombre_completo"
                required
            />
            <InputError :message="form.errors.nombre_completo" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-1.5">
                <Label for="fc-puesto">Puesto</Label>
                <Input id="fc-puesto" v-model="form.puesto" />
                <InputError :message="form.errors.puesto" />
            </div>
            <div class="grid gap-1.5">
                <Label for="fc-area_id">Área / Departamento</Label>
                <BuscadorAsync
                    id="fc-area_id"
                    :model-value="areaSel"
                    :buscar="buscarAreas"
                    :dependencia="empresaId"
                    :disabled="empresaId === ''"
                    :etiqueta="(a) => (a as Opcion).nombre"
                    placeholder="Sin área"
                    placeholder-busqueda="Buscar área por nombre"
                    sin-resultados="Esta empresa no tiene áreas registradas."
                    :invalido="!!form.errors.area_id"
                    @update:model-value="
                        (v) => {
                            areaSel = v as Opcion | null;
                            form.area_id = (v as Opcion | null)?.id ?? '';
                            form.clearErrors('area_id');
                        }
                    "
                />
                <InputError :message="form.errors.area_id" />
                <p
                    v-if="empresaId !== ''"
                    class="text-muted-foreground text-xs"
                >
                    Opcional.
                    <Link href="/areas" class="underline">Crear un área</Link>.
                </p>
            </div>
        </div>

        <div class="grid gap-1.5">
            <Label for="fc-correo">Correo electrónico (opcional)</Label>
            <Input id="fc-correo" v-model="form.correo" type="email" />
            <InputError :message="form.errors.correo" />
        </div>

        <div class="grid gap-1.5">
            <Label for="fc-foto">Foto de perfil (opcional)</Label>
            <SubidaArchivo
                id="fc-foto"
                v-model="form.foto"
                v-model:eliminar="form.eliminar_foto"
                tipo="imagen"
                accept="image/jpeg,image/png,image/webp"
                formatos-etiqueta="JPG, PNG o WEBP"
                :peso-maximo-mb="3"
                :archivo-actual-url="colaborador?.foto_url"
                :invalido="!!form.errors.foto"
            />
            <InputError :message="form.errors.foto" />
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input
                v-model="form.activo"
                type="checkbox"
                class="size-4 rounded border"
            />
            Colaborador activo
        </label>

        <div class="flex items-center justify-end gap-2 pt-1">
            <Button
                type="button"
                variant="ghost"
                :disabled="form.processing"
                @click="emit('cancelar')"
            >
                Cancelar
            </Button>
            <Button type="submit" :disabled="form.processing">
                {{ esEdicion ? 'Guardar cambios' : 'Registrar colaborador' }}
            </Button>
        </div>
    </form>
</template>
