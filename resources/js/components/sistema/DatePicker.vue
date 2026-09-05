<script setup lang="ts">
import type { DateValue } from '@internationalized/date';
import { getLocalTimeZone, parseDate } from '@internationalized/date';
import { CalendarIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

/**
 * Selector de fecha global del sistema: reemplaza `<input type="date">` en
 * todos los formularios y filtros. `modelValue` es una cadena `YYYY-MM-DD`
 * (cadena vacía cuando no hay fecha), igual que el `value` de un
 * `<input type="date">` nativo — así no cambia la forma en que el resto del
 * formulario ya trabaja con la fecha (envío a Laravel, filtros reactivos…).
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        min?: string | null;
        max?: string | null;
        placeholder?: string;
        disabled?: boolean;
        invalido?: boolean;
        id?: string;
    }>(),
    { placeholder: 'Selecciona una fecha' },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const abierto = ref(false);

const formateador = new Intl.DateTimeFormat('es-MX', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
});

function aFecha(valor?: string | null): DateValue | undefined {
    if (!valor) return undefined;
    try {
        return parseDate(valor);
    } catch {
        return undefined;
    }
}

const fechaSeleccionada = computed<DateValue | undefined>({
    get: () => aFecha(props.modelValue),
    set: (valor) => {
        emit('update:modelValue', valor ? valor.toString() : '');
        if (valor) abierto.value = false;
    },
});

const minFecha = computed(() => aFecha(props.min));
const maxFecha = computed(() => aFecha(props.max));

const etiqueta = computed(() =>
    fechaSeleccionada.value
        ? formateador.format(fechaSeleccionada.value.toDate(getLocalTimeZone()))
        : props.placeholder,
);
</script>

<template>
    <Popover v-model:open="abierto">
        <PopoverTrigger as-child>
            <button
                :id="id"
                type="button"
                :disabled="disabled"
                :aria-invalid="invalido || undefined"
                :class="
                    cn(
                        'border-input bg-background flex h-9 w-full items-center gap-2 rounded-md border px-3 text-sm shadow-xs transition-colors',
                        'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] focus-visible:outline-none',
                        'aria-invalid:ring-destructive/20 aria-invalid:border-destructive dark:aria-invalid:ring-destructive/40',
                        'disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
                        !fechaSeleccionada && 'text-muted-foreground',
                    )
                "
            >
                <CalendarIcon class="size-4 shrink-0" />
                <span class="truncate">{{ etiqueta }}</span>
            </button>
        </PopoverTrigger>
        <PopoverContent class="w-auto" align="start">
            <Calendar
                v-model="fechaSeleccionada"
                :default-placeholder="fechaSeleccionada"
                :min-value="minFecha"
                :max-value="maxFecha"
            />
        </PopoverContent>
    </Popover>
</template>
