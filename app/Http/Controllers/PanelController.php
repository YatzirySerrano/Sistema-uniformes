<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConEmpresaActiva;
use App\Servicios\ServicioDashboard;
use Inertia\Inertia;
use Inertia\Response;

class PanelController extends Controller
{
    use ConEmpresaActiva;

    public function index(ServicioDashboard $dashboard): Response
    {
        $empresa = $this->contexto()->empresa();

        return Inertia::render('Panel', [
            'resumen' => $empresa === null ? null : $dashboard->resumen($empresa),
            'sinEmpresa' => $empresa === null,
        ]);
    }
}
