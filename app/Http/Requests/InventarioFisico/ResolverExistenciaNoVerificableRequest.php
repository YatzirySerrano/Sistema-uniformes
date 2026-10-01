<?php

namespace App\Http\Requests\InventarioFisico;

use App\Models\InventarioFisico;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Marcar un renglón por cantidad como «No fue posible verificar» (motivo
 * OPCIONAL) o reabrirlo a Pendiente. Misma autorización que contar
 * (`InventarioFisicoPolicy::administrar`: permiso + acceso a la empresa).
 */
class ResolverExistenciaNoVerificableRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ronda = $this->route('inventarioFisico');

        return $ronda instanceof InventarioFisico
            && ($this->user()?->can('administrar', $ronda) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['nullable', 'string', 'max:255'],
            'verificada_en_vista' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.max' => 'El motivo no puede superar 255 caracteres.',
        ];
    }
}
