<?php

namespace App\Acciones;

use App\Enums\EstadoInventarioFisico;
use App\Enums\TipoMovimiento;
use App\Excepciones\DiferenciasInventarioFisicoDesactualizadasException;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\InventarioFisico;
use App\Models\SaldoInventario;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioInventario;
use App\Servicios\ServicioResumenInventarioFisico;
use Illuminate\Support\Facades\DB;

/**
 * Aplica en UN SOLO lote, todo-o-nada, las diferencias detectadas por una
 * ronda de inventario físico YA finalizada y firmada — el equivalente masivo
 * de ir renglón por renglón a "Corregir existencia" en Existencias globales.
 * Reutiliza `ServicioInventario::registrarMovimiento()` (la misma puerta que
 * usa `AjustarInventario` para una corrección manual): nunca se reescribe
 * `saldos_inventario` a mano ni se copia esa lógica.
 *
 * Protección crítica: la ronda congeló `cantidad_esperada` al iniciar, pero el
 * inventario pudo moverse después (entradas, entregas, devoluciones, otro
 * ajuste, un traspaso...). Antes de escribir NADA, se relee bajo
 * `lockForUpdate()` — mismo orden de locks que el resto del módulo, Ronda →
 * fila — el saldo ACTUAL de cada combinación y se compara contra el
 * snapshot: si una sola no coincide, se aborta el lote completo (se lanza
 * `DiferenciasInventarioFisicoDesactualizadasException` con el detalle) sin
 * tocar ninguna fila, ni siquiera las que sí coincidían.
 *
 * Nunca toca `UnidadActivo`: sólo `inventario_fisico_existencias` (activos
 * POR CANTIDAD). Las unidades identificadas tienen su propio mecanismo
 * (`MarcarUnidadIncidencia`, `DarDeBajaUnidadActivo`, etc.), fuera de alcance.
 */
class AplicarCorreccionesInventarioFisico
{
    public function __construct(
        private readonly ServicioInventario $inventario,
        private readonly ServicioResumenInventarioFisico $resumen,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    public function ejecutar(InventarioFisico $ronda, User $usuario): InventarioFisico
    {
        return DB::transaction(function () use ($ronda, $usuario): InventarioFisico {
            // Primer lock de la transacción, SIEMPRE Ronda → fila (igual que
            // verificar/escanear/finalizar), para nunca chocar en deadlock
            // con otra mutación de esta misma ronda.
            $bloqueada = InventarioFisico::query()->whereKey($ronda->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado !== EstadoInventarioFisico::Finalizado) {
                throw new ExcepcionDeNegocioSimple('Esta ronda todavía no está finalizada. Ciérrala antes de aplicar correcciones.');
            }

            if (! $bloqueada->firma()->exists()) {
                throw new ExcepcionDeNegocioSimple('Esta ronda todavía no tiene firma de cierre.');
            }

            if ($bloqueada->tieneCorreccionesAplicadas()) {
                throw new ExcepcionDeNegocioSimple('Las correcciones de esta ronda ya fueron aplicadas anteriormente.');
            }

            if ($bloqueada->almacen_id === null) {
                throw new ExcepcionDeNegocioSimple('Esta ronda no tiene un almacén asociado; no se pueden aplicar correcciones.');
            }

            // Mismo criterio "con_diferencia" que ya usa la pantalla de la
            // ronda: `cantidad_contada` verificada y distinta de la esperada.
            // Orden estable (activo, talla) para que el orden de locks de
            // saldo sea SIEMPRE el mismo entre aplicaciones concurrentes de
            // distintas rondas que pudieran tocar el mismo almacén.
            $filas = $this->resumen->consultaExistencias($bloqueada, 'con_diferencia')
                ->orderBy('activo_id')->orderBy('talla_id')
                ->get();

            if ($filas->isEmpty()) {
                throw new ExcepcionDeNegocioSimple('No hay diferencias por aplicar en esta ronda.');
            }

            // Fase 1: verificar TODAS las combinaciones bajo lock antes de
            // escribir nada. Si una sola cambió, se recopila el conflicto
            // completo (no basta con el primero) y no se aplica ni una fila.
            $conflictos = [];

            foreach ($filas as $fila) {
                $actual = $this->saldoActualBloqueado($bloqueada->empresa_id, $bloqueada->almacen_id, $fila->activo_id, $fila->talla_id);

                if ($actual !== $fila->cantidad_esperada) {
                    $conflictos[] = [
                        'activo' => $fila->activo->nombre,
                        'talla' => $fila->talla?->valor,
                        'esperada' => $fila->cantidad_esperada,
                        'actual' => $actual,
                    ];
                }
            }

            if ($conflictos !== []) {
                throw new DiferenciasInventarioFisicoDesactualizadasException(
                    'No se aplicaron las correcciones porque algunas existencias cambiaron después de realizar esta ronda. Revísalas antes de continuar.',
                    $conflictos,
                );
            }

            // Fase 2: aplicar. El saldo de cada combinación sigue bloqueado
            // por esta misma transacción desde la fase 1 — `registrarMovimiento()`
            // vuelve a leerlo (mismo candado, ya nuestro) y encuentra
            // exactamente el valor ya verificado, nunca uno distinto.
            foreach ($filas as $fila) {
                $diferencia = $fila->cantidad_contada - $fila->cantidad_esperada;

                $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
                    empresaId: $bloqueada->empresa_id,
                    almacenId: $bloqueada->almacen_id,
                    activoId: $fila->activo_id,
                    tallaId: $fila->talla_id,
                    tipo: $diferencia > 0 ? TipoMovimiento::AjusteEntrada : TipoMovimiento::AjusteSalida,
                    cantidad: abs($diferencia),
                    realizadoPor: $usuario->id,
                    referenciaTipo: InventarioFisico::class,
                    referenciaId: $bloqueada->id,
                    motivo: "Corrección por inventario físico {$bloqueada->folio}.",
                ));
            }

            $bloqueada->update([
                'correcciones_aplicadas_en' => now(),
                'correcciones_aplicadas_por' => $usuario->id,
            ]);

            $this->auditoria->registrar('inventario_fisico', 'correcciones_aplicar', [
                'empresa_id' => $bloqueada->empresa_id,
                'tipo_entidad' => InventarioFisico::class,
                'entidad_id' => $bloqueada->id,
                'descripcion' => "Se aplicaron {$filas->count()} corrección(es) de inventario provenientes de la ronda «{$bloqueada->nombre}» ({$bloqueada->folio}).",
                'motivo' => 'Aplicación en bloque de las diferencias verificadas en la ronda.',
                'valores_anteriores' => ['correcciones_aplicadas_en' => null],
                'valores_nuevos' => ['correcciones_aplicadas_en' => $bloqueada->correcciones_aplicadas_en->toIso8601String()],
            ]);

            return $bloqueada->fresh();
        });
    }

    /**
     * Existencia actual de una combinación bajo `lockForUpdate()`, para poder
     * compararla contra el snapshot ANTES de decidir si se aplica. Ausencia de
     * fila (nunca debería ocurrir: `saldos_inventario` no se borra) cuenta
     * como 0 — igual que `ServicioInventario::saldoActual()`.
     */
    private function saldoActualBloqueado(int $empresaId, int $almacenId, int $activoId, ?int $tallaId): int
    {
        $consulta = SaldoInventario::query()
            ->where('empresa_id', $empresaId)
            ->where('almacen_id', $almacenId)
            ->where('activo_id', $activoId);

        $tallaId === null ? $consulta->whereNull('talla_id') : $consulta->where('talla_id', $tallaId);

        return (int) ($consulta->lockForUpdate()->value('cantidad') ?? 0);
    }
}
