<?php

use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Soporte\ContextoEmpresa;

/*
|--------------------------------------------------------------------------
| Alcance global: Superadministrador y Administrador
|--------------------------------------------------------------------------
*/

it('un administrador tiene alcance global sobre todas las empresas', function () {
    sembrarRolesPermisos();

    Empresa::factory()->count(3)->create();

    // Administrador sin ninguna fila en empresa_usuario.
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/empresas')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('empresas.total', 3));
});

it('un administrador ve una empresa recién creada sin asignación manual', function () {
    sembrarRolesPermisos();

    Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    // Se registra una empresa nueva después de crear al administrador.
    $nueva = Empresa::factory()->create(['nombre_comercial' => 'Recién Creada']);

    $this->actingAs($admin)
        ->get('/empresas')
        ->assertInertia(fn ($page) => $page->where('empresas.total', 2));

    $this->actingAs($admin)
        ->get('/empresas?buscar=Reci%C3%A9n+Creada')
        ->assertInertia(fn ($page) => $page
            ->where('empresas.total', 1)
            ->where('empresas.data.0.id', $nueva->id)
        );

    // Y puede activarla aunque no exista fila en empresa_usuario.
    $this->actingAs($admin)
        ->post('/empresa-activa', ['empresa_id' => $nueva->id])
        ->assertSessionHasNoErrors();

    expect(session(ContextoEmpresa::SESSION_KEY))->toBe($nueva->id);
});

/*
|--------------------------------------------------------------------------
| Alcance restringido: Supervisor / Encargado
|--------------------------------------------------------------------------
*/

it('un supervisor sólo accede a las empresas que tiene asignadas', function () {
    sembrarRolesPermisos();

    $a = Empresa::factory()->create();
    $b = Empresa::factory()->create();
    $c = Empresa::factory()->create();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$a, $b]);

    // Cambia entre sus dos empresas: permitido.
    $this->actingAs($supervisor)->post('/empresa-activa', ['empresa_id' => $a->id])->assertSessionHasNoErrors();
    expect(session(ContextoEmpresa::SESSION_KEY))->toBe($a->id);

    $this->actingAs($supervisor)->post('/empresa-activa', ['empresa_id' => $b->id])->assertSessionHasNoErrors();

    // Una empresa no asignada: rechazo controlado.
    $this->actingAs($supervisor)
        ->post('/empresa-activa', ['empresa_id' => $c->id])
        ->assertSessionHasErrors('empresa_id');
});

it('el listado de empresas de un supervisor sólo muestra las asignadas', function () {
    sembrarRolesPermisos();

    $a = Empresa::factory()->create();
    Empresa::factory()->create();
    Empresa::factory()->create();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$a]);

    $this->actingAs($supervisor)
        ->get('/empresas')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('empresas.total', 1)
            ->where('empresas.data.0.id', $a->id)
        );
});

it('impide ver un colaborador de otra empresa a un rol restringido (IDOR / cross-tenant)', function () {
    $datos = escenarioMultiempresa();

    $colaboradorB = Colaborador::factory()
        ->for($datos['empresaB'])
        ->for($datos['sucursalB'])
        ->create();

    // Supervisor (tiene colaboradores.editar) limitado a la empresa A.
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$datos['empresaA']]);

    $respuesta = $this->actingAs($supervisor)
        ->withSession([ContextoEmpresa::SESSION_KEY => $datos['empresaA']->id])
        ->get("/colaboradores/{$colaboradorB->id}/editar");

    expect($respuesta->status())->toBeIn([403, 404]);
});

it('el listado de colaboradores solo muestra los de la empresa activa', function () {
    $datos = escenarioMultiempresa();

    Colaborador::factory()->count(3)->for($datos['empresaA'])->for($datos['sucursalA'])->create();
    Colaborador::factory()->count(5)->for($datos['empresaB'])->for($datos['sucursalB'])->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

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

    // Supervisor con dos empresas => no se autoselecciona ninguna.
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$datos['empresaA'], $datos['empresaB']]);

    $this->actingAs($supervisor)
        ->get('/colaboradores')
        ->assertRedirect(); // ExcepcionDeNegocio -> back(); no es un 500
});
