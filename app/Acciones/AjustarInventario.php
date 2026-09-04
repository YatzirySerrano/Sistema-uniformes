<?php

namespace App\Acciones;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;

/**
 * Ajuste de existencias de un ALMACÉN a un valor objetivo. Exige motivo y queda
 * auditado. No sustituye el saldo directamente: genera el movimiento de ajuste.
 */
class AjustarInventario
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

        $almacen = Almacen::query()->paraEmpresa($empresaId)
            ->findOr($almacenId, fn () => throw new ExcepcionDeNegocioSimple('El almacén no abastece a esta empresa.'));

        if (! $almacen->activo) {
            throw new ExcepcionDeNegocioSimple('El almacén está desactivado; no admite ajustes de inventario.');
        }

        $activo = Activo::query()->where('empresa_id', $empresaId)
            ->findOr($activoId, fn () => throw new ExcepcionDeNegocioSimple('El activo no pertenece a esta empresa.'));

        // Ajuste = corrección de una fila existente: la variante debe ser nula o
        // estar asociada al activo. No se exige que siga habilitada para la
        // empresa (hay que poder corregir existencias históricas).
        if ($tallaId !== null && ! $activo->tallas()->whereKey($tallaId)->exists()) {
            throw new ExcepcionDeNegocioSimple('Esa variante no corresponde al activo seleccionado.');
        }

        return DB::transaction(function () use ($empresaId, $almacenId, $activoId, $tallaId, $existenciaObjetivo, $motivo, $realizadoPor): ?MovimientoInventario {
            $anterior = $this->inventario->saldoActual($empresaId, $almacenId, $activoId, $tallaId);

            $movimiento = $this->inventario->fijarExistencia(
                $empresaId,
                $almacenId,
                $activoId,
                $tallaId,
                $existenciaObjetivo,
                $motivo,
                $realizadoPor,
            );

            $this->auditoria->registrar('inventario', 'ajuste', [
                'tipo_entidad' => MovimientoInventario::class,
                'entidad_id' => $movimiento?->getKey(),
                'empresa_id' => $empresaId,
                'motivo' => $motivo,
                'descripcion' => "Ajuste de existencia de {$anterior} a {$existenciaObjetivo} en almacén #{$almacenId}.",
                'valores_anteriores' => ['cantidad' => $anterior],
                'valores_nuevos' => ['cantidad' => $existenciaObjetivo],
            ]);

            return $movimiento;
        });
    }
}
