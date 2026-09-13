<?php

namespace App\Acciones;

use App\Models\ImagenUnidadActivo;
use App\Models\UnidadActivo;
use App\Servicios\ServicioAuditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Sube o reemplaza la foto 1:1 de una unidad. El archivo NUEVO ya debe estar
 * escrito en disco antes de llamar aquí (`ServicioEvidencias::guardarPendiente()`,
 * reutilizado tal cual — misma validación MIME/hash que evidencias de
 * entrega/devolución). El archivo ANTERIOR sólo se borra después de que la
 * fila queda confirmada en BD (nunca antes): si algo falla, la foto vigente
 * no se pierde. Nunca toca codigo/public_token/estado/condicion de la unidad.
 */
class GuardarImagenUnidadActivo
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    /**
     * @param  array{ruta: string, nombre_original: string, mime: string, extension: string, peso_bytes: int, hash_sha256: string}  $meta
     */
    public function ejecutar(UnidadActivo $unidad, array $meta, ?int $subidoPor): ImagenUnidadActivo
    {
        $anterior = $unidad->imagen()->first();

        $imagen = DB::transaction(function () use ($unidad, $meta, $subidoPor): ImagenUnidadActivo {
            $imagen = ImagenUnidadActivo::query()->updateOrCreate(
                ['unidad_activo_id' => $unidad->getKey()],
                [
                    'disco' => 'local',
                    'ruta' => $meta['ruta'],
                    'nombre_original' => $meta['nombre_original'],
                    'mime' => $meta['mime'],
                    'extension' => $meta['extension'],
                    'peso_bytes' => $meta['peso_bytes'],
                    'hash_sha256' => $meta['hash_sha256'],
                    'subido_por' => $subidoPor,
                ],
            );

            $this->auditoria->registrar('inventario', 'unidad_imagen_guardar', [
                'tipo_entidad' => UnidadActivo::class,
                'entidad_id' => $unidad->getKey(),
                'empresa_id' => $unidad->empresa_id,
                'descripcion' => 'Foto de la unidad '.$unidad->codigo.' actualizada.',
            ]);

            return $imagen;
        });

        if ($anterior !== null && $anterior->ruta !== $imagen->ruta) {
            Storage::disk($anterior->disco)->delete($anterior->ruta);
        }

        return $imagen;
    }
}
