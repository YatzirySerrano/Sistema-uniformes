<?php

use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sucursal;

beforeEach(function () {
    sembrarRolesPermisos();
});

/*
|--------------------------------------------------------------------------
| Filtro por sucursal en el listado
|--------------------------------------------------------------------------
*/

it('sin filtro de estado muestra activos e inactivos (regla: sin filtros = todos)', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    Colaborador::factory()->for($empresa)->for($sucursal)->create();
    Colaborador::factory()->inactivo()->for($empresa)->for($sucursal)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/colaboradores')
        ->assertInertia(fn ($page) => $page->where('colaboradores.total', 2));
});

it('un rol sin permiso de eliminar colaboradores nunca ve los eliminados, ni con estado=todos ni forzando la URL', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    Colaborador::factory()->for($empresa)->for($sucursal)->create(['activo' => true]);
    Colaborador::factory()->inactivo()->for($empresa)->for($sucursal)->create();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);

    $this->actingAs($supervisor)
        ->get('/colaboradores')
        ->assertInertia(fn ($page) => $page
            ->where('colaboradores.total', 1)
            ->where('puedeVerEliminados', false),
        );

    $this->actingAs($supervisor)
        ->get('/colaboradores?estado=todos')
        ->assertInertia(fn ($page) => $page->where('colaboradores.total', 1));

    // El backend IGNORA el filtro (no lo rechaza con error): sigue mostrando
    // sólo lo que el supervisor puede ver normalmente, nunca el eliminado.
    $this->actingAs($supervisor)
        ->get('/colaboradores?estado=inactivos')
        ->assertInertia(fn ($page) => $page->where('colaboradores.total', 1));
});

it('un administrador (con permiso de eliminar) sí ve la opción de eliminados', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    Colaborador::factory()->inactivo()->for($empresa)->for($sucursal)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/colaboradores')
        ->assertInertia(fn ($page) => $page->where('puedeVerEliminados', true));
});

it('filtra el listado de colaboradores por sucursal_id', function () {
    $empresa = Empresa::factory()->create();
    $sucursalA = Sucursal::factory()->for($empresa)->create();
    $sucursalB = Sucursal::factory()->for($empresa)->create();
    Colaborador::factory()->for($empresa)->for($sucursalA)->create();
    Colaborador::factory()->for($empresa)->for($sucursalB)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
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

    $this->actingAs($admin)
        ->get("/colaboradores/crear?sucursal_id={$sucursal->id}")
        ->assertInertia(fn ($page) => $page
            ->component('Colaboradores/Formulario')
            ->where('sucursalPreseleccionada.id', $sucursal->id)
        );
});

it('no preselecciona ninguna sucursal si no llega sucursal_id', function () {
    $empresa = Empresa::factory()->create();
    Sucursal::factory()->for($empresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/colaboradores/crear')
        ->assertInertia(fn ($page) => $page->where('sucursalPreseleccionada', null));
});

it('ignora un sucursal_id de una empresa fuera del alcance del usuario', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $sucursalAjena = Sucursal::factory()->for($ajena)->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);

    $this->actingAs($supervisor)
        ->get("/colaboradores/crear?sucursal_id={$sucursalAjena->id}")
        ->assertInertia(fn ($page) => $page->where('sucursalPreseleccionada', null));
});

it('un supervisor no puede preseleccionar una sucursal fuera de su alcance, pero sí la suya', function () {
    $empresa = Empresa::factory()->create();
    $suSucursal = Sucursal::factory()->for($empresa)->create();
    $otraSucursal = Sucursal::factory()->for($empresa)->create();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);
    $supervisor->sucursales()->sync([$suSucursal->id]);

    $this->actingAs($supervisor)
        ->get("/colaboradores/crear?sucursal_id={$otraSucursal->id}")
        ->assertInertia(fn ($page) => $page->where('sucursalPreseleccionada', null));

    $this->actingAs($supervisor)
        ->get("/colaboradores/crear?sucursal_id={$suSucursal->id}")
        ->assertInertia(fn ($page) => $page->where('sucursalPreseleccionada.id', $suSucursal->id));
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

    $this->actingAs($admin)
        ->post('/colaboradores', [
            'empresa_id' => $empresaA->id,
            'numero_empleado' => 'EMP-100',
            'nombre_completo' => 'Colaborador de prueba',
            'sucursal_id' => $sucursalB->id,
        ])
        ->assertSessionHasErrors('sucursal_id');

    expect(Colaborador::query()->where('numero_empleado', 'EMP-100')->exists())->toBeFalse();
});
