<?php

namespace App\Acciones;

use App\Models\SaldoInventario;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;

/**
 * Cambia el mínimo configurado de una fila de saldo. No es un movimiento de
 * stock: no toca `cantidad`, no genera `MovimientoInventario`. Queda auditado
 * en Auditoría sólo cuando el valor realmente cambia.
 */
class AjustarMinimoInventario
{
    public function __construct(
        private readonly ServicioInventario $inventario,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    public function ejecutar(
        int $empresaId,
        int $almacenId,
        int $activoId,
        ?int $tallaId,
        int $minimoNuevo,
    ): SaldoInventario {
        return DB::transaction(function () use ($empresaId, $almacenId, $activoId, $tallaId, $minimoNuevo): SaldoInventario {
            $minimoAnterior = $this->inventario->minimoActual($empresaId, $almacenId, $activoId, $tallaId);

            $saldo = $this->inventario->ajustarMinimo($empresaId, $almacenId, $activoId, $tallaId, $minimoNuevo);

            if ($minimoAnterior !== $minimoNuevo) {
                $saldo->loadMissing(['activo', 'almacen', 'talla']);

                $nombreActivo = $saldo->activo->nombre;
                $variante = $saldo->talla?->valor;
                $nombreCompleto = $variante !== null ? "{$nombreActivo} · {$variante}" : $nombreActivo;
                $nombreAlmacen = $saldo->almacen->nombre;

                $this->auditoria->registrar('inventario', 'actualizar_minimo', [
                    'tipo_entidad' => SaldoInventario::class,
                    'entidad_id' => $saldo->getKey(),
                    'empresa_id' => $empresaId,
                    'descripcion' => "Se actualizó el mínimo de {$nombreCompleto} en {$nombreAlmacen} de {$minimoAnterior} a {$minimoNuevo}.",
                    'valores_anteriores' => ['minimo' => $minimoAnterior],
                    'valores_nuevos' => ['minimo' => $minimoNuevo],
                ]);
            }

            return $saldo;
        });
    }
}
