<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ImagePlus,
    Maximize2,
    Package,
    QrCode,
    RotateCcw,
    ScrollText,
    Trash2,
    Wrench,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import BotonVer from '@/components/sistema/BotonVer.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import CapturaEvidencia from '@/components/sistema/CapturaEvidencia.vue';
import InputError from '@/components/InputError.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Badge } from '@/components/ui/badge';
import { claseEstadoVisibleUnidad } from '@/lib/estadoVisibleUnidad';
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
import { fechaHora } from '@/lib/fecha';

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
        estado_visible: string;
        estado_visible_etiqueta: string;
        estado_visible_descripcion: string;
        observaciones: string | null;
        motivo_baja: string | null;
        dado_de_baja_en: string | null;
        incidencia_motivo: string | null;
        incidencia_registrada_en: string | null;
        activo: { id: number; nombre: string | null };
        almacen: { id: number; nombre: string | null };
        empresa: { id: number; nombre_comercial: string | null };
        colaborador: { id: number; nombre_completo: string } | null;
        ubicacion_operativa:
            | {
                  tipo: 'almacen';
                  almacen: { id: number; nombre: string } | null;
              }
            | { tipo: 'servicio'; contrato: string; servicio: string }
            | { tipo: 'sin_servicio' }
            | { tipo: 'baja' };
        registrado_por: string | null;
        creada_en: string | null;
        perfil_tecnico: string | null;
        perfil_tecnico_etiqueta: string | null;
        datos_equipo: {
            campo: string;
            etiqueta: string;
            valor: string | null;
        }[];
        especificacion: {
            marca: string | null;
            modelo: string | null;
            imei: string | null;
            numero_telefonico: string | null;
            operador: string | null;
            plan: string | null;
        } | null;
        imagen_url: string | null;
    };
    movimientos: {
        tipo: string;
        motivo: string | null;
        ocurrido_en: string;
        referencia: { tipo: string; etiqueta: string; url: string } | null;
    }[];
    condicionesIncidencia: { valor: string; etiqueta: string }[];
    condicionesRecuperacion: { valor: string; etiqueta: string }[];
    condicionesRestauracion: { valor: string; etiqueta: string }[];
    condicionesDano: { valor: string; etiqueta: string }[];
    permisos: { administrar: boolean };
}>();

const esIncidencia = ['perdido', 'robado'].includes(props.unidad.condicion);
// No entregable por condición (en reparación / inservible) pero SIN ser
// pérdida/robo: se restaura con su propio flujo, nunca con "Recuperar unidad"
// (ese es exclusivo de incidencias) — misma unidad, nunca cambia su almacén.
const noEntregablePorCondicion =
    ['en_reparacion', 'inservible'].includes(props.unidad.condicion) &&
    props.unidad.estado === 'en_almacen';
// Único camino válido para "Marcar dañada" (ver `MarcarCondicionUnidadRequest`):
// misma condición que exige el backend, en_almacen + funcionando.
const puedeMarcarDanada =
    props.unidad.estado === 'en_almacen' &&
    props.unidad.condicion === 'funcionando';

const dialogoDanar = ref(false);
const formDanar = useForm({
    condicion_resultante: 'en_reparacion',
    motivo: '',
});

function marcarDanada(): void {
    formDanar.post(`/activos/unidades/${props.unidad.public_token}/danar`, {
        preserveScroll: true,
        onSuccess: () => {
            dialogoDanar.value = false;
            formDanar.reset();
        },
    });
}

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

const dialogoRestaurar = ref(false);
const formRestaurar = useForm<{
    condicion_resultante: string;
    notas: string;
}>({
    condicion_resultante: 'funcionando',
    notas: '',
});

function restaurarCondicion(): void {
    formRestaurar.post(
        `/activos/unidades/${props.unidad.public_token}/restaurar-condicion`,
        {
            preserveScroll: true,
            onSuccess: () => {
                dialogoRestaurar.value = false;
                formRestaurar.reset();
            },
        },
    );
}

/* --- Foto de la unidad (opcional, 1:1): subir/reemplazar/quitar. Nunca
   cambia codigo/public_token/IMEI/estado/condicion. --- */
const formImagen = useForm<{ imagen: File | null }>({ imagen: null });
const origenImagen = ref<'camara' | 'archivo' | null>(null);

watch(
    () => formImagen.imagen,
    (archivo) => {
        if (!archivo) return;
        formImagen.post(
            `/activos/unidades/${props.unidad.public_token}/imagen`,
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => {
                    formImagen.imagen = null;
                    origenImagen.value = null;
                },
            },
        );
    },
);

function quitarImagenUnidad(): void {
    router.delete(`/activos/unidades/${props.unidad.public_token}/imagen`, {
        preserveScroll: true,
    });
}

const dialogoImagenAmpliada = ref(false);

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

/* --- Editar datos del equipo --- */
type CampoEquipo =
    | 'marca'
    | 'modelo'
    | 'imei'
    | 'numero_telefonico'
    | 'operador'
    | 'plan';
const dialogoEquipo = ref(false);
const formEquipo = useForm({
    marca: props.unidad.especificacion?.marca ?? '',
    modelo: props.unidad.especificacion?.modelo ?? '',
    imei: props.unidad.especificacion?.imei ?? '',
    numero_telefonico: props.unidad.especificacion?.numero_telefonico ?? '',
    operador: props.unidad.especificacion?.operador ?? '',
    plan: props.unidad.especificacion?.plan ?? '',
});

function abrirEditarEquipo(): void {
    formEquipo.defaults({
        marca: props.unidad.especificacion?.marca ?? '',
        modelo: props.unidad.especificacion?.modelo ?? '',
        imei: props.unidad.especificacion?.imei ?? '',
        numero_telefonico: props.unidad.especificacion?.numero_telefonico ?? '',
        operador: props.unidad.especificacion?.operador ?? '',
        plan: props.unidad.especificacion?.plan ?? '',
    });
    formEquipo.reset();
    formEquipo.clearErrors();
    dialogoEquipo.value = true;
}

function guardarEquipo(): void {
    formEquipo.patch(
        `/activos/unidades/${props.unidad.public_token}/especificacion`,
        {
            preserveScroll: true,
            onSuccess: () => (dialogoEquipo.value = false),
        },
    );
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
            class="flex flex-col gap-4 rounded-xl border p-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="flex min-w-0 gap-4">
                <div class="shrink-0 text-center">
                    <img
                        :src="`/activos/unidades/${unidad.public_token}/qr`"
                        :alt="`Código QR de la unidad ${unidad.codigo}`"
                        class="size-24 rounded-md border bg-white p-1"
                        width="96"
                        height="96"
                    />
                    <p class="text-muted-foreground mt-1 text-[11px]">
                        QR generado
                    </p>
                </div>
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
                        <Badge
                            variant="outline"
                            :class="
                                claseEstadoVisibleUnidad(unidad.estado_visible)
                            "
                        >
                            {{ unidad.estado_visible_etiqueta }}
                        </Badge>
                        <Badge
                            v-if="unidad.condicion !== 'funcionando'"
                            variant="outline"
                            class="text-muted-foreground"
                        >
                            {{ unidad.condicion_etiqueta }}
                        </Badge>
                    </div>
                    <p class="text-muted-foreground mt-1 text-xs">
                        {{ unidad.estado_visible_descripcion }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button variant="outline" size="sm" as-child>
                    <a
                        :href="`/activos/unidades/etiquetas?ids=${unidad.id}`"
                        target="_blank"
                    >
                        <QrCode class="size-3.5" /> Ver etiqueta (PDF)
                    </a>
                </Button>
                <Button variant="ghost" size="sm" as-child>
                    <a
                        :href="`/activos/unidades/${unidad.public_token}/qr`"
                        target="_blank"
                    >
                        <QrCode class="size-3.5" /> Ver QR
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
                    v-if="permisos.administrar && noEntregablePorCondicion"
                    variant="outline"
                    size="sm"
                    @click="dialogoRestaurar = true"
                >
                    <RotateCcw class="size-3.5" /> Restaurar condición
                </Button>
                <Button
                    v-if="permisos.administrar && puedeMarcarDanada"
                    variant="outline"
                    size="sm"
                    @click="dialogoDanar = true"
                >
                    <Wrench class="size-3.5" /> Marcar dañada
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

        <div
            v-if="noEntregablePorCondicion"
            class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-400"
        >
            <p class="flex items-center gap-1.5 font-medium">
                <AlertTriangle class="size-4" />
                Unidad {{ unidad.condicion_etiqueta }}: no se puede seleccionar
                en una nueva entrega
            </p>
            <p class="mt-1">
                Sigue siendo la misma unidad física (mismo código y QR) y
                permanece en el almacén, pero no es entregable mientras su
                condición no vuelva a ser «Funcionando». Usa «Restaurar
                condición» cuando ya esté lista para operar de nuevo.
            </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl border p-4">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <ImagePlus class="text-muted-foreground size-4" />
                    Foto
                </h2>
                <button
                    v-if="unidad.imagen_url"
                    type="button"
                    class="focus-visible:ring-ring mb-3 block w-full rounded-md focus-visible:ring-2 focus-visible:outline-none"
                    aria-label="Ampliar foto de la unidad"
                    @click="dialogoImagenAmpliada = true"
                >
                    <img
                        :src="unidad.imagen_url"
                        :alt="`Foto de la unidad ${unidad.codigo}`"
                        class="max-h-56 w-full rounded-md border object-contain transition-opacity hover:opacity-90"
                    />
                </button>
                <p v-else class="text-muted-foreground mb-3 text-sm">
                    Esta unidad no tiene foto.
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="unidad.imagen_url"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="dialogoImagenAmpliada = true"
                    >
                        <Maximize2 class="size-3.5" /> Ampliar
                    </Button>
                    <template v-if="permisos.administrar">
                        <CapturaEvidencia
                            v-model="formImagen.imagen"
                            v-model:origen="origenImagen"
                            :etiqueta="
                                unidad.imagen_url
                                    ? 'Reemplazar foto'
                                    : 'Agregar foto'
                            "
                            :disabled="formImagen.processing"
                        />
                        <Button
                            v-if="unidad.imagen_url"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="text-destructive"
                            :disabled="formImagen.processing"
                            @click="quitarImagenUnidad"
                        >
                            <Trash2 class="size-3.5" /> Quitar imagen
                        </Button>
                    </template>
                </div>
                <InputError :message="formImagen.errors.imagen" />
            </section>

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
                    <div v-if="unidad.ubicacion_operativa.tipo === 'servicio'">
                        <dt class="text-muted-foreground text-xs">
                            Ubicación operativa
                        </dt>
                        <dd>
                            {{ unidad.ubicacion_operativa.contrato }} —
                            {{ unidad.ubicacion_operativa.servicio }}
                        </dd>
                    </div>
                    <div
                        v-else-if="
                            unidad.ubicacion_operativa.tipo === 'sin_servicio'
                        "
                    >
                        <dt class="text-muted-foreground text-xs">
                            Ubicación operativa
                        </dt>
                        <dd class="text-muted-foreground">
                            Sin servicio asignado
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

            <section v-if="unidad.perfil_tecnico" class="rounded-xl border p-4">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 class="flex items-center gap-2 text-sm font-semibold">
                        <ScrollText class="text-muted-foreground size-4" />
                        Información del equipo ·
                        {{ unidad.perfil_tecnico_etiqueta }}
                    </h2>
                    <Button
                        v-if="permisos.administrar"
                        variant="outline"
                        size="sm"
                        @click="abrirEditarEquipo"
                    >
                        Editar
                    </Button>
                </div>
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div v-for="d in unidad.datos_equipo" :key="d.campo">
                        <dt class="text-muted-foreground text-xs">
                            {{ d.etiqueta }}
                        </dt>
                        <dd :class="{ 'text-muted-foreground': !d.valor }">
                            {{ d.valor ?? 'Sin especificar' }}
                        </dd>
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
                        class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1 border-t pt-2 first:border-t-0 first:pt-0"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">{{ m.tipo }}</p>
                            <p class="text-muted-foreground text-xs">
                                {{ fechaHora(m.ocurrido_en) }}
                                <span v-if="m.motivo"> · {{ m.motivo }}</span>
                            </p>
                        </div>
                        <BotonVer
                            v-if="m.referencia"
                            :href="m.referencia.url"
                            class="shrink-0"
                        />
                    </li>
                </ul>
            </section>
        </div>

        <Dialog v-if="unidad.imagen_url" v-model:open="dialogoImagenAmpliada">
            <DialogContent class="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle
                        >Foto de la unidad {{ unidad.codigo }}</DialogTitle
                    >
                </DialogHeader>
                <img
                    :src="unidad.imagen_url"
                    :alt="`Foto de la unidad ${unidad.codigo}`"
                    class="max-h-[70vh] w-full rounded-md object-contain"
                />
            </DialogContent>
        </Dialog>

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
                        <SelectSimple
                            id="incidencia-tipo"
                            v-model="formIncidencia.tipo"
                            :opciones="
                                condicionesIncidencia.map((c) => ({
                                    valor: c.valor,
                                    etiqueta: c.etiqueta,
                                }))
                            "
                            :invalido="!!formIncidencia.errors.tipo"
                        />
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
                            class="border-input bg-background rounded-md border px-3 py-2 text-base md:text-sm"
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
                        <SelectSimple
                            id="recuperar-condicion"
                            v-model="formRecuperar.condicion_resultante"
                            :opciones="
                                condicionesRecuperacion.map((c) => ({
                                    valor: c.valor,
                                    etiqueta: c.etiqueta,
                                }))
                            "
                            :invalido="
                                !!formRecuperar.errors.condicion_resultante
                            "
                        />
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

        <Dialog v-model:open="dialogoRestaurar">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Restaurar condición</DialogTitle>
                    <DialogDescription>
                        La unidad sigue en el mismo almacén; sólo cambia su
                        condición. Conserva código, QR e historial.
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-3" @submit.prevent="restaurarCondicion">
                    <div class="grid gap-1.5">
                        <Label for="restaurar-condicion">Nueva condición</Label>
                        <SelectSimple
                            id="restaurar-condicion"
                            v-model="formRestaurar.condicion_resultante"
                            :opciones="
                                condicionesRestauracion.map((c) => ({
                                    valor: c.valor,
                                    etiqueta: c.etiqueta,
                                }))
                            "
                            :invalido="
                                !!formRestaurar.errors.condicion_resultante
                            "
                        />
                        <InputError
                            :message="formRestaurar.errors.condicion_resultante"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="restaurar-notas"
                            >Notas
                            <span class="text-muted-foreground"
                                >(opcional)</span
                            ></Label
                        >
                        <Input
                            id="restaurar-notas"
                            v-model="formRestaurar.notas"
                            placeholder="Ej. reparado por proveedor X, folio de servicio Y"
                        />
                        <InputError :message="formRestaurar.errors.notas" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="dialogoRestaurar = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            :disabled="formRestaurar.processing"
                        >
                            Restaurar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="dialogoDanar">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Marcar unidad como dañada</DialogTitle>
                    <DialogDescription>
                        La unidad sigue en el mismo almacén (mismo código y QR),
                        pero deja de ser entregable hasta que su condición
                        vuelva a «Funcionando» mediante «Restaurar condición».
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-3" @submit.prevent="marcarDanada">
                    <div class="grid gap-1.5">
                        <Label for="danar-condicion">Queda como</Label>
                        <SelectSimple
                            id="danar-condicion"
                            v-model="formDanar.condicion_resultante"
                            :opciones="
                                condicionesDano.map((c) => ({
                                    valor: c.valor,
                                    etiqueta: c.etiqueta,
                                }))
                            "
                            :invalido="!!formDanar.errors.condicion_resultante"
                        />
                        <InputError
                            :message="formDanar.errors.condicion_resultante"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="danar-motivo">Motivo</Label>
                        <Input
                            id="danar-motivo"
                            v-model="formDanar.motivo"
                            placeholder="p. ej. Pantalla rota"
                        />
                        <InputError :message="formDanar.errors.motivo" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="dialogoDanar = false"
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" :disabled="formDanar.processing">
                            Marcar dañada
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="dialogoEquipo">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Editar datos del equipo</DialogTitle>
                    <DialogDescription>
                        No cambia el código ni el QR de la unidad.
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-3" @submit.prevent="guardarEquipo">
                    <div
                        v-for="d in unidad.datos_equipo"
                        :key="d.campo"
                        class="grid gap-1"
                    >
                        <Label :for="`eq-${d.campo}`" class="text-xs">
                            {{ d.etiqueta }}
                        </Label>
                        <Input
                            :id="`eq-${d.campo}`"
                            v-model="formEquipo[d.campo as CampoEquipo]"
                            autocomplete="off"
                        />
                        <InputError
                            :message="
                                (formEquipo.errors as Record<string, string>)[
                                    d.campo
                                ]
                            "
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="dialogoEquipo = false"
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" :disabled="formEquipo.processing">
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
