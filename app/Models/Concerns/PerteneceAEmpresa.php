<?php

namespace App\Models\Concerns;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aporta la relación con la empresa propietaria del registro y ámbitos de
 * consulta explícitos para el aislamiento multiempresa. El aislamiento NO se
 * aplica mediante un global scope automático: cada consulta de dominio debe
 * acotarse de forma explícita con deEmpresa() y las Policies revalidan el
 * acceso. Las vistas globales se programan deliberadamente sin este ámbito.
 */
trait PerteneceAEmpresa
{
    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDeEmpresa(Builder $query, Empresa|int|null $empresa): Builder
    {
        $empresaId = $empresa instanceof Empresa ? $empresa->getKey() : $empresa;

        return $query->where($this->qualifyColumn('empresa_id'), $empresaId);
    }
}
