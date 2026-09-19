<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Boxes, ExternalLink } from '@lucide/vue';
import { ref } from 'vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
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

type PendienteFila = {
    tipo: 'unidad' | 'cantidad';
    tipo_etiqueta: string;
    activo: string;
    talla: string | null;
    cantidad: number;
    referencia: string | null;
    entrega_id: number | null;
    entrega_folio: string | null;
    detalle_entrega_id: number | null;
    unidad_activo_id: number | null;
    unidad_public_token: string | null;
};

type IncidenciaFila = {
    activo: string;
    talla: string | null;
    cantidad: number;
    tipo: 'robado' | 'perdido';
    tipo_etiqueta: string;
    motivo: string;
    observacion: string | null;
    entrega_folio: string | null;
    usuario: string | null;
    ocurrido_en: string | null;
};

const props = defineProps<{
    colaboradorId: number;
    pendientes: PendienteFila[];
    incidencias: IncidenciaFila[];
    puedeReportar: boolean;
}>();

const dialogoIncidencia = ref(false);
const filaSeleccionada = ref<PendienteFila | null>(null);

const form = useForm<{
    detalle_entrega_id: number | null;
    tipo: 'robado' | 'perdido';
    cantidad: number;
    motivo: string;
    observacion: string;
}>({
    detalle_entrega_id: null,
    tipo: 'robado',
    cantidad: 1,
    motivo: '',
    observacion: '',
});

function abrirReportarIncidencia(fila: PendienteFila): void {
    filaSeleccionada.value = fila;
    form.reset();
    form.clearErrors();
    form.detalle_entrega_id = fila.detalle_entrega_id;
    form.cantidad = fila.cantidad;
    dialogoIncidencia.value = true;
}

function enviarIncidencia(): void {
    form.post(`/colaboradores/${props.colaboradorId}/custodia/incidencia`, {
        preserveScroll: true,
        onSuccess: () => (dialogoIncidencia.value = false),
    });
}

function fecha(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}
</script>

<template>
    <div class="grid gap-4">
        <EstadoVacio
            v-if="!pendientes.length && !incidencias.length"
            titulo="Sin activos bajo custodia"
            descripcion="Este colaborador no tiene nada pendiente de devolución ni incidencias reportadas."
        />

        <section v-if="pendientes.length" class="rounded-xl border p-4">
            <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                <Boxes class="text-muted-foreground size-4" />
                Pendiente de devolución
            </h3>
            <ul class="grid gap-2">
                <li
                    v-for="(f, i) in pendientes"
                    :key="i"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm"
                >
                    <div class="min-w-0">
                        <p class="font-medium">
                            {{ f.activo
                            }}<span
                                v-if="f.talla"
                                class="text-muted-foreground"
                            >
                                · {{ f.talla }}</span
                            >
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ f.tipo_etiqueta }} · Cantidad
                            {{ f.cantidad }}
                            <span v-if="f.entrega_folio">
                                · Entrega {{ f.entrega_folio }}</span
                            >
                            <span v-if="f.tipo === 'unidad' && f.referencia">
                                · {{ f.referencia }}</span
                            >
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="f.tipo === 'unidad' && f.unidad_public_token"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link
                                :href="`/activos/unidades/${f.unidad_public_token}`"
                            >
                                <ExternalLink class="size-3.5" /> Ver unidad
                            </Link>
                        </Button>
                        <Button
                            v-if="f.tipo === 'cantidad' && puedeReportar"
                            variant="outline"
                            size="sm"
                            @click="abrirReportarIncidencia(f)"
                        >
                            <AlertTriangle class="size-3.5" />
                            Reportar robo o pérdida
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <section v-if="incidencias.length" class="rounded-xl border p-4">
            <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                <AlertTriangle class="text-muted-foreground size-4" />
                Robos / pérdidas reportados
            </h3>
            <ul class="grid gap-2">
                <li
                    v-for="(i, idx) in incidencias"
                    :key="idx"
                    class="rounded-lg border p-3 text-sm"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <p class="font-medium">
                            {{ i.activo
                            }}<span
                                v-if="i.talla"
                                class="text-muted-foreground"
                            >
                                · {{ i.talla }}</span
                            >
                        </p>
                        <Badge
                            variant="outline"
                            class="border-destructive/30 text-destructive"
                        >
                            {{ i.tipo_etiqueta }} · {{ i.cantidad }}
                        </Badge>
                    </div>
                    <p class="text-muted-foreground mt-1 text-xs">
                        {{ i.motivo }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-xs">
                        {{ fecha(i.ocurrido_en) }}
                        <span v-if="i.usuario"> · {{ i.usuario }}</span>
                        <span v-if="i.entrega_folio">
                            · Entrega {{ i.entrega_folio }}</span
                        >
                    </p>
                </li>
            </ul>
        </section>

        <Dialog v-model:open="dialogoIncidencia">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Reportar robo o pérdida</DialogTitle>
                    <DialogDescription>
                        Registra que estas piezas ya no van a devolverse porque
                        se perdieron o fueron robadas. El pendiente de
                        devolución del colaborador se actualiza y el reporte
                        queda guardado en el historial.
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-3" @submit.prevent="enviarIncidencia">
                    <dl
                        v-if="filaSeleccionada"
                        class="bg-muted/40 grid gap-1.5 rounded-lg border p-3 text-sm"
                    >
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Activo
                            </dt>
                            <dd class="font-medium">
                                {{ filaSeleccionada.activo
                                }}<span
                                    v-if="filaSeleccionada.talla"
                                    class="text-muted-foreground"
                                >
                                    · {{ filaSeleccionada.talla }}</span
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Pendiente de devolución
                            </dt>
                            <dd class="font-medium">
                                {{ filaSeleccionada.cantidad }}
                            </dd>
                        </div>
                    </dl>

                    <div class="grid gap-1.5">
                        <Label for="incidencia-tipo">Tipo</Label>
                        <SelectSimple
                            id="incidencia-tipo"
                            v-model="form.tipo"
                            :opciones="[
                                { valor: 'robado', etiqueta: 'Robado' },
                                { valor: 'perdido', etiqueta: 'Perdido' },
                            ]"
                        />
                        <InputError :message="form.errors.tipo" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="incidencia-cantidad">Cantidad</Label>
                        <Input
                            id="incidencia-cantidad"
                            v-model.number="form.cantidad"
                            type="number"
                            min="1"
                            :max="filaSeleccionada?.cantidad"
                            step="1"
                        />
                        <InputError :message="form.errors.cantidad" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label
                            for="incidencia-motivo"
                            class="flex items-center gap-1"
                        >
                            Motivo
                            <span class="text-destructive">*</span>
                        </Label>
                        <Input
                            id="incidencia-motivo"
                            v-model="form.motivo"
                            placeholder="p. ej. El colaborador reportó el robo el…"
                        />
                        <InputError :message="form.errors.motivo" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="incidencia-observacion"
                            >Observación
                            <span class="text-muted-foreground"
                                >(opcional)</span
                            ></Label
                        >
                        <Input
                            id="incidencia-observacion"
                            v-model="form.observacion"
                        />
                        <InputError :message="form.errors.observacion" />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="dialogoIncidencia = false"
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            Registrar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
