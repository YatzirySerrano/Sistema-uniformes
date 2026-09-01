<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Http\Requests\Prendas\GuardarPrendaRequest;
use App\Models\Prenda;
use App\Models\SaldoInventario;
use App\Servicios\ServicioAuditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PrendaController extends Controller
{
    use ConEmpresaActiva;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Prenda::class);
        $empresa = $this->empresaActiva();

        $existencias = SaldoInventario::query()
            ->where('empresa_id', $empresa->id)
            ->selectRaw('prenda_id, SUM(cantidad) as total, SUM(CASE WHEN minimo > 0 AND cantidad <= minimo THEN 1 ELSE 0 END) as tallas_bajo_minimo')
            ->groupBy('prenda_id')
            ->get()
            ->keyBy('prenda_id');

        $prendas = Prenda::query()
            ->where('empresa_id', $empresa->id)
            ->with('tallas:id,valor')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Prenda $p): array => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'categoria' => $p->categoria,
                'codigo_interno' => $p->codigo_interno,
                'activa' => $p->activa,
                'imagen_url' => $p->imagen_ruta ? Storage::disk('public')->url($p->imagen_ruta) : null,
                'tallas' => $p->tallas->pluck('valor'),
                'existencias' => (int) ($existencias[$p->id]->total ?? 0),
                'tallas_bajo_minimo' => (int) ($existencias[$p->id]->tallas_bajo_minimo ?? 0),
            ]);

        return Inertia::render('Prendas/Index', [
            'prendas' => $prendas,
            'puedeCrear' => request()->user()->can('create', Prenda::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Prenda::class);

        return Inertia::render('Prendas/Formulario', [
            'prenda' => null,
            'tallas' => $this->tallasEmpresa(),
        ]);
    }

    public function store(GuardarPrendaRequest $request): RedirectResponse
    {
        $empresa = $this->empresaActiva();

        $prenda = Prenda::query()->create([
            'empresa_id' => $empresa->id,
            'nombre' => $request->string('nombre'),
            'descripcion' => $request->input('descripcion'),
            'categoria' => $request->input('categoria'),
            'codigo_interno' => $request->input('codigo_interno'),
            'activa' => $request->boolean('activa', true),
            'imagen_ruta' => $request->hasFile('imagen')
                ? ($request->file('imagen')->store("prendas/{$empresa->id}", 'public') ?: null)
                : null,
        ]);

        $prenda->tallas()->sync($request->input('tallas', []));

        $this->auditoria->registrar('prendas', 'crear', [
            'tipo_entidad' => Prenda::class,
            'entidad_id' => $prenda->id,
            'descripcion' => 'Alta de prenda '.$prenda->nombre,
        ]);

        return to_route('prendas.index')->with('toast', ['type' => 'success', 'message' => 'Prenda creada.']);
    }

    public function edit(Prenda $prenda): Response
    {
        $this->authorize('update', $prenda);
        $this->verificarEmpresa($prenda);

        return Inertia::render('Prendas/Formulario', [
            'prenda' => [
                ...$prenda->only(['id', 'nombre', 'descripcion', 'categoria', 'codigo_interno', 'activa']),
                'imagen_url' => $prenda->imagen_ruta ? Storage::disk('public')->url($prenda->imagen_ruta) : null,
                'tallas' => $prenda->tallas()->pluck('tallas.id'),
            ],
            'tallas' => $this->tallasEmpresa(),
        ]);
    }

    public function update(GuardarPrendaRequest $request, Prenda $prenda): RedirectResponse
    {
        $this->verificarEmpresa($prenda);
        $empresa = $this->empresaActiva();

        $prenda->fill([
            'nombre' => $request->string('nombre'),
            'descripcion' => $request->input('descripcion'),
            'categoria' => $request->input('categoria'),
            'codigo_interno' => $request->input('codigo_interno'),
            'activa' => $request->boolean('activa', $prenda->activa),
        ]);

        if ($request->hasFile('imagen')) {
            if ($prenda->imagen_ruta) {
                Storage::disk('public')->delete($prenda->imagen_ruta);
            }
            $prenda->imagen_ruta = $request->file('imagen')->store("prendas/{$empresa->id}", 'public') ?: null;
        }

        $prenda->save();
        $prenda->tallas()->sync($request->input('tallas', []));

        $this->auditoria->registrar('prendas', 'editar', [
            'tipo_entidad' => Prenda::class,
            'entidad_id' => $prenda->id,
            'descripcion' => 'Edición de prenda '.$prenda->nombre,
        ]);

        return to_route('prendas.index')->with('toast', ['type' => 'success', 'message' => 'Prenda actualizada.']);
    }

    public function show(Prenda $prenda): Response
    {
        $this->authorize('view', $prenda);
        $this->verificarEmpresa($prenda);

        $saldos = SaldoInventario::query()
            ->where('empresa_id', $prenda->empresa_id)
            ->where('prenda_id', $prenda->id)
            ->with(['sucursal:id,nombre', 'talla:id,valor'])
            ->get()
            ->map(fn (SaldoInventario $s): array => [
                'sucursal' => $s->sucursal?->nombre,
                'talla' => $s->talla?->valor,
                'cantidad' => $s->cantidad,
                'minimo' => $s->minimo,
                'bajo_minimo' => $s->estaBajoMinimo(),
            ]);

        return Inertia::render('Prendas/Detalle', [
            'prenda' => [
                ...$prenda->only(['id', 'nombre', 'descripcion', 'categoria', 'codigo_interno', 'activa']),
                'imagen_url' => $prenda->imagen_ruta ? Storage::disk('public')->url($prenda->imagen_ruta) : null,
                'tallas' => $prenda->tallas()->pluck('valor'),
            ],
            'saldos' => $saldos,
        ]);
    }

    /**
     * @return array<int, array{id: int, valor: string}>
     */
    private function tallasEmpresa(): array
    {
        return $this->empresaActiva()->tallas()->ordenadas()->get(['id', 'valor'])->toArray();
    }

    private function verificarEmpresa(Prenda $prenda): void
    {
        abort_unless($prenda->empresa_id === $this->empresaActiva()->id, 404);
    }
}
