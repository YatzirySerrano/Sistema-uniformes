<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type ContratoOpcion = { id: number; nombre: string };
type ServicioOpcion = { id: number; nombre: string; contrato_id: number };

const props = defineProps<{
    open: boolean;
    colaboradorId: number;
    empresaId: number;
    /** Servicio operativo vigente del colaborador, o null si no tiene. */
    servicioActual: {
        id: number;
        nombre: string;
        contrato: { id: number; nombre: string };
    } | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'guardado'): void;
}>();

const contratoSel = ref<ContratoOpcion | null>(
    props.servicioActual?.contrato ?? null,
);
const servicioSel = ref<ServicioOpcion | null>(null);
const dejarSinServicio = ref(false);

const form = useForm<{ servicio_id: number | null; motivo: string }>({
    servicio_id: props.servicioActual?.id ?? null,
    motivo: '',
});

// Reabrir el diálogo siempre parte del estado real actual del colaborador —
// nunca conserva lo que se haya tecleado en un intento anterior cancelado.
watch(
    () => props.open,
    (abierto) => {
        if (!abierto) return;
        contratoSel.value = props.servicioActual?.contrato ?? null;
        servicioSel.value = null;
        dejarSinServicio.value = false;
        form.reset();
        form.servicio_id = props.servicioActual?.id ?? null;
    },
);

watch(contratoSel, () => {
    servicioSel.value = null;
});
watch(servicioSel, (s) => {
    form.servicio_id = s?.id ?? null;
});
watch(dejarSinServicio, (v) => {
    if (v) {
        form.servicio_id = null;
    } else {
        form.servicio_id = servicioSel.value?.id ?? null;
    }
});

async function buscarContratos(termino: string, signal?: AbortSignal) {
    const params = new URLSearchParams({
        empresa_id: String(props.empresaId),
        q: termino,
    });
    const res = await fetch(`/contratos/buscar?${params}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];

    return ((await res.json()).contratos ?? []) as ContratoOpcion[];
}

async function buscarServicios(termino: string, signal?: AbortSignal) {
    if (contratoSel.value === null) return [];

    const params = new URLSearchParams({
        contrato_id: String(contratoSel.value.id),
        q: termino,
    });
    const res = await fetch(`/servicios/buscar?${params}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];

    return ((await res.json()).servicios ?? []) as ServicioOpcion[];
}

const puedeGuardar = computed(
    () => dejarSinServicio.value || servicioSel.value !== null,
);

function enviar(): void {
    if (!puedeGuardar.value) return;

    form.post(`/colaboradores/${props.colaboradorId}/servicio`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('guardado');
            emit('update:open', false);
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Cambiar servicio</DialogTitle>
                <DialogDescription>
                    Actualiza únicamente la ubicación operativa vigente del
                    colaborador. No crea ni modifica entregas, devoluciones ni
                    asignaciones de activos: los que ya tenga siguen con él y
                    mostrarán la nueva ubicación automáticamente.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="enviar">
                <div
                    v-if="servicioActual"
                    class="bg-muted/40 rounded-lg p-3 text-sm"
                >
                    <span class="text-muted-foreground text-xs"
                        >Servicio actual</span
                    >
                    <p class="font-medium">
                        {{ servicioActual.contrato.nombre }} —
                        {{ servicioActual.nombre }}
                    </p>
                </div>
                <p v-else class="text-muted-foreground text-sm">
                    Actualmente sin servicio asignado.
                </p>

                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="dejarSinServicio"
                        type="checkbox"
                        class="size-4"
                    />
                    Dejar sin servicio asignado
                </label>

                <div v-if="!dejarSinServicio" class="grid gap-4">
                    <div class="grid gap-1.5">
                        <Label
                            for="cs-contrato"
                            class="flex items-center gap-1.5"
                        >
                            Nuevo contrato
                            <AyudaTooltip
                                texto="Contrato del que depende el nuevo servicio."
                                etiqueta="Ayuda sobre el contrato"
                            />
                        </Label>
                        <BuscadorAsync
                            id="cs-contrato"
                            v-model="contratoSel"
                            :buscar="buscarContratos"
                            :etiqueta="(c) => String(c.nombre)"
                            :dependencia="empresaId"
                            placeholder="Selecciona un contrato"
                            placeholder-busqueda="Buscar contrato…"
                        />
                    </div>

                    <div class="grid gap-1.5">
                        <Label
                            for="cs-servicio"
                            class="flex items-center gap-1.5"
                        >
                            Nuevo servicio
                            <AyudaTooltip
                                texto="Puesto operativo donde queda asignado el colaborador a partir de ahora."
                                etiqueta="Ayuda sobre el servicio"
                            />
                        </Label>
                        <BuscadorAsync
                            id="cs-servicio"
                            v-model="servicioSel"
                            :buscar="buscarServicios"
                            :etiqueta="(s) => String(s.nombre)"
                            :dependencia="contratoSel?.id ?? null"
                            :disabled="contratoSel === null"
                            placeholder="Selecciona un servicio"
                            placeholder-busqueda="Buscar servicio…"
                            sin-resultados="No hay servicios activos para este contrato."
                        />
                        <InputError :message="form.errors.servicio_id" />
                    </div>
                </div>

                <div class="grid gap-1.5">
                    <Label for="cs-motivo">Motivo / observación</Label>
                    <textarea
                        id="cs-motivo"
                        v-model="form.motivo"
                        rows="2"
                        maxlength="500"
                        placeholder="Opcional"
                        class="border-input bg-background focus-visible:ring-ring min-h-[60px] rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
                    ></textarea>
                    <InputError :message="form.errors.motivo" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        :disabled="form.processing"
                        @click="emit('update:open', false)"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing || !puedeGuardar"
                    >
                        Confirmar cambio
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
