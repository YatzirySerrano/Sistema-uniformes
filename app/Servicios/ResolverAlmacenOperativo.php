<?php

namespace App\Servicios;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Almacen;
use App\Models\Empresa;

/**
 * Resuelve el almacén de origen del stock para operaciones que aún no eligen el
 * almacén de forma explícita (entregas, devoluciones, correcciones) mientras su
 * reingeniería de UI llega (Bloque E/F). Regla: el almacén activo que abastece a
 * la empresa debe ser inequívoco. Si hay cero o varios, la operación se detiene
 * con un mensaje claro en español (nunca un 500).
 */
class ResolverAlmacenOperativo
{
    public function paraEmpresa(Empresa $empresa, ?int $almacenPreferidoId = null): Almacen
    {
        if ($almacenPreferidoId !== null) {
            $preferido = Almacen::query()
                ->where('activo', true)
                ->paraEmpresa($empresa->getKey())
                ->whereKey($almacenPreferidoId)
                ->first();

            if ($preferido instanceof Almacen) {
                return $preferido;
            }

            throw new ExcepcionDeNegocioSimple(
                "El almacén seleccionado no está activo o no abastece a «{$empresa->nombre_comercial}»."
            );
        }

        $almacenes = Almacen::query()
            ->where('activo', true)
            ->paraEmpresa($empresa->getKey())
            ->get();

        if ($almacenes->count() === 1) {
            return $almacenes->first();
        }

        if ($almacenes->isEmpty()) {
            throw new ExcepcionDeNegocioSimple(
                "La empresa «{$empresa->nombre_comercial}» no tiene ningún almacén activo que la abastezca. ".
                'Registra o activa un almacén (módulo Almacenes) para poder operar su inventario.'
            );
        }

        throw new ExcepcionDeNegocioSimple(
            "La empresa «{$empresa->nombre_comercial}» es abastecida por varios almacenes. ".
            'Selecciona el almacén de origen de forma explícita.'
        );
    }
}
