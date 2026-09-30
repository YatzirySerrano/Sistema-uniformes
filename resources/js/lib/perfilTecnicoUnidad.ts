/**
 * Espejo en cliente de `App\Enums\PerfilTecnicoUnidad` y
 * `App\Soporte\EspecificacionUnidad`. La fuente de verdad es SIEMPRE el
 * backend (perfil = relación 1:1 de la categoría, nunca el nombre); esto sólo
 * sirve para que los formularios muestren los campos correctos antes de
 * enviar.
 */
export type PerfilTecnico =
    | 'celular'
    | 'computadora'
    | 'tablet'
    | 'transporte'
    | 'electrodomestico';

export type CampoEspecificacion =
    | 'marca'
    | 'modelo'
    | 'imei'
    | 'numero_telefonico'
    | 'operador'
    | 'plan'
    | 'clase_vehiculo'
    | 'anio'
    | 'color'
    | 'placas'
    | 'numero_serie';

const PERFILES: readonly PerfilTecnico[] = [
    'celular',
    'computadora',
    'tablet',
    'transporte',
    'electrodomestico',
];

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
    transporte: [
        'clase_vehiculo',
        'marca',
        'modelo',
        'anio',
        'color',
        'placas',
        'numero_serie',
    ],
    electrodomestico: ['marca', 'modelo', 'color', 'numero_serie'],
};

const CAMPOS_REQUERIDOS: Record<PerfilTecnico, CampoEspecificacion[]> = {
    celular: ['marca', 'modelo', 'imei'],
    computadora: ['marca', 'modelo'],
    tablet: ['marca', 'modelo'],
    transporte: ['clase_vehiculo', 'marca', 'modelo', 'anio'],
    electrodomestico: ['marca'],
};

/** "Al menos uno de" por perfil (Transporte: placas o NIV / serie). */
const IDENTIFICADORES_ALTERNATIVOS: Record<
    PerfilTecnico,
    CampoEspecificacion[][]
> = {
    celular: [],
    computadora: [],
    tablet: [],
    transporte: [['placas', 'numero_serie']],
    electrodomestico: [],
};

export const ETIQUETA_CAMPO: Record<CampoEspecificacion, string> = {
    marca: 'Marca',
    modelo: 'Modelo',
    imei: 'IMEI',
    numero_telefonico: 'Número telefónico',
    operador: 'Operador',
    plan: 'Plan',
    clase_vehiculo: 'Tipo de vehículo',
    anio: 'Año',
    color: 'Color',
    placas: 'Placas',
    numero_serie: 'NIV / VIN / número de serie',
};

export const ETIQUETA_PERFIL: Record<PerfilTecnico, string> = {
    celular: 'Celular',
    computadora: 'Computadora',
    tablet: 'Tablet',
    transporte: 'Transporte',
    electrodomestico: 'Electrodoméstico',
};

export const OPCIONES_CLASE_VEHICULO = [
    { valor: 'automovil', etiqueta: 'Automóvil' },
    { valor: 'camioneta', etiqueta: 'Camioneta' },
    { valor: 'motocicleta', etiqueta: 'Motocicleta' },
    { valor: 'otro', etiqueta: 'Otro' },
];

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

/**
 * Espejo de `PerfilTecnicoUnidad::exigeSeguimientoIndividual()`: todo perfil
 * técnico describe un bien físico concreto. El backend valida la misma regla.
 */
export function exigeSeguimientoIndividual(
    perfil: PerfilTecnico | null,
): boolean {
    return perfil !== null;
}

export function camposVisibles(perfil: PerfilTecnico): CampoEspecificacion[] {
    return CAMPOS_VISIBLES[perfil];
}

export function camposRequeridos(perfil: PerfilTecnico): CampoEspecificacion[] {
    return CAMPOS_REQUERIDOS[perfil];
}

/** Texto de ayuda para los "al menos uno de" del perfil, o null. */
export function ayudaIdentificadores(perfil: PerfilTecnico): string | null {
    const grupos = IDENTIFICADORES_ALTERNATIVOS[perfil];
    if (!grupos.length) return null;
    return grupos
        .map(
            (g) =>
                `Captura al menos uno: ${g.map((c) => ETIQUETA_CAMPO[c]).join(' o ')}.`,
        )
        .join(' ');
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
        clase_vehiculo: '',
        anio: '',
        color: '',
        placas: '',
        numero_serie: '',
    };
}
