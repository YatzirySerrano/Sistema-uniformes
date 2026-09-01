<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
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

const props = defineProps<{ empresa: EmpresaEditable | null }>();
const emit = defineEmits<{ (e: 'guardado'): void; (e: 'cancelar'): void }>();

const esEdicion = computed(() => props.empresa !== null);

const form = useForm({
    nombre_comercial: props.empresa?.nombre_comercial ?? '',
    razon_social: props.empresa?.razon_social ?? '',
    rfc: props.empresa?.rfc ?? '',
    codigo: props.empresa?.codigo ?? '',
    telefono: props.empresa?.telefono ?? '',
    correo: props.empresa?.correo ?? '',
    direccion: props.empresa?.direccion ?? '',
    activa: props.empresa?.activa ?? true,
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

    if (
        tocado.codigo &&
        form.codigo.trim() !== '' &&
        !/^[A-Za-z0-9_-]+$/.test(form.codigo.trim())
    ) {
        e.codigo = 'Sólo letras, números, guiones y guiones bajos.';
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

function enviar(): void {
    tocado.nombre_comercial = true;

    if (form.nombre_comercial.trim() === '') {
        return;
    }

    const opciones = {
        preserveScroll: true,
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
                        texto="Identificador interno para distinguir esta empresa dentro de la plataforma. Si lo dejas vacío se genera automáticamente."
                        etiqueta="Ayuda sobre el código de empresa"
                    />
                </Label>
                <Input
                    id="ef-codigo"
                    v-model="form.codigo"
                    class="uppercase"
                    placeholder="Se genera automáticamente"
                    maxlength="20"
                    @blur="marcar('codigo')"
                />
                <InputError :message="error('codigo')" />
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
