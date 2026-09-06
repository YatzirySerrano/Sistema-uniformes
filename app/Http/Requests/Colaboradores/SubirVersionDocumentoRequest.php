<?php

namespace App\Http\Requests\Colaboradores;

use App\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Nueva versión de un documento ya existente del expediente. Nunca reemplaza
 * ni borra la versión anterior.
 */
class SubirVersionDocumentoRequest extends FormRequest
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
            'archivo' => [
                'required', 'file',
                'mimes:pdf,jpg,jpeg,png,webp,xls,xlsx,doc,docx,csv,txt',
                'max:10240',
            ],
            'comentario' => ['nullable', 'string', 'max:300'],
        ];
    }
}
