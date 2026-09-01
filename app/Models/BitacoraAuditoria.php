<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora append-only. Se escribe exclusivamente desde
 * App\Servicios\ServicioAuditoria y nunca se actualiza ni elimina.
 *
 * @property int $id
 * @property int|null $usuario_id
 * @property int|null $empresa_id
 * @property string $modulo
 * @property string $accion
 * @property string|null $tipo_entidad
 * @property int|null $entidad_id
 */
class BitacoraAuditoria extends Model
{
    protected $table = 'bitacora_auditoria';

    public const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'nombre_usuario_snapshot',
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
            'created_at' => 'datetime',
        ];
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
}
