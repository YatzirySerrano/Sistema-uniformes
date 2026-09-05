<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contador atómico por ámbito, para catálogos SIN dimensión de empresa (p.
 * ej. Almacén, Tipo de activo). Ver `App\Soporte\ServicioGeneradorCodigosGlobal`.
 *
 * @property int $id
 * @property string $ambito
 * @property int $ultimo_valor
 */
class SecuenciaCodigoGlobal extends Model
{
    protected $table = 'secuencias_codigo_globales';

    protected $fillable = [
        'ambito',
        'ultimo_valor',
    ];

    protected function casts(): array
    {
        return [
            'ultimo_valor' => 'integer',
        ];
    }
}
