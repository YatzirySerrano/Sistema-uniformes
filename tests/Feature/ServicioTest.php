<?php

use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\Servicio;
use App\Models\Sucursal;

beforeEach(function () {
    sembrarRolesPermisos();
});

it('un administrador ve todos los servicios y puede filtrarlos por empresa y por contrato', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $contratoA1 = Contrato::factory()->for($empresaA)->create();
    $contratoA2 = Contrato::factory()->for($empresaA)->create();
    $contratoB = Contrato::factory()->for($empresaB)->create();
    $sucursalA = Sucursal::factory()->for($empresaA)->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();

    Servicio::factory()->count(2)->for($contratoA1)->for($sucursalA)->create();
    Servicio::factory()->count(1)->for($contratoA2)->for($sucursalA)->create();
    Servicio::factory()->count(3)->for($contratoB)->for($sucursalB)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/servicios')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Servicios/Index')->where('servicios.total', 6));

    $this->actingAs($admin)
        ->get('/servicios?empresa_id='.$empresaA->id)
        ->assertInertia(fn ($page) => $page->where('servicios.total', 3));

    $this->actingAs($admin)
        ->get('/servicios?contrato_id='.$contratoA1->id)
        ->assertInertia(fn ($page) => $page->where('servicios.total', 2));
});

it('un administrador crea un servicio con contrato y sucursal de la misma empresa, con código autogenerado global', function () {
    $empresa = Empresa::factory()->create();
    $contrato = Contrato::factory()->for($empresa)->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/servicios', [
            'contrato_id' => $contrato->id,
            'sucursal_id' => $sucursal->id,
            'nombre' => 'Polab Cuernavaca',
        ])
        ->assertRedirect()
        ->assertSessionHas('toast')
        ->assertSessionHasNoErrors();

    $servicio = Servicio::query()->where('nombre', 'Polab Cuernavaca')->first();
    expect($servicio)->not->toBeNull();
    expect($servicio->contrato_id)->toBe($contrato->id);
    expect($servicio->sucursal_id)->toBe($sucursal->id);
    expect($servicio->codigo)->toStartWith('SER-');
    expect($servicio->activo)->toBeTrue();
});

it('el código de servicio es único a nivel de plataforma (generador global), no por empresa', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $contratoA = Contrato::factory()->for($empresaA)->create();
    $contratoB = Contrato::factory()->for($empresaB)->create();
    $sucursalA = Sucursal::factory()->for($empresaA)->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->post('/servicios', ['contrato_id' => $contratoA->id, 'sucursal_id' => $sucursalA->id, 'nombre' => 'Servicio A']);
    $this->actingAs($admin)->post('/servicios', ['contrato_id' => $contratoB->id, 'sucursal_id' => $sucursalB->id, 'nombre' => 'Servicio B']);

    $codigos = Servicio::query()->orderBy('id')->pluck('codigo')->all();
    expect($codigos)->toHaveCount(2);
    expect($codigos[0])->not->toBe($codigos[1]);
});

it('CASO 5: rechaza un servicio cuyo contrato y sucursal pertenecen a empresas distintas', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $contratoA = Contrato::factory()->for($empresaA)->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/servicios')
        ->post('/servicios', [
            'contrato_id' => $contratoA->id,
            'sucursal_id' => $sucursalB->id,
            'nombre' => 'Servicio inválido',
        ])
        ->assertSessionHasErrors('sucursal_id');

    expect(Servicio::query()->where('nombre', 'Servicio inválido')->exists())->toBeFalse();
});

it('rechaza crear un servicio con un contrato fuera del alcance del usuario', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $contratoAjeno = Contrato::factory()->for($ajena)->create();
    $sucursalAjena = Sucursal::factory()->for($ajena)->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);

    $this->actingAs($supervisor)
        ->from('/servicios')
        ->post('/servicios', [
            'contrato_id' => $contratoAjeno->id,
            'sucursal_id' => $sucursalAjena->id,
            'nombre' => 'Servicio ajeno',
        ])
        ->assertSessionHasErrors('contrato_id');

    expect(Servicio::query()->where('nombre', 'Servicio ajeno')->exists())->toBeFalse();
});

it('rechaza un nombre de servicio duplicado dentro del mismo contrato y lo permite en otro', function () {
    $empresa = Empresa::factory()->create();
    $contratoA = Contrato::factory()->for($empresa)->create();
    $contratoB = Contrato::factory()->for($empresa)->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    Servicio::factory()->for($contratoA)->for($sucursal)->create(['nombre' => 'Acceso principal']);

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->from('/servicios')
        ->post('/servicios', ['contrato_id' => $contratoA->id, 'sucursal_id' => $sucursal->id, 'nombre' => 'Acceso principal'])
        ->assertSessionHasErrors('nombre');

    $this->actingAs($admin)
        ->post('/servicios', ['contrato_id' => $contratoB->id, 'sucursal_id' => $sucursal->id, 'nombre' => 'Acceso principal'])
        ->assertSessionHasNoErrors();
});

it('un administrador puede editar y activar/desactivar un servicio sin borrar colaboradores que lo tengan asignado', function () {
    $empresa = Empresa::factory()->create();
    $contrato = Contrato::factory()->for($empresa)->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $servicio = Servicio::factory()->for($contrato)->for($sucursal)->create(['nombre' => 'Antes', 'activo' => true]);
    Colaborador::factory()->for($empresa)->for($sucursal)->create(['servicio_actual_id' => $servicio->id]);

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->put("/servicios/{$servicio->id}", ['contrato_id' => $contrato->id, 'sucursal_id' => $sucursal->id, 'nombre' => 'Después'])
        ->assertSessionHasNoErrors();
    expect($servicio->fresh()->nombre)->toBe('Después');

    $this->actingAs($admin)
        ->post("/servicios/{$servicio->id}/estado")->assertSessionHas('toast');
    expect($servicio->fresh()->activo)->toBeFalse();
    expect(Colaborador::query()->where('servicio_actual_id', $servicio->id)->count())->toBe(1);
});

it('un encargado sin permiso de edición no puede crear ni cambiar el estado de un servicio', function () {
    $empresa = Empresa::factory()->create();
    $contrato = Contrato::factory()->for($empresa)->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $servicio = Servicio::factory()->for($contrato)->for($sucursal)->create();
    $encargado = usuarioCon(RolSistema::Encargado->value, [$empresa]);

    $this->actingAs($encargado)
        ->post('/servicios', ['contrato_id' => $contrato->id, 'sucursal_id' => $sucursal->id, 'nombre' => 'Intento'])
        ->assertForbidden();

    $this->actingAs($encargado)
        ->post("/servicios/{$servicio->id}/estado")->assertForbidden();
});

it('un rol restringido no puede ver un servicio de una empresa fuera de su alcance', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $contratoAjeno = Contrato::factory()->for($ajena)->create();
    $sucursalAjena = Sucursal::factory()->for($ajena)->create();
    $servicioAjeno = Servicio::factory()->for($contratoAjeno)->for($sucursalAjena)->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);

    $respuesta = $this->actingAs($supervisor)->get("/servicios/{$servicioAjeno->id}");
    expect($respuesta->status())->toBeIn([403, 404]);
});

it('la búsqueda de servicios acota por contrato_id y respeta el alcance del usuario', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $contrato = Contrato::factory()->for($miEmpresa)->create();
    $contratoAjeno = Contrato::factory()->for($ajena)->create();
    $sucursal = Sucursal::factory()->for($miEmpresa)->create();
    $sucursalAjena = Sucursal::factory()->for($ajena)->create();
    Servicio::factory()->for($contrato)->for($sucursal)->create(['nombre' => 'Polab Jiutepec', 'activo' => true]);
    Servicio::factory()->for($contrato)->for($sucursal)->create(['nombre' => 'Servicio Inactivo', 'activo' => false]);
    Servicio::factory()->for($contratoAjeno)->for($sucursalAjena)->create(['nombre' => 'Servicio Ajeno']);

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);

    $this->actingAs($supervisor)
        ->get('/servicios/buscar')
        ->assertJson(['servicios' => []]);

    $this->actingAs($supervisor)
        ->get("/servicios/buscar?contrato_id={$contrato->id}")
        ->assertJsonFragment(['nombre' => 'Polab Jiutepec'])
        ->assertJsonMissing(['nombre' => 'Servicio Inactivo']);

    $this->actingAs($supervisor)
        ->get("/servicios/buscar?contrato_id={$contratoAjeno->id}")
        ->assertJson(['servicios' => []]);
});
