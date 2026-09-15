import { tourDashboard } from './dashboard';
import { tourFirmaDevolucion, tourFirmaEntrega } from './firma';
import { tourGenerico } from './generico';
import { tourInventarioFisicoDetalle } from './inventarioFisico';
import type { Tour } from './tipos';

type EntradaRegistro = { patron: RegExp; tours: Tour[] };

const REGISTRO: EntradaRegistro[] = [
    { patron: /^\/dashboard\/?$/, tours: [tourDashboard] },
    {
        patron: /^\/empresas\/?$/,
        tours: [
            tourGenerico(
                'empresas',
                'Empresas',
                '/empresas',
                'Cada empresa es una razón social independiente, con su propia marca y colaboradores.',
                true,
            ),
        ],
    },
    {
        patron: /^\/sucursales\/?$/,
        tours: [
            tourGenerico(
                'sucursales',
                'Sucursales',
                '/sucursales',
                'Las sucursales son el destino/contexto del colaborador, no una dimensión de inventario.',
                true,
            ),
        ],
    },
    {
        patron: /^\/colaboradores\/?$/,
        tours: [
            tourGenerico(
                'colaboradores',
                'Colaboradores',
                '/colaboradores',
                'Aquí registras al personal y accedes a su expediente digital y su historial de entregas/devoluciones.',
                true,
            ),
        ],
    },
    {
        patron: /^\/areas\/?$/,
        tours: [
            tourGenerico(
                'areas',
                'Áreas / Departamentos',
                '/areas',
                'Organiza a tus colaboradores por área o departamento dentro de cada empresa.',
                true,
            ),
        ],
    },
    {
        patron: /^\/activos\/?$/,
        tours: [
            tourGenerico(
                'activos',
                'Activos',
                '/activos',
                'El catálogo de todo lo que la empresa puede entregar: uniformes, equipo de cómputo, herramientas… "Existencias globales" y "Registrar ingreso de stock" viven aquí arriba; los mínimos y las existencias puntuales se configuran en el detalle de cada activo.',
                true,
            ),
        ],
    },
    {
        patron: /^\/almacenes\/?$/,
        tours: [
            tourGenerico(
                'almacenes',
                'Almacenes',
                '/almacenes',
                'Un almacén puede abastecer a varias empresas a la vez; aquí sólo administras datos y estado, no el inventario.',
                true,
            ),
        ],
    },
    {
        patron: /^\/activos\/unidades\/?$/,
        tours: [
            tourGenerico(
                'unidades',
                'Unidades',
                '/activos/unidades',
                'Activos con seguimiento individual (equipo de cómputo, herramientas caras…), cada uno con su propio código y QR.',
                true,
            ),
        ],
    },
    {
        patron: /^\/conjuntos\/?$/,
        tours: [
            tourGenerico(
                'conjuntos',
                'Conjuntos',
                '/conjuntos',
                'Agrupa varios activos como una sola plantilla de entrega (ej. "Uniforme completo"): su disponibilidad se calcula en vivo, sin stock propio.',
                true,
            ),
        ],
    },
    {
        patron: /^\/inventario\/traspasos\/?$/,
        tours: [
            tourGenerico(
                'traspasos',
                'Traspasos de inventario',
                '/inventario/traspasos',
                'Consulta y registra transferencias de inventario entre almacenes. El historial técnico completo (entradas, ajustes…) sigue disponible en Movimientos.',
                true,
            ),
        ],
    },
    {
        patron: /^\/inventarios-fisicos\/?$/,
        tours: [
            tourGenerico(
                'inventarios-fisicos',
                'Inventario físico',
                '/inventarios-fisicos',
                'Rondas de conteo por escaneo de QR: eliges empresa/almacén al crear la ronda y comparas lo encontrado contra lo esperado. No mueve stock ni cambia asignaciones.',
                true,
            ),
        ],
    },
    {
        patron: /^\/inventarios-fisicos\/\d+\/?$/,
        tours: [tourInventarioFisicoDetalle],
    },
    {
        patron: /^\/contratos\/?$/,
        tours: [
            tourGenerico(
                'contratos',
                'Contratos',
                '/contratos',
                'Un contrato pertenece a una empresa y puede tener varios Servicios operativos derivados de él, cada uno con su propia vigencia y estado.',
                true,
            ),
        ],
    },
    {
        patron: /^\/servicios\/?$/,
        tours: [
            tourGenerico(
                'servicios',
                'Servicios',
                '/servicios',
                'Un Servicio pertenece siempre a un Contrato (la empresa se hereda de ahí) y tiene una sucursal asociada; los colaboradores se asignan operativamente a él, así que cambiar sus asignaciones afecta el trabajo diario, no sólo un dato administrativo.',
                true,
            ),
        ],
    },
    {
        patron: /^\/activos-catalogos\/?$/,
        tours: [
            tourGenerico(
                'catalogos',
                'Tipos y categorías',
                '/activos-catalogos',
                'Catálogos globales de la plataforma: los usan todas las empresas por igual.',
            ),
        ],
    },
    {
        patron: /^\/tallas\/?$/,
        tours: [
            tourGenerico(
                'tallas',
                'Variantes / tallas',
                '/tallas',
                'Catálogo global de variantes (tallas, medidas…). Habilítalas por empresa según lo que cada una use.',
            ),
        ],
    },
    {
        patron: /^\/entregas\/?$/,
        tours: [
            tourGenerico(
                'entregas',
                'Entregas',
                '/entregas',
                'Registra qué activos recibe cada colaborador. No queda concretada hasta que ambas partes firman.',
                true,
            ),
        ],
    },
    {
        patron: /^\/entregas\/\d+\/firmar\/?$/,
        tours: [tourFirmaEntrega],
    },
    {
        patron: /^\/devoluciones\/?$/,
        tours: [
            tourGenerico(
                'devoluciones',
                'Devoluciones',
                '/devoluciones',
                'Registra qué activos regresa un colaborador. El inventario se actualiza hasta que ambas partes firman.',
                true,
            ),
        ],
    },
    {
        patron: /^\/devoluciones\/\d+\/firmar\/?$/,
        tours: [tourFirmaDevolucion],
    },
    {
        patron: /^\/reportes\/?$/,
        tours: [
            tourGenerico(
                'reportes',
                'Reportes',
                '/reportes',
                'Genera reportes de entregas, inventario y más, en Excel o PDF.',
            ),
        ],
    },
    {
        patron: /^\/auditoria\/?$/,
        tours: [
            tourGenerico(
                'auditoria',
                'Auditoría',
                '/auditoria',
                'Bitácora de todo lo que ocurre en el sistema: quién hizo qué y cuándo, sin posibilidad de edición.',
                true,
            ),
        ],
    },
    {
        patron: /^\/usuarios\/?$/,
        tours: [
            tourGenerico(
                'usuarios',
                'Usuarios',
                '/usuarios',
                'Administra las cuentas del sistema y qué rol tiene cada una.',
            ),
        ],
    },
    {
        patron: /^\/roles\/?$/,
        tours: [
            tourGenerico(
                'roles',
                'Roles y permisos',
                '/roles',
                'Crea roles personalizados y decide exactamente qué puede hacer cada uno, permiso por permiso.',
            ),
        ],
    },
    {
        patron: /^\/configuracion\/?$/,
        tours: [
            tourGenerico(
                'configuracion',
                'Configuración',
                '/configuracion',
                'Personalización visual global del sistema (no la de una empresa en particular).',
            ),
        ],
    },
];

/**
 * Tours disponibles para la ruta actual. `pathname` viene de
 * `window.location.pathname` (sin dominio ni query string).
 */
export function toursDisponibles(pathname: string): Tour[] {
    return REGISTRO.filter((entrada) => entrada.patron.test(pathname)).flatMap(
        (entrada) => entrada.tours,
    );
}
