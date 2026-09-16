<?php

namespace App\Http\Requests\Datos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * Validación de la subida del Excel maestro. Restringido a
 * `datos.importar_maestro`: es una herramienta de arranque de plataforma, no
 * un módulo de negocio (ver `App\Soporte\Permisos`).
 */
class ImportarBaseMaestraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('datos.importar_maestro') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('archivo')) {
                return;
            }

            $archivo = $this->file('archivo');

            if (! $archivo instanceof UploadedFile || ! $this->tieneFirmaXlsx($archivo)) {
                $validator->errors()->add('archivo', 'El archivo no es un Excel (.xlsx) válido.');
            }
        });
    }

    /**
     * Un .xlsx es en realidad un ZIP: valida la firma real de los primeros
     * bytes, no sólo la extensión/MIME reportados por el navegador (mismo
     * espíritu defensivo que `App\Servicios\ServicioEvidencias`).
     */
    private function tieneFirmaXlsx(UploadedFile $archivo): bool
    {
        $manejador = @fopen($archivo->getRealPath(), 'rb');

        if ($manejador === false) {
            return false;
        }

        $firma = fread($manejador, 4);
        fclose($manejador);

        return $firma === "PK\x03\x04";
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => 'Selecciona el archivo Excel de la base maestra.',
            'archivo.mimes' => 'El archivo debe ser un Excel (.xlsx).',
            'archivo.max' => 'El archivo no puede superar los 20 MB.',
        ];
    }
}
