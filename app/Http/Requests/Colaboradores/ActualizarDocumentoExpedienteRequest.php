<?php

namespace App\Http\Requests\Colaboradores;

use App\Enums\CategoriaDocumentoExpediente;
use App\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de metadatos de un documento del expediente (nombre, descripción,
 * categoría). No toca el archivo ni el historial de versiones.
 */
class ActualizarDocumentoExpedienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');

        return $colaborador instanceof Colaborador
            && ($this->user()?->can('administrarExpediente', $colaborador) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'categoria' => ['required', Rule::enum(CategoriaDocumentoExpediente::class)],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }
}
