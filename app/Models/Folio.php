<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contador atómico GLOBAL para folios legibles por tipo de documento y año
 * (nunca partido por empresa: los folios son únicos en toda la plataforma).
 * Se consume mediante App\Servicios\ServicioFolios dentro de una transacción
 * con bloqueo pesimista.
 *
 * @property int $id
 * @property string $tipo
 * @property int $anio
 * @property int $consecutivo
 */
class Folio extends Model
{
    protected $table = 'folios';

    protected $fillable = [
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
