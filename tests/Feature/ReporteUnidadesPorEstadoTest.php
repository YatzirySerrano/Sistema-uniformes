<?php

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\EstadoVisibleUnidad;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * QA 2026-10: con Empresa = DASTI el listado mostraba 4 unidades y la
 * gráfica "Unidades por estado" del reporte de Inventario decía 168
 * disponibles (97 %): `ServicioReportes::metricasUnidades()` ignoraba el
 * filtro de empresa y contaba las unidades de TODAS las empresas
 * autorizadas. La gráfica debe usar el mismo alcance que el listado y contar
 * sólo unidades de seguimiento individual.
 */
beforeEach(function () {
    sembrarRolesPermisos();

    $this->dasti = Empresa::factory()->create(['nombre_comercial' => 'DASTI']);
    $this->inmag = Empresa::factory()->create(['nombre_comercial' => 'INMAG']);
    $this->almacenDasti = Almacen::factory()->paraEmpresa($this->dasti)->create();
    $this->almacenInmag = Almacen::factory()->paraEmpresa($this->inmag)->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->dasti, $this->inmag]);

    $equipoDasti = Activo::factory()->for($this->dasti)->seguimientoIndividual()->create();
    $equipoInmag = Activo::factory()->for($this->inmag)->seguimientoIndividual()->create();

    $this->unidad = fn (Activo $activo, Empresa $empresa, Almacen $almacen, EstadoUnidadActivo $estado, CondicionUnidadActivo $condicion) => UnidadActivo::factory()
        ->for($empresa, 'empresa')->for($activo)->for($almacen)
        ->create(['estado' => $estado, 'condicion' => $condicion]);

    // DASTI: 2 disponibles, 1 asignada, 1 robada (el caso reportado).
    ($this->unidad)($equipoDasti, $this->dasti, $this->almacenDasti, EstadoUnidadActivo::EnAlmacen, CondicionUnidadActivo::Funcionando);
    ($this->unidad)($equipoDasti, $this->dasti, $this->almacenDasti, EstadoUnidadActivo::EnAlmacen, CondicionUnidadActivo::Funcionando);
    ($this->unidad)($equipoDasti, $this->dasti, $this->almacenDasti, EstadoUnidadActivo::Asignada, CondicionUnidadActivo::Funcionando);
    ($this->unidad)($equipoDasti, $this->dasti, $this->almacenDasti, EstadoUnidadActivo::EnAlmacen, CondicionUnidadActivo::Robado);

    // INMAG: muchas disponibles que NO deben contaminar a DASTI.
    foreach (range(1, 6) as $_) {
        ($this->unidad)($equipoInmag, $this->inmag, $this->almacenInmag, EstadoUnidadActivo::EnAlmacen, CondicionUnidadActivo::Funcionando);
    }

    // Piezas POR CANTIDAD (camisas = 200): nunca entran en la gráfica.
    $camisa = Activo::factory()->for($this->dasti)->create(['nombre' => 'Camisa']);
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->dasti->id, almacenId: $this->almacenDasti->id,
        activoId: $camisa->id, tallaId: null, tipo: TipoMovimiento::Inicial, cantidad: 200,
    ));

    $this->grafica = function (array $query = []): array {
        $serie = $this->actingAs($this->admin)
            ->get('/reportes?'.http_build_query(['tab' => 'inventario', ...$query]))
            ->assertOk()
            ->viewData('page')['props']['graficas']['unidades_por_estado'];

        return collect($serie)->pluck('total', 'estado')->all();
    };
    $this->totalListado = fn (array $query): int => $this->actingAs($this->admin)
        ->get('/activos/unidades?'.http_build_query($query))
        ->assertOk()
        ->viewData('page')['props']['unidades']['total'];
});

it('caso DASTI: 2 disponibles, 1 asignada, 1 robada — total 4, sin piezas por cantidad ni unidades de otra empresa', function () {
    expect(($this->grafica)(['empresa_id' => $this->dasti->id]))->toBe([
        'disponible' => 2, 'asignado' => 1, 'reparacion' => 0, 'inservible' => 0,
        'perdido' => 0, 'robado' => 1, 'baja' => 0,
    ])
        ->and(array_sum(($this->grafica)(['empresa_id' => $this->dasti->id])))->toBe(4);
});

it('sin filtro suma todas las empresas autorizadas; el filtro por otra empresa sólo cuenta la suya', function () {
    expect(array_sum(($this->grafica)()))->toBe(10)
        ->and(($this->grafica)(['empresa_id' => $this->inmag->id])['disponible'])->toBe(6)
        ->and(array_sum(($this->grafica)(['empresa_id' => $this->inmag->id])))->toBe(6);
});

it('cada estado visible se clasifica igual que el listado de unidades, con los mismos filtros', function () {
    $equipo = Activo::factory()->for($this->dasti)->seguimientoIndividual()->create();
    ($this->unidad)($equipo, $this->dasti, $this->almacenDasti, EstadoUnidadActivo::EnAlmacen, CondicionUnidadActivo::EnReparacion);
    ($this->unidad)($equipo, $this->dasti, $this->almacenDasti, EstadoUnidadActivo::EnAlmacen, CondicionUnidadActivo::Inservible);
    ($this->unidad)($equipo, $this->dasti, $this->almacenDasti, EstadoUnidadActivo::Asignada, CondicionUnidadActivo::Perdido);
    UnidadActivo::factory()->for($this->dasti, 'empresa')->for($equipo)->for($this->almacenDasti)->baja()->create();

    $grafica = ($this->grafica)(['empresa_id' => $this->dasti->id]);

    expect($grafica)->toBe([
        'disponible' => 2, 'asignado' => 1, 'reparacion' => 1, 'inservible' => 1,
        'perdido' => 1, 'robado' => 1, 'baja' => 1,
    ]);
    foreach (EstadoVisibleUnidad::cases() as $estado) {
        expect(($this->totalListado)(['empresa_id' => $this->dasti->id, 'estado_visible' => $estado->value]))
            ->toBe($grafica[$estado->value], "Listado vs gráfica para «{$estado->etiqueta()}»");
    }
    expect(($this->totalListado)(['empresa_id' => $this->dasti->id]))->toBe(array_sum($grafica));
});

it('una unidad cuyo almacén de procedencia ya no abastece a la empresa sigue contando, igual que en el listado', function () {
    $otroAlmacen = Almacen::factory()->paraEmpresa($this->inmag)->create();
    $equipo = Activo::factory()->for($this->dasti)->seguimientoIndividual()->create();
    ($this->unidad)($equipo, $this->dasti, $otroAlmacen, EstadoUnidadActivo::Asignada, CondicionUnidadActivo::Funcionando);

    expect(($this->grafica)(['empresa_id' => $this->dasti->id])['asignado'])->toBe(2)
        ->and(($this->totalListado)(['empresa_id' => $this->dasti->id, 'estado_visible' => 'asignado']))->toBe(2);
});

it('respeta el alcance del usuario: una empresa no autorizada no se cuenta ni forzando el filtro', function () {
    $soloDasti = usuarioCon(RolSistema::Administrador->value, [$this->dasti]);
    $soloDasti->syncRoles([]);
    $soloDasti->givePermissionTo('reportes.ver');

    $serie = fn (array $q) => collect($this->actingAs($soloDasti)
        ->get('/reportes?'.http_build_query(['tab' => 'inventario', ...$q]))
        ->assertOk()->viewData('page')['props']['graficas']['unidades_por_estado'])->sum('total');

    // Forzar una empresa ajena nunca filtra sus unidades: queda vacío,
    // igual que el resto de consultas del reporte.
    expect($serie([]))->toBe(4)
        ->and($serie(['empresa_id' => $this->inmag->id]))->toBe(0);
});

it('el filtro de almacén acota la gráfica', function () {
    expect(array_sum(($this->grafica)(['almacen_id' => $this->almacenInmag->id])))->toBe(6);
});

it('se resuelve con una sola consulta agrupada, sin una por unidad', function () {
    $consultas = 0;
    DB::listen(function ($q) use (&$consultas): void {
        if (str_contains($q->sql, 'from "unidades_activo"') || str_contains($q->sql, 'from `unidades_activo`')) {
            $consultas++;
        }
    });

    ($this->grafica)(['empresa_id' => $this->dasti->id]);

    expect($consultas)->toBe(1);
});

it('los KPIs de unidades del reporte (fuente compartida con Excel/PDF) usan el mismo alcance', function () {
    Pdf::fake();

    $this->actingAs($this->admin)->get('/reportes?'.http_build_query(['tab' => 'inventario', 'empresa_id' => $this->dasti->id]))
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('kpis.Unidades disponibles', 2)
            ->where('kpis.Unidades asignadas', 1)
            ->etc());
    $this->actingAs($this->admin)->get('/reportes/inventario/exportar?'.http_build_query(['empresa_id' => $this->dasti->id, 'formato' => 'pdf']))->assertOk();
    Pdf::assertSee('Unidades por estado');
});
