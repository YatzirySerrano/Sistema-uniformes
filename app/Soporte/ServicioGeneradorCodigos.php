<?php

namespace App\Soporte;

use App\Models\Empresa;
use App\Models\SecuenciaCodigo;
use Illuminate\Support\Facades\DB;

/**
 * Genera códigos internos únicos, race-safe y permanentes para unidades de
 * seguimiento individual (y cualquier otro ámbito que lo necesite en el
 * futuro). Nunca `MAX(id)+1` sin protección: la fila de `secuencias_codigo`
 * se bloquea con `lockForUpdate()` dentro de una transacción antes de
 * incrementar, así que dos altas concurrentes de la misma empresa nunca
 * obtienen el mismo número.
 *
 * El código se prefija con `Empresa::codigo` (corto, ya único a nivel
 * plataforma) — nunca con el almacén ni con datos del Activo, para que el
 * código sea estable de por vida aunque la unidad cambie de almacén o el
 * Activo/Almacén/Empresa se renombren.
 */
class ServicioGeneradorCodigos
{
    public function siguiente(Empresa $empresa, string $ambito = 'unidad_activo'): string
    {
        return DB::transaction(function () use ($empresa, $ambito): string {
            $secuencia = SecuenciaCodigo::query()
                ->where('empresa_id', $empresa->id)
                ->where('ambito', $ambito)
                ->lockForUpdate()
                ->first();

            if ($secuencia === null) {
                $secuencia = SecuenciaCodigo::query()->create([
                    'empresa_id' => $empresa->id,
                    'ambito' => $ambito,
                    'ultimo_valor' => 0,
                ]);
                $secuencia = SecuenciaCodigo::query()
                    ->whereKey($secuencia->getKey())
                    ->lockForUpdate()
                    ->first();
            }

            $siguiente = $secuencia->ultimo_valor + 1;
            $secuencia->update(['ultimo_valor' => $siguiente]);

            return sprintf('%s-%06d', $empresa->codigo, $siguiente);
        });
    }
}
