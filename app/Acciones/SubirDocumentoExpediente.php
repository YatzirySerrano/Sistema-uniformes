<?php

namespace App\Acciones;

use App\Enums\CategoriaDocumentoExpediente;
use App\Models\Colaborador;
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
 * Crea un documento nuevo del expediente (primera versión). El archivo se
 * guarda en el disco privado antes de abrir la transacción, igual que
 * `ConfirmarAcuseRecepcion` con la firma manuscrita.
 */
class SubirDocumentoExpediente
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function ejecutar(
        Colaborador $colaborador,
        CategoriaDocumentoExpediente $categoria,
        string $nombre,
        ?string $descripcion,
        UploadedFile $archivo,
        User $usuario,
    ): DocumentoExpediente {
        $extension = strtolower((string) $archivo->getClientOriginalExtension());
        $ruta = $this->guardarArchivo($colaborador, $categoria, $archivo, $extension);
        $hash = hash_file('sha256', $archivo->getRealPath());

        if ($hash === false) {
            throw new RuntimeException('No fue posible calcular el hash del archivo.');
        }

        try {
            return DB::transaction(function () use ($colaborador, $categoria, $nombre, $descripcion, $archivo, $usuario, $ruta, $hash, $extension): DocumentoExpediente {
                $documento = DocumentoExpediente::query()->create([
                    'colaborador_id' => $colaborador->getKey(),
                    'categoria' => $categoria,
                    'nombre' => $nombre,
                    'descripcion' => $descripcion,
                    'creado_por' => $usuario->getKey(),
                ]);

                VersionDocumentoExpediente::query()->create([
                    'documento_expediente_id' => $documento->getKey(),
                    'version' => 1,
                    'ruta' => $ruta,
                    'nombre_archivo_original' => $archivo->getClientOriginalName(),
                    'mime' => $archivo->getMimeType() ?? $archivo->getClientMimeType(),
                    'extension' => $extension,
                    'peso_bytes' => $archivo->getSize() ?: 0,
                    'hash_sha256' => $hash,
                    'subido_por' => $usuario->getKey(),
                ]);

                $this->auditoria->registrar('colaboradores', 'expediente-subir', [
                    'tipo_entidad' => DocumentoExpediente::class,
                    'entidad_id' => $documento->getKey(),
                    'empresa_id' => $colaborador->empresa_id,
                    'descripcion' => 'Documento "'.$nombre.'" subido al expediente de '.$colaborador->nombre_completo.' ('.$categoria->etiqueta().')',
                ]);

                return $documento;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($ruta);

            throw $e;
        }
    }

    private function guardarArchivo(Colaborador $colaborador, CategoriaDocumentoExpediente $categoria, UploadedFile $archivo, string $extension): string
    {
        $nombreArchivo = Str::uuid().($extension !== '' ? '.'.$extension : '');

        $ruta = $archivo->storeAs(
            "expedientes/{$colaborador->getKey()}/{$categoria->value}",
            $nombreArchivo,
            'local',
        );

        if ($ruta === false) {
            throw new RuntimeException('No fue posible guardar el archivo del expediente.');
        }

        return $ruta;
    }
}
