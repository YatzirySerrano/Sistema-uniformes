<?php

use App\Enums\RolSistema;
use App\Enums\TipoControlActivo;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Models\TipoActivo;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    sembrarRolesPermisos();
});

it('conserva los datos migrados desde el antiguo catálogo de prendas', function () {
    $empresa = Empresa::factory()->create();
    $activo = Activo::factory()->for($empresa)->create([
        'nombre' => 'Camisa Operativa',
        'codigo' => 'CAM-001',
        'tipo_control' => TipoControlActivo::Cantidad,
    ]);

    expect($activo->fresh()->nombre)->toBe('Camisa Operativa');
    expect($activo->fresh()->tipo_control)->toBe(TipoControlActivo::Cantidad);
});

it('un administrador ve todos los activos y puede filtrarlos por empresa', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Activo::factory()->count(2)->for($empresaA)->create();
    Activo::factory()->count(3)->for($empresaB)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/activos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Activos/Index')
            ->has('activos', 5)
        );

    $this->actingAs($admin)
        ->get('/activos?empresa_id='.$empresaA->id)
        ->assertInertia(fn ($page) => $page->has('activos', 2));
});

it('un administrador crea un activo por cantidad con tallas y código autogenerado', function () {
    $empresa = Empresa::factory()->create();
    $tallas = Talla::factory()->count(2)->paraEmpresa($empresa)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/activos', [
            'empresa_id' => $empresa->id,
            'nombre' => 'Playera Institucional',
            'tipo_control' => 'cantidad',
            'tallas' => $tallas->pluck('id')->all(),
        ])
        ->assertRedirect('/activos')
        ->assertSessionHasNoErrors();

    $activo = Activo::query()->where('nombre', 'Playera Institucional')->first();
    expect($activo)->not->toBeNull();
    expect($activo->empresa_id)->toBe($empresa->id);
    expect($activo->codigo)->toStartWith('ACT-');
    expect($activo->tipo_control)->toBe(TipoControlActivo::Cantidad);
    expect($activo->tallas()->count())->toBe(2);
});

it('permite crear un activo serializado sin tallas', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/activos', [
            'empresa_id' => $empresa->id,
            'nombre' => 'Laptop Dell',
            'tipo_control' => 'serializado',
        ])
        ->assertSessionHasNoErrors();

    $activo = Activo::query()->where('nombre', 'Laptop Dell')->first();
    expect($activo->tipo_control)->toBe(TipoControlActivo::Serializado);
    expect($activo->tallas()->count())->toBe(0);
});

it('rechaza un tipo de control inválido y un tipo de activo de otra empresa', function () {
    $empresa = Empresa::factory()->create();
    $otra = Empresa::factory()->create();
    $tipoAjeno = TipoActivo::factory()->paraEmpresa($otra)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->from('/activos/crear')
        ->post('/activos', ['empresa_id' => $empresa->id, 'nombre' => 'X', 'tipo_control' => 'inventado'])
        ->assertSessionHasErrors('tipo_control');

    $this->actingAs($admin)->from('/activos/crear')
        ->post('/activos', ['empresa_id' => $empresa->id, 'nombre' => 'Y', 'tipo_control' => 'cantidad', 'tipo_activo_id' => $tipoAjeno->id])
        ->assertSessionHasErrors('tipo_activo_id');
});

it('valida la imagen: rechaza un archivo que no es imagen', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/activos/crear')
        ->post('/activos', [
            'empresa_id' => $empresa->id,
            'nombre' => 'Con archivo',
            'tipo_control' => 'cantidad',
            'imagen' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
        ])
        ->assertSessionHasErrors('imagen');
});

it('rechaza crear un activo en una empresa fuera del alcance del usuario', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);
    $supervisor->givePermissionTo('activos.crear');

    $this->actingAs($supervisor)
        ->from('/activos/crear')
        ->post('/activos', ['nombre' => 'Ancla', 'tipo_control' => 'cantidad', 'empresa_id' => $ajena->id])
        ->assertSessionHasErrors('empresa_id');

    expect(Activo::query()->where('nombre', 'Ancla')->exists())->toBeFalse();
});

it('un rol restringido no puede ver ni editar un activo de una empresa fuera de su alcance (IDOR)', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $activoAjeno = Activo::factory()->for($ajena)->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);

    expect($this->actingAs($supervisor)->get("/activos/{$activoAjeno->id}")->status())->toBeIn([403, 404]);
    expect($this->actingAs($supervisor)->get("/activos/{$activoAjeno->id}/editar")->status())->toBeIn([403, 404]);
});

it('el detalle muestra las existencias por almacén y talla del activo', function () {
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $talla = Talla::factory()->paraEmpresa($empresa)->create(['valor' => 'M']);
    $activo = Activo::factory()->for($empresa)->create();

    SaldoInventario::factory()->create([
        'empresa_id' => $empresa->id,
        'almacen_id' => $almacen->id,
        'activo_id' => $activo->id,
        'talla_id' => $talla->id,
        'cantidad' => 12,
    ]);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->get("/activos/{$activo->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Activos/Detalle')
            ->where('saldos.0.cantidad', 12)
        );
});

it('un supervisor sin permiso de administración no puede cambiar el estado de un activo', function () {
    $empresa = Empresa::factory()->create();
    $activo = Activo::factory()->for($empresa)->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);

    $this->actingAs($supervisor)
        ->post("/activos/{$activo->id}/estado")
        ->assertForbidden();
});

it('valida sin generar un 500 cuando el nombre llega como arreglo', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/activos/crear')
        ->post('/activos', ['empresa_id' => $empresa->id, 'nombre' => ['no'], 'tipo_control' => 'cantidad'])
        ->assertRedirect('/activos/crear')
        ->assertSessionHasErrors('nombre');
});
