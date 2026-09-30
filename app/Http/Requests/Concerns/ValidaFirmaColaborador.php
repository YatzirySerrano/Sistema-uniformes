<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * Firma de QUIEN RECIBE (Entregas) o QUIEN DEVUELVE (Devoluciones): se
 * dibuja en el pad (`firma`, data URI) o se sube un archivo (`firma_archivo`,
 * firma a distancia). Una sola fuente: con `firma_metodo = archivo` la firma
 * dibujada está prohibida y viceversa. Sin `firma_metodo` se asume
 * `dibujada` (compatibilidad con clientes previos). La firma del encargado
 * (`firma_operador`) no cambia: siempre dibujada.
 *
 * Aquí se valida extensión + MIME detectado por el servidor + peso; el
 * contenido real (magic bytes, imagen decodificable, PDF) lo revalida
 * `ValidadorFirma::validarArchivo()` antes de guardar nada.
 */
trait ValidaFirmaColaborador
{
    public const METODO_FIRMA_DIBUJADA = 'dibujada';

    public const METODO_FIRMA_ARCHIVO = 'archivo';

    /** Peso máximo del archivo de firma, en KB (5 MB). */
    public const FIRMA_ARCHIVO_MAX_KB = 5120;

    /**
     * @return array<string, mixed>
     */
    protected function reglasFirmaColaborador(): array
    {
        return [
            'firma_metodo' => ['nullable', Rule::in([self::METODO_FIRMA_DIBUJADA, self::METODO_FIRMA_ARCHIVO])],
            'firma' => [
                'nullable',
                Rule::requiredIf(fn (): bool => ! $this->firmaPorArchivo()),
                Rule::prohibitedIf(fn (): bool => $this->firmaPorArchivo()),
                'string', 'max:3000000',
            ],
            'firma_archivo' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->firmaPorArchivo()),
                Rule::prohibitedIf(fn (): bool => ! $this->firmaPorArchivo()),
                'file',
                'mimes:png,jpg,jpeg,webp,pdf',
                'mimetypes:image/png,image/jpeg,image/webp,application/pdf',
                'max:'.self::FIRMA_ARCHIVO_MAX_KB,
            ],
        ];
    }

    public function firmaPorArchivo(): bool
    {
        return $this->input('firma_metodo') === self::METODO_FIRMA_ARCHIVO;
    }

    /**
     * @return array<string, string>
     */
    protected function mensajesFirmaColaborador(string $deQuien): array
    {
        return [
            'firma.required' => "Solicita la firma {$deQuien} para continuar.",
            'firma.prohibited' => 'Elegiste subir un archivo de firma: no envíes también una firma dibujada.',
            'firma_archivo.required' => "Sube el archivo con la firma {$deQuien}.",
            'firma_archivo.prohibited' => 'Elegiste la firma dibujada: no envíes también un archivo de firma.',
            'firma_archivo.file' => 'El archivo de firma no se recibió correctamente. Vuelve a seleccionarlo.',
            'firma_archivo.mimes' => 'El archivo de firma debe ser una imagen PNG, JPG o WEBP, o un PDF.',
            'firma_archivo.mimetypes' => 'El contenido del archivo de firma no es una imagen PNG, JPG o WEBP ni un PDF.',
            'firma_archivo.max' => 'El archivo de firma no puede pesar más de 5 MB.',
            'firma_metodo.in' => 'El método de firma debe ser «dibujada» o «archivo».',
        ];
    }
}
