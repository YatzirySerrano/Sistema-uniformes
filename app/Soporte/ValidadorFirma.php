<?php

namespace App\Soporte;

use App\Excepciones\ExcepcionDeNegocioSimple;
use Illuminate\Http\UploadedFile;

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

    /** Peso máximo de un archivo de firma subido (firma a distancia). */
    private const MAX_BYTES_ARCHIVO = 5 * 1024 * 1024;

    /** Lado máximo de la imagen normalizada que se incrusta en el acuse. */
    private const MAX_LADO_NORMALIZADO = 1600;

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

    /**
     * Firma SUBIDA como archivo (firma a distancia: la persona firma en
     * papel/otro dispositivo y se sube aquí). No confía en la extensión ni en
     * el MIME que declara el navegador: detecta el tipo por contenido.
     *
     * - Imagen (PNG / JPEG / WEBP): debe decodificarse; se NORMALIZA a PNG
     *   (re-codificada con GD: sin metadatos, tamaño acotado) para usarla
     *   como firma visual del acuse, igual que una firma dibujada.
     * - PDF: se valida su cabecera `%PDF-` y se conserva ÍNTEGRO como
     *   evidencia documental; no se rasteriza (el acuse indica "documento
     *   adjunto").
     *
     * @return array{original: string, mime: string, extension: string, nombre_original: string, peso_bytes: int, es_pdf: bool, binario_firma: string}
     */
    public function validarArchivo(UploadedFile $archivo): array
    {
        if (! $archivo->isValid()) {
            throw new ExcepcionDeNegocioSimple('El archivo de firma no se recibió correctamente. Vuelve a seleccionarlo.');
        }

        $original = (string) file_get_contents($archivo->getRealPath());
        $peso = strlen($original);

        if ($peso === 0) {
            throw new ExcepcionDeNegocioSimple('El archivo de firma está vacío.');
        }

        if ($peso > self::MAX_BYTES_ARCHIVO) {
            throw new ExcepcionDeNegocioSimple('El archivo de firma no puede pesar más de 5 MB.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($original) ?: '';
        $nombre = mb_substr($archivo->getClientOriginalName() !== '' ? $archivo->getClientOriginalName() : 'firma', 0, 255);

        if ($mime === 'application/pdf') {
            if (! str_starts_with($original, '%PDF-')) {
                throw new ExcepcionDeNegocioSimple('El archivo de firma no es un PDF válido.');
            }

            return [
                'original' => $original, 'mime' => $mime, 'extension' => 'pdf', 'nombre_original' => $nombre,
                'peso_bytes' => $peso, 'es_pdf' => true, 'binario_firma' => $original,
            ];
        }

        $extensiones = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

        if (! isset($extensiones[$mime])) {
            throw new ExcepcionDeNegocioSimple('El archivo de firma debe ser una imagen PNG, JPG o WEBP, o un PDF.');
        }

        $imagen = @imagecreatefromstring($original);

        if ($imagen === false) {
            throw new ExcepcionDeNegocioSimple('No fue posible leer la imagen de la firma. Sube otro archivo.');
        }

        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);

        if ($ancho < self::MIN_ANCHO || $alto < self::MIN_ALTO) {
            imagedestroy($imagen);

            throw new ExcepcionDeNegocioSimple('La imagen de la firma es demasiado pequeña (mínimo 200 × 60 px).');
        }

        $escala = min(1, self::MAX_LADO_NORMALIZADO / max($ancho, $alto));
        $nuevoAncho = max(1, (int) round($ancho * $escala));
        $nuevoAlto = max(1, (int) round($alto * $escala));

        $lienzo = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        imagealphablending($lienzo, false);
        imagesavealpha($lienzo, true);
        imagefill($lienzo, 0, 0, (int) imagecolorallocatealpha($lienzo, 255, 255, 255, 127));
        imagecopyresampled($lienzo, $imagen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

        ob_start();
        imagepng($lienzo);
        $png = (string) ob_get_clean();
        imagedestroy($imagen);
        imagedestroy($lienzo);

        return [
            'original' => $original, 'mime' => $mime, 'extension' => $extensiones[$mime], 'nombre_original' => $nombre,
            'peso_bytes' => $peso, 'es_pdf' => false, 'binario_firma' => $png,
        ];
    }
}
