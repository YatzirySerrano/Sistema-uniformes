<?php

use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Soporte\ContextoEmpresa;

beforeEach(function () {
    sembrarRolesPermisos();
});

/*
|--------------------------------------------------------------------------
| Filtro por sucursal en el listado
|--------------------------------------------------------------------------
*/

it('filtra el listado de colaboradores por sucursal_id', function () {
    $empresa = Empresa::factory()->create();
    $sucursalA = Sucursal::factory()->for($empresa)->create();
    $sucursalB = Sucursal::factory()->for($empresa)->create();
    Colaborador::factory()->for($empresa)->for($sucursalA)->create();
    Colaborador::factory()->for($empresa)->for($sucursalB)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get("/colaboradores?sucursal_id={$sucursalA->id}")
        ->assertInertia(fn ($page) => $page->where('colaboradores.total', 1));
});

/*
|--------------------------------------------------------------------------
| Preselección de sucursal en "Nuevo colaborador"
|--------------------------------------------------------------------------
*/

it('preselecciona la sucursal en el formulario de alta cuando llega por query', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get("/colaboradores/crear?sucursal_id={$sucursal->id}")
        ->assertInertia(fn ($page) => $page
            ->component('Colaboradores/Formulario')
            ->where('sucursalPreseleccionadaId', $sucursal->id)
        );
});

it('no preselecciona ninguna sucursal si no llega sucursal_id', function () {
    $empresa = Empresa::factory()->create();
    Sucursal::factory()->for($empresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get('/colaboradores/crear')
        ->assertInertia(fn ($page) => $page->where('sucursalPreseleccionadaId', null));
});

it('ignora un sucursal_id que pertenece a otra empresa', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresaA->id])
        ->get("/colaboradores/crear?sucursal_id={$sucursalB->id}")
        ->assertInertia(fn ($page) => $page->where('sucursalPreseleccionadaId', null));
});

it('un supervisor no puede preseleccionar una sucursal fuera de su alcance, pero sí la suya', function () {
    $empresa = Empresa::factory()->create();
    $suSucursal = Sucursal::factory()->for($empresa)->create();
    $otraSucursal = Sucursal::factory()->for($empresa)->create();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);
    $supervisor->sucursales()->sync([$suSucursal->id]);

    $this->actingAs($supervisor)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get("/colaboradores/crear?sucursal_id={$otraSucursal->id}")
        ->assertInertia(fn ($page) => $page->where('sucursalPreseleccionadaId', null));

    $this->actingAs($supervisor)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get("/colaboradores/crear?sucursal_id={$suSucursal->id}")
        ->assertInertia(fn ($page) => $page->where('sucursalPreseleccionadaId', $suSucursal->id));
});

/*
|--------------------------------------------------------------------------
| El backend sigue validando empresa/sucursal al guardar
|--------------------------------------------------------------------------
*/

it('rechaza registrar un colaborador con una sucursal de otra empresa', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresaA->id])
        ->post('/colaboradores', [
            'numero_empleado' => 'EMP-100',
            'nombre_completo' => 'Colaborador de prueba',
            'sucursal_id' => $sucursalB->id,
        ])
        ->assertSessionHasErrors('sucursal_id');

    expect(Colaborador::query()->where('numero_empleado', 'EMP-100')->exists())->toBeFalse();
});
