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
 * @property int|null $area_id
 * @property int|null $servicio_actual_id
 * @property string $numero_empleado
 * @property string $nombre_completo
 * @property string|null $area
 * @property string|null $foto_ruta
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
        'area_id',
        'servicio_actual_id',
        'correo',
        'foto_ruta',
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
     * Relación estructurada con el área/departamento. Se llama `departamento`
     * (no `area`) para no colisionar con la columna de texto `area`, que se
     * conserva como espejo temporal. La fuente de verdad es `area_id`.
     *
     * @return BelongsTo<Area, $this>
     */
    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * Ubicación operativa VIGENTE del colaborador (puesto/servicio de
     * vigilancia). Nullable: puede no tener servicio asignado todavía, estar
     * temporalmente fuera de servicio o ser personal administrativo. Se
     * cambia únicamente vía `App\Acciones\CambiarServicioColaborador` — nunca
     * a través del formulario general de edición.
     *
     * @return BelongsTo<Servicio, $this>
     */
    public function servicioActual(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_actual_id');
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
     * @return HasMany<DocumentoExpediente, $this>
     */
    public function documentosExpediente(): HasMany
    {
        return $this->hasMany(DocumentoExpediente::class);
    }

    /**
     * @return HasMany<UnidadActivo, $this>
     */
    public function unidadesActivo(): HasMany
    {
        return $this->hasMany(UnidadActivo::class);
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
