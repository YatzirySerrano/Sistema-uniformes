<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import SubidaArchivo from '@/components/sistema/SubidaArchivo.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type EmpresaEditable = {
    id: number;
    codigo: string;
    nombre_comercial: string;
    razon_social: string | null;
    rfc: string | null;
    telefono: string | null;
    correo: string | null;
    direccion: string | null;
    activa: boolean;
};

const props = defineProps<{
    empresa: EmpresaEditable | null;
    logoUrlActual?: string | null;
}>();
const emit = defineEmits<{ (e: 'guardado'): void; (e: 'cancelar'): void }>();

const esEdicion = computed(() => props.empresa !== null);

const form = useForm<{
    nombre_comercial: string;
    razon_social: string;
    rfc: string;
    telefono: string;
    correo: string;
    direccion: string;
    activa: boolean;
    logo: File | null;
    eliminar_logo: boolean;
}>({
    nombre_comercial: props.empresa?.nombre_comercial ?? '',
    razon_social: props.empresa?.razon_social ?? '',
    rfc: props.empresa?.rfc ?? '',
    telefono: props.empresa?.telefono ?? '',
    correo: props.empresa?.correo ?? '',
    direccion: props.empresa?.direccion ?? '',
    activa: props.empresa?.activa ?? true,
    logo: null,
    eliminar_logo: false,
});

/** Campos que el usuario ya tocó: sólo mostramos error en tiempo real tras salir del campo. */
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

const rfcRegex = /^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{0,3}$/i;
const correoRegex = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;

const erroresLocales = computed<Record<string, string>>(() => {
    const e: Record<string, string> = {};

    if (tocado.nombre_comercial && form.nombre_comercial.trim() === '') {
        e.nombre_comercial = 'El nombre comercial es obligatorio.';
    } else if (form.nombre_comercial.length > 255) {
        e.nombre_comercial = 'Máximo 255 caracteres.';
    }

    if (
        tocado.correo &&
        form.correo.trim() !== '' &&
        !correoRegex.test(form.correo.trim())
    ) {
        e.correo = 'Introduce un correo electrónico válido.';
    }

    if (tocado.telefono && form.telefono.trim() !== '') {
        const digitos = form.telefono.replace(/\D+/g, '');
        if (digitos.length !== 10) {
            e.telefono = 'El teléfono debe contener 10 dígitos.';
        }
    }

    if (
        tocado.rfc &&
        form.rfc.trim() !== '' &&
        !rfcRegex.test(form.rfc.trim())
    ) {
        e.rfc = 'RFC con formato no válido (ej. ABC010203XYZ).';
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

// --- Código de empresa: lo genera el backend, nunca lo escribe el usuario.
// Esto sólo previsualiza (no reserva) el código; el valor definitivo se
// calcula y reserva atómicamente al guardar (ver EmpresaController::store()).
const codigoPreview = ref<string | null>(props.empresa?.codigo ?? null);
const cargandoPreview = ref(false);
let controladorPreview: AbortController | undefined;
let temporizadorPreview: ReturnType<typeof setTimeout> | undefined;

async function actualizarPreview(): Promise<void> {
    const nombre = form.nombre_comercial.trim();

    if (nombre === '') {
        codigoPreview.value = null;
        cargandoPreview.value = false;
        return;
    }

    controladorPreview?.abort();
    controladorPreview = new AbortController();
    cargandoPreview.value = true;

    try {
        const res = await fetch(
            `/empresas/siguiente-codigo?nombre_comercial=${encodeURIComponent(nombre)}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controladorPreview.signal,
            },
        );
        if (!res.ok) return;
        codigoPreview.value = (await res.json()).codigo ?? null;
    } catch {
        // Petición abortada o de red: se ignora, el usuario puede seguir escribiendo.
    } finally {
        cargandoPreview.value = false;
    }
}

if (!esEdicion.value) {
    watch(
        () => form.nombre_comercial,
        () => {
            clearTimeout(temporizadorPreview);
            temporizadorPreview = setTimeout(actualizarPreview, 400);
        },
    );
}

onBeforeUnmount(() => {
    clearTimeout(temporizadorPreview);
    controladorPreview?.abort();
});

function enviar(): void {
    tocado.nombre_comercial = true;

    if (form.nombre_comercial.trim() === '') {
        return;
    }

    const opciones = {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => emit('guardado'),
    };

    if (esEdicion.value) {
        form.put(`/empresas/${props.empresa!.id}`, opciones);
    } else {
        form.post('/empresas', opciones);
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="enviar">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="ef-nombre" class="flex items-center gap-1.5">
                    Nombre comercial
                    <span class="text-destructive">*</span>
                    <AyudaTooltip
                        texto="Nombre con el que se conoce a la empresa en el día a día. Aparece en menús, comprobantes y reportes."
                        etiqueta="Ayuda sobre el nombre comercial"
                    />
                </Label>
                <Input
                    id="ef-nombre"
                    v-model="form.nombre_comercial"
                    autocomplete="organization"
                    required
                    @blur="marcar('nombre_comercial')"
                />
                <InputError :message="error('nombre_comercial')" />
            </div>

            <div class="grid gap-1.5">
                <Label for="ef-razon" class="flex items-center gap-1.5">
                    Razón social
                    <AyudaTooltip
                        texto="Nombre legal con el que la empresa está registrada ante el SAT. Puede incluir S.A. de C.V., S. de R.L., etc."
                        etiqueta="Ayuda sobre la razón social"
                    />
                </Label>
                <Input
                    id="ef-razon"
                    v-model="form.razon_social"
                    @blur="marcar('razon_social')"
                />
                <InputError :message="error('razon_social')" />
            </div>

            <div class="grid gap-1.5">
                <Label for="ef-rfc" class="flex items-center gap-1.5">
                    RFC
                    <AyudaTooltip
                        texto="Registro Federal de Contribuyentes de la empresa. 12 o 13 caracteres; se guarda en mayúsculas."
                        etiqueta="Ayuda sobre el RFC"
                    />
                </Label>
                <Input
                    id="ef-rfc"
                    v-model="form.rfc"
                    class="uppercase"
                    maxlength="13"
                    @blur="marcar('rfc')"
                />
                <InputError :message="error('rfc')" />
            </div>

            <div class="grid gap-1.5">
                <Label for="ef-codigo" class="flex items-center gap-1.5">
                    Código de empresa
                    <AyudaTooltip
                        texto="Lo genera el sistema automáticamente a partir del nombre comercial. No se puede escribir ni editar."
                        etiqueta="Ayuda sobre el código de empresa"
                    />
                </Label>
                <div
                    id="ef-codigo"
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
                            ? 'Asignado al crear la empresa; no se puede modificar.'
                            : 'Captura el nombre comercial para ver el código que se asignará al guardar.'
                    }}
                </p>
            </div>

            <div class="grid gap-1.5">
                <Label for="ef-telefono" class="flex items-center gap-1.5">
                    Teléfono
                    <AyudaTooltip
                        texto="Teléfono de contacto de la empresa a 10 dígitos. Se guardan sólo los números."
                        etiqueta="Ayuda sobre el teléfono"
                    />
                </Label>
                <Input
                    id="ef-telefono"
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

            <div class="grid gap-1.5">
                <Label for="ef-correo" class="flex items-center gap-1.5">
                    Correo
                    <AyudaTooltip
                        texto="Correo de contacto de la empresa. Si lo capturas debe tener un formato válido."
                        etiqueta="Ayuda sobre el correo"
                    />
                </Label>
                <Input
                    id="ef-correo"
                    v-model="form.correo"
                    type="email"
                    autocomplete="email"
                    @blur="marcar('correo')"
                />
                <InputError :message="error('correo')" />
            </div>

            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="ef-direccion" class="flex items-center gap-1.5">
                    Dirección
                    <AyudaTooltip
                        texto="Domicilio principal de la empresa. Se usa como referencia en comprobantes y reportes."
                        etiqueta="Ayuda sobre la dirección"
                    />
                </Label>
                <Input
                    id="ef-direccion"
                    v-model="form.direccion"
                    autocomplete="street-address"
                    maxlength="500"
                />
                <InputError :message="error('direccion')" />
            </div>
        </div>

        <div class="grid gap-1.5">
            <Label class="flex items-center gap-1.5">
                Logotipo
                <AyudaTooltip
                    texto="Se usa como referencia administrativa y en comprobantes/PDF de esta empresa. No define los colores de la aplicación (eso se configura globalmente en Configuración)."
                    etiqueta="Ayuda sobre el logotipo"
                />
            </Label>
            <SubidaArchivo
                v-model="form.logo"
                v-model:eliminar="form.eliminar_logo"
                tipo="imagen"
                tamano="compact"
                accept="image/png,image/jpeg,image/svg+xml"
                formatos-etiqueta="PNG, JPG o SVG"
                :peso-maximo-mb="2"
                :archivo-actual-url="logoUrlActual"
                :invalido="!!error('logo')"
            />
            <InputError :message="error('logo')" />
        </div>

        <label class="flex items-start gap-2.5 rounded-lg border p-3 text-sm">
            <input
                v-model="form.activa"
                type="checkbox"
                class="mt-0.5 size-4"
            />
            <span>
                <span class="font-medium">Empresa activa</span>
                <span class="text-muted-foreground block text-xs">
                    Una empresa inactiva se conserva con todo su historial, pero
                    sus operaciones quedan restringidas hasta reactivarla.
                </span>
            </span>
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
            <Button
                type="submit"
                :disabled="form.processing || hayErroresLocales"
            >
                {{ esEdicion ? 'Guardar cambios' : 'Registrar empresa' }}
            </Button>
        </div>
    </form>
</template>
