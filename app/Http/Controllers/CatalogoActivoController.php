<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Models\CategoriaActivo;
use App\Models\Empresa;
use App\Models\TipoActivo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración de los catálogos COMPARTIDOS de Activos: tipos y categorías.
 * Los catálogos son de plataforma; cada fila indica para qué empresas está
 * habilitada. El selector de empresa fija el contexto de la columna "Habilitada"
 * y del toggle rápido por empresa.
 */
class CatalogoActivoController extends Controller
{
    use ConEmpresa;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', TipoActivo::class);

        $empresa = $this->empresaDelFiltro($request) ?? $this->empresasAutorizadas($request)->first();
        abort_if($empresa === null, 403, 'No tienes ninguna empresa asignada.');

        $tipos = TipoActivo::query()
            ->with('empresas:id,nombre_comercial')
            ->withCount('activos')
            ->orderBy('nombre')
            ->get()
            ->map(fn (TipoActivo $t): array => [
                'id' => $t->id,
                'nombre' => $t->nombre,
                'codigo' => $t->codigo,
                'activo' => $t->activo,
                'activos_count' => (int) $t->activos_count,
                'habilitado' => $t->empresas->contains('id', $empresa->id),
                'empresas' => $t->empresas->map(fn (Empresa $e): array => ['id' => $e->id, 'nombre_comercial' => $e->nombre_comercial])->all(),
            ]);

        $categorias = CategoriaActivo::query()
            ->with(['empresas:id,nombre_comercial', 'tipoActivo:id,nombre'])
            ->withCount('activos')
            ->orderBy('nombre')
            ->get()
            ->map(fn (CategoriaActivo $c): array => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'tipo_activo_id' => $c->tipo_activo_id,
                'tipo' => $c->tipoActivo?->nombre,
                'activa' => $c->activa,
                'activos_count' => (int) $c->activos_count,
                'habilitada' => $c->empresas->contains('id', $empresa->id),
                'empresas' => $c->empresas->map(fn (Empresa $e): array => ['id' => $e->id, 'nombre_comercial' => $e->nombre_comercial])->all(),
            ]);

        return Inertia::render('Activos/Catalogos', [
            'tipos' => $tipos,
            'categorias' => $categorias,
            'tiposSelect' => $tipos->where('activo', true)->map(fn ($t): array => ['id' => $t['id'], 'nombre' => $t['nombre']])->values(),
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'empresaSeleccionadaId' => $empresa->id,
            'permisos' => [
                'administrar_tipos' => $request->user()->can('administrar', TipoActivo::class),
                'administrar_categorias' => $request->user()->can('administrar', CategoriaActivo::class),
            ],
        ]);
    }
}
