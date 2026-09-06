<?php

namespace App\Http\Requests\Colaboradores;

use App\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambia únicamente la foto de perfil del colaborador (desde el diálogo
 * dedicado del perfil), sin exigir el resto de los campos del formulario
 * completo de edición.
 */
class ActualizarFotoColaboradorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');

        return $colaborador instanceof Colaborador
            && ($this->user()?->can('update', $colaborador) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ];
    }
}
