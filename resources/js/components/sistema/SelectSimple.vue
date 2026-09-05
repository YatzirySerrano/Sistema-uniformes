<script setup lang="ts">
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

/**
 * Select shadcn para catálogos PEQUEÑOS y finitos (estados, condiciones, tipo
 * de control, orden, formato…). Para catálogos que pueden crecer (empresa,
 * sucursal, colaborador, almacén, activo, unidad, conjunto, tipo, categoría,
 * variante…) usa `BuscadorAsync.vue` en su lugar — nunca cargues un catálogo
 * grande completo sólo para poder usar este componente.
 */
const props = defineProps<{
    modelValue: string | number | null;
    opciones: {
        valor: string | number;
        etiqueta: string;
        disabled?: boolean;
    }[];
    placeholder?: string;
    disabled?: boolean;
    invalido?: boolean;
    id?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string | number | null): void;
}>();

// reka-ui (como Radix) reserva la cadena vacía para representar "nada
// seleccionado" en el Select raíz, así que un <SelectItem> no puede usar ''
// como value. Las opciones "Todas" / "Todos" (valor === '') se envuelven con
// este centinela sólo para el cableado interno del componente.
const CENTINELA_VACIO = '__vacio__';

function envolver(valor: string | number): string {
    return valor === '' ? CENTINELA_VACIO : String(valor);
}

function alCambiar(valor: unknown): void {
    if (valor === undefined || valor === null) {
        emit('update:modelValue', null);

        return;
    }

    const original = props.opciones.find(
        (o) => envolver(o.valor) === String(valor),
    );
    emit('update:modelValue', original?.valor ?? (valor as string));
}
</script>

<template>
    <Select
        :model-value="modelValue !== null ? envolver(modelValue) : undefined"
        :disabled="disabled"
        @update:model-value="alCambiar"
    >
        <SelectTrigger
            :id="id"
            class="w-full"
            :class="invalido ? 'border-destructive' : ''"
            :aria-invalid="invalido || undefined"
        >
            <SelectValue :placeholder="placeholder ?? 'Selecciona…'" />
        </SelectTrigger>
        <SelectContent>
            <SelectItem
                v-for="opcion in opciones"
                :key="opcion.valor"
                :value="envolver(opcion.valor)"
                :disabled="opcion.disabled"
            >
                {{ opcion.etiqueta }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
