<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Controllers\Concerns\ExportaListado;
use App\Models\BitacoraAuditoria;
use App\Soporte\DescripcionAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class BitacoraController extends Controller
{
    use ConEmpresa;
    use ExportaListado;

    public function __construct(private readonly DescripcionAuditoria $descripcionAuditoria) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('auditoria.ver'), 403);

        $filtros = $this->filtrosListado($request);
        $empresaFiltro = $this->empresaDelFiltro($request);

        $registros = $this->consultaBitacora($request, $filtros)
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
                'empresa' => $b->empresa?->nombre_comercial,
                'sucursal' => $b->sucursal?->nombre,
                'motivo' => $b->motivo,
                'ip' => $b->ip,
                'cambios' => $this->descripcionAuditoria->cambios($b->tipo_entidad, $b->valores_anteriores, $b->valores_nuevos),
                'valores_anteriores' => $b->valores_anteriores,
                'valores_nuevos' => $b->valores_nuevos,
            ]);

        return Inertia::render('Auditoria/Index', [
            'registros' => $registros,
            'filtros' => [...$filtros, 'empresa_id' => $empresaFiltro?->id],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'modulos' => BitacoraAuditoria::query()->distinct()->orderBy('modulo')->pluck('modulo'),
        ]);
    }

    /**
     * Excel/PDF del listado, respetando los mismos filtros que `index()`.
     */
    public function exportar(Request $request): BinaryFileResponse|HttpResponse
    {
        abort_unless($request->user()->can('auditoria.ver'), 403);

        $filtros = $this->filtrosListado($request);
        $registros = $this->consultaBitacora($request, $filtros)->get();

        $filas = $registros->map(fn (BitacoraAuditoria $b): array => [
            $b->created_at?->format('d/m/Y H:i'),
            $b->nombre_usuario_snapshot,
            $b->modulo,
            $b->accion,
            $b->descripcion,
            $b->tipo_entidad ? class_basename($b->tipo_entidad).' #'.$b->entidad_id : null,
            $b->motivo,
            $b->ip,
        ])->all();

        return $this->respuestaExportacion($request->input('formato', 'xlsx'), $filas, [
            'Fecha', 'Usuario', 'Módulo', 'Acción', 'Descripción', 'Entidad', 'Motivo', 'IP',
        ], 'Auditoría');
    }

    /**
     * @return array<string, mixed>
     */
    private function filtrosListado(Request $request): array
    {
        return $request->validate([
            'modulo' => ['nullable', 'string', 'max:60'],
            'accion' => ['nullable', 'string', 'max:60'],
            'buscar' => ['nullable', 'string', 'max:100'],
            'empresa_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<BitacoraAuditoria>
     */
    private function consultaBitacora(Request $request, array $filtros): Builder
    {
        $empresaFiltro = $this->empresaDelFiltro($request);
        $idsAutorizadas = $this->idsEmpresasAutorizadas($request);
        $superadmin = $request->user()->esSuperadministrador();

        return BitacoraAuditoria::query()
            ->with(['empresa:id,nombre_comercial', 'sucursal:id,nombre'])
            ->when(! $superadmin, fn (Builder $q) => $q->where(fn (Builder $s) => $s->whereIn('empresa_id', $idsAutorizadas)->orWhereNull('empresa_id')))
            ->when($empresaFiltro !== null, fn (Builder $q) => $q->where('empresa_id', $empresaFiltro->id))
            ->when($filtros['modulo'] ?? null, fn (Builder $q, $v) => $q->where('modulo', $v))
            ->when($filtros['accion'] ?? null, fn (Builder $q, $v) => $q->where('accion', $v))
            ->when($filtros['buscar'] ?? null, fn (Builder $q, $v) => $q->where(fn (Builder $s) => $s
                ->where('descripcion', 'like', "%{$v}%")
                ->orWhere('nombre_usuario_snapshot', 'like', "%{$v}%")))
            ->when($filtros['desde'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filtros['hasta'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest();
    }
}
