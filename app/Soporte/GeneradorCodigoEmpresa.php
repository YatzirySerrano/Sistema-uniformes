<?php

namespace App\Soporte;

use App\Http\Controllers\Concerns\ReconciliaSecuenciaCodigo;
use App\Models\Empresa;
use Illuminate\Support\Str;

/**
 * Genera el código legible de Empresa a partir del nombre comercial
 * ("ALIMEN01"). Extraído de `EmpresaController` para que otros flujos (p. ej.
 * el importador de base de datos maestra) puedan reutilizar exactamente el
 * mismo algoritmo sin duplicarlo. Cada prefijo derivado del nombre tiene su
 * propio contador dentro de `secuencias_codigo_globales` (ámbito
 * "empresa:{base}"), race-safe vía `ServicioGeneradorCodigosGlobal`, y se
 * reconcilia contra el mayor sufijo REALMENTE existente con ese prefijo en
 * cada llamada.
 */
final class GeneradorCodigoEmpresa
{
    use ReconciliaSecuenciaCodigo;

    public function __construct(
        private readonly ServicioGeneradorCodigosGlobal $codigosGlobales,
    ) {}

    public function generar(string $nombreComercial): string
    {
        [$base, $ambito] = $this->baseYAmbito($nombreComercial);

        $siguiente = $this->codigosGlobales->siguienteNumero($ambito, fn (): int => $this->maximoSufijo(
            Empresa::query()->where('codigo', 'like', $base.'%')->pluck('codigo'),
            $base,
        ));

        return $base.str_pad((string) $siguiente, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Previsualización NO autoritativa (no reserva el consecutivo) para UX
     * mientras el usuario escribe el nombre comercial. El valor definitivo se
     * calcula de nuevo, atómicamente, en `generar()`.
     */
    public function previsualizar(string $nombreComercial): ?string
    {
        $nombre = trim($nombreComercial);

        if ($nombre === '') {
            return null;
        }

        [$base, $ambito] = $this->baseYAmbito($nombre);

        $siguiente = $this->codigosGlobales->siguienteNumeroAproximado($ambito, fn (): int => $this->maximoSufijo(
            Empresa::query()->where('codigo', 'like', $base.'%')->pluck('codigo'),
            $base,
        ));

        return $base.str_pad((string) $siguiente, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function baseYAmbito(string $nombre): array
    {
        $base = Str::upper(Str::slug(Str::substr($nombre, 0, 6), ''));
        $base = $base !== '' ? $base : 'EMP';

        return [$base, 'empresa:'.$base];
    }
}
