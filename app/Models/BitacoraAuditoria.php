<?php

namespace App\Models;

use App\Enums\RolSistema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora append-only. Se escribe exclusivamente desde
 * App\Servicios\ServicioAuditoria y nunca se actualiza ni elimina.
 *
 * @property int $id
 * @property int|null $usuario_id
 * @property bool|null $realizada_por_superadministrador
 * @property int|null $empresa_id
 * @property string $modulo
 * @property string $accion
 * @property string|null $tipo_entidad
 * @property int|null $entidad_id
 * @property array<string, mixed>|null $valores_anteriores
 * @property array<string, mixed>|null $valores_nuevos
 */
class BitacoraAuditoria extends Model
{
    protected $table = 'bitacora_auditoria';

    public const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'nombre_usuario_snapshot',
        'realizada_por_superadministrador',
        'empresa_id',
        'sucursal_id',
        'modulo',
        'accion',
        'tipo_entidad',
        'entidad_id',
        'descripcion',
        'valores_anteriores',
        'valores_nuevos',
        'motivo',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'valores_anteriores' => 'array',
            'valores_nuevos' => 'array',
            'realizada_por_superadministrador' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * ÚNICA definición de qué registros puede recibir un observador. Un
     * Superadministrador ve todo; cualquier otro usuario (Administrador,
     * roles base o personalizados, con `auditoria.ver` o no) jamás recibe
     * acciones realizadas por un Superadministrador. Se decide por el
     * snapshot HISTÓRICO `realizada_por_superadministrador`, nunca por el rol
     * actual del actor; sólo las filas anteriores al snapshot (`null`, sin
     * evidencia) caen a la comprobación conservadora del rol actual, y si su
     * actor ya no existe (quedó su nombre pero no su `usuario_id`) quedan
     * ocultas. Una fila sin actor alguno (ni id ni nombre) es una acción de
     * sistema y es visible — mismo criterio que el backfill de la migración.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisiblePara(Builder $query, User $observador): Builder
    {
        if ($observador->esSuperadministrador()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('realizada_por_superadministrador', false)
            ->orWhere(fn (Builder $legado) => $legado
                ->whereNull('realizada_por_superadministrador')
                ->where(fn (Builder $actor) => $actor
                    ->where(fn (Builder $sistema) => $sistema->whereNull('usuario_id')->whereNull('nombre_usuario_snapshot'))
                    ->orWhere(fn (Builder $existente) => $existente
                        ->whereNotNull('usuario_id')
                        ->whereDoesntHave('usuario', fn (Builder $u) => $u->whereHas(
                            'roles',
                            fn (Builder $r) => $r->where('name', RolSistema::Superadministrador->value),
                        ))))));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}
