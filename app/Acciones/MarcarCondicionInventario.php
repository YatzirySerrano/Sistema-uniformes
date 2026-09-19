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
use Illuminate\Support\Facades\DB;

/**
 * Marca N piezas de un activo POR CANTIDAD como Dañado, Baja o Robo/extravío,
 * directamente desde el stock disponible de un almacén (nunca desde una
 * Devolución). Resta de "Disponible" reutilizando
 * `ServicioInventario::registrarMovimiento()` (mismo candado/transacción/
 * rechazo de negativos que cualquier otro movimiento) y deja rastro
 * persistente en `condiciones_inventario` para que "cuántas están en cada
 * condición ahora" sea trazable — nunca desaparecen del sistema.
 *
 * "Robo / extravío" es terminal, igual que "Baja": no existe una acción de
 * "restaurar" desde ahí (una pieza reportada como robada/extraviada que
 * aparece de nuevo se vuelve a dar de alta como una entrada normal, nunca se
 * "revive" un robo). Ver `App\Models\MovimientoInventario::etiquetaEfectiva()`
 * para cómo se distingue de una pérdida/robo real de una `UnidadActivo`.
 */
class MarcarCondicionInventario
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
        CondicionDevolucion $condicion,
        int $cantidad,
        string $motivo,
        ?int $realizadoPor,
    ): CondicionInventario {
        if (! in_array($condicion, [CondicionDevolucion::Danado, CondicionDevolucion::Baja, CondicionDevolucion::RoboExtravio], true)) {
            throw new ExcepcionDeNegocioSimple('La condición debe ser "Dañado", "Baja" o "Robo / extravío".');
        }
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

        return DB::transaction(function () use ($empresaId, $almacenId, $tallaId, $condicion, $cantidad, $motivo, $realizadoPor, $almacen, $activo): CondicionInventario {
            // Mismo motor que cualquier otro movimiento: lock pesimista sobre
            // el saldo y rechazo de negativos si no alcanza. Reutiliza los
            // `TipoMovimiento` ya existentes (Incidencia = pasa a Dañado o a
            // Robo/extravío, Baja = pasa a Baja) — mismo vocabulario que
            // `UnidadActivo`, ahora también para inventario por cantidad.
            // Dañado y Robo/extravío comparten `TipoMovimiento::Incidencia`;
            // `condiciones_inventario.condicion` es lo que los distingue
            // (ver `MovimientoInventario::etiquetaEfectiva()`), nunca el
            // texto de `motivo`.
            $movimiento = $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
                empresaId: $empresaId,
                almacenId: $almacenId,
                activoId: $activo->id,
                tallaId: $tallaId,
                tipo: $condicion === CondicionDevolucion::Baja ? TipoMovimiento::Baja : TipoMovimiento::Incidencia,
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
                'condicion' => $condicion,
                'tipo' => $movimiento->tipo,
                'cantidad' => $cantidad,
                'motivo' => $motivo,
                'realizado_por' => $realizadoPor,
            ]);

            $variante = $tallaId !== null ? Talla::query()->whereKey($tallaId)->value('valor') : null;
            $nombreActivo = $variante !== null ? "{$activo->nombre} · {$variante}" : $activo->nombre;

            $this->auditoria->registrar('inventario', 'condicion_marcar', [
                'tipo_entidad' => CondicionInventario::class,
                'entidad_id' => $registro->getKey(),
                'empresa_id' => $empresaId,
                'motivo' => $motivo,
                'descripcion' => "{$cantidad} pieza(s) de {$nombreActivo} en {$almacen->nombre} ({$almacen->codigo}) marcadas como {$condicion->etiqueta()}. Motivo: {$motivo}.",
                'valores_anteriores' => ['condicion' => null],
                'valores_nuevos' => ['condicion' => $condicion->value, 'cantidad' => $cantidad],
            ]);

            return $registro;
        });
    }
}
