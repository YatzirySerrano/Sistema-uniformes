/**
 * Espejo en cliente de `App\Enums\PerfilTecnicoUnidad`. La fuente de verdad es
 * SIEMPRE el backend (por el `codigo` de la categoría / tipo, nunca por el
 * nombre); esto sólo sirve para que los formularios muestren los campos
 * correctos antes de enviar.
 */
export type PerfilTecnico = 'celular' | 'computadora' | 'tablet';

export type CampoEspecificacion =
    | 'marca'
    | 'modelo'
    | 'imei'
    | 'numero_telefonico'
    | 'operador'
    | 'plan';

const PERFILES: readonly PerfilTecnico[] = ['celular', 'computadora', 'tablet'];

const CAMPOS_VISIBLES: Record<PerfilTecnico, CampoEspecificacion[]> = {
    celular: [
        'marca',
        'modelo',
        'imei',
        'numero_telefonico',
        'operador',
        'plan',
    ],
    computadora: ['marca', 'modelo'],
    tablet: ['marca', 'modelo'],
};

const CAMPOS_REQUERIDOS: Record<PerfilTecnico, CampoEspecificacion[]> = {
    celular: ['marca', 'modelo', 'imei'],
    computadora: ['marca', 'modelo'],
    tablet: ['marca', 'modelo'],
};

export const ETIQUETA_CAMPO: Record<CampoEspecificacion, string> = {
    marca: 'Marca',
    modelo: 'Modelo',
    imei: 'IMEI',
    numero_telefonico: 'Número telefónico',
    operador: 'Operador',
    plan: 'Plan',
};

export const ETIQUETA_PERFIL: Record<PerfilTecnico, string> = {
    celular: 'Celular',
    computadora: 'Computadora',
    tablet: 'Tablet',
};

/**
 * Coacciona el valor `perfil_tecnico` que llega del backend (relación 1:1 de
 * la categoría) a un `PerfilTecnico` válido, o null.
 */
export function aPerfilTecnico(
    valor: string | null | undefined,
): PerfilTecnico | null {
    return valor && (PERFILES as readonly string[]).includes(valor)
        ? (valor as PerfilTecnico)
        : null;
}

export function camposVisibles(perfil: PerfilTecnico): CampoEspecificacion[] {
    return CAMPOS_VISIBLES[perfil];
}

export function camposRequeridos(perfil: PerfilTecnico): CampoEspecificacion[] {
    return CAMPOS_REQUERIDOS[perfil];
}

/** Fila del formulario por unidad: todos los campos presentes como string. */
export type EspecificacionUnidad = Record<CampoEspecificacion, string>;

/** Fila vacía de especificación para inicializar el formulario por unidad. */
export function especificacionVacia(): EspecificacionUnidad {
    return {
        marca: '',
        modelo: '',
        imei: '',
        numero_telefonico: '',
        operador: '',
        plan: '',
    };
}
