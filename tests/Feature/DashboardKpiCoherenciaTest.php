<?php

use App\Enums\CondicionUnidadActivo;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use App\Models\UnidadActivo;
use Tests\TestCase;

/**
 * Coherencia KPI ↔ listado destino: el número que muestra la card del
 * Dashboard debe ser EXACTAMENTE el mismo que el usuario ve al hacer clic y
 * llegar al listado filtrado (`Panel.vue` arma la URL con los mismos filtros
 * que ya se usaron para calcular el `resumen`). Fixture propio y autónomo
 * (no comparte `escenarioDashboard()` de `DashboardTest.php` para poder
 * ejecutarse también de forma aislada, sin depender del orden de carga de
 * los archivos de test).
 *
 * @return array{empresa: Empresa, almacen: Almacen, sucursal: Sucursal}
 */
function escenarioCoherenciaDashboard(): array
{
    sembrarRolesPermisos();

    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();

    $colaboradores = Colaborador::factory()->for($empresa)->for($sucursal)->count(2)->create(['activo' => true]);
    Colaborador::factory()->for($empresa)->for($sucursal)->create(['activo' => false]);
    $colaborador = $colaboradores->first();
    $encargado = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $activo = Activo::factory()->for($empresa)->create();
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 2, 'minimo' => 10]);
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 50, 'minimo' => 5]);

    $activoIndividual = Activo::factory()->for($empresa)->seguimientoIndividual()->create();
    UnidadActivo::factory()->for($empresa)->for($activoIndividual)->for($almacen)->create();
    UnidadActivo::factory()->for($empresa)->for($activoIndividual)->for($almacen)->asignada()->create();
    UnidadActivo::factory()->for($empresa)->for($activoIndividual)->for($almacen)
        ->conCondicion(CondicionUnidadActivo::EnReparacion)->create();

    EntregaUniforme::factory()->for($empresa)->for($sucursal)->create([
        'almacen_id' => $almacen->id,
        'colaborador_id' => $colaborador->id,
        'encargado_id' => $encargado->id,
        'fecha_entrega' => now()->toDateString(),
    ]);
    Devolucion::factory()->for($empresa)->for($sucursal)->create([
        'almacen_id' => $almacen->id,
        'colaborador_id' => $colaborador->id,
        'registrada_por' => $encargado->id,
        'fecha' => now()->toDateString(),
    ]);

    return compact('empresa', 'almacen', 'sucursal');
}

function kpisDelDashboard(TestCase $test, string $query): array
{
    $kpis = null;
    $test->get("/dashboard{$query}")->assertInertia(function ($page) use (&$kpis) {
        $kpis = $page->toArray()['props']['resumen']['kpis'];
    });

    return $kpis;
}

it('Colaboradores activos: el KPI coincide con el total del listado filtrado', function () {
    ['empresa' => $empresa] = escenarioCoherenciaDashboard();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);
    $this->actingAs($admin);

    $kpi = kpisDelDashboard($this, "?empresa_id={$empresa->id}")['colaboradores_activos'];

    $this->get("/colaboradores?empresa_id={$empresa->id}&estado=activos")
        ->assertInertia(fn ($page) => $page->where('colaboradores.total', $kpi));
});

it('Entregas del periodo: el KPI coincide con el total del listado filtrado (empresa + fechas)', function () {
    ['empresa' => $empresa] = escenarioCoherenciaDashboard();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);
    $this->actingAs($admin);

    $desde = now()->subDays(29)->toDateString();
    $hasta = now()->toDateString();

    $kpi = kpisDelDashboard($this, "?empresa_id={$empresa->id}&desde={$desde}&hasta={$hasta}")['entregas_periodo'];

    $this->get("/entregas?empresa_id={$empresa->id}&desde={$desde}&hasta={$hasta}")
        ->assertInertia(fn ($page) => $page->where('entregas.total', $kpi));
});

it('Devoluciones del periodo: el KPI coincide con el total del listado filtrado (empresa + fechas)', function () {
    ['empresa' => $empresa] = escenarioCoherenciaDashboard();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);
    $this->actingAs($admin);

    $desde = now()->subDays(29)->toDateString();
    $hasta = now()->toDateString();

    $kpi = kpisDelDashboard($this, "?empresa_id={$empresa->id}&desde={$desde}&hasta={$hasta}")['devoluciones_periodo'];

    $this->get("/devoluciones?empresa_id={$empresa->id}&desde={$desde}&hasta={$hasta}")
        ->assertInertia(fn ($page) => $page->where('devoluciones.total', $kpi));
});

it('Activos con stock bajo: el KPI coincide con el total del listado de Inventario filtrado', function () {
    ['empresa' => $empresa] = escenarioCoherenciaDashboard();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);
    $this->actingAs($admin);

    $kpi = kpisDelDashboard($this, "?empresa_id={$empresa->id}")['activos_stock_bajo'];

    $this->get("/inventario?empresa_id={$empresa->id}&estado_stock=bajo_minimo")
        ->assertInertia(fn ($page) => $page->where('saldos.total', $kpi));
});

it('Almacenes activos: el KPI coincide con el total del listado filtrado', function () {
    ['empresa' => $empresa] = escenarioCoherenciaDashboard();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);
    $this->actingAs($admin);

    $kpi = kpisDelDashboard($this, "?empresa_id={$empresa->id}")['almacenes_activos'];

    $this->get("/almacenes?empresa_id={$empresa->id}&estado=activos")
        ->assertInertia(fn ($page) => $page->where('almacenes.total', $kpi));
});

it('Unidades disponibles/asignadas/en reparación: cada KPI coincide con estado_visible en Unidades', function () {
    ['empresa' => $empresa, 'almacen' => $almacen] = escenarioCoherenciaDashboard();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);
    $this->actingAs($admin);

    $kpis = kpisDelDashboard($this, "?empresa_id={$empresa->id}&almacen_id={$almacen->id}");

    foreach ([
        'unidades_disponibles' => 'disponible',
        'unidades_asignadas' => 'asignado',
        'unidades_en_reparacion' => 'reparacion',
    ] as $kpiKey => $estadoVisible) {
        $this->get("/activos/unidades?empresa_id={$empresa->id}&almacen_id={$almacen->id}&estado_visible={$estadoVisible}")
            ->assertInertia(fn ($page) => $page->where('unidades.total', $kpis[$kpiKey]));
    }
});

it('un rol restringido de otra empresa nunca ve, vía el enlace del KPI, datos de una empresa ajena', function () {
    ['empresa' => $empresaPropia] = escenarioCoherenciaDashboard();
    ['empresa' => $empresaAjena] = escenarioCoherenciaDashboard();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresaPropia]);

    // Si alguien manipula el enlace del KPI para apuntar a la empresa ajena,
    // el listado destino debe seguir aplicando su propio alcance — nunca
    // confiar en el empresa_id que trae la URL.
    $this->actingAs($supervisor)
        ->get("/colaboradores?empresa_id={$empresaAjena->id}&estado=activos")
        ->assertInertia(fn ($page) => $page
            ->where('filtros.empresa_id', null)
            ->where('colaboradores.total', 2)); // sólo ve los de su propia empresa
});
