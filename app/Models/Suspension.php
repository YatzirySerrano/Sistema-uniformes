<?php

namespace App\Models;

use Database\Factories\SuspensionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Rastro de una desactivación por cascada (Fase 7): `entidad` es el
 * dependiente que quedó `activo = false` como consecuencia de desactivar
 * `causante` (p. ej. una Sucursal suspendida al desactivar su Empresa). Sólo
 * existe para dependientes que estaban activos en el momento de la cascada —
 * nunca se crea para algo ya inactivo por otra causa, así la reactivación
 * selectiva del causante (`ServicioCascadaSuspension::pendientesDeCausante()`)
 * jamás revive algo que no le pertenecía. `columna_activo` guarda el nombre
 * real de la columna de estado del modelo (no todos usan el mismo: Empresa/
 * Sucursal/Área usan `activa`, el resto `activo`).
 *
 * @property int $id
 * @property class-string<Model> $entidad_type
 * @property int $entidad_id
 * @property string $columna_activo
 * @property class-string<Model> $causante_type
 * @property int $causante_id
 * @property string|null $motivo
 * @property Carbon $suspendida_en
 * @property Carbon|null $levantada_en
 */
class Suspension extends Model
{
    /** @use HasFactory<SuspensionFactory> */
    use HasFactory;

    protected $table = 'suspensiones';

    protected $fillable = [
        'entidad_type',
        'entidad_id',
        'columna_activo',
        'causante_type',
        'causante_id',
        'motivo',
        'suspendida_por',
        'levantada_en',
        'levantada_por',
    ];

    protected function casts(): array
    {
        return [
            'suspendida_en' => 'datetime',
            'levantada_en' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function entidad(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function causante(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function suspendidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspendida_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function levantadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'levantada_por');
    }

    public function estaVigente(): bool
    {
        return $this->levantada_en === null;
    }

    /**
     * Nombre legible del dependiente suspendido, sea cual sea su modelo
     * (cada uno usa un campo distinto: `nombre`, `nombre_completo`…). `null`
     * si el registro ya no existe (nunca debería pasar: la cascada nunca
     * borra, sólo desactiva).
     */
    public function etiquetaEntidad(): ?string
    {
        $modelo = $this->entidad;

        if ($modelo === null) {
            return null;
        }

        return $modelo->nombre_completo ?? $modelo->nombre ?? null;
    }

    public function etiquetaTipo(): string
    {
        return match ($this->entidad_type) {
            Sucursal::class => 'Sucursal',
            Colaborador::class => 'Colaborador',
            Area::class => 'Área',
            Activo::class => 'Activo',
            Conjunto::class => 'Conjunto',
            default => class_basename($this->entidad_type),
        };
    }
}
