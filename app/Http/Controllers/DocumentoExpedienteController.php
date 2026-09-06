<?php

namespace App\Http\Controllers;

use App\Acciones\SubirDocumentoExpediente;
use App\Acciones\SubirVersionDocumentoExpediente;
use App\Enums\CategoriaDocumentoExpediente;
use App\Http\Requests\Colaboradores\ActualizarDocumentoExpedienteRequest;
use App\Http\Requests\Colaboradores\GuardarDocumentoExpedienteRequest;
use App\Http\Requests\Colaboradores\SubirVersionDocumentoRequest;
use App\Models\Colaborador;
use App\Models\DocumentoExpediente;
use App\Models\VersionDocumentoExpediente;
use App\Servicios\ServicioAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expediente digital de un colaborador: documentos agrupados por categoría,
 * cada uno con un historial de versiones append-only. Todas las acciones se
 * autorizan contra el `Colaborador` padre (`ColaboradorPolicy`), nunca contra
 * el documento suelto.
 */
class DocumentoExpedienteController extends Controller
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Colaborador $colaborador, Request $request): Response
    {
        $this->authorize('verExpediente', $colaborador);

        $documentos = $colaborador->documentosExpediente()
            ->with(['versionActual', 'creadoPor:id,name'])
            ->withCount('versiones')
            ->orderBy('nombre')
            ->get();

        return Inertia::render('Colaboradores/Expediente', [
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre_completo' => $colaborador->nombre_completo,
                'numero_empleado' => $colaborador->numero_empleado,
                'foto_url' => $colaborador->foto_ruta !== null ? route('colaboradores.foto', $colaborador) : null,
            ],
            'categorias' => collect(CategoriaDocumentoExpediente::cases())
                ->map(fn (CategoriaDocumentoExpediente $c): array => ['valor' => $c->value, 'etiqueta' => $c->etiqueta()])
                ->all(),
            'documentos' => $documentos->map(fn (DocumentoExpediente $d): array => $this->documentoPayload($d))->all(),
            'puedeAdministrar' => $request->user()->can('administrarExpediente', $colaborador),
            'puedeDescargar' => $request->user()->can('descargarExpediente', $colaborador),
        ]);
    }

    public function store(GuardarDocumentoExpedienteRequest $request, Colaborador $colaborador, SubirDocumentoExpediente $accion): RedirectResponse
    {
        $accion->ejecutar(
            $colaborador,
            CategoriaDocumentoExpediente::from($request->validated('categoria')),
            $request->validated('nombre'),
            $request->validated('descripcion'),
            $request->file('archivo'),
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento subido al expediente.']);
    }

    public function nuevaVersion(SubirVersionDocumentoRequest $request, Colaborador $colaborador, DocumentoExpediente $documento, SubirVersionDocumentoExpediente $accion): RedirectResponse
    {
        $this->verificarPertenece($colaborador, $documento);

        $accion->ejecutar($documento, $request->file('archivo'), $request->validated('comentario'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Nueva versión guardada.']);
    }

    public function update(ActualizarDocumentoExpedienteRequest $request, Colaborador $colaborador, DocumentoExpediente $documento): RedirectResponse
    {
        $this->verificarPertenece($colaborador, $documento);

        $anteriores = ['nombre' => $documento->nombre, 'descripcion' => $documento->descripcion, 'categoria' => $documento->categoria->value];

        $documento->update([
            'nombre' => $request->validated('nombre'),
            'descripcion' => $request->validated('descripcion'),
            'categoria' => CategoriaDocumentoExpediente::from($request->validated('categoria')),
        ]);

        $this->auditoria->registrar('colaboradores', 'expediente-editar', [
            'tipo_entidad' => DocumentoExpediente::class,
            'entidad_id' => $documento->id,
            'empresa_id' => $colaborador->empresa_id,
            'descripcion' => 'Edición de metadatos del documento "'.$anteriores['nombre'].'" en el expediente de '.$colaborador->nombre_completo,
            'valores_anteriores' => $anteriores,
            'valores_nuevos' => ['nombre' => $documento->nombre, 'descripcion' => $documento->descripcion, 'categoria' => $documento->categoria->value],
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento actualizado.']);
    }

    public function toggle(Request $request, Colaborador $colaborador, DocumentoExpediente $documento): RedirectResponse
    {
        $this->authorize('administrarExpediente', $colaborador);
        $this->verificarPertenece($colaborador, $documento);

        $documento->update(['activo' => ! $documento->activo]);

        $this->auditoria->registrar('colaboradores', $documento->activo ? 'expediente-restaurar' : 'expediente-eliminar', [
            'tipo_entidad' => DocumentoExpediente::class,
            'entidad_id' => $documento->id,
            'empresa_id' => $colaborador->empresa_id,
            'descripcion' => ($documento->activo ? 'Restauración' : 'Eliminación').' del documento "'.$documento->nombre.'" en el expediente de '.$colaborador->nombre_completo,
            'valores_anteriores' => ['activo' => ! $documento->activo],
            'valores_nuevos' => ['activo' => $documento->activo],
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => $documento->activo ? 'Documento restaurado.' : 'Documento eliminado.']);
    }

    public function descargar(Colaborador $colaborador, DocumentoExpediente $documento): StreamedResponse
    {
        $this->authorize('descargarExpediente', $colaborador);
        $this->verificarPertenece($colaborador, $documento);

        $version = $documento->versionActual;
        abort_if($version === null, 404);
        abort_unless(Storage::disk('local')->exists($version->ruta), 404);

        return Storage::disk('local')->download($version->ruta, $version->nombre_archivo_original);
    }

    public function ver(Colaborador $colaborador, DocumentoExpediente $documento): StreamedResponse
    {
        $this->authorize('descargarExpediente', $colaborador);
        $this->verificarPertenece($colaborador, $documento);

        $version = $documento->versionActual;
        abort_if($version === null, 404);
        abort_unless(in_array($version->mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true), 415);
        abort_unless(Storage::disk('local')->exists($version->ruta), 404);

        return Storage::disk('local')->response($version->ruta, $version->nombre_archivo_original, [
            'Content-Type' => $version->mime,
        ]);
    }

    public function versiones(Colaborador $colaborador, DocumentoExpediente $documento): JsonResponse
    {
        $this->authorize('verExpediente', $colaborador);
        $this->verificarPertenece($colaborador, $documento);

        $versiones = $documento->versiones()
            ->with('subidoPor:id,name')
            ->orderByDesc('version')
            ->get()
            ->map(fn (VersionDocumentoExpediente $v): array => [
                'version' => $v->version,
                'nombre_archivo_original' => $v->nombre_archivo_original,
                'mime' => $v->mime,
                'peso_bytes' => $v->peso_bytes,
                'hash_sha256' => $v->hash_sha256,
                'comentario' => $v->comentario,
                'subido_por' => $v->subidoPor?->name,
                'subido_en' => $v->created_at?->toIso8601String(),
            ]);

        return response()->json(['versiones' => $versiones]);
    }

    public function descargarVersion(Colaborador $colaborador, DocumentoExpediente $documento, VersionDocumentoExpediente $version): StreamedResponse
    {
        $this->authorize('descargarExpediente', $colaborador);
        $this->verificarPertenece($colaborador, $documento);
        abort_unless($version->documento_expediente_id === $documento->id, 404);
        abort_unless(Storage::disk('local')->exists($version->ruta), 404);

        return Storage::disk('local')->download($version->ruta, 'v'.$version->version.'-'.$version->nombre_archivo_original);
    }

    /**
     * @return array<string, mixed>
     */
    private function documentoPayload(DocumentoExpediente $documento): array
    {
        $actual = $documento->versionActual;

        return [
            'id' => $documento->id,
            'categoria' => $documento->categoria->value,
            'nombre' => $documento->nombre,
            'descripcion' => $documento->descripcion,
            'activo' => $documento->activo,
            'creado_por' => $documento->creadoPor?->name,
            'total_versiones' => $documento->versiones_count,
            'version_actual' => $actual === null ? null : [
                'version' => $actual->version,
                'nombre_archivo_original' => $actual->nombre_archivo_original,
                'mime' => $actual->mime,
                'extension' => $actual->extension,
                'peso_bytes' => $actual->peso_bytes,
                'subido_en' => $actual->created_at?->toIso8601String(),
                'puede_previsualizar' => str_starts_with($actual->mime, 'image/') || $actual->mime === 'application/pdf',
            ],
        ];
    }

    private function verificarPertenece(Colaborador $colaborador, DocumentoExpediente $documento): void
    {
        abort_unless($documento->colaborador_id === $colaborador->id, 404);
    }
}
