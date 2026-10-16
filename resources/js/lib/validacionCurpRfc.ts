/**
 * CURP: prevalidación LIGERA, sólo para UX mientras el usuario escribe
 * (longitud, caracteres y estructura: vocal o X, fecha, sexo, consonantes). El
 * catálogo de claves de entidad NO se copia aquí a propósito — una copia
 * rígida ya divergió una vez (Querétaro `QT`). La autoridad definitiva es
 * `App\Http\Requests\Colaboradores\GuardarColaboradorRequest::REGEX_CURP`,
 * consultada vía `POST /colaboradores/validar-curp` (responde
 * `disponible: null` si el formato no es válido).
 *
 * RFC: misma regla que `App\Http\Requests\Empresas\GuardarEmpresaRequest::REGEX_RFC`.
 */
export const REGEX_CURP_ESTRUCTURA =
    /^[A-Z][AEIOUX][A-Z]{2}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[HM][A-Z]{2}[B-DF-HJ-NP-TV-Z]{3}[A-Z0-9]\d$/;

export const REGEX_RFC = /^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/;

/** CURP: siempre 18 caracteres exactos con estructura de CURP. */
export function formatoCurpValido(valorMayusculas: string): boolean {
    return (
        valorMayusculas.length === 18 &&
        REGEX_CURP_ESTRUCTURA.test(valorMayusculas)
    );
}

/** RFC: 12 (persona moral) o 13 (persona física) caracteres. */
export function formatoRfcValido(valorMayusculas: string): boolean {
    return (
        valorMayusculas.length >= 12 &&
        valorMayusculas.length <= 13 &&
        REGEX_RFC.test(valorMayusculas)
    );
}
