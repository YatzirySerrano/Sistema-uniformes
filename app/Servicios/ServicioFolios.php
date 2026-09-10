<?php

namespace App\Servicios;

use App\Models\Folio;
use Illuminate\Support\Facades\DB;

/**
 * Genera folios legibles y consecutivos por tipo de documento y año, de forma
 * atómica (bloqueo pesimista sobre la fila contador). La secuencia es GLOBAL
 * por tipo+año (nunca partida por empresa): `entregas_uniformes.folio` /
 * `acuses_recepcion.folio` / `devoluciones.folio` son únicas a nivel de toda
 * la plataforma, así que dos empresas jamás pueden compartir "ENT-2026-000001"
 * — antes de la migración `..._000031` el contador sí se partía por empresa,
 * lo que producía folios duplicados en cuanto una segunda empresa registraba
 * su primer documento del año (`entregas_uniformes_folio_unique` chocaba).
 */
class ServicioFolios
{
    public const ENTREGA = 'entrega';

    public const ACUSE = 'acuse';

    public const DEVOLUCION = 'devolucion';

    public const ACUSE_DEVOLUCION = 'acuse_devolucion';

    public const INVENTARIO_FISICO = 'inventario_fisico';

    public const TRASPASO = 'traspaso';

    /**
     * @var array<string, string>
     */
    private const PREFIJOS = [
        self::ENTREGA => 'ENT',
        self::ACUSE => 'ACU',
        self::DEVOLUCION => 'DEV',
        self::ACUSE_DEVOLUCION => 'ACD',
        self::INVENTARIO_FISICO => 'INVF',
        self::TRASPASO => 'TRA',
    ];

    public function siguiente(string $tipo, ?int $anio = null): string
    {
        $anio ??= (int) now()->format('Y');
        $prefijo = self::PREFIJOS[$tipo] ?? strtoupper(substr($tipo, 0, 3));

        $consecutivo = DB::transaction(function () use ($tipo, $anio): int {
            $fila = Folio::query()
                ->where('tipo', $tipo)
                ->where('anio', $anio)
                ->lockForUpdate()
                ->first();

            if ($fila === null) {
                $fila = Folio::query()->create([
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
