<?php

namespace App\Enums;

/**
 * Perfil técnico de una unidad identificada individualmente: determina qué
 * datos específicos del equipo se piden y muestran (marca, modelo, IMEI…).
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

    public function etiqueta(): string
    {
        return match ($this) {
            self::Celular => 'Celular',
            self::Computadora => 'Computadora',
            self::Tablet => 'Tablet',
        };
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
        };
    }

    /**
     * Campos OBLIGATORIOS al dar de alta / editar una unidad de este perfil.
     * Para Celular el IMEI es identificador técnico del equipo y va aunque no
     * tenga línea; número / operador / plan quedan opcionales.
     *
     * @return list<string>
     */
    public function camposRequeridos(): array
    {
        return match ($this) {
            self::Celular => ['marca', 'modelo', 'imei'],
            self::Computadora, self::Tablet => ['marca', 'modelo'],
        };
    }
}
