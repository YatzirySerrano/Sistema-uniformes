<?php

namespace App\Servicios;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Empresa;
use App\Soporte\NormalizadorNombre;
use App\Soporte\ServicioGeneradorCodigos;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resuelve, en un traspaso INTEREMPRESA, cuál es el Activo de la empresa
 * DESTINO equivalente al Activo de origen — o crea uno nuevo si no existe.
 *
 * NUNCA empareja sólo por nombre ("Camisa" == "Camisa" no implica el mismo
 * artículo). La clave lógica de homologación es ESTRICTA: deben coincidir
 * TODOS estos atributos estructurales (globales de plataforma o propios del
 * activo):
 *
 *   - `tipo_control` (cantidad | individual)
 *   - nombre NORMALIZADO (`NormalizadorNombre::catalogo`)
 *   - `tipo_activo_id`   (NULL casa con NULL)
 *   - `categoria_id`     (NULL casa con NULL)
 *   - el CONJUNTO de variantes asociadas (`activo_talla.talla_id`), ordenado
 *
 * Si hay exactamente 1 candidato → se reutiliza. Si hay 0 → se crea. Si hay
 * MÁS de 1 → se DETIENE con un mensaje humano pidiendo elegir manualmente:
 * jamás un `first()` silencioso.
 */
class HomologadorActivo
{
    public function __construct(private readonly ServicioGeneradorCodigos $codigos) {}

    /**
     * Candidatos equivalentes en la empresa destino (read-only, para la
     * previsualización del formulario).
     *
     * @return Collection<int, Activo>
     */
    public function candidatos(Activo $origen, int $empresaDestinoId): Collection
    {
        $nombreNormalizado = NormalizadorNombre::catalogo($origen->nombre);
        $variantesOrigen = $this->variantesDe($origen);

        return Activo::query()
            ->where('empresa_id', $empresaDestinoId)
            ->where('activo', true)
            ->where('tipo_control', $origen->tipo_control->value)
            ->when($origen->tipo_activo_id === null, fn ($q) => $q->whereNull('tipo_activo_id'), fn ($q) => $q->where('tipo_activo_id', $origen->tipo_activo_id))
            ->when($origen->categoria_id === null, fn ($q) => $q->whereNull('categoria_id'), fn ($q) => $q->where('categoria_id', $origen->categoria_id))
            ->with('tallas:id')
            ->get()
            ->filter(fn (Activo $candidato): bool => NormalizadorNombre::catalogo($candidato->nombre) === $nombreNormalizado
                && $this->variantesDe($candidato) === $variantesOrigen)
            ->values();
    }

    /**
     * Resuelve el Activo destino DENTRO de la transacción del traspaso.
     * Si `$manualId` viene, se valida y se usa; si no, se homologa; si no hay
     * candidatos, se crea; si hay varios, se lanza `ExcepcionDeNegocioSimple`.
     *
     * @return array{activo: Activo, creado: bool}
     */
    public function resolver(Activo $origen, int $empresaDestinoId, ?int $manualId, string $nombreEmpresaDestino): array
    {
        if ($manualId !== null) {
            $manual = Activo::query()
                ->where('empresa_id', $empresaDestinoId)
                ->where('activo', true)
                ->where('tipo_control', $origen->tipo_control->value)
                ->find($manualId);

            if ($manual === null) {
                throw new ExcepcionDeNegocioSimple('El activo destino elegido no pertenece a la empresa destino o no es del mismo tipo de control.');
            }

            return ['activo' => $manual, 'creado' => false];
        }

        $candidatos = $this->candidatos($origen, $empresaDestinoId);

        if ($candidatos->count() === 1) {
            return ['activo' => $candidatos->first(), 'creado' => false];
        }

        if ($candidatos->count() > 1) {
            throw new ExcepcionDeNegocioSimple(sprintf(
                'En «%s» hay %d activos que coinciden con «%s». Elige el activo destino correcto en el formulario o corrige el catálogo antes de traspasar.',
                $nombreEmpresaDestino,
                $candidatos->count(),
                $origen->nombre,
            ));
        }

        return ['activo' => $this->crearEquivalente($origen, $empresaDestinoId), 'creado' => true];
    }

    private function crearEquivalente(Activo $origen, int $empresaDestinoId): Activo
    {
        $codigo = $this->codigos->siguienteConPrefijo(
            Empresa::query()->findOrFail($empresaDestinoId),
            'activo',
            'ACT',
            semilla: fn (): int => $this->maximoSufijoActivo($empresaDestinoId),
        );

        $activo = Activo::query()->create([
            'empresa_id' => $empresaDestinoId,
            'nombre' => $origen->nombre,
            'descripcion' => $origen->descripcion,
            'tipo_activo_id' => $origen->tipo_activo_id,
            'categoria_id' => $origen->categoria_id,
            'categoria' => $origen->categoria,
            'tipo_control' => $origen->tipo_control,
            'codigo' => $codigo,
            'activo' => true,
        ]);

        // Mismas variantes que el origen (catálogo de variantes es de
        // plataforma). Nunca se copian stock, movimientos, código de origen
        // ni imagen.
        $activo->tallas()->sync($this->variantesDe($origen));

        return $activo;
    }

    /**
     * IDs de variantes asociadas al activo (`activo_talla`), ordenados — la
     * "huella" de variantes para comparar equivalencia.
     *
     * @return array<int, int>
     */
    private function variantesDe(Activo $activo): array
    {
        $ids = $activo->relationLoaded('tallas')
            ? $activo->tallas->pluck('id')->all()
            : $activo->tallas()->pluck('tallas.id')->all();

        $ids = array_map('intval', $ids);
        sort($ids);

        return $ids;
    }

    private function maximoSufijoActivo(int $empresaId): int
    {
        $maximo = 0;

        foreach (Activo::query()->where('empresa_id', $empresaId)->where('codigo', 'like', 'ACT-%')->pluck('codigo') as $codigo) {
            if (preg_match('/(\d+)$/', (string) $codigo, $m) === 1) {
                $maximo = max($maximo, (int) $m[1]);
            }
        }

        return $maximo;
    }
}
