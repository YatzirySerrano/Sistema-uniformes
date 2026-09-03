<?php

use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\Empresa;

/*
|--------------------------------------------------------------------------
| Multiempresa SIN "empresa activa"
|--------------------------------------------------------------------------
| El contexto de empresa se determina por recurso / formulario / filtro.
| El backend siempre valida el acceso; nunca hay una empresa global en sesión.
*/

it('un administrador tiene alcance global sobre todas las empresas', function () {
    sembrarRolesPermisos();

    Empresa::factory()->count(3)->create();

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
});

it('un supervisor sólo ve las empresas que tiene asignadas', function () {
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

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$datos['empresaA']]);

    $respuesta = $this->actingAs($supervisor)
        ->get("/colaboradores/{$colaboradorB->id}/editar");

    expect($respuesta->status())->toBeIn([403, 404]);
});

it('el listado de colaboradores muestra todos los autorizados y se puede filtrar por empresa', function () {
    $datos = escenarioMultiempresa();

    Colaborador::factory()->count(3)->for($datos['empresaA'])->for($datos['sucursalA'])->create();
    Colaborador::factory()->count(5)->for($datos['empresaB'])->for($datos['sucursalB'])->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    // Sin filtro: ve los de todas las empresas (3 + 1 escenario + 5 = 9).
    $this->actingAs($admin)
        ->get('/colaboradores')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Colaboradores/Index')
            ->where('colaboradores.total', 9)
        );

    // Filtrando por la empresa A: 3 + 1.
    $this->actingAs($admin)
        ->get('/colaboradores?empresa_id='.$datos['empresaA']->id)
        ->assertInertia(fn ($page) => $page->where('colaboradores.total', 4));
});

it('un supervisor con dos empresas entra a los listados sin necesidad de elegir empresa', function () {
    $datos = escenarioMultiempresa();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$datos['empresaA'], $datos['empresaB']]);

    $this->actingAs($supervisor)
        ->get('/colaboradores')
        ->assertOk();

    $this->actingAs($supervisor)
        ->get('/inventario')
        ->assertOk();
});
