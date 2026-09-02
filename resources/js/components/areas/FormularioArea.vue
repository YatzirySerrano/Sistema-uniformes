<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type AreaEditable = {
    id: number;
    nombre: string;
    codigo: string | null;
    descripcion: string | null;
};

const props = defineProps<{ area: AreaEditable | null }>();
const emit = defineEmits<{ (e: 'guardado'): void; (e: 'cancelar'): void }>();

const esEdicion = computed(() => props.area !== null);

const form = useForm({
    nombre: props.area?.nombre ?? '',
    codigo: props.area?.codigo ?? '',
    descripcion: props.area?.descripcion ?? '',
});

const tocado = reactive<Record<string, boolean>>({});
function marcar(campo: string): void {
    tocado[campo] = true;
}

const erroresLocales = computed<Record<string, string>>(() => {
    const e: Record<string, string> = {};

    if (tocado.nombre && form.nombre.trim() === '') {
        e.nombre = 'El nombre del área es obligatorio.';
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
        form.put(`/areas/${props.area!.id}`, opciones);
    } else {
        form.post('/areas', opciones);
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="enviar">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="af-nombre" class="flex items-center gap-1.5">
                    Nombre
                    <span class="text-destructive">*</span>
                    <AyudaTooltip
                        texto="Nombre del área o departamento (Seguridad, Recursos Humanos, Operaciones, etc.)."
                        etiqueta="Ayuda sobre el nombre"
                    />
                </Label>
                <Input
                    id="af-nombre"
                    v-model="form.nombre"
                    required
                    maxlength="255"
                    @blur="marcar('nombre')"
                />
                <InputError :message="error('nombre')" />
            </div>

            <div class="grid gap-1.5">
                <Label for="af-codigo" class="flex items-center gap-1.5">
                    Código
                    <AyudaTooltip
                        texto="Identificador interno del área dentro de la empresa. Si lo dejas vacío se genera automáticamente (ARE-0001)."
                        etiqueta="Ayuda sobre el código"
                    />
                </Label>
                <Input
                    id="af-codigo"
                    v-model="form.codigo"
                    class="uppercase"
                    placeholder="Se genera automáticamente"
                    maxlength="60"
                    @blur="marcar('codigo')"
                />
                <InputError :message="error('codigo')" />
            </div>

            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="af-descripcion" class="flex items-center gap-1.5">
                    Descripción
                    <AyudaTooltip
                        texto="Detalle opcional sobre la función del área. Se muestra en el detalle del área."
                        etiqueta="Ayuda sobre la descripción"
                    />
                </Label>
                <textarea
                    id="af-descripcion"
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
                {{ esEdicion ? 'Guardar cambios' : 'Registrar área' }}
            </Button>
        </div>
    </form>
</template>
