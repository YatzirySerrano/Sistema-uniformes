<?php

namespace App\Servicios;

use App\Acciones\SubirDocumentoExpediente;
use App\Enums\CategoriaDocumentoExpediente;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Colaborador;
use App\Models\DocumentoExpediente;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consulta y captura del documento de identidad (INE / identificación
 * oficial) del expediente de un colaborador, para la verificación VISUAL de
 * mínimo privilegio que se hace durante Entregas y Devoluciones. Única fuente
 * de esta lógica: la usan `EntregaController` y `DevolucionController` /
 * `AcuseDevolucionController` — cada uno resuelve el `Colaborador` y aplica su
 * propia autorización de mínimo privilegio antes de llamar aquí.
 *
 * El documento de identidad = el `DocumentoExpediente` ACTIVO más reciente en
 * `CategoriaDocumentoExpediente::Identificacion` (nada hardcodeado por id); se
 * sirve siempre su `versionActual` (versión de número más alto). Sólo
 * verificación visual humana: nada de OCR, biometría ni comparación
 * automática, y el sistema nunca afirma "identidad verificada".
 */
class ServicioIdentidadColaborador
{
    public function __construct(private readonly SubirDocumentoExpediente $subirDocumento) {}

    /**
     * El documento de identidad vigente del colaborador, o `null` si no
     * tiene ninguno activo.
     */
    public function documentoActivo(Colaborador $colaborador): ?DocumentoExpediente
    {
        return $colaborador->documentosExpediente()
            ->where('categoria', CategoriaDocumentoExpediente::Identificacion)
            ->where('activo', true)
            ->with('versionActual')
            ->latest('id')
            ->first();
    }

    /**
     * Metadata pública (sin ruta de disco) del documento de identidad
     * vigente del colaborador, lista para responder como JSON.
     *
     * @return array<string, mixed>
     */
    public function metadata(Colaborador $colaborador): array
    {
        return $this->payload($this->documentoActivo($colaborador));
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(?DocumentoExpediente $documento): array
    {
        $version = $documento?->versionActual;

        if ($documento === null || $version === null) {
            return ['disponible' => false];
        }

        return [
            'disponible' => true,
            'nombre' => $documento->nombre,
            'mime' => $version->mime,
            'previsualizable' => ServicioExpediente::esPrevisualizable($version->mime),
            'actualizado_en' => $version->created_at?->toIso8601String(),
        ];
    }

    /**
     * Sirve, en streaming, la ÚLTIMA versión del documento de identidad del
     * colaborador. Nunca expone la ruta en disco ni genera una URL
     * pública/predecible.
     */
    public function streamDocumento(Colaborador $colaborador): StreamedResponse
    {
        $documento = $this->documentoActivo($colaborador);
        abort_if($documento === null, 404, 'No hay un documento de identidad en el expediente de este colaborador.');

        $version = $documento->versionActual;
        abort_if($version === null, 404, 'No hay un documento de identidad en el expediente de este colaborador.');
        abort_unless(ServicioExpediente::esPrevisualizable($version->mime), 415, 'El documento de identidad no puede previsualizarse; consúltalo desde el expediente del colaborador.');
        abort_unless(Storage::disk('local')->exists($version->ruta), 404);

        return Storage::disk('local')->response($version->ruta, 'identificacion.'.$version->extension, [
            'Content-Type' => $version->mime,
            'Content-Disposition' => 'inline; filename="identificacion.'.$version->extension.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Sube una identificación FALTANTE al expediente del colaborador. No
     * permite reemplazar una ya existente (eso vive en el módulo de
     * expediente). Verifica la persistencia real (documento + versión +
     * archivo en disco) antes de devolver éxito: un fallo parcial nunca se
     * reporta como guardado.
     *
     * @param  string  $contexto  Para el mensaje de auditoría/log, p. ej. "una entrega" o "una devolución".
     */
    public function guardarFaltante(Colaborador $colaborador, UploadedFile $archivo, User $usuario, string $contexto): DocumentoExpediente
    {
        if ($this->documentoActivo($colaborador) !== null) {
            throw new ExcepcionDeNegocioSimple('Este colaborador ya tiene una identificación en su expediente. El reemplazo se hace desde su expediente.');
        }

        $this->subirDocumento->ejecutar(
            $colaborador,
            CategoriaDocumentoExpediente::Identificacion,
            'Identificación oficial',
            'Capturada durante '.$contexto,
            $archivo,
            $usuario,
        );

        $documento = $this->documentoActivo($colaborador->fresh() ?? $colaborador);
        $version = $documento?->versionActual;

        if ($documento === null || $version === null || ! Storage::disk('local')->exists($version->ruta)) {
            Log::error('Identificación durante '.$contexto.': el documento no persistió correctamente', [
                'colaborador_id' => $colaborador->id,
                'documento_id' => $documento?->id,
                'tiene_version' => $version !== null,
            ]);

            throw new ExcepcionDeNegocioSimple('No se pudo guardar la identificación. Inténtalo de nuevo.');
        }

        return $documento;
    }
}
