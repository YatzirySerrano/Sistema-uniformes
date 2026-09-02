<?php

namespace App\Acciones;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\MovimientoInventario;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;

/**
 * Ajuste de existencias a un valor objetivo. Exige motivo y queda auditado.
 * No sustituye el saldo directamente: genera el movimiento de ajuste.
 */
class AjustarInventario
{
    public function __construct(
        private readonly ServicioInventario $inventario,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    public function ejecutar(
        int $empresaId,
        int $sucursalId,
        int $activoId,
        int $tallaId,
        int $existenciaObjetivo,
        string $motivo,
        ?int $realizadoPor,
    ): ?MovimientoInventario {
        if (trim($motivo) === '') {
            throw new ExcepcionDeNegocioSimple('El motivo del ajuste es obligatorio.');
        }
        if ($existenciaObjetivo < 0) {
            throw new ExcepcionDeNegocioSimple('La existencia objetivo no puede ser negativa.');
        }

        Sucursal::query()->where('empresa_id', $empresaId)->findOr($sucursalId, fn () => throw new ExcepcionDeNegocioSimple('La sucursal no pertenece a esta empresa.'));
        Activo::query()->where('empresa_id', $empresaId)->findOr($activoId, fn () => throw new ExcepcionDeNegocioSimple('El activo no pertenece a esta empresa.'));
        Talla::query()->where('empresa_id', $empresaId)->findOr($tallaId, fn () => throw new ExcepcionDeNegocioSimple('La talla no pertenece a esta empresa.'));

        return DB::transaction(function () use ($empresaId, $sucursalId, $activoId, $tallaId, $existenciaObjetivo, $motivo, $realizadoPor): ?MovimientoInventario {
            $anterior = $this->inventario->saldoActual($empresaId, $sucursalId, $activoId, $tallaId);

            $movimiento = $this->inventario->fijarExistencia(
                $empresaId,
                $sucursalId,
                $activoId,
                $tallaId,
                $existenciaObjetivo,
                $motivo,
                $realizadoPor,
            );

            $this->auditoria->registrar('inventario', 'ajuste', [
                'sucursal_id' => $sucursalId,
                'tipo_entidad' => MovimientoInventario::class,
                'entidad_id' => $movimiento?->getKey(),
                'motivo' => $motivo,
                'descripcion' => "Ajuste de existencia de {$anterior} a {$existenciaObjetivo}.",
                'valores_anteriores' => ['cantidad' => $anterior],
                'valores_nuevos' => ['cantidad' => $existenciaObjetivo],
            ]);

            return $movimiento;
        });
    }
}
