<?php

namespace App\Soporte;

use App\Models\SecuenciaCodigoGlobal;
use Illuminate\Support\Facades\DB;

/**
 * Genera códigos consecutivos, únicos y race-safe para catálogos GLOBALES de
 * plataforma (sin dimensión de empresa: Almacén, Tipo de activo…). Mismo
 * principio que `App\Soporte\ServicioGeneradorCodigos` (lock de la fila
 * contador dentro de una transacción antes de incrementar), pero respaldado
 * por `secuencias_codigo_globales` (una fila por ámbito, no por
 * empresa+ámbito) para no forzar una FK de empresa nula en una tabla pensada
 * para contadores por empresa.
 */
class ServicioGeneradorCodigosGlobal
{
    public function siguiente(string $ambito, string $prefijo, int $digitos = 4): string
    {
        return DB::transaction(function () use ($ambito, $prefijo, $digitos): string {
            $secuencia = SecuenciaCodigoGlobal::query()
                ->where('ambito', $ambito)
                ->lockForUpdate()
                ->first();

            if ($secuencia === null) {
                $secuencia = SecuenciaCodigoGlobal::query()->create([
                    'ambito' => $ambito,
                    'ultimo_valor' => 0,
                ]);
                $secuencia = SecuenciaCodigoGlobal::query()
                    ->whereKey($secuencia->getKey())
                    ->lockForUpdate()
                    ->first();
            }

            $siguiente = $secuencia->ultimo_valor + 1;
            $secuencia->update(['ultimo_valor' => $siguiente]);

            return sprintf('%s-%0'.$digitos.'d', $prefijo, $siguiente);
        });
    }
}
