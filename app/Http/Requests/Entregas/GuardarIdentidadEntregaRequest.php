<?php

namespace App\Http\Requests\Entregas;

use App\Models\Colaborador;
use App\Models\EntregaUniforme;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Captura de una identificación oficial FALTANTE durante el flujo de entrega.
 * Autorización de MÍNIMO PRIVILEGIO — el mismo criterio que ver la INE durante
 * la firma (`EntregaController::autorizarConsultaIdentidad`): basta poder
 * registrar entregas y tener al colaborador dentro del alcance de empresa. NO
 * concede administración del expediente; el reemplazo de una INE ya existente
 * sigue viviendo en el módulo de expediente.
 */
class GuardarIdentidadEntregaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');
        $usuario = $this->user();

        return $colaborador instanceof Colaborador
            && $usuario !== null
            && $usuario->can('create', EntregaUniforme::class)
            && $usuario->puedeAccederEmpresa($colaborador->empresa_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => 'Adjunta la foto o el archivo de la identificación.',
            'archivo.mimes' => 'La identificación debe ser una imagen (JPG, PNG, WebP) o un PDF.',
            'archivo.max' => 'El archivo de la identificación no puede superar los 10 MB.',
        ];
    }
}
