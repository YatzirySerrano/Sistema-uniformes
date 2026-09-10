<?php

namespace App\Acciones;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoControlActivo;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\Talla;
use App\Models\TraspasoInventario;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\HomologadorActivo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioFolios;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;

/**
 * Traspaso de inventario entre almacenes de la MISMA empresa o entre EMPRESAS
 * distintas, con encabezado + varios renglones, en UNA transacción atómica.
 *
 * Por cada renglón por cantidad:
 *   1. resuelve el Activo destino (homologación: reutiliza el equivalente de
 *      la empresa destino o lo crea — `HomologadorActivo`);
 *   2. registra la SALIDA en `(empresa origen, almacén origen, activo origen,
 *      talla)` vía `ServicioInventario` (lock + revalidación de saldo +
 *      prohibición de negativo viven ahí);
 *   3. registra la ENTRADA en `(empresa destino, almacén destino, activo
 *      destino, talla)`;
 *   4. correlaciona ambos movimientos con el encabezado del traspaso.
 *
 * Por cada unidad de seguimiento individual: se transfiere la MISMA fila
 * `UnidadActivo` (mismo `id`, `codigo`, `public_token`, historial). Sólo
 * cambia el contexto actual (`empresa_id`/`activo_id`/`almacen_id`). Se
 * generan dos movimientos de unidad (salida en contexto origen, entrada en
 * contexto destino).
 *
 * Si CUALQUIER paso falla (existencias insuficientes, unidad no disponible,
 * homologación ambigua…) se revierte TODO — no queda inventario descuadrado
 * ni Activo destino fantasma.
 */
class RegistrarTraspasoInventario
{
    public function __construct(
        private readonly ServicioInventario $inventario,
        private readonly ServicioFolios $folios,
        private readonly ServicioAuditoria $auditoria,
        private readonly HomologadorActivo $homologador,
    ) {}

    /**
     * @param  array<int, array{
     *     control: string,
     *     activo_origen_id: int|string,
     *     talla_id?: int|string|null,
     *     cantidad?: int|string|null,
     *     unidad_ids?: array<int, int|string>|null,
     *     activo_destino_id?: int|string|null,
     * }>  $renglones
     */
    public function ejecutar(
        int $empresaOrigenId,
        int $almacenOrigenId,
        int $empresaDestinoId,
        int $almacenDestinoId,
        array $renglones,
        ?int $realizadoPor,
        ?string $motivo = null,
        ?string $notas = null,
    ): TraspasoInventario {
        $empresaOrigen = Empresa::query()->findOr($empresaOrigenId, fn () => throw new ExcepcionDeNegocioSimple('La empresa origen no existe.'));
        $empresaDestino = Empresa::query()->findOr($empresaDestinoId, fn () => throw new ExcepcionDeNegocioSimple('La empresa destino no existe.'));
        $almacenOrigen = Almacen::query()->findOr($almacenOrigenId, fn () => throw new ExcepcionDeNegocioSimple('El almacén origen no existe.'));
        $almacenDestino = Almacen::query()->findOr($almacenDestinoId, fn () => throw new ExcepcionDeNegocioSimple('El almacén destino no existe.'));

        if (! $almacenOrigen->abasteceEmpresa($empresaOrigen->id) || ! $almacenOrigen->activo) {
            throw new ExcepcionDeNegocioSimple('El almacén origen no abastece a la empresa origen o está desactivado.');
        }
        if (! $almacenDestino->abasteceEmpresa($empresaDestino->id) || ! $almacenDestino->activo) {
            throw new ExcepcionDeNegocioSimple('El almacén destino no abastece a la empresa destino o está desactivado.');
        }
        if ($empresaOrigen->id === $empresaDestino->id && $almacenOrigen->id === $almacenDestino->id) {
            throw new ExcepcionDeNegocioSimple('El almacén origen y el destino no pueden ser el mismo en un traspaso dentro de la misma empresa.');
        }
        if ($renglones === []) {
            throw new ExcepcionDeNegocioSimple('Agrega al menos un renglón al traspaso.');
        }

        return DB::transaction(function () use ($empresaOrigen, $empresaDestino, $almacenOrigen, $almacenDestino, $renglones, $realizadoPor, $motivo, $notas): TraspasoInventario {
            $traspaso = TraspasoInventario::query()->create([
                'folio' => $this->folios->siguiente(ServicioFolios::TRASPASO),
                'tipo' => $empresaOrigen->id === $empresaDestino->id
                    ? TraspasoInventario::TIPO_MISMA_EMPRESA
                    : TraspasoInventario::TIPO_INTEREMPRESA,
                'empresa_origen_id' => $empresaOrigen->id,
                'almacen_origen_id' => $almacenOrigen->id,
                'empresa_destino_id' => $empresaDestino->id,
                'almacen_destino_id' => $almacenDestino->id,
                'estado' => 'completado',
                'motivo' => $motivo,
                'notas' => $notas,
                'realizado_por' => $realizadoPor,
                'ocurrido_en' => now(),
            ]);

            foreach ($renglones as $renglon) {
                $control = TipoControlActivo::from((string) $renglon['control']);
                $activoOrigen = Activo::query()
                    ->where('empresa_id', $empresaOrigen->id)
                    ->findOr((int) $renglon['activo_origen_id'], fn () => throw new ExcepcionDeNegocioSimple('Un activo origen no pertenece a la empresa origen.'));

                if ($activoOrigen->tipo_control !== $control) {
                    throw new ExcepcionDeNegocioSimple('El tipo de control indicado no coincide con el del activo «'.$activoOrigen->nombre.'».');
                }

                $manualDestinoId = ($renglon['activo_destino_id'] ?? null) !== null ? (int) $renglon['activo_destino_id'] : null;

                if ($control === TipoControlActivo::Cantidad) {
                    $this->traspasarCantidad($traspaso, $empresaOrigen, $almacenOrigen, $empresaDestino, $almacenDestino, $activoOrigen, $renglon, $manualDestinoId, $realizadoPor);

                    continue;
                }

                $this->traspasarUnidades($traspaso, $empresaOrigen, $almacenOrigen, $empresaDestino, $almacenDestino, $activoOrigen, $renglon, $manualDestinoId, $realizadoPor);
            }

            $this->auditoria->registrar('inventario', 'traspaso', [
                'tipo_entidad' => TraspasoInventario::class,
                'entidad_id' => $traspaso->getKey(),
                'empresa_id' => $empresaOrigen->id,
                'motivo' => $motivo,
                'descripcion' => sprintf(
                    'Traspaso %s: %s / %s → %s / %s (%d renglón(es)).',
                    $traspaso->folio,
                    $empresaOrigen->nombre_comercial,
                    $almacenOrigen->nombre,
                    $empresaDestino->nombre_comercial,
                    $almacenDestino->nombre,
                    $traspaso->renglones()->count(),
                ),
                'valores_nuevos' => [
                    'folio' => $traspaso->folio,
                    'tipo' => $traspaso->tipo,
                    'empresa_origen' => $empresaOrigen->nombre_comercial,
                    'almacen_origen' => $almacenOrigen->nombre,
                    'empresa_destino' => $empresaDestino->nombre_comercial,
                    'almacen_destino' => $almacenDestino->nombre,
                    'renglones' => $traspaso->renglones()->count(),
                ],
            ]);

            return $traspaso->load('renglones');
        });
    }

    /**
     * @param  array<string, mixed>  $renglon
     */
    private function traspasarCantidad(
        TraspasoInventario $traspaso,
        Empresa $empresaOrigen,
        Almacen $almacenOrigen,
        Empresa $empresaDestino,
        Almacen $almacenDestino,
        Activo $activoOrigen,
        array $renglon,
        ?int $manualDestinoId,
        ?int $realizadoPor,
    ): void {
        $cantidad = (int) ($renglon['cantidad'] ?? 0);
        if ($cantidad < 1) {
            throw new ExcepcionDeNegocioSimple('Indica una cantidad mayor a cero para «'.$activoOrigen->nombre.'».');
        }

        $tallaId = ($renglon['talla_id'] ?? null) !== null ? (int) $renglon['talla_id'] : null;

        // Variante elegible del activo ORIGEN (asociada + activa) — o nula si
        // el activo no usa variantes.
        $elegibles = $activoOrigen->tallasElegibles()->pluck('id')->all();
        if ($elegibles === []) {
            $tallaId = null;
        } elseif ($tallaId === null || ! in_array($tallaId, $elegibles, true)) {
            throw new ExcepcionDeNegocioSimple('Selecciona una variante válida para «'.$activoOrigen->nombre.'».');
        }

        $destino = $this->homologador->resolver($activoOrigen, $empresaDestino->id, $manualDestinoId, $empresaDestino->nombre_comercial);
        $activoDestino = $destino['activo'];

        $tallaValor = $tallaId === null ? null : Talla::query()->whereKey($tallaId)->value('valor');

        $salida = $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
            empresaId: $empresaOrigen->id,
            almacenId: $almacenOrigen->id,
            activoId: $activoOrigen->id,
            tallaId: $tallaId,
            tipo: TipoMovimiento::TraspasoSalida,
            cantidad: $cantidad,
            realizadoPor: $realizadoPor,
            referenciaTipo: TraspasoInventario::class,
            referenciaId: $traspaso->getKey(),
            motivo: 'Traspaso '.$traspaso->folio,
        ));

        $entrada = $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
            empresaId: $empresaDestino->id,
            almacenId: $almacenDestino->id,
            activoId: $activoDestino->id,
            tallaId: $tallaId,
            tipo: TipoMovimiento::TraspasoEntrada,
            cantidad: $cantidad,
            realizadoPor: $realizadoPor,
            referenciaTipo: TraspasoInventario::class,
            referenciaId: $traspaso->getKey(),
            motivo: 'Traspaso '.$traspaso->folio,
        ));

        $traspaso->renglones()->create([
            'control' => TipoControlActivo::Cantidad,
            'activo_origen_id' => $activoOrigen->id,
            'activo_destino_id' => $activoDestino->id,
            'talla_id' => $tallaId,
            'cantidad' => $cantidad,
            'activo_origen_nombre_snapshot' => $activoOrigen->nombre,
            'activo_destino_nombre_snapshot' => $activoDestino->nombre,
            'talla_valor_snapshot' => $tallaValor,
            'activo_destino_creado' => $destino['creado'],
            'movimiento_salida_id' => $salida->id,
            'movimiento_entrada_id' => $entrada->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $renglon
     */
    private function traspasarUnidades(
        TraspasoInventario $traspaso,
        Empresa $empresaOrigen,
        Almacen $almacenOrigen,
        Empresa $empresaDestino,
        Almacen $almacenDestino,
        Activo $activoOrigen,
        array $renglon,
        ?int $manualDestinoId,
        ?int $realizadoPor,
    ): void {
        $unidadIds = array_values(array_unique(array_map('intval', is_array($renglon['unidad_ids'] ?? null) ? $renglon['unidad_ids'] : [])));
        if ($unidadIds === []) {
            throw new ExcepcionDeNegocioSimple('Selecciona al menos una unidad de «'.$activoOrigen->nombre.'».');
        }

        $destino = $this->homologador->resolver($activoOrigen, $empresaDestino->id, $manualDestinoId, $empresaDestino->nombre_comercial);
        $activoDestino = $destino['activo'];

        foreach ($unidadIds as $unidadId) {
            /** @var UnidadActivo|null $unidad */
            $unidad = UnidadActivo::query()->whereKey($unidadId)->lockForUpdate()->first();

            if ($unidad === null
                || $unidad->empresa_id !== $empresaOrigen->id
                || $unidad->activo_id !== $activoOrigen->id
                || $unidad->almacen_id !== $almacenOrigen->id
                || $unidad->estado !== EstadoUnidadActivo::EnAlmacen
                || $unidad->condicion !== CondicionUnidadActivo::Funcionando
            ) {
                throw new ExcepcionDeNegocioSimple('Una de las unidades seleccionadas ya no está disponible en el almacén origen (fue asignada, movida o dejó de ser operativa).');
            }

            $codigoSnapshot = $unidad->codigo;

            // SALIDA: se registra ANTES de mutar la unidad, para que el
            // movimiento capture el contexto origen (empresa/almacén/activo).
            $salida = $this->inventario->registrarMovimientoUnidad(
                $unidad,
                TipoMovimiento::TraspasoSalida,
                $realizadoPor,
                TraspasoInventario::class,
                $traspaso->getKey(),
                'Traspaso '.$traspaso->folio,
            );

            // La MISMA fila física: sólo cambia el contexto actual. `codigo`,
            // `public_token`, `id` e historial permanecen intactos (decisión
            // de negocio: identidad física estable de por vida).
            $unidad->update([
                'empresa_id' => $empresaDestino->id,
                'activo_id' => $activoDestino->id,
                'almacen_id' => $almacenDestino->id,
            ]);

            $entrada = $this->inventario->registrarMovimientoUnidad(
                $unidad->refresh(),
                TipoMovimiento::TraspasoEntrada,
                $realizadoPor,
                TraspasoInventario::class,
                $traspaso->getKey(),
                'Traspaso '.$traspaso->folio,
            );

            $traspaso->renglones()->create([
                'control' => TipoControlActivo::SeguimientoIndividual,
                'activo_origen_id' => $activoOrigen->id,
                'activo_destino_id' => $activoDestino->id,
                'unidad_activo_id' => $unidad->id,
                'cantidad' => 1,
                'activo_origen_nombre_snapshot' => $activoOrigen->nombre,
                'activo_destino_nombre_snapshot' => $activoDestino->nombre,
                'activo_destino_creado' => $destino['creado'],
                'unidad_codigo_snapshot' => $codigoSnapshot,
                'movimiento_salida_id' => $salida->id,
                'movimiento_entrada_id' => $entrada->id,
            ]);
        }
    }
}
