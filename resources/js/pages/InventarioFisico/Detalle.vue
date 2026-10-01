<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Camera,
    CameraOff,
    CheckCircle2,
    CircleAlert,
    ClipboardList,
    Keyboard,
    RotateCcw,
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
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import PadFirma from '@/components/sistema/PadFirma.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useEscanerQr } from '@/composables/useEscanerQr';
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import { fechaHora } from '@/lib/fecha';
import { claseEstadoVisibleUnidad } from '@/lib/estadoVisibleUnidad';
import {
    ETIQUETA_VARIANTE,
    etiquetaCantidadEsperada,
    textoVariante,
} from '@/lib/etiquetasCantidad';
import { varianteBadgeFinalidad } from '@/lib/finalidadCustodia';

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
    /** «No fue posible verificar»: resueltos sin cantidad (no son 0). */
    cantidad_no_verificables: number;
    cantidad_coinciden: number;
    cantidad_con_diferencia: number;
    cantidad_esperada_total: number;
    cantidad_contada_total: number;
    cantidad_custodia_renglones: number;
    cantidad_almacen_con_diferencia: number;
    cantidad_custodia_con_diferencia: number;
};

type FilaUnidad = {
    id: number;
    /**
     * Estado de VERIFICACIÓN: `pendiente` (ronda abierta, sin revisar),
     * `encontrado` (Presente), `faltante` (ronda cerrada sin localizarla),
     * `no_esperado` (escaneada fuera del universo).
     */
    clasificacion: 'encontrado' | 'pendiente' | 'faltante' | 'no_esperado';
    clasificacion_etiqueta: string;
    esperada: boolean;
    escaneado_en: string | null;
    escaneado_por: string | null;
    codigo: string | null;
    activo: string | null;
    almacen: string | null;
    colaborador: string | null;
    /** Ubicación OPERATIVA actual: guardada en almacén o con un colaborador. */
    ubicacion: 'almacen' | 'colaborador';
    colaborador_sucursal: string | null;
    colaborador_servicio: string | null;
    /** Estado OPERATIVO actual (En almacén / Asignado / Reparación…). */
    estado_visible: string | null;
    estado_visible_etiqueta: string | null;
};

type FilaExistencia = {
    id: number;
    /**
     * `almacen`: saldo de un almacén (su diferencia se puede aplicar).
     * `custodia`: lo que tenía un colaborador al iniciar la ronda (su
     * diferencia sólo se registra, nunca toca el inventario).
     */
    origen: 'almacen' | 'custodia';
    /** Almacén del renglón: ahí se cuenta y ahí se aplica la corrección. */
    almacen_id: number | null;
    almacen: string | null;
    custodio: {
        id: number;
        nombre_completo: string;
        numero_empleado: string | null;
    } | null;
    custodio_sucursal: string | null;
    custodio_servicio: string | null;
    finalidad: 'uso_personal' | 'redistribucion' | null;
    finalidad_etiqueta: string | null;
    activo: string | null;
    talla: string | null;
    cantidad_esperada: number;
    cantidad_contada: number | null;
    /** «No fue posible verificar»: sin cantidad contada ni diferencia. */
    no_verificable: boolean;
    motivo_no_verificable: string | null;
    diferencia: number | null;
    resultado:
        | 'pendiente'
        | 'coincide'
        | 'faltante'
        | 'sobrante'
        | 'no_verificable';
    verificada_por: string | null;
    verificada_en: string | null;
};

type EstadoCorrecciones = 'sin_diferencias' | 'pendientes' | 'aplicadas';

type Correcciones = {
    estado: EstadoCorrecciones;
    total_diferencias: number;
    /** Diferencias de custodia: incidencias a revisar, nunca se aplican. */
    diferencias_custodia: number;
    aplicadas_en: string | null;
    aplicadas_por: string | null;
    total_aplicadas: number | null;
};

type ConflictoExistencia = {
    activo: string;
    talla: string | null;
    esperada: number;
    actual: number;
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
        /** Ronda integral de la empresa (sin almacén único). */
        general: boolean;
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
    /** Filtro por estado operativo actual de la unidad (vacío = todos). */
    estadoUnidad: string | null;
    estadosUnidad: { valor: string; etiqueta: string }[];
    /** Filtro "trabajar por almacén" (cantidades y unidades hoy guardadas en él). */
    almacenFiltro: number | null;
    almacenes: { id: number; nombre: string }[];
    unidades: {
        data: FilaUnidad[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    existencias: FilaExistencia[];
    textoAceptacion: string;
    correcciones: Correcciones;
    conflictosInventarioFisico: ConflictoExistencia[] | null;
    permisos: {
        administrar: boolean;
        finalizar: boolean;
        aplicarCorrecciones: boolean;
    };
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
        etiqueta: 'Presentes',
        total: () => contadores.encontrados,
    },
    {
        // Mientras la ronda está abierta nada es "faltante": sólo está
        // pendiente de verificar. Al cerrarla, lo no verificado sí es "no
        // localizado".
        valor: 'faltantes',
        etiqueta:
            props.ronda.estado === 'en_proceso'
                ? 'Pendientes de verificar'
                : 'No localizados',
        total: () => contadores.pendientes,
    },
    {
        valor: 'no_esperados',
        etiqueta: 'No esperados',
        total: () => contadores.no_esperados,
    },
];

function recargarUnidades(
    seccion: Seccion,
    estadoUnidad: string,
    almacenId: number | null = props.almacenFiltro,
): void {
    router.get(
        `/inventarios-fisicos/${props.ronda.id}`,
        {
            seccion,
            ...(estadoUnidad ? { estado_unidad: estadoUnidad } : {}),
            ...(almacenId ? { almacen_id: almacenId } : {}),
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: [
                'unidades',
                'existencias',
                'seccion',
                'estadoUnidad',
                'almacenFiltro',
                'contadores',
            ],
        },
    );
}

function cambiarSeccion(s: Seccion): void {
    if (s === props.seccion) return;
    recargarUnidades(s, props.estadoUnidad ?? '');
}

/* ---------- Filtro por almacén (trabajar la ronda por zonas) ---------- */
const OPCIONES_ALMACEN = computed(() => [
    { valor: '', etiqueta: 'Todos los almacenes' },
    ...props.almacenes.map((a) => ({ valor: a.id, etiqueta: a.nombre })),
]);
function cambiarAlmacen(valor: string | number | null): void {
    const id = valor ? Number(valor) : null;
    if (id === props.almacenFiltro) return;
    recargarUnidades(props.seccion, props.estadoUnidad ?? '', id);
}

/* ---------- Filtro por estado OPERATIVO (independiente de la verificación) ---------- */
const OPCIONES_ESTADO_UNIDAD = computed(() => [
    { valor: '', etiqueta: 'Todos los estados' },
    ...props.estadosUnidad,
]);
function cambiarEstadoUnidad(valor: string | number | null): void {
    const nuevo = String(valor ?? '');
    if (nuevo === (props.estadoUnidad ?? '')) return;
    recargarUnidades(props.seccion, nuevo);
}

// Tabla ↔ Tarjetas del detalle: misma query/paginación/filtros, sólo cambia
// la presentación.
const vista = useVistaPreferida('inventario-fisico-detalle', 'cards');

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
// Almacén vs. custodia: ambos orígenes se cuentan igual, pero el encargado
// necesita separarlos (la custodia se comprueba con cada persona). Filtro
// local: los renglones ya están todos cargados.
type FiltroOrigen = 'todos' | 'almacen' | 'custodia';
const filtroOrigen = ref<FiltroOrigen>('todos');
const busquedaExistencia = ref('');
const OPCIONES_ORIGEN: { valor: FiltroOrigen; etiqueta: string }[] = [
    { valor: 'todos', etiqueta: 'Todos' },
    { valor: 'almacen', etiqueta: 'En almacén' },
    { valor: 'custodia', etiqueta: 'Bajo custodia' },
];
const hayCustodia = computed(() =>
    existencias.value.some((e) => e.origen === 'custodia'),
);
const existenciasVisibles = computed(() => {
    const q = busquedaExistencia.value.trim().toLocaleLowerCase('es');
    return existencias.value.filter(
        (e) =>
            (filtroOrigen.value === 'todos' ||
                e.origen === filtroOrigen.value) &&
            (q === '' ||
                [
                    e.activo,
                    e.almacen,
                    e.custodio?.nombre_completo,
                    e.custodio?.numero_empleado,
                    e.talla,
                ].some((v) => v?.toLocaleLowerCase('es').includes(q))),
    );
});
function totalOrigen(origen: FiltroOrigen): number {
    return origen === 'todos'
        ? existencias.value.length
        : existencias.value.filter((e) => e.origen === origen).length;
}
function nombreCustodio(c: NonNullable<FilaExistencia['custodio']>): string {
    return c.numero_empleado
        ? `${c.nombre_completo} (${c.numero_empleado})`
        : c.nombre_completo;
}
function ubicacionExistencia(e: FilaExistencia): string {
    return e.custodio ? nombreCustodio(e.custodio) : (e.almacen ?? '—');
}
function contextoCustodio(e: FilaExistencia): string | null {
    const partes = [
        e.custodio_sucursal ? `Sucursal: ${e.custodio_sucursal}` : null,
        e.custodio_servicio ? `Servicio: ${e.custodio_servicio}` : null,
    ].filter(Boolean);
    return partes.length ? partes.join(' · ') : null;
}

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
    no_verificable: {
        texto: 'No fue posible verificar',
        clase: 'border-slate-500/40 text-slate-700 dark:text-slate-300',
    },
};

/**
 * Única rutina de escritura de un renglón por cantidad (contar, «No fue
 * posible verificar», reabrir). Siempre manda la versión que se ve en
 * pantalla (`verificada_en`): si otro encargado ya cambió el renglón, el
 * backend responde 409 con el estado real en vez de pisarlo.
 */
async function enviarExistencia(
    fila: FilaExistencia,
    ruta: string,
    method: 'POST' | 'DELETE',
    datos: Record<string, unknown>,
    errorPorDefecto: string,
): Promise<boolean> {
    if (guardandoExistencia.value !== null) return false;
    guardandoExistencia.value = fila.id;
    try {
        const res = await fetch(
            `/inventarios-fisicos/${props.ronda.id}/existencias/${fila.id}${ruta}`,
            {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrf(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    ...datos,
                    verificada_en_vista: fila.verificada_en,
                }),
            },
        );
        const data = await res.json().catch(() => ({}));
        if (res.status === 409) {
            const i = existencias.value.findIndex((e) => e.id === fila.id);
            if (i !== -1 && data.existencia)
                existencias.value[i] = data.existencia;
            delete borrador[fila.id];
            if (data.contadores) Object.assign(contadores, data.contadores);
            ultimo.value = {
                ok: false,
                titulo: 'Ya lo registró otra persona',
                detalle: data.message,
                tono: 'aviso',
            };
            return false;
        }
        if (!res.ok) {
            ultimo.value = {
                ok: false,
                titulo: 'No se registró',
                detalle: data.message ?? errorPorDefecto,
                tono: 'error',
            };
            return false;
        }
        const i = existencias.value.findIndex((e) => e.id === fila.id);
        if (i !== -1) existencias.value[i] = data.existencia;
        delete borrador[fila.id];
        Object.assign(contadores, data.contadores);
        return true;
    } catch {
        ultimo.value = {
            ok: false,
            titulo: 'Error de conexión',
            detalle: 'No se pudo contactar al servidor.',
            tono: 'error',
        };
        return false;
    } finally {
        guardandoExistencia.value = null;
    }
}

async function verificarExistencia(
    fila: FilaExistencia,
    cantidad: number,
): Promise<void> {
    if (cantidad < 0) return;
    await enviarExistencia(
        fila,
        '',
        'POST',
        { cantidad_contada: cantidad },
        'No se pudo guardar la cantidad.',
    );
}

/* ---------- «No fue posible verificar» (motivo opcional) ----------
 * Resuelve el renglón para el cierre SIN cantidad: nunca es un 0, no tiene
 * diferencia y no entra a las correcciones. Se puede reabrir (o sustituir
 * por un conteo real) mientras la ronda siga abierta. */
const filaNoVerificable = ref<FilaExistencia | null>(null);
const motivoNoVerificable = ref('');

function abrirNoVerificable(fila: FilaExistencia): void {
    filaNoVerificable.value = fila;
    motivoNoVerificable.value = fila.motivo_no_verificable ?? '';
}

async function confirmarNoVerificable(): Promise<void> {
    const fila = filaNoVerificable.value;
    if (!fila) return;
    const motivo = motivoNoVerificable.value.trim();
    const ok = await enviarExistencia(
        fila,
        '/no-verificable',
        'POST',
        { motivo: motivo === '' ? null : motivo },
        'No se pudo registrar la resolución.',
    );
    if (ok) filaNoVerificable.value = null;
}

async function reabrirExistencia(fila: FilaExistencia): Promise<void> {
    await enviarExistencia(
        fila,
        '/no-verificable',
        'DELETE',
        {},
        'No se pudo reabrir el renglón.',
    );
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
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrf(),
                },
                credentials: 'same-origin',
                // Deshacer revalida contra la verificación que se ve aquí.
                body:
                    metodo === 'DELETE'
                        ? JSON.stringify({
                              escaneado_en_vista: fila.escaneado_en,
                          })
                        : undefined,
            },
        );
        const data = await res.json().catch(() => ({}));
        if (res.status === 409) {
            // Otro encargado ya la verificó (gana el primero): se muestra el
            // estado real, quién y cuándo — nada se sobrescribe.
            aplicarFilaActualizada(data.unidad);
            if (data.contadores) Object.assign(contadores, data.contadores);
            ultimo.value = {
                ok: false,
                titulo: 'Ya la verificó otra persona',
                detalle: data.message,
                tono: 'aviso',
            };
            return;
        }
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

/* ---------- Aplicar correcciones de inventario (todo o nada) ----------
 * Sólo aparece cuando la ronda ya está finalizada y firmada: firmar NUNCA
 * aplica el inventario por sí solo (ver `FinalizarRondaInventarioFisico`),
 * esto es un paso posterior y explícito, nunca obligatorio. */
const dialogoRevisarCorrecciones = ref(false);
const aplicandoCorrecciones = ref(false);

// Sólo los renglones que de verdad generarían un cambio (coincide/pendiente
// no aplican nada) — misma regla que ya usa el backend para elegir qué
// aplicar.
// Las de CUSTODIA nunca se aplican: esas piezas ya no estaban en un almacén.
const diferenciasParaAplicar = computed(() =>
    props.existencias.filter(
        (e) =>
            e.origen === 'almacen' &&
            e.diferencia !== null &&
            e.diferencia !== 0,
    ),
);

function abrirRevisionCorrecciones(): void {
    dialogoRevisarCorrecciones.value = true;
}

function aplicarCorrecciones(): void {
    aplicandoCorrecciones.value = true;
    router.post(
        `/inventarios-fisicos/${props.ronda.id}/aplicar-correcciones`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                aplicandoCorrecciones.value = false;
            },
            onSuccess: () => {
                dialogoRevisarCorrecciones.value = false;
            },
        },
    );
}

// Combinaciones que impidieron aplicar el lote en el último intento: llegan
// desde el backend (flash de sesión) tras un conflicto de concurrencia. Se
// muestran automáticamente apenas la página las recibe — el usuario no tiene
// que volver a pulsar nada para verlas.
const dialogoConflictos = ref(false);
const conflictosMostrados = ref<ConflictoExistencia[]>([]);
watch(
    () => props.conflictosInventarioFisico,
    (conflictos) => {
        if (conflictos && conflictos.length > 0) {
            conflictosMostrados.value = conflictos;
            dialogoConflictos.value = true;
        }
    },
    { immediate: true },
);

function claseClasificacion(c: FilaUnidad['clasificacion']): string {
    switch (c) {
        case 'encontrado':
            return 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400';
        case 'pendiente':
            // Neutral: todavía no se revisa, no es un problema.
            return 'border-muted-foreground/30 text-muted-foreground';
        case 'no_esperado':
            return 'border-amber-500/40 text-amber-700 dark:text-amber-400';
        default:
            return 'border-red-500/40 text-red-700 dark:text-red-400';
    }
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
                    :filtros="{ seccion, estado_unidad: estadoUnidad ?? '' }"
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
            <span v-else-if="ronda.general"
                >Alcance:
                <strong class="text-foreground">Toda la empresa</strong></span
            >
            <span
                >Iniciada por:
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
            <span class="text-muted-foreground font-mono text-xs break-all">
                Huella SHA-256 {{ ronda.firma.hash_firma }}
            </span>
        </div>

        <!-- Aplicación de correcciones (sólo con ronda finalizada Y firmada):
             estado independiente del estado de la ronda — nunca se mezclan.
             Firmar/finalizar sólo confirma el conteo; aplicar es un paso
             posterior, explícito y nunca obligatorio. -->
        <div
            v-if="ronda.firma && correcciones.estado === 'pendientes'"
            class="flex flex-col gap-3 rounded-lg border border-amber-500/40 bg-amber-50/50 p-4 text-sm sm:flex-row sm:items-center sm:justify-between dark:bg-amber-950/20"
        >
            <div class="flex items-start gap-2">
                <AlertTriangle
                    class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"
                />
                <div>
                    <p class="font-medium">
                        {{ correcciones.total_diferencias }}
                        {{
                            correcciones.total_diferencias === 1
                                ? 'diferencia pendiente de aplicar'
                                : 'diferencias pendientes de aplicar'
                        }}
                    </p>
                    <p class="text-muted-foreground mt-0.5">
                        El conteo físico encontró diferencias con las
                        existencias registradas. Revisa los cambios antes de
                        actualizar el inventario.
                    </p>
                </div>
            </div>
            <Button
                v-if="permisos.aplicarCorrecciones"
                size="sm"
                class="shrink-0"
                @click="abrirRevisionCorrecciones"
            >
                <ClipboardList class="size-4" />
                Revisar y aplicar correcciones
            </Button>
        </div>

        <div
            v-else-if="ronda.firma && correcciones.estado === 'aplicadas'"
            class="rounded-lg border border-emerald-500/40 bg-emerald-50/50 p-4 text-sm dark:bg-emerald-950/20"
        >
            <p class="flex items-center gap-2 font-medium">
                <CheckCircle2
                    class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                />
                Correcciones aplicadas
            </p>
            <p class="text-muted-foreground mt-0.5">
                {{ correcciones.total_aplicadas }}
                {{
                    correcciones.total_aplicadas === 1
                        ? 'existencia fue actualizada'
                        : 'existencias fueron actualizadas'
                }}
                el {{ fecha(correcciones.aplicadas_en) }}
                <template v-if="correcciones.aplicadas_por">
                    por <strong>{{ correcciones.aplicadas_por }}</strong>
                </template>
                .
            </p>
        </div>

        <p
            v-else-if="ronda.firma && correcciones.estado === 'sin_diferencias'"
            class="text-muted-foreground text-sm"
        >
            Sin diferencias por aplicar: el conteo físico coincidió con las
            existencias registradas en almacén.
        </p>

        <!-- Diferencias de custodia: se registran en la ronda como
             incidencias a revisar; nunca se aplican al inventario. -->
        <div
            v-if="correcciones.diferencias_custodia > 0"
            class="flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-50/50 p-4 text-sm dark:bg-amber-950/20"
        >
            <AlertTriangle
                class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"
            />
            <div>
                <p class="font-medium">
                    {{ correcciones.diferencias_custodia }}
                    {{
                        correcciones.diferencias_custodia === 1
                            ? 'diferencia de custodia a revisar'
                            : 'diferencias de custodia a revisar'
                    }}
                </p>
                <p class="text-muted-foreground mt-0.5">
                    Lo contado con algún colaborador no coincide con lo que
                    tenía bajo su custodia. Queda registrado en esta ronda, pero
                    no modifica el inventario, ni la custodia, ni su finalidad:
                    revísalo y, si corresponde, registra la devolución o el
                    reporte de robo/pérdida por su flujo habitual.
                </p>
            </div>
        </div>

        <!-- Resumen: unidades identificadas / QR -->
        <section class="space-y-2" data-tour="resumen-conteo">
            <h2 class="text-sm font-semibold">Unidades identificadas / QR</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl border p-3">
                    <p class="text-muted-foreground text-xs">Esperadas</p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ contadores.esperados }}
                    </p>
                </div>
                <div class="rounded-xl border p-3">
                    <p class="text-muted-foreground text-xs">Presentes</p>
                    <p
                        class="text-2xl font-semibold text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        {{ contadores.encontrados }}
                    </p>
                </div>
                <div class="rounded-xl border p-3">
                    <p class="text-muted-foreground text-xs">
                        {{
                            enProceso
                                ? 'Pendientes de verificar'
                                : 'No localizadas'
                        }}
                    </p>
                    <p
                        class="text-2xl font-semibold tabular-nums"
                        :class="
                            enProceso
                                ? 'text-foreground'
                                : 'text-red-600 dark:text-red-400'
                        "
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

        <!-- Escaneo (sólo mientras la ronda está en proceso) -->
        <div
            v-if="puedeEscanear"
            data-tour="escaneo-qr"
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
            <div v-if="almacenes.length > 1" class="w-full sm:w-52">
                <label for="filtro-almacen" class="sr-only"
                    >Filtrar por almacén</label
                >
                <SelectSimple
                    id="filtro-almacen"
                    :model-value="almacenFiltro ?? ''"
                    :opciones="OPCIONES_ALMACEN"
                    placeholder="Almacén"
                    @update:model-value="cambiarAlmacen"
                />
            </div>
            <div class="w-full sm:w-52">
                <label for="filtro-estado-unidad" class="sr-only"
                    >Filtrar por estado de la unidad</label
                >
                <SelectSimple
                    id="filtro-estado-unidad"
                    :model-value="estadoUnidad ?? ''"
                    :opciones="OPCIONES_ESTADO_UNIDAD"
                    placeholder="Estado de la unidad"
                    @update:model-value="cambiarEstadoUnidad"
                />
            </div>
            <SelectorVista v-model="vista" class="ml-auto" />
        </div>

        <p v-if="puedeEscanear" class="text-muted-foreground -mt-1 text-xs">
            «Presente» confirma que la unidad existe (equivale a escanear su
            QR), aunque esté asignada a alguien o en reparación: no cambia su
            custodio, almacén ni estado. «Deshacer» revierte la marca mientras
            la ronda siga abierta.
        </p>

        <EstadoVacio
            v-if="!filas.length"
            titulo="Sin unidades en esta sección"
            :descripcion="
                seccion === 'faltantes'
                    ? 'No hay unidades pendientes con este filtro.'
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
                        <th class="px-3 py-2 font-medium">Estado actual</th>
                        <th class="px-3 py-2 font-medium">Ubicación actual</th>
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
                        <td class="px-3 py-2">
                            <Badge
                                v-if="f.estado_visible"
                                variant="outline"
                                class="text-xs whitespace-nowrap"
                                :class="
                                    claseEstadoVisibleUnidad(f.estado_visible)
                                "
                            >
                                {{ f.estado_visible_etiqueta }}
                            </Badge>
                        </td>
                        <td class="text-muted-foreground px-3 py-2 text-xs">
                            <template v-if="f.ubicacion === 'colaborador'">
                                <p class="text-foreground text-sm">
                                    {{ f.colaborador }}
                                </p>
                                <p
                                    v-if="
                                        f.colaborador_sucursal ||
                                        f.colaborador_servicio
                                    "
                                >
                                    {{
                                        [
                                            f.colaborador_sucursal,
                                            f.colaborador_servicio,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </p>
                            </template>
                            <template v-else>
                                <p class="text-foreground text-sm">
                                    {{ f.almacen ?? '—' }}
                                </p>
                                <p>En almacén</p>
                            </template>
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
                                    f.clasificacion === 'pendiente'
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
                            <span v-else-if="f.clasificacion === 'pendiente'"
                                >Pendiente de verificar</span
                            >
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

                <Badge
                    v-if="f.estado_visible"
                    variant="outline"
                    class="w-fit text-xs"
                    :class="claseEstadoVisibleUnidad(f.estado_visible)"
                >
                    {{ f.estado_visible_etiqueta }}
                </Badge>

                <!-- Ubicación operativa actual: sólo lo que aplica, sin "—" de relleno. -->
                <div
                    class="text-muted-foreground grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5 text-xs"
                >
                    <template v-if="f.ubicacion === 'colaborador'">
                        <span>Asignada a:</span>
                        <span class="text-foreground min-w-0 break-words">{{
                            f.colaborador
                        }}</span>
                        <template v-if="f.colaborador_sucursal">
                            <span>Sucursal:</span>
                            <span class="text-foreground">{{
                                f.colaborador_sucursal
                            }}</span>
                        </template>
                        <template v-if="f.colaborador_servicio">
                            <span>Servicio:</span>
                            <span class="text-foreground">{{
                                f.colaborador_servicio
                            }}</span>
                        </template>
                    </template>
                    <template v-else>
                        <span>Almacén:</span>
                        <span class="text-foreground">{{
                            f.almacen ?? '—'
                        }}</span>
                    </template>
                </div>
                <p
                    v-if="
                        f.ubicacion === 'colaborador' &&
                        f.clasificacion === 'pendiente'
                    "
                    class="text-xs text-amber-700 dark:text-amber-400"
                >
                    No está en un almacén: confírmala con quien la tiene.
                </p>

                <div class="text-muted-foreground text-xs">
                    <template v-if="f.escaneado_en">
                        Escaneada: {{ fecha(f.escaneado_en) }}
                        <span v-if="f.escaneado_por" class="block">
                            Por: {{ f.escaneado_por }}
                        </span>
                    </template>
                    <span v-else-if="f.clasificacion === 'pendiente'"
                        >Aún sin verificar.</span
                    >
                    <span v-else>Sin verificar.</span>
                </div>
                <div
                    v-if="
                        puedeEscanear &&
                        f.esperada &&
                        (f.clasificacion === 'pendiente' || f.escaneado_en)
                    "
                >
                    <Button
                        v-if="f.clasificacion === 'pendiente'"
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
        <!-- Resumen: artículos por cantidad -->
        <section
            v-if="contadores.cantidad_renglones > 0"
            class="space-y-2"
            data-tour="cantidad-articulos"
        >
            <h2 class="text-sm font-semibold">Artículos por cantidad</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
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
                <div class="rounded-xl border p-3">
                    <p class="text-muted-foreground text-xs">
                        No fue posible verificar
                    </p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ contadores.cantidad_no_verificables }}
                    </p>
                </div>
            </div>
            <p class="text-muted-foreground text-xs">
                Esperado total: {{ contadores.cantidad_esperada_total }} ·
                Contado total:
                {{ contadores.cantidad_contada_total }}
                <template v-if="contadores.cantidad_custodia_renglones > 0">
                    · Incluye {{ contadores.cantidad_custodia_renglones }}
                    renglón(es) bajo custodia de colaboradores
                </template>
            </p>
        </section>

        <section
            v-if="existencias.length"
            class="space-y-3 rounded-xl border p-4"
        >
            <div>
                <h2 class="text-sm font-semibold">
                    Artículos por cantidad · comprobación manual
                </h2>
                <p class="text-muted-foreground mt-0.5 text-xs">
                    Prendas y consumibles sin QR individual, en almacén o bajo
                    custodia de un colaborador (compruébalo con esa persona:
                    presencial, llamada o videollamada). Marca «Coincide» si el
                    conteo cuadra con lo esperado, o captura la cantidad real
                    contada. Esto sólo compara: no ajusta el inventario ni
                    cambia la custodia.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <template v-if="hayCustodia">
                    <Button
                        v-for="o in OPCIONES_ORIGEN"
                        :key="o.valor"
                        size="sm"
                        :variant="
                            filtroOrigen === o.valor ? 'default' : 'outline'
                        "
                        :aria-pressed="filtroOrigen === o.valor"
                        @click="filtroOrigen = o.valor"
                    >
                        {{ o.etiqueta }} ({{ totalOrigen(o.valor) }})
                    </Button>
                </template>
                <div class="w-full sm:ml-auto sm:w-64">
                    <label for="buscar-existencia" class="sr-only"
                        >Buscar por activo, almacén o colaborador</label
                    >
                    <Input
                        id="buscar-existencia"
                        v-model="busquedaExistencia"
                        type="search"
                        placeholder="Buscar activo, almacén o persona"
                    />
                </div>
            </div>

            <p
                v-if="existenciasVisibles.length === 0"
                class="text-muted-foreground text-sm"
            >
                Ningún renglón coincide con el filtro.
            </p>

            <!-- Móvil: cards (evita el scroll horizontal de la tabla). Misma
                 data/estado/función que la tabla de escritorio, sólo cambia
                 la presentación. -->
            <div class="grid gap-3 md:hidden">
                <div
                    v-for="e in existenciasVisibles"
                    :key="e.id"
                    class="flex min-w-0 flex-col gap-2 rounded-xl border p-3 text-sm"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 space-y-0.5">
                            <Badge
                                :variant="
                                    e.origen === 'custodia'
                                        ? varianteBadgeFinalidad(e.finalidad)
                                        : 'success'
                                "
                                class="text-xs"
                                >{{
                                    e.origen === 'custodia'
                                        ? e.finalidad_etiqueta
                                        : 'En almacén'
                                }}</Badge
                            >
                            <p class="font-medium break-words">
                                {{ ubicacionExistencia(e) }}
                            </p>
                            <p
                                v-if="contextoCustodio(e)"
                                class="text-muted-foreground text-xs"
                            >
                                {{ contextoCustodio(e) }}
                            </p>
                            <p class="break-words">{{ e.activo ?? '—' }}</p>
                            <p class="text-muted-foreground text-xs">
                                {{ ETIQUETA_VARIANTE }}:
                                <span class="text-foreground font-medium">{{
                                    textoVariante(e.talla)
                                }}</span>
                            </p>
                        </div>
                        <Badge
                            variant="outline"
                            class="shrink-0 text-xs"
                            :class="ETIQUETA_EXISTENCIA[e.resultado].clase"
                        >
                            {{ ETIQUETA_EXISTENCIA[e.resultado].texto }}
                        </Badge>
                    </div>
                    <p
                        v-if="e.verificada_por"
                        class="text-muted-foreground text-xs"
                    >
                        {{
                            e.no_verificable ? 'Registrado por' : 'Contado por'
                        }}
                        {{ e.verificada_por }}
                    </p>
                    <p v-if="e.motivo_no_verificable" class="text-xs">
                        <span class="text-muted-foreground">Motivo:</span>
                        {{ e.motivo_no_verificable }}
                    </p>

                    <div
                        class="bg-muted/40 grid grid-cols-3 divide-x rounded-lg text-center"
                    >
                        <div class="px-2 py-1.5">
                            <p class="text-muted-foreground text-[11px]">
                                {{
                                    etiquetaCantidadEsperada(
                                        e.origen === 'custodia',
                                    )
                                }}
                            </p>
                            <p class="font-semibold tabular-nums">
                                {{ e.cantidad_esperada }}
                            </p>
                        </div>
                        <div class="px-2 py-1.5">
                            <p class="text-muted-foreground text-[11px]">
                                Cantidad contada
                            </p>
                            <p class="font-semibold tabular-nums">
                                {{ e.cantidad_contada ?? '—' }}
                            </p>
                        </div>
                        <div class="px-2 py-1.5">
                            <p class="text-muted-foreground text-[11px]">
                                Diferencia
                            </p>
                            <p
                                class="font-semibold tabular-nums"
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
                            </p>
                        </div>
                    </div>

                    <div v-if="puedeEscanear" class="flex flex-col gap-1.5">
                        <Button
                            size="sm"
                            variant="outline"
                            class="h-10 w-full"
                            :disabled="guardandoExistencia !== null"
                            @click="verificarExistencia(e, e.cantidad_esperada)"
                        >
                            <CheckCircle2 class="size-4" /> Coincide
                        </Button>
                        <div class="flex items-center gap-1.5">
                            <Input
                                v-model.number="borrador[e.id]"
                                type="number"
                                min="0"
                                class="h-10 flex-1"
                                placeholder="Cantidad real"
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-10"
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
                        <Button
                            v-if="e.no_verificable"
                            size="sm"
                            variant="ghost"
                            class="h-10 w-full"
                            :disabled="guardandoExistencia !== null"
                            @click="reabrirExistencia(e)"
                        >
                            <RotateCcw class="size-4" /> Reabrir como pendiente
                        </Button>
                        <Button
                            v-else
                            size="sm"
                            variant="ghost"
                            class="h-10 w-full"
                            :disabled="guardandoExistencia !== null"
                            @click="abrirNoVerificable(e)"
                        >
                            <CircleAlert class="size-4" /> No fue posible
                            verificar
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Escritorio / tablet ancha: tabla (permite comparar varias
                 filas a la vez sin perder contexto). -->
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="text-muted-foreground text-left">
                        <tr>
                            <th class="py-1.5 pr-3">Almacén / custodio</th>
                            <th class="py-1.5">Activo</th>
                            <th class="py-1.5">{{ ETIQUETA_VARIANTE }}</th>
                            <th class="py-1.5 text-right">Cantidad esperada</th>
                            <th class="py-1.5 text-right">Cantidad contada</th>
                            <th class="py-1.5 text-right">Diferencia</th>
                            <th class="py-1.5">Resultado</th>
                            <th v-if="puedeEscanear" class="py-1.5">
                                Verificar
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="e in existenciasVisibles"
                            :key="e.id"
                            class="border-t align-top"
                        >
                            <td class="py-1.5 pr-3">
                                <Badge
                                    :variant="
                                        e.origen === 'custodia'
                                            ? varianteBadgeFinalidad(
                                                  e.finalidad,
                                              )
                                            : 'success'
                                    "
                                    class="text-xs"
                                    >{{
                                        e.origen === 'custodia'
                                            ? e.finalidad_etiqueta
                                            : 'En almacén'
                                    }}</Badge
                                >
                                <span class="block">{{
                                    ubicacionExistencia(e)
                                }}</span>
                                <span
                                    v-if="contextoCustodio(e)"
                                    class="text-muted-foreground block text-xs"
                                    >{{ contextoCustodio(e) }}</span
                                >
                            </td>
                            <td class="py-1.5">{{ e.activo ?? '—' }}</td>
                            <td class="py-1.5">{{ textoVariante(e.talla) }}</td>
                            <td class="py-1.5 text-right tabular-nums">
                                {{ e.cantidad_esperada }}
                                <span
                                    v-if="e.origen === 'custodia'"
                                    class="text-muted-foreground block text-xs"
                                    >en custodia</span
                                >
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
                                <span
                                    v-if="e.verificada_por"
                                    class="text-muted-foreground block text-xs"
                                    >por {{ e.verificada_por }}</span
                                >
                                <span
                                    v-if="e.motivo_no_verificable"
                                    class="block max-w-48 text-xs break-words"
                                    ><span class="text-muted-foreground"
                                        >Motivo:</span
                                    >
                                    {{ e.motivo_no_verificable }}</span
                                >
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
                                <Button
                                    v-if="e.no_verificable"
                                    size="sm"
                                    variant="link"
                                    class="h-auto px-0 text-xs"
                                    :disabled="guardandoExistencia !== null"
                                    @click="reabrirExistencia(e)"
                                >
                                    <RotateCcw class="size-3.5" /> Reabrir como
                                    pendiente
                                </Button>
                                <Button
                                    v-else
                                    size="sm"
                                    variant="link"
                                    class="h-auto px-0 text-xs"
                                    :disabled="guardandoExistencia !== null"
                                    @click="abrirNoVerificable(e)"
                                >
                                    <CircleAlert class="size-3.5" /> No fue
                                    posible verificar
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Cerrar la ronda es la última acción de la pantalla, siempre al
             final: nunca en medio del flujo de captura, para no interrumpir
             al usuario mientras todavía está registrando conteos. -->
        <div
            v-if="puedeEscanear"
            data-tour="finalizar-ronda"
            class="flex flex-col items-start gap-2 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-muted-foreground text-sm">
                Cuando termines de capturar y revisar todo, cierra la ronda para
                dejarla firmada y lista para aplicar sus diferencias.
            </p>
            <Button
                variant="outline"
                class="w-full border-red-600/30 text-red-700 hover:bg-red-50 sm:w-auto dark:text-red-400 dark:hover:bg-red-950/40"
                @click="dialogoFinalizar = true"
            >
                Finalizar inventario
            </Button>
        </div>

        <Dialog
            :open="filaNoVerificable !== null"
            @update:open="(v: boolean) => !v && (filaNoVerificable = null)"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>No fue posible verificar</DialogTitle>
                    <DialogDescription>
                        Úsalo cuando no pudiste comprobar este renglón. No
                        equivale a contar 0: no genera diferencia, no ajusta el
                        inventario ni la custodia, y permite cerrar la ronda.
                        Puedes reabrirlo o capturar la cantidad mientras la
                        ronda siga abierta.
                    </DialogDescription>
                </DialogHeader>

                <p v-if="filaNoVerificable" class="text-sm">
                    <strong>{{ filaNoVerificable.activo ?? '—' }}</strong>
                    · {{ ETIQUETA_VARIANTE }}:
                    {{ textoVariante(filaNoVerificable.talla) }}
                    <span class="text-muted-foreground block">
                        {{ ubicacionExistencia(filaNoVerificable) }} ·
                        {{
                            etiquetaCantidadEsperada(
                                filaNoVerificable.origen === 'custodia',
                            )
                        }}: {{ filaNoVerificable.cantidad_esperada }}
                    </span>
                </p>

                <div class="space-y-1.5">
                    <Label for="motivo-no-verificable">Motivo (opcional)</Label>
                    <Input
                        id="motivo-no-verificable"
                        v-model="motivoNoVerificable"
                        maxlength="255"
                        placeholder="Ej. El colaborador no respondió"
                    />
                </div>

                <DialogFooter>
                    <Button
                        variant="ghost"
                        :disabled="guardandoExistencia !== null"
                        @click="filaNoVerificable = null"
                    >
                        Cancelar
                    </Button>
                    <Button
                        :disabled="guardandoExistencia !== null"
                        @click="confirmarNoVerificable"
                    >
                        Confirmar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

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
                    v-if="contadores.cantidad_renglones > 0"
                    class="bg-muted/40 rounded-md border p-2 text-sm"
                >
                    Artículos por cantidad que firmas:
                    <strong>{{ contadores.cantidad_verificados }}</strong>
                    contado(s) ·
                    <strong>{{ contadores.cantidad_con_diferencia }}</strong>
                    con diferencia ·
                    <strong>{{ contadores.cantidad_no_verificables }}</strong>
                    no fue posible verificar ·
                    <strong>{{ contadores.cantidad_pendientes }}</strong>
                    pendiente(s).
                </p>

                <p
                    v-if="contadores.cantidad_pendientes > 0"
                    class="rounded-md border border-amber-500/40 bg-amber-500/10 p-2 text-sm text-amber-700 dark:text-amber-400"
                >
                    Aún hay {{ contadores.cantidad_pendientes }} renglón(es) de
                    artículos por cantidad pendientes. Captura una cantidad o
                    marca «No fue posible verificar» antes de cerrar la ronda.
                    (Las unidades QR faltantes sí se pueden dejar así — son un
                    resultado válido.)
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

        <!-- Revisar y aplicar correcciones: TODAS las diferencias elegibles o
             ninguna — sin selección parcial en esta versión. -->
        <Dialog v-model:open="dialogoRevisarCorrecciones">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle
                        >Revisar correcciones de inventario</DialogTitle
                    >
                    <DialogDescription>
                        Se actualizarán las existencias para que coincidan con
                        el conteo físico registrado en esta ronda.
                    </DialogDescription>
                </DialogHeader>

                <p class="text-muted-foreground text-sm">
                    <strong>{{ ronda.empresa }}</strong>
                    <span v-if="ronda.almacen"> · {{ ronda.almacen }}</span>
                    <span v-else-if="ronda.general"> · Toda la empresa</span>
                </p>

                <ul class="grid max-h-[50vh] gap-2 overflow-y-auto">
                    <li
                        v-for="e in diferenciasParaAplicar"
                        :key="e.id"
                        class="rounded-lg border p-3 text-sm"
                    >
                        <p class="truncate font-medium">
                            {{ e.activo ?? '—' }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ e.almacen ?? '—' }} · {{ ETIQUETA_VARIANTE }}:
                            {{ textoVariante(e.talla) }}
                        </p>
                        <div class="mt-2 grid grid-cols-3 gap-2 text-center">
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    Sistema
                                </p>
                                <p class="font-medium tabular-nums">
                                    {{ e.cantidad_esperada }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    Contado
                                </p>
                                <p class="font-medium tabular-nums">
                                    {{ e.cantidad_contada }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    Cambio
                                </p>
                                <p
                                    class="font-medium tabular-nums"
                                    :class="
                                        (e.diferencia ?? 0) < 0
                                            ? 'text-red-600 dark:text-red-400'
                                            : 'text-amber-600 dark:text-amber-400'
                                    "
                                >
                                    {{
                                        (e.diferencia ?? 0) > 0
                                            ? `+${e.diferencia}`
                                            : e.diferencia
                                    }}
                                </p>
                            </div>
                        </div>
                    </li>
                </ul>

                <p class="text-muted-foreground text-sm">
                    Se actualizarán {{ diferenciasParaAplicar.length }}
                    {{
                        diferenciasParaAplicar.length === 1
                            ? 'existencia'
                            : 'existencias'
                    }}
                    para que coincidan con el conteo físico registrado en esta
                    ronda. Esta acción quedará registrada.
                </p>

                <DialogFooter>
                    <Button
                        variant="ghost"
                        :disabled="aplicandoCorrecciones"
                        @click="dialogoRevisarCorrecciones = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        :disabled="aplicandoCorrecciones"
                        @click="aplicarCorrecciones"
                    >
                        {{
                            aplicandoCorrecciones
                                ? 'Aplicando…'
                                : 'Aplicar correcciones'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Conflicto: alguna existencia cambió después del snapshot de la
             ronda. El backend ya abortó TODO el lote — aquí sólo se explica
             por qué, nunca se reintenta automáticamente. -->
        <Dialog v-model:open="dialogoConflictos">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle
                        >Las correcciones siguen pendientes</DialogTitle
                    >
                    <DialogDescription>
                        No se aplicaron las correcciones porque algunas
                        existencias cambiaron después de realizar esta ronda.
                        Revísalas antes de continuar.
                    </DialogDescription>
                </DialogHeader>

                <ul class="grid max-h-[50vh] gap-2 overflow-y-auto">
                    <li
                        v-for="(c, i) in conflictosMostrados"
                        :key="i"
                        class="rounded-lg border p-3 text-sm"
                    >
                        <p class="truncate font-medium">{{ c.activo }}</p>
                        <p class="text-muted-foreground text-xs">
                            {{ c.talla ?? 'Sin variante' }}
                        </p>
                        <div class="mt-2 grid grid-cols-2 gap-2 text-center">
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    Durante la ronda
                                </p>
                                <p class="font-medium tabular-nums">
                                    {{ c.esperada }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    Actual
                                </p>
                                <p class="font-medium tabular-nums">
                                    {{ c.actual }}
                                </p>
                            </div>
                        </div>
                    </li>
                </ul>

                <DialogFooter>
                    <Button @click="dialogoConflictos = false">
                        Entendido
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
