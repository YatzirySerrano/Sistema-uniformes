<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { AlertTriangle, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

type ServicioActual = {
    id: number;
    nombre: string;
    contrato: { id: number; nombre: string };
};

type Candidato = {
    id: number;
    nombre_completo: string;
    numero_empleado: string;
    sucursal: string | null;
    servicio_actual: ServicioActual | null;
};

type OpcionSucursal = { id: number; nombre: string };
type OpcionServicio = { id: number; nombre: string };

const props = defineProps<{
    open: boolean;
    servicioId: number;
    empresaId: number;
    servicioNombre: string;
    contratoNombre: string;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'guardado'): void;
}>();

const seleccionados = ref<Candidato[]>([]);
const picker = ref<Candidato | null>(null);
const sucursalSel = ref<OpcionSucursal | null>(null);
const servicioActualFiltro = ref<OpcionServicio | null>(null);
const soloSinServicio = ref(false);
const confirmoMovimientos = ref(false);

const form = useForm<{ colaborador_ids: number[]; motivo: string }>({
    colaborador_ids: [],
    motivo: '',
});

const erroresLaxos = computed(
    () => form.errors as unknown as Record<string, string>,
);

// Reabrir siempre parte de cero.
watch(
    () => props.open,
    (abierto) => {
        if (!abierto) return;
        seleccionados.value = [];
        picker.value = null;
        sucursalSel.value = null;
        servicioActualFiltro.value = null;
        soloSinServicio.value = false;
        confirmoMovimientos.value = false;
        form.reset();
        form.clearErrors();
    },
);

watch(soloSinServicio, (v) => {
    if (v) servicioActualFiltro.value = null;
});

// La dependencia obliga a `BuscadorAsync` a descartar resultados obsoletos
// cuando cambia cualquier filtro.
const dependenciaBusqueda = computed(
    () =>
        `${sucursalSel.value?.id ?? ''}-${servicioActualFiltro.value?.id ?? ''}-${
            soloSinServicio.value ? 1 : 0
        }-${seleccionados.value.length}`,
);

async function buscarCandidatos(
    q: string,
    signal?: AbortSignal,
): Promise<Candidato[]> {
    const params = new URLSearchParams({
        empresa_id: String(props.empresaId),
        q,
    });
    if (sucursalSel.value)
        params.set('sucursal_id', String(sucursalSel.value.id));
    if (soloSinServicio.value) params.set('sin_servicio', '1');
    if (servicioActualFiltro.value)
        params.set('con_servicio_id', String(servicioActualFiltro.value.id));

    const res = await fetch(`/colaboradores/buscar?${params}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];
    const lista = ((await res.json()).colaboradores ?? []) as Candidato[];
    // Quita los ya elegidos y los que ya están en este servicio.
    return lista.filter(
        (c) =>
            !seleccionados.value.some((s) => s.id === c.id) &&
            c.servicio_actual?.id !== props.servicioId,
    );
}

async function buscarSucursales(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionSucursal[]> {
    const res = await fetch(
        `/sucursales/buscar?empresa_id=${props.empresaId}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).sucursales ?? [];
}

async function buscarServicios(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionServicio[]> {
    const res = await fetch(
        `/servicios/buscar?empresa_id=${props.empresaId}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).servicios ?? [];
}

function alElegir(c: Candidato | null): void {
    if (c === null) return;
    if (!seleccionados.value.some((s) => s.id === c.id)) {
        seleccionados.value.push(c);
    }
    picker.value = null;
    confirmoMovimientos.value = false;
    form.clearErrors();
}

function quitar(id: number): void {
    seleccionados.value = seleccionados.value.filter((c) => c.id !== id);
    confirmoMovimientos.value = false;
}

// Colaboradores que YA tienen otro servicio: serán cambiados.
const movimientos = computed(() =>
    seleccionados.value.filter(
        (c) => c.servicio_actual && c.servicio_actual.id !== props.servicioId,
    ),
);

const puedeEnviar = computed(
    () =>
        seleccionados.value.length > 0 &&
        (movimientos.value.length === 0 || confirmoMovimientos.value) &&
        !form.processing,
);

function enviar(): void {
    if (!puedeEnviar.value) return;
    form.colaborador_ids = seleccionados.value.map((c) => c.id);
    form.post(`/servicios/${props.servicioId}/colaboradores`, {
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
        <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Asignar colaboradores</DialogTitle>
                <DialogDescription>
                    Asigna colaboradores de
                    <span class="font-medium">{{ contratoNombre }}</span> al
                    servicio
                    <span class="font-medium">{{ servicioNombre }}</span
                    >. Sólo se listan colaboradores de la misma empresa. Cambia
                    la misma información que «Cambiar servicio» en la ficha de
                    cada persona.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="enviar">
                <!-- Filtros -->
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label>Filtrar por sucursal</Label>
                        <BuscadorAsync
                            v-model="sucursalSel"
                            :buscar="buscarSucursales"
                            :dependencia="empresaId"
                            :etiqueta="
                                (s) => String((s as OpcionSucursal).nombre)
                            "
                            placeholder="Todas las sucursales"
                            placeholder-busqueda="Buscar sucursal…"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Filtrar por servicio actual</Label>
                        <BuscadorAsync
                            v-model="servicioActualFiltro"
                            :buscar="buscarServicios"
                            :dependencia="empresaId"
                            :disabled="soloSinServicio"
                            :etiqueta="
                                (s) => String((s as OpcionServicio).nombre)
                            "
                            placeholder="Cualquier servicio"
                            placeholder-busqueda="Buscar servicio…"
                        />
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="soloSinServicio"
                        type="checkbox"
                        class="size-4"
                    />
                    Sólo colaboradores sin servicio asignado
                </label>

                <!-- Buscador de colaboradores -->
                <div class="grid gap-1.5">
                    <Label>Agregar colaborador</Label>
                    <BuscadorAsync
                        :model-value="picker"
                        :buscar="buscarCandidatos"
                        :dependencia="dependenciaBusqueda"
                        :etiqueta="
                            (c) => String((c as Candidato).nombre_completo)
                        "
                        :descripcion="
                            (c) =>
                                `N.º ${(c as Candidato).numero_empleado}` +
                                ((c as Candidato).servicio_actual
                                    ? ` · en ${(c as Candidato).servicio_actual?.nombre}`
                                    : '')
                        "
                        placeholder="Buscar por nombre o número de empleado"
                        placeholder-busqueda="Buscar por nombre o número de empleado"
                        sugerencia-busqueda="Escribe para buscar entre los colaboradores de esta empresa."
                        sin-resultados="Ningún colaborador coincide."
                        @update:model-value="
                            (v) => alElegir(v as Candidato | null)
                        "
                    />
                </div>

                <!-- Seleccionados -->
                <div v-if="seleccionados.length" class="grid gap-2">
                    <p class="text-sm font-medium">
                        {{ seleccionados.length }} seleccionado{{
                            seleccionados.length === 1 ? '' : 's'
                        }}
                    </p>
                    <ul class="grid gap-1.5">
                        <li
                            v-for="(c, i) in seleccionados"
                            :key="c.id"
                            class="rounded-lg border p-2"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">
                                        {{ c.nombre_completo }}
                                    </p>
                                    <p
                                        class="text-muted-foreground font-mono text-xs"
                                    >
                                        {{ c.numero_empleado }}
                                        <span v-if="c.sucursal">
                                            · {{ c.sucursal }}</span
                                        >
                                    </p>
                                    <p
                                        v-if="
                                            c.servicio_actual &&
                                            c.servicio_actual.id !== servicioId
                                        "
                                        class="mt-1 flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400"
                                    >
                                        <AlertTriangle
                                            class="size-3 shrink-0"
                                        />
                                        Actualmente en
                                        {{ c.servicio_actual.nombre }} — será
                                        cambiado a {{ servicioNombre }}.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="text-muted-foreground hover:text-foreground shrink-0"
                                    :aria-label="`Quitar ${c.nombre_completo} de la selección`"
                                    @click="quitar(c.id)"
                                >
                                    <X class="size-4" />
                                </button>
                            </div>
                            <InputError
                                :message="erroresLaxos[`colaborador_ids.${i}`]"
                            />
                        </li>
                    </ul>
                    <InputError :message="form.errors.colaborador_ids" />
                </div>
                <p v-else class="text-muted-foreground text-sm">
                    Aún no has seleccionado colaboradores.
                </p>

                <!-- Confirmación de cambios de servicio -->
                <div
                    v-if="movimientos.length"
                    class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
                >
                    <p
                        class="flex items-center gap-1.5 font-medium text-amber-800 dark:text-amber-300"
                    >
                        <AlertTriangle class="size-4 shrink-0" />
                        {{ movimientos.length }} colaborador{{
                            movimientos.length === 1 ? '' : 'es'
                        }}
                        cambiará{{ movimientos.length === 1 ? '' : 'n' }} de
                        servicio
                    </p>
                    <ul
                        class="text-muted-foreground mt-1 list-disc space-y-0.5 pl-5 text-xs"
                    >
                        <li v-for="m in movimientos" :key="m.id">
                            {{ m.nombre_completo }} está en
                            {{ m.servicio_actual?.nombre }} → pasará a
                            {{ servicioNombre }}.
                        </li>
                    </ul>
                    <label class="mt-2 flex items-start gap-2 text-xs">
                        <input
                            v-model="confirmoMovimientos"
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0"
                        />
                        Entiendo que estos colaboradores serán cambiados de su
                        servicio actual.
                    </label>
                </div>

                <div class="grid gap-1.5">
                    <Label for="asignar-motivo">Motivo / observación</Label>
                    <textarea
                        id="asignar-motivo"
                        v-model="form.motivo"
                        rows="2"
                        maxlength="500"
                        placeholder="Opcional — queda en la auditoría de cada colaborador"
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
                    <Button type="submit" :disabled="!puedeEnviar">
                        Asignar
                        {{
                            seleccionados.length
                                ? `(${seleccionados.length})`
                                : ''
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
