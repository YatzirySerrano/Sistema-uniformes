<?php

use App\Enums\RolSistema;
use App\Models\Area;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Soporte\ContextoEmpresa;

beforeEach(function () {
    sembrarRolesPermisos();
});

it('un administrador ve las áreas de la empresa activa y no las de otra empresa', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Area::factory()->count(3)->for($empresaA)->create();
    Area::factory()->count(2)->for($empresaB)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresaA->id])
        ->get('/areas')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Areas/Index')
            ->where('areas.total', 3)
            ->where('empresa.id', $empresaA->id)
        );
});

it('un administrador puede crear un área en la empresa activa con código autogenerado', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post('/areas', ['nombre' => 'Recursos Humanos'])
        ->assertRedirect()
        ->assertSessionHas('toast')
        ->assertSessionHasNoErrors();

    $area = Area::query()->where('nombre', 'Recursos Humanos')->first();
    expect($area)->not->toBeNull();
    expect($area->empresa_id)->toBe($empresa->id);
    expect($area->codigo)->toStartWith('ARE-');
    expect($area->activa)->toBeTrue();
});

it('el área se crea siempre en la empresa activa, ignorando el empresa_id del formulario', function () {
    $activa = Empresa::factory()->create();
    $otra = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $activa->id])
        ->post('/areas', ['nombre' => 'Sistemas', 'empresa_id' => $otra->id]);

    expect(Area::query()->where('nombre', 'Sistemas')->first()->empresa_id)->toBe($activa->id);
});

it('rechaza un nombre de área duplicado dentro de la misma empresa y lo permite en otra', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Area::factory()->for($empresaA)->create(['nombre' => 'Contabilidad']);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresaA->id])
        ->from('/areas')
        ->post('/areas', ['nombre' => 'Contabilidad'])
        ->assertSessionHasErrors('nombre');

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresaB->id])
        ->post('/areas', ['nombre' => 'Contabilidad'])
        ->assertSessionHasNoErrors();
});

it('un administrador puede editar y activar/desactivar un área sin perder colaboradores', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $area = Area::factory()->for($empresa)->create(['nombre' => 'Antes', 'activa' => true]);
    Colaborador::factory()->count(2)->for($empresa)->for($sucursal)->create(['area_id' => $area->id]);

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->put("/areas/{$area->id}", ['nombre' => 'Después'])
        ->assertSessionHasNoErrors();
    expect($area->fresh()->nombre)->toBe('Después');

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
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

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get('/areas')
        ->assertInertia(fn ($page) => $page
            ->where('areas.data.0.colaboradores_activos', 3)
            ->where('areas.data.0.colaboradores_total', 5)
        );
});

it('un supervisor sin permiso de edición no puede crear ni cambiar el estado de un área', function () {
    $empresa = Empresa::factory()->create();
    $area = Area::factory()->for($empresa)->create();
    $encargado = usuarioCon(RolSistema::Encargado->value, [$empresa]);

    $this->actingAs($encargado)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post('/areas', ['nombre' => 'Intento'])->assertForbidden();

    $this->actingAs($encargado)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post("/areas/{$area->id}/estado")->assertForbidden();
});

it('no permite ver un área que no pertenece a la empresa activa (IDOR)', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $areaB = Area::factory()->for($empresaB)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresaA->id])
        ->get("/areas/{$areaB->id}")
        ->assertNotFound();
});

it('valida sin generar un 500 cuando el nombre llega como arreglo', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->from('/areas')
        ->post('/areas', ['nombre' => ['no', 'soy', 'texto']])
        ->assertRedirect('/areas')
        ->assertSessionHasErrors('nombre');
});
