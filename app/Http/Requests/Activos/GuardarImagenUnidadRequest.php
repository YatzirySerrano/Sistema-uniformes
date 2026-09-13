<?php

namespace App\Http\Requests\Activos;

use App\Models\UnidadActivo;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sube/reemplaza la foto de una unidad. La validación MIME/contenido real
 * (más estricta que la regla `image` de Laravel: revisa bytes reales con
 * `getimagesize()`) la hace `ServicioEvidencias::guardarPendiente()`, ya
 * auditada y reutilizada tal cual — aquí sólo se acota tipo/peso de entrada.
 */
class GuardarImagenUnidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var UnidadActivo $unidad */
        $unidad = $this->route('unidad');

        return $this->user()?->can('administrar', $unidad) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'imagen' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'imagen.required' => 'Selecciona o toma una foto.',
            'imagen.image' => 'El archivo debe ser una imagen JPG, PNG o WebP.',
            'imagen.max' => 'La imagen no puede pesar más de 8 MB.',
        ];
    }
}
