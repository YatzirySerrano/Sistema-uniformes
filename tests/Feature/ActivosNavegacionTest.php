<?php

use App\Enums\RolSistema;

/**
 * Reorganización de navegación Activos/Inventario (Sección P del QA):
 * "Inventario" deja de ser un módulo de primer nivel en el sidebar, pero su
 * lógica sigue existiendo — el encabezado de Activos ahora ofrece
 * "Existencias globales" (reutiliza /inventario) y "Registrar ingreso de
 * stock" (reutiliza /inventario/entrada), ambas gateadas por los MISMOS
 * permisos que ya exigían esas rutas. Ninguna ruta se elimina ni se rompe.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
});

it('el encabezado de Activos expone verExistenciasGlobales y registrarIngreso según los permisos reales del usuario', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $this->actingAs($admin)->get('/activos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Activos/Index')
            ->where('permisos.verExistenciasGlobales', true)
            ->where('permisos.registrarIngreso', true));
});

it('sin inventario.ver ni inventario.entrada, el encabezado de Activos NO ofrece esos botones', function () {
    // Colaborador no tiene ninguno de los dos permisos por defecto.
    $sinPermisos = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);
    $sinPermisos->givePermissionTo('activos.ver');

    $this->actingAs($sinPermisos)->get('/activos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permisos.verExistenciasGlobales', false)
            ->where('permisos.registrarIngreso', false));
});

it('/inventario (Existencias globales) sigue funcionando internamente sin ruta rota, con el mismo permiso inventario.ver', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);

    $this->actingAs($admin)->get('/inventario')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventario/Index'));

    $this->actingAs($sinPermiso)->get('/inventario')->assertForbidden();
});

it('/inventario/entrada (Registrar ingreso de stock) sigue funcionando internamente sin ruta rota, con el mismo permiso inventario.entrada', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);

    $this->actingAs($admin)->get('/inventario/entrada')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventario/Entrada'));

    $this->actingAs($sinPermiso)->get('/inventario/entrada')->assertForbidden();
});

it('las rutas de Variantes/tallas y Tipos/categorías siguen accesibles aunque ya no estén en el encabezado de Activos', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $this->actingAs($admin)->get('/tallas')->assertOk();
    $this->actingAs($admin)->get('/activos-catalogos')->assertOk();
});

it('Nuevo activo sigue disponible tal cual, gateado por el mismo permiso de creación', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $this->actingAs($admin)->get('/activos')
        ->assertInertia(fn ($page) => $page->where('permisos.crear', true));

    $this->actingAs($admin)->get('/activos/crear')->assertOk();
});
