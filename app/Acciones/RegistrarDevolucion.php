<?php

namespace App\Acciones;

use App\Enums\CondicionDevolucion;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\EntregaUniforme;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ResolverAlmacenOperativo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioFolios;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;

/**
 * Registra la devolución de activos por parte de un colaborador. Sólo los
 * activos marcados como reutilizables (y con reingreso habilitado) vuelven al
 * inventario disponible mediante un movimiento de tipo devolución.
 */
class RegistrarDevolucion
{
    public function __construct(
        private readonly ServicioInventario $inventario,
        private readonly ServicioFolios $folios,
        private readonly ServicioAuditoria $auditoria,
        private readonly ResolverAlmacenOperativo $resolverAlmacen,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function ejecutar(
        int $empresaId,
        int $sucursalId,
        int $colaboradorId,
        ?int $entregaId,
        string $fecha,
        array $items,
        ?int $registradaPor,
        ?string $motivo = null,
        ?string $notas = null,
    ): Devolucion {
        $sucursal = Sucursal::query()->where('empresa_id', $empresaId)->findOr($sucursalId, fn () => throw new ExcepcionDeNegocioSimple('La sucursal no pertenece a esta empresa.'));
        $colaborador = Colaborador::query()->where('empresa_id', $empresaId)->findOr($colaboradorId, fn () => throw new ExcepcionDeNegocioSimple('El colaborador no pertenece a esta empresa.'));

        $entregaOrigen = null;
        if ($entregaId !== null) {
            $entregaOrigen = EntregaUniforme::query()->where('empresa_id', $empresaId)->where('colaborador_id', $colaboradorId)
                ->findOr($entregaId, fn () => throw new ExcepcionDeNegocioSimple('La entrega indicada no corresponde a este colaborador.'));
        }

        // Destino de la devolución: por defecto el almacén de origen de la
        // entrega si sigue disponible; si no, el abastecedor inequívoco de la
        // sucursal.
        $almacen = $this->resolverAlmacen->paraSucursal($sucursal, $entregaOrigen?->almacen_id);

        $items = array_values(array_filter($items, fn ($i): bool => (int) $i['cantidad'] > 0));

        if ($items === []) {
            throw new ExcepcionDeNegocioSimple('Agrega al menos un activo a la devolución.');
        }

        $activos = Activo::query()->where('empresa_id', $empresaId)->whereIn('id', array_column($items, 'activo_id'))->pluck('id')->all();
        $tallas = Talla::query()->where('empresa_id', $empresaId)->whereIn('id', array_column($items, 'talla_id'))->pluck('id')->all();

        return DB::transaction(function () use ($empresaId, $sucursal, $almacen, $colaborador, $entregaId, $fecha, $items, $registradaPor, $motivo, $notas, $activos, $tallas): Devolucion {
            $devolucion = Devolucion::query()->create([
                'folio' => $this->folios->siguiente(ServicioFolios::DEVOLUCION, $empresaId),
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursal->getKey(),
                'almacen_id' => $almacen->getKey(),
                'colaborador_id' => $colaborador->getKey(),
                'entrega_uniforme_id' => $entregaId,
                'registrada_por' => $registradaPor,
                'fecha' => $fecha,
                'motivo' => $motivo,
                'notas' => $notas,
            ]);

            foreach ($items as $item) {
                if (! in_array((int) $item['activo_id'], $activos, true) || ! in_array((int) $item['talla_id'], $tallas, true)) {
                    throw new ExcepcionDeNegocioSimple('Un activo o talla seleccionado no pertenece a esta empresa.');
                }

                $condicion = CondicionDevolucion::tryFrom((string) ($item['condicion'] ?? 'reutilizable')) ?? CondicionDevolucion::Reutilizable;
                $reingresa = $condicion->reingresaInventario();

                $devolucion->detalles()->create([
                    'activo_id' => (int) $item['activo_id'],
                    'talla_id' => (int) $item['talla_id'],
                    'cantidad' => (int) $item['cantidad'],
                    'condicion' => $condicion,
                    'reingresa_inventario' => $reingresa,
                ]);

                if ($reingresa) {
                    $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
                        empresaId: $empresaId,
                        almacenId: $almacen->getKey(),
                        activoId: (int) $item['activo_id'],
                        tallaId: (int) $item['talla_id'],
                        tipo: TipoMovimiento::Devolucion,
                        cantidad: (int) $item['cantidad'],
                        realizadoPor: $registradaPor,
                        referenciaTipo: Devolucion::class,
                        referenciaId: $devolucion->getKey(),
                        motivo: 'Devolución '.$devolucion->folio,
                        sucursalId: $sucursal->getKey(),
                    ));
                }
            }

            $this->auditoria->registrar('devoluciones', 'crear', [
                'tipo_entidad' => Devolucion::class,
                'entidad_id' => $devolucion->getKey(),
                'sucursal_id' => $sucursal->getKey(),
                'descripcion' => 'Devolución '.$devolucion->folio.' registrada para '.$colaborador->nombre_completo,
            ]);

            return $devolucion->load('detalles');
        });
    }
}
