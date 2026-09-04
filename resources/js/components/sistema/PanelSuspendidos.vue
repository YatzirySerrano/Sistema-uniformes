<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { RotateCcw } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type Suspendido = {
    id: number;
    tipo: string;
    nombre: string | null;
    suspendida_en: string;
    /**
     * `false` cuando la entidad todavía depende de algo inactivo (otra
     * empresa/sucursal/componente) además de esta suspensión — reactivarla
     * ahora dejaría un estado inválido, así que el backend la rechazaría.
     * Se deshabilita aquí mismo para que el usuario no tenga que descubrirlo
     * por ensayo y error.
     */
    puede_reactivarse: boolean;
    motivos: string[];
};

const props = defineProps<{
    suspendidos: Suspendido[];
    endpoint: string;
    puedeReactivar: boolean;
}>();

const seleccionados = ref<Set<number>>(new Set());
const enviando = ref(false);

function alternar(id: number): void {
    if (seleccionados.value.has(id)) {
        seleccionados.value.delete(id);
    } else {
        seleccionados.value.add(id);
    }
    // Forzar reactividad de Set en la plantilla.
    seleccionados.value = new Set(seleccionados.value);
}

function reactivarSeleccionados(): void {
    if (!seleccionados.value.size) return;
    enviando.value = true;
    router.post(
        props.endpoint,
        { ids: Array.from(seleccionados.value) },
        {
            preserveScroll: true,
            onFinish: () => {
                enviando.value = false;
                seleccionados.value = new Set();
            },
        },
    );
}
</script>

<template>
    <Card v-if="suspendidos.length" class="border-amber-500/40">
        <CardHeader>
            <CardTitle class="text-base"
                >Suspendidos por esta desactivación</CardTitle
            >
            <p class="text-muted-foreground text-sm">
                Estos registros quedaron inactivos automáticamente al desactivar
                este registro. No se reactivan solos: marca los que quieras
                reactivar ahora. Los que ya estaban inactivos por otra causa no
                aparecen aquí.
            </p>
        </CardHeader>
        <CardContent class="space-y-3">
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="s in suspendidos"
                    :key="s.id"
                    class="px-3 py-2 text-sm"
                >
                    <div class="flex items-center gap-3">
                        <input
                            :id="`suspendido-${s.id}`"
                            type="checkbox"
                            class="size-4"
                            :disabled="!puedeReactivar || !s.puede_reactivarse"
                            :checked="seleccionados.has(s.id)"
                            @change="alternar(s.id)"
                        />
                        <label
                            :for="`suspendido-${s.id}`"
                            class="min-w-0 flex-1"
                            :class="
                                s.puede_reactivarse
                                    ? 'cursor-pointer'
                                    : 'cursor-not-allowed opacity-70'
                            "
                        >
                            <span class="text-muted-foreground text-xs">{{
                                s.tipo
                            }}</span>
                            <span class="ml-1.5 font-medium">{{
                                s.nombre ?? `#${s.id}`
                            }}</span>
                        </label>
                        <span class="text-muted-foreground shrink-0 text-xs">{{
                            s.suspendida_en
                        }}</span>
                    </div>
                    <div
                        v-if="!s.puede_reactivarse && s.motivos.length"
                        class="text-muted-foreground mt-1 pl-7 text-xs"
                    >
                        <p>No se puede reactivar todavía:</p>
                        <ul class="list-disc pl-4">
                            <li v-for="(motivo, i) in s.motivos" :key="i">
                                {{ motivo }}
                            </li>
                        </ul>
                    </div>
                </li>
            </ul>
            <Button
                v-if="puedeReactivar"
                type="button"
                variant="outline"
                size="sm"
                :disabled="!seleccionados.size || enviando"
                @click="reactivarSeleccionados"
            >
                <RotateCcw class="size-4" /> Reactivar seleccionados ({{
                    seleccionados.size
                }})
            </Button>
        </CardContent>
    </Card>
</template>
