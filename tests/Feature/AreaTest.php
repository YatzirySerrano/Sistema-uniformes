<?php

use App\Enums\RolSistema;
use App\Models\Area;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sucursal;

beforeEach(function () {
    sembrarRolesPermisos();
});

it('un administrador ve todas las áreas y puede filtrarlas por empresa', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Area::factory()->count(3)->for($empresaA)->create();
    Area::factory()->count(2)->for($empresaB)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/areas')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Areas/Index')
            ->where('areas.total', 5)
            ->has('empresasAutorizadas')
        );

    $this->actingAs($admin)
        ->get('/areas?empresa_id='.$empresaA->id)
        ->assertInertia(fn ($page) => $page->where('areas.total', 3));
});

it('un administrador crea un área en la empresa indicada con código autogenerado', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/areas', ['nombre' => 'Recursos Humanos', 'empresa_id' => $empresa->id])
        ->assertRedirect()
        ->assertSessionHas('toast')
        ->assertSessionHasNoErrors();

    $area = Area::query()->where('nombre', 'Recursos Humanos')->first();
    expect($area)->not->toBeNull();
    expect($area->empresa_id)->toBe($empresa->id);
    expect($area->codigo)->toStartWith('ARE-');
    expect($area->activa)->toBeTrue();
});

it('rechaza crear un área en una empresa fuera del alcance del usuario', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);

    $this->actingAs($supervisor)
        ->from('/areas')
        ->post('/areas', ['nombre' => 'Sistemas', 'empresa_id' => $ajena->id])
        ->assertSessionHasErrors('empresa_id');

    expect(Area::query()->where('nombre', 'Sistemas')->exists())->toBeFalse();
});

it('rechaza un nombre de área duplicado dentro de la misma empresa y lo permite en otra', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Area::factory()->for($empresaA)->create(['nombre' => 'Contabilidad']);

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->from('/areas')
        ->post('/areas', ['nombre' => 'Contabilidad', 'empresa_id' => $empresaA->id])
        ->assertSessionHasErrors('nombre');

    $this->actingAs($admin)
        ->post('/areas', ['nombre' => 'Contabilidad', 'empresa_id' => $empresaB->id])
        ->assertSessionHasNoErrors();
});

it('un administrador puede editar y activar/desactivar un área sin perder colaboradores', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $area = Area::factory()->for($empresa)->create(['nombre' => 'Antes', 'activa' => true]);
    Colaborador::factory()->count(2)->for($empresa)->for($sucursal)->create(['area_id' => $area->id]);

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->put("/areas/{$area->id}", ['nombre' => 'Después'])
        ->assertSessionHasNoErrors();
    expect($area->fresh()->nombre)->toBe('Después');

    $this->actingAs($admin)
        ->post("/areas/{$area->id}/estado")->assertSessionHas('toast');
    expect($area->fresh()->activa)->toBeFalse();
    expect(Colaborador::query()->where('area_id', $area->id)->count())->toBe(2);
});

it('el contador de colaboradores del área excluye a los inactivos', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $area = Area::factory()->for($empresa)->create();
    Colaborador::factory()->count(3)->for($empresa)->for($sucursal)->create(['area_id' => $area->id]);
    Colaborador::factory()->count(2)->for($empresa)->for($sucursal)->inactivo()->create(['area_id' => $area->id]);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->get('/areas?empresa_id='.$empresa->id)
        ->assertInertia(fn ($page) => $page
            ->where('areas.data.0.colaboradores_activos', 3)
            ->where('areas.data.0.colaboradores_total', 5)
        );
});

it('un encargado sin permiso de edición no puede crear ni cambiar el estado de un área', function () {
    $empresa = Empresa::factory()->create();
    $area = Area::factory()->for($empresa)->create();
    $encargado = usuarioCon(RolSistema::Encargado->value, [$empresa]);

    $this->actingAs($encargado)
        ->post('/areas', ['nombre' => 'Intento', 'empresa_id' => $empresa->id])->assertForbidden();

    $this->actingAs($encargado)
        ->post("/areas/{$area->id}/estado")->assertForbidden();
});

it('un rol restringido no puede ver un área de una empresa fuera de su alcance', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $areaAjena = Area::factory()->for($ajena)->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);

    $respuesta = $this->actingAs($supervisor)->get("/areas/{$areaAjena->id}");
    expect($respuesta->status())->toBeIn([403, 404]);
});

it('valida sin generar un 500 cuando el nombre llega como arreglo', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/areas')
        ->post('/areas', ['nombre' => ['no', 'soy', 'texto'], 'empresa_id' => $empresa->id])
        ->assertRedirect('/areas')
        ->assertSessionHasErrors('nombre');
});
