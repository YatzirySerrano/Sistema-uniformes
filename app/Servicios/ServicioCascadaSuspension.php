<?php

namespace App\Servicios;

use App\Models\Suspension;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Cascada de desactivación NO destructiva (Fase 7). Cuando un Empresa/
 * Sucursal/Activo se desactiva, sus dependientes que estaban activos en ese
 * momento quedan también inactivos y se deja un rastro en `suspensiones`
 * (nunca se toca algo ya inactivo por otra causa: `suspender()` sólo alcanza
 * a los que cumplían la columna de estado en `true`). Reactivar el causante
 * NO reactiva sus dependientes automáticamente — `pendientesDeCausante()` /
 * `checklistDe()` alimentan un checklist para que el usuario elija cuáles
 * reactivar. Nunca toca históricos (movimientos, entregas…), sólo el flag de
 * estado de catálogo/config del dependiente.
 *
 * Blindaje multicausa: una entidad puede depender de MÁS de una precondición
 * para operar de verdad (p. ej. un Colaborador de su Empresa Y su Sucursal; un
 * Conjunto de su Empresa Y de todos sus Activos componentes). Levantar la
 * suspensión propia de una entidad NUNCA basta por sí sola —
 * `ServicioOperatividad::dependenciasNoOperativas()` es la única fuente de
 * verdad que decide si reactivarla de verdad es válido; si no, la suspensión
 * se conserva y se informa el motivo (nunca un estado a medias silencioso).
 */
class ServicioCascadaSuspension
{
    public function __construct(private readonly ServicioOperatividad $operatividad) {}

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $consultaDependientes  Ya acotada al causante (p. ej. `where('empresa_id', ...)`); esta función añade el filtro de "actualmente activo".
     */
    public function suspender(Model $causante, Builder $consultaDependientes, string $columnaActivo, ?int $realizadoPor): int
    {
        $dependientes = $consultaDependientes->where($columnaActivo, true)->get();

        foreach ($dependientes as $dependiente) {
            DB::transaction(function () use ($dependiente, $causante, $columnaActivo, $realizadoPor): void {
                $dependiente->update([$columnaActivo => false]);

                Suspension::query()->create([
                    'entidad_type' => $dependiente->getMorphClass(),
                    'entidad_id' => $dependiente->getKey(),
                    'columna_activo' => $columnaActivo,
                    'causante_type' => $causante->getMorphClass(),
                    'causante_id' => $causante->getKey(),
                    'motivo' => 'Cascada al desactivar '.class_basename($causante).' #'.$causante->getKey(),
                    'suspendida_por' => $realizadoPor,
                ]);
            });
        }

        return $dependientes->count();
    }

    /**
     * Suspensiones aún vigentes causadas por ESTE causante — lo que un
     * checklist de reactivación selectiva debe ofrecer. Nunca incluye
     * dependientes inactivos por otra causa (nunca tuvieron fila aquí).
     *
     * @return Collection<int, Suspension>
     */
    public function pendientesDeCausante(Model $causante): Collection
    {
        return Suspension::query()
            ->where('causante_type', $causante->getMorphClass())
            ->where('causante_id', $causante->getKey())
            ->whereNull('levantada_en')
            ->with('entidad')
            ->latest('suspendida_en')
            ->get();
    }

    /**
     * Checklist listo para presentar: cada suspensión vigente de este
     * causante junto con si REALMENTE puede reactivarse ahora mismo y, si no,
     * por qué — para que el usuario no tenga que descubrir las dependencias
     * por ensayo y error (ver `PanelSuspendidos.vue`).
     *
     * @return list<array{suspension: Suspension, puede_reactivarse: bool, motivos: list<string>}>
     */
    public function checklistDe(Model $causante): array
    {
        $filas = [];

        foreach ($this->pendientesDeCausante($causante) as $suspension) {
            $motivos = $this->motivosDeSuspension($suspension);

            $filas[] = [
                'suspension' => $suspension,
                'puede_reactivarse' => $motivos === [],
                'motivos' => $motivos,
            ];
        }

        return $filas;
    }

    /**
     * Convierte `checklistDe()` al array plano que consume
     * `PanelSuspendidos.vue` (mismo shape en Empresas/Sucursales/Activos).
     *
     * @param  list<array{suspension: Suspension, puede_reactivarse: bool, motivos: list<string>}>  $checklist
     * @return list<array{id: int, tipo: string, nombre: string|null, suspendida_en: string, puede_reactivarse: bool, motivos: list<string>}>
     */
    public function paraVista(array $checklist): array
    {
        return array_map(fn (array $item): array => [
            'id' => $item['suspension']->id,
            'tipo' => $item['suspension']->etiquetaTipo(),
            'nombre' => $item['suspension']->etiquetaEntidad(),
            'suspendida_en' => $item['suspension']->suspendida_en->toDateTimeString(),
            'puede_reactivarse' => $item['puede_reactivarse'],
            'motivos' => $item['motivos'],
        ], $checklist);
    }

    /**
     * Reactiva UN dependiente suspendido, sólo si ya no le queda ninguna
     * dependencia obligatoria inactiva (Empresa/Sucursal/componentes). Si
     * todavía existe una causa real, NO reactiva nada y conserva la
     * suspensión — nunca deja un estado a medias. Devuelve si de verdad se
     * reactivó.
     */
    public function reactivar(Suspension $suspension, ?int $realizadoPor): bool
    {
        $modelo = $this->entidadDe($suspension);

        if ($modelo === null || $this->operatividad->dependenciasNoOperativas($modelo) !== []) {
            return false;
        }

        DB::transaction(function () use ($modelo, $suspension, $realizadoPor): void {
            $modelo->update([$suspension->columna_activo => true]);
            $suspension->update(['levantada_en' => now(), 'levantada_por' => $realizadoPor]);
        });

        return true;
    }

    /**
     * Reactiva sólo las suspensiones VIGENTES de este causante cuyo id esté
     * en `$ids` Y que ya no tengan otra dependencia obligatoria inactiva —
     * ignora cualquier id que no le pertenezca a `$causante` o que ya haya
     * sido levantado. Las que no se pudieron reactivar de verdad quedan en
     * `omitidos` con el motivo, para que la UI lo muestre (nunca un 200 mudo
     * sobre algo que en realidad no cambió).
     *
     * @param  array<int, int|string>  $ids
     * @return array{reactivados: int, omitidos: list<array{id: int, motivos: list<string>}>}
     */
    public function reactivarSeleccionados(Model $causante, array $ids, ?int $realizadoPor): array
    {
        $suspensiones = $this->pendientesDeCausante($causante)->whereIn('id', $ids);

        $reactivados = 0;
        $omitidos = [];

        foreach ($suspensiones as $suspension) {
            if ($this->reactivar($suspension, $realizadoPor)) {
                $reactivados++;

                continue;
            }

            $omitidos[] = [
                'id' => $suspension->id,
                'motivos' => $this->motivosDeSuspension($suspension),
            ];
        }

        return ['reactivados' => $reactivados, 'omitidos' => $omitidos];
    }

    /**
     * @return list<string>
     */
    private function motivosDeSuspension(Suspension $suspension): array
    {
        $modelo = $this->entidadDe($suspension);

        return $modelo === null ? [] : $this->operatividad->dependenciasNoOperativas($modelo);
    }

    private function entidadDe(Suspension $suspension): ?Model
    {
        /** @var class-string<Model> $clase */
        $clase = $suspension->entidad_type;

        return $clase::query()->find($suspension->entidad_id);
    }
}
