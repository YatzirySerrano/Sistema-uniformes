/**
 * Regex de CURP/RFC EXACTAMENTE iguales a las del backend (fuente única de
 * verdad ahí: `App\Http\Requests\Colaboradores\GuardarColaboradorRequest::REGEX_CURP`
 * y `App\Http\Requests\Empresas\GuardarEmpresaRequest::REGEX_RFC`) — el
 * frontend nunca debe decir "válido" y que el backend luego lo rechace.
 * Si cambia alguna regla ahí, hay que replicarla aquí a mano (no hay forma
 * de compartir el patrón entre PHP y TypeScript sin una build extra).
 */
export const REGEX_CURP =
    /^[A-Z][AEIOU][A-Z]{2}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[HM](AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QO|QR|SL|SP|SR|TC|TL|TS|VZ|YN|ZS|NE)[B-DF-HJ-NP-TV-Z]{3}[A-Z0-9]\d$/;

export const REGEX_RFC = /^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/;

/** CURP: siempre 18 caracteres exactos. */
export function formatoCurpValido(valorMayusculas: string): boolean {
    return valorMayusculas.length === 18 && REGEX_CURP.test(valorMayusculas);
}

/** RFC: 12 (persona moral) o 13 (persona física) caracteres. */
export function formatoRfcValido(valorMayusculas: string): boolean {
    return (
        valorMayusculas.length >= 12 &&
        valorMayusculas.length <= 13 &&
        REGEX_RFC.test(valorMayusculas)
    );
}
