<?php

namespace App\Enums;

/**
 * Roles base del sistema. Los nombres visibles se muestran en español y los
 * identificadores internos (value) son los que se registran en Spatie.
 */
enum RolSistema: string
{
    case Superadministrador = 'superadministrador';
    case Administrador = 'administrador';
    case Supervisor = 'supervisor';
    case Encargado = 'encargado';
    case Colaborador = 'colaborador';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Superadministrador => 'Superadministrador',
            self::Administrador => 'Administrador',
            self::Supervisor => 'Supervisor',
            self::Encargado => 'Encargado',
            self::Colaborador => 'Colaborador',
        };
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(fn (self $rol): string => $rol->value, self::cases());
    }
}
