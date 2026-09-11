<?php

namespace App\Http\Requests\Concerns;

use App\Enums\PerfilTecnicoUnidad;
use App\Models\Activo;
use App\Models\CategoriaActivo;
use App\Soporte\ResolverPerfilTecnicoUnidad;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Reglas comunes para los datos técnicos por unidad identificada (marca,
 * modelo, IMEI, número, operador, plan), compartidas por el alta de Activo y
 * por "agregar unidades". El perfil (Celular / Computadora / Tablet) lo decide
 * SIEMPRE el backend a partir del `codigo` de la categoría/tipo, nunca el
 * frontend.
 */
trait ValidaEspecificacionUnidad
{
    /**
     * @param  int|null  $ignorarUnidadId  al editar una unidad existente, su propia fila no cuenta para el UNIQUE de IMEI
     * @return array<string, list<mixed>>
     */
    protected static function reglasEspecificacion(?int $ignorarUnidadId = null): array
    {
        $imeiUnico = Rule::unique('unidad_activo_especificaciones', 'imei');

        if ($ignorarUnidadId !== null) {
            $imeiUnico = $imeiUnico->ignore($ignorarUnidadId, 'unidad_activo_id');
        }

        return [
            'especificaciones.*.marca' => ['nullable', 'string', 'max:120'],
            'especificaciones.*.modelo' => ['nullable', 'string', 'max:160'],
            'especificaciones.*.imei' => ['nullable', 'string', 'regex:/^[0-9]{14,17}$/', 'distinct', $imeiUnico],
            'especificaciones.*.numero_telefonico' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/'],
            'especificaciones.*.operador' => ['nullable', 'string', 'max:80'],
            'especificaciones.*.plan' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function mensajesEspecificacion(): array
    {
        return [
            'especificaciones.*.imei.regex' => 'El IMEI debe tener entre 14 y 17 dígitos.',
            'especificaciones.*.imei.distinct' => 'Hay un IMEI repetido entre las unidades.',
            'especificaciones.*.imei.unique' => 'Ya existe una unidad registrada con ese IMEI.',
            'especificaciones.*.numero_telefonico.regex' => 'El número telefónico no tiene un formato válido.',
        ];
    }

    /**
     * Perfil técnico resuelto a partir de la categoría elegida (relación 1:1
     * `categoria_activo_perfil_tecnico`, NUNCA por `codigo` ni nombre). En alta
     * no hay Activo todavía, así que se consulta la categoría directamente.
     */
    protected function perfilTecnicoDeEntrada(?Activo $activo, ?int $categoriaId): ?PerfilTecnicoUnidad
    {
        if ($activo instanceof Activo) {
            return app(ResolverPerfilTecnicoUnidad::class)->paraActivo($activo);
        }

        if ($categoriaId === null) {
            return null;
        }

        return CategoriaActivo::query()
            ->with('perfilTecnico')
            ->find($categoriaId)
            ?->perfilTecnico?->perfil;
    }

    /**
     * Valida que, cuando el perfil no es nulo, haya una fila de especificación
     * por unidad y que los campos obligatorios del perfil vengan llenos. Sin
     * perfil, `especificaciones` se ignora por completo.
     */
    protected function validarEspecificacionesPorPerfil(Validator $validator, ?PerfilTecnicoUnidad $perfil, int $cantidadUnidades): void
    {
        if ($perfil === null || $cantidadUnidades < 1) {
            return;
        }

        $filas = $this->input('especificaciones');
        $filas = is_array($filas) ? array_values($filas) : [];

        if (count($filas) !== $cantidadUnidades) {
            $validator->errors()->add('especificaciones', 'Captura los datos técnicos de cada unidad ('.$cantidadUnidades.').');

            return;
        }

        foreach ($filas as $i => $fila) {
            foreach ($perfil->camposRequeridos() as $campo) {
                $valor = is_array($fila) ? ($fila[$campo] ?? null) : null;

                if (! is_string($valor) || trim($valor) === '') {
                    $validator->errors()->add("especificaciones.{$i}.{$campo}", 'Este dato es obligatorio para '.$perfil->etiqueta().'.');
                }
            }
        }
    }
}
