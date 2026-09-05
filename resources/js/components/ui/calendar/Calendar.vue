<script setup lang="ts">
import type { DateValue } from '@internationalized/date';
import { getLocalTimeZone, today } from '@internationalized/date';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import {
    CalendarCell,
    CalendarCellTrigger,
    CalendarGrid,
    CalendarGridBody,
    CalendarGridHead,
    CalendarGridRow,
    CalendarHeadCell,
    CalendarNext,
    CalendarPrev,
    CalendarRoot,
} from 'reka-ui';
import { computed, ref } from 'vue';

/**
 * Calendario base (shadcn/reka-ui) con selectores de mes/año y días en
 * español. `DatePicker.vue` es el componente que normalmente se usa en
 * formularios (expone un `v-model` de cadena `YYYY-MM-DD`); este componente
 * es la pieza visual reutilizable por si algún flujo necesita el calendario
 * embebido sin popover. El mes mostrado (`placeholder`) es estado interno,
 * sembrado una sola vez desde `defaultPlaceholder` — así el padre no necesita
 * lidiar con el tipo `DateValue` (unión `CalendarDate | CalendarDateTime |
 * ZonedDateTime`) para algo que sólo controla qué mes se ve al abrir.
 */
const props = defineProps<{
    minValue?: DateValue;
    maxValue?: DateValue;
    defaultPlaceholder?: DateValue;
}>();

const modelValue = defineModel<DateValue | undefined>({ default: undefined });
const placeholder = ref<DateValue>(
    props.defaultPlaceholder ?? modelValue.value ?? today(getLocalTimeZone()),
);

const DIAS_SEMANA = ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sa', 'Do'];
const MESES = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre',
];

const anios = computed(() => {
    const actual = today(getLocalTimeZone()).year;
    return Array.from({ length: 13 }, (_, i) => actual - 6 + i);
});

function irAMes(mes: number): void {
    placeholder.value = placeholder.value.set({ month: mes });
}

function irAAnio(anio: number): void {
    placeholder.value = placeholder.value.set({ year: anio });
}

function irAHoy(): void {
    const hoy = today(getLocalTimeZone());
    placeholder.value = hoy;
    modelValue.value = hoy;
}

function quitarSeleccion(): void {
    modelValue.value = undefined;
}

const celdaClase =
    'inline-flex size-9 items-center justify-center rounded-lg text-sm font-normal text-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-2 data-[selected]:bg-primary data-[selected]:font-medium data-[selected]:text-primary-foreground data-[selected]:hover:bg-primary-hover data-[today]:ring-primary data-[today]:ring-1 data-[today]:ring-inset data-[outside-view]:text-muted-foreground/40 data-[disabled]:pointer-events-none data-[disabled]:opacity-40 data-[unavailable]:pointer-events-none data-[unavailable]:line-through';
</script>

<template>
    <!-- `placeholder` se castea a `any`: el `.d.ts` empaquetado de reka-ui
    2.9.x colapsa la unión `DateValue` en un tipo estructural roto para esta
    prop puntual (confirmado en runtime, el componente sólo necesita un
    `DateValue`); sin el cast, TS rechaza cualquier valor válido. -->
    <CalendarRoot
        v-slot="{ grid }"
        v-model="modelValue"
        :placeholder="(placeholder as any)"
        @update:placeholder="(valor: DateValue) => (placeholder = valor)"
        locale="es-MX"
        :week-starts-on="1"
        :min-value="props.minValue"
        :max-value="props.maxValue"
        fixed-weeks
        class="w-fit"
    >
        <div class="flex items-center justify-between gap-2">
            <CalendarPrev
                class="hover:bg-accent inline-flex size-7 items-center justify-center rounded-md border transition-colors disabled:pointer-events-none disabled:opacity-30"
                aria-label="Mes anterior"
            >
                <ChevronLeft class="size-4" />
            </CalendarPrev>

            <div class="flex items-center gap-1.5">
                <select
                    class="border-input h-8 rounded-md border bg-transparent px-1.5 text-sm"
                    aria-label="Seleccionar mes"
                    :value="placeholder.month"
                    @change="
                        irAMes(
                            Number(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option v-for="(mes, i) in MESES" :key="mes" :value="i + 1">
                        {{ mes }}
                    </option>
                </select>
                <select
                    class="border-input h-8 rounded-md border bg-transparent px-1.5 text-sm"
                    aria-label="Seleccionar año"
                    :value="placeholder.year"
                    @change="
                        irAAnio(
                            Number(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option v-for="anio in anios" :key="anio" :value="anio">
                        {{ anio }}
                    </option>
                </select>
            </div>

            <CalendarNext
                class="hover:bg-accent inline-flex size-7 items-center justify-center rounded-md border transition-colors disabled:pointer-events-none disabled:opacity-30"
                aria-label="Mes siguiente"
            >
                <ChevronRight class="size-4" />
            </CalendarNext>
        </div>

        <CalendarGrid
            v-for="mes in grid"
            :key="mes.value.toString()"
            class="mt-3 w-full border-collapse space-y-1 select-none"
        >
            <CalendarGridHead>
                <CalendarGridRow class="flex">
                    <CalendarHeadCell
                        v-for="dia in DIAS_SEMANA"
                        :key="dia"
                        class="text-muted-foreground w-9 text-center text-xs font-medium"
                    >
                        {{ dia }}
                    </CalendarHeadCell>
                </CalendarGridRow>
            </CalendarGridHead>
            <CalendarGridBody>
                <CalendarGridRow
                    v-for="(semana, i) in mes.rows"
                    :key="`semana-${i}`"
                    class="mt-1.5 flex w-full"
                >
                    <CalendarCell
                        v-for="dia in semana"
                        :key="dia.toString()"
                        :date="dia"
                        class="relative size-9 p-0 text-center text-sm"
                    >
                        <CalendarCellTrigger
                            :day="dia"
                            :month="mes.value"
                            :class="celdaClase"
                        />
                    </CalendarCell>
                </CalendarGridRow>
            </CalendarGridBody>
        </CalendarGrid>

        <div class="mt-3 flex items-center justify-between border-t pt-3">
            <button
                type="button"
                class="text-primary text-xs font-medium hover:underline"
                @click="irAHoy"
            >
                Hoy
            </button>
            <button
                type="button"
                class="text-muted-foreground text-xs hover:underline disabled:pointer-events-none disabled:opacity-40"
                :disabled="!modelValue"
                @click="quitarSeleccion"
            >
                Quitar selección
            </button>
        </div>
    </CalendarRoot>
</template>
