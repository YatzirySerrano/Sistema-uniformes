<?php

namespace App\Http\Controllers;

use App\Exports\PlantillaColaboradoresExport;
use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Models\Colaborador;
use App\Servicios\ServicioImportacionColaboradores;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImportacionColaboradorController extends Controller
{
    use ConEmpresaActiva;

    public function __construct(private readonly ServicioImportacionColaboradores $servicio) {}

    public function create(): Response
    {
        $this->authorize('importar', Colaborador::class);

        return Inertia::render('Colaboradores/Importar', [
            'columnas' => ['numero_empleado', 'nombre_completo', 'puesto', 'area', 'correo', 'sucursal_codigo'],
        ]);
    }

    public function plantilla(): BinaryFileResponse
    {
        $this->authorize('importar', Colaborador::class);

        $codigos = $this->empresaActiva()->sucursales()->pluck('codigo')->all();

        return Excel::download(new PlantillaColaboradoresExport($codigos), 'plantilla-colaboradores.xlsx');
    }

    public function analizar(Request $request): Response
    {
        $this->authorize('importar', Colaborador::class);

        $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
        ], [], ['archivo' => 'archivo']);

        $analisis = $this->servicio->analizar($request->file('archivo'), $this->empresaActiva());

        return Inertia::render('Colaboradores/Importar', [
            'columnas' => ['numero_empleado', 'nombre_completo', 'puesto', 'area', 'correo', 'sucursal_codigo'],
            'analisis' => $analisis,
        ]);
    }

    public function confirmar(Request $request): RedirectResponse
    {
        $this->authorize('importar', Colaborador::class);

        $datos = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $importados = $this->servicio->importar($datos['token'], $this->empresaActiva(), $request->user()->id);

        return to_route('colaboradores.index')->with('toast', [
            'type' => 'success',
            'message' => "Importación completada: {$importados} colaboradores creados.",
        ]);
    }
}
