<?php

namespace App\Servicios;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Almacen;
use App\Models\Sucursal;

/**
 * Resuelve el almacén de origen del stock para operaciones que aún seleccionan
 * la sucursal (entregas, devoluciones, correcciones) mientras su reingeniería de
 * UI llega. Regla: el almacén activo que abastece esa sucursal debe ser
 * inequívoco. Si hay cero o varios, la operación se detiene con un mensaje claro
 * en español (nunca un 500).
 */
class ResolverAlmacenOperativo
{
    public function paraSucursal(Sucursal $sucursal, ?int $almacenPreferidoId = null): Almacen
    {
        if ($almacenPreferidoId !== null) {
            $preferido = $sucursal->almacenes()
                ->where('almacenes.activo', true)
                ->whereKey($almacenPreferidoId)
                ->first();

            if ($preferido instanceof Almacen) {
                return $preferido;
            }
        }

        $almacenes = $sucursal->almacenes()->where('almacenes.activo', true)->get();

        if ($almacenes->count() === 1) {
            return $almacenes->first();
        }

        if ($almacenes->isEmpty()) {
            throw new ExcepcionDeNegocioSimple(
                "La sucursal «{$sucursal->nombre}» no tiene un almacén que la abastezca. ".
                'Asígnale un almacén (módulo Almacenes) para poder operar su inventario.'
            );
        }

        throw new ExcepcionDeNegocioSimple(
            "La sucursal «{$sucursal->nombre}» es abastecida por varios almacenes. ".
            'Selecciona el almacén de origen de forma explícita.'
        );
    }
}
