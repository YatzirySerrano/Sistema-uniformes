<?php

namespace App\Soporte;

use App\Models\Empresa;
use App\Models\SecuenciaCodigo;
use Illuminate\Support\Facades\DB;

/**
 * Genera códigos internos únicos, race-safe y permanentes para catálogos
 * acotados a una empresa (unidades de seguimiento individual, sucursales,
 * áreas, activos…). Nunca `MAX(id)+1` / `count()+1` sin protección: la fila
 * de `secuencias_codigo` se bloquea con `lockForUpdate()` dentro de una
 * transacción antes de incrementar, así que dos altas concurrentes de la
 * misma empresa nunca obtienen el mismo número.
 *
 * Para catálogos GLOBALES de plataforma (sin dimensión de empresa, p. ej.
 * Almacén o Tipo de activo) usa `App\Soporte\ServicioGeneradorCodigosGlobal`.
 */
class ServicioGeneradorCodigos
{
    /**
     * Código prefijado con `Empresa::codigo` (corto, ya único a nivel
     * plataforma) — nunca con el almacén ni con datos del Activo, para que el
     * código sea estable de por vida aunque la unidad cambie de almacén o el
     * Activo/Almacén/Empresa se renombren. Usado por unidades de seguimiento
     * individual.
     */
    public function siguiente(Empresa $empresa, string $ambito = 'unidad_activo'): string
    {
        return sprintf('%s-%06d', $empresa->codigo, $this->incrementar($empresa->id, $ambito));
    }

    /**
     * Código con un prefijo literal fijo (p. ej. "SUC", "ARE", "ACT"),
     * consecutivo dentro de la empresa. Mismo contador atómico que
     * `siguiente()`, sólo cambia el formato de salida.
     */
    public function siguienteConPrefijo(Empresa $empresa, string $ambito, string $prefijo, int $digitos = 4): string
    {
        return sprintf('%s-%0'.$digitos.'d', $prefijo, $this->incrementar($empresa->id, $ambito));
    }

    private function incrementar(int $empresaId, string $ambito): int
    {
        return DB::transaction(function () use ($empresaId, $ambito): int {
            $secuencia = SecuenciaCodigo::query()
                ->where('empresa_id', $empresaId)
                ->where('ambito', $ambito)
                ->lockForUpdate()
                ->first();

            if ($secuencia === null) {
                $secuencia = SecuenciaCodigo::query()->create([
                    'empresa_id' => $empresaId,
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

            return $siguiente;
        });
    }
}
