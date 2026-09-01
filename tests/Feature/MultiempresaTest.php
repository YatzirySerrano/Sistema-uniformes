<?php

use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Soporte\ContextoEmpresa;

it('un administrador de A y B accede a esas empresas pero no a C', function () {
    sembrarRolesPermisos();

    $a = Empresa::factory()->create();
    $b = Empresa::factory()->create();
    $c = Empresa::factory()->create();

    $admin = usuarioCon(RolSistema::Administrador->value, [$a, $b]);

    // Cambia a la empresa A: permitido.
    $this->actingAs($admin)
        ->post('/empresa-activa', ['empresa_id' => $a->id])
        ->assertSessionMissing('errors');

    expect(session(ContextoEmpresa::SESSION_KEY))->toBe($a->id);

    // Cambia a la empresa B: permitido.
    $this->actingAs($admin)
        ->post('/empresa-activa', ['empresa_id' => $b->id])
        ->assertSessionHasNoErrors();

    // Cambia a la empresa C: rechazado.
    $this->actingAs($admin)
        ->post('/empresa-activa', ['empresa_id' => $c->id])
        ->assertSessionHasErrors('empresa_id');
});

it('impide ver un colaborador de otra empresa (IDOR / cross-tenant)', function () {
    $datos = escenarioMultiempresa();

    $colaboradorB = Colaborador::factory()
        ->for($datos['empresaB'])
        ->for($datos['sucursalB'])
        ->create();

    $admin = usuarioCon(RolSistema::Administrador->value, [$datos['empresaA']]);

    $respuesta = $this->actingAs($admin)
        ->withSession([ContextoEmpresa::SESSION_KEY => $datos['empresaA']->id])
        ->get("/colaboradores/{$colaboradorB->id}/editar");

    // El acceso se niega (política de empresa) sin filtrar información: 403 o 404.
    expect($respuesta->status())->toBeIn([403, 404]);
});

it('el listado de colaboradores solo muestra los de la empresa activa', function () {
    $datos = escenarioMultiempresa();

    Colaborador::factory()->count(3)->for($datos['empresaA'])->for($datos['sucursalA'])->create();
    Colaborador::factory()->count(5)->for($datos['empresaB'])->for($datos['sucursalB'])->create();

    $admin = usuarioCon(RolSistema::Administrador->value, [$datos['empresaA'], $datos['empresaB']]);

    $respuesta = $this->actingAs($admin)
        ->withSession([ContextoEmpresa::SESSION_KEY => $datos['empresaA']->id])
        ->get('/colaboradores');

    $respuesta->assertOk();
    $respuesta->assertInertia(fn ($page) => $page
        ->component('Colaboradores/Index')
        ->where('colaboradores.total', 4) // 3 nuevos + 1 del escenario
    );
});

it('sin empresa activa las operaciones que la requieren responden de forma controlada', function () {
    $datos = escenarioMultiempresa();

    // Administrador con dos empresas => no se autoselecciona ninguna.
    $admin = usuarioCon(RolSistema::Administrador->value, [$datos['empresaA'], $datos['empresaB']]);

    $this->actingAs($admin)
        ->get('/colaboradores')
        ->assertRedirect(); // ExcepcionDeNegocio -> back(); no es un 500
});
