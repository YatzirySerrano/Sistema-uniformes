<?php

namespace App\Acciones;

use App\Enums\TipoControlActivo;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\UnidadActivo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Alta unificada de Activo + existencia inicial (redefinición funcional):
 * "Crear Activo" y "Registrar entrada" dejan de ser dos pasos separados. El
 * almacén elegido aquí es sólo el ALMACÉN DE ENTRADA INICIAL — no una
 * propiedad permanente del Activo (`activos.almacen_id` no existe).
 *
 * Dentro de una única transacción: 1) crea el Activo; 2) asocia variantes;
 * 3) si es por cantidad, registra la entrada inicial vía
 * `RegistrarEntradaInventario` (reutilizada tal cual, motivo "Alta inicial
 * del activo"); 4) si es de seguimiento individual, genera las unidades vía
 * `RegistrarUnidadesActivo` (códigos autogenerados, sin saldo agregado).
 * Nunca se asigna `saldo = cantidad` directamente. Si el stock falla, todo se
 * revierte y el Activo no queda huérfano.
 */
class CrearActivoConExistencias
{
    public function __construct(
        private readonly RegistrarEntradaInventario $registrarEntrada,
        private readonly RegistrarUnidadesActivo $registrarUnidades,
    ) {}

    /**
     * @param  array<string, mixed>  $datosActivo  columnas de `activos` (sin empresa_id, se pasa aparte)
     * @param  array<int, int>  $tallaIds  variantes asociadas al activo (activo_talla, sólo control=cantidad)
     * @param  array<int, array{talla_id: int|null, cantidad: int}>  $existenciaInicial  filas de stock inicial (sólo control=cantidad)
     * @return array{activo: Activo, unidades: Collection<int, UnidadActivo>}
     */
    public function ejecutar(
        int $empresaId,
        array $datosActivo,
        array $tallaIds,
        ?int $almacenId,
        array $existenciaInicial,
        int $cantidadUnidades,
        ?int $realizadoPor,
    ): array {
        return DB::transaction(function () use ($empresaId, $datosActivo, $tallaIds, $almacenId, $existenciaInicial, $cantidadUnidades, $realizadoPor): array {
            $activo = Activo::query()->create([...$datosActivo, 'empresa_id' => $empresaId]);
            $unidades = new Collection;

            if ($activo->tipo_control === TipoControlActivo::Cantidad) {
                $activo->tallas()->sync($tallaIds);

                $items = array_values(array_map(
                    fn (array $item): array => ['activo_id' => $activo->id, ...$item],
                    array_filter($existenciaInicial, fn (array $item): bool => (int) $item['cantidad'] > 0),
                ));

                if ($almacenId !== null && $items !== []) {
                    $this->registrarEntrada->ejecutar(
                        empresaId: $empresaId,
                        almacenId: $almacenId,
                        items: $items,
                        motivo: 'Alta inicial del activo',
                        realizadoPor: $realizadoPor,
                        cargaInicial: true,
                    );
                }
            } elseif ($almacenId !== null && $cantidadUnidades > 0) {
                $unidades = $this->registrarUnidades->ejecutar(
                    empresa: Empresa::query()->findOrFail($empresaId),
                    activo: $activo,
                    almacen: Almacen::query()->findOrFail($almacenId),
                    cantidad: $cantidadUnidades,
                    motivo: 'Alta inicial del activo',
                    realizadoPor: $realizadoPor,
                    cargaInicial: true,
                );
            }

            return ['activo' => $activo, 'unidades' => $unidades];
        });
    }
}
