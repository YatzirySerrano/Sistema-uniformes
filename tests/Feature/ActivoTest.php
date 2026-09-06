<?php

use App\Acciones\RegistrarEntradaInventario;
use App\Enums\RolSistema;
use App\Enums\TipoControlActivo;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Models\TipoActivo;
use App\Models\UnidadActivo;
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

it('sólo quien puede administrar activos ve los eliminados, ni forzando el filtro por URL', function () {
    $empresa = Empresa::factory()->create();
    Activo::factory()->for($empresa)->create(['nombre' => 'Vivo', 'activo' => true]);
    Activo::factory()->for($empresa)->create(['nombre' => 'Apagado', 'activo' => false]);

    $admin = usuarioCon(RolSistema::Administrador->value);
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);

    $this->actingAs($admin)
        ->get('/activos?estado=inactivos')
        ->assertInertia(fn ($page) => $page
            ->where('permisos.verEliminados', true)
            ->has('activos', 1)
            ->where('activos.0.nombre', 'Apagado'),
        );

    $this->actingAs($supervisor)
        ->get('/activos')
        ->assertInertia(fn ($page) => $page
            ->where('permisos.verEliminados', false)
            ->has('activos', 1)
            ->where('activos.0.nombre', 'Vivo'),
        );

    $this->actingAs($supervisor)
        ->get('/activos?estado=inactivos')
        ->assertInertia(fn ($page) => $page
            ->has('activos', 1)
            ->where('activos.0.nombre', 'Vivo'),
        );
});

it('un administrador crea un activo por cantidad con tallas y código autogenerado', function () {
    $empresa = Empresa::factory()->create();
    $tallas = Talla::factory()->count(2)->create();

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

it('crea un activo por cantidad sin variantes junto con su existencia inicial (alta unificada)', function () {
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/activos', [
            'empresa_id' => $empresa->id,
            'nombre' => 'Extintor',
            'tipo_control' => 'cantidad',
            'almacen_id' => $almacen->id,
            'cantidad_inicial' => 25,
        ])
        ->assertRedirect('/activos')
        ->assertSessionHasNoErrors();

    $activo = Activo::query()->where('nombre', 'Extintor')->firstOrFail();
    $saldo = SaldoInventario::query()->where('activo_id', $activo->id)->sole();
    expect($saldo->cantidad)->toBe(25)
        ->and($saldo->almacen_id)->toBe($almacen->id)
        ->and($saldo->talla_id)->toBeNull();

    $this->assertDatabaseHas('movimientos_inventario', [
        'activo_id' => $activo->id, 'tipo' => 'inicial', 'cantidad' => 25,
    ]);
});

it('crea un activo por cantidad con variantes y una cantidad inicial por cada una', function () {
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $chica = Talla::factory()->create(['valor' => 'CH']);
    $grande = Talla::factory()->create(['valor' => 'G']);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/activos', [
            'empresa_id' => $empresa->id,
            'nombre' => 'Playera',
            'tipo_control' => 'cantidad',
            'tallas' => [$chica->id, $grande->id],
            'almacen_id' => $almacen->id,
            'existencias' => [
                ['talla_id' => $chica->id, 'cantidad' => 10],
                ['talla_id' => $grande->id, 'cantidad' => 15],
            ],
        ])
        ->assertSessionHasNoErrors();

    $activo = Activo::query()->where('nombre', 'Playera')->firstOrFail();
    expect(SaldoInventario::query()->where(['activo_id' => $activo->id, 'talla_id' => $chica->id])->value('cantidad'))->toBe(10)
        ->and(SaldoInventario::query()->where(['activo_id' => $activo->id, 'talla_id' => $grande->id])->value('cantidad'))->toBe(15);
});

it('crea un activo sin existencia inicial cuando no se indica cantidad ni almacén', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/activos', ['empresa_id' => $empresa->id, 'nombre' => 'Sólo catálogo', 'tipo_control' => 'cantidad'])
        ->assertSessionHasNoErrors();

    $activo = Activo::query()->where('nombre', 'Sólo catálogo')->firstOrFail();
    expect(SaldoInventario::query()->where('activo_id', $activo->id)->exists())->toBeFalse();
});

it('exige almacén cuando se captura una cantidad inicial mayor a cero', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/activos/crear')
        ->post('/activos', [
            'empresa_id' => $empresa->id, 'nombre' => 'X', 'tipo_control' => 'cantidad', 'cantidad_inicial' => 10,
        ])
        ->assertSessionHasErrors('almacen_id');

    expect(Activo::query()->where('nombre', 'X')->exists())->toBeFalse();
});

it('si el almacén no abastece a la empresa, el alta se rechaza y no queda ningún Activo huérfano', function () {
    $empresa = Empresa::factory()->create();
    $otra = Empresa::factory()->create();
    $almacenAjeno = Almacen::factory()->paraEmpresa($otra)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/activos/crear')
        ->post('/activos', [
            'empresa_id' => $empresa->id, 'nombre' => 'Huérfano', 'tipo_control' => 'cantidad',
            'almacen_id' => $almacenAjeno->id, 'cantidad_inicial' => 5,
        ])
        ->assertSessionHasErrors('almacen_id');

    expect(Activo::query()->where('nombre', 'Huérfano')->exists())->toBeFalse();
});

it('filtra activos por almacén: sólo los que tienen existencia ahí', function () {
    $empresa = Empresa::factory()->create();
    $almacenA = Almacen::factory()->paraEmpresa($empresa)->create();
    $almacenB = Almacen::factory()->paraEmpresa($empresa)->create();
    $enA = Activo::factory()->for($empresa)->create(['nombre' => 'En almacén A', 'tipo_control' => 'cantidad']);
    $enB = Activo::factory()->for($empresa)->create(['nombre' => 'En almacén B', 'tipo_control' => 'cantidad']);

    app(RegistrarEntradaInventario::class)->ejecutar($empresa->id, $almacenA->id, [
        ['activo_id' => $enA->id, 'talla_id' => null, 'cantidad' => 3],
    ], 'Compra', null);
    app(RegistrarEntradaInventario::class)->ejecutar($empresa->id, $almacenB->id, [
        ['activo_id' => $enB->id, 'talla_id' => null, 'cantidad' => 3],
    ], 'Compra', null);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->get('/activos?almacen_id='.$almacenA->id)
        ->assertInertia(fn ($page) => $page
            ->component('Activos/Index')
            ->has('activos', 1)
            ->where('activos.0.nombre', 'En almacén A'),
        );
});

it('permite crear un activo de seguimiento individual sin tallas', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/activos', [
            'empresa_id' => $empresa->id,
            'nombre' => 'Laptop Dell',
            'tipo_control' => 'individual',
        ])
        ->assertSessionHasNoErrors();

    $activo = Activo::query()->where('nombre', 'Laptop Dell')->first();
    expect($activo->tipo_control)->toBe(TipoControlActivo::SeguimientoIndividual);
    expect($activo->tallas()->count())->toBe(0);
});

it('rechaza un tipo de control inválido y un tipo de activo inactivo o inexistente', function () {
    $empresa = Empresa::factory()->create();
    $tipoInactivo = TipoActivo::factory()->create(['activo' => false]);

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->from('/activos/crear')
        ->post('/activos', ['empresa_id' => $empresa->id, 'nombre' => 'X', 'tipo_control' => 'inventado'])
        ->assertSessionHasErrors('tipo_control');

    $this->actingAs($admin)->from('/activos/crear')
        ->post('/activos', ['empresa_id' => $empresa->id, 'nombre' => 'Y', 'tipo_control' => 'cantidad', 'tipo_activo_id' => $tipoInactivo->id])
        ->assertSessionHasErrors('tipo_activo_id');
});

it('un tipo de activo creado para una empresa puede usarse desde cualquier otra (catálogo global)', function () {
    $empresa = Empresa::factory()->create();
    $tipo = TipoActivo::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/activos/crear')
        ->post('/activos', ['empresa_id' => $empresa->id, 'nombre' => 'Y', 'tipo_control' => 'cantidad', 'tipo_activo_id' => $tipo->id])
        ->assertSessionHasNoErrors();

    expect(Activo::query()->where('nombre', 'Y')->first()?->tipo_activo_id)->toBe($tipo->id);
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
    $talla = Talla::factory()->create(['valor' => 'M']);
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

/**
 * Regresión: `UnidadActivo::estado` está casteado a
 * `App\Enums\EstadoUnidadActivo` (enum). Usarlo directamente como clave de
 * `pluck()` (`->pluck('total', 'estado')`) provocaba
 * `TypeError: Cannot access offset of type App\Enums\EstadoUnidadActivo on
 * array` al abrir el detalle de cualquier activo de seguimiento individual
 * con unidades registradas.
 */
it('el detalle de un activo de seguimiento individual resume sus unidades por estado sin TypeError', function () {
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->seguimientoIndividual()->create();

    UnidadActivo::factory()->for($empresa)->for($activo)->for($almacen)->count(2)->create();
    UnidadActivo::factory()->for($empresa)->for($activo)->for($almacen)->asignada()->create();
    UnidadActivo::factory()->for($empresa)->for($activo)->for($almacen)->baja()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->get("/activos/{$activo->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Activos/Detalle')
            ->where('resumenUnidades.en_almacen', 2)
            ->where('resumenUnidades.asignada', 1)
            ->where('resumenUnidades.baja', 1)
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
