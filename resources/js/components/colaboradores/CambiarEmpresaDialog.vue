<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { CircleAlert, Loader2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import InputError from '@/components/InputError.vue';
import { usePermisos } from '@/composables/usePermisos';
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

type Opcion = { id: number; nombre: string };
type EmpresaOpcion = { id: number; nombre: string };
type CustodiaFila = {
    tipo: string;
    tipo_etiqueta: string;
    activo: string;
    talla: string | null;
    cantidad: number;
    referencia: string | null;
};

const props = defineProps<{
    open: boolean;
    colaboradorId: number;
    empresaActualId: number;
    empresaActualNombre: string | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const { empresasAutorizadas } = usePermisos();

const paso = ref<1 | 2 | 3>(1);
const empresaSel = ref<EmpresaOpcion | null>(null);
const sucursalSel = ref<Opcion | null>(null);
const areaSel = ref<Opcion | null>(null);

const form = useForm<{
    empresa_destino_id: number | null;
    sucursal_destino_id: number | null;
    area_destino_id: number | null;
    motivo: string;
}>({
    empresa_destino_id: null,
    sucursal_destino_id: null,
    area_destino_id: null,
    motivo: '',
});

const cargandoCustodia = ref(false);
const custodia = ref<CustodiaFila[]>([]);
const tienePendientes = ref(false);
const errorCustodia = ref<string | null>(null);

function reiniciar(): void {
    paso.value = 1;
    empresaSel.value = null;
    sucursalSel.value = null;
    areaSel.value = null;
    custodia.value = [];
    tienePendientes.value = false;
    errorCustodia.value = null;
    form.reset();
    form.clearErrors();
}

watch(
    () => props.open,
    (abierto) => {
        if (abierto) reiniciar();
    },
);

watch(empresaSel, (e) => {
    form.empresa_destino_id = e?.id ?? null;
    sucursalSel.value = null;
    areaSel.value = null;
    form.sucursal_destino_id = null;
    form.area_destino_id = null;
});
watch(sucursalSel, (s) => {
    form.sucursal_destino_id = s?.id ?? null;
});
watch(areaSel, (a) => {
    form.area_destino_id = a?.id ?? null;
});

// Empresas de destino posibles: las autorizadas del usuario, menos la actual.
async function buscarEmpresas(termino: string): Promise<EmpresaOpcion[]> {
    const q = termino.trim().toLowerCase();
    return empresasAutorizadas.value
        .filter((e) => e.id !== props.empresaActualId)
        .filter((e) => q === '' || e.nombre_comercial.toLowerCase().includes(q))
        .map((e) => ({ id: e.id, nombre: e.nombre_comercial }));
}

async function buscarSucursales(
    termino: string,
    signal?: AbortSignal,
): Promise<Opcion[]> {
    if (empresaSel.value === null) return [];
    const params = new URLSearchParams({
        empresa_id: String(empresaSel.value.id),
        q: termino,
    });
    const res = await fetch(`/sucursales/buscar?${params}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];
    return ((await res.json()).sucursales ?? []) as Opcion[];
}

async function buscarAreas(
    termino: string,
    signal?: AbortSignal,
): Promise<Opcion[]> {
    if (empresaSel.value === null) return [];
    const params = new URLSearchParams({
        empresa_id: String(empresaSel.value.id),
        q: termino,
    });
    const res = await fetch(`/areas/buscar?${params}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];
    return ((await res.json()).areas ?? []) as Opcion[];
}

const paso1Completo = computed(
    () => empresaSel.value !== null && sucursalSel.value !== null,
);

async function irAPaso2(): Promise<void> {
    if (!paso1Completo.value) return;
    paso.value = 2;
    cargandoCustodia.value = true;
    errorCustodia.value = null;
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
        custodia.value = (data.pendientes ?? []) as CustodiaFila[];
        tienePendientes.value = Boolean(data.tiene_pendientes);
    } catch {
        errorCustodia.value =
            'No se pudo consultar la custodia pendiente. Inténtalo de nuevo.';
        tienePendientes.value = true; // fail-closed: no permitir confirmar a ciegas
    } finally {
        cargandoCustodia.value = false;
    }
}

const puedeConfirmar = computed(
    () =>
        paso.value === 3 &&
        !tienePendientes.value &&
        !cargandoCustodia.value &&
        form.motivo.trim().length > 0 &&
        !form.processing,
);

function confirmar(): void {
    if (!puedeConfirmar.value) return;
    form.transform((d) => ({
        ...d,
        area_destino_id: d.area_destino_id ?? undefined,
    })).post(`/colaboradores/${props.colaboradorId}/cambiar-empresa`, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Transferir a otra empresa</DialogTitle>
                <DialogDescription>
                    Cambia la empresa / razón social del colaborador conservando
                    el mismo registro, expediente e historial. Es distinto de
                    «Cambiar servicio»: sólo se puede cuando no tiene activos
                    pendientes de devolución, y el servicio queda sin asignar.
                </DialogDescription>
            </DialogHeader>

            <ol
                class="text-muted-foreground flex items-center gap-2 text-xs font-medium"
            >
                <li :class="{ 'text-foreground': paso === 1 }">1 · Destino</li>
                <li aria-hidden="true">›</li>
                <li :class="{ 'text-foreground': paso === 2 }">2 · Custodia</li>
                <li aria-hidden="true">›</li>
                <li :class="{ 'text-foreground': paso === 3 }">
                    3 · Confirmar
                </li>
            </ol>

            <!-- Paso 1: destino -->
            <div v-if="paso === 1" class="space-y-4">
                <div class="bg-muted/40 rounded-lg p-3 text-sm">
                    <span class="text-muted-foreground text-xs"
                        >Empresa actual</span
                    >
                    <p class="font-medium">{{ empresaActualNombre ?? '—' }}</p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="ce-empresa">Empresa de destino</Label>
                    <BuscadorAsync
                        id="ce-empresa"
                        v-model="empresaSel"
                        :buscar="buscarEmpresas"
                        :etiqueta="(e) => String(e.nombre)"
                        placeholder="Selecciona la empresa de destino"
                        placeholder-busqueda="Buscar empresa…"
                        sin-resultados="No tienes acceso a otra empresa."
                    />
                    <InputError :message="form.errors.empresa_destino_id" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="ce-sucursal">Sucursal de destino</Label>
                    <BuscadorAsync
                        id="ce-sucursal"
                        v-model="sucursalSel"
                        :buscar="buscarSucursales"
                        :etiqueta="(s) => String(s.nombre)"
                        :dependencia="empresaSel?.id ?? null"
                        :disabled="empresaSel === null"
                        placeholder="Selecciona la sucursal de destino"
                        placeholder-busqueda="Buscar sucursal…"
                        sin-resultados="No hay sucursales activas disponibles."
                    />
                    <InputError :message="form.errors.sucursal_destino_id" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="ce-area">
                        Área / Departamento de destino
                        <span class="text-muted-foreground">(opcional)</span>
                    </Label>
                    <BuscadorAsync
                        id="ce-area"
                        v-model="areaSel"
                        :buscar="buscarAreas"
                        :etiqueta="(a) => String(a.nombre)"
                        :dependencia="empresaSel?.id ?? null"
                        :disabled="empresaSel === null"
                        placeholder="Sin área"
                        placeholder-busqueda="Buscar área…"
                        sin-resultados="No hay áreas activas en esta empresa."
                    />
                    <InputError :message="form.errors.area_destino_id" />
                </div>
            </div>

            <!-- Paso 2: custodia pendiente -->
            <div v-else-if="paso === 2" class="space-y-3">
                <p
                    v-if="cargandoCustodia"
                    class="text-muted-foreground flex items-center gap-2 text-sm"
                >
                    <Loader2 class="size-4 animate-spin" /> Revisando activos en
                    custodia…
                </p>

                <template v-else>
                    <p
                        v-if="errorCustodia"
                        class="flex items-start gap-2 text-sm text-red-600 dark:text-red-400"
                    >
                        <CircleAlert class="mt-0.5 size-4 shrink-0" />
                        {{ errorCustodia }}
                    </p>

                    <div
                        v-else-if="tienePendientes"
                        class="space-y-2 rounded-lg border border-red-500/40 bg-red-50/60 p-3 text-sm dark:bg-red-950/30"
                    >
                        <p
                            class="flex items-start gap-2 font-medium text-red-700 dark:text-red-400"
                        >
                            <CircleAlert class="mt-0.5 size-4 shrink-0" />
                            Primero registra las devoluciones
                        </p>
                        <ul class="list-disc space-y-0.5 pl-6">
                            <li v-for="(f, i) in custodia" :key="i">
                                {{ f.cantidad }} ·
                                {{ f.activo }}
                                <span v-if="f.talla">({{ f.talla }})</span>
                                <span
                                    v-if="f.referencia"
                                    class="text-muted-foreground"
                                >
                                    — {{ f.referencia }}
                                </span>
                            </li>
                        </ul>
                        <Button as-child variant="outline" size="sm">
                            <Link href="/devoluciones/crear"
                                >Ir a Devoluciones</Link
                            >
                        </Button>
                    </div>

                    <p
                        v-else
                        class="rounded-lg border border-emerald-500/40 bg-emerald-50/60 p-3 text-sm text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400"
                    >
                        El colaborador no tiene activos pendientes de
                        devolución. Puedes continuar.
                    </p>
                </template>
            </div>

            <!-- Paso 3: confirmación -->
            <div v-else class="space-y-4">
                <dl class="grid gap-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">Empresa</dt>
                        <dd class="text-right">
                            {{ empresaActualNombre ?? '—' }} →
                            <strong>{{ empresaSel?.nombre }}</strong>
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">Sucursal destino</dt>
                        <dd class="text-right font-medium">
                            {{ sucursalSel?.nombre }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">Área destino</dt>
                        <dd class="text-right">{{ areaSel?.nombre ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">Servicio</dt>
                        <dd class="text-right">Quedará sin asignar</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">N.º de empleado</dt>
                        <dd class="text-right">
                            Se generará uno nuevo para la empresa de destino
                        </dd>
                    </div>
                </dl>

                <div class="grid gap-1.5">
                    <Label for="ce-motivo">Motivo de la transferencia</Label>
                    <textarea
                        id="ce-motivo"
                        v-model="form.motivo"
                        rows="2"
                        maxlength="500"
                        placeholder="Ej. Reasignación de contrato del cliente"
                        class="border-input bg-background focus-visible:ring-ring min-h-[60px] rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
                    ></textarea>
                    <InputError :message="form.errors.motivo" />
                </div>
            </div>

            <DialogFooter class="gap-2 sm:gap-2">
                <Button
                    v-if="paso === 1"
                    type="button"
                    variant="ghost"
                    @click="emit('update:open', false)"
                >
                    Cancelar
                </Button>
                <Button
                    v-else
                    type="button"
                    variant="ghost"
                    :disabled="form.processing"
                    @click="paso = ((paso as number) - 1) as 1 | 2 | 3"
                >
                    Atrás
                </Button>

                <Button
                    v-if="paso === 1"
                    type="button"
                    :disabled="!paso1Completo"
                    @click="irAPaso2"
                >
                    Continuar
                </Button>
                <Button
                    v-else-if="paso === 2"
                    type="button"
                    :disabled="cargandoCustodia || tienePendientes"
                    @click="paso = 3"
                >
                    Continuar
                </Button>
                <Button
                    v-else
                    type="button"
                    :disabled="!puedeConfirmar"
                    @click="confirmar"
                >
                    Confirmar transferencia
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
