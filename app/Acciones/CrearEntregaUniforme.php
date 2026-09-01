<?php

namespace App\Acciones;

use App\Enums\EstadoEntrega;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Colaborador;
use App\Models\EntregaUniforme;
use App\Models\Prenda;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioFolios;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;

/**
 * Registra una entrega de uniformes: valida la coherencia empresa/sucursal/
 * colaborador/prendas, genera folio, crea la cabecera y sus items con snapshot
 * de nombres, y descuenta el inventario a través de ServicioInventario. Todo
 * dentro de una transacción; si falta stock de cualquier item, no se registra
 * nada.
 */
class CrearEntregaUniforme
{
    public function __construct(
        private readonly ServicioInventario $inventario,
        private readonly ServicioFolios $folios,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function ejecutar(
        int $empresaId,
        int $sucursalId,
        int $colaboradorId,
        int $encargadoId,
        string $fechaEntrega,
        array $items,
        ?string $notas = null,
    ): EntregaUniforme {
        $sucursal = Sucursal::query()->where('empresa_id', $empresaId)->findOr($sucursalId, fn () => throw new ExcepcionDeNegocioSimple('La sucursal indicada no pertenece a esta empresa.'));

        $colaborador = Colaborador::query()
            ->where('empresa_id', $empresaId)
            ->findOr($colaboradorId, fn () => throw new ExcepcionDeNegocioSimple('El colaborador indicado no pertenece a esta empresa.'));

        if ($colaborador->sucursal_id !== $sucursal->getKey()) {
            throw new ExcepcionDeNegocioSimple('El colaborador no está adscrito a la sucursal seleccionada.');
        }

        if (! $colaborador->activo) {
            throw new ExcepcionDeNegocioSimple('El colaborador está inactivo y no puede recibir entregas.');
        }

        $items = $this->consolidar($items);

        if ($items === []) {
            throw new ExcepcionDeNegocioSimple('Agrega al menos una prenda a la entrega.');
        }

        $prendas = Prenda::query()->where('empresa_id', $empresaId)->whereIn('id', array_column($items, 'prenda_id'))->get()->keyBy('id');
        $tallas = Talla::query()->where('empresa_id', $empresaId)->whereIn('id', array_column($items, 'talla_id'))->get()->keyBy('id');

        foreach ($items as $item) {
            if (! $prendas->has($item['prenda_id'])) {
                throw new ExcepcionDeNegocioSimple('Una de las prendas seleccionadas no pertenece a esta empresa.');
            }
            if (! $tallas->has($item['talla_id'])) {
                throw new ExcepcionDeNegocioSimple('Una de las tallas seleccionadas no pertenece a esta empresa.');
            }
        }

        return DB::transaction(function () use ($empresaId, $sucursal, $colaborador, $encargadoId, $fechaEntrega, $items, $notas, $prendas, $tallas): EntregaUniforme {
            $entrega = EntregaUniforme::query()->create([
                'folio' => $this->folios->siguiente(ServicioFolios::ENTREGA, $empresaId),
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursal->getKey(),
                'colaborador_id' => $colaborador->getKey(),
                'encargado_id' => $encargadoId,
                'estado' => EstadoEntrega::PendienteFirma,
                'fecha_entrega' => $fechaEntrega,
                'notas' => $notas,
            ]);

            foreach ($items as $item) {
                $entrega->detalles()->create([
                    'prenda_id' => $item['prenda_id'],
                    'talla_id' => $item['talla_id'],
                    'cantidad' => $item['cantidad'],
                    'prenda_nombre_snapshot' => $prendas[$item['prenda_id']]->nombre,
                    'talla_valor_snapshot' => $tallas[$item['talla_id']]->valor,
                ]);

                $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
                    empresaId: $empresaId,
                    sucursalId: $sucursal->getKey(),
                    prendaId: $item['prenda_id'],
                    tallaId: $item['talla_id'],
                    tipo: TipoMovimiento::Entrega,
                    cantidad: $item['cantidad'],
                    realizadoPor: $encargadoId,
                    referenciaTipo: EntregaUniforme::class,
                    referenciaId: $entrega->getKey(),
                    motivo: 'Entrega '.$entrega->folio,
                ));
            }

            $this->auditoria->registrar('entregas', 'crear', [
                'tipo_entidad' => EntregaUniforme::class,
                'entidad_id' => $entrega->getKey(),
                'sucursal_id' => $sucursal->getKey(),
                'descripcion' => 'Entrega '.$entrega->folio.' registrada para '.$colaborador->nombre_completo,
                'valores_nuevos' => $entrega->load('detalles')->toArray(),
            ]);

            return $entrega->load(['detalles', 'colaborador', 'sucursal', 'encargado']);
        });
    }

    /**
     * Suma cantidades de items repetidos (misma prenda + talla) y descarta los
     * de cantidad no positiva.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{prenda_id: int, talla_id: int, cantidad: int}>
     */
    private function consolidar(array $items): array
    {
        $mapa = [];

        foreach ($items as $item) {
            $cantidad = (int) ($item['cantidad'] ?? 0);

            if ($cantidad <= 0) {
                continue;
            }

            $clave = $item['prenda_id'].'-'.$item['talla_id'];
            $mapa[$clave] ??= ['prenda_id' => (int) $item['prenda_id'], 'talla_id' => (int) $item['talla_id'], 'cantidad' => 0];
            $mapa[$clave]['cantidad'] += $cantidad;
        }

        return array_values($mapa);
    }
}
