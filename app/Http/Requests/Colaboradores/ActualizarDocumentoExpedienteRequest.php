<?php

namespace App\Http\Requests\Colaboradores;

use App\Enums\CategoriaDocumentoExpediente;
use App\Models\Colaborador;
use App\Models\DocumentoExpediente;
use App\Models\VersionDocumentoExpediente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Edición de metadatos de un documento del expediente (nombre, descripción,
 * categoría). No toca el archivo ni el historial de versiones.
 *
 * La CATEGORÍA es una frontera histórica de visibilidad (personal vs.
 * empresarial): una vez que el slot tiene al menos una versión registrada, su
 * categoría queda INMUTABLE — cambiarla reescribiría retroactivamente qué
 * empresas ven qué versiones. `nombre` y `descripcion` siguen editables.
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $documento = $this->route('documento');

            if (! $documento instanceof DocumentoExpediente || $validator->errors()->has('categoria')) {
                return;
            }

            $cambiaCategoria = $this->input('categoria') !== $documento->categoria->value;

            if (! $cambiaCategoria) {
                return;
            }

            $tieneVersiones = VersionDocumentoExpediente::query()
                ->where('documento_expediente_id', $documento->getKey())
                ->exists();

            if ($tieneVersiones) {
                $validator->errors()->add(
                    'categoria',
                    'La categoría de un documento con versiones registradas no puede modificarse.',
                );
            }
        });
    }
}
