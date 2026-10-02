<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Calendar,
    ChevronLeft,
    ChevronRight,
    Combine,
    Split,
} from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, nextTick, ref, watch } from 'vue';
import AlertaProblemasMovil from '@/components/sistema/AlertaProblemasMovil.vue';
import ApartadoTemporalBanner from '@/components/sistema/ApartadoTemporalBanner.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import CapturaEvidencia from '@/components/sistema/CapturaEvidencia.vue';
import DocumentoIdentidadColaborador from '@/components/sistema/DocumentoIdentidadColaborador.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import FirmaColaborador from '@/components/sistema/FirmaColaborador.vue';
import type { MetodoFirma } from '@/components/sistema/FirmaColaborador.vue';
import PadFirma from '@/components/sistema/PadFirma.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    type RespuestaReserva,
    useReservaBorrador,
} from '@/composables/useReservaBorrador';
import { useDisponibilidadViva } from '@/composables/useDisponibilidadViva';
import { fechaNegocio } from '@/lib/fecha';
import { varianteBadgeFinalidad } from '@/lib/finalidadCustodia';
import { mensajeDisponibilidadInsuficiente } from '@/lib/mensajesDisponibilidad';
import {
    estadoReparto,
    partesParaEnviar,
    type Reparto,
    repartoInicial,
} from '@/lib/repartoCondicion';

type OpcionEntrega = {
    id: number;
    folio: string;
    colaborador: string | null;
    numero_empleado: string | null;
    empresa: string | null;
    fecha_entrega: string;
};

type OpcionAlmacen = { id: number; nombre: string; codigo: string | null };

type Renglon = {
    detalle_entrega_id: number;
    activo: string;
    talla: string | null;
    cantidad: number;
    finalidad: 'uso_personal' | 'redistribucion' | null;
    finalidad_etiqueta: string;
    pendiente: number | null;
    /** Cuánto ya se devolvió CONFIRMADO hasta ahora (`cantidad - pendiente`). */
    ya_devuelto: number | null;
    es_unidad: boolean;
    unidad_codigo: string | null;
    unidad_disponible: boolean;
    unidad_estado_visible: string | null;
    unidad_estado_visible_etiqueta: string | null;
    /**
     * Regla por renglón del backend (`DevolucionPolicy::recibirRenglon`):
     * uso personal / sin clasificar de la PROPIA custodia sin el permiso
     * especial → visible pero no seleccionable.
     */
    puede_devolver: boolean;
    motivo_bloqueo: string | null;
};

/** Respuesta de `POST /devoluciones/reserva` (ver `App\Acciones\ReservarCustodiaDevolucion`). */
type RespuestaReservaDevolucion = RespuestaReserva & {
    lineas_cantidad: {
        detalle_entrega_id: number;
        activo_nombre: string | null;
        talla_valor: string | null;
        pendiente_real: number;
        disponible_efectivo: number;
        solicitado: number;
        suficiente: boolean;
    }[];
    lineas_unidad: {
        detalle_entrega_id: number;
        unidad_activo_id: number | null;
        ok: boolean;
        motivo: string | null;
    }[];
};

type Entrega = {
    id: number;
    folio: string;
    empresa_id: number;
    empresa: string | null;
    colaborador: string | null;
    almacen_id: number | null;
    almacen: string | null;
    renglones: Renglon[];
};

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
};

type ColaboradorContexto = {
    id: number;
    nombre: string;
    pendientes: PendienteFila[];
};

const props = defineProps<{
    entrega: Entrega | null;
    colaboradorContexto: ColaboradorContexto | null;
    condiciones: { valor: string; etiqueta: string }[];
    condicionesUnidad: { valor: string; etiqueta: string }[];
    textoConsentimiento: string;
    /**
     * Mensaje cuando quien opera representa al colaborador que devuelve y no
     * tiene `devoluciones.procesar-custodia-propia` (el backend vuelve a
     * validarlo al apartar y al confirmar).
     */
    bloqueoCustodiaPropia: string | null;
    /**
     * Fecha de negocio "de hoy" ("Y-m-d"), calculada en el servidor con la
     * zona de presentación de la aplicación — nunca `new Date().toISOString()`
     * del navegador, que cerca de medianoche puede desfasarse un día por UTC.
     * Sólo para MOSTRARLA de forma no editable: la fecha realmente guardada
     * siempre la decide el servidor al confirmar.
     */
    fechaActual: string;
}>();

// ------------------------------------------------------------------
// Contexto "Transferencia → Devoluciones": agrupa los pendientes REALES
// del colaborador (IDs, no folios) por entrega de origen para poder
// procesarlos uno por uno sin volver al perfil entre cada uno.
// ------------------------------------------------------------------
type GrupoPendiente = {
    entregaId: number | null;
    folio: string;
    filas: PendienteFila[];
};

const gruposPendientes = computed<GrupoPendiente[]>(() => {
    const mapa = new Map<string, GrupoPendiente>();
    for (const fila of props.colaboradorContexto?.pendientes ?? []) {
        const clave =
            fila.entrega_id !== null
                ? String(fila.entrega_id)
                : `sin-entrega-${fila.referencia}`;
        if (!mapa.has(clave)) {
            mapa.set(clave, {
                entregaId: fila.entrega_id,
                folio: fila.entrega_folio ?? 'Sin folio',
                filas: [],
            });
        }
        mapa.get(clave)!.filas.push(fila);
    }
    return Array.from(mapa.values());
});

function procesarGrupo(grupo: GrupoPendiente): void {
    if (grupo.entregaId === null || !props.colaboradorContexto) return;
    router.get(
        '/devoluciones/crear',
        {
            colaborador_id: props.colaboradorContexto.id,
            entrega_id: grupo.entregaId,
        },
        { preserveState: false },
    );
}

const hrefCancelar = computed(() =>
    props.colaboradorContexto
        ? `/devoluciones/crear?colaborador_id=${props.colaboradorContexto.id}`
        : '/devoluciones',
);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Devoluciones', href: '/devoluciones' },
            { title: 'Nueva devolución', href: '/devoluciones/crear' },
        ],
    },
});

// Fecha de negocio "de hoy": SIEMPRE la que da el servidor (`fechaActual`),
// nunca `new Date().toISOString()` — evita el desfase de día por UTC cerca de
// medianoche. Ya no es editable por el usuario (ver "Fecha" abajo).
const hoy = props.fechaActual;

// ------------------------------------------------------------------
// Pasos: la devolución NO queda concluida hasta firmar (una sola
// operación atómica en el backend). No se puede saltar de paso.
// ------------------------------------------------------------------
const PASOS = [
    { n: 1, titulo: 'Datos de la devolución' },
    { n: 2, titulo: 'Artículos, condición y evidencias' },
    { n: 3, titulo: 'Revisión y firmas' },
] as const;
const paso = ref<1 | 2 | 3>(1);

// ------------------------------------------------------------------
// Sin entrega precargada: buscarla primero (siempre se origina de una).
// ------------------------------------------------------------------
async function buscarEntregas(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionEntrega[]> {
    const res = await fetch(`/entregas/buscar?q=${encodeURIComponent(q)}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];
    return (await res.json()).entregas ?? [];
}

function elegirEntrega(o: OpcionEntrega | null): void {
    if (!o) return;
    router.get(
        '/devoluciones/crear',
        { entrega_id: o.id },
        { preserveState: false },
    );
}

// ------------------------------------------------------------------
// Entrega precargada: renglones + almacén destino
// ------------------------------------------------------------------
type OrigenEvidencia = 'camara' | 'archivo' | null;
type FilaCantidad = {
    detalle_entrega_id: number;
    incluir: boolean;
    cantidad: number;
    condicion: string;
    /** "Dividir por condición": el mismo renglón en varias condiciones. */
    dividir: boolean;
    reparto: Reparto;
    evidencia: File | null;
    evidencia_origen: OrigenEvidencia;
};
type FilaUnidad = {
    detalle_entrega_id: number;
    incluir: boolean;
    condicion: string;
    evidencia: File | null;
    evidencia_origen: OrigenEvidencia;
};

const filasCantidad = ref<FilaCantidad[]>(
    (props.entrega?.renglones ?? [])
        .filter((r) => !r.es_unidad && (r.pendiente ?? 0) > 0)
        .map((r) => ({
            detalle_entrega_id: r.detalle_entrega_id,
            incluir: false,
            cantidad: r.pendiente ?? 0,
            condicion: 'reutilizable',
            dividir: false,
            reparto: {},
            evidencia: null,
            evidencia_origen: null,
        })),
);

const filasUnidad = ref<FilaUnidad[]>(
    (props.entrega?.renglones ?? [])
        .filter((r) => r.es_unidad && r.unidad_disponible)
        .map((r) => ({
            detalle_entrega_id: r.detalle_entrega_id,
            incluir: false,
            condicion: 'funcionando',
            evidencia: null,
            evidencia_origen: null,
        })),
);

function renglonDe(id: number): Renglon | undefined {
    return props.entrega?.renglones.find((r) => r.detalle_entrega_id === id);
}

const almacenSel = ref<OpcionAlmacen | null>(
    props.entrega?.almacen_id && props.entrega.almacen
        ? {
              id: props.entrega.almacen_id,
              nombre: props.entrega.almacen,
              codigo: null,
          }
        : null,
);

const form = useForm<{
    entrega_uniforme_id: number | '';
    colaborador_id: number | null;
    almacen_id: number | null;
    fecha: string;
    motivo: string;
    notas: string;
    activos: {
        detalle_entrega_id: number;
        cantidad: number;
        condicion?: string;
        condiciones?: { condicion: string; cantidad: number }[];
        evidencia: File | null;
        evidencia_origen: OrigenEvidencia;
    }[];
    unidades: {
        detalle_entrega_id: number;
        condicion: string;
        evidencia: File | null;
        evidencia_origen: OrigenEvidencia;
    }[];
    firma: string;
    /** Firma de quien recibe/devuelve: dibujada en el pad o archivo subido. */
    firma_metodo: MetodoFirma;
    firma_archivo: File | null;
    firma_operador: string;
    aceptacion: boolean;
}>({
    entrega_uniforme_id: props.entrega?.id ?? '',
    colaborador_id: props.colaboradorContexto?.id ?? null,
    almacen_id: props.entrega?.almacen_id ?? null,
    fecha: hoy,
    motivo: '',
    notas: '',
    activos: [],
    unidades: [],
    firma: '',
    firma_metodo: 'dibujada',
    firma_archivo: null,
    firma_operador: '',
    aceptacion: false,
});

/** Acceso laxo a errores anidados (`activos.0.cantidad`, `negocio`…). */
const erroresLaxos = computed(
    () => form.errors as unknown as Record<string, string>,
);

async function buscarAlmacenes(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionAlmacen[]> {
    if (!props.entrega) return [];
    const res = await fetch(
        `/almacenes/buscar?empresa_id=${props.entrega.empresa_id}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).almacenes ?? [];
}

function alElegirAlmacen(o: OpcionAlmacen | null): void {
    almacenSel.value = o;
    form.almacen_id = o?.id ?? null;
    form.clearErrors('almacen_id');
}

// ------------------------------------------------------------------
// Validez por paso — bloqueo + mensaje visible (no sólo botón disabled)
// ------------------------------------------------------------------
const filasCantidadIncluidas = computed(() =>
    filasCantidad.value.filter((f) => f.incluir),
);
const filasUnidadIncluidas = computed(() =>
    filasUnidad.value.filter((f) => f.incluir),
);

const hayAlgoIncluido = computed(
    () =>
        filasCantidadIncluidas.value.length > 0 ||
        filasUnidadIncluidas.value.length > 0,
);

// ------------------------------------------------------------------
// Apartado temporal (TTL) de CUSTODIA pendiente — ver
// `App\Acciones\ReservarCustodiaDevolucion`. NUNCA aparta stock de almacén:
// aparta el DERECHO a devolver un renglón concreto, para que dos usuarios no
// intenten devolver el mismo pendiente a la vez. Nunca reemplaza la
// revalidación autoritativa de `RegistrarDevolucion` al confirmar.
// ------------------------------------------------------------------
const reserva = useReservaBorrador<RespuestaReservaDevolucion>({
    reservar: '/devoluciones/reserva',
    liberarBase: '/devoluciones/reserva',
    extenderBase: '/devoluciones/reserva',
});

function construirPayloadReserva(): Record<string, unknown> {
    return {
        entrega_uniforme_id: props.entrega?.id ?? null,
        colaborador_id: props.colaboradorContexto?.id ?? null,
        activos: filasCantidadIncluidas.value.map((f) => ({
            detalle_entrega_id: f.detalle_entrega_id,
            cantidad: f.cantidad,
        })),
        unidades: filasUnidadIncluidas.value.map((f) => ({
            detalle_entrega_id: f.detalle_entrega_id,
        })),
    };
}

watch(
    [filasCantidad, filasUnidad],
    () => {
        if (!props.entrega || props.bloqueoCustodiaPropia) return;
        reserva.reservarConRetraso(construirPayloadReserva());
    },
    { deep: true },
);

/** Línea de reserva de este renglón (si ya se calculó al menos una vez). */
function lineaReservaDe(detalleEntregaId: number) {
    return reserva.resultado.value?.lineas_cantidad.find(
        (l) => l.detalle_entrega_id === detalleEntregaId,
    );
}

// ------------------------------------------------------------------
// Custodia devolvible "viva": sin recargar la página, cada ~8 s (pestaña
// visible, paso 2, sin confirmar) se relee cuánto de cada renglón sigue
// pendiente y cuánto apartó OTRA devolución — con el token propio, así lo
// apartado por este borrador nunca se descuenta a sí mismo. Es CUSTODIA,
// nunca stock de almacén, y SÓLO lectura: no toca el apartado, su TTL, la
// custodia real ni el inventario.
// ------------------------------------------------------------------
type CustodiaViva = {
    pendiente_real: number;
    apartado_por_otros: number;
    disponible: number;
};
type UnidadViva = { unidad_disponible: boolean; apartada_por_otro: boolean };
type RespuestaCustodiaViva = {
    renglones: (CustodiaViva & { detalle_entrega_id: number })[];
    unidades: (UnidadViva & { detalle_entrega_id: number })[];
};

const custodiaViva = ref<Record<number, CustodiaViva>>({});
const unidadesVivas = ref<Record<number, UnidadViva>>({});
/** Renglones cuya cantidad devolvible BAJÓ con el formulario abierto. */
const renglonesReducidos = ref(new Set<number>());

function fusionarCustodia(renglones: RespuestaCustodiaViva['renglones']): void {
    const mapa = { ...custodiaViva.value };
    const reducidos = new Set(renglonesReducidos.value);
    for (const r of renglones) {
        const previo =
            mapa[r.detalle_entrega_id]?.disponible ??
            renglonDe(r.detalle_entrega_id)?.pendiente ??
            undefined;
        if (previo !== undefined && previo !== null) {
            if (r.disponible < previo) reducidos.add(r.detalle_entrega_id);
            if (r.disponible > previo) reducidos.delete(r.detalle_entrega_id);
        }
        mapa[r.detalle_entrega_id] = {
            pendiente_real: r.pendiente_real,
            apartado_por_otros: r.apartado_por_otros,
            disponible: r.disponible,
        };
    }
    custodiaViva.value = mapa;
    renglonesReducidos.value = reducidos;
}

const custodiaEnVivo = useDisponibilidadViva<RespuestaCustodiaViva>({
    habilitado: () =>
        !!props.entrega &&
        !props.bloqueoCustodiaPropia &&
        paso.value === 2 &&
        !reserva.confirmando.value &&
        !form.processing,
    consultar: async (signal) => {
        const params = new URLSearchParams({
            entrega_uniforme_id: String(props.entrega?.id),
            token: reserva.token.value,
        });
        const res = await fetch(
            `/devoluciones/disponibilidad?${params.toString()}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal,
            },
        );
        return res.ok ? ((await res.json()) as RespuestaCustodiaViva) : null;
    },
    aplicar: (json) => {
        fusionarCustodia(json.renglones);
        unidadesVivas.value = Object.fromEntries(
            json.unidades.map((u) => [
                u.detalle_entrega_id,
                {
                    unidad_disponible: u.unidad_disponible,
                    apartada_por_otro: u.apartada_por_otro,
                },
            ]),
        );
    },
});

watch(paso, (p) => {
    if (p === 2) void custodiaEnVivo.refrescar();
});

// La respuesta de apartar es la lectura más fresca: actualiza la pantalla
// de inmediato (también tras un rechazo) y descarta un sondeo en vuelo.
watch(
    () => reserva.resultado.value,
    (res) => {
        if (!res) return;
        custodiaEnVivo.invalidar();
        fusionarCustodia(
            res.lineas_cantidad.map((l) => ({
                detalle_entrega_id: l.detalle_entrega_id,
                pendiente_real: l.pendiente_real,
                apartado_por_otros: Math.max(
                    0,
                    l.pendiente_real - l.disponible_efectivo,
                ),
                disponible: l.disponible_efectivo,
            })),
        );
    },
);

/** Pendiente REAL del renglón (vivo si ya se consultó; si no, el de la carga). */
function pendienteDe(detalleEntregaId: number): number {
    return (
        custodiaViva.value[detalleEntregaId]?.pendiente_real ??
        renglonDe(detalleEntregaId)?.pendiente ??
        0
    );
}

/** Cuánto tiene apartado OTRA devolución activa de este renglón (0 si no hay conflicto o aún no se calculó). */
function apartadoPorOtrosDe(detalleEntregaId: number): number {
    const vivo = custodiaViva.value[detalleEntregaId];
    if (vivo) return vivo.apartado_por_otros;
    const linea = lineaReservaDe(detalleEntregaId);
    if (!linea) return 0;
    return Math.max(0, linea.pendiente_real - linea.disponible_efectivo);
}

/** Lo que este borrador puede devolver ahora: pendiente real − apartado por otros. */
function disponibleParaDevolver(detalleEntregaId: number): number {
    return (
        custodiaViva.value[detalleEntregaId]?.disponible ??
        Math.max(
            0,
            pendienteDe(detalleEntregaId) -
                apartadoPorOtrosDe(detalleEntregaId),
        )
    );
}

const problemasPaso2 = computed<string[]>(() => {
    const problemas: string[] = [];
    if (!hayAlgoIncluido.value) {
        problemas.push('Marca al menos un renglón para devolver.');
    }
    // La cifra devolvible ya descuenta lo apartado por OTRAS devoluciones
    // activas (sondeo + respuesta de apartar, ver
    // `App\Acciones\ReservarCustodiaDevolucion`): un renglón puede parecer
    // pendiente y ya estar apartado por otro usuario.
    filasCantidadIncluidas.value.forEach((f) => {
        const r = renglonDe(f.detalle_entrega_id);
        const disponible = disponibleParaDevolver(f.detalle_entrega_id);
        if (!(f.cantidad > 0)) {
            problemas.push(
                `${r?.activo ?? 'Renglón'}: indica una cantidad mayor a 0.`,
            );
        } else if (f.cantidad > disponible) {
            problemas.push(
                mensajeDisponibilidadInsuficiente({
                    operacion: 'devolucion',
                    nombre: r?.activo ?? 'Renglón',
                    variante: r?.talla,
                    solicitado: f.cantidad,
                    disponible,
                    porOtros:
                        apartadoPorOtrosDe(f.detalle_entrega_id) > 0 ||
                        renglonesReducidos.value.has(f.detalle_entrega_id),
                }),
            );
        }
    });

    filasCantidadIncluidas.value.forEach((f) => {
        if (!f.dividir || !(f.cantidad > 0)) return;
        const mensaje = estadoRepartoDe(f).mensaje;
        if (mensaje) {
            problemas.push(
                `${renglonDe(f.detalle_entrega_id)?.activo ?? 'Renglón'}: ${mensaje}`,
            );
        }
    });

    const res = reserva.resultado.value;
    const unidadesRechazadas = new Set(
        (res?.lineas_unidad ?? [])
            .filter((l) => !l.ok)
            .map((l) => l.detalle_entrega_id),
    );
    if (res && !res.ok) {
        for (const l of res.lineas_unidad) {
            if (!l.ok && l.motivo) problemas.push(l.motivo);
        }
    }
    filasUnidadIncluidas.value.forEach((f) => {
        const viva = unidadesVivas.value[f.detalle_entrega_id];
        if (!viva || unidadesRechazadas.has(f.detalle_entrega_id)) return;
        const r = renglonDe(f.detalle_entrega_id);
        const nombre = `«${r?.activo ?? 'Unidad'}» (${r?.unidad_codigo ?? 's/c'})`;
        if (!viva.unidad_disponible) {
            problemas.push(
                `${nombre}: ya no está asignada a este colaborador; no se puede devolver.`,
            );
        } else if (viva.apartada_por_otro) {
            problemas.push(
                `${nombre}: otra devolución apartó esta unidad mientras capturabas. Quítala o espera a que se libere.`,
            );
        }
    });

    return problemas;
});

const puedeAvanzar1 = computed(
    () =>
        !!props.entrega &&
        !props.bloqueoCustodiaPropia &&
        form.almacen_id !== null &&
        form.fecha.trim() !== '',
);

// ------------------------------------------------------------------
// Dividir por condición (sólo renglones por cantidad)
// ------------------------------------------------------------------
function alternarDivision(fila: FilaCantidad): void {
    if (!fila.dividir && Object.keys(fila.reparto).length === 0) {
        fila.reparto = repartoInicial(
            props.condiciones.map((c) => c.valor),
            fila.condicion,
            fila.cantidad,
        );
    }
    fila.dividir = !fila.dividir;
}

function estadoRepartoDe(fila: FilaCantidad) {
    return estadoReparto(fila.cantidad, fila.reparto);
}

function textoReparto(fila: FilaCantidad): string {
    return partesParaEnviar(fila.reparto)
        .map(
            (p) =>
                `${props.condiciones.find((c) => c.valor === p.condicion)?.etiqueta ?? p.condicion} ${p.cantidad}`,
        )
        .join(' · ');
}
const puedeAvanzar2 = computed(
    () => hayAlgoIncluido.value && problemasPaso2.value.length === 0,
);

// ------------------------------------------------------------------
// Paso 3 — firmas
// ------------------------------------------------------------------
const padColaborador = ref<InstanceType<typeof FirmaColaborador> | null>(null);
const padOperador = ref<InstanceType<typeof PadFirma> | null>(null);
const firmaColaboradorVacia = ref(true);
const firmaOperadorVacia = ref(true);
const bloqueIdentidad = ref<InstanceType<
    typeof DocumentoIdentidadColaborador
> | null>(null);

const faltantesFirma = computed<string[]>(() => {
    const faltan: string[] = [];
    if (reserva.vencida.value)
        faltan.push(
            'Tu apartado de custodia venció. Vuelve al paso anterior para actualizar los pendientes.',
        );
    if (firmaColaboradorVacia.value)
        faltan.push(
            form.firma_metodo === 'archivo'
                ? 'Sube el archivo con la firma de quien devuelve para continuar.'
                : 'Solicita la firma de quien devuelve para continuar.',
        );
    if (firmaOperadorVacia.value)
        faltan.push('Falta la firma del encargado que recibe la devolución.');
    if (!form.aceptacion)
        faltan.push(
            'Debes confirmar la aceptación antes de finalizar la devolución.',
        );
    return faltan;
});

const puedeConfirmar = computed(
    () =>
        puedeAvanzar1.value &&
        puedeAvanzar2.value &&
        !reserva.vencida.value &&
        faltantesFirma.value.length === 0 &&
        !form.processing,
);

watch(paso, (p) => {
    if (p === 3) {
        void nextTick(() => {
            padColaborador.value?.recalibrar();
            padOperador.value?.recalibrar();
        });

        // Siempre recarga al entrar al paso: la entrega de origen puede
        // haber cambiado desde la última vez (volver al paso 1 y elegir
        // otra), así que nunca debe quedar la identidad de un colaborador
        // distinto pintada por accidente.
        void bloqueIdentidad.value?.cargar();
    }
});

// ------------------------------------------------------------------
// Alerta móvil/tablet al pulsar "Continuar" con errores — mismo patrón que
// `Entregas/Crear.vue`: conserva el recuadro amarillo siempre, y en
// pantallas angostas añade un Dialog que sólo aparece al intentar avanzar
// (nunca mientras se escribe), con acceso directo de vuelta al recuadro.
// ------------------------------------------------------------------
const esMovilOTablet = useMediaQuery('(max-width: 1024px)');
const dialogoProblemasMovil = ref(false);
const resumenProblemasRef = ref<HTMLElement | null>(null);

function desplazarseAResumenProblemas(): void {
    void nextTick(() => {
        resumenProblemasRef.value?.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        });
        resumenProblemasRef.value?.focus();
    });
}

function irAlPrimerProblema(): void {
    dialogoProblemasMovil.value = false;
    desplazarseAResumenProblemas();
}

function mostrarProblemasPaso2(): void {
    if (esMovilOTablet.value) dialogoProblemasMovil.value = true;
    else desplazarseAResumenProblemas();
}

/**
 * Avanzar al paso 3 SIEMPRE refresca la reserva de custodia de forma
 * síncrona y exige `ok=true` — el usuario nunca llega a firmas con una
 * selección que otra devolución ya apartó mientras tanto.
 */
async function irA(n: 1 | 2 | 3): Promise<void> {
    if (n >= 2 && !puedeAvanzar1.value) return;
    if (n === 3) {
        if (!puedeAvanzar2.value) {
            if (puedeAvanzar1.value && problemasPaso2.value.length) {
                mostrarProblemasPaso2();
            }
            return;
        }

        const resultado = await reserva.reservar(construirPayloadReserva());
        if (!resultado || !resultado.ok) {
            mostrarProblemasPaso2();
            return;
        }
    }
    paso.value = n;
}

function irAPasoConError(): void {
    const claves = Object.keys(form.errors);
    if (
        claves.some(
            (k) =>
                k === 'firma' ||
                k.startsWith('firma_') ||
                k === 'aceptacion' ||
                k === 'negocio',
        )
    ) {
        paso.value = 3;
    } else if (
        claves.some((k) => /^(activos|unidades)\b/.test(k)) ||
        claves.includes('items')
    ) {
        paso.value = 2;
    } else {
        paso.value = 1;
    }
}

function enviar(): void {
    form.firma = padColaborador.value?.obtenerDataUrl() ?? '';
    form.firma_operador = padOperador.value?.obtenerDataUrl() ?? '';

    if (!puedeConfirmar.value) return;

    form.activos = filasCantidadIncluidas.value.map((f) => ({
        detalle_entrega_id: f.detalle_entrega_id,
        cantidad: f.cantidad,
        ...(f.dividir
            ? { condiciones: partesParaEnviar(f.reparto) }
            : { condicion: f.condicion }),
        evidencia: f.evidencia,
        evidencia_origen: f.evidencia_origen,
    }));
    form.unidades = filasUnidadIncluidas.value.map((f) => ({
        detalle_entrega_id: f.detalle_entrega_id,
        condicion: f.condicion,
        evidencia: f.evidencia,
        evidencia_origen: f.evidencia_origen,
    }));

    // La transacción de confirmación consume el apartado: ninguna limpieza
    // automática (desmontar/pagehide) debe liberarlo mientras tanto.
    reserva.iniciarConfirmacion();
    form.transform((datos) => ({
        ...datos,
        reserva_token: reserva.token.value,
    })).post('/devoluciones', {
        forceFormData: true,
        preserveScroll: true,
        onError: () => irAPasoConError(),
        onFinish: () => reserva.finalizarConfirmacion(),
    });
}
</script>

<template>
    <Head title="Nueva devolución" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Registrar devolución"
            descripcion="Registrar y firmar son un solo proceso: la devolución no queda concluida hasta que quien devuelve y quien recibe firman. Sólo los activos reutilizables reingresan al inventario del almacén destino."
        />

        <template v-if="!entrega && colaboradorContexto">
            <div class="bg-muted/40 space-y-1 rounded-lg border p-3 text-sm">
                <p class="font-medium">
                    Devoluciones pendientes para completar la transferencia de
                    {{ colaboradorContexto.nombre }}
                </p>
                <p class="text-muted-foreground text-xs">
                    Elige una entrega para procesarla. Al confirmar cada
                    devolución volverás aquí automáticamente hasta que ya no
                    quede nada pendiente.
                </p>
            </div>

            <template v-if="gruposPendientes.length">
                <div
                    v-for="grupo in gruposPendientes"
                    :key="grupo.entregaId ?? grupo.folio"
                    class="space-y-2 rounded-xl border p-4"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h3 class="text-sm font-semibold">
                            {{ grupo.folio }}
                        </h3>
                        <Button
                            v-if="grupo.entregaId !== null"
                            size="sm"
                            @click="procesarGrupo(grupo)"
                        >
                            Procesar esta entrega
                        </Button>
                    </div>
                    <ul class="text-muted-foreground space-y-0.5 text-sm">
                        <li v-for="(fila, i) in grupo.filas" :key="i">
                            <template v-if="fila.tipo === 'unidad'">
                                Unidad identificada:
                                {{ fila.activo }} · {{ fila.referencia }}
                            </template>
                            <template v-else>
                                {{ fila.activo
                                }}<span v-if="fila.talla">
                                    ({{ fila.talla }})</span
                                >
                                · pendiente {{ fila.cantidad }}
                            </template>
                        </li>
                    </ul>
                </div>
            </template>
            <p
                v-else
                class="text-muted-foreground rounded-xl border p-4 text-sm"
            >
                Ya no quedan devoluciones pendientes de
                {{ colaboradorContexto.nombre }}. Puedes completar la
                transferencia desde su perfil.
            </p>

            <Button variant="ghost" as-child class="w-fit">
                <Link :href="`/colaboradores/${colaboradorContexto.id}`"
                    >Volver al perfil del colaborador</Link
                >
            </Button>
        </template>

        <template v-else-if="!entrega">
            <div class="grid gap-1.5">
                <Label for="entrega">Entrega de origen</Label>
                <BuscadorAsync
                    id="entrega"
                    :model-value="null"
                    :buscar="buscarEntregas"
                    :etiqueta="
                        (e) =>
                            `${(e as OpcionEntrega).folio} · ${(e as OpcionEntrega).colaborador ?? ''}`
                    "
                    :descripcion="
                        (e) =>
                            `${(e as OpcionEntrega).empresa ?? ''} · ${(e as OpcionEntrega).fecha_entrega}`
                    "
                    placeholder="Busca por folio, colaborador o número de empleado"
                    placeholder-busqueda="Buscar entrega…"
                    @update:model-value="
                        (v) => elegirEntrega(v as OpcionEntrega | null)
                    "
                />
                <p class="text-muted-foreground text-xs">
                    Toda devolución se origina desde una entrega concreta.
                </p>
            </div>
            <Button variant="ghost" as-child class="w-fit">
                <Link href="/devoluciones">Cancelar</Link>
            </Button>
        </template>

        <template v-else>
            <div
                v-if="colaboradorContexto"
                class="bg-muted/40 rounded-lg border p-3 text-sm"
            >
                Parte de la transferencia pendiente de
                <strong>{{ colaboradorContexto.nombre }}</strong
                >. Al confirmar volverás a sus pendientes automáticamente.
            </div>

            <!-- Indicador de pasos -->
            <ol class="flex flex-wrap items-center gap-2 text-sm">
                <li
                    v-for="(p, idx) in PASOS"
                    :key="p.n"
                    class="flex items-center gap-2"
                >
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-full border px-3 py-1.5 transition-colors"
                        :class="
                            paso === p.n
                                ? 'border-primary bg-primary text-primary-foreground'
                                : paso > p.n
                                  ? 'border-primary/40 text-primary'
                                  : 'text-muted-foreground'
                        "
                        :aria-current="paso === p.n ? 'step' : undefined"
                        @click="irA(p.n)"
                    >
                        <span
                            class="flex size-5 items-center justify-center rounded-full border text-xs font-semibold"
                            :class="
                                paso >= p.n
                                    ? 'border-current'
                                    : 'border-muted-foreground/40'
                            "
                            >{{ p.n }}</span
                        >
                        {{ p.titulo }}
                    </button>
                    <ChevronRight
                        v-if="idx < PASOS.length - 1"
                        class="text-muted-foreground/50 size-4"
                    />
                </li>
            </ol>

            <p
                v-if="bloqueoCustodiaPropia"
                role="alert"
                class="border-destructive/40 bg-destructive/10 text-destructive rounded-md border p-3 text-sm"
            >
                {{ bloqueoCustodiaPropia }}
            </p>

            <form class="space-y-6" @submit.prevent="enviar">
                <!-- ============ PASO 1 · Datos ============ -->
                <section
                    v-show="paso === 1"
                    class="space-y-4 rounded-xl border p-4"
                >
                    <div
                        class="bg-muted/40 grid gap-3 rounded-lg border p-3 text-sm sm:grid-cols-2"
                    >
                        <p>
                            <span class="text-muted-foreground text-xs"
                                >Entrega</span
                            ><br />
                            {{ entrega.folio }}
                        </p>
                        <p>
                            <span class="text-muted-foreground text-xs"
                                >Empresa</span
                            ><br />
                            {{ entrega.empresa ?? '—' }}
                        </p>
                        <p>
                            <span class="text-muted-foreground text-xs"
                                >Colaborador</span
                            ><br />
                            {{ entrega.colaborador ?? '—' }}
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="almacen">Almacén destino</Label>
                            <BuscadorAsync
                                id="almacen"
                                :model-value="almacenSel"
                                :buscar="buscarAlmacenes"
                                :etiqueta="(a) => (a as OpcionAlmacen).nombre"
                                :descripcion="
                                    (a) => (a as OpcionAlmacen).codigo ?? ''
                                "
                                placeholder="Selecciona el almacén destino"
                                placeholder-busqueda="Buscar almacén por nombre"
                                :invalido="!!form.errors.almacen_id"
                                @update:model-value="
                                    (v) =>
                                        alElegirAlmacen(
                                            v as OpcionAlmacen | null,
                                        )
                                "
                            />
                            <InputError :message="form.errors.almacen_id" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Fecha de devolución</Label>
                            <div
                                class="bg-muted/40 text-foreground flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
                            >
                                <Calendar
                                    class="text-muted-foreground size-4 shrink-0"
                                />
                                <span class="font-medium">{{
                                    fechaNegocio(hoy)
                                }}</span>
                                <span class="text-muted-foreground text-xs"
                                    >· Asignada automáticamente</span
                                >
                            </div>
                            <InputError :message="form.errors.fecha" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="motivo">Motivo (opcional)</Label>
                            <Input id="motivo" v-model="form.motivo" />
                            <InputError :message="form.errors.motivo" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="notas">Notas (opcional)</Label>
                            <Input id="notas" v-model="form.notas" />
                            <InputError :message="form.errors.notas" />
                        </div>
                    </div>
                </section>

                <!-- ============ PASO 2 · Artículos, condición y evidencias ============ -->
                <section
                    v-show="paso === 2"
                    class="space-y-3 rounded-xl border p-4"
                >
                    <h2 class="text-sm font-semibold">
                        Renglones de la entrega
                    </h2>

                    <ApartadoTemporalBanner
                        v-if="hayAlgoIncluido"
                        :minutos-segundos="reserva.minutosSegundos.value"
                        :por-vencer="reserva.porVencer.value"
                        :vencida="reserva.vencida.value"
                        :cargando="reserva.cargando.value"
                        :error="reserva.error.value"
                        @extender="reserva.extender()"
                    />

                    <p
                        v-if="erroresLaxos['items']"
                        class="border-destructive/40 bg-destructive/10 text-destructive rounded-md border p-2 text-sm"
                    >
                        {{ erroresLaxos['items'] }}
                    </p>

                    <div
                        v-if="problemasPaso2.length"
                        ref="resumenProblemasRef"
                        tabindex="-1"
                        class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-700 outline-none dark:text-amber-400"
                    >
                        <p class="font-medium">
                            Revisa lo siguiente antes de continuar:
                        </p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5">
                            <li v-for="m in problemasPaso2" :key="m">
                                {{ m }}
                            </li>
                        </ul>
                    </div>

                    <p
                        v-if="!filasCantidad.length && !filasUnidad.length"
                        class="text-muted-foreground text-sm"
                    >
                        Esta entrega no tiene renglones pendientes de
                        devolución.
                    </p>

                    <div
                        v-for="fila in filasCantidad"
                        :key="`c-${fila.detalle_entrega_id}`"
                        class="grid grid-cols-1 items-start gap-3 rounded-lg border p-3 sm:grid-cols-[auto_1fr_110px_160px]"
                    >
                        <input
                            v-model="fila.incluir"
                            type="checkbox"
                            class="mt-2.5 size-4 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="
                                renglonDe(fila.detalle_entrega_id)
                                    ?.puede_devolver === false
                            "
                            :aria-label="`Incluir ${renglonDe(fila.detalle_entrega_id)?.activo}`"
                            :aria-describedby="
                                renglonDe(fila.detalle_entrega_id)
                                    ?.motivo_bloqueo
                                    ? `bloqueo-${fila.detalle_entrega_id}`
                                    : undefined
                            "
                        />
                        <div
                            class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2"
                        >
                            <p class="font-medium sm:col-span-2">
                                {{ renglonDe(fila.detalle_entrega_id)?.activo }}
                                <Badge
                                    v-if="renglonDe(fila.detalle_entrega_id)"
                                    :variant="
                                        varianteBadgeFinalidad(
                                            renglonDe(fila.detalle_entrega_id)
                                                ?.finalidad ?? null,
                                        )
                                    "
                                    class="ml-1 text-xs"
                                    >{{
                                        renglonDe(fila.detalle_entrega_id)
                                            ?.finalidad_etiqueta
                                    }}</Badge
                                >
                            </p>
                            <p
                                v-if="renglonDe(fila.detalle_entrega_id)?.talla"
                                class="text-muted-foreground text-xs"
                            >
                                Talla / variante:
                                <span class="text-foreground font-medium">{{
                                    renglonDe(fila.detalle_entrega_id)?.talla
                                }}</span>
                            </p>
                            <p class="text-muted-foreground text-xs">
                                Entregado originalmente:
                                <span class="text-foreground font-medium">{{
                                    renglonDe(fila.detalle_entrega_id)?.cantidad
                                }}</span>
                            </p>
                            <p class="text-muted-foreground text-xs">
                                Ya devuelto:
                                <span class="text-foreground font-medium">{{
                                    renglonDe(fila.detalle_entrega_id)
                                        ?.ya_devuelto ?? 0
                                }}</span>
                            </p>
                            <p class="text-muted-foreground text-xs">
                                Pendiente por devolver:
                                <span class="text-foreground font-medium">{{
                                    pendienteDe(fila.detalle_entrega_id)
                                }}</span>
                            </p>
                            <template
                                v-if="
                                    apartadoPorOtrosDe(
                                        fila.detalle_entrega_id,
                                    ) > 0
                                "
                            >
                                <p
                                    class="text-xs text-amber-700 dark:text-amber-400"
                                >
                                    Apartado temporalmente por otra devolución:
                                    <span class="font-medium">{{
                                        apartadoPorOtrosDe(
                                            fila.detalle_entrega_id,
                                        )
                                    }}</span>
                                </p>
                                <p
                                    class="text-xs text-amber-700 dark:text-amber-400"
                                >
                                    Disponible para devolver ahora:
                                    <span class="font-medium">{{
                                        disponibleParaDevolver(
                                            fila.detalle_entrega_id,
                                        )
                                    }}</span>
                                </p>
                            </template>
                        </div>
                        <div>
                            <Label
                                :for="`cantidad-${fila.detalle_entrega_id}`"
                                class="text-xs"
                                >Cantidad a devolver</Label
                            >
                            <Input
                                :id="`cantidad-${fila.detalle_entrega_id}`"
                                v-model.number="fila.cantidad"
                                type="number"
                                min="1"
                                :max="pendienteDe(fila.detalle_entrega_id)"
                                class="h-9"
                                :disabled="!fila.incluir"
                                :aria-invalid="
                                    fila.incluir &&
                                    fila.cantidad >
                                        disponibleParaDevolver(
                                            fila.detalle_entrega_id,
                                        )
                                "
                            />
                            <InputError
                                :message="
                                    erroresLaxos[
                                        `activos.${filasCantidadIncluidas.indexOf(fila)}.cantidad`
                                    ]
                                "
                            />
                        </div>
                        <div>
                            <template v-if="!fila.dividir">
                                <Label class="text-xs"
                                    >Condición al recibir</Label
                                >
                                <SelectSimple
                                    v-model="fila.condicion"
                                    :opciones="
                                        condiciones.map((c) => ({
                                            valor: c.valor,
                                            etiqueta: c.etiqueta,
                                        }))
                                    "
                                    :disabled="!fila.incluir"
                                />
                            </template>
                            <p
                                v-else
                                class="text-muted-foreground pt-5 text-xs"
                            >
                                Repartida por condición (abajo).
                            </p>
                            <Button
                                v-if="fila.incluir"
                                type="button"
                                variant="outline"
                                size="sm"
                                class="mt-2 h-8 w-full gap-1.5 text-xs sm:w-auto"
                                :aria-expanded="fila.dividir"
                                :aria-controls="`reparto-${fila.detalle_entrega_id}`"
                                @click="alternarDivision(fila)"
                            >
                                <Combine
                                    v-if="fila.dividir"
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                <Split
                                    v-else
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{
                                    fila.dividir
                                        ? 'Usar una sola condición'
                                        : 'Dividir por condición'
                                }}
                            </Button>
                        </div>
                        <fieldset
                            v-if="fila.incluir && fila.dividir"
                            :id="`reparto-${fila.detalle_entrega_id}`"
                            class="rounded-md border p-3 sm:col-span-full"
                        >
                            <legend class="px-1 text-xs font-medium">
                                Cantidad por condición
                            </legend>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div
                                    v-for="c in condiciones"
                                    :key="c.valor"
                                    class="space-y-1"
                                >
                                    <Label
                                        :for="`reparto-${fila.detalle_entrega_id}-${c.valor}`"
                                        class="text-xs"
                                        >{{ c.etiqueta }}</Label
                                    >
                                    <Input
                                        :id="`reparto-${fila.detalle_entrega_id}-${c.valor}`"
                                        v-model.number="fila.reparto[c.valor]"
                                        type="number"
                                        min="0"
                                        step="1"
                                        inputmode="numeric"
                                        class="h-10 sm:h-9"
                                        :aria-invalid="
                                            !!estadoRepartoDe(fila).mensaje
                                        "
                                    />
                                </div>
                            </div>
                            <p
                                class="mt-2 text-xs"
                                :class="
                                    estadoRepartoDe(fila).mensaje
                                        ? 'text-destructive'
                                        : 'text-muted-foreground'
                                "
                                aria-live="polite"
                            >
                                Total asignado:
                                <span class="font-medium tabular-nums"
                                    >{{ estadoRepartoDe(fila).asignado }} de
                                    {{ fila.cantidad || 0 }}</span
                                >
                                <template v-if="estadoRepartoDe(fila).mensaje">
                                    · {{ estadoRepartoDe(fila).mensaje }}
                                </template>
                            </p>
                            <InputError
                                :message="
                                    erroresLaxos[
                                        `activos.${filasCantidadIncluidas.indexOf(fila)}.condiciones`
                                    ]
                                "
                            />
                        </fieldset>
                        <p
                            v-if="
                                renglonDe(fila.detalle_entrega_id)
                                    ?.motivo_bloqueo
                            "
                            :id="`bloqueo-${fila.detalle_entrega_id}`"
                            class="text-destructive text-xs sm:col-span-full"
                        >
                            {{
                                renglonDe(fila.detalle_entrega_id)
                                    ?.motivo_bloqueo
                            }}
                        </p>
                        <div v-if="fila.incluir" class="sm:col-span-full">
                            <CapturaEvidencia
                                v-model="fila.evidencia"
                                v-model:origen="fila.evidencia_origen"
                                etiqueta="Agregar foto de la condición"
                            />
                        </div>
                    </div>

                    <div
                        v-for="fila in filasUnidad"
                        :key="`u-${fila.detalle_entrega_id}`"
                        class="grid grid-cols-1 items-start gap-2 rounded-lg border p-3 sm:grid-cols-[auto_1fr_160px]"
                    >
                        <input
                            v-model="fila.incluir"
                            type="checkbox"
                            class="mt-2.5 size-4 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="
                                renglonDe(fila.detalle_entrega_id)
                                    ?.puede_devolver === false
                            "
                            :aria-label="`Incluir ${renglonDe(fila.detalle_entrega_id)?.activo}`"
                            :aria-describedby="
                                renglonDe(fila.detalle_entrega_id)
                                    ?.motivo_bloqueo
                                    ? `bloqueo-${fila.detalle_entrega_id}`
                                    : undefined
                            "
                        />
                        <div class="text-sm">
                            <p class="font-medium">
                                {{ renglonDe(fila.detalle_entrega_id)?.activo }}
                                <Badge
                                    v-if="renglonDe(fila.detalle_entrega_id)"
                                    :variant="
                                        varianteBadgeFinalidad(
                                            renglonDe(fila.detalle_entrega_id)
                                                ?.finalidad ?? null,
                                        )
                                    "
                                    class="ml-1 text-xs"
                                    >{{
                                        renglonDe(fila.detalle_entrega_id)
                                            ?.finalidad_etiqueta
                                    }}</Badge
                                >
                            </p>
                            <p class="text-muted-foreground font-mono text-xs">
                                {{
                                    renglonDe(fila.detalle_entrega_id)
                                        ?.unidad_codigo
                                }}
                            </p>
                            <p
                                v-if="
                                    !reserva.resultado.value?.lineas_unidad.find(
                                        (l) =>
                                            l.detalle_entrega_id ===
                                            fila.detalle_entrega_id,
                                    )?.ok &&
                                    reserva.resultado.value?.lineas_unidad.find(
                                        (l) =>
                                            l.detalle_entrega_id ===
                                            fila.detalle_entrega_id,
                                    )
                                "
                                class="text-destructive text-xs"
                            >
                                {{
                                    reserva.resultado.value?.lineas_unidad.find(
                                        (l) =>
                                            l.detalle_entrega_id ===
                                            fila.detalle_entrega_id,
                                    )?.motivo
                                }}
                            </p>
                        </div>
                        <div>
                            <Label class="text-xs">Condición al recibir</Label>
                            <SelectSimple
                                v-model="fila.condicion"
                                :opciones="
                                    condicionesUnidad.map((c) => ({
                                        valor: c.valor,
                                        etiqueta: c.etiqueta,
                                    }))
                                "
                                :disabled="!fila.incluir"
                            />
                        </div>
                        <p
                            v-if="
                                renglonDe(fila.detalle_entrega_id)
                                    ?.motivo_bloqueo
                            "
                            :id="`bloqueo-${fila.detalle_entrega_id}`"
                            class="text-destructive text-xs sm:col-span-full"
                        >
                            {{
                                renglonDe(fila.detalle_entrega_id)
                                    ?.motivo_bloqueo
                            }}
                        </p>
                        <div v-if="fila.incluir" class="sm:col-span-full">
                            <CapturaEvidencia
                                v-model="fila.evidencia"
                                v-model:origen="fila.evidencia_origen"
                                etiqueta="Agregar foto de la condición"
                            />
                        </div>
                    </div>

                    <p
                        v-for="r in entrega.renglones.filter(
                            (r) => r.es_unidad && !r.unidad_disponible,
                        )"
                        :key="`nd-${r.detalle_entrega_id}`"
                        class="text-muted-foreground text-xs"
                    >
                        {{ r.activo }} ({{ r.unidad_codigo }}) ya no está
                        asignada a este colaborador — no se puede devolver desde
                        aquí.
                        <span v-if="r.unidad_estado_visible_etiqueta">
                            Estado actual:
                            {{ r.unidad_estado_visible_etiqueta }}.
                        </span>
                    </p>
                </section>

                <!-- ============ PASO 3 · Revisión y firmas ============ -->
                <div v-show="paso === 3" class="space-y-6">
                    <ApartadoTemporalBanner
                        :minutos-segundos="reserva.minutosSegundos.value"
                        :por-vencer="reserva.porVencer.value"
                        :vencida="reserva.vencida.value"
                        :cargando="reserva.cargando.value"
                        :error="reserva.error.value"
                        @extender="reserva.extender()"
                    />

                    <p
                        v-if="erroresLaxos['negocio']"
                        class="border-destructive/40 bg-destructive/10 text-destructive rounded-md border p-3 text-sm font-medium"
                    >
                        {{ erroresLaxos['negocio'] }}
                    </p>

                    <section class="rounded-xl border p-4">
                        <h2 class="mb-3 text-sm font-semibold">
                            Revisión de la devolución
                        </h2>
                        <dl
                            class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3"
                        >
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Colaborador
                                </dt>
                                <dd>{{ entrega.colaborador ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Empresa
                                </dt>
                                <dd>{{ entrega.empresa ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Almacén destino
                                </dt>
                                <dd>{{ almacenSel?.nombre ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Fecha
                                </dt>
                                <dd>{{ fechaNegocio(form.fecha) }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Renglones a devolver
                                </dt>
                                <dd>
                                    {{
                                        filasCantidadIncluidas.length +
                                        filasUnidadIncluidas.length
                                    }}
                                </dd>
                            </div>
                        </dl>

                        <table class="mt-3 w-full text-sm">
                            <thead class="text-muted-foreground text-left">
                                <tr>
                                    <th class="py-1.5">Activo</th>
                                    <th class="py-1.5">Talla / unidad</th>
                                    <th class="py-1.5">Condición</th>
                                    <th class="py-1.5 text-right">Cantidad</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="f in filasCantidadIncluidas"
                                    :key="`rc-${f.detalle_entrega_id}`"
                                    class="border-t"
                                >
                                    <td class="py-1.5">
                                        {{
                                            renglonDe(f.detalle_entrega_id)
                                                ?.activo
                                        }}
                                    </td>
                                    <td class="py-1.5">
                                        {{
                                            renglonDe(f.detalle_entrega_id)
                                                ?.talla ?? '—'
                                        }}
                                    </td>
                                    <td class="py-1.5">
                                        {{
                                            f.dividir
                                                ? textoReparto(f)
                                                : (condiciones.find(
                                                      (c) =>
                                                          c.valor ===
                                                          f.condicion,
                                                  )?.etiqueta ?? f.condicion)
                                        }}
                                    </td>
                                    <td class="py-1.5 text-right">
                                        {{ f.cantidad }}
                                    </td>
                                </tr>
                                <tr
                                    v-for="f in filasUnidadIncluidas"
                                    :key="`ru-${f.detalle_entrega_id}`"
                                    class="border-t"
                                >
                                    <td class="py-1.5">
                                        {{
                                            renglonDe(f.detalle_entrega_id)
                                                ?.activo
                                        }}
                                    </td>
                                    <td class="py-1.5 font-mono text-xs">
                                        {{
                                            renglonDe(f.detalle_entrega_id)
                                                ?.unidad_codigo
                                        }}
                                    </td>
                                    <td class="py-1.5">
                                        {{
                                            condicionesUnidad.find(
                                                (c) => c.valor === f.condicion,
                                            )?.etiqueta ?? f.condicion
                                        }}
                                    </td>
                                    <td class="py-1.5 text-right">1</td>
                                </tr>
                            </tbody>
                        </table>
                    </section>

                    <DocumentoIdentidadColaborador
                        ref="bloqueIdentidad"
                        :url-metadata="`/devoluciones/documento-identidad/${entrega.id}`"
                        :url-ver="`/devoluciones/documento-identidad/${entrega.id}/ver`"
                        :url-guardar="`/devoluciones/documento-identidad/${entrega.id}`"
                        nota-captura="Se guardará en el expediente del colaborador (carpeta Identificación) y quedará disponible para ésta y futuras operaciones. La devolución no se bloquea si decides continuar sin ella."
                    />

                    <section class="space-y-3 rounded-xl border p-4">
                        <h2 class="text-sm font-semibold">
                            Firma de quien devuelve
                        </h2>
                        <p class="text-muted-foreground text-sm">
                            Firma del colaborador que devuelve ({{
                                entrega.colaborador
                            }}) los activos descritos. La verificación de
                            identidad y firma es responsabilidad de quien
                            procesa la devolución.
                        </p>
                        <FirmaColaborador
                            ref="padColaborador"
                            v-model:metodo="form.firma_metodo"
                            v-model:archivo="form.firma_archivo"
                            quien="quien devuelve"
                            :error="form.errors.firma_archivo"
                            @cambio="
                                (v: boolean) => (firmaColaboradorVacia = v)
                            "
                        />
                        <InputError :message="form.errors.firma" />
                        <InputError :message="form.errors.firma_metodo" />
                    </section>

                    <section class="space-y-3 rounded-xl border p-4">
                        <h2 class="text-sm font-semibold">
                            Firma de quien recibe
                        </h2>
                        <label
                            class="bg-muted/40 flex items-start gap-2 rounded-lg border p-3 text-sm"
                        >
                            <input
                                v-model="form.aceptacion"
                                type="checkbox"
                                class="mt-0.5 size-4 shrink-0"
                            />
                            <span>{{ textoConsentimiento }}</span>
                        </label>
                        <PadFirma
                            ref="padOperador"
                            @cambio="(v: boolean) => (firmaOperadorVacia = v)"
                        />
                        <InputError :message="form.errors.firma_operador" />
                        <InputError :message="form.errors.aceptacion" />
                    </section>

                    <ul
                        v-if="faltantesFirma.length"
                        class="text-muted-foreground space-y-1 text-sm"
                    >
                        <li
                            v-for="msg in faltantesFirma"
                            :key="msg"
                            class="flex items-center gap-1.5"
                        >
                            <span
                                class="bg-muted-foreground/50 inline-block size-1.5 rounded-full"
                            />
                            {{ msg }}
                        </li>
                    </ul>
                </div>

                <!-- ============ Navegación ============ -->
                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        v-if="paso > 1"
                        type="button"
                        variant="outline"
                        @click="irA((paso - 1) as 1 | 2 | 3)"
                    >
                        <ChevronLeft class="size-4" /> Atrás
                    </Button>

                    <Button
                        v-if="paso === 1"
                        type="button"
                        :disabled="!puedeAvanzar1"
                        @click="irA(2)"
                    >
                        Siguiente <ChevronRight class="size-4" />
                    </Button>
                    <Button
                        v-else-if="paso === 2"
                        type="button"
                        :disabled="!puedeAvanzar2"
                        @click="irA(3)"
                    >
                        Continuar a revisión y firmas
                        <ChevronRight class="size-4" />
                    </Button>
                    <Button
                        v-else
                        type="submit"
                        :disabled="!puedeConfirmar"
                        :class="
                            puedeConfirmar &&
                            'shadow-success/30 shadow-lg transition-shadow duration-300'
                        "
                    >
                        Confirmar devolución
                    </Button>

                    <Button variant="ghost" as-child>
                        <Link :href="hrefCancelar" @click="reserva.liberar()"
                            >Cancelar</Link
                        >
                    </Button>
                </div>
            </form>
        </template>

        <AlertaProblemasMovil
            v-model:open="dialogoProblemasMovil"
            :problemas="problemasPaso2"
            @ir-al-problema="irAlPrimerProblema"
        />
    </div>
</template>
