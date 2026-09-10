<?php

namespace App\Servicios;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\DetalleDevolucion;
use App\Models\DetalleEntrega;
use App\Models\Evidencia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Guarda y adjunta evidencia fotográfica a un renglón de entrega o devolución.
 *
 * Sigue el mismo patrón que `ConfirmarAcuseRecepcion` con la firma: el archivo
 * se escribe en disco privado ANTES de abrir la transacción de negocio; la
 * fila `evidencias` se crea DENTRO de la transacción; si algo falla, el
 * llamador borra el archivo huérfano con `descartar()`. Nunca `public/`.
 */
class ServicioEvidencias
{
    /**
     * @var list<string>
     */
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Valida y guarda el archivo en disco privado. NO toca la BD.
     *
     * @return array{ruta: string, nombre_original: string, mime: string, extension: string, peso_bytes: int, hash_sha256: string, origen: string}
     */
    public function guardarPendiente(UploadedFile $archivo, string $prefijoRuta, string $origen): array
    {
        $mime = $archivo->getMimeType() ?? $archivo->getClientMimeType();
        $ruta = $archivo->getRealPath();

        if (! in_array($mime, self::MIMES, true) || $ruta === false || @getimagesize($ruta) === false) {
            throw new ExcepcionDeNegocioSimple('La evidencia debe ser una imagen JPG, PNG o WebP válida.');
        }

        $extension = strtolower((string) ($archivo->getClientOriginalExtension() ?: match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        }));

        $hash = hash_file('sha256', $ruta);
        if ($hash === false) {
            throw new RuntimeException('No fue posible calcular el hash de la evidencia.');
        }

        $destino = trim($prefijoRuta, '/').'/'.Str::uuid().'.'.$extension;
        $guardada = $archivo->storeAs(dirname($destino), basename($destino), 'local');

        if ($guardada === false) {
            throw new RuntimeException('No fue posible guardar la evidencia.');
        }

        return [
            'ruta' => $guardada,
            'nombre_original' => $archivo->getClientOriginalName(),
            'mime' => $mime,
            'extension' => $extension,
            'peso_bytes' => $archivo->getSize() ?: 0,
            'hash_sha256' => $hash,
            'origen' => $origen === Evidencia::ORIGEN_CAMARA ? Evidencia::ORIGEN_CAMARA : Evidencia::ORIGEN_ARCHIVO,
        ];
    }

    /**
     * Crea la fila `evidencias` ligada al renglón. Debe llamarse dentro de la
     * transacción de negocio.
     *
     * @param  array{ruta: string, nombre_original: string, mime: string, extension: string, peso_bytes: int, hash_sha256: string, origen: string}  $meta
     */
    public function adjuntar(DetalleEntrega|DetalleDevolucion $renglon, array $meta, ?int $usuarioId): Evidencia
    {
        return $renglon->evidencias()->create([
            'disco' => 'local',
            'ruta' => $meta['ruta'],
            'nombre_original' => $meta['nombre_original'],
            'mime' => $meta['mime'],
            'extension' => $meta['extension'],
            'peso_bytes' => $meta['peso_bytes'],
            'hash_sha256' => $meta['hash_sha256'],
            'origen' => $meta['origen'],
            'subido_por' => $usuarioId,
        ]);
    }

    /**
     * Borra los archivos huérfanos si la transacción falló.
     *
     * @param  array<int, array{ruta: string}>  $metas
     */
    public function descartar(array $metas): void
    {
        foreach ($metas as $meta) {
            Storage::disk('local')->delete($meta['ruta']);
        }
    }
}
