<?php

use App\Enums\RolSistema;
use App\Http\Middleware\HandleInertiaRequests;

/**
 * Un acceso no autorizado sigue respondiendo 403 real; sólo cambia la
 * presentación: página propia en español al navegar, JSON manejable (para un
 * toast) en acciones y `fetch`, sin textos técnicos ni detalles internos.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->colaborador = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);
});

it('al navegar sin permiso responde 403 con la página amigable en español', function () {
    $respuesta = $this->actingAs($this->colaborador)->get('/empresas');

    $respuesta->assertForbidden()
        ->assertInertia(fn ($page) => $page
            ->component('Errores/SinPermiso')
            ->where('mensaje', 'No tienes permiso para realizar esta acción.'));

    expect($respuesta->getContent())
        ->not->toContain('This action is unauthorized')
        ->not->toContain('empresas.ver')
        ->not->toContain('EmpresaPolicy')
        ->not->toContain('Stack trace');
});

it('una navegación Inertia también recibe la página amigable con estado 403', function () {
    $this->actingAs($this->colaborador)
        ->get('/empresas', ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request())])
        ->assertForbidden()
        ->assertJsonPath('component', 'Errores/SinPermiso');
});

it('una acción o un fetch sin permiso recibe un 403 JSON en español para mostrarlo como aviso', function () {
    $this->actingAs($this->colaborador)
        ->postJson('/empresas', ['nombre_comercial' => 'X'])
        ->assertForbidden()
        ->assertExactJson(['message' => 'No tienes permiso para realizar esta acción.']);

    // Formulario Inertia (no JSON): tampoco navega a una página completa.
    $this->actingAs($this->colaborador)
        ->post('/empresas', ['nombre_comercial' => 'X'], ['X-Inertia' => 'true'])
        ->assertForbidden()
        ->assertExactJson(['message' => 'No tienes permiso para realizar esta acción.']);
});

it('conserva un mensaje propio en español escrito para el usuario', function () {
    $restringido = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);

    $this->actingAs($restringido)
        ->getJson("/entregas/documento-identidad/{$this->datos['colaboradorA']->id}")
        ->assertForbidden()
        ->assertExactJson(['message' => 'No tienes acceso a la empresa de ese colaborador.']);
});

it('un usuario autorizado sigue accediendo normalmente', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->get('/empresas')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Empresas/Index'));
});
