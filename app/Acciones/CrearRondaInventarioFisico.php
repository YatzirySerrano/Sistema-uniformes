<?php

namespace App\Acciones;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoInventarioFisico;
use App\Enums\EstadoUnidadActivo;
use App\Models\Empresa;
use App\Models\InventarioFisico;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioFolios;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Inicia una ronda de inventario físico INTEGRAL de una empresa y CONGELA su
 * universo esperado en el mismo instante (snapshot lógico). Una sola ronda
 * responde "¿pude comprobar que existe?" para todo lo de la empresa:
 *
 *  - Existencias POR CANTIDAD de todos los almacenes que abastecen a la
 *    empresa → `inventario_fisico_existencias`, UN renglón por almacén +
 *    activo + variante (nunca se suman almacenes: la diferencia debe poder
 *    corregirse en el almacén donde ocurrió).
 *  - Unidades identificadas verificables → `inventario_fisico_unidades`
 *    (en almacén o asignadas; funcionando, en reparación o inservibles).
 *
 * `inventarios_fisicos.almacen_id` queda NULL en rondas nuevas (las
 * históricas de un almacén lo conservan). A partir de aquí nada del sistema
 * cambia el esperado: una unidad creada o movida después, o un cambio de
 * stock, no alteran esta ronda. La ronda sólo VERIFICA: jamás cambia
 * custodio, almacén, estado ni condición.
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
        ?string $observaciones,
        ?int $usuarioId,
    ): InventarioFisico {
        return DB::transaction(function () use ($empresa, $nombre, $observaciones, $usuarioId): InventarioFisico {
            $ronda = InventarioFisico::query()->create([
                'empresa_id' => $empresa->id,
                // Quien INICIA la ronda; cualquier usuario autorizado de la
                // empresa puede colaborar en ella (Policy `administrar`).
                'usuario_id' => $usuarioId,
                'almacen_id' => null,
                'folio' => $this->folios->siguiente(ServicioFolios::INVENTARIO_FISICO),
                'nombre' => $nombre,
                'estado' => EstadoInventarioFisico::EnProceso,
                'observaciones' => $observaciones,
            ]);

            $ahora = now();

            self::universo($empresa->id)
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

            self::universoExistencias($empresa->id)
                ->orderBy('almacen_id')
                ->orderBy('id')
                ->get(['almacen_id', 'activo_id', 'talla_id', 'cantidad'])
                ->chunk(1000)
                ->each(function ($chunk) use ($ronda, $ahora): void {
                    DB::table('inventario_fisico_existencias')->insert(
                        $chunk->map(fn (SaldoInventario $s): array => [
                            'inventario_fisico_id' => $ronda->id,
                            'almacen_id' => $s->almacen_id,
                            'activo_id' => $s->activo_id,
                            'talla_id' => $s->talla_id,
                            'cantidad_esperada' => (int) $s->cantidad,
                            'cantidad_contada' => null,
                            'verificada_por' => null,
                            'verificada_en' => null,
                            'created_at' => $ahora,
                            'updated_at' => $ahora,
                        ])->values()->all()
                    );
                });

            $this->auditoria->registrar('inventario_fisico', 'ronda_crear', [
                'empresa_id' => $empresa->id,
                'tipo_entidad' => InventarioFisico::class,
                'entidad_id' => $ronda->id,
                'descripcion' => 'Alta de ronda de inventario físico «'.$ronda->nombre.'» ('.$ronda->folio.') de toda la empresa (existencias por cantidad de sus almacenes y unidades identificadas).',
            ]);

            return $ronda;
        });
    }

    /**
     * ÚNICA definición del universo de unidades identificadas ESPERADAS,
     * reutilizada por el snapshot y por la previsualización: unidades de la
     * empresa en almacén o asignadas, en una condición en la que la pieza
     * sigue existiendo (`condicionesFisicamentePresentes()`). Nunca Perdido /
     * Robado ni `estado = baja`. La ubicación operativa (almacén o
     * colaborador) se muestra por renglón; nunca se presenta una asignada
     * como si estuviera en un almacén.
     *
     * @return Builder<UnidadActivo>
     */
    public static function universo(int $empresaId): Builder
    {
        return UnidadActivo::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('estado', [EstadoUnidadActivo::EnAlmacen->value, EstadoUnidadActivo::Asignada->value])
            ->whereIn('condicion', array_map(fn (CondicionUnidadActivo $c): string => $c->value, self::condicionesFisicamentePresentes()));
    }

    /**
     * ÚNICA definición del universo de existencias por cantidad: saldos con
     * existencia de la empresa en los almacenes que la abastecen, uno por
     * almacén + activo + variante (tal cual `saldos_inventario`).
     *
     * @return Builder<SaldoInventario>
     */
    public static function universoExistencias(int $empresaId): Builder
    {
        return SaldoInventario::query()
            ->where('empresa_id', $empresaId)
            ->where('cantidad', '>', 0)
            ->whereIn('almacen_id', DB::table('almacen_empresa')->where('empresa_id', $empresaId)->select('almacen_id'));
    }

    /**
     * Condiciones de una unidad que sigue existiendo FÍSICAMENTE (aunque no
     * sea entregable). Perdido y Robado quedan fuera: no hay pieza que
     * comprobar.
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
