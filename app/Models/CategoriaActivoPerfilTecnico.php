<?php

namespace App\Models;

use App\Enums\PerfilTecnicoUnidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perfil técnico (Celular / Computadora / Tablet) de una categoría de activo,
 * 1:1 con `CategoriaActivo`. Separado de `categorias_activo.codigo` (que es el
 * identificador operativo del catálogo): son conceptos distintos.
 *
 * @property int $id
 * @property int $categoria_activo_id
 * @property PerfilTecnicoUnidad $perfil
 */
class CategoriaActivoPerfilTecnico extends Model
{
    protected $table = 'categoria_activo_perfil_tecnico';

    protected $fillable = [
        'categoria_activo_id',
        'perfil',
    ];

    protected function casts(): array
    {
        return [
            'perfil' => PerfilTecnicoUnidad::class,
        ];
    }

    /**
     * @return BelongsTo<CategoriaActivo, $this>
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaActivo::class, 'categoria_activo_id');
    }
}
