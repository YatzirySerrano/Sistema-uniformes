<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\ColaboradorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $empresa_id
 * @property int $sucursal_id
 * @property int|null $usuario_id
 * @property string $numero_empleado
 * @property string $nombre_completo
 * @property bool $activo
 */
class Colaborador extends Model
{
    /** @use HasFactory<ColaboradorFactory> */
    use HasFactory, PerteneceAEmpresa, SoftDeletes;

    protected $table = 'colaboradores';

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'usuario_id',
        'numero_empleado',
        'nombre_completo',
        'puesto',
        'area',
        'correo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * @return HasMany<EntregaUniforme, $this>
     */
    public function entregas(): HasMany
    {
        return $this->hasMany(EntregaUniforme::class);
    }

    /**
     * @return HasMany<Devolucion, $this>
     */
    public function devoluciones(): HasMany
    {
        return $this->hasMany(Devolucion::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
