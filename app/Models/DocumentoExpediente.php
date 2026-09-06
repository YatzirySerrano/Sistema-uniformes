<?php

namespace App\Models;

use App\Enums\CategoriaDocumentoExpediente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * "Slot" estable de un documento del expediente digital del colaborador
 * (identidad + categoría + nombre). El archivo físico vigente vive en
 * `versionActual()`; esta tabla nunca guarda una ruta directamente para que
 * el historial de versiones sea la única fuente de verdad del contenido.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property CategoriaDocumentoExpediente $categoria
 * @property string $nombre
 * @property string|null $descripcion
 * @property bool $activo
 * @property int|null $creado_por
 */
class DocumentoExpediente extends Model
{
    protected $table = 'documentos_expediente';

    protected $fillable = [
        'colaborador_id',
        'categoria',
        'nombre',
        'descripcion',
        'activo',
        'creado_por',
    ];

    protected function casts(): array
    {
        return [
            'categoria' => CategoriaDocumentoExpediente::class,
            'activo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * @return HasMany<VersionDocumentoExpediente, $this>
     */
    public function versiones(): HasMany
    {
        return $this->hasMany(VersionDocumentoExpediente::class);
    }

    /**
     * Versión vigente: la de número más alto. Nunca se persiste un puntero
     * aparte para evitar una FK circular con `documento_expediente_versiones`.
     *
     * @return HasOne<VersionDocumentoExpediente, $this>
     */
    public function versionActual(): HasOne
    {
        return $this->hasOne(VersionDocumentoExpediente::class)->latestOfMany('version');
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
