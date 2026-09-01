<?php

namespace App\Servicios;

use App\Models\Folio;
use Illuminate\Support\Facades\DB;

/**
 * Genera folios legibles y consecutivos por tipo de documento, empresa y año
 * de forma atómica (bloqueo pesimista sobre la fila contador).
 */
class ServicioFolios
{
    public const ENTREGA = 'entrega';

    public const ACUSE = 'acuse';

    public const DEVOLUCION = 'devolucion';

    /**
     * @var array<string, string>
     */
    private const PREFIJOS = [
        self::ENTREGA => 'ENT',
        self::ACUSE => 'ACU',
        self::DEVOLUCION => 'DEV',
    ];

    public function siguiente(string $tipo, ?int $empresaId, ?int $anio = null): string
    {
        $anio ??= (int) now()->format('Y');
        $prefijo = self::PREFIJOS[$tipo] ?? strtoupper(substr($tipo, 0, 3));

        $consecutivo = DB::transaction(function () use ($tipo, $empresaId, $anio): int {
            $fila = Folio::query()
                ->where('empresa_id', $empresaId)
                ->where('tipo', $tipo)
                ->where('anio', $anio)
                ->lockForUpdate()
                ->first();

            if ($fila === null) {
                $fila = Folio::query()->create([
                    'empresa_id' => $empresaId,
                    'tipo' => $tipo,
                    'anio' => $anio,
                    'consecutivo' => 0,
                ]);
            }

            $fila->increment('consecutivo');

            return $fila->consecutivo;
        });

        return sprintf('%s-%d-%06d', $prefijo, $anio, $consecutivo);
    }
}
