<?php

namespace App\Soporte;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Resuelve el logotipo de una empresa como data URI embebible en un correo
 * (nunca una URL remota: muchos clientes de correo bloquean imágenes
 * externas o no pueden alcanzar un dominio de desarrollo/interno).
 *
 * SVG queda fuera a propósito: es un formato de logo válido para subir
 * (`GuardarEmpresaRequest`) y para el PDF/Excel (`ContextoExportacion`), pero
 * el soporte de SVG embebido en correo es inconsistente entre clientes
 * (Outlook de escritorio no lo renderiza) — mostrarlo ahí arriesga una
 * imagen rota en vez de mostrarlo simplemente. Cuando el logo no se puede
 * embeber (no existe, formato no soportado, o no hay logo), `dataUri()`
 * devuelve `null` y el layout del correo pinta un placeholder con la
 * inicial de la empresa en el color de marca — nunca dejar el hueco vacío
 * ni un ícono roto.
 */
final class LogoEmpresaCorreo
{
    public static function dataUri(?string $ruta): ?string
    {
        if ($ruta === null) {
            return null;
        }

        $mime = match (Str::lower(pathinfo($ruta, PATHINFO_EXTENSION))) {
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

            $contenido = Storage::disk('public')->get($ruta);
        } catch (\Throwable) {
            return null;
        }

        return $contenido === null ? null : 'data:'.$mime.';base64,'.base64_encode($contenido);
    }
}
