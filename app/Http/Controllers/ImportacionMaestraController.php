<?php

namespace App\Http\Controllers;

use App\Exports\ErroresImportacionExport;
use App\Http\Requests\Datos\ImportarBaseMaestraRequest;
use App\Servicios\ServicioImportacionMaestra;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Importación única de la base de datos maestra (carga inicial de
 * plataforma): dos fases, prevalidar → confirmar, restringidas al permiso
 * `datos.importar_maestro`. Ver `App\Servicios\ServicioImportacionMaestra`
 * para la resolución de relaciones y la lógica de atomicidad.
 */
class ImportacionMaestraController extends Controller
{
    public function __construct(private readonly ServicioImportacionMaestra $servicio) {}

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('datos.importar_maestro'), 403);

        return Inertia::render('Configuracion/Datos/ImportarBaseDatos', [
            'baseNoVacia' => $this->servicio->baseNoVacia(),
        ]);
    }

    public function prevalidar(ImportarBaseMaestraRequest $request): Response
    {
        $this->rechazarSiBaseNoVacia();

        $analisis = $this->servicio->prevalidar($request->file('archivo'));

        return Inertia::render('Configuracion/Datos/ImportarBaseDatos', [
            'baseNoVacia' => false,
            'analisis' => $analisis,
        ]);
    }

    public function confirmar(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('datos.importar_maestro'), 403);

        $this->rechazarSiBaseNoVacia();

        $datos = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $resultado = $this->servicio->confirmar($datos['token'], $request->user()->id);

        return to_route('datos.importar-maestro')->with('toast', [
            'type' => 'success',
            'message' => 'Importación maestra completada: '.array_sum($resultado['conteos']).' registro(s) creado(s) en '.$resultado['duracion_ms'].' ms.',
        ]);
    }

    /**
     * Descarga los errores de la última prevalidación como Excel. Recibe la
     * lista de errores del propio cliente (ya la tiene en los props de la
     * página tras `prevalidar()`): evita tener que reprocesar el archivo o
     * mantener un caché servidor efímero adicional sólo para esta descarga.
     */
    public function descargarErrores(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can('datos.importar_maestro'), 403);

        $datos = $request->validate([
            'errores' => ['required', 'array', 'min:1'],
            'errores.*.hoja' => ['required', 'string'],
            'errores.*.fila' => ['required', 'integer'],
            'errores.*.campo' => ['nullable', 'string'],
            'errores.*.valor' => ['nullable'],
            'errores.*.error' => ['required', 'string'],
        ]);

        return Excel::download(new ErroresImportacionExport($datos['errores']), 'errores-importacion-maestra.xlsx');
    }

    private function rechazarSiBaseNoVacia(): void
    {
        abort_if(
            $this->servicio->baseNoVacia(),
            422,
            'La base de datos ya contiene información (al menos una empresa registrada). Este importador es exclusivo para la carga inicial de una base vacía.',
        );
    }
}
