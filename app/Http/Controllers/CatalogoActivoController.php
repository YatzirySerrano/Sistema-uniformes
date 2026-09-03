<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Models\CategoriaActivo;
use App\Models\TipoActivo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla de administración de los catálogos de Activos: tipos y categorías.
 * Integrada en el área de Activos (no ocupa una entrada propia del menú).
 */
class CatalogoActivoController extends Controller
{
    use ConEmpresaActiva;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', TipoActivo::class);
        $empresaId = $this->empresaActiva()->id;

        $tipos = TipoActivo::query()
            ->where('empresa_id', $empresaId)
            ->withCount('activos')
            ->orderBy('nombre')
            ->get()
            ->map(fn (TipoActivo $t): array => [
                'id' => $t->id,
                'nombre' => $t->nombre,
                'codigo' => $t->codigo,
                'activo' => $t->activo,
                'activos_count' => (int) $t->activos_count,
            ]);

        $categorias = CategoriaActivo::query()
            ->where('empresa_id', $empresaId)
            ->withCount('activos')
            ->with('tipoActivo:id,nombre')
            ->orderBy('nombre')
            ->get()
            ->map(fn (CategoriaActivo $c): array => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'tipo_activo_id' => $c->tipo_activo_id,
                'tipo' => $c->tipoActivo?->nombre,
                'activa' => $c->activa,
                'activos_count' => (int) $c->activos_count,
            ]);

        return Inertia::render('Activos/Catalogos', [
            'tipos' => $tipos,
            'categorias' => $categorias,
            'tiposSelect' => $tipos->where('activo', true)->map(fn ($t): array => ['id' => $t['id'], 'nombre' => $t['nombre']])->values(),
            'permisos' => [
                'administrar_tipos' => $request->user()->can('administrar', TipoActivo::class),
                'administrar_categorias' => $request->user()->can('administrar', CategoriaActivo::class),
            ],
        ]);
    }
}
