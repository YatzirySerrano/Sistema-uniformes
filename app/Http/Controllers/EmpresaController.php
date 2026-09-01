<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Models\Empresa;
use App\Servicios\ServicioAuditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmpresaController extends Controller
{
    use ConEmpresaActiva;

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Empresa::class);

        $empresas = ($request->user()->esSuperadministrador()
            ? Empresa::query()
            : $request->user()->empresas()->getQuery())
            ->withCount(['sucursales', 'colaboradores'])
            ->orderBy('nombre_comercial')
            ->paginate($this->porPagina())
            ->through(fn (Empresa $e): array => [
                'id' => $e->id,
                'codigo' => $e->codigo,
                'nombre_comercial' => $e->nombre_comercial,
                'razon_social' => $e->razon_social,
                'activa' => $e->activa,
                'sucursales' => $e->sucursales_count,
                'colaboradores' => $e->colaboradores_count,
                'color_principal' => $e->color_principal,
            ]);

        return Inertia::render('Empresas/Index', [
            'empresas' => $empresas,
            'puedeCrear' => $request->user()->can('create', Empresa::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Empresa::class);

        return Inertia::render('Empresas/Formulario', ['empresa' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Empresa::class);

        $datos = $this->validar($request, null);
        $datos['codigo'] = $datos['codigo'] ?: $this->generarCodigo($datos['nombre_comercial']);

        $empresa = Empresa::query()->create($datos);

        $this->auditoria->registrar('empresas', 'crear', [
            'empresa_id' => $empresa->id,
            'tipo_entidad' => Empresa::class, 'entidad_id' => $empresa->id,
            'descripcion' => 'Alta de empresa '.$empresa->nombre_comercial,
        ]);

        return to_route('empresas.index')->with('toast', ['type' => 'success', 'message' => 'Empresa creada.']);
    }

    public function edit(Empresa $empresa): Response
    {
        $this->authorize('update', $empresa);

        return Inertia::render('Empresas/Formulario', [
            'empresa' => $empresa->only(['id', 'codigo', 'nombre_comercial', 'razon_social', 'rfc', 'telefono', 'correo', 'direccion', 'activa']),
        ]);
    }

    public function update(Request $request, Empresa $empresa): RedirectResponse
    {
        $this->authorize('update', $empresa);

        $anteriores = $empresa->toArray();
        $empresa->update($this->validar($request, $empresa));

        $this->auditoria->registrar('empresas', 'editar', [
            'empresa_id' => $empresa->id,
            'tipo_entidad' => Empresa::class, 'entidad_id' => $empresa->id,
            'descripcion' => 'Edición de empresa '.$empresa->nombre_comercial,
            'valores_anteriores' => $anteriores, 'valores_nuevos' => $empresa->toArray(),
        ]);

        return to_route('empresas.index')->with('toast', ['type' => 'success', 'message' => 'Empresa actualizada.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?Empresa $empresa): array
    {
        return $request->validate([
            'nombre_comercial' => ['required', 'string', 'max:255'],
            'razon_social' => ['nullable', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:20'],
            'codigo' => ['nullable', 'string', 'max:20', 'alpha_dash', Rule::unique('empresas', 'codigo')->ignore($empresa?->id)],
            'telefono' => ['nullable', 'string', 'max:40'],
            'correo' => ['nullable', 'email', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'activa' => ['boolean'],
        ]);
    }

    private function generarCodigo(string $nombre): string
    {
        $base = Str::upper(Str::slug(Str::substr($nombre, 0, 6), ''));
        $base = $base !== '' ? $base : 'EMP';
        $n = 1;
        do {
            $codigo = $base.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $n++;
        } while (Empresa::query()->where('codigo', $codigo)->exists());

        return $codigo;
    }
}
