<?php

namespace App\Soporte;

use App\Enums\ClaseVehiculo;
use App\Enums\PerfilTecnicoUnidad;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Catálogo ÚNICO de los campos técnicos por unidad (tabla
 * `unidad_activo_especificaciones`): nombres, etiquetas y reglas de formato.
 * Qué campos aplican / son obligatorios lo decide `PerfilTecnicoUnidad`;
 * aquí sólo vive lo común para no repetirlo en cada request, acción o vista.
 */
final class EspecificacionUnidad
{
    /**
     * @var array<string, string> campo => etiqueta visible
     */
    public const CAMPOS = [
        'marca' => 'Marca',
        'modelo' => 'Modelo',
        'imei' => 'IMEI',
        'numero_telefonico' => 'Número telefónico',
        'operador' => 'Operador',
        'plan' => 'Plan',
        'clase_vehiculo' => 'Tipo de vehículo',
        'anio' => 'Año',
        'color' => 'Color',
        'placas' => 'Placas',
        'numero_serie' => 'NIV / VIN / número de serie',
    ];

    /**
     * Reglas de formato por campo (la obligatoriedad por perfil va aparte).
     *
     * @param  string  $prefijo  p. ej. `especificaciones.*.` en altas por lote, `''` al editar una unidad
     * @param  list<mixed>  $reglasImeiExtra  unicidad del IMEI según el contexto
     * @return array<string, list<mixed>>
     */
    public static function reglas(string $prefijo = '', array $reglasImeiExtra = []): array
    {
        $anioMaximo = (int) now()->year + 1;

        return [
            "{$prefijo}marca" => ['nullable', 'string', 'max:120'],
            "{$prefijo}modelo" => ['nullable', 'string', 'max:160'],
            "{$prefijo}imei" => ['nullable', 'string', 'regex:/^[0-9]{14,17}$/', ...$reglasImeiExtra],
            "{$prefijo}numero_telefonico" => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/'],
            "{$prefijo}operador" => ['nullable', 'string', 'max:80'],
            "{$prefijo}plan" => ['nullable', 'string', 'max:200'],
            "{$prefijo}clase_vehiculo" => ['nullable', Rule::enum(ClaseVehiculo::class)],
            "{$prefijo}anio" => ['nullable', 'integer', 'min:1900', "max:{$anioMaximo}"],
            "{$prefijo}color" => ['nullable', 'string', 'max:60'],
            "{$prefijo}placas" => ['nullable', 'string', 'max:15', 'regex:/^[A-Za-z0-9\-\s]{3,15}$/'],
            "{$prefijo}numero_serie" => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mensajes(string $prefijo = ''): array
    {
        $anioMaximo = (int) now()->year + 1;

        return [
            "{$prefijo}imei.regex" => 'El IMEI debe tener entre 14 y 17 dígitos.',
            "{$prefijo}numero_telefonico.regex" => 'El número telefónico no tiene un formato válido.',
            "{$prefijo}clase_vehiculo.enum" => 'Elige un tipo de vehículo válido.',
            "{$prefijo}anio.integer" => 'El año debe ser un número.',
            "{$prefijo}anio.min" => 'El año debe ser 1900 o posterior.',
            "{$prefijo}anio.max" => "El año no puede ser posterior a {$anioMaximo}.",
            "{$prefijo}placas.regex" => 'Las placas sólo admiten letras, números y guiones.',
        ];
    }

    /**
     * Obligatorios del perfil + "al menos uno de" (p. ej. placas o NIV).
     *
     * @param  array<string, mixed>  $datos  valores de UNA unidad
     * @param  string  $prefijo  clave de error, p. ej. `especificaciones.0.`
     */
    public static function validarPorPerfil(Validator $validator, PerfilTecnicoUnidad $perfil, array $datos, string $prefijo = ''): void
    {
        foreach ($perfil->camposRequeridos() as $campo) {
            if (! self::lleno($datos[$campo] ?? null)) {
                $validator->errors()->add($prefijo.$campo, 'Este dato es obligatorio para '.$perfil->etiqueta().'.');
            }
        }

        foreach ($perfil->identificadoresAlternativos() as $grupo) {
            $hayAlguno = collect($grupo)->contains(fn (string $campo): bool => self::lleno($datos[$campo] ?? null));

            if (! $hayAlguno) {
                $etiquetas = implode(' o ', array_map(fn (string $c): string => self::CAMPOS[$c], $grupo));
                $validator->errors()->add($prefijo.$grupo[0], "Captura al menos uno: {$etiquetas}.");
            }
        }
    }

    /**
     * Normaliza los valores de una unidad a las columnas de la tabla
     * (cadenas vacías → null; sólo campos conocidos).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public static function normalizar(array $datos): array
    {
        $valores = [];

        foreach (array_keys(self::CAMPOS) as $campo) {
            $valor = $datos[$campo] ?? null;
            $valor = is_string($valor) ? trim($valor) : $valor;
            $valores[$campo] = ($valor === '' || $valor === null) ? null : $valor;
        }

        if ($valores['placas'] !== null) {
            $valores['placas'] = mb_strtoupper((string) $valores['placas']);
        }

        return $valores;
    }

    private static function lleno(mixed $valor): bool
    {
        return is_int($valor) || (is_string($valor) && trim($valor) !== '');
    }
}
