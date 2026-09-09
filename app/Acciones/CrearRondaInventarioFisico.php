<?php

namespace App\Acciones;

use App\Enums\EstadoInventarioFisico;
use App\Enums\EstadoUnidadActivo;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\InventarioFisico;
use App\Models\UnidadActivo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioFolios;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Inicia una ronda de inventario físico y CONGELA su universo esperado en el
 * mismo instante (snapshot lógico): a partir de aquí, una unidad creada más
 * tarde nunca infla el total esperado — se registrará como "no esperada" si se
 * escanea. Los renglones del snapshot se insertan por chunks para soportar
 * rondas de miles de unidades sin N inserts individuales.
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
        ?Almacen $almacen,
        ?string $observaciones,
        ?int $usuarioId,
    ): InventarioFisico {
        if ($almacen !== null && ! $almacen->abasteceEmpresa($empresa->id)) {
            throw new ExcepcionDeNegocioSimple('El almacén seleccionado no abastece a esta empresa.');
        }

        return DB::transaction(function () use ($empresa, $nombre, $almacen, $observaciones, $usuarioId): InventarioFisico {
            $ronda = InventarioFisico::query()->create([
                'empresa_id' => $empresa->id,
                'usuario_id' => $usuarioId,
                'almacen_id' => $almacen?->id,
                'folio' => $this->folios->siguiente(ServicioFolios::INVENTARIO_FISICO),
                'nombre' => $nombre,
                'estado' => EstadoInventarioFisico::EnProceso,
                'observaciones' => $observaciones,
            ]);

            $ahora = now();

            self::universo($empresa->id, $almacen?->id)
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

            $this->auditoria->registrar('inventario_fisico', 'ronda_crear', [
                'empresa_id' => $empresa->id,
                'tipo_entidad' => InventarioFisico::class,
                'entidad_id' => $ronda->id,
                'descripcion' => 'Alta de ronda de inventario físico «'.$ronda->nombre.'» ('.$ronda->folio.').',
            ]);

            return $ronda;
        });
    }

    /**
     * ÚNICA definición del universo esperado, reutilizada por el snapshot y por
     * la previsualización de "cuántas unidades entrarán". Regla:
     *
     * - Unidad de la empresa (y, si la ronda tiene alcance de almacén, cuyo
     *   almacén "de casa" es ése — una unidad asignada a un colaborador conserva
     *   su `almacen_id`, así que no se excluye).
     * - Se EXCLUYEN las unidades en BAJA (retiradas): no se espera encontrarlas.
     * - Se INCLUYEN las perdidas / robadas / en reparación / inservibles: son
     *   parte del reporte de diferencias (encontrarlas físicamente, o confirmar
     *   que faltan, es justo lo que interesa).
     *
     * @return Builder<UnidadActivo>
     */
    public static function universo(int $empresaId, ?int $almacenId): Builder
    {
        return UnidadActivo::query()
            ->where('empresa_id', $empresaId)
            ->where('estado', '!=', EstadoUnidadActivo::Baja->value)
            ->when($almacenId !== null, fn (Builder $q) => $q->where('almacen_id', $almacenId));
    }
}
