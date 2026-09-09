<?php

use App\Enums\RolSistema;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\Servicio;
use App\Models\Sucursal;

beforeEach(function () {
    sembrarRolesPermisos();
});

it('un administrador ve todos los contratos y puede filtrarlos por empresa', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Contrato::factory()->count(3)->for($empresaA)->create();
    Contrato::factory()->count(2)->for($empresaB)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/contratos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Contratos/Index')
            ->where('contratos.total', 5)
            ->has('empresasAutorizadas')
        );

    $this->actingAs($admin)
        ->get('/contratos?empresa_id='.$empresaA->id)
        ->assertInertia(fn ($page) => $page->where('contratos.total', 3));
});

it('sólo quien puede administrar contratos ve los eliminados, ni forzando el filtro por URL', function () {
    $empresa = Empresa::factory()->create();
    Contrato::factory()->for($empresa)->create(['nombre' => 'Vivo', 'activo' => true]);
    Contrato::factory()->for($empresa)->create(['nombre' => 'Apagado', 'activo' => false]);

    $admin = usuarioCon(RolSistema::Administrador->value);
    $encargado = usuarioCon(RolSistema::Encargado->value, [$empresa]);

    $this->actingAs($admin)
        ->get('/contratos?estado=inactivos')
        ->assertInertia(fn ($page) => $page
            ->where('contratos.total', 1)
            ->where('contratos.data.0.nombre', 'Apagado')
            ->where('permisos.verEliminados', true),
        );

    $this->actingAs($encargado)
        ->get('/contratos')
        ->assertInertia(fn ($page) => $page
            ->where('contratos.total', 1)
            ->where('contratos.data.0.nombre', 'Vivo')
            ->where('permisos.verEliminados', false),
        );
});

it('un administrador crea un contrato en la empresa indicada con código autogenerado', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/contratos', ['nombre' => 'Laboratorios Clínicos Polab', 'empresa_id' => $empresa->id])
        ->assertRedirect()
        ->assertSessionHas('toast')
        ->assertSessionHasNoErrors();

    $contrato = Contrato::query()->where('nombre', 'Laboratorios Clínicos Polab')->first();
    expect($contrato)->not->toBeNull();
    expect($contrato->empresa_id)->toBe($empresa->id);
    expect($contrato->codigo)->toStartWith('CON-');
    expect($contrato->activo)->toBeTrue();
});

it('el código de contrato es race-safe: dos altas seguidas nunca repiten consecutivo', function () {
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->post('/contratos', ['nombre' => 'Contrato Uno', 'empresa_id' => $empresa->id]);
    $this->actingAs($admin)->post('/contratos', ['nombre' => 'Contrato Dos', 'empresa_id' => $empresa->id]);

    $codigos = Contrato::query()->where('empresa_id', $empresa->id)->orderBy('id')->pluck('codigo')->all();

    expect($codigos)->toHaveCount(2);
    expect($codigos[0])->not->toBe($codigos[1]);
});

it('rechaza crear un contrato en una empresa fuera del alcance del usuario', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);

    $this->actingAs($supervisor)
        ->from('/contratos')
        ->post('/contratos', ['nombre' => 'Contrato Ajeno', 'empresa_id' => $ajena->id])
        ->assertSessionHasErrors('empresa_id');

    expect(Contrato::query()->where('nombre', 'Contrato Ajeno')->exists())->toBeFalse();
});

it('rechaza un nombre de contrato duplicado dentro de la misma empresa y lo permite en otra', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Contrato::factory()->for($empresaA)->create(['nombre' => 'Plaza Averanda']);

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->from('/contratos')
        ->post('/contratos', ['nombre' => 'Plaza Averanda', 'empresa_id' => $empresaA->id])
        ->assertSessionHasErrors('nombre');

    $this->actingAs($admin)
        ->post('/contratos', ['nombre' => 'Plaza Averanda', 'empresa_id' => $empresaB->id])
        ->assertSessionHasNoErrors();
});

it('un administrador puede editar y activar/desactivar un contrato sin perder sus servicios', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $contrato = Contrato::factory()->for($empresa)->create(['nombre' => 'Antes', 'activo' => true]);
    Servicio::factory()->for($contrato)->for($sucursal)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->put("/contratos/{$contrato->id}", ['nombre' => 'Después'])
        ->assertSessionHasNoErrors();
    expect($contrato->fresh()->nombre)->toBe('Después');

    $this->actingAs($admin)
        ->post("/contratos/{$contrato->id}/estado")->assertSessionHas('toast');
    expect($contrato->fresh()->activo)->toBeFalse();
    expect(Servicio::query()->where('contrato_id', $contrato->id)->count())->toBe(1);
});

it('rechaza fecha de fin anterior a la fecha de inicio', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/contratos')
        ->post('/contratos', [
            'nombre' => 'Contrato con fechas',
            'empresa_id' => $empresa->id,
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-01-01',
        ])
        ->assertSessionHasErrors('fecha_fin');
});

it('un encargado sin permiso de edición no puede crear ni cambiar el estado de un contrato', function () {
    $empresa = Empresa::factory()->create();
    $contrato = Contrato::factory()->for($empresa)->create();
    $encargado = usuarioCon(RolSistema::Encargado->value, [$empresa]);

    $this->actingAs($encargado)
        ->post('/contratos', ['nombre' => 'Intento', 'empresa_id' => $empresa->id])->assertForbidden();

    $this->actingAs($encargado)
        ->post("/contratos/{$contrato->id}/estado")->assertForbidden();
});

it('un rol restringido no puede ver un contrato de una empresa fuera de su alcance', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $contratoAjeno = Contrato::factory()->for($ajena)->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);

    $respuesta = $this->actingAs($supervisor)->get("/contratos/{$contratoAjeno->id}");
    expect($respuesta->status())->toBeIn([403, 404]);
});

it('la búsqueda de contratos requiere empresa_id, respeta el alcance del usuario y sólo trae contratos activos', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    Contrato::factory()->for($miEmpresa)->create(['nombre' => 'Laboratorios Polab', 'activo' => true]);
    Contrato::factory()->for($miEmpresa)->create(['nombre' => 'Contrato Inactivo', 'activo' => false]);
    Contrato::factory()->for($ajena)->create(['nombre' => 'Contrato Ajeno']);

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);

    $this->actingAs($supervisor)
        ->get('/contratos/buscar')
        ->assertJson(['contratos' => []]);

    $this->actingAs($supervisor)
        ->get("/contratos/buscar?empresa_id={$miEmpresa->id}")
        ->assertJsonFragment(['nombre' => 'Laboratorios Polab'])
        ->assertJsonMissing(['nombre' => 'Contrato Inactivo'])
        ->assertJsonMissing(['nombre' => 'Contrato Ajeno']);

    $this->actingAs($supervisor)
        ->get("/contratos/buscar?empresa_id={$ajena->id}")
        ->assertJson(['contratos' => []]);
});
