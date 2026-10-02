/**
 * Mensajes de "no alcanza" para Entregas, Traspasos y Devoluciones con
 * apartado temporal. Siempre con los números que devolvió el backend
 * (solicitado real + disponible actual), en singular/plural correcto, y
 * diciendo POR QUÉ cuando la causa es concurrencia (otra operación apartó
 * existencias mientras el usuario capturaba).
 */
export type OperacionDisponibilidad = 'entrega' | 'traspaso' | 'devolucion';

const NOMBRE_OPERACION: Record<OperacionDisponibilidad, string> = {
    entrega: 'la entrega',
    traspaso: 'el traspaso',
    devolucion: 'la devolución',
};

export function textoPiezas(n: number): string {
    return n === 1 ? '1 pieza' : `${n} piezas`;
}

/** "1 disponible" / "2 disponibles" (y "… para devolver" en devoluciones). */
export function textoDisponibles(
    n: number,
    operacion: OperacionDisponibilidad = 'entrega',
): string {
    const base = n === 1 ? '1 disponible' : `${n} disponibles`;
    return operacion === 'devolucion' ? `${base} para devolver` : base;
}

export interface DatosMensajeDisponibilidad {
    operacion: OperacionDisponibilidad;
    nombre: string;
    variante?: string | null;
    solicitado: number;
    disponible: number;
    /** La diferencia la causa otra operación que apartó (o usó) existencias. */
    porOtros: boolean;
    /**
     * Sólo cuando la cifra solicitada NO sale de un único renglón (si no,
     * el detalle sobra y confunde).
     */
    origen?: 'mixto' | 'conjuntos' | 'varios-renglones';
}

const TEXTO_ORIGEN = {
    mixto: ' (sumando artículos sueltos y conjuntos)',
    conjuntos: ' (en los conjuntos elegidos)',
    'varios-renglones': ' (sumando varios renglones)',
} as const;

export function mensajeDisponibilidadInsuficiente(
    d: DatosMensajeDisponibilidad,
): string {
    const variante = d.variante ? ` talla ${d.variante}` : '';
    const origen = d.origen ? TEXTO_ORIGEN[d.origen] : '';
    const verbo =
        d.operacion === 'devolucion' ? 'Solicitaste devolver' : 'Solicitaste';
    const pedido = `${verbo} ${textoPiezas(d.solicitado)} de «${d.nombre}»${variante}${origen}`;
    const disponible = Math.max(0, d.disponible);

    if (!d.porOtros) {
        if (disponible === 0) {
            return d.operacion === 'devolucion'
                ? `${pedido}, pero ya no queda nada por devolver.`
                : `${pedido}, pero no hay existencias disponibles.`;
        }
        return `${pedido}, pero sólo ${disponible === 1 ? 'queda' : 'quedan'} ${textoDisponibles(disponible, d.operacion)}.`;
    }

    const aviso = `La disponibilidad cambió mientras realizabas ${NOMBRE_OPERACION[d.operacion]}.`;
    const causa =
        d.operacion === 'devolucion'
            ? 'porque otra devolución apartó esas piezas'
            : 'porque otras operaciones apartaron existencias';

    if (disponible === 0) {
        return d.operacion === 'devolucion'
            ? `${aviso} ${pedido}, pero actualmente ya no queda nada disponible para devolver ${causa}.`
            : `${aviso} ${pedido}, pero actualmente ya no quedan existencias disponibles.`;
    }

    return `${aviso} ${pedido}, pero actualmente sólo ${disponible === 1 ? 'queda' : 'quedan'} ${textoDisponibles(disponible, d.operacion)} ${causa}.`;
}
