<?php

use App\Acciones\RegistrarEntradaInventario;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use App\Servicios\ServicioInventario;

beforeEach(function () {
    sembrarRolesPermisos();
});

it('un administrador ve todos los almacenes y puede filtrarlos por empresa abastecida', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Almacen::factory()->count(2)->paraEmpresa($empresaA)->create();
    Almacen::factory()->count(3)->paraEmpresa($empresaB)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/almacenes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Almacenes/Index')
            ->where('almacenes.total', 5)
            ->has('empresasAutorizadas')
        );

    $this->actingAs($admin)
        ->get('/almacenes?empresa_id='.$empresaA->id)
        ->assertInertia(fn ($page) => $page->where('almacenes.total', 2));
});

it('sólo quien administra almacenes ve los eliminados, ni forzando el filtro por URL', function () {
    $empresa = Empresa::factory()->create();
    Almacen::factory()->paraEmpresa($empresa)->create(['nombre' => 'Vivo', 'activo' => true]);
    Almacen::factory()->paraEmpresa($empresa)->create(['nombre' => 'Apagado', 'activo' => false]);

    $admin = usuarioCon(RolSistema::Administrador->value);
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);

    $this->actingAs($admin)
        ->get('/almacenes?estado=inactivos')
        ->assertInertia(fn ($page) => $page
            ->where('almacenes.total', 1)
            ->where('almacenes.data.0.nombre', 'Apagado')
            ->where('permisos.verEliminados', true),
        );

    $this->actingAs($supervisor)
        ->get('/almacenes')
        ->assertInertia(fn ($page) => $page
            ->where('almacenes.total', 1)
            ->where('almacenes.data.0.nombre', 'Vivo')
            ->where('permisos.verEliminados', false),
        );

    $this->actingAs($supervisor)
        ->get('/almacenes?estado=inactivos')
        ->assertInertia(fn ($page) => $page
            ->where('almacenes.total', 1)
            ->where('almacenes.data.0.nombre', 'Vivo'),
        );
});

it('un administrador crea un almacén con código autogenerado y varias empresas abastecidas', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/almacenes', [
            'nombre' => 'Almacén Morelos',
            'telefono' => '7771234567',
            'empresa_ids' => [$empresaA->id, $empresaB->id],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toast');

    $almacen = Almacen::query()->where('nombre', 'Almacén Morelos')->first();
    expect($almacen)->not->toBeNull();
    expect($almacen->codigo)->toStartWith('ALM-');
    expect($almacen->telefono)->toBe('7771234567');
    expect($almacen->empresas()->pluck('empresas.id')->sort()->values()->all())
        ->toBe(collect([$empresaA->id, $empresaB->id])->sort()->values()->all());
});

it('exige al menos una empresa abastecida', function () {
    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/almacenes')
        ->post('/almacenes', ['nombre' => 'Sin empresas', 'empresa_ids' => []])
        ->assertSessionHasErrors('empresa_ids');
});

it('rechaza una empresa abastecida fuera del alcance del usuario', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);

    $supervisor->givePermissionTo('almacenes.crear');

    $this->actingAs($supervisor)
        ->from('/almacenes')
        ->post('/almacenes', ['nombre' => 'X', 'empresa_ids' => [$miEmpresa->id, $ajena->id]])
        ->assertSessionHasErrors('empresa_ids.1');
});

it('el código de almacén es único a nivel plataforma', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Almacen::factory()->paraEmpresa($empresaA)->create(['codigo' => 'CENTRAL']);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/almacenes')
        ->post('/almacenes', ['nombre' => 'Otro', 'codigo' => 'CENTRAL', 'empresa_ids' => [$empresaB->id]])
        ->assertSessionHasErrors('codigo');
});

it('un administrador puede editar un almacén y cambiar sus empresas abastecidas', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresaA)->create(['nombre' => 'Antes']);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->put("/almacenes/{$almacen->id}", [
            'nombre' => 'Después',
            'empresa_ids' => [$empresaA->id, $empresaB->id],
        ])
        ->assertSessionHasNoErrors();

    expect($almacen->fresh()->nombre)->toBe('Después');
    expect($almacen->empresas()->count())->toBe(2);
});

it('acepta un responsable de alguna empresa abastecida y rechaza uno de otra', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $responsable = Colaborador::factory()->for($empresa)->for($sucursal)->create();

    $otra = Empresa::factory()->create();
    $sucursalOtra = Sucursal::factory()->for($otra)->create();
    $ajeno = Colaborador::factory()->for($otra)->for($sucursalOtra)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post('/almacenes', ['nombre' => 'Con Responsable', 'empresa_ids' => [$empresa->id], 'responsable_colaborador_id' => $responsable->id])
        ->assertSessionHasNoErrors();
    expect(Almacen::query()->where('nombre', 'Con Responsable')->first()->responsable_colaborador_id)->toBe($responsable->id);

    $this->actingAs($admin)
        ->from('/almacenes')
        ->post('/almacenes', ['nombre' => 'Responsable Ajeno', 'empresa_ids' => [$empresa->id], 'responsable_colaborador_id' => $ajeno->id])
        ->assertSessionHasErrors('responsable_colaborador_id');
});

it('rechaza como responsable a un colaborador inactivo', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $inactivo = Colaborador::factory()->for($empresa)->for($sucursal)->inactivo()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/almacenes')
        ->post('/almacenes', ['nombre' => 'X', 'empresa_ids' => [$empresa->id], 'responsable_colaborador_id' => $inactivo->id])
        ->assertSessionHasErrors('responsable_colaborador_id');
});

it('activar y desactivar un almacén no toca sus empresas ni el catálogo', function () {
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create(['activo' => true]);

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post("/almacenes/{$almacen->id}/estado")->assertSessionHas('toast');
    expect($almacen->fresh()->activo)->toBeFalse();
    expect($almacen->empresas()->count())->toBe(1);

    $this->actingAs($admin)->post("/almacenes/{$almacen->id}/estado");
    expect($almacen->fresh()->activo)->toBeTrue();
});

it('un supervisor no puede crear ni cambiar el estado de un almacén', function () {
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);

    $this->actingAs($supervisor)
        ->post('/almacenes', ['nombre' => 'Intento', 'empresa_ids' => [$empresa->id]])->assertForbidden();

    $this->actingAs($supervisor)
        ->post("/almacenes/{$almacen->id}/estado")->assertForbidden();

    expect(Almacen::query()->where('nombre', 'Intento')->exists())->toBeFalse();
});

it('un rol restringido no puede ver un almacén de una empresa fuera de su alcance (IDOR)', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $almacenAjeno = Almacen::factory()->paraEmpresa($ajena)->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);

    $respuesta = $this->actingAs($supervisor)->get("/almacenes/{$almacenAjeno->id}");
    expect($respuesta->status())->toBeIn([403, 404]);
});

it('valida sin generar un 500 cuando el nombre llega como arreglo', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/almacenes')
        ->post('/almacenes', ['nombre' => ['no', 'texto'], 'empresa_ids' => [$empresa->id]])
        ->assertRedirect('/almacenes')
        ->assertSessionHasErrors('nombre');
});

it('el detalle de almacén es CRUD/configuración: sin desglose de inventario, sólo un resumen mínimo', function () {
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create(['tipo_control' => 'cantidad']);

    app(RegistrarEntradaInventario::class)->ejecutar(
        empresaId: $empresa->id,
        almacenId: $almacen->id,
        items: [['activo_id' => $activo->id, 'talla_id' => null, 'cantidad' => 7]],
        motivo: 'Compra',
        realizadoPor: null,
    );

    $this->actingAs(usuarioCon(RolSistema::Administrador->value, [$empresa]))
        ->get("/almacenes/{$almacen->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Almacenes/Detalle')
            ->where('resumen.empresas_abastecidas', 1)
            ->where('resumen.activos_con_existencia', 1)
            ->missing('inventarioPorEmpresa')
            ->missing('resumen.existencias')
            ->missing('resumen.variantes_bajo_minimo'),
        );
});

it('no permite quitar una empresa abastecida de un almacén si todavía tiene existencias operativas', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresaA, $empresaB)->create();
    $activo = Activo::factory()->for($empresaA)->create(['tipo_control' => 'cantidad']);

    app(RegistrarEntradaInventario::class)->ejecutar(
        empresaId: $empresaA->id,
        almacenId: $almacen->id,
        items: [['activo_id' => $activo->id, 'talla_id' => null, 'cantidad' => 5]],
        motivo: 'Compra',
        realizadoPor: null,
    );

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->put("/almacenes/{$almacen->id}", [
            'nombre' => $almacen->nombre,
            'empresa_ids' => [$empresaB->id],
        ])
        ->assertSessionHasErrors('empresa_ids');

    expect($almacen->fresh()->empresas()->pluck('empresas.id')->sort()->values()->all())
        ->toBe(collect([$empresaA->id, $empresaB->id])->sort()->values()->all());
});

it('sí permite quitar una empresa abastecida sin existencias operativas, conservando históricos', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresaA, $empresaB)->create();
    $activo = Activo::factory()->for($empresaA)->create(['tipo_control' => 'cantidad']);

    app(RegistrarEntradaInventario::class)->ejecutar(
        empresaId: $empresaA->id,
        almacenId: $almacen->id,
        items: [['activo_id' => $activo->id, 'talla_id' => null, 'cantidad' => 5]],
        motivo: 'Compra',
        realizadoPor: null,
    );
    app(ServicioInventario::class)->fijarExistencia($empresaA->id, $almacen->id, $activo->id, null, 0, 'Salida total', null);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->put("/almacenes/{$almacen->id}", [
            'nombre' => $almacen->nombre,
            'empresa_ids' => [$empresaB->id],
        ])
        ->assertSessionHasNoErrors();

    expect($almacen->fresh()->empresas()->pluck('empresas.id')->all())->toBe([$empresaB->id])
        ->and(SaldoInventario::query()->where('activo_id', $activo->id)->exists())->toBeTrue();
});

it('la búsqueda de colaboradores para responsable exige empresa_id y sólo devuelve activos de esa empresa', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    Colaborador::factory()->for($empresa)->for($sucursal)->create(['nombre_completo' => 'Ana Buscable']);
    Colaborador::factory()->for($empresa)->for($sucursal)->inactivo()->create(['nombre_completo' => 'Ana Inactiva']);

    $otra = Empresa::factory()->create();
    $sucursalOtra = Sucursal::factory()->for($otra)->create();
    Colaborador::factory()->for($otra)->for($sucursalOtra)->create(['nombre_completo' => 'Ana Ajena']);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->getJson('/almacenes/colaboradores-buscar?empresa_id='.$empresa->id.'&q=Ana')
        ->assertOk()
        ->assertJsonPath('colaboradores.0.nombre_completo', 'Ana Buscable')
        ->assertJsonCount(1, 'colaboradores');
});
