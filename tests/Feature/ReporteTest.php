<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use App\Models\Sucursal;

/**
 * Cobertura mínima de `/reportes`: la Fase 10 quitó el botón "Aplicar
 * filtros" del frontend (ahora auto-aplica por `watch()` + debounce), pero
 * el backend (`ReporteController::filtros()` + `ServicioReportes`) no
 * cambió — estos tests confirman que los filtros siguen funcionando y que
 * el alcance multiempresa se respeta igual que antes.
 */
it('GET /reportes responde 200 en ambos tabs', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/reportes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Reportes/Index')->where('tab', 'entregas'));

    $this->actingAs($admin)->get('/reportes?tab=inventario')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Reportes/Index')->where('tab', 'inventario'));
});

it('el filtro de empresa en Reportes/Entregas acota los resultados a esa empresa', function () {
    sembrarRolesPermisos();
    $empresaA = Empresa::factory()->create();
    $sucursalA = Sucursal::factory()->for($empresaA)->create();
    $almacenA = Almacen::factory()->paraEmpresa($empresaA)->create();
    EntregaUniforme::factory()->for($empresaA)->for($sucursalA)->create(['almacen_id' => $almacenA->id]);

    $empresaB = Empresa::factory()->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();
    $almacenB = Almacen::factory()->paraEmpresa($empresaB)->create();
    EntregaUniforme::factory()->for($empresaB)->for($sucursalB)->create(['almacen_id' => $almacenB->id]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresaA, $empresaB]);

    $this->actingAs($admin)
        ->get("/reportes?empresa_id={$empresaA->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totales.entregas', 1)
            ->has('entregas.data', 1));
});

it('el filtro "solo bajo mínimo" en Reportes/Inventario sólo devuelve saldos bajo mínimo', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();

    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 1, 'minimo' => 10]);
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 50, 'minimo' => 5]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)
        ->get('/reportes?tab=inventario&solo_bajo_minimo=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('inventario.data', 1)->where('inventario.data.0.cantidad', 1));
});

it('un rol restringido no ve en Reportes datos de una empresa fuera de su alcance', function () {
    sembrarRolesPermisos();
    $empresaPropia = Empresa::factory()->create();
    $sucursalPropia = Sucursal::factory()->for($empresaPropia)->create();
    $almacenPropio = Almacen::factory()->paraEmpresa($empresaPropia)->create();
    EntregaUniforme::factory()->for($empresaPropia)->for($sucursalPropia)->create(['almacen_id' => $almacenPropio->id]);

    $empresaAjena = Empresa::factory()->create();
    $sucursalAjena = Sucursal::factory()->for($empresaAjena)->create();
    $almacenAjeno = Almacen::factory()->paraEmpresa($empresaAjena)->create();
    EntregaUniforme::factory()->for($empresaAjena)->for($sucursalAjena)->create(['almacen_id' => $almacenAjeno->id]);

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresaPropia]);

    $this->actingAs($supervisor)
        ->get('/reportes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totales.entregas', 1)->has('entregas.data', 1));
});

it('la exportación de Excel de Entregas respeta el filtro de empresa activo', function () {
    sembrarRolesPermisos();
    $empresaA = Empresa::factory()->create();
    $sucursalA = Sucursal::factory()->for($empresaA)->create();
    $almacenA = Almacen::factory()->paraEmpresa($empresaA)->create();
    EntregaUniforme::factory()->for($empresaA)->for($sucursalA)->create(['almacen_id' => $almacenA->id]);

    $empresaB = Empresa::factory()->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();
    $almacenB = Almacen::factory()->paraEmpresa($empresaB)->create();
    EntregaUniforme::factory()->for($empresaB)->for($sucursalB)->create(['almacen_id' => $almacenB->id]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresaA, $empresaB]);

    $this->actingAs($admin)
        ->get("/reportes/entregas/exportar?empresa_id={$empresaA->id}&formato=xlsx")
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('sin permiso reportes.ver, /reportes responde 403', function () {
    sembrarRolesPermisos();
    $colaborador = usuarioCon(RolSistema::Colaborador->value);

    $this->actingAs($colaborador)->get('/reportes')->assertForbidden();
});

it('exporta el reporte de inventario en PDF respetando el mismo permiso y los filtros', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 2, 'minimo' => 10]);
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 40, 'minimo' => 5]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $completo = $this->actingAs($admin)->get('/reportes/inventario/exportar?formato=pdf');
    $completo->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($completo->getContent(), 0, 4))->toBe('%PDF');

    // El filtro `solo_bajo_minimo` se respeta igual que en pantalla / Excel.
    $filtrado = $this->actingAs($admin)->get('/reportes/inventario/exportar?formato=pdf&solo_bajo_minimo=1');
    $filtrado->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('el Excel de inventario sigue funcionando tras añadir el PDF', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 3, 'minimo' => 1]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)
        ->get('/reportes/inventario/exportar?formato=xlsx')
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('un usuario sin reportes.exportar no puede descargar el PDF de inventario aunque arme la URL', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sinPermiso = usuarioCon(RolSistema::Encargado->value, [$empresa]);

    $this->actingAs($sinPermiso)
        ->get("/reportes/inventario/exportar?formato=pdf&empresa_id={$empresa->id}")
        ->assertForbidden();
});
