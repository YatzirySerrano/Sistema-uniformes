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
 * resolución (orden de prioridad: baja > robado > perdido > reparación >
 * inservible > asignado > disponible). `Reparacion` e `Inservible` son
 * estados VISIBLES SEPARADOS (hasta 2026-09 se agrupaban ambos bajo
 * `Reparacion`, lo que hacía aparecer una unidad Inservible con la etiqueta
 * y descripción de "En reparación" — decisión revertida por confundir al
 * usuario: Inservible no implica que haya un diagnóstico/seguimiento en
 * curso).
 */
enum EstadoVisibleUnidad: string
{
    case Disponible = 'disponible';
    case Asignado = 'asignado';
    case Reparacion = 'reparacion';
    case Inservible = 'inservible';
    case Perdido = 'perdido';
    case Robado = 'robado';
    case Baja = 'baja';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::Asignado => 'Asignado',
            self::Reparacion => 'Reparación',
            self::Inservible => 'Inservible',
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
            self::Inservible => 'Fuera de operación y no disponible para uso.',
            self::Perdido => 'Ubicación desconocida; acciones de contención en curso o cerradas.',
            self::Robado => 'Sustracción reportada; accesos y línea bloqueados.',
            self::Baja => 'Retirado definitivamente, con borrado y destino final documentados.',
        };
    }

    /**
     * Única fuente de verdad de la regla de resolución (orden de prioridad:
     * baja > robado > perdido > reparación > inservible > asignado >
     * disponible). `UnidadActivo::estadoVisible()` delegan aquí; también la
     * usa `ServicioDashboard` para colapsar conteos agrupados en SQL
     * (`estado`+`condicion`) sin traer cada unidad a PHP.
     */
    public static function resolver(EstadoUnidadActivo $estado, CondicionUnidadActivo $condicion): self
    {
        if ($estado === EstadoUnidadActivo::Baja) {
            return self::Baja;
        }

        if ($condicion === CondicionUnidadActivo::Robado) {
            return self::Robado;
        }

        if ($condicion === CondicionUnidadActivo::Perdido) {
            return self::Perdido;
        }

        if ($condicion === CondicionUnidadActivo::EnReparacion) {
            return self::Reparacion;
        }

        if ($condicion === CondicionUnidadActivo::Inservible) {
            return self::Inservible;
        }

        if ($estado === EstadoUnidadActivo::Asignada) {
            return self::Asignado;
        }

        return self::Disponible;
    }
}
