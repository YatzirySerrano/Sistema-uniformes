<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Models\BitacoraAuditoria;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BitacoraController extends Controller
{
    use ConEmpresaActiva;

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('auditoria.ver'), 403);

        $filtros = $request->validate([
            'modulo' => ['nullable', 'string', 'max:60'],
            'accion' => ['nullable', 'string', 'max:60'],
            'buscar' => ['nullable', 'string', 'max:100'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $registros = BitacoraAuditoria::query()
            ->when(! $request->user()->esSuperadministrador(), fn ($q) => $q->where('empresa_id', $this->empresaActiva()->id))
            ->when($request->user()->esSuperadministrador() && $this->contexto()->tieneEmpresa(), fn ($q) => $q->where('empresa_id', $this->contexto()->id()))
            ->when($filtros['modulo'] ?? null, fn ($q, $v) => $q->where('modulo', $v))
            ->when($filtros['accion'] ?? null, fn ($q, $v) => $q->where('accion', $v))
            ->when($filtros['buscar'] ?? null, fn ($q, $v) => $q->where(fn ($s) => $s
                ->where('descripcion', 'like', "%{$v}%")
                ->orWhere('nombre_usuario_snapshot', 'like', "%{$v}%")))
            ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest()
            ->paginate($this->porPagina())
            ->withQueryString()
            ->through(fn (BitacoraAuditoria $b): array => [
                'id' => $b->id,
                'fecha' => $b->created_at?->toIso8601String(),
                'usuario' => $b->nombre_usuario_snapshot,
                'modulo' => $b->modulo,
                'accion' => $b->accion,
                'descripcion' => $b->descripcion,
                'entidad' => $b->tipo_entidad ? class_basename($b->tipo_entidad).' #'.$b->entidad_id : null,
                'motivo' => $b->motivo,
                'ip' => $b->ip,
                'valores_anteriores' => $b->valores_anteriores,
                'valores_nuevos' => $b->valores_nuevos,
            ]);

        return Inertia::render('Auditoria/Index', [
            'registros' => $registros,
            'filtros' => $filtros,
            'modulos' => BitacoraAuditoria::query()->distinct()->orderBy('modulo')->pluck('modulo'),
        ]);
    }
}
