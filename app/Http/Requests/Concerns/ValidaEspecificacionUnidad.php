<?php

namespace App\Http\Requests\Concerns;

use App\Enums\PerfilTecnicoUnidad;
use App\Models\Activo;
use App\Models\CategoriaActivo;
use App\Soporte\EspecificacionUnidad;
use App\Soporte\ResolverPerfilTecnicoUnidad;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Reglas comunes para los datos técnicos por unidad identificada (ver
 * `App\Soporte\EspecificacionUnidad`), compartidas por el alta de Activo y
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

        return EspecificacionUnidad::reglas('especificaciones.*.', ['distinct', $imeiUnico]);
    }

    /**
     * @return array<string, string>
     */
    protected static function mensajesEspecificacion(): array
    {
        return [
            ...EspecificacionUnidad::mensajes('especificaciones.*.'),
            'especificaciones.*.imei.distinct' => 'Hay un IMEI repetido entre las unidades.',
            'especificaciones.*.imei.unique' => 'Ya existe una unidad registrada con ese IMEI.',
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
            EspecificacionUnidad::validarPorPerfil($validator, $perfil, is_array($fila) ? $fila : [], "especificaciones.{$i}.");
        }
    }
}
