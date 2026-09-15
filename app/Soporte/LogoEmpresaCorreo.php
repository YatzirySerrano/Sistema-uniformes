<?php

namespace App\Soporte;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Resuelve el logotipo de una empresa para incrustarlo INLINE en un correo
 * vía Content-ID (`$message->embedData()` — API real de
 * `Illuminate\Mail\Message`, soportada por Symfony Mailer). Nunca una URL
 * remota (muchos clientes de correo la bloquean o no pueden alcanzar un
 * dominio de desarrollo/interno) ni un data URI (mismo HTML crece mucho y
 * algunos clientes de escritorio lo tratan peor que un adjunto inline real).
 *
 * SVG queda fuera a propósito: es un formato de logo válido para subir
 * (`GuardarEmpresaRequest`) y para el PDF/Excel (`ContextoExportacion`), pero
 * el soporte de SVG embebido en correo es inconsistente entre clientes
 * (Outlook de escritorio no lo renderiza) — mostrarlo ahí arriesga una
 * imagen rota. Cuando el logo no se puede resolver (no existe, formato no
 * soportado, o no hay logo), `resolver()` devuelve `null` y el layout del
 * correo pinta un placeholder con la inicial de la empresa en el color de
 * marca — nunca dejar el hueco vacío ni un ícono roto.
 */
final class LogoEmpresaCorreo
{
    /**
     * @return array{binario: string, mime: string, nombre: string}|null
     */
    public static function resolver(?string $ruta): ?array
    {
        if ($ruta === null) {
            return null;
        }

        $extension = Str::lower(pathinfo($ruta, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            default => null,
        };

        if ($mime === null) {
            return null;
        }

        try {
            if (! Storage::disk('public')->exists($ruta)) {
                return null;
            }

            $binario = Storage::disk('public')->get($ruta);
        } catch (\Throwable) {
            return null;
        }

        if ($binario === null) {
            return null;
        }

        return [
            'binario' => $binario,
            'mime' => $mime,
            'nombre' => 'logo-empresa.'.$extension,
        ];
    }
}
