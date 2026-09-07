<?php

namespace App\Servicios;

use App\Enums\CategoriaDocumentoExpediente;
use App\Models\Colaborador;
use App\Models\DocumentoExpediente;
use App\Models\User;

/**
 * Arma el payload del expediente digital de un colaborador (carpetas +
 * documentos + permisos del usuario actual). Única fuente de verdad: la usan
 * tanto la página dedicada del expediente (`DocumentoExpedienteController::index()`)
 * como el perfil del colaborador, que lo embebe como pestaña.
 */
class ServicioExpediente
{
    /**
     * Mimes que se pueden mostrar inline (`Content-Disposition: inline`) en
     * el navegador. Todo lo demás (Excel, Word…) sólo se ofrece para
     * descargar, nunca inline.
     *
     * @var list<string>
     */
    public const MIMES_PREVIEW_INLINE = [
        'image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'text/plain', 'text/csv',
    ];

    public static function esPrevisualizable(string $mime): bool
    {
        return in_array($mime, self::MIMES_PREVIEW_INLINE, true);
    }

    /**
     * Estados de filtro válidos para un usuario que SÍ puede ver eliminados.
     * Cualquier otro valor (incluyendo el de un usuario sin ese permiso) cae
     * en "activos" — los documentos eliminados son contenido archivado, nunca
     * el default.
     *
     * @var list<string>
     */
    private const FILTROS_CON_ELIMINADOS = ['eliminados', 'todos'];

    /**
     * @return array<string, mixed>
     */
    public function payload(Colaborador $colaborador, User $usuario, string $filtroEstado = 'activos'): array
    {
        $puedeVerEliminados = $usuario->can('administrarExpediente', $colaborador);
        $filtroAplicado = $puedeVerEliminados && in_array($filtroEstado, self::FILTROS_CON_ELIMINADOS, true)
            ? $filtroEstado
            : 'activos';

        $consulta = $colaborador->documentosExpediente()
            ->with(['versionActual.subidoPor:id,name', 'creadoPor:id,name'])
            ->withCount('versiones')
            ->orderBy('nombre');

        if ($filtroAplicado === 'activos') {
            $consulta->activos();
        } elseif ($filtroAplicado === 'eliminados') {
            $consulta->where('activo', false);
        }
        // 'todos': sin filtro adicional por estado.

        $documentos = $consulta->get();

        return [
            'categorias' => collect(CategoriaDocumentoExpediente::cases())
                ->map(fn (CategoriaDocumentoExpediente $c): array => ['valor' => $c->value, 'etiqueta' => $c->etiqueta()])
                ->all(),
            'documentos' => $documentos->map(fn (DocumentoExpediente $d): array => $this->documentoPayload($d))->all(),
            'puedeAdministrar' => $usuario->can('administrarExpediente', $colaborador),
            'puedeDescargar' => $usuario->can('descargarExpediente', $colaborador),
            'puedeVerEliminados' => $puedeVerEliminados,
            'filtroEstado' => $filtroAplicado,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function documentoPayload(DocumentoExpediente $documento): array
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
                'subido_por' => $actual->subidoPor?->name,
                'puede_previsualizar' => self::esPrevisualizable($actual->mime),
            ],
        ];
    }
}
