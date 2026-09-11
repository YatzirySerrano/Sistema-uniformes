<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Camera,
    CameraOff,
    CheckCircle2,
    CircleAlert,
    Keyboard,
    SwitchCamera,
    Undo2,
    XCircle,
} from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
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
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import PadFirma from '@/components/sistema/PadFirma.vue';
import { Input } from '@/components/ui/input';
import { useEscanerQr } from '@/composables/useEscanerQr';
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import { fechaHora } from '@/lib/fecha';
import { claseEstadoVisibleUnidad } from '@/lib/estadoVisibleUnidad';

type Seccion = 'todos' | 'encontrados' | 'faltantes' | 'no_esperados';

type Contadores = {
    todos: number;
    esperados: number;
    encontrados_esperados: number;
    encontrados: number;
    pendientes: number;
    no_esperados: number;
    cantidad_renglones: number;
    cantidad_verificados: number;
    cantidad_pendientes: number;
    cantidad_coinciden: number;
    cantidad_con_diferencia: number;
    cantidad_esperada_total: number;
    cantidad_contada_total: number;
};

type FilaUnidad = {
    id: number;
    clasificacion: 'encontrado' | 'faltante' | 'no_esperado';
    clasificacion_etiqueta: string;
    esperada: boolean;
    escaneado_en: string | null;
    escaneado_por: string | null;
    codigo: string | null;
    activo: string | null;
    almacen: string | null;
    colaborador: string | null;
    estado_visible: string | null;
    estado_visible_etiqueta: string | null;
};

type FilaExistencia = {
    id: number;
    activo: string | null;
    talla: string | null;
    cantidad_esperada: number;
    cantidad_contada: number | null;
    diferencia: number | null;
    resultado: 'pendiente' | 'coincide' | 'faltante' | 'sobrante';
    verificada_por: string | null;
    verificada_en: string | null;
};

const props = defineProps<{
    ronda: {
        id: number;
        folio: string;
        nombre: string;
        estado: 'en_proceso' | 'finalizado';
        estado_etiqueta: string;
        empresa: string | null;
        almacen: string | null;
        responsable: string | null;
        observaciones: string | null;
        iniciado_en: string | null;
        finalizado_en: string | null;
        firma: {
            nombre_firmante: string;
            hash_firma: string;
            aceptado_en: string | null;
        } | null;
    };
    contadores: Contadores;
    seccion: Seccion;
    unidades: {
        data: FilaUnidad[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    existencias: FilaExistencia[];
    textoAceptacion: string;
    permisos: { administrar: boolean; finalizar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inventario físico', href: '/inventarios-fisicos' },
            { title: 'Ronda', href: '#' },
        ],
    },
});

const enProceso = computed(() => props.ronda.estado === 'en_proceso');
const puedeEscanear = computed(
    () => enProceso.value && props.permisos.administrar,
);

/* ---------- Filas visibles (copia local reactiva) ----------
 * Se itera esta copia y no `props.unidades.data` para poder reflejar de
 * inmediato el resultado de marcar / desmarcar una unidad (la respuesta del
 * servidor trae la fila fresca) sin recargar toda la página. */
const filas = ref<FilaUnidad[]>(props.unidades.data.map((f) => ({ ...f })));
watch(
    () => props.unidades,
    (nuevas) => {
        filas.value = nuevas.data.map((f) => ({ ...f }));
    },
);

function aplicarFilaActualizada(u: FilaUnidad | undefined): void {
    if (!u) return;
    const i = filas.value.findIndex((f) => f.id === u.id);
    if (i !== -1) {
        filas.value[i] = { ...filas.value[i], ...u };
    }
}

/* ---------- Contadores en vivo ---------- */
const contadores = reactive<Contadores>({ ...props.contadores });
watch(
    () => props.contadores,
    (nuevos) => Object.assign(contadores, nuevos),
);

/* ---------- Escaneo ---------- */
function xsrf(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

type Ultimo = {
    ok: boolean;
    titulo: string;
    detalle: string;
    tono: 'exito' | 'aviso' | 'error';
};
const ultimo = ref<Ultimo | null>(null);
const recientes = ref<Array<FilaUnidad & { resultado: string; _k: number }>>(
    [],
);
const enviando = ref(false);

const ETIQUETA_RESULTADO: Record<string, Ultimo> = {
    encontrada: {
        ok: true,
        titulo: 'Encontrada',
        detalle: 'Coincide con el universo esperado.',
        tono: 'exito',
    },
    no_esperada: {
        ok: true,
        titulo: 'Encontrada, no esperada',
        detalle: 'No formaba parte del universo al iniciar esta ronda.',
        tono: 'aviso',
    },
    ya_escaneada: {
        ok: false,
        titulo: 'Ya escaneada',
        detalle: 'Esta unidad ya había sido escaneada en esta ronda.',
        tono: 'aviso',
    },
};

let reloadPendiente: ReturnType<typeof setTimeout> | undefined;
let reloadDeadline: number | undefined;
/**
 * Red de seguridad para paginación / cambios desde otro dispositivo: NO es la
 * fuente de verdad de la fila ni del contador (esos vienen en la respuesta de
 * cada acción). Debounce de 1.2 s pero con tope de espera de 4 s: aunque el
 * usuario haga clics seguidos, la lista se re-sincroniza como muy tarde a los
 * 4 s en lugar de posponerse indefinidamente.
 */
function programarRefresco(): void {
    const ahora = Date.now();
    if (reloadDeadline === undefined) reloadDeadline = ahora + 4000;
    clearTimeout(reloadPendiente);
    const espera = Math.max(0, Math.min(1200, reloadDeadline - ahora));
    reloadPendiente = setTimeout(() => {
        reloadDeadline = undefined;
        router.reload({ only: ['unidades', 'contadores'] });
    }, espera);
}

const {
    estado: estadoEscaner,
    mensajeError: errorEscaner,
    puedeCambiarCamara: puedeCambiarCamaraEscaner,
    iniciar: iniciarEscaner,
    detener: detenerEscaner,
    cambiarCamara: cambiarCamaraEscaner,
    desbloquear: desbloquearEscaner,
} = useEscanerQr((texto) => {
    void enviarCodigo(texto);
});

async function enviarCodigo(texto: string): Promise<void> {
    const valor = texto.trim();
    if (!valor || enviando.value || !puedeEscanear.value) {
        desbloquearEscaner();
        return;
    }
    enviando.value = true;
    try {
        const res = await fetch(
            `/inventarios-fisicos/${props.ronda.id}/escanear`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrf(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ codigo: valor }),
            },
        );
        const data = await res.json().catch(() => ({}));

        if (!res.ok) {
            ultimo.value = {
                ok: false,
                titulo: 'No se registró',
                detalle:
                    data.message ??
                    'No se pudo registrar el escaneo. Revisa el código.',
                tono: 'error',
            };
            return;
        }

        Object.assign(contadores, data.contadores);
        aplicarFilaActualizada(data.unidad);
        const base =
            ETIQUETA_RESULTADO[data.resultado] ?? ETIQUETA_RESULTADO.encontrada;
        ultimo.value = {
            ...base,
            detalle: `${data.unidad.codigo ?? ''} · ${data.unidad.activo ?? ''} — ${data.contexto ?? base.detalle}`,
        };
        recientes.value = [
            { ...data.unidad, resultado: data.resultado, _k: Date.now() },
            ...recientes.value,
        ].slice(0, 15);
        programarRefresco();
    } catch {
        ultimo.value = {
            ok: false,
            titulo: 'Error de conexión',
            detalle: 'No se pudo contactar al servidor. Revisa tu conexión.',
            tono: 'error',
        };
    } finally {
        enviando.value = false;
        desbloquearEscaner();
    }
}

const videoRef = ref<HTMLVideoElement | null>(null);
async function alternarCamara(): Promise<void> {
    if (estadoEscaner.value === 'activo') {
        detenerEscaner();
        return;
    }
    if (videoRef.value) {
        await iniciarEscaner(videoRef.value);
    }
}

/* ---------- Entrada manual ---------- */
const codigoManual = ref('');
function enviarManual(): void {
    const v = codigoManual.value;
    codigoManual.value = '';
    void enviarCodigo(v);
}

/* ---------- Secciones ---------- */
const SECCIONES: { valor: Seccion; etiqueta: string; total: () => number }[] = [
    { valor: 'todos', etiqueta: 'Todos', total: () => contadores.todos },
    {
        valor: 'encontrados',
        etiqueta: 'Encontrados',
        total: () => contadores.encontrados,
    },
    {
        valor: 'faltantes',
        etiqueta: 'Faltantes',
        total: () => contadores.pendientes,
    },
    {
        valor: 'no_esperados',
        etiqueta: 'No esperados',
        total: () => contadores.no_esperados,
    },
];

function cambiarSeccion(s: Seccion): void {
    if (s === props.seccion) return;
    router.get(
        `/inventarios-fisicos/${props.ronda.id}`,
        { seccion: s },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['unidades', 'seccion', 'contadores'],
        },
    );
}

// Tabla ↔ Tarjetas del detalle: misma query/paginación/filtros, sólo cambia
// la presentación.
const vista = useVistaPreferida('inventario-fisico-detalle', 'tabla');

/* ---------- Artículos por cantidad (comprobación manual) ---------- */
const existencias = ref<FilaExistencia[]>(
    props.existencias.map((e) => ({ ...e })),
);
watch(
    () => props.existencias,
    (nuevas) => {
        existencias.value = nuevas.map((e) => ({ ...e }));
    },
);
// Borrador editable del conteo por renglón (input controlado).
const borrador = reactive<Record<number, number | ''>>({});
const guardandoExistencia = ref<number | null>(null);

const ETIQUETA_EXISTENCIA: Record<
    FilaExistencia['resultado'],
    { texto: string; clase: string }
> = {
    pendiente: {
        texto: 'Pendiente',
        clase: 'text-muted-foreground border-muted-foreground/30',
    },
    coincide: {
        texto: 'Coincide',
        clase: 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400',
    },
    faltante: {
        texto: 'Faltante',
        clase: 'border-red-500/40 text-red-700 dark:text-red-400',
    },
    sobrante: {
        texto: 'Sobrante',
        clase: 'border-amber-500/40 text-amber-700 dark:text-amber-400',
    },
};

async function verificarExistencia(
    fila: FilaExistencia,
    cantidad: number,
): Promise<void> {
    if (cantidad < 0 || guardandoExistencia.value !== null) return;
    guardandoExistencia.value = fila.id;
    try {
        const res = await fetch(
            `/inventarios-fisicos/${props.ronda.id}/existencias/${fila.id}`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrf(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ cantidad_contada: cantidad }),
            },
        );
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            ultimo.value = {
                ok: false,
                titulo: 'No se registró',
                detalle: data.message ?? 'No se pudo guardar la cantidad.',
                tono: 'error',
            };
            return;
        }
        const i = existencias.value.findIndex((e) => e.id === fila.id);
        if (i !== -1) existencias.value[i] = data.existencia;
        delete borrador[fila.id];
        Object.assign(contadores, data.contadores);
    } catch {
        ultimo.value = {
            ok: false,
            titulo: 'Error de conexión',
            detalle: 'No se pudo contactar al servidor.',
            tono: 'error',
        };
    } finally {
        guardandoExistencia.value = null;
    }
}

/* ---------- Marcar / desmarcar unidad presente (sin QR) ----------
 * Candado POR FILA (no global): dos filas distintas se pueden marcar en
 * paralelo, pero una fila con una petición en vuelo queda deshabilitada hasta
 * que responde. El backend es idempotente y la respuesta trae la fila + los
 * contadores reales; nunca se incrementa un contador a ciegas. */
const filasEnCurso = reactive(new Set<number>());

async function mutarPresente(
    fila: FilaUnidad,
    metodo: 'POST' | 'DELETE',
): Promise<void> {
    if (filasEnCurso.has(fila.id) || !puedeEscanear.value) return;
    filasEnCurso.add(fila.id);
    try {
        const res = await fetch(
            `/inventarios-fisicos/${props.ronda.id}/unidades/${fila.id}/presente`,
            {
                method: metodo,
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
                credentials: 'same-origin',
            },
        );
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            ultimo.value = {
                ok: false,
                titulo: 'No se registró',
                detalle:
                    data.message ??
                    (metodo === 'POST'
                        ? 'No se pudo marcar la unidad.'
                        : 'No se pudo revertir la marca.'),
                tono: 'error',
            };
            return;
        }
        aplicarFilaActualizada(data.unidad);
        Object.assign(contadores, data.contadores);
        programarRefresco();
    } catch {
        ultimo.value = {
            ok: false,
            titulo: 'Error de conexión',
            detalle: 'No se pudo contactar al servidor.',
            tono: 'error',
        };
    } finally {
        filasEnCurso.delete(fila.id);
    }
}

const marcarPresente = (fila: FilaUnidad) => mutarPresente(fila, 'POST');
const desmarcarPresente = (fila: FilaUnidad) => mutarPresente(fila, 'DELETE');

/* ---------- Finalizar ---------- */
const dialogoFinalizar = ref(false);
const finalizando = ref(false);
const padFinalizar = ref<InstanceType<typeof PadFirma> | null>(null);
const firmaVacia = ref(true);
const aceptaFinalizar = ref(false);

const puedeFinalizarDialog = computed(
    () =>
        !firmaVacia.value &&
        aceptaFinalizar.value &&
        contadores.cantidad_pendientes === 0 &&
        !finalizando.value,
);

watch(dialogoFinalizar, (abierto) => {
    if (abierto) {
        void nextTick(() => padFinalizar.value?.recalibrar());
    }
});

function finalizar(): void {
    const firma = padFinalizar.value?.obtenerDataUrl() ?? '';
    if (!firma || !aceptaFinalizar.value) return;
    finalizando.value = true;
    router.post(
        `/inventarios-fisicos/${props.ronda.id}/finalizar`,
        { firma, aceptacion: aceptaFinalizar.value },
        {
            preserveScroll: true,
            onFinish: () => {
                finalizando.value = false;
            },
            onSuccess: () => {
                dialogoFinalizar.value = false;
            },
        },
    );
}

function fecha(valor: string | null): string {
    return fechaHora(valor);
}

function claseClasificacion(c: FilaUnidad['clasificacion']): string {
    return c === 'encontrado'
        ? 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400'
        : c === 'no_esperado'
          ? 'border-amber-500/40 text-amber-700 dark:text-amber-400'
          : 'border-red-500/40 text-red-700 dark:text-red-400';
}

onBeforeUnmount(() => {
    clearTimeout(reloadPendiente);
    detenerEscaner();
});
</script>

<template>
    <Head :title="`Inventario físico · ${ronda.folio}`" />

    <div class="flex w-full flex-col gap-4 p-4">
        <EncabezadoPagina
            :titulo="ronda.nombre"
            :descripcion="`Folio ${ronda.folio}`"
        >
            <template #acciones>
                <BotonesExportar
                    :endpoint="`/inventarios-fisicos/${ronda.id}/exportar`"
                    :filtros="{ seccion }"
                />
            </template>
        </EncabezadoPagina>

        <div
            class="text-muted-foreground flex flex-wrap gap-x-6 gap-y-1 text-sm"
        >
            <span
                >Empresa:
                <strong class="text-foreground">{{
                    ronda.empresa ?? '—'
                }}</strong></span
            >
            <span v-if="ronda.almacen"
                >Almacén:
                <strong class="text-foreground">{{
                    ronda.almacen
                }}</strong></span
            >
            <span
                >Responsable:
                <strong class="text-foreground">{{
                    ronda.responsable ?? '—'
                }}</strong></span
            >
            <span>Inicio: {{ fecha(ronda.iniciado_en) }}</span>
            <span v-if="ronda.finalizado_en"
                >Cierre: {{ fecha(ronda.finalizado_en) }}</span
            >
            <Badge
                variant="outline"
                :class="
                    enProceso
                        ? 'border-amber-500/40 text-amber-700 dark:text-amber-400'
                        : 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400'
                "
            >
                {{ ronda.estado_etiqueta }}
            </Badge>
        </div>
        <p v-if="ronda.observaciones" class="text-muted-foreground text-sm">
            {{ ronda.observaciones }}
        </p>

        <div
            v-if="ronda.firma"
            class="rounded-lg border border-emerald-500/40 bg-emerald-50/50 p-3 text-sm dark:bg-emerald-950/20"
        >
            Ronda cerrada y firmada por
            <strong>{{ ronda.firma.nombre_firmante }}</strong>
            el {{ fecha(ronda.firma.aceptado_en) }} ·
            <span class="text-muted-foreground font-mono text-xs">
                Huella SHA-256 {{ ronda.firma.hash_firma }}
            </span>
        </div>

        <!-- Resumen: unidades identificadas / QR -->
        <section class="space-y-2">
            <h2 class="text-sm font-semibold">Unidades identificadas / QR</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl border p-3">
                    <p class="text-muted-foreground text-xs">Esperadas</p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ contadores.esperados }}
                    </p>
                </div>
                <div class="rounded-xl border p-3">
                    <p class="text-muted-foreground text-xs">Encontradas</p>
                    <p
                        class="text-2xl font-semibold text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        {{ contadores.encontrados }}
                    </p>
                </div>
                <div class="rounded-xl border p-3">
                    <p class="text-muted-foreground text-xs">Faltantes</p>
                    <p
                        class="text-2xl font-semibold text-red-600 tabular-nums dark:text-red-400"
                    >
                        {{ contadores.pendientes }}
                    </p>
                </div>
                <div class="rounded-xl border p-3">
                    <p class="text-muted-foreground text-xs">No esperadas</p>
                    <p
                        class="text-2xl font-semibold text-amber-600 tabular-nums dark:text-amber-400"
                    >
                        {{ contadores.no_esperados }}
                    </p>
                </div>
            </div>
        </section>

        <!-- Resumen: artículos por cantidad -->
        <section v-if="contadores.cantidad_renglones > 0" class="space-y-2">
            <h2 class="text-sm font-semibold">Artículos por cantidad</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div class="rounded-xl border p-3">
                    <p class="text-muted-foreground text-xs">Renglones</p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ contadores.cantidad_renglones }}
                    </p>
                </div>
                <div class="rounded-xl border p-3">
                    <p class="text-muted-foreground text-xs">Verificados</p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ contadores.cantidad_verificados }}
                    </p>
                </div>
                <div class="rounded-xl border p-3">
                    <p class="text-muted-foreground text-xs">Pendientes</p>
                    <p
                        class="text-2xl font-semibold text-red-600 tabular-nums dark:text-red-400"
                    >
                        {{ contadores.cantidad_pendientes }}
                    </p>
                </div>
                <div class="rounded-xl border p-3">
                    <p class="text-muted-foreground text-xs">Coinciden</p>
                    <p
                        class="text-2xl font-semibold text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        {{ contadores.cantidad_coinciden }}
                    </p>
                </div>
                <div class="rounded-xl border p-3">
                    <p class="text-muted-foreground text-xs">Con diferencia</p>
                    <p
                        class="text-2xl font-semibold text-amber-600 tabular-nums dark:text-amber-400"
                    >
                        {{ contadores.cantidad_con_diferencia }}
                    </p>
                </div>
            </div>
            <p class="text-muted-foreground text-xs">
                Esperado total: {{ contadores.cantidad_esperada_total }} ·
                Contado total: {{ contadores.cantidad_contada_total }}
            </p>
        </section>

        <!-- Escaneo (sólo mientras la ronda está en proceso) -->
        <div
            v-if="puedeEscanear"
            class="grid gap-4 rounded-xl border p-4 lg:grid-cols-[minmax(0,1fr)_320px]"
        >
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <Button type="button" @click="alternarCamara">
                        <component
                            :is="
                                estadoEscaner === 'activo' ? CameraOff : Camera
                            "
                            class="size-4"
                        />
                        {{
                            estadoEscaner === 'activo'
                                ? 'Detener cámara'
                                : 'Iniciar cámara'
                        }}
                    </Button>
                    <Button
                        v-if="
                            estadoEscaner === 'activo' &&
                            puedeCambiarCamaraEscaner
                        "
                        type="button"
                        variant="outline"
                        aria-label="Cambiar de cámara"
                        title="Cambiar de cámara"
                        @click="cambiarCamaraEscaner()"
                    >
                        <SwitchCamera class="size-4" /> Cambiar cámara
                    </Button>
                    <span
                        v-if="estadoEscaner === 'iniciando'"
                        class="text-muted-foreground text-sm"
                    >
                        Solicitando acceso a la cámara…
                    </span>
                </div>

                <div
                    class="relative aspect-video w-full overflow-hidden rounded-lg border bg-black/90"
                >
                    <video
                        ref="videoRef"
                        class="h-full w-full object-cover"
                        playsinline
                        muted
                    ></video>
                    <div
                        v-if="estadoEscaner === 'activo'"
                        class="pointer-events-none absolute inset-0 flex items-center justify-center"
                    >
                        <div
                            class="size-40 rounded-lg border-2 border-white/70 sm:size-56"
                        ></div>
                    </div>
                    <div
                        v-if="estadoEscaner !== 'activo'"
                        class="text-muted-foreground absolute inset-0 flex items-center justify-center p-4 text-center text-sm"
                    >
                        {{
                            errorEscaner ??
                            'La vista de la cámara aparecerá aquí. También puedes escribir el código manualmente.'
                        }}
                    </div>
                </div>

                <p
                    v-if="errorEscaner && estadoEscaner === 'error'"
                    class="flex items-start gap-2 text-sm text-red-600 dark:text-red-400"
                >
                    <CircleAlert class="mt-0.5 size-4 shrink-0" />
                    {{ errorEscaner }}
                </p>

                <div class="flex flex-col gap-1.5">
                    <label
                        for="codigo-manual"
                        class="text-muted-foreground flex items-center gap-1.5 text-sm"
                    >
                        <Keyboard class="size-4" /> Ingresar código manualmente
                    </label>
                    <form class="flex gap-2" @submit.prevent="enviarManual">
                        <Input
                            id="codigo-manual"
                            v-model="codigoManual"
                            placeholder="Código de la unidad o contenido del QR"
                            autocomplete="off"
                        />
                        <Button
                            type="submit"
                            :disabled="enviando || !codigoManual.trim()"
                        >
                            Registrar
                        </Button>
                    </form>
                </div>
            </div>

            <div class="flex flex-col gap-3">
                <div
                    v-if="ultimo"
                    class="rounded-lg border p-3"
                    :class="{
                        'border-emerald-500/40 bg-emerald-50/60 dark:bg-emerald-950/30':
                            ultimo.tono === 'exito',
                        'border-amber-500/40 bg-amber-50/60 dark:bg-amber-950/30':
                            ultimo.tono === 'aviso',
                        'border-red-500/40 bg-red-50/60 dark:bg-red-950/30':
                            ultimo.tono === 'error',
                    }"
                    aria-live="polite"
                >
                    <p class="flex items-center gap-1.5 font-medium">
                        <component
                            :is="
                                ultimo.tono === 'error' ? XCircle : CheckCircle2
                            "
                            class="size-4"
                        />
                        {{ ultimo.titulo }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{ ultimo.detalle }}
                    </p>
                </div>

                <div class="rounded-lg border">
                    <p
                        class="text-muted-foreground border-b px-3 py-2 text-xs font-medium"
                    >
                        Escaneos recientes
                    </p>
                    <ul class="max-h-64 divide-y overflow-y-auto text-sm">
                        <li
                            v-for="r in recientes"
                            :key="r._k"
                            class="flex items-center justify-between gap-2 px-3 py-1.5"
                        >
                            <span class="min-w-0">
                                <span class="font-mono text-xs">{{
                                    r.codigo
                                }}</span>
                                <span
                                    class="text-muted-foreground block truncate text-xs"
                                >
                                    {{ r.activo }}
                                </span>
                            </span>
                            <Badge
                                variant="outline"
                                class="shrink-0 text-[10px]"
                                :class="
                                    r.resultado === 'no_esperada'
                                        ? 'border-amber-500/40 text-amber-700 dark:text-amber-400'
                                        : r.resultado === 'ya_escaneada'
                                          ? 'text-muted-foreground'
                                          : 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400'
                                "
                            >
                                {{
                                    r.resultado === 'no_esperada'
                                        ? 'No esperada'
                                        : r.resultado === 'ya_escaneada'
                                          ? 'Repetida'
                                          : 'Encontrada'
                                }}
                            </Badge>
                        </li>
                        <li
                            v-if="!recientes.length"
                            class="text-muted-foreground px-3 py-3 text-center text-xs"
                        >
                            Aún no hay escaneos en esta sesión.
                        </li>
                    </ul>
                </div>

                <Button
                    variant="outline"
                    class="border-red-600/30 text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                    @click="dialogoFinalizar = true"
                >
                    Finalizar inventario
                </Button>
            </div>
        </div>

        <p
            v-else-if="enProceso"
            class="text-muted-foreground rounded-lg border border-dashed p-3 text-sm"
        >
            No tienes permiso para escanear en esta ronda. Puedes consultar los
            resultados a continuación.
        </p>

        <!-- Secciones -->
        <div class="flex flex-wrap items-center gap-2">
            <Button
                v-for="s in SECCIONES"
                :key="s.valor"
                size="sm"
                :variant="seccion === s.valor ? 'default' : 'outline'"
                @click="cambiarSeccion(s.valor)"
            >
                {{ s.etiqueta }} ({{ s.total() }})
            </Button>
            <SelectorVista v-model="vista" class="ml-auto" />
        </div>

        <p v-if="puedeEscanear" class="text-muted-foreground -mt-1 text-xs">
            «Presente» marca una unidad faltante como encontrada físicamente
            (equivale a escanear su QR). «Deshacer» revierte la marca mientras
            la ronda siga abierta.
        </p>

        <EstadoVacio
            v-if="!filas.length"
            titulo="Sin unidades en esta sección"
            :descripcion="
                seccion === 'faltantes'
                    ? 'Todas las unidades esperadas fueron escaneadas.'
                    : seccion === 'no_esperados'
                      ? 'No se ha escaneado ninguna unidad fuera del universo esperado.'
                      : seccion === 'todos'
                        ? 'Esta ronda no tiene unidades registradas.'
                        : 'Todavía no se ha escaneado ninguna unidad.'
            "
        />

        <div
            v-else-if="vista === 'tabla'"
            class="overflow-x-auto rounded-xl border"
        >
            <table class="w-full min-w-[820px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th
                            v-if="seccion === 'todos'"
                            class="px-3 py-2 font-medium"
                        >
                            Resultado
                        </th>
                        <th class="px-3 py-2 font-medium">Código / Activo</th>
                        <th class="px-3 py-2 font-medium">Almacén</th>
                        <th class="px-3 py-2 font-medium">Asignada a</th>
                        <th class="px-3 py-2 font-medium">Estado actual</th>
                        <th class="px-3 py-2 font-medium">Escaneada</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="f in filas"
                        :key="f.id"
                        class="hover:bg-muted/40 border-t transition-colors"
                    >
                        <td v-if="seccion === 'todos'" class="px-3 py-2">
                            <Badge
                                variant="outline"
                                class="text-xs"
                                :class="claseClasificacion(f.clasificacion)"
                            >
                                {{ f.clasificacion_etiqueta }}
                            </Badge>
                        </td>
                        <td class="px-3 py-2">
                            <p class="font-mono font-medium">
                                {{ f.codigo ?? '—' }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                {{ f.activo ?? '—' }}
                            </p>
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ f.almacen ?? '—' }}
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ f.colaborador ?? '—' }}
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                v-if="f.estado_visible"
                                variant="outline"
                                class="text-xs"
                                :class="
                                    claseEstadoVisibleUnidad(f.estado_visible)
                                "
                            >
                                {{ f.estado_visible_etiqueta }}
                            </Badge>
                        </td>
                        <td class="text-muted-foreground px-3 py-2 text-xs">
                            <template v-if="f.escaneado_en">
                                <span
                                    class="flex items-center gap-1 text-emerald-700 dark:text-emerald-400"
                                >
                                    <CheckCircle2 class="size-3.5" /> Presente
                                </span>
                                <span class="block">{{
                                    fecha(f.escaneado_en)
                                }}</span>
                                <span v-if="f.escaneado_por" class="block">
                                    por {{ f.escaneado_por }}
                                </span>
                                <Button
                                    v-if="puedeEscanear && f.esperada"
                                    size="sm"
                                    variant="ghost"
                                    class="mt-1 h-7 px-2 text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                    :disabled="filasEnCurso.has(f.id)"
                                    @click="desmarcarPresente(f)"
                                >
                                    <Undo2 class="size-3.5" /> Deshacer
                                </Button>
                            </template>
                            <div
                                v-else-if="
                                    puedeEscanear &&
                                    f.esperada &&
                                    f.clasificacion === 'faltante'
                                "
                            >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="filasEnCurso.has(f.id)"
                                    @click="marcarPresente(f)"
                                >
                                    <CheckCircle2 class="size-4" /> Presente
                                </Button>
                            </div>
                            <span v-else>No localizada</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-else
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <div
                v-for="f in filas"
                :key="f.id"
                class="flex flex-col gap-2 rounded-xl border p-4 text-sm"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-mono font-medium">
                            {{ f.codigo ?? '—' }}
                        </p>
                        <p class="text-muted-foreground truncate text-xs">
                            {{ f.activo ?? '—' }}
                        </p>
                    </div>
                    <Badge
                        variant="outline"
                        class="shrink-0 text-xs"
                        :class="claseClasificacion(f.clasificacion)"
                    >
                        {{ f.clasificacion_etiqueta }}
                    </Badge>
                </div>

                <div
                    class="text-muted-foreground grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5 text-xs"
                >
                    <span>Estado sistema:</span>
                    <span class="text-foreground">
                        {{ f.estado_visible_etiqueta ?? '—' }}
                    </span>
                    <span>Almacén:</span>
                    <span class="text-foreground">{{ f.almacen ?? '—' }}</span>
                    <span>Asignada a:</span>
                    <span class="text-foreground">
                        {{ f.colaborador ?? '—' }}
                    </span>
                </div>

                <div class="text-muted-foreground text-xs">
                    <template v-if="f.escaneado_en">
                        Escaneada: {{ fecha(f.escaneado_en) }}
                        <span v-if="f.escaneado_por" class="block">
                            Por: {{ f.escaneado_por }}
                        </span>
                    </template>
                    <span v-else>Escaneada: — · Por: —</span>
                </div>
                <div
                    v-if="
                        puedeEscanear &&
                        f.esperada &&
                        (f.clasificacion === 'faltante' || f.escaneado_en)
                    "
                >
                    <Button
                        v-if="f.clasificacion === 'faltante'"
                        size="sm"
                        variant="outline"
                        class="w-fit"
                        :disabled="filasEnCurso.has(f.id)"
                        @click="marcarPresente(f)"
                    >
                        <CheckCircle2 class="size-4" /> Presente
                    </Button>
                    <Button
                        v-else
                        size="sm"
                        variant="ghost"
                        class="w-fit text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                        :disabled="filasEnCurso.has(f.id)"
                        @click="desmarcarPresente(f)"
                    >
                        <Undo2 class="size-4" /> Deshacer presente
                    </Button>
                </div>
            </div>
        </div>

        <Paginacion :links="unidades.links" :total="unidades.total" />

        <!-- Artículos por cantidad (comprobación manual) -->
        <section
            v-if="existencias.length"
            class="space-y-3 rounded-xl border p-4"
        >
            <div>
                <h2 class="text-sm font-semibold">
                    Artículos por cantidad · comprobación manual
                </h2>
                <p class="text-muted-foreground mt-0.5 text-xs">
                    Prendas y consumibles sin QR individual. Marca «Coincide» si
                    el conteo cuadra con lo esperado, o captura la cantidad real
                    contada. Esto sólo compara: no ajusta el inventario.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="text-muted-foreground text-left">
                        <tr>
                            <th class="py-1.5">Activo</th>
                            <th class="py-1.5">Talla</th>
                            <th class="py-1.5 text-right">Esperado</th>
                            <th class="py-1.5 text-right">Contado</th>
                            <th class="py-1.5 text-right">Diferencia</th>
                            <th class="py-1.5">Resultado</th>
                            <th v-if="puedeEscanear" class="py-1.5">
                                Verificar
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="e in existencias"
                            :key="e.id"
                            class="border-t"
                        >
                            <td class="py-1.5">{{ e.activo ?? '—' }}</td>
                            <td class="py-1.5">{{ e.talla ?? '—' }}</td>
                            <td class="py-1.5 text-right tabular-nums">
                                {{ e.cantidad_esperada }}
                            </td>
                            <td class="py-1.5 text-right tabular-nums">
                                {{ e.cantidad_contada ?? '—' }}
                            </td>
                            <td
                                class="py-1.5 text-right tabular-nums"
                                :class="
                                    (e.diferencia ?? 0) < 0
                                        ? 'text-red-600 dark:text-red-400'
                                        : (e.diferencia ?? 0) > 0
                                          ? 'text-amber-600 dark:text-amber-400'
                                          : ''
                                "
                            >
                                {{
                                    e.diferencia === null
                                        ? '—'
                                        : e.diferencia > 0
                                          ? `+${e.diferencia}`
                                          : e.diferencia
                                }}
                            </td>
                            <td class="py-1.5">
                                <Badge
                                    variant="outline"
                                    class="text-xs"
                                    :class="
                                        ETIQUETA_EXISTENCIA[e.resultado].clase
                                    "
                                >
                                    {{ ETIQUETA_EXISTENCIA[e.resultado].texto }}
                                </Badge>
                            </td>
                            <td v-if="puedeEscanear" class="py-1.5">
                                <div class="flex items-center gap-1.5">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        :disabled="guardandoExistencia !== null"
                                        @click="
                                            verificarExistencia(
                                                e,
                                                e.cantidad_esperada,
                                            )
                                        "
                                    >
                                        <CheckCircle2 class="size-4" /> Coincide
                                    </Button>
                                    <Input
                                        v-model.number="borrador[e.id]"
                                        type="number"
                                        min="0"
                                        class="h-8 w-20"
                                        placeholder="Contado"
                                    />
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        :disabled="
                                            guardandoExistencia !== null ||
                                            borrador[e.id] === '' ||
                                            borrador[e.id] === undefined
                                        "
                                        @click="
                                            verificarExistencia(
                                                e,
                                                Number(borrador[e.id]),
                                            )
                                        "
                                    >
                                        Guardar
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Dialog v-model:open="dialogoFinalizar">
            <DialogContent class="max-h-[90dvh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Finalizar inventario físico</DialogTitle>
                    <DialogDescription>
                        Al finalizar, la ronda queda cerrada e inmutable: no
                        admitirá más escaneos, marcas «Presente» ni cambios de
                        cantidad. Firma como responsable de lo registrado.
                    </DialogDescription>
                </DialogHeader>

                <p
                    v-if="contadores.cantidad_pendientes > 0"
                    class="rounded-md border border-amber-500/40 bg-amber-500/10 p-2 text-sm text-amber-700 dark:text-amber-400"
                >
                    Aún hay {{ contadores.cantidad_pendientes }} renglón(es) de
                    artículos por cantidad sin verificar. Verifícalos antes de
                    cerrar la ronda. (Las unidades QR faltantes sí se pueden
                    dejar así — son un resultado válido.)
                </p>

                <div class="space-y-2">
                    <p class="text-sm font-medium">Firma del responsable</p>
                    <PadFirma
                        ref="padFinalizar"
                        @cambio="(v: boolean) => (firmaVacia = v)"
                    />
                </div>

                <label
                    class="bg-muted/40 flex items-start gap-2 rounded-lg border p-3 text-sm"
                >
                    <input
                        v-model="aceptaFinalizar"
                        type="checkbox"
                        class="mt-0.5 size-4 shrink-0"
                    />
                    <span>{{ textoAceptacion }}</span>
                </label>

                <DialogFooter>
                    <Button variant="ghost" @click="dialogoFinalizar = false">
                        Cancelar
                    </Button>
                    <Button
                        :disabled="!puedeFinalizarDialog"
                        @click="finalizar"
                    >
                        Finalizar y firmar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
