<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { ActivoOpcion } from '@/types/sistema';

type Item = {
    activo_id: number | null;
    talla_id: number | null;
    cantidad: number;
    condicion?: string;
};

const props = defineProps<{
    modelValue: Item[];
    activos: ActivoOpcion[];
    disponibles?: Record<string, number>; // clave `${activo_id}-${talla_id}` => disponible
    conCondicion?: boolean;
    condiciones?: { valor: string; etiqueta: string }[];
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', v: Item[]): void;
}>();

const items = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

function tallasDe(activoId: number | null) {
    return props.activos.find((p) => p.id === activoId)?.tallas ?? [];
}

function disponibleDe(item: Item): number | null {
    if (!props.disponibles || !item.activo_id || !item.talla_id) return null;
    return props.disponibles[`${item.activo_id}-${item.talla_id}`] ?? 0;
}

function agregar() {
    items.value = [
        ...items.value,
        {
            activo_id: null,
            talla_id: null,
            cantidad: 1,
            ...(props.conCondicion ? { condicion: 'reutilizable' } : {}),
        },
    ];
}

function quitar(i: number) {
    items.value = items.value.filter((_, idx) => idx !== i);
}
</script>

<template>
    <div class="space-y-3">
        <div
            v-for="(item, i) in items"
            :key="i"
            class="grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_120px_110px_auto]"
            :class="
                conCondicion ? 'sm:grid-cols-[1fr_110px_100px_140px_auto]' : ''
            "
        >
            <div>
                <select
                    v-model="item.activo_id"
                    class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                    @change="item.talla_id = null"
                >
                    <option :value="null" disabled>Selecciona activo</option>
                    <option v-for="p in activos" :key="p.id" :value="p.id">
                        {{ p.nombre }}
                    </option>
                </select>
            </div>
            <div>
                <select
                    v-model="item.talla_id"
                    class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                    :disabled="!item.activo_id"
                >
                    <option :value="null" disabled>Talla</option>
                    <option
                        v-for="t in tallasDe(item.activo_id)"
                        :key="t.id"
                        :value="t.id"
                    >
                        {{ t.valor }}
                    </option>
                </select>
            </div>
            <div>
                <Input
                    v-model.number="item.cantidad"
                    type="number"
                    min="1"
                    class="h-9"
                />
                <p
                    v-if="disponibleDe(item) !== null"
                    class="text-muted-foreground mt-0.5 text-[11px]"
                    :class="
                        (disponibleDe(item) ?? 0) < item.cantidad
                            ? 'text-destructive'
                            : ''
                    "
                >
                    Disp.: {{ disponibleDe(item) }}
                </p>
            </div>
            <div v-if="conCondicion">
                <select
                    v-model="item.condicion"
                    class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                >
                    <option
                        v-for="c in condiciones"
                        :key="c.valor"
                        :value="c.valor"
                    >
                        {{ c.etiqueta }}
                    </option>
                </select>
            </div>
            <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                @click="quitar(i)"
            >
                <Trash2 class="size-4" />
            </Button>
        </div>

        <Button type="button" variant="outline" size="sm" @click="agregar">
            <Plus class="size-4" /> Agregar activo
        </Button>
    </div>
</template>
