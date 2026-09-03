<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Servicios\ServicioAuditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Personalización (branding y datos) de una empresa concreta, identificada por
 * la ruta. Sin "empresa activa": se accede desde el módulo Empresas.
 */
class PersonalizacionEmpresaController extends Controller
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function edit(Empresa $empresa): Response
    {
        $this->authorize('personalizar', $empresa);

        return Inertia::render('Empresas/Personalizacion', [
            'empresa' => [
                ...$empresa->only(['id', 'nombre_comercial', 'razon_social', 'telefono', 'correo', 'direccion', 'color_principal', 'color_secundario', 'color_acento']),
                'logo_url' => $empresa->logo_ruta ? Storage::disk('public')->url($empresa->logo_ruta) : null,
            ],
        ]);
    }

    public function update(Request $request, Empresa $empresa): RedirectResponse
    {
        $this->authorize('personalizar', $empresa);

        $datos = $request->validate([
            'nombre_comercial' => ['required', 'string', 'max:255'],
            'razon_social' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'correo' => ['nullable', 'email', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'color_principal' => ['required', 'string', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'color_secundario' => ['required', 'string', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'color_acento' => ['required', 'string', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg', 'max:2048'],
        ], [
            'color_principal.regex' => 'El color principal debe ser un valor hexadecimal (#RRGGBB).',
            'color_secundario.regex' => 'El color secundario debe ser un valor hexadecimal (#RRGGBB).',
            'color_acento.regex' => 'El color de acento debe ser un valor hexadecimal (#RRGGBB).',
        ]);

        if ($request->hasFile('logo')) {
            if ($empresa->logo_ruta) {
                Storage::disk('public')->delete($empresa->logo_ruta);
            }
            $datos['logo_ruta'] = $request->file('logo')->store("empresas/{$empresa->id}", 'public');
        }
        unset($datos['logo']);

        $empresa->update($datos);

        $this->auditoria->registrar('empresas', 'branding', [
            'empresa_id' => $empresa->id,
            'tipo_entidad' => Empresa::class, 'entidad_id' => $empresa->id,
            'descripcion' => 'Actualización de personalización de '.$empresa->nombre_comercial,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Personalización guardada.']);
    }
}
