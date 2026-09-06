<?php

namespace App\Acciones;

use App\Models\DocumentoExpediente;
use App\Models\User;
use App\Models\VersionDocumentoExpediente;
use App\Servicios\ServicioAuditoria;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Sube una nueva versión de un documento existente. Nunca borra ni
 * sobrescribe versiones anteriores. `lockForUpdate()` sobre el documento y
 * sobre el cálculo de la siguiente versión evita que dos subidas
 * concurrentes al mismo documento obtengan el mismo número de versión.
 */
class SubirVersionDocumentoExpediente
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function ejecutar(
        DocumentoExpediente $documento,
        UploadedFile $archivo,
        ?string $comentario,
        User $usuario,
    ): VersionDocumentoExpediente {
        $documento->loadMissing('colaborador');
        $extension = strtolower((string) $archivo->getClientOriginalExtension());

        $nombreArchivo = Str::uuid().($extension !== '' ? '.'.$extension : '');
        $ruta = $archivo->storeAs(
            "expedientes/{$documento->colaborador_id}/{$documento->categoria->value}",
            $nombreArchivo,
            'local',
        );

        if ($ruta === false) {
            throw new RuntimeException('No fue posible guardar el archivo del expediente.');
        }

        $hash = hash_file('sha256', $archivo->getRealPath());

        if ($hash === false) {
            throw new RuntimeException('No fue posible calcular el hash del archivo.');
        }

        try {
            return DB::transaction(function () use ($documento, $archivo, $comentario, $usuario, $ruta, $hash, $extension): VersionDocumentoExpediente {
                $bloqueado = DocumentoExpediente::query()->whereKey($documento->getKey())->lockForUpdate()->firstOrFail();

                $siguienteVersion = (int) VersionDocumentoExpediente::query()
                    ->where('documento_expediente_id', $bloqueado->getKey())
                    ->lockForUpdate()
                    ->max('version') + 1;

                $version = VersionDocumentoExpediente::query()->create([
                    'documento_expediente_id' => $bloqueado->getKey(),
                    'version' => $siguienteVersion,
                    'ruta' => $ruta,
                    'nombre_archivo_original' => $archivo->getClientOriginalName(),
                    'mime' => $archivo->getMimeType() ?? $archivo->getClientMimeType(),
                    'extension' => $extension,
                    'peso_bytes' => $archivo->getSize() ?: 0,
                    'hash_sha256' => $hash,
                    'comentario' => $comentario,
                    'subido_por' => $usuario->getKey(),
                ]);

                $this->auditoria->registrar('colaboradores', 'expediente-nueva-version', [
                    'tipo_entidad' => DocumentoExpediente::class,
                    'entidad_id' => $bloqueado->getKey(),
                    'empresa_id' => $documento->colaborador->empresa_id,
                    'descripcion' => 'Nueva versión (v'.$siguienteVersion.') del documento "'.$bloqueado->nombre.'" en el expediente de '.$documento->colaborador->nombre_completo,
                ]);

                return $version;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($ruta);

            throw $e;
        }
    }
}
