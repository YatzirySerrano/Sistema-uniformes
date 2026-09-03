<?php

namespace App\Servicios;

use App\Enums\TipoMovimiento;
use App\Excepciones\ExistenciasInsuficientesException;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Servicios\DTO\MovimientoInventarioDatos;
use Illuminate\Support\Facades\DB;

/**
 * Única puerta de modificación del inventario. Cualquier cambio de existencias
 * (entradas, entregas, devoluciones, ajustes, correcciones y traspasos) debe
 * pasar por aquí para garantizar coherencia entre saldo y movimientos, control
 * de concurrencia y prohibición de stock negativo.
 *
 * La dimensión del saldo es: empresa + ALMACÉN + activo + talla.
 */
class ServicioInventario
{
    public function saldoActual(int $empresaId, int $almacenId, int $activoId, int $tallaId): int
    {
        return (int) SaldoInventario::query()
            ->where('empresa_id', $empresaId)
            ->where('almacen_id', $almacenId)
            ->where('activo_id', $activoId)
            ->where('talla_id', $tallaId)
            ->value('cantidad');
    }

    /**
     * Registra un movimiento y actualiza el saldo dentro de una transacción con
     * bloqueo pesimista sobre la fila de saldo.
     */
    public function registrarMovimiento(MovimientoInventarioDatos $datos): MovimientoInventario
    {
        if ($datos->cantidad <= 0) {
            throw new \InvalidArgumentException('La cantidad del movimiento debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($datos): MovimientoInventario {
            $saldo = SaldoInventario::query()
                ->where('empresa_id', $datos->empresaId)
                ->where('almacen_id', $datos->almacenId)
                ->where('activo_id', $datos->activoId)
                ->where('talla_id', $datos->tallaId)
                ->lockForUpdate()
                ->first();

            if ($saldo === null) {
                $saldo = new SaldoInventario([
                    'empresa_id' => $datos->empresaId,
                    'almacen_id' => $datos->almacenId,
                    'sucursal_id' => null,
                    'activo_id' => $datos->activoId,
                    'talla_id' => $datos->tallaId,
                    'cantidad' => 0,
                    'minimo' => 0,
                ]);
                $saldo->save();

                $saldo = SaldoInventario::query()->whereKey($saldo->getKey())->lockForUpdate()->first();
            }

            $anterior = (int) $saldo->cantidad;
            $direccion = $datos->tipo->direccion();
            $resultante = $anterior + ($direccion->signo() * $datos->cantidad);

            if ($resultante < 0 && ! $datos->permitirNegativo) {
                throw ExistenciasInsuficientesException::para(
                    Activo::query()->whereKey($datos->activoId)->value('nombre') ?? 'el activo',
                    Talla::query()->whereKey($datos->tallaId)->value('valor') ?? 's/v',
                    Almacen::query()->whereKey($datos->almacenId)->value('nombre') ?? 's/a',
                    $anterior,
                    $datos->cantidad,
                );
            }

            $saldo->cantidad = $resultante;
            $saldo->save();

            return MovimientoInventario::query()->create([
                'empresa_id' => $datos->empresaId,
                'almacen_id' => $datos->almacenId,
                'sucursal_id' => $datos->sucursalId,
                'activo_id' => $datos->activoId,
                'talla_id' => $datos->tallaId,
                'tipo' => $datos->tipo,
                'direccion' => $direccion,
                'cantidad' => $datos->cantidad,
                'existencia_anterior' => $anterior,
                'existencia_resultante' => $resultante,
                'referencia_tipo' => $datos->referenciaTipo,
                'referencia_id' => $datos->referenciaId,
                'motivo' => $datos->motivo,
                'notas' => $datos->notas,
                'realizado_por' => $datos->realizadoPor,
                'ocurrido_en' => now(),
            ]);
        });
    }

    public function ajustarMinimo(int $empresaId, int $almacenId, int $activoId, int $tallaId, int $minimo): SaldoInventario
    {
        return DB::transaction(function () use ($empresaId, $almacenId, $activoId, $tallaId, $minimo): SaldoInventario {
            /** @var SaldoInventario $saldo */
            $saldo = SaldoInventario::query()->firstOrCreate(
                [
                    'empresa_id' => $empresaId,
                    'almacen_id' => $almacenId,
                    'activo_id' => $activoId,
                    'talla_id' => $tallaId,
                ],
                ['sucursal_id' => null, 'cantidad' => 0, 'minimo' => 0],
            );

            $saldo->update(['minimo' => max(0, $minimo)]);

            return $saldo;
        });
    }

    /**
     * Ajuste absoluto: fija la existencia a un valor objetivo generando el
     * movimiento de ajuste correspondiente. Exige motivo.
     */
    public function fijarExistencia(
        int $empresaId,
        int $almacenId,
        int $activoId,
        int $tallaId,
        int $objetivo,
        string $motivo,
        ?int $realizadoPor,
        ?int $sucursalId = null,
    ): ?MovimientoInventario {
        $actual = $this->saldoActual($empresaId, $almacenId, $activoId, $tallaId);
        $delta = $objetivo - $actual;

        if ($delta === 0) {
            return null;
        }

        return $this->registrarMovimiento(new MovimientoInventarioDatos(
            empresaId: $empresaId,
            almacenId: $almacenId,
            activoId: $activoId,
            tallaId: $tallaId,
            tipo: $delta > 0 ? TipoMovimiento::AjusteEntrada : TipoMovimiento::AjusteSalida,
            cantidad: abs($delta),
            realizadoPor: $realizadoPor,
            motivo: $motivo,
            sucursalId: $sucursalId,
        ));
    }
}
