<?php

namespace App\Soporte;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Models\UnidadActivo;
use Illuminate\Support\Str;

/**
 * Transforma el `valores_anteriores` / `valores_nuevos` (JSON técnico) de un
 * registro de auditoría en una lista de cambios "Campo | Antes | Ahora" en
 * español, para no obligar al usuario a leer JSON crudo. Best-effort: si no
 * hay antes/después capturados (la mayoría de las acciones sólo registran
 * `descripcion`), simplemente no hay cambios que mostrar — el JSON técnico
 * (cuando exista) sigue disponible aparte como opción avanzada.
 *
 * NO inventa datos: nunca resuelve nombres de FK con una consulta aparte
 * (evita N+1 y evita mostrar un nombre que no venía en el snapshot). Por eso
 * los campos `*_id` (referencias) se omiten del diff humano — son ruido para
 * un lector no técnico sin el nombre resuelto.
 */
class DescripcionAuditoria
{
    /**
     * @var array<string, string>
     */
    private const ETIQUETAS_CAMPO = [
        'nombre' => 'Nombre',
        'nombre_completo' => 'Nombre completo',
        'nombre_comercial' => 'Nombre comercial',
        'razon_social' => 'Razón social',
        'descripcion' => 'Descripción',
        'correo' => 'Correo',
        'email' => 'Correo',
        'telefono' => 'Teléfono',
        'direccion' => 'Dirección',
        'rfc' => 'RFC',
        'puesto' => 'Puesto',
        'area' => 'Área',
        'numero_empleado' => 'Número de empleado',
        'activo' => 'Estado',
        'activa' => 'Estado',
        'estado' => 'Estado',
        'condicion' => 'Condición',
        'codigo' => 'Código',
        'cantidad' => 'Cantidad',
        'minimo' => 'Mínimo',
        'motivo' => 'Motivo',
        'notas' => 'Notas',
        'name' => 'Nombre',
    ];

    /**
     * @param  array<string, mixed>|null  $anteriores
     * @param  array<string, mixed>|null  $nuevos
     * @return array<int, array{campo: string, antes: string, ahora: string}>
     */
    public function cambios(?string $tipoEntidad, ?array $anteriores, ?array $nuevos): array
    {
        if ($anteriores === null && $nuevos === null) {
            return [];
        }

        $anteriores ??= [];
        $nuevos ??= [];
        $claves = array_unique([...array_keys($anteriores), ...array_keys($nuevos)]);

        $cambios = [];
        foreach ($claves as $clave) {
            if ($this->esClaveOculta($clave)) {
                continue;
            }

            $antes = $anteriores[$clave] ?? null;
            $despues = $nuevos[$clave] ?? null;

            if ($this->normalizar($antes) === $this->normalizar($despues)) {
                continue;
            }

            $cambios[] = [
                'campo' => $this->etiquetaCampo($clave),
                'antes' => $this->humanizarValor($tipoEntidad, $clave, $antes),
                'ahora' => $this->humanizarValor($tipoEntidad, $clave, $despues),
            ];
        }

        return $cambios;
    }

    private function esClaveOculta(string $clave): bool
    {
        return $clave === 'id'
            || str_ends_with($clave, '_id')
            || in_array($clave, ['created_at', 'updated_at', 'deleted_at'], true);
    }

    private function normalizar(mixed $valor): mixed
    {
        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        return $valor === null ? null : (string) $valor;
    }

    private function etiquetaCampo(string $clave): string
    {
        return self::ETIQUETAS_CAMPO[$clave] ?? Str::of($clave)->replace('_', ' ')->ucfirst()->toString();
    }

    private function humanizarValor(?string $tipoEntidad, string $clave, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        if (is_bool($valor)) {
            return in_array($clave, ['activo', 'activa'], true)
                ? ($valor ? 'Activo' : 'Inactivo')
                : ($valor ? 'Sí' : 'No');
        }

        if ($tipoEntidad === UnidadActivo::class) {
            if ($clave === 'estado') {
                return EstadoUnidadActivo::tryFrom((string) $valor)?->etiqueta() ?? (string) $valor;
            }
            if ($clave === 'condicion') {
                return CondicionUnidadActivo::tryFrom((string) $valor)?->etiqueta() ?? (string) $valor;
            }
        }

        return (string) $valor;
    }
}
