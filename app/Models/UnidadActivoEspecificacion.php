<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Datos técnicos de una unidad identificada (1:1 con `UnidadActivo`): marca,
 * modelo, IMEI, número telefónico, operador y plan. Todos nullable — una
 * unidad puede no tener línea (equipo sólo Wi-Fi / línea pendiente) y seguir
 * teniendo IMEI. Nunca contiene el `codigo` ni el `public_token` de la unidad
 * (esos son inmutables y viven en `unidades_activo`).
 *
 * @property int $id
 * @property int $unidad_activo_id
 * @property string|null $marca
 * @property string|null $modelo
 * @property string|null $imei
 * @property string|null $numero_telefonico
 * @property string|null $operador
 * @property string|null $plan
 */
class UnidadActivoEspecificacion extends Model
{
    protected $table = 'unidad_activo_especificaciones';

    protected $fillable = [
        'unidad_activo_id',
        'marca',
        'modelo',
        'imei',
        'numero_telefonico',
        'operador',
        'plan',
    ];

    /**
     * @return BelongsTo<UnidadActivo, $this>
     */
    public function unidad(): BelongsTo
    {
        return $this->belongsTo(UnidadActivo::class, 'unidad_activo_id');
    }

    /**
     * "Marca Modelo" en una línea, o null si no hay ninguno de los dos.
     */
    public function marcaModelo(): ?string
    {
        $texto = trim(implode(' ', array_filter([$this->marca, $this->modelo])));

        return $texto === '' ? null : $texto;
    }

    /**
     * IMEI enmascarado para listados / selectores (`••••4728`). El IMEI
     * completo sólo se muestra en el detalle autenticado y en el export
     * administrativo.
     */
    public function imeiMascara(): ?string
    {
        if ($this->imei === null || $this->imei === '') {
            return null;
        }

        return '••••'.mb_substr($this->imei, -4);
    }
}
