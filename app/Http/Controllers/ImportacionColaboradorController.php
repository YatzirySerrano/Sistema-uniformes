<?php

namespace App\Http\Controllers;

use App\Exports\ErroresImportacionColaboradoresExport;
use App\Exports\PlantillaColaboradoresExport;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Requests\Colaboradores\AnalizarImportacionColaboradoresRequest;
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
 * formulario (`empresa_id`) y se valida el acceso del usuario. El número de
 * empleado NUNCA viene del archivo (ver `ServicioImportacionColaboradores`).
 */
class ImportacionColaboradorController extends Controller
{
    use ConEmpresa;

    /**
     * Columnas de la plantilla, compartidas con el frontend para el texto de
     * ayuda. `numero_empleado` NO forma parte del archivo: es autogenerado.
     *
     * @var list<string>
     */
    private const COLUMNAS = ['nombre_completo', 'curp', 'puesto', 'area', 'correo', 'sucursal_codigo'];

    public function __construct(private readonly ServicioImportacionColaboradores $servicio) {}

    public function create(Request $request): Response
    {
        $this->authorize('importar', Colaborador::class);

        return Inertia::render('Colaboradores/Importar', [
            'columnas' => self::COLUMNAS,
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

    public function analizar(AnalizarImportacionColaboradoresRequest $request): Response
    {
        $empresa = $this->resolverEmpresa($request);
        $analisis = $this->servicio->analizar($request->file('archivo'), $empresa);

        return Inertia::render('Colaboradores/Importar', [
            'columnas' => self::COLUMNAS,
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
            'message' => "Importación completada: {$importados} colaboradores creados. Los números de empleado se generaron automáticamente.",
        ]);
    }

    /**
     * Descarga los errores/duplicados del último análisis como Excel.
     * Recibe la lista tal cual la tiene el frontend en los props tras
     * `analizar()`: evita reprocesar el archivo o mantener un caché
     * servidor efímero adicional sólo para esta descarga (mismo patrón que
     * `ImportacionMaestraController::descargarErrores`).
     */
    public function descargarErrores(Request $request): BinaryFileResponse
    {
        $this->authorize('importar', Colaborador::class);

        $datos = $request->validate([
            'errores' => ['required', 'array', 'min:1'],
            'errores.*.fila' => ['required', 'integer'],
            'errores.*.campo' => ['nullable', 'string'],
            'errores.*.valor' => ['nullable'],
            'errores.*.error' => ['required', 'string'],
        ]);

        return Excel::download(new ErroresImportacionColaboradoresExport($datos['errores']), 'errores-importacion-colaboradores.xlsx');
    }
}
