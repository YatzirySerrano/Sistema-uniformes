<?php

namespace App\Http\Controllers;

use App\Exports\PlantillaColaboradoresExport;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Models\Colaborador;
use App\Servicios\ServicioImportacionColaboradores;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Importación de colaboradores por Excel. La empresa destino se elige en el
 * formulario (`empresa_id`) y se valida el acceso del usuario.
 */
class ImportacionColaboradorController extends Controller
{
    use ConEmpresa;

    public function __construct(private readonly ServicioImportacionColaboradores $servicio) {}

    public function create(Request $request): Response
    {
        $this->authorize('importar', Colaborador::class);

        return Inertia::render('Colaboradores/Importar', [
            'columnas' => ['numero_empleado', 'nombre_completo', 'puesto', 'area', 'correo', 'sucursal_codigo'],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
        ]);
    }

    public function plantilla(Request $request): BinaryFileResponse
    {
        $this->authorize('importar', Colaborador::class);

        $empresa = $this->resolverEmpresa($request);
        $codigos = $empresa->sucursales()->pluck('codigo')->all();

        return Excel::download(new PlantillaColaboradoresExport($codigos), 'plantilla-colaboradores.xlsx');
    }

    public function analizar(Request $request): Response
    {
        $this->authorize('importar', Colaborador::class);

        $request->validate([
            'empresa_id' => ['required', 'integer'],
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
        ], [], ['archivo' => 'archivo']);

        $empresa = $this->resolverEmpresa($request);
        $analisis = $this->servicio->analizar($request->file('archivo'), $empresa);

        return Inertia::render('Colaboradores/Importar', [
            'columnas' => ['numero_empleado', 'nombre_completo', 'puesto', 'area', 'correo', 'sucursal_codigo'],
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'empresaSeleccionadaId' => $empresa->id,
            'analisis' => $analisis,
        ]);
    }

    public function confirmar(Request $request): RedirectResponse
    {
        $this->authorize('importar', Colaborador::class);

        $datos = $request->validate([
            'empresa_id' => ['required', 'integer'],
            'token' => ['required', 'string'],
        ]);

        $empresa = $this->resolverEmpresa($request);
        $importados = $this->servicio->importar($datos['token'], $empresa, $request->user()->id);

        return to_route('colaboradores.index')->with('toast', [
            'type' => 'success',
            'message' => "Importación completada: {$importados} colaboradores creados.",
        ]);
    }
}
