<?php

namespace App\Soporte;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Interpreta el texto crudo que llega de un escaneo de QR (o de la entrada
 * manual de respaldo) y extrae un identificador de unidad SEGURO: el
 * `public_token` (UUID permanente del QR) o el `codigo` interno de la unidad.
 *
 * Nunca confía en una URL arbitraria: sólo acepta una URL si su ruta es la del
 * detalle de unidad del propio sistema (`/activos/unidades/{token}`) y, cuando
 * trae host, si ese host es el de la instalación. Cualquier otra cosa se trata
 * como un `codigo` a secas (y si no existe, el backend responde "no
 * encontrada", sin filtrar información).
 *
 * Es un parser PURO: no toca la base de datos. La acción de escaneo resuelve
 * la `UnidadActivo` acotada a la empresa de la ronda.
 */
class ResolvedorUnidadEscaneada
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @return array{tipo: 'token'|'codigo', valor: string}|null
     */
    public function resolver(string $entrada): ?array
    {
        $entrada = trim($entrada);

        if ($entrada === '' || mb_strlen($entrada) > 2048) {
            return null;
        }

        if ($token = $this->tokenDesdeUrl($entrada)) {
            return ['tipo' => 'token', 'valor' => Str::lower($token)];
        }

        if (preg_match(self::UUID, $entrada) === 1) {
            return ['tipo' => 'token', 'valor' => Str::lower($entrada)];
        }

        // Entrada manual del código de unidad (p. ej. "DASTI01-000027"). El
        // `codigo` real tiene como máximo 40 caracteres.
        if (mb_strlen($entrada) <= 40) {
            return ['tipo' => 'codigo', 'valor' => $entrada];
        }

        return null;
    }

    /**
     * Extrae el `public_token` de una URL SÓLO si su ruta es
     * `/activos/unidades/{uuid}` y (si trae host) el host es el de la
     * instalación. Devuelve `null` para cualquier otra URL.
     */
    private function tokenDesdeUrl(string $entrada): ?string
    {
        if (! Str::contains($entrada, '/activos/unidades/')) {
            return null;
        }

        $partes = parse_url($entrada);

        if ($partes === false) {
            return null;
        }

        if (isset($partes['host'])) {
            $hostInstalacion = parse_url(URL::to('/'), PHP_URL_HOST);

            if ($hostInstalacion !== null && Str::lower($partes['host']) !== Str::lower((string) $hostInstalacion)) {
                return null;
            }
        }

        $ruta = $partes['path'] ?? $entrada;

        if (preg_match('#/activos/unidades/([0-9a-f-]{36})#i', $ruta, $coincidencias) !== 1) {
            return null;
        }

        return preg_match(self::UUID, $coincidencias[1]) === 1 ? $coincidencias[1] : null;
    }
}
