<?php

namespace App\Http\Controllers;

use App\Exports\ErroresImportacionColaboradoresExport;
use App\Exports\PlantillaColaboradoresExport;
use App\Http\Controllers\Concerns\ConEmpresa;
use App\Http\Requests\Colaboradores\AnalizarImportacionColaboradoresRequest;
use App\Models\Area;
use App\Models\Colaborador;
use App\Models\Servicio;
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
     * Columnas de la plantilla (fuente única: el servicio que las valida),
     * compartidas con el frontend para el texto de ayuda. `numero_empleado`
     * NO forma parte del archivo: es autogenerado.
     */
    private const COLUMNAS = ServicioImportacionColaboradores::COLUMNAS;

    /** Clave flash del último análisis (ver `analizar()`). */
    private const SESION_ANALISIS = 'importacion_colaboradores_analisis';

    public function __construct(private readonly ServicioImportacionColaboradores $servicio) {}

    /**
     * Muestra la pantalla de importación y, justo después de `analizar()`,
     * el resultado del análisis que éste dejó en la sesión (flash).
     */
    public function create(Request $request): Response
    {
        $this->authorize('importar', Colaborador::class);

        /** @var array{empresa_id: int, analisis: array<string, mixed>}|null $ultimoAnalisis */
        $ultimoAnalisis = $request->session()->get(self::SESION_ANALISIS);

        return Inertia::render('Colaboradores/Importar', [
            'columnas' => self::COLUMNAS,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            ...($ultimoAnalisis === null ? [] : [
                'empresaSeleccionadaId' => $ultimoAnalisis['empresa_id'],
                'analisis' => $ultimoAnalisis['analisis'],
            ]),
        ]);
    }

    public function plantilla(Request $request): BinaryFileResponse
    {
        $this->authorize('importar', Colaborador::class);

        $empresa = $this->resolverEmpresa($request);

        // Ejemplos con códigos reales y activos de la empresa elegida (el
        // usuario ya tiene acceso a ella); si no hay, el export usa códigos
        // ficticios con el formato del sistema.
        return Excel::download(new PlantillaColaboradoresExport(
            codigoArea: Area::query()->where('empresa_id', $empresa->getKey())->where('activa', true)->orderBy('codigo')->value('codigo'),
            codigoSucursal: $empresa->sucursales()->where('activa', true)->orderBy('codigo')->value('codigo'),
            codigoServicio: Servicio::query()->activos()
                ->whereHas('contrato', fn ($q) => $q->where('empresa_id', $empresa->getKey())->where('activo', true))
                ->orderBy('codigo')->value('codigo'),
        ), 'plantilla-colaboradores.xlsx');
    }

    /**
     * Post/Redirect/Get: nunca responde la página directamente al POST. Si lo
     * hiciera, Inertia guardaría `/colaboradores/importar/analizar` como URL
     * de la página y cualquier recarga (F5, atrás/adelante) haría GET a una
     * ruta sólo-POST → 405. El análisis viaja en la sesión (flash) a
     * `create()`; la confirmación sigue reprocesando el archivo por token.
     */
    public function analizar(AnalizarImportacionColaboradoresRequest $request): RedirectResponse
    {
        $empresa = $this->resolverEmpresa($request);
        $analisis = $this->servicio->analizar($request->file('archivo'), $empresa);

        return to_route('colaboradores.importar')->with(self::SESION_ANALISIS, [
            'empresa_id' => $empresa->id,
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
