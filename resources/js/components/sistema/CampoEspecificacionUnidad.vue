<script setup lang="ts">
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Input } from '@/components/ui/input';
import type { CampoEspecificacion } from '@/lib/perfilTecnicoUnidad';
import { OPCIONES_CLASE_VEHICULO } from '@/lib/perfilTecnicoUnidad';

/**
 * Control de UN dato técnico por unidad: lista para el tipo de vehículo,
 * numérico para el año, texto para el resto. Compartido por el alta de
 * activo, "agregar existencias" y la edición en el detalle de la unidad.
 */
defineProps<{ campo: CampoEspecificacion; id?: string }>();

const modelo = defineModel<string>({ required: true });

const anioMaximo = new Date().getFullYear() + 1;
</script>

<template>
    <div v-if="campo === 'clase_vehiculo'" class="w-full">
        <SelectSimple
            :id="id"
            :model-value="modelo || null"
            :opciones="OPCIONES_CLASE_VEHICULO"
            placeholder="Selecciona el tipo"
            @update:model-value="(v) => (modelo = v === null ? '' : String(v))"
        />
    </div>
    <Input
        v-else-if="campo === 'anio'"
        :id="id"
        v-model="modelo"
        type="number"
        inputmode="numeric"
        min="1900"
        :max="anioMaximo"
        placeholder="Ej. 2022"
    />
    <Input
        v-else
        :id="id"
        v-model="modelo"
        :placeholder="
            campo === 'imei'
                ? '15 dígitos'
                : campo === 'placas'
                  ? 'Ej. ABC-123-D'
                  : undefined
        "
        autocomplete="off"
    />
</template>
