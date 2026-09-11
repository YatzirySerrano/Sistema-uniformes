<?php

namespace App\Soporte;

use App\Enums\PerfilTecnicoUnidad;
use App\Models\Activo;

/**
 * Fuente ÚNICA de verdad del perfil técnico de un activo identificado
 * individualmente (Celular / Computadora / Tablet / ninguno).
 *
 * El perfil vive en la relación 1:1 `CategoriaActivo::perfilTecnico`
 * (`categoria_activo_perfil_tecnico`) — NUNCA en `codigo` (identificador
 * operativo del catálogo) ni en el nombre. Un activo cuya categoría no tiene
 * fila de perfil técnico devuelve `null` (sin formulario especializado) hasta
 * que un administrador lo clasifique desde el catálogo.
 */
class ResolverPerfilTecnicoUnidad
{
    public function paraActivo(Activo $activo): ?PerfilTecnicoUnidad
    {
        $activo->loadMissing('categoriaActivo.perfilTecnico');

        return $activo->categoriaActivo?->perfilTecnico?->perfil;
    }
}
