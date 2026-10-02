import { textoPiezas } from '@/lib/mensajesDisponibilidad';

/**
 * "Dividir por condición" en una devolución: el MISMO renglón por cantidad
 * repartido entre varias condiciones (p. ej. 6 reutilizables, 1 dañada, 1
 * baja) dentro de una sola devolución. La suma debe ser EXACTA; nunca se
 * ajusta nada en silencio: si no cuadra, se explica y no se deja avanzar.
 * El backend (`GuardarDevolucionRequest` + `RegistrarDevolucion`) vuelve a
 * validarlo todo.
 */
export type Reparto = Record<string, number | ''>;

export interface EstadoReparto {
    asignado: number;
    mensaje: string | null;
}

function valorDe(v: number | '' | undefined): number {
    return v === '' || v === undefined ? 0 : v;
}

export function estadoReparto(total: number, reparto: Reparto): EstadoReparto {
    const valores = Object.values(reparto).map(valorDe);
    const asignado = valores.reduce((s, n) => s + n, 0);

    if (valores.some((n) => !Number.isInteger(n) || n < 0)) {
        return {
            asignado,
            mensaje:
                'Las cantidades por condición deben ser números enteros de 0 o más.',
        };
    }
    if (asignado < total) {
        return {
            asignado,
            mensaje: `Falta asignar condición a ${textoPiezas(total - asignado)}.`,
        };
    }
    if (asignado > total) {
        return {
            asignado,
            mensaje: `La distribución por condición supera la cantidad a devolver por ${textoPiezas(asignado - total)}.`,
        };
    }

    return { asignado, mensaje: null };
}

/** Al empezar a dividir: todo en la condición que ya estaba elegida. */
export function repartoInicial(
    condiciones: string[],
    condicionActual: string,
    cantidad: number,
): Reparto {
    return Object.fromEntries(
        condiciones.map((c) => [c, c === condicionActual ? cantidad : 0]),
    );
}

/** Lo que viaja al backend: sólo las condiciones con piezas. */
export function partesParaEnviar(
    reparto: Reparto,
): { condicion: string; cantidad: number }[] {
    return Object.entries(reparto)
        .map(([condicion, v]) => ({ condicion, cantidad: valorDe(v) }))
        .filter((p) => p.cantidad > 0);
}
