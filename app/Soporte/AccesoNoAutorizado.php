<?php

namespace App\Soporte;

use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Texto que ve el usuario final ante un acceso no autorizado (403). Nunca
 * expone el mensaje técnico por defecto de Laravel («This action is
 * unauthorized.»), nombres de Policies, permisos, clases ni rutas: sólo
 * conserva un mensaje propio del sistema cuando alguien lo escribió a
 * propósito en español para el usuario (p. ej. `abort(403, 'No tienes acceso
 * a la empresa de ese colaborador.')`).
 */
final class AccesoNoAutorizado
{
    public const MENSAJE_GENERAL = 'No tienes permiso para realizar esta acción.';

    /**
     * Mensajes por defecto del framework que nunca deben llegar al usuario.
     *
     * @var list<string>
     */
    private const MENSAJES_TECNICOS = [
        '',
        'This action is unauthorized.',
        'Forbidden',
        'Unauthorized.',
        'Invalid signature.',
        'User does not have the right permissions.',
        'User does not have the right roles.',
    ];

    public static function mensajePara(Throwable $excepcion): string
    {
        $mensaje = trim($excepcion->getMessage());

        if (! $excepcion instanceof HttpExceptionInterface && ! $excepcion instanceof AuthorizationException) {
            return self::MENSAJE_GENERAL;
        }

        if (in_array($mensaje, self::MENSAJES_TECNICOS, true) || ! self::pareceMensajeDeUsuario($mensaje)) {
            return self::MENSAJE_GENERAL;
        }

        return $mensaje;
    }

    /**
     * Un mensaje escrito para el usuario empieza con mayúscula, es corto y no
     * trae rastros técnicos (clases con `\`, rutas, `::`, llaves de permiso
     * `recurso.accion`).
     */
    private static function pareceMensajeDeUsuario(string $mensaje): bool
    {
        if (mb_strlen($mensaje) > 200) {
            return false;
        }

        return preg_match('/[\\\\\/]|::|\b[a-z-]+\.[a-z-]+\b/u', $mensaje) !== 1;
    }
}
