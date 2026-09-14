<?php

namespace App\Http\Requests\Concerns;

/**
 * Reglas de validación del archivo de una identificación oficial (INE)
 * capturada como faltante durante Entregas o Devoluciones. Único lugar de
 * estas reglas: evita que ambos flujos diverjan en formatos/peso permitidos.
 */
trait ReglasArchivoIdentidad
{
    /**
     * @return array<string, mixed>
     */
    protected function reglasArchivoIdentidad(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mensajesArchivoIdentidad(): array
    {
        return [
            'archivo.required' => 'Adjunta la foto o el archivo de la identificación.',
            'archivo.mimes' => 'La identificación debe ser una imagen (JPG, PNG, WebP) o un PDF.',
            'archivo.max' => 'El archivo de la identificación no puede superar los 10 MB.',
        ];
    }
}
