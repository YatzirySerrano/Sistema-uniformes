<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Package,
    QrCode,
    RotateCcw,
    ScrollText,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type OpcionAlmacen = { id: number; nombre: string; codigo: string | null };

const props = defineProps<{
    unidad: {
        id: number;
        public_token: string;
        codigo: string;
        estado: string;
        estado_etiqueta: string;
        condicion: string;
        condicion_etiqueta: string;
        observaciones: string | null;
        motivo_baja: string | null;
        dado_de_baja_en: string | null;
        incidencia_motivo: string | null;
        incidencia_registrada_en: string | null;
        activo: { id: number; nombre: string | null };
        almacen: { id: number; nombre: string | null };
        empresa: { id: number; nombre_comercial: string | null };
        colaborador: { id: number; nombre_completo: string } | null;
        registrado_por: string | null;
        creada_en: string | null;
    };
    movimientos: {
        tipo: string;
        motivo: string | null;
        ocurrido_en: string;
    }[];
    condicionesIncidencia: { valor: string; etiqueta: string }[];
    condicionesRecuperacion: { valor: string; etiqueta: string }[];
    permisos: { administrar: boolean };
}>();

const esIncidencia = ['perdido', 'robado'].includes(props.unidad.condicion);

const dialogoIncidencia = ref(false);
const formIncidencia = useForm({
    tipo: 'perdido',
    motivo: '',
    observacion: '',
});

function reportarIncidencia(): void {
    formIncidencia.post(
        `/activos/unidades/${props.unidad.public_token}/incidencia`,
        {
            preserveScroll: true,
            onSuccess: () => {
                dialogoIncidencia.value = false;
                formIncidencia.reset();
            },
        },
    );
}

const dialogoRecuperar = ref(false);
const almacenDestino = ref<OpcionAlmacen | null>(null);
const formRecuperar = useForm<{
    almacen_id: number | null;
    condicion_resultante: string;
    notas: string;
}>({
    almacen_id: null,
    condicion_resultante: 'funcionando',
    notas: '',
});

async function buscarAlmacenes(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionAlmacen[]> {
    const res = await fetch(
        `/almacenes/buscar?empresa_id=${props.unidad.empresa.id}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).almacenes ?? [];
}

function recuperarUnidad(): void {
    formRecuperar.post(
        `/activos/unidades/${props.unidad.public_token}/recuperar`,
        {
            preserveScroll: true,
            onSuccess: () => {
                dialogoRecuperar.value = false;
                formRecuperar.reset();
                almacenDestino.value = null;
            },
        },
    );
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Activos', href: '/activos' },
            { title: 'Unidades', href: '/activos/unidades' },
            { title: 'Detalle', href: '#' },
        ],
    },
});

const dialogoBaja = ref(false);
const form = useForm({ motivo: '' });

function darDeBaja(): void {
    form.post(`/activos/unidades/${props.unidad.public_token}/baja`, {
        preserveScroll: true,
        onSuccess: () => {
            dialogoBaja.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <Head :title="`Unidad ${unidad.codigo}`" />

    <div class="flex w-full flex-col gap-4 p-4">
        <Button variant="ghost" size="sm" as-child class="w-fit">
            <Link href="/activos/unidades">
                <ArrowLeft class="size-4" /> Volver a unidades
            </Link>
        </Button>

        <div
            class="flex flex-col gap-4 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0">
                <h1
                    class="flex items-center gap-2 truncate font-mono text-xl font-semibold tracking-tight"
                >
                    <QrCode class="text-muted-foreground size-5 shrink-0" />
                    {{ unidad.codigo }}
                </h1>
                <p class="text-muted-foreground text-sm">
                    {{ unidad.activo.nombre }}
                </p>
                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    <Badge>{{ unidad.estado_etiqueta }}</Badge>
                    <Badge variant="outline">{{
                        unidad.condicion_etiqueta
                    }}</Badge>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button variant="outline" size="sm" as-child>
                    <a
                        :href="`/activos/unidades/etiquetas?ids=${unidad.id}`"
                        target="_blank"
                    >
                        <QrCode class="size-3.5" /> Generar etiqueta
                    </a>
                </Button>
                <Button
                    v-if="
                        permisos.administrar &&
                        unidad.estado === 'asignada' &&
                        !esIncidencia
                    "
                    variant="outline"
                    size="sm"
                    @click="dialogoIncidencia = true"
                >
                    <AlertTriangle class="size-3.5" /> Reportar pérdida / robo
                </Button>
                <Button
                    v-if="permisos.administrar && esIncidencia"
                    variant="outline"
                    size="sm"
                    @click="dialogoRecuperar = true"
                >
                    <RotateCcw class="size-3.5" /> Recuperar unidad
                </Button>
                <Button
                    v-if="permisos.administrar && unidad.estado !== 'baja'"
                    variant="destructive"
                    size="sm"
                    @click="dialogoBaja = true"
                >
                    <Trash2 class="size-3.5" /> Dar de baja
                </Button>
            </div>
        </div>

        <div
            v-if="esIncidencia"
            class="border-destructive/40 bg-destructive/5 rounded-xl border p-4 text-sm"
        >
            <p class="text-destructive flex items-center gap-1.5 font-medium">
                <AlertTriangle class="size-4" />
                Esta unidad está reportada como {{ unidad.condicion_etiqueta }}
            </p>
            <p
                v-if="unidad.incidencia_motivo"
                class="text-muted-foreground mt-1"
            >
                {{ unidad.incidencia_motivo }}
            </p>
            <p
                v-if="unidad.incidencia_registrada_en"
                class="text-muted-foreground text-xs"
            >
                Registrada el {{ unidad.incidencia_registrada_en }}
            </p>
            <p class="text-muted-foreground mt-1">
                No cuenta como stock ni es entregable hasta que se recupere de
                forma explícita. Conserva el último responsable para
                trazabilidad.
            </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl border p-4">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <Package class="text-muted-foreground size-4" />
                    Ubicación y posesión
                </h2>
                <dl class="grid gap-3 text-sm">
                    <div>
                        <dt class="text-muted-foreground text-xs">Empresa</dt>
                        <dd>{{ unidad.empresa.nombre_comercial }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Almacén</dt>
                        <dd>{{ unidad.almacen.nombre ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Colaborador actual
                        </dt>
                        <dd>
                            {{
                                unidad.colaborador?.nombre_completo ??
                                'Sin asignar'
                            }}
                        </dd>
                    </div>
                    <div v-if="unidad.motivo_baja">
                        <dt class="text-muted-foreground text-xs">
                            Motivo de baja
                        </dt>
                        <dd>{{ unidad.motivo_baja }}</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-xl border p-4">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <ScrollText class="text-muted-foreground size-4" />
                    Historial de movimientos
                </h2>
                <p
                    v-if="!movimientos.length"
                    class="text-muted-foreground text-sm"
                >
                    Sin movimientos registrados.
                </p>
                <ul v-else class="flex flex-col gap-2 text-sm">
                    <li
                        v-for="(m, i) in movimientos"
                        :key="i"
                        class="border-t pt-2 first:border-t-0 first:pt-0"
                    >
                        <p class="font-medium">{{ m.tipo }}</p>
                        <p class="text-muted-foreground text-xs">
                            {{ m.ocurrido_en }}
                            <span v-if="m.motivo"> · {{ m.motivo }}</span>
                        </p>
                    </li>
                </ul>
            </section>
        </div>

        <Dialog v-model:open="dialogoBaja">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Dar de baja esta unidad</DialogTitle>
                    <DialogDescription>
                        La unidad queda fuera de operación de forma permanente
                        (nunca se borra). Esta acción no se puede deshacer desde
                        aquí.
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-3" @submit.prevent="darDeBaja">
                    <div class="grid gap-1.5">
                        <Label for="baja-motivo">Motivo</Label>
                        <Input
                            id="baja-motivo"
                            v-model="form.motivo"
                            placeholder="p. ej. Equipo obsoleto"
                        />
                        <InputError :message="form.errors.motivo" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="dialogoBaja = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="form.processing"
                        >
                            Dar de baja
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="dialogoIncidencia">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Reportar pérdida o robo</DialogTitle>
                    <DialogDescription>
                        La unidad deja de contar como stock y ya no es
                        entregable. Conserva el último responsable para
                        trazabilidad; NO representa una devolución física.
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-3" @submit.prevent="reportarIncidencia">
                    <div class="grid gap-1.5">
                        <Label for="incidencia-tipo">Tipo</Label>
                        <select
                            id="incidencia-tipo"
                            v-model="formIncidencia.tipo"
                            class="border-input bg-background h-9 rounded-md border px-2.5 text-sm"
                        >
                            <option
                                v-for="c in condicionesIncidencia"
                                :key="c.valor"
                                :value="c.valor"
                            >
                                {{ c.etiqueta }}
                            </option>
                        </select>
                        <InputError :message="formIncidencia.errors.tipo" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="incidencia-motivo">Motivo</Label>
                        <Input
                            id="incidencia-motivo"
                            v-model="formIncidencia.motivo"
                            placeholder="p. ej. No se localiza tras el cambio de turno"
                        />
                        <InputError :message="formIncidencia.errors.motivo" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="incidencia-observacion"
                            >Observaciones
                            <span class="text-muted-foreground"
                                >(opcional)</span
                            ></Label
                        >
                        <textarea
                            id="incidencia-observacion"
                            v-model="formIncidencia.observacion"
                            rows="2"
                            class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        />
                        <InputError
                            :message="formIncidencia.errors.observacion"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="dialogoIncidencia = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="formIncidencia.processing"
                        >
                            Reportar incidencia
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="dialogoRecuperar">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Recuperar unidad</DialogTitle>
                    <DialogDescription>
                        Único camino explícito para que esta unidad vuelva a
                        operar. Elige el almacén de destino y la condición con
                        la que regresa.
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-3" @submit.prevent="recuperarUnidad">
                    <div class="grid gap-1.5">
                        <Label for="recuperar-almacen">Almacén destino</Label>
                        <BuscadorAsync
                            id="recuperar-almacen"
                            :model-value="almacenDestino"
                            :buscar="buscarAlmacenes"
                            :etiqueta="(a) => (a as OpcionAlmacen).nombre"
                            :descripcion="
                                (a) => (a as OpcionAlmacen).codigo ?? ''
                            "
                            placeholder="Selecciona un almacén"
                            placeholder-busqueda="Buscar almacén por nombre"
                            sin-resultados="Ese almacén no abastece a esta empresa."
                            :invalido="!!formRecuperar.errors.almacen_id"
                            @update:model-value="
                                (v) => {
                                    almacenDestino = v as OpcionAlmacen | null;
                                    formRecuperar.almacen_id =
                                        (v as OpcionAlmacen | null)?.id ?? null;
                                    formRecuperar.clearErrors('almacen_id');
                                }
                            "
                        />
                        <InputError
                            :message="formRecuperar.errors.almacen_id"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="recuperar-condicion"
                            >Condición al regresar</Label
                        >
                        <select
                            id="recuperar-condicion"
                            v-model="formRecuperar.condicion_resultante"
                            class="border-input bg-background h-9 rounded-md border px-2.5 text-sm"
                        >
                            <option
                                v-for="c in condicionesRecuperacion"
                                :key="c.valor"
                                :value="c.valor"
                            >
                                {{ c.etiqueta }}
                            </option>
                        </select>
                        <InputError
                            :message="formRecuperar.errors.condicion_resultante"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="recuperar-notas"
                            >Notas
                            <span class="text-muted-foreground"
                                >(opcional)</span
                            ></Label
                        >
                        <Input
                            id="recuperar-notas"
                            v-model="formRecuperar.notas"
                        />
                        <InputError :message="formRecuperar.errors.notas" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="dialogoRecuperar = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            :disabled="formRecuperar.processing"
                        >
                            Recuperar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
