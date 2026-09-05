<?php

namespace App\Http\Controllers;

use App\Http\Requests\Configuracion\GuardarConfiguracionRequest;
use App\Models\ConfiguracionSistema;
use App\Servicios\ServicioAuditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Personalización visual GLOBAL de la instancia (una sola configuración,
 * nunca por empresa ni por usuario). Reemplaza la antigua "Personalización
 * de empresa" — ver `.ai/rules` y CLAUDE.md, Fase 10.
 */
class ConfiguracionController extends Controller
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function edit(Request $request): Response
    {
        $this->authorize('ver', ConfiguracionSistema::class);

        $configuracion = ConfiguracionSistema::actual();

        return Inertia::render('Configuracion/Index', [
            'configuracion' => $configuracion->only([
                'color_principal', 'color_hover_principal', 'color_texto_boton_principal',
                'fondo_general', 'fondo_tarjetas', 'fondo_sidebar', 'color_secundario',
            ]),
            'valoresPorDefecto' => ConfiguracionSistema::valoresPorDefecto(),
            'puedeEditar' => $request->user()->can('actualizar', ConfiguracionSistema::class),
        ]);
    }

    public function update(GuardarConfiguracionRequest $request): RedirectResponse
    {
        $configuracion = ConfiguracionSistema::actual();
        $anteriores = $configuracion->only(array_keys($request->validated()));

        $configuracion->update($request->validated());

        $this->auditoria->registrar('configuracion', 'actualizar', [
            'tipo_entidad' => ConfiguracionSistema::class,
            'entidad_id' => $configuracion->id,
            'descripcion' => 'Actualización de la personalización visual global del sistema',
            'valores_anteriores' => $anteriores,
            'valores_nuevos' => $configuracion->only(array_keys($request->validated())),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Personalización guardada. Los cambios ya se aplican en toda la aplicación.']);
    }
}
