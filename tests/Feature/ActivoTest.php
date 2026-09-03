<?php

use App\Enums\RolSistema;
use App\Enums\TipoControlActivo;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Models\TipoActivo;
use App\Soporte\ContextoEmpresa;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    sembrarRolesPermisos();
});

it('conserva los datos migrados desde el antiguo catálogo de prendas', function () {
    // La tabla ya se llama `activos`; un registro "de siempre" sigue accesible.
    $empresa = Empresa::factory()->create();
    $activo = Activo::factory()->for($empresa)->create([
        'nombre' => 'Camisa Operativa',
        'codigo' => 'CAM-001',
        'tipo_control' => TipoControlActivo::Cantidad,
    ]);

    expect($activo->fresh()->nombre)->toBe('Camisa Operativa');
    expect($activo->fresh()->tipo_control)->toBe(TipoControlActivo::Cantidad);
});

it('un administrador ve los activos de la empresa activa y no los de otra', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Activo::factory()->count(2)->for($empresaA)->create();
    Activo::factory()->count(3)->for($empresaB)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresaA->id])
        ->get('/activos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Activos/Index')
            ->has('activos', 2)
        );
});

it('un administrador crea un activo por cantidad con tallas y código autogenerado', function () {
    $empresa = Empresa::factory()->create();
    $tallas = Talla::factory()->count(2)->for($empresa)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post('/activos', [
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
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post('/activos', [
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
    $tipoAjeno = TipoActivo::factory()->for($otra)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])->from('/activos/crear')
        ->post('/activos', ['nombre' => 'X', 'tipo_control' => 'inventado'])
        ->assertSessionHasErrors('tipo_control');

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])->from('/activos/crear')
        ->post('/activos', ['nombre' => 'Y', 'tipo_control' => 'cantidad', 'tipo_activo_id' => $tipoAjeno->id])
        ->assertSessionHasErrors('tipo_activo_id');
});

it('valida la imagen: rechaza un archivo que no es imagen', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->from('/activos/crear')
        ->post('/activos', [
            'nombre' => 'Con archivo',
            'tipo_control' => 'cantidad',
            'imagen' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
        ])
        ->assertSessionHasErrors('imagen');
});

it('el activo se crea siempre en la empresa activa, ignorando el empresa_id del formulario', function () {
    $activa = Empresa::factory()->create();
    $otra = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $activa->id])
        ->post('/activos', ['nombre' => 'Ancla', 'tipo_control' => 'cantidad', 'empresa_id' => $otra->id]);

    expect(Activo::query()->where('nombre', 'Ancla')->first()->empresa_id)->toBe($activa->id);
});

it('no permite ver ni editar un activo de otra empresa (IDOR)', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $activoB = Activo::factory()->for($empresaB)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresaA->id])
        ->get("/activos/{$activoB->id}")->assertNotFound();

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresaA->id])
        ->get("/activos/{$activoB->id}/editar")->assertNotFound();
});

it('el detalle muestra las existencias por almacén y talla del activo', function () {
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->for($empresa)->create();
    $talla = Talla::factory()->for($empresa)->create(['valor' => 'M']);
    $activo = Activo::factory()->for($empresa)->create();

    SaldoInventario::factory()->create([
        'empresa_id' => $empresa->id,
        'almacen_id' => $almacen->id,
        'sucursal_id' => null,
        'activo_id' => $activo->id,
        'talla_id' => $talla->id,
        'cantidad' => 12,
    ]);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
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

    $this->actingAs($supervisor)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post("/activos/{$activo->id}/estado")
        ->assertForbidden();
});

it('valida sin generar un 500 cuando el nombre llega como arreglo', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->from('/activos/crear')
        ->post('/activos', ['nombre' => ['no'], 'tipo_control' => 'cantidad'])
        ->assertRedirect('/activos/crear')
        ->assertSessionHasErrors('nombre');
});
