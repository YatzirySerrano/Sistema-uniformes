<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contador atómico para folios legibles por tipo de documento, empresa y año.
 * Se consume mediante App\Servicios\ServicioFolios dentro de una transacción
 * con bloqueo pesimista.
 *
 * @property int $id
 * @property int|null $empresa_id
 * @property string $tipo
 * @property int $anio
 * @property int $consecutivo
 */
class Folio extends Model
{
    protected $table = 'folios';

    protected $fillable = [
        'empresa_id',
        'tipo',
        'anio',
        'consecutivo',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'consecutivo' => 'integer',
        ];
    }
}
