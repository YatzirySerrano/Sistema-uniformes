<?php

namespace App\Acciones;

use App\Enums\EstadoEntrega;
use App\Enums\TipoControlActivo;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\Conjunto;
use App\Models\ConjuntoComponente;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\Talla;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ResolverAlmacenOperativo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioFolios;
use App\Servicios\ServicioInventario;
use App\Servicios\ServicioUnidadesActivo;
use Illuminate\Support\Facades\DB;

/**
 * Registra una entrega combinando, en cualquier mezcla: activos sueltos por
 * cantidad+variante, unidades de seguimiento individual elegidas
 * explícitamente, y conjuntos (que expanden a sus componentes REALES — el
 * stock se valida y descuenta por componente, nunca "stock del conjunto").
 * Cada renglón de la entrega mueve stock real de forma independiente dentro
 * de una única transacción; si cualquiera falla (existencias insuficientes,
 * unidad ya no entregable, etc.) no se registra nada.
 */
class CrearEntregaUniforme
{
    public function __construct(
        private readonly ServicioInventario $inventario,
        private readonly ServicioUnidadesActivo $unidadesActivo,
        private readonly ServicioFolios $folios,
        private readonly ServicioAuditoria $auditoria,
        private readonly ResolverAlmacenOperativo $resolverAlmacen,
    ) {}

    /**
     * @param  array<int, array{activo_id: int|string, talla_id?: int|string|null, cantidad: int|string}>  $activos
     * @param  array<int, array{unidad_activo_id: int|string}>  $unidades
     * @param  array<int, array{conjunto_id: int|string, cantidad: int|string, variantes?: array<int|string, int|string|null>}>  $conjuntos
     */
    public function ejecutar(
        int $colaboradorId,
        int $almacenId,
        int $encargadoId,
        string $fechaEntrega,
        array $activos,
        array $unidades,
        array $conjuntos,
        ?string $notas = null,
        ?int $servicioId = null,
    ): EntregaUniforme {
        $colaborador = Colaborador::query()->findOr($colaboradorId, fn () => throw new ExcepcionDeNegocioSimple('El colaborador indicado no existe.'));

        if (! $colaborador->activo) {
            throw new ExcepcionDeNegocioSimple('El colaborador está inactivo y no puede recibir entregas.');
        }

        $empresaId = $colaborador->empresa_id;
        $sucursalId = $colaborador->sucursal_id;
        $almacen = $this->resolverAlmacen->paraEmpresa(Empresa::query()->findOrFail($empresaId), $almacenId);

        $activosConsolidados = $this->consolidarActivos($activos);
        $unidadIds = array_values(array_unique(array_map(fn (array $u): int => (int) $u['unidad_activo_id'], $unidades)));

        if ($activosConsolidados === [] && $unidadIds === [] && $conjuntos === []) {
            throw new ExcepcionDeNegocioSimple('Agrega al menos un activo, unidad identificada o conjunto a la entrega.');
        }

        return DB::transaction(function () use ($empresaId, $sucursalId, $almacen, $colaborador, $encargadoId, $fechaEntrega, $activosConsolidados, $unidadIds, $conjuntos, $notas, $servicioId): EntregaUniforme {
            $entrega = EntregaUniforme::query()->create([
                'folio' => $this->folios->siguiente(ServicioFolios::ENTREGA),
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'almacen_id' => $almacen->getKey(),
                // Snapshot histórico del servicio de destino de ESTA entrega —
                // se guarda tal cual llegó validado, sin derivarlo ni
                // sincronizarlo con `colaborador->servicio_actual_id` (eso es
                // una acción independiente, ver `CambiarServicioColaborador`).
                'servicio_id' => $servicioId,
                'colaborador_id' => $colaborador->getKey(),
                'encargado_id' => $encargadoId,
                'estado' => EstadoEntrega::PendienteFirma,
                'fecha_entrega' => $fechaEntrega,
                'notas' => $notas,
            ]);

            $unidadesUsadas = [];

            foreach ($activosConsolidados as $item) {
                $this->registrarComponenteCantidad($entrega, $empresaId, $sucursalId, $almacen->getKey(), $encargadoId, $item['activo_id'], $item['talla_id'], $item['cantidad']);
            }

            foreach ($unidadIds as $unidadId) {
                $unidad = $this->unidadesActivo->bloquearYVerificarEntregable($unidadId);
                $this->validarUnidadParaEntrega($unidad, $empresaId, $almacen->getKey());
                $this->registrarComponenteUnidad($entrega, $sucursalId, $encargadoId, $unidad);
                $unidadesUsadas[] = $unidad->id;
            }

            foreach ($conjuntos as $fila) {
                $this->expandirConjunto($entrega, $empresaId, $sucursalId, $almacen->getKey(), $encargadoId, $fila, $unidadesUsadas);
            }

            $this->auditoria->registrar('entregas', 'crear', [
                'tipo_entidad' => EntregaUniforme::class,
                'entidad_id' => $entrega->getKey(),
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'descripcion' => 'Entrega '.$entrega->folio.' registrada para '.$colaborador->nombre_completo,
                'valores_nuevos' => $entrega->load('detalles')->toArray(),
            ]);

            return $entrega->load(['detalles', 'colaborador', 'sucursal', 'encargado']);
        });
    }

    private function registrarComponenteCantidad(EntregaUniforme $entrega, int $empresaId, int $sucursalId, int $almacenId, int $encargadoId, int $activoId, ?int $tallaId, int $cantidad, ?int $conjuntoId = null, ?string $conjuntoNombre = null): void
    {
        $activo = Activo::query()->where('empresa_id', $empresaId)->where('tipo_control', TipoControlActivo::Cantidad)->where('activo', true)
            ->findOr($activoId, fn () => throw new ExcepcionDeNegocioSimple('Uno de los activos seleccionados no pertenece a esta empresa o ya no está disponible.'));

        $tallaValor = null;
        if ($tallaId !== null) {
            $tallaValor = Talla::query()->where('activa', true)->findOr($tallaId, fn () => throw new ExcepcionDeNegocioSimple('Una de las variantes seleccionadas no es válida.'))->valor;
        }

        $entrega->detalles()->create([
            'activo_id' => $activo->id,
            'talla_id' => $tallaId,
            'cantidad' => $cantidad,
            'activo_nombre_snapshot' => $activo->nombre,
            'talla_valor_snapshot' => $tallaValor,
            'conjunto_id' => $conjuntoId,
            'conjunto_nombre_snapshot' => $conjuntoNombre,
        ]);

        $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
            empresaId: $empresaId,
            almacenId: $almacenId,
            activoId: $activo->id,
            tallaId: $tallaId,
            tipo: TipoMovimiento::Entrega,
            cantidad: $cantidad,
            realizadoPor: $encargadoId,
            referenciaTipo: EntregaUniforme::class,
            referenciaId: $entrega->getKey(),
            motivo: 'Entrega '.$entrega->folio,
            sucursalId: $sucursalId,
        ));
    }

    private function registrarComponenteUnidad(EntregaUniforme $entrega, int $sucursalId, int $encargadoId, UnidadActivo $unidad, ?int $conjuntoId = null, ?string $conjuntoNombre = null): void
    {
        $entrega->detalles()->create([
            'activo_id' => $unidad->activo_id,
            'talla_id' => null,
            'unidad_activo_id' => $unidad->id,
            'cantidad' => 1,
            'activo_nombre_snapshot' => $unidad->activo->nombre,
            'talla_valor_snapshot' => null,
            'conjunto_id' => $conjuntoId,
            'conjunto_nombre_snapshot' => $conjuntoNombre,
        ]);

        $this->unidadesActivo->asignar($unidad, $entrega->colaborador_id, $encargadoId, EntregaUniforme::class, $entrega->getKey(), 'Entrega '.$entrega->folio, $sucursalId);
    }

    private function validarUnidadParaEntrega(UnidadActivo $unidad, int $empresaId, int $almacenId): void
    {
        if ($unidad->empresa_id !== $empresaId) {
            throw new ExcepcionDeNegocioSimple('Una de las unidades seleccionadas no pertenece a esta empresa.');
        }

        if ($unidad->almacen_id !== $almacenId) {
            throw new ExcepcionDeNegocioSimple('Una de las unidades seleccionadas no está en el almacén de origen elegido.');
        }
    }

    /**
     * @param  array{conjunto_id: int|string, cantidad: int|string, variantes?: array<int|string, int|string|null>}  $fila
     * @param  array<int, int>  $unidadesUsadas
     */
    private function expandirConjunto(EntregaUniforme $entrega, int $empresaId, int $sucursalId, int $almacenId, int $encargadoId, array $fila, array &$unidadesUsadas): void
    {
        $conjunto = Conjunto::query()->where('empresa_id', $empresaId)->where('activo', true)->with('componentes')
            ->findOr((int) $fila['conjunto_id'], fn () => throw new ExcepcionDeNegocioSimple('Uno de los conjuntos seleccionados no pertenece a esta empresa.'));

        $cantidadConjuntos = (int) $fila['cantidad'];
        $variantesElegidas = $fila['variantes'] ?? [];

        if ($conjunto->disponibilidad($almacenId) < $cantidadConjuntos) {
            throw new ExcepcionDeNegocioSimple("No hay suficiente disponibilidad del conjunto «{$conjunto->nombre}» en el almacén seleccionado.");
        }

        foreach ($conjunto->componentes as $componente) {
            /** @var ConjuntoComponente $componente */
            $cantidadNecesaria = $componente->cantidad_requerida * $cantidadConjuntos;
            $activo = Activo::query()->findOrFail($componente->activo_id);

            $tallaId = match (true) {
                $componente->talla_id !== null => $componente->talla_id,
                $componente->talla_libre => isset($variantesElegidas[$componente->id]) ? (int) $variantesElegidas[$componente->id] : null,
                default => null,
            };

            if ($activo->tipo_control === TipoControlActivo::Cantidad) {
                $this->registrarComponenteCantidad($entrega, $empresaId, $sucursalId, $almacenId, $encargadoId, $activo->id, $tallaId, $cantidadNecesaria, $conjunto->id, $conjunto->nombre);

                continue;
            }

            $reservadas = $this->unidadesActivo->reservarDisponibles($activo->id, $almacenId, $cantidadNecesaria, $unidadesUsadas);

            foreach ($reservadas as $unidad) {
                $this->registrarComponenteUnidad($entrega, $sucursalId, $encargadoId, $unidad, $conjunto->id, $conjunto->nombre);
                $unidadesUsadas[] = $unidad->id;
            }
        }
    }

    /**
     * Suma cantidades de activos sueltos repetidos (mismo activo + talla) y
     * descarta los de cantidad no positiva.
     *
     * @param  array<int, array{activo_id: int|string, talla_id?: int|string|null, cantidad: int|string}>  $activos
     * @return array<int, array{activo_id: int, talla_id: int|null, cantidad: int}>
     */
    private function consolidarActivos(array $activos): array
    {
        $mapa = [];

        foreach ($activos as $item) {
            $cantidad = (int) $item['cantidad'];

            if ($cantidad <= 0) {
                continue;
            }

            $tallaId = ($item['talla_id'] ?? null) !== null ? (int) $item['talla_id'] : null;
            $clave = $item['activo_id'].'-'.($tallaId ?? '0');
            $mapa[$clave] ??= ['activo_id' => (int) $item['activo_id'], 'talla_id' => $tallaId, 'cantidad' => 0];
            $mapa[$clave]['cantidad'] += $cantidad;
        }

        return array_values($mapa);
    }
}
