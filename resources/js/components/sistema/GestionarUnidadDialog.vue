<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type OpcionUnidad = {
    id: number;
    public_token: string;
    codigo: string;
    activo: string | null;
    almacen: string | null;
    estado: 'en_almacen' | 'asignada' | 'baja';
    condicion: 'funcionando' | 'en_reparacion' | 'inservible' | 'perdido' | 'robado';
    estado_visible_etiqueta: string;
    condicion_etiqueta: string;
};
type OpcionCondicion = { valor: string; etiqueta: string };
type OpcionAlmacen = { id: number; nombre: string; codigo: string | null };
type Accion = 'incidencia' | 'baja' | 'recuperar' | 'restaurar';

const props = defineProps<{
    open: boolean;
    activoId: number;
    empresaId: number;
    condicionesIncidencia: OpcionCondicion[];
    condicionesNoIncidencia: OpcionCondicion[];
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();

const unidadSel = ref<OpcionUnidad | null>(null);
const accion = ref<Accion | null>(null);

const esIncidencia = computed(
    () => unidadSel.value?.condicion === 'perdido' || unidadSel.value?.condicion === 'robado',
);

// Acciones que tienen sentido según el estado/condición ACTUAL de la unidad
// elegida — sólo un hint de UX; el backend siempre revalida la transición
// real al confirmar (`App\Acciones\{MarcarUnidadIncidencia,DarDeBajaUnidadActivo,
// RecuperarUnidadActivo,RestaurarCondicionUnidadActivo}`, sin cambios).
const accionesDisponibles = computed<{ valor: Accion; etiqueta: string }[]>(() => {
    if (!unidadSel.value) return [];
    const u = unidadSel.value;
    const opciones: { valor: Accion; etiqueta: string }[] = [];

    if (u.estado === 'asignada') {
        opciones.push({ valor: 'incidencia', etiqueta: 'Marcar incidencia (pérdida / robo)' });
    }
    if (u.estado === 'en_almacen' && u.condicion === 'funcionando') {
        opciones.push({ valor: 'baja', etiqueta: 'Dar de baja' });
    }
    if (esIncidencia.value) {
        opciones.push({ valor: 'recuperar', etiqueta: 'Recuperar unidad' });
    }
    if (u.estado === 'en_almacen' && (u.condicion === 'en_reparacion' || u.condicion === 'inservible')) {
        opciones.push({ valor: 'restaurar', etiqueta: 'Restaurar condición' });
    }

    return opciones;
});

const form = useForm<{
    tipo: string;
    motivo: string;
    observacion: string;
    almacen_id: number | null;
    condicion_resultante: string;
    notas: string;
}>({
    tipo: '',
    motivo: '',
    observacion: '',
    almacen_id: null,
    condicion_resultante: '',
    notas: '',
});

const almacenSel = ref<OpcionAlmacen | null>(null);

async function buscarUnidades(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionUnidad[]> {
    const res = await fetch(
        `/activos/unidades/buscar?activo_id=${props.activoId}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).unidades ?? [];
}

async function buscarAlmacenes(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionAlmacen[]> {
    const res = await fetch(
        `/almacenes/buscar?empresa_id=${props.empresaId}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).almacenes ?? [];
}

function elegirUnidad(u: OpcionUnidad | null): void {
    unidadSel.value = u;
    accion.value = null;
    form.reset();
    form.clearErrors();
    almacenSel.value = null;
}

watch(accion, () => {
    form.reset();
    form.clearErrors();
    almacenSel.value = null;
});

watch(
    () => props.open,
    (abierto) => {
        if (abierto) {
            unidadSel.value = null;
            accion.value = null;
            form.reset();
            form.clearErrors();
            almacenSel.value = null;
        }
    },
);

function enviar(): void {
    if (!unidadSel.value || !accion.value) return;
    const token = unidadSel.value.public_token;

    const rutas: Record<Accion, string> = {
        incidencia: `/activos/unidades/${token}/incidencia`,
        baja: `/activos/unidades/${token}/baja`,
        recuperar: `/activos/unidades/${token}/recuperar`,
        restaurar: `/activos/unidades/${token}/restaurar-condicion`,
    };

    form.post(rutas[accion.value], {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Gestionar unidad</DialogTitle>
                <DialogDescription>
                    Busca una unidad de este activo para cambiar su estado o
                    condición, sin salir del detalle del activo.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-3">
                <div class="grid gap-1.5">
                    <Label for="gu-unidad">Unidad</Label>
                    <BuscadorAsync
                        id="gu-unidad"
                        :model-value="unidadSel"
                        :buscar="buscarUnidades"
                        :etiqueta="(u) => (u as OpcionUnidad).codigo"
                        :descripcion="
                            (u) =>
                                `${(u as OpcionUnidad).estado_visible_etiqueta} · ${(u as OpcionUnidad).condicion_etiqueta}`
                        "
                        placeholder="Buscar unidad por código…"
                        placeholder-busqueda="Buscar por código, IMEI, marca…"
                        @update:model-value="
                            (v) => elegirUnidad(v as OpcionUnidad | null)
                        "
                    />
                </div>

                <template v-if="unidadSel">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <Badge variant="outline">{{
                            unidadSel.estado_visible_etiqueta
                        }}</Badge>
                        <Badge variant="outline">{{
                            unidadSel.condicion_etiqueta
                        }}</Badge>
                        <span class="text-muted-foreground">{{
                            unidadSel.almacen ?? '—'
                        }}</span>
                    </div>

                    <p
                        v-if="!accionesDisponibles.length"
                        class="text-muted-foreground text-sm"
                    >
                        Esta unidad no tiene ninguna acción disponible en su
                        estado/condición actual.
                    </p>

                    <div v-else class="grid gap-1.5">
                        <Label for="gu-accion">Acción</Label>
                        <SelectSimple
                            id="gu-accion"
                            v-model="accion"
                            :opciones="accionesDisponibles"
                            placeholder="Elige una acción"
                        />
                    </div>

                    <!-- Marcar incidencia -->
                    <template v-if="accion === 'incidencia'">
                        <div class="grid gap-1.5">
                            <Label for="gu-tipo">Tipo</Label>
                            <SelectSimple
                                id="gu-tipo"
                                v-model="form.tipo"
                                :opciones="
                                    condicionesIncidencia.map((c) => ({
                                        valor: c.valor,
                                        etiqueta: c.etiqueta,
                                    }))
                                "
                                placeholder="Pérdida o robo"
                            />
                            <InputError :message="form.errors.tipo" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="gu-motivo-inc">Motivo</Label>
                            <Input id="gu-motivo-inc" v-model="form.motivo" />
                            <InputError :message="form.errors.motivo" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="gu-obs"
                                >Observación
                                <span class="text-muted-foreground"
                                    >(opcional)</span
                                ></Label
                            >
                            <Input id="gu-obs" v-model="form.observacion" />
                            <InputError :message="form.errors.observacion" />
                        </div>
                    </template>

                    <!-- Dar de baja -->
                    <template v-else-if="accion === 'baja'">
                        <div class="grid gap-1.5">
                            <Label for="gu-motivo-baja">Motivo de la baja</Label>
                            <Input id="gu-motivo-baja" v-model="form.motivo" />
                            <InputError :message="form.errors.motivo" />
                        </div>
                    </template>

                    <!-- Recuperar (pérdida/robo -> vuelve a operar) -->
                    <template v-else-if="accion === 'recuperar'">
                        <div class="grid gap-1.5">
                            <Label for="gu-almacen">Almacén de destino</Label>
                            <BuscadorAsync
                                id="gu-almacen"
                                :model-value="almacenSel"
                                :buscar="buscarAlmacenes"
                                :etiqueta="(a) => (a as OpcionAlmacen).nombre"
                                placeholder="Selecciona un almacén"
                                placeholder-busqueda="Buscar almacén"
                                :invalido="!!form.errors.almacen_id"
                                @update:model-value="
                                    (v) => {
                                        almacenSel = v as OpcionAlmacen | null;
                                        form.almacen_id =
                                            (v as OpcionAlmacen | null)?.id ??
                                            null;
                                        form.clearErrors('almacen_id');
                                    }
                                "
                            />
                            <InputError :message="form.errors.almacen_id" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="gu-condicion-rec"
                                >Condición con la que regresa</Label
                            >
                            <SelectSimple
                                id="gu-condicion-rec"
                                v-model="form.condicion_resultante"
                                :opciones="
                                    condicionesNoIncidencia.map((c) => ({
                                        valor: c.valor,
                                        etiqueta: c.etiqueta,
                                    }))
                                "
                                placeholder="Elige la condición"
                            />
                            <InputError
                                :message="form.errors.condicion_resultante"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="gu-notas-rec"
                                >Notas
                                <span class="text-muted-foreground"
                                    >(opcional)</span
                                ></Label
                            >
                            <Input id="gu-notas-rec" v-model="form.notas" />
                            <InputError :message="form.errors.notas" />
                        </div>
                    </template>

                    <!-- Restaurar condición (en reparación / inservible) -->
                    <template v-else-if="accion === 'restaurar'">
                        <div class="grid gap-1.5">
                            <Label for="gu-condicion-res"
                                >Condición con la que queda</Label
                            >
                            <SelectSimple
                                id="gu-condicion-res"
                                v-model="form.condicion_resultante"
                                :opciones="
                                    condicionesNoIncidencia.map((c) => ({
                                        valor: c.valor,
                                        etiqueta: c.etiqueta,
                                    }))
                                "
                                placeholder="Elige la condición"
                            />
                            <InputError
                                :message="form.errors.condicion_resultante"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="gu-notas-res"
                                >Notas
                                <span class="text-muted-foreground"
                                    >(opcional)</span
                                ></Label
                            >
                            <Input id="gu-notas-res" v-model="form.notas" />
                            <InputError :message="form.errors.notas" />
                        </div>
                    </template>
                </template>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="ghost"
                    @click="emit('update:open', false)"
                >
                    Cancelar
                </Button>
                <Button
                    type="button"
                    :disabled="!accion || form.processing"
                    @click="enviar"
                >
                    Guardar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
