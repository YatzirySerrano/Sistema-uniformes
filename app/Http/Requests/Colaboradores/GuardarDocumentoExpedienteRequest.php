<?php

namespace App\Http\Requests\Colaboradores;

use App\Enums\CategoriaDocumentoExpediente;
use App\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta del primer documento de un "slot" del expediente. La empresa/colaborador
 * quedan fijados por el modelo de ruta `{colaborador}`.
 */
class GuardarDocumentoExpedienteRequest extends FormRequest
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
            'archivo' => [
                'required', 'file',
                'mimes:pdf,jpg,jpeg,png,webp,xls,xlsx,doc,docx,csv,txt',
                'max:10240',
            ],
        ];
    }
}
