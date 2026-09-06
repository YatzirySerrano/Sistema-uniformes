<?php

namespace App\Soporte;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoDevolucion;
use App\Enums\EstadoEntrega;
use App\Enums\EstadoUnidadActivo;
use App\Models\Devolucion;
use App\Models\EntregaUniforme;
use App\Models\UnidadActivo;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Stringable;
use UnitEnum;

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

    /**
     * Representación canónica sólo para decidir si antes/después cambiaron
     * (no necesita ser "bonita", sólo estable y nunca lanzar). Reutiliza
     * `representar()` para que un array/objeto histórico jamás llegue a un
     * `(string) $valor` sin normalizar antes.
     */
    private function normalizar(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        return $this->representar($valor);
    }

    private function etiquetaCampo(string $clave): string
    {
        return self::ETIQUETAS_CAMPO[$clave] ?? Str::of($clave)->replace('_', ' ')->ucfirst()->toString();
    }

    private function humanizarValor(?string $tipoEntidad, string $clave, mixed $valor): string
    {
        if ($valor === null || $valor === '' || $valor === []) {
            return '—';
        }

        if (is_bool($valor)) {
            return in_array($clave, ['activo', 'activa'], true)
                ? ($valor ? 'Activo' : 'Inactivo')
                : ($valor ? 'Sí' : 'No');
        }

        if ($tipoEntidad === UnidadActivo::class && is_string($valor)) {
            if ($clave === 'estado') {
                return EstadoUnidadActivo::tryFrom($valor)?->etiqueta() ?? $valor;
            }
            if ($clave === 'condicion') {
                return CondicionUnidadActivo::tryFrom($valor)?->etiqueta() ?? $valor;
            }
        }

        if ($tipoEntidad === EntregaUniforme::class && $clave === 'estado' && is_string($valor)) {
            return EstadoEntrega::tryFrom($valor)?->etiqueta() ?? $valor;
        }

        if ($tipoEntidad === Devolucion::class && $clave === 'estado' && is_string($valor)) {
            return EstadoDevolucion::tryFrom($valor)?->etiqueta() ?? $valor;
        }

        return $this->representar($valor);
    }

    /**
     * Representación humana best-effort de un valor de tipo arbitrario
     * proveniente de un snapshot histórico (JSON decodificado): escalar,
     * enum, fecha, array simple o estructura compleja. Nunca lanza — es la
     * única puerta por la que un valor no escalar puede llegar a texto, para
     * que ni `normalizar()` ni `humanizarValor()` hagan un `(string) $valor`
     * directo sobre un array/objeto ("Array to string conversion").
     */
    private function representar(mixed $valor): string
    {
        if (is_string($valor)) {
            return $valor;
        }

        if (is_int($valor) || is_float($valor)) {
            return (string) $valor;
        }

        if (is_bool($valor)) {
            return $valor ? 'Sí' : 'No';
        }

        if ($valor instanceof BackedEnum) {
            return method_exists($valor, 'etiqueta') ? $valor->etiqueta() : (string) $valor->value;
        }

        if ($valor instanceof UnitEnum) {
            return $valor->name;
        }

        if ($valor instanceof DateTimeInterface) {
            return $valor->format('d/m/Y H:i');
        }

        if ($valor instanceof Collection) {
            $valor = $valor->all();
        } elseif ($valor instanceof Arrayable) {
            $valor = $valor->toArray();
        }

        if (is_array($valor)) {
            return $this->representarArray($valor);
        }

        if ($valor instanceof Stringable) {
            return (string) $valor;
        }

        if (is_object($valor)) {
            return $this->representarComoJson($valor);
        }

        return (string) $valor;
    }

    /**
     * @param  array<array-key, mixed>  $valor
     */
    private function representarArray(array $valor): string
    {
        if ($valor === []) {
            return '—';
        }

        // Una lista de registros anidados (p. ej. `detalles` de una entrega:
        // cada elemento es a su vez un array con sus propias columnas) no es
        // legible como JSON crudo en un diff pensado para no-técnicos. Como
        // el conteo es todo lo que aporta valor aquí (el detalle completo
        // sigue disponible en "JSON técnico"), se resume en vez de volcarse.
        if (array_is_list($valor) && collect($valor)->every(fn (mixed $v): bool => is_array($v))) {
            $total = count($valor);

            return $total === 1 ? '1 elemento' : "{$total} elementos";
        }

        $esEscalarPlano = collect($valor)->every(
            fn (mixed $v): bool => $v === null || is_scalar($v) || $v instanceof BackedEnum || $v instanceof UnitEnum
        );

        if (! $esEscalarPlano) {
            return $this->representarComoJson($valor);
        }

        if (array_is_list($valor)) {
            return collect($valor)->map(fn (mixed $v): string => $this->representar($v))->implode(', ');
        }

        return collect($valor)
            ->map(fn (mixed $v, int|string $clave): string => "{$clave}: {$this->representar($v)}")
            ->implode('; ');
    }

    private function representarComoJson(mixed $valor): string
    {
        $json = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '(valor no representable)' : $json;
    }
}
