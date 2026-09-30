<?php

namespace App\Enums;

/**
 * Perfil técnico de una unidad identificada individualmente: determina qué
 * datos específicos del equipo se piden y muestran (marca, modelo, IMEI,
 * placas…).
 *
 * NO se deriva del NOMBRE ni del `codigo` del catálogo: la fuente de verdad es
 * la relación 1:1 `CategoriaActivo::perfilTecnico`
 * (`categoria_activo_perfil_tecnico`), resuelta por
 * `App\Soporte\ResolverPerfilTecnicoUnidad`. Una categoría sin fila de perfil
 * NO tiene perfil técnico (no se muestra formulario especializado) hasta que
 * un administrador la clasifique.
 */
enum PerfilTecnicoUnidad: string
{
    case Celular = 'celular';
    case Computadora = 'computadora';
    case Tablet = 'tablet';
    case Transporte = 'transporte';
    case Electrodomestico = 'electrodomestico';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Celular => 'Celular',
            self::Computadora => 'Computadora',
            self::Tablet => 'Tablet',
            self::Transporte => 'Transporte',
            self::Electrodomestico => 'Electrodoméstico',
        };
    }

    /**
     * Regla CENTRAL de dominio: un activo cuya categoría tiene perfil técnico
     * es un bien físico concreto (custodio, condición, historial, serie…) y
     * sólo puede controlarse con SEGUIMIENTO INDIVIDUAL — nunca "por
     * cantidad". Hoy aplica a todos los perfiles; si mañana existiera uno
     * agregable, basta con excluirlo aquí. La usan el alta/edición de activos
     * (backend) y el formulario (espejo en `resources/js/lib/perfilTecnicoUnidad.ts`).
     */
    public function exigeSeguimientoIndividual(): bool
    {
        return true;
    }

    /**
     * Campos de especificación que APLICAN a este perfil (en orden de UI).
     *
     * @return list<string>
     */
    public function camposVisibles(): array
    {
        return match ($this) {
            self::Celular => ['marca', 'modelo', 'imei', 'numero_telefonico', 'operador', 'plan'],
            self::Computadora, self::Tablet => ['marca', 'modelo'],
            self::Transporte => ['clase_vehiculo', 'marca', 'modelo', 'anio', 'color', 'placas', 'numero_serie'],
            self::Electrodomestico => ['marca', 'modelo', 'color', 'numero_serie'],
        };
    }

    /**
     * Campos OBLIGATORIOS al dar de alta / editar una unidad de este perfil.
     * - Celular: el IMEI identifica al equipo aunque no tenga línea.
     * - Transporte: clase, marca, modelo y año describen el vehículo; placas
     *   y NIV/serie se piden como alternativa (ver `identificadoresAlternativos()`).
     * - Electrodoméstico: sólo marca — muchos equipos existentes no tienen
     *   modelo o serie visibles y no deben quedar fuera del sistema.
     *
     * @return list<string>
     */
    public function camposRequeridos(): array
    {
        return match ($this) {
            self::Celular => ['marca', 'modelo', 'imei'],
            self::Computadora, self::Tablet => ['marca', 'modelo'],
            self::Transporte => ['clase_vehiculo', 'marca', 'modelo', 'anio'],
            self::Electrodomestico => ['marca'],
        };
    }

    /**
     * Grupos de campos donde basta con capturar AL MENOS UNO. Un vehículo
     * recién comprado puede no tener placas todavía, pero siempre trae NIV /
     * VIN / número de serie de fábrica: exigir uno de los dos evita dar de
     * alta un vehículo imposible de identificar sin bloquear la operación.
     *
     * @return list<list<string>>
     */
    public function identificadoresAlternativos(): array
    {
        return match ($this) {
            self::Transporte => [['placas', 'numero_serie']],
            default => [],
        };
    }
}
