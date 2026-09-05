<?php

namespace App\Enums;

/**
 * Estado VISIBLE consolidado de una unidad, para presentación (listados,
 * detalle, Dashboard, entregas/devoluciones, auditoría, filtros, Excel/PDF).
 *
 * NO sustituye el modelo interno: `UnidadActivo::estado` (posesión) y
 * `UnidadActivo::condicion` (salud física) siguen siendo los dos ejes reales
 * y son la fuente de verdad — este enum sólo resume ambos en un único valor
 * fácil de entender. Ver `UnidadActivo::estadoVisible()` para la regla de
 * resolución (orden de prioridad: baja > robado > perdido > reparación
 * [incluye inservible] > asignado > disponible).
 */
enum EstadoVisibleUnidad: string
{
    case Disponible = 'disponible';
    case Asignado = 'asignado';
    case Reparacion = 'reparacion';
    case Perdido = 'perdido';
    case Robado = 'robado';
    case Baja = 'baja';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::Asignado => 'Asignado',
            self::Reparacion => 'Reparación',
            self::Perdido => 'Perdido',
            self::Robado => 'Robado',
            self::Baja => 'Baja',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Disponible => 'En resguardo, funcional, configurado y listo para entrega.',
            self::Asignado => 'Entregado y bajo custodia de una persona/servicio identificado.',
            self::Reparacion => 'Fuera de operación y con diagnóstico o seguimiento abierto.',
            self::Perdido => 'Ubicación desconocida; acciones de contención en curso o cerradas.',
            self::Robado => 'Sustracción reportada; accesos y línea bloqueados.',
            self::Baja => 'Retirado definitivamente, con borrado y destino final documentados.',
        };
    }
}
