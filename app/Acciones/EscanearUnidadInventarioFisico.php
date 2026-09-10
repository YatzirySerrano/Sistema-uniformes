<?php

namespace App\Acciones;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoUnidad;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Servicios\ServicioResumenInventarioFisico;
use App\Soporte\ResolvedorUnidadEscaneada;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Registra el escaneo de una unidad dentro de una ronda EN PROCESO. El
 * backend es la única fuente de verdad de la clasificación (encontrada / ya
 * escaneada / no esperada / rechazada); el frontend nunca decide si "coincide".
 *
 * Multiempresa: la empresa la fija la RONDA, nunca el cliente. Una unidad de
 * otra empresa se rechaza sin revelar ningún dato de ella.
 *
 * Doble escaneo / concurrencia: el `UNIQUE (inventario_fisico_id,
 * unidad_activo_id)` es la protección real; aquí se añade un `lockForUpdate`
 * puntual sobre el renglón (nunca sobre la ronda entera) y un catch de la
 * violación de unicidad para dos dispositivos escaneando a la vez.
 */
class EscanearUnidadInventarioFisico
{
    public function __construct(
        private readonly ResolvedorUnidadEscaneada $resolvedor,
        private readonly ServicioResumenInventarioFisico $resumen,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function ejecutar(InventarioFisico $ronda, string $entrada, User $usuario): array
    {
        // Feedback rápido; la comprobación AUTORITATIVA del estado vive dentro
        // de `registrarEscaneo`, bajo el lock de la ronda (F16-bis).
        if (! $ronda->estaEnProceso()) {
            throw new ExcepcionDeNegocioSimple('Esta ronda ya fue finalizada; no admite más escaneos.');
        }

        $identificador = $this->resolvedor->resolver($entrada);

        if ($identificador === null) {
            throw new ExcepcionDeNegocioSimple('El código escaneado no tiene un formato válido.');
        }

        $columna = $identificador['tipo'] === 'token' ? 'public_token' : 'codigo';
        $unidad = UnidadActivo::query()->where($columna, $identificador['valor'])->first();

        if ($unidad === null) {
            throw new ExcepcionDeNegocioSimple('No se encontró ninguna unidad con ese código.');
        }

        if ($unidad->empresa_id !== $ronda->empresa_id) {
            throw new ExcepcionDeNegocioSimple('Ese código no corresponde a esta ronda.');
        }

        [$resultado, $fila] = $this->registrarEscaneo($ronda, $unidad, $usuario);

        $fila->setRelation('unidad', $unidad->loadMissing([
            'activo:id,nombre', 'almacen:id,nombre', 'colaborador:id,nombre_completo',
        ]));

        return [
            'resultado' => $resultado,
            'contexto' => $this->contextoHumano($resultado, $ronda, $unidad, $usuario),
            'unidad' => $this->resumen->filaResumen($fila),
            'contadores' => $this->resumen->contadores($ronda),
        ];
    }

    /**
     * Mensaje de contexto para el escaneo (nunca sensible de otra empresa).
     */
    private function contextoHumano(string $resultado, InventarioFisico $ronda, UnidadActivo $unidad, User $usuario): ?string
    {
        if ($resultado !== 'no_esperada') {
            return null;
        }

        if ($ronda->almacen_id === null) {
            return 'Esta unidad no estaba esperada en esta ronda.';
        }

        $ronda->loadMissing('almacen:id,nombre');
        $texto = 'Esta unidad no estaba esperada en el almacén '.$ronda->almacen->nombre.'.';

        if ($unidad->almacen_id !== $ronda->almacen_id && $usuario->puedeAccederEmpresa($unidad->empresa_id)) {
            $unidad->loadMissing('almacen:id,nombre');
            $texto .= ' Su almacén de resguardo actual es '.$unidad->almacen->nombre.'.';
        }

        return $texto;
    }

    /**
     * @return array{0: string, 1: InventarioFisicoUnidad}
     */
    private function registrarEscaneo(InventarioFisico $ronda, UnidadActivo $unidad, User $usuario): array
    {
        return DB::transaction(function () use ($ronda, $unidad, $usuario): array {
            // Orden de locks fijo: Ronda → renglón (F16-bis). Una ronda
            // finalizada en carrera con este escaneo se detecta aquí.
            $bloqueada = InventarioFisico::query()->whereKey($ronda->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueada->estaEnProceso()) {
                throw new ExcepcionDeNegocioSimple('Esta ronda ya fue finalizada; no admite más escaneos.');
            }

            $fila = InventarioFisicoUnidad::query()
                ->where('inventario_fisico_id', $ronda->id)
                ->where('unidad_activo_id', $unidad->id)
                ->lockForUpdate()
                ->first();

            if ($fila !== null) {
                if ($fila->escaneado_en !== null) {
                    return ['ya_escaneada', $fila];
                }

                $fila->update(['escaneado_en' => now(), 'escaneado_por' => $usuario->id]);

                return ['encontrada', $fila];
            }

            try {
                $fila = InventarioFisicoUnidad::query()->create([
                    'inventario_fisico_id' => $ronda->id,
                    'unidad_activo_id' => $unidad->id,
                    'esperada' => false,
                    'escaneado_en' => now(),
                    'escaneado_por' => $usuario->id,
                ]);
            } catch (QueryException) {
                // Carrera con otro dispositivo: el UNIQUE ganó; se relee la fila.
                $fila = InventarioFisicoUnidad::query()
                    ->where('inventario_fisico_id', $ronda->id)
                    ->where('unidad_activo_id', $unidad->id)
                    ->firstOrFail();

                return ['ya_escaneada', $fila];
            }

            return ['no_esperada', $fila];
        });
    }
}
