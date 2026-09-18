<?php

namespace App\Acciones;

use App\Enums\CondicionDevolucion;
use App\Enums\TipoControlActivo;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\CondicionInventario;
use App\Models\Talla;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioInventario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Restaura N piezas marcadas como Dañado de vuelta a "Disponible" (se
 * repararon o el conteo estaba mal) — mismo espíritu que
 * `RestaurarCondicionUnidadActivo` para unidades individuales. NUNCA aplica
 * a "Baja": es terminal, igual que para `UnidadActivo` (no se "revive" una
 * baja de inventario por cantidad).
 */
class RestaurarCondicionInventario
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
        int $cantidad,
        string $motivo,
        ?int $realizadoPor,
    ): CondicionInventario {
        if (trim($motivo) === '') {
            throw new ExcepcionDeNegocioSimple('El motivo es obligatorio.');
        }
        if ($cantidad <= 0) {
            throw new ExcepcionDeNegocioSimple('La cantidad debe ser mayor a cero.');
        }

        $almacen = Almacen::query()->paraEmpresa($empresaId)
            ->findOr($almacenId, fn () => throw new ExcepcionDeNegocioSimple('El almacén no abastece a esta empresa.'));

        if (! $almacen->activo) {
            throw new ExcepcionDeNegocioSimple('El almacén está desactivado; no admite cambios de condición.');
        }

        $activo = Activo::query()->where('empresa_id', $empresaId)->where('tipo_control', TipoControlActivo::Cantidad)
            ->findOr($activoId, fn () => throw new ExcepcionDeNegocioSimple('El activo no pertenece a esta empresa o no es un activo por cantidad.'));

        if ($tallaId !== null && ! $activo->tallas()->whereKey($tallaId)->exists()) {
            throw new ExcepcionDeNegocioSimple('Esa variante no corresponde al activo seleccionado.');
        }

        return DB::transaction(function () use ($empresaId, $almacenId, $tallaId, $cantidad, $motivo, $realizadoPor, $almacen, $activo): CondicionInventario {
            $danadasActuales = $this->danadasActuales($empresaId, $almacenId, $activo->id, $tallaId);

            if ($cantidad > $danadasActuales) {
                throw new ExcepcionDeNegocioSimple("Sólo hay {$danadasActuales} pieza(s) dañada(s) registrada(s) para restaurar.");
            }

            // "Recuperacion" (Entrada): mismo TipoMovimiento que ya usa
            // `RecuperarUnidadActivo`, ahora también para pooled cantidad —
            // regresa a "Disponible" a través del mismo candado/transacción.
            $movimiento = $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
                empresaId: $empresaId,
                almacenId: $almacenId,
                activoId: $activo->id,
                tallaId: $tallaId,
                tipo: TipoMovimiento::Recuperacion,
                cantidad: $cantidad,
                realizadoPor: $realizadoPor,
                motivo: $motivo,
            ));

            $registro = CondicionInventario::query()->create([
                'empresa_id' => $empresaId,
                'almacen_id' => $almacenId,
                'activo_id' => $activo->id,
                'talla_id' => $tallaId,
                'movimiento_inventario_id' => $movimiento->getKey(),
                'condicion' => CondicionDevolucion::Danado,
                'tipo' => TipoMovimiento::Recuperacion,
                'cantidad' => $cantidad,
                'motivo' => $motivo,
                'realizado_por' => $realizadoPor,
            ]);

            $variante = $tallaId !== null ? Talla::query()->whereKey($tallaId)->value('valor') : null;
            $nombreActivo = $variante !== null ? "{$activo->nombre} · {$variante}" : $activo->nombre;

            $this->auditoria->registrar('inventario', 'condicion_restaurar', [
                'tipo_entidad' => CondicionInventario::class,
                'entidad_id' => $registro->getKey(),
                'empresa_id' => $empresaId,
                'motivo' => $motivo,
                'descripcion' => "{$cantidad} pieza(s) de {$nombreActivo} en {$almacen->nombre} ({$almacen->codigo}) restauradas de Dañado a Disponible. Motivo: {$motivo}.",
                'valores_anteriores' => ['condicion' => CondicionDevolucion::Danado->value],
                'valores_nuevos' => ['condicion' => 'disponible', 'cantidad' => $cantidad],
            ]);

            return $registro;
        });
    }

    /**
     * Neto actual de piezas marcadas como Dañado para esta combinación
     * (empresa + almacén + activo + variante): entradas a "dañado"
     * (`Incidencia`) menos las que ya se restauraron (`Recuperacion`). Mismo
     * criterio de suma histórica que usa `ServicioEstadoInventario`.
     */
    private function danadasActuales(int $empresaId, int $almacenId, int $activoId, ?int $tallaId): int
    {
        $consulta = CondicionInventario::query()
            ->where('empresa_id', $empresaId)
            ->where('almacen_id', $almacenId)
            ->where('activo_id', $activoId)
            ->where('condicion', CondicionDevolucion::Danado)
            ->when(
                $tallaId === null,
                fn (Builder $q) => $q->whereNull('talla_id'),
                fn (Builder $q) => $q->where('talla_id', $tallaId),
            );

        $marcadas = (int) (clone $consulta)->where('tipo', TipoMovimiento::Incidencia)->sum('cantidad');
        $restauradas = (int) (clone $consulta)->where('tipo', TipoMovimiento::Recuperacion)->sum('cantidad');

        return max(0, $marcadas - $restauradas);
    }
}
