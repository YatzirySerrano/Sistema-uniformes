<?php

namespace App\Servicios;

use App\Enums\RolSistema;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Colaborador;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Vínculo EXPLÍCITO cuenta de acceso (User) ↔ ficha de colaborador, 1:1
 * (índice único en `colaboradores.usuario_id`). Es lo que da significado a
 * "mi custodia": nunca se infiere por nombre, correo, rol ni puesto.
 *
 * Una cuenta es ELEGIBLE para un colaborador si está activa, no representa ya
 * a otra ficha y puede operar la empresa del colaborador (alcance global o
 * `empresa_usuario`, la misma regla que `User::puedeAccederEmpresa`). Una
 * cuenta de Superadministrador sólo la vincula otro Superadministrador (mismo
 * criterio de `UserPolicy`).
 */
class ServicioCuentaColaborador
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    /**
     * @return Collection<int, array{id: int, name: string, email: string}>
     */
    public function elegibles(Colaborador $colaborador, User $actor, string $termino = ''): Collection
    {
        return $this->consultaElegibles($colaborador, $actor)
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s
                ->where('name', 'like', "%{$termino}%")
                ->orWhere('email', 'like', "%{$termino}%")))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]);
    }

    /**
     * Vincula (o desvincula con `null`) la cuenta del colaborador. Revalida
     * elegibilidad bajo lock; si otra operación tomó la misma cuenta en
     * paralelo, el índice único lo impide y se responde en español.
     */
    public function vincular(Colaborador $colaborador, ?int $usuarioId, User $actor): Colaborador
    {
        try {
            return DB::transaction(function () use ($colaborador, $usuarioId, $actor): Colaborador {
                /** @var Colaborador $colaborador */
                $colaborador = Colaborador::query()->whereKey($colaborador->getKey())->lockForUpdate()->firstOrFail();
                $anterior = $colaborador->usuario_id !== null ? User::query()->find($colaborador->usuario_id) : null;

                if ($usuarioId === $colaborador->usuario_id) {
                    return $colaborador;
                }

                $nueva = null;
                if ($usuarioId !== null) {
                    $nueva = $this->consultaElegibles($colaborador, $actor)->whereKey($usuarioId)->lockForUpdate()->first();

                    if ($nueva === null) {
                        throw new ExcepcionDeNegocioSimple('Esa cuenta no puede vincularse: está inactiva, ya representa a otro colaborador o no tiene acceso a la empresa de este colaborador.');
                    }
                }

                $colaborador->update(['usuario_id' => $nueva?->getKey()]);

                $this->auditoria->registrar('colaboradores', $nueva === null ? 'desvincular_cuenta' : 'vincular_cuenta', [
                    'tipo_entidad' => Colaborador::class,
                    'entidad_id' => $colaborador->getKey(),
                    'empresa_id' => $colaborador->empresa_id,
                    'descripcion' => $nueva === null
                        ? "Se desvinculó la cuenta de acceso de {$colaborador->nombre_completo}."
                        : "Cuenta de acceso de {$colaborador->nombre_completo}: {$nueva->email}.",
                    'valores_anteriores' => ['cuenta' => $anterior === null ? 'Sin cuenta' : "{$anterior->name} <{$anterior->email}>"],
                    'valores_nuevos' => ['cuenta' => $nueva === null ? 'Sin cuenta' : "{$nueva->name} <{$nueva->email}>"],
                ]);

                return $colaborador;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ExcepcionDeNegocioSimple('Esa cuenta ya representa a otro colaborador.');
        }
    }

    /**
     * @return Builder<User>
     */
    private function consultaElegibles(Colaborador $colaborador, User $actor): Builder
    {
        $rolesGlobales = [RolSistema::Superadministrador->value, RolSistema::Administrador->value];

        return User::query()
            ->where('activo', true)
            ->where(fn (Builder $q) => $q
                ->whereDoesntHave('colaborador')
                ->orWhereHas('colaborador', fn (Builder $c) => $c->whereKey($colaborador->getKey())))
            // Misma regla de alcance que `User::puedeAccederEmpresa()`.
            ->where(fn (Builder $q) => $q
                ->whereHas('empresas', fn (Builder $e) => $e->whereKey($colaborador->empresa_id))
                ->orWhereHas('roles', fn (Builder $r) => $r->whereIn('name', $rolesGlobales)))
            ->when(! $actor->esSuperadministrador(), fn (Builder $q) => $q
                ->whereDoesntHave('roles', fn (Builder $r) => $r->where('name', RolSistema::Superadministrador->value)));
    }
}
