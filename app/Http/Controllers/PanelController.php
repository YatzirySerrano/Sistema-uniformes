<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresa;
use App\Servicios\ServicioDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PanelController extends Controller
{
    use ConEmpresa;

    public function index(Request $request, ServicioDashboard $dashboard): Response
    {
        // Sin "empresa activa": el panel muestra una empresa a la vez, elegida
        // con el selector (por defecto, la primera empresa autorizada).
        $empresa = $this->empresaDelFiltro($request) ?? $this->empresasAutorizadas($request)->first();

        return Inertia::render('Panel', [
            'resumen' => $empresa === null ? null : $dashboard->resumen($empresa),
            'empresaSeleccionadaId' => $empresa?->id,
            'empresasAutorizadas' => $this->opcionesEmpresas($request),
            'sinEmpresa' => $empresa === null,
        ]);
    }
}
