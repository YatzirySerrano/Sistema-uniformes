<?php

namespace App\Soporte;

use App\Excepciones\ExcepcionDeNegocioSimple;

/**
 * Valida y normaliza una firma manuscrita recibida como base64 (data URI o
 * cadena pura). Verifica decodificación estricta, tipo real por magic bytes,
 * peso y dimensiones, y rechaza imágenes prácticamente vacías.
 */
class ValidadorFirma
{
    private const MAX_BYTES = 2_000_000; // 2 MB

    private const MIN_BYTES = 800;       // por debajo de esto es un lienzo vacío

    private const MIN_ANCHO = 200;

    private const MIN_ALTO = 60;

    /**
     * @return array{binario: string, mime: string, ancho: int, alto: int}
     */
    public function validar(string $entrada): array
    {
        $entrada = trim($entrada);

        if (str_starts_with($entrada, 'data:')) {
            $partes = explode(',', $entrada, 2);
            if (count($partes) !== 2 || ! str_contains($partes[0], 'base64')) {
                throw new ExcepcionDeNegocioSimple('La firma recibida no tiene un formato válido.');
            }
            $entrada = $partes[1];
        }

        $binario = base64_decode(strtr($entrada, ' ', '+'), true);

        if ($binario === false || $binario === '') {
            throw new ExcepcionDeNegocioSimple('No fue posible decodificar la firma. Vuelve a firmar.');
        }

        if (strlen($binario) < self::MIN_BYTES) {
            throw new ExcepcionDeNegocioSimple('La firma parece estar en blanco. Dibuja tu firma antes de confirmar.');
        }

        if (strlen($binario) > self::MAX_BYTES) {
            throw new ExcepcionDeNegocioSimple('La imagen de la firma es demasiado grande.');
        }

        $info = @getimagesizefromstring($binario);

        if ($info === false) {
            throw new ExcepcionDeNegocioSimple('El archivo de firma no es una imagen válida.');
        }

        [$ancho, $alto] = $info;
        $mime = $info['mime'];

        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            throw new ExcepcionDeNegocioSimple('La firma debe ser una imagen PNG o JPEG.');
        }

        if ($mime === 'image/png' && ! str_starts_with($binario, "\x89PNG\x0d\x0a\x1a\x0a")) {
            throw new ExcepcionDeNegocioSimple('La firma no es un PNG válido.');
        }

        if ($mime === 'image/jpeg' && ! str_starts_with($binario, "\xFF\xD8\xFF")) {
            throw new ExcepcionDeNegocioSimple('La firma no es un JPEG válido.');
        }

        if ($ancho < self::MIN_ANCHO || $alto < self::MIN_ALTO) {
            throw new ExcepcionDeNegocioSimple('El área de la firma es demasiado pequeña.');
        }

        return ['binario' => $binario, 'mime' => $mime, 'ancho' => (int) $ancho, 'alto' => (int) $alto];
    }
}
