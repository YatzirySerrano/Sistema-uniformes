<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleAlert, Loader2 } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { usePermisos } from '@/composables/usePermisos';

/**
 * Verificación previa a ELIMINAR (desactivar) un colaborador: consulta su
 * custodia pendiente (misma fuente y mismo endpoint que "Transferir a otra
 * empresa") y bloquea la confirmación mientras tenga bienes — incluidos los
 * de uso personal, para redistribuir, sin clasificar y unidades
 * identificadas. El backend lo vuelve a validar bajo candado al confirmar.
 */
type CustodiaFila = {
    tipo: string;
    finalidad_etiqueta: string;
    activo: string;
    talla: string | null;
    cantidad: number;
    referencia: string | null;
};

export type EstadoCustodiaBaja = 'cargando' | 'libre' | 'bloqueado' | 'error';

const props = defineProps<{ colaboradorId: number }>();
const emit = defineEmits<{ (e: 'estado', valor: EstadoCustodiaBaja): void }>();

const { puede } = usePermisos();
const estado = ref<EstadoCustodiaBaja>('cargando');
const filas = ref<CustodiaFila[]>([]);

function fijar(valor: EstadoCustodiaBaja): void {
    estado.value = valor;
    emit('estado', valor);
}

onMounted(async () => {
    fijar('cargando');
    try {
        const res = await fetch(
            `/colaboradores/${props.colaboradorId}/custodia`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );
        if (!res.ok) throw new Error();
        const data = await res.json();
        filas.value = (data.pendientes ?? []) as CustodiaFila[];
        fijar(data.tiene_pendientes ? 'bloqueado' : 'libre');
    } catch {
        // Fail-closed: sin poder confirmar la custodia no se permite la baja.
        fijar('error');
    }
});
</script>

<template>
    <div class="text-sm">
        <p
            v-if="estado === 'cargando'"
            class="text-muted-foreground flex items-center gap-2"
        >
            <Loader2 class="size-4 animate-spin" /> Revisando la custodia del
            colaborador…
        </p>
        <p
            v-else-if="estado === 'error'"
            class="flex items-start gap-2 text-red-600 dark:text-red-400"
        >
            <CircleAlert class="mt-0.5 size-4 shrink-0" />
            No se pudo consultar la custodia pendiente. Inténtalo de nuevo.
        </p>
        <div
            v-else-if="estado === 'bloqueado'"
            class="space-y-2 rounded-lg border border-red-500/40 bg-red-50/60 p-3 dark:bg-red-950/30"
        >
            <p
                class="flex items-start gap-2 font-medium text-red-700 dark:text-red-400"
            >
                <CircleAlert class="mt-0.5 size-4 shrink-0" />
                Este colaborador todavía tiene activos bajo custodia. Registra
                las devoluciones antes de continuar.
            </p>
            <ul class="max-h-48 list-disc space-y-0.5 overflow-y-auto pl-6">
                <li v-for="(f, i) in filas" :key="i">
                    {{ f.cantidad }} · {{ f.activo }}
                    <span v-if="f.talla">(talla {{ f.talla }})</span>
                    <span
                        v-if="f.tipo === 'unidad' && f.referencia"
                        class="font-mono"
                    >
                        {{ f.referencia }}</span
                    >
                    <span class="text-muted-foreground">
                        — {{ f.finalidad_etiqueta }}</span
                    >
                </li>
            </ul>
            <Button
                v-if="puede('devoluciones.crear')"
                as-child
                variant="outline"
                size="sm"
            >
                <Link
                    :href="`/devoluciones/crear?colaborador_id=${colaboradorId}`"
                    >Ir a Devoluciones</Link
                >
            </Button>
        </div>
        <p
            v-else
            class="rounded-lg border border-emerald-500/40 bg-emerald-50/60 p-3 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400"
        >
            El colaborador no tiene activos bajo custodia. Puedes continuar.
        </p>
    </div>
</template>
