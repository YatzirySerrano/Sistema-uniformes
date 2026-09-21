<?php

namespace App\Http\Requests\Colaboradores;

use App\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Validación de la subida del Excel/CSV de colaboradores. La empresa destino
 * viaja en `empresa_id`; el acceso a esa empresa lo revalida el controller
 * (`ConEmpresa::resolverEmpresa`), no este Request.
 */
class AnalizarImportacionColaboradoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('importar', Colaborador::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'empresa_id' => ['required', 'integer'],
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('archivo')) {
                return;
            }

            $archivo = $this->file('archivo');

            if (! $archivo instanceof UploadedFile || ! $this->tieneFirmaValida($archivo)) {
                $validator->errors()->add('archivo', 'El archivo no parece ser un Excel/CSV válido.');
            }
        });
    }

    /**
     * Firma real de los primeros bytes según la extensión ya restringida por
     * `mimes:xlsx,xls,csv` (mismo espíritu defensivo que
     * `ImportarBaseMaestraRequest::tieneFirmaXlsx`): un .xlsx es en realidad
     * un ZIP ("PK\x03\x04"), un .xls legado es un contenedor OLE2
     * ("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"). Un .csv es texto plano sin firma
     * binaria propia, así que sólo se rechaza si trae disfrazada alguna de
     * esas dos firmas binarias (un Excel real renombrado a .csv).
     */
    private function tieneFirmaValida(UploadedFile $archivo): bool
    {
        $manejador = @fopen($archivo->getRealPath(), 'rb');

        if ($manejador === false) {
            return false;
        }

        $inicio = (string) fread($manejador, 8);
        fclose($manejador);

        return match (Str::lower($archivo->getClientOriginalExtension())) {
            'xlsx' => str_starts_with($inicio, "PK\x03\x04"),
            'xls' => str_starts_with($inicio, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'csv' => ! str_starts_with($inicio, "PK\x03\x04") && ! str_starts_with($inicio, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empresa_id.required' => 'Selecciona la empresa destino.',
            'archivo.required' => 'Selecciona el archivo a importar.',
            'archivo.mimes' => 'El archivo debe ser XLSX, XLS o CSV.',
            'archivo.max' => 'El archivo no puede superar los 10 MB.',
        ];
    }
}
