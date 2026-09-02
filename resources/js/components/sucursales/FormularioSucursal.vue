<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type SucursalEditable = {
    id: number;
    codigo: string;
    nombre: string;
    direccion: string | null;
    telefono: string | null;
};

const props = defineProps<{ sucursal: SucursalEditable | null }>();
const emit = defineEmits<{ (e: 'guardado'): void; (e: 'cancelar'): void }>();

const esEdicion = computed(() => props.sucursal !== null);

const form = useForm({
    nombre: props.sucursal?.nombre ?? '',
    codigo: props.sucursal?.codigo ?? '',
    direccion: props.sucursal?.direccion ?? '',
    telefono: props.sucursal?.telefono ?? '',
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

    if (tocado.nombre && form.nombre.trim() === '') {
        e.nombre = 'El nombre de la sucursal es obligatorio.';
    } else if (form.nombre.length > 255) {
        e.nombre = 'Máximo 255 caracteres.';
    }

    if (
        tocado.codigo &&
        form.codigo.trim() !== '' &&
        !/^[A-Za-z0-9_-]+$/.test(form.codigo.trim())
    ) {
        e.codigo = 'Sólo letras, números, guiones y guiones bajos.';
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

function enviar(): void {
    tocado.nombre = true;
    if (form.nombre.trim() === '') {
        return;
    }

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
                        texto="Identificador interno de la sucursal dentro de la empresa. Si lo dejas vacío se genera automáticamente (SUC-0001)."
                        etiqueta="Ayuda sobre el código"
                    />
                </Label>
                <Input
                    id="sf-codigo"
                    v-model="form.codigo"
                    class="uppercase"
                    placeholder="Se genera automáticamente"
                    maxlength="60"
                    @blur="marcar('codigo')"
                />
                <InputError :message="error('codigo')" />
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
