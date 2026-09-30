<?php

namespace App\Acciones;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoInventarioFisico;
use App\Enums\EstadoUnidadActivo;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\InventarioFisico;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioFolios;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Inicia una ronda de inventario físico y CONGELA su universo esperado en el
 * mismo instante (snapshot lógico). El alcance es SIEMPRE un almacén concreto:
 * la ronda comprueba "lo que debería estar físicamente en ESTE almacén".
 *
 * Se congelan dos cosas:
 *  - Unidades identificadas (QR) físicamente guardadas en ese almacén (disponibles,
 *    en reparación o inservibles; nunca asignadas, perdidas, robadas ni de baja)
 *    → `inventario_fisico_unidades`.
 *  - Existencias por cantidad (prendas, sin QR) → `inventario_fisico_existencias`,
 *    con `cantidad_esperada` tomada de `saldos_inventario` en ese instante.
 *
 * A partir de aquí nada del sistema cambia el esperado: una unidad creada o
 * movida después, o un cambio de stock, no alteran esta ronda.
 */
class CrearRondaInventarioFisico
{
    public function __construct(
        private readonly ServicioFolios $folios,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    public function ejecutar(
        Empresa $empresa,
        string $nombre,
        Almacen $almacen,
        ?string $observaciones,
        ?int $usuarioId,
    ): InventarioFisico {
        if (! $almacen->abasteceEmpresa($empresa->id)) {
            throw new ExcepcionDeNegocioSimple('El almacén seleccionado no abastece a esta empresa.');
        }

        return DB::transaction(function () use ($empresa, $nombre, $almacen, $observaciones, $usuarioId): InventarioFisico {
            $ronda = InventarioFisico::query()->create([
                'empresa_id' => $empresa->id,
                'usuario_id' => $usuarioId,
                'almacen_id' => $almacen->id,
                'folio' => $this->folios->siguiente(ServicioFolios::INVENTARIO_FISICO),
                'nombre' => $nombre,
                'estado' => EstadoInventarioFisico::EnProceso,
                'observaciones' => $observaciones,
            ]);

            $ahora = now();

            self::universo($empresa->id, $almacen->id)
                ->orderBy('id')
                ->pluck('id')
                ->chunk(1000)
                ->each(function ($chunk) use ($ronda, $ahora): void {
                    DB::table('inventario_fisico_unidades')->insert(
                        $chunk->map(fn ($unidadId): array => [
                            'inventario_fisico_id' => $ronda->id,
                            'unidad_activo_id' => (int) $unidadId,
                            'esperada' => true,
                            'escaneado_en' => null,
                            'escaneado_por' => null,
                            'created_at' => $ahora,
                            'updated_at' => $ahora,
                        ])->all()
                    );
                });

            SaldoInventario::query()
                ->where('empresa_id', $empresa->id)
                ->where('almacen_id', $almacen->id)
                ->where('cantidad', '>', 0)
                ->orderBy('id')
                ->get(['activo_id', 'talla_id', 'cantidad'])
                ->chunk(1000)
                ->each(function ($chunk) use ($ronda, $ahora): void {
                    DB::table('inventario_fisico_existencias')->insert(
                        $chunk->map(fn (SaldoInventario $s): array => [
                            'inventario_fisico_id' => $ronda->id,
                            'activo_id' => $s->activo_id,
                            'talla_id' => $s->talla_id,
                            'cantidad_esperada' => (int) $s->cantidad,
                            'cantidad_contada' => null,
                            'verificada_por' => null,
                            'verificada_en' => null,
                            'created_at' => $ahora,
                            'updated_at' => $ahora,
                        ])->all()
                    );
                });

            $this->auditoria->registrar('inventario_fisico', 'ronda_crear', [
                'empresa_id' => $empresa->id,
                'tipo_entidad' => InventarioFisico::class,
                'entidad_id' => $ronda->id,
                'descripcion' => 'Alta de ronda de inventario físico «'.$ronda->nombre.'» ('.$ronda->folio.') en el almacén '.$almacen->nombre.'.',
            ]);

            return $ronda;
        });
    }

    /**
     * ÚNICA definición del universo de unidades identificadas ESPERADAS,
     * reutilizada por el snapshot y por la previsualización. El alcance de
     * una ronda es UN ALMACÉN (toma física de ese lugar), así que sólo entran
     * las unidades que deberían estar físicamente dentro de él:
     *
     * - de la empresa y del almacén de la ronda;
     * - `estado = En almacén` y sin colaborador: una unidad ASIGNADA está
     *   bajo la custodia de una persona fuera del almacén — contarla aquí
     *   falsearía su ubicación (su almacén_id sólo es la procedencia);
     * - condición Funcionando, En reparación o Inservible: siguen siendo
     *   piezas físicas guardadas en el almacén aunque no sean entregables;
     * - nunca Perdido / Robado (no están físicamente) ni Baja (retirada
     *   definitivamente; `estado = baja` ya la excluye).
     *
     * Sólo afecta a rondas nuevas — las históricas conservan su snapshot.
     *
     * @return Builder<UnidadActivo>
     */
    public static function universo(int $empresaId, int $almacenId): Builder
    {
        return UnidadActivo::query()
            ->where('empresa_id', $empresaId)
            ->where('almacen_id', $almacenId)
            ->where('estado', EstadoUnidadActivo::EnAlmacen->value)
            ->whereIn('condicion', array_map(fn (CondicionUnidadActivo $c): string => $c->value, self::condicionesFisicamentePresentes()))
            ->whereNull('colaborador_id');
    }

    /**
     * Condiciones de una unidad que sigue FÍSICAMENTE en el almacén (aunque
     * no sea entregable). Perdido y Robado quedan fuera: no hay pieza que
     * contar.
     *
     * @return list<CondicionUnidadActivo>
     */
    public static function condicionesFisicamentePresentes(): array
    {
        return [
            CondicionUnidadActivo::Funcionando,
            CondicionUnidadActivo::EnReparacion,
            CondicionUnidadActivo::Inservible,
        ];
    }
}
