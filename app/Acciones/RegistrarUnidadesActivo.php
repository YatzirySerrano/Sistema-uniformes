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
use App\Models\UnidadActivo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioInventario;
use App\Soporte\NormalizadorNombre;
use App\Soporte\ServicioGeneradorCodigosGlobal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta de N unidades de seguimiento individual (identificación individual).
 * El usuario NUNCA captura el código: lo genera el sistema (race-safe, único,
 * estable). El código VISIBLE es `NOMBRE-ACTIVO-000001` con secuencia GLOBAL
 * por nombre normalizado (`unidad:{slug}` en `secuencias_codigo_globales`) —
 * independiente de Empresa/Sucursal/Almacén: dos empresas con un activo "Tablet"
 * comparten la misma secuencia `TABLET-######` y nunca colisionan. El
 * `public_token` y el QR NO dependen del código. Cada unidad genera un
 * movimiento propio vía `ServicioInventario::registrarMovimientoUnidad()`, que
 * NO toca `saldos_inventario`. Si una unidad falla, toda la operación se
 * revierte (atómica).
 */
class RegistrarUnidadesActivo
{
    public function __construct(
        private readonly ServicioGeneradorCodigosGlobal $codigos,
        private readonly ServicioInventario $inventario,
        private readonly ServicioAuditoria $auditoria,
    ) {}

    /**
     * @return Collection<int, UnidadActivo>
     */
    public function ejecutar(
        Empresa $empresa,
        Activo $activo,
        Almacen $almacen,
        int $cantidad,
        string $motivo,
        ?int $realizadoPor,
        bool $cargaInicial = false,
    ): Collection {
        if ($activo->empresa_id !== $empresa->id) {
            throw new ExcepcionDeNegocioSimple('El activo no pertenece a esta empresa.');
        }
        if ($activo->tipo_control !== TipoControlActivo::SeguimientoIndividual) {
            throw new ExcepcionDeNegocioSimple('Este activo no usa seguimiento individual.');
        }
        if (! $almacen->abasteceEmpresa($empresa->id)) {
            throw new ExcepcionDeNegocioSimple('El almacén indicado no abastece a esta empresa.');
        }
        if (! $almacen->activo) {
            throw new ExcepcionDeNegocioSimple('El almacén está desactivado; no admite altas de unidades.');
        }
        if ($cantidad < 1) {
            throw new ExcepcionDeNegocioSimple('Indica una cantidad de unidades mayor a cero.');
        }

        $slug = NormalizadorNombre::codigoActivo($activo->nombre);

        return DB::transaction(function () use ($empresa, $activo, $almacen, $cantidad, $motivo, $realizadoPor, $cargaInicial, $slug): Collection {
            $unidades = new Collection;

            for ($i = 0; $i < $cantidad; $i++) {
                $unidad = UnidadActivo::query()->create([
                    'empresa_id' => $empresa->id,
                    'activo_id' => $activo->id,
                    'almacen_id' => $almacen->id,
                    'codigo' => $this->codigos->siguiente("unidad:{$slug}", $slug, 6),
                    'public_token' => (string) Str::uuid(),
                    'estado' => EstadoUnidadActivo::EnAlmacen,
                    'condicion' => CondicionUnidadActivo::Funcionando,
                    'registrado_por' => $realizadoPor,
                ]);

                $this->inventario->registrarMovimientoUnidad(
                    unidad: $unidad,
                    tipo: $cargaInicial ? TipoMovimiento::Inicial : TipoMovimiento::Entrada,
                    realizadoPor: $realizadoPor,
                    referenciaTipo: 'alta_unidad',
                    motivo: $motivo,
                );

                $unidades->push($unidad);
            }

            $this->auditoria->registrar('inventario', $cargaInicial ? 'unidades_carga_inicial' : 'unidades_alta', [
                'empresa_id' => $empresa->id,
                'descripcion' => $unidades->count().' unidad(es) de seguimiento individual creadas para «'.$activo->nombre.'». Motivo: '.$motivo,
            ]);

            return $unidades;
        });
    }
}
