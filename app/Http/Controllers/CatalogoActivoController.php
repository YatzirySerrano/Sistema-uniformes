<?php

namespace App\Http\Controllers;

use App\Models\CategoriaActivo;
use App\Models\TipoActivo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración de los catálogos GLOBALES de Activos: tipos y categorías.
 * Son catálogos de plataforma, visibles para todas las empresas por igual;
 * no hay habilitación ni gestión por empresa.
 */
class CatalogoActivoController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', TipoActivo::class);

        $tipos = TipoActivo::query()
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
            ->with('tipoActivo:id,nombre')
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
