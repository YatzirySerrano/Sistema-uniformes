<?php

namespace App\Acciones;

use App\Models\UnidadActivo;
use App\Servicios\ServicioAuditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Quita la foto de una unidad (sin dejar rastro huérfano en disco). No hace
 * nada si la unidad no tenía foto. Nunca toca codigo/public_token/estado/
 * condicion.
 */
class QuitarImagenUnidadActivo
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function ejecutar(UnidadActivo $unidad, ?int $realizadoPor): void
    {
        $imagen = $unidad->imagen()->first();

        if ($imagen === null) {
            return;
        }

        DB::transaction(function () use ($unidad, $imagen): void {
            $imagen->delete();

            $this->auditoria->registrar('inventario', 'unidad_imagen_quitar', [
                'tipo_entidad' => UnidadActivo::class,
                'entidad_id' => $unidad->getKey(),
                'empresa_id' => $unidad->empresa_id,
                'descripcion' => 'Foto de la unidad '.$unidad->codigo.' eliminada.',
            ]);
        });

        Storage::disk($imagen->disco)->delete($imagen->ruta);
    }
}
