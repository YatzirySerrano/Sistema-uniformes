<?php

use App\Enums\CondicionUnidadActivo;
use App\Enums\DireccionMovimiento;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use App\Models\UnidadActivo;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

/**
 * Arma un escenario con datos conocidos para una empresa: colaboradores,
 * activos, existencias, unidades en cada estado visible y una entrega +
 * devolución del día. Devuelve las referencias para las aserciones.
 */
function escenarioDashboard(): array
{
    sembrarRolesPermisos();

    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();

    $colaboradores = Colaborador::factory()->for($empresa)->for($sucursal)->count(2)->create(['activo' => true]);
    Colaborador::factory()->for($empresa)->for($sucursal)->create(['activo' => false]);
    $colaborador = $colaboradores->first();
    $encargado = User::factory()->create();

    $activo = Activo::factory()->for($empresa)->create(['activo' => true]);
    Activo::factory()->for($empresa)->create(['activo' => false]);

    // Bajo mínimo (2 <= 10) y saludable (50 > 5): sólo el primero cuenta.
    // `activo_id` explícito: el default de `SaldoInventarioFactory` es
    // `Activo::factory()`, que a su vez crea su PROPIA `Empresa::factory()`
    // si no se le indica — mismo riesgo de empresas "fantasma" que en
    // `colaborador_id` (ver nota más abajo).
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 2, 'minimo' => 10]);
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 50, 'minimo' => 5]);

    $activoIndividual = Activo::factory()->for($empresa)->seguimientoIndividual()->create();
    UnidadActivo::factory()->for($empresa)->for($activoIndividual)->for($almacen)->create(); // disponible
    UnidadActivo::factory()->for($empresa)->for($activoIndividual)->for($almacen)->asignada()->create(); // asignada
    UnidadActivo::factory()->for($empresa)->for($activoIndividual)->for($almacen)
        ->conCondicion(CondicionUnidadActivo::EnReparacion)->create(); // reparación
    UnidadActivo::factory()->for($empresa)->for($activoIndividual)->for($almacen)
        ->asignada()->conCondicion(CondicionUnidadActivo::Perdido)->create(); // perdida
    UnidadActivo::factory()->for($empresa)->for($activoIndividual)->for($almacen)
        ->asignada()->conCondicion(CondicionUnidadActivo::Robado)->create(); // robada

    // `colaborador_id`/`encargado_id`/`registrada_por` se pasan explícitos:
    // los defaults de estos factories son `Colaborador::factory()` /
    // `User::factory()`, y `ColaboradorFactory` a su vez crea su PROPIA
    // `Empresa::factory()` si no se le indica — dejarlo implícito sembraría
    // empresas "fantasma" invisibles para los tests que filtran por una sola
    // empresa, pero que sí contaminan la agregación multiempresa del
    // Administrador (alcance global = todas las empresas de la plataforma).
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

it('calcula los KPIs, series y alertas del dashboard para la empresa filtrada', function () {
    ['empresa' => $empresa] = escenarioDashboard();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)
        ->get("/dashboard?empresa_id={$empresa->id}")
        ->assertInertia(fn ($page) => $page
            ->component('Panel')
            ->where('resumen.kpis.colaboradores_activos', 2)
            // El activo por cantidad activo + el activo de seguimiento individual (también activo).
            ->where('resumen.kpis.activos_activos', 2)
            ->where('resumen.kpis.existencias_disponibles', 52)
            ->where('resumen.kpis.activos_stock_bajo', 1)
            ->where('resumen.kpis.almacenes_activos', 1)
            ->where('resumen.kpis.unidades_disponibles', 1)
            ->where('resumen.kpis.unidades_asignadas', 1)
            ->where('resumen.kpis.unidades_en_reparacion', 1)
            ->where('resumen.kpis.unidades_perdidas', 1)
            ->where('resumen.kpis.unidades_robadas', 1)
            ->where('resumen.kpis.entregas_periodo', 1)
            ->where('resumen.kpis.devoluciones_periodo', 1)
            // 30 días (por defecto): hoy - 29 días hasta hoy.
            ->has('resumen.series.entregas_por_periodo', 30)
            ->has('resumen.series.devoluciones_por_periodo', 30)
            ->has('resumen.series.movimientos_por_periodo', 30)
            // Las 6 categorías de EstadoVisibleUnidad, en el mismo orden.
            ->has('resumen.series.unidades_por_estado', 6)
            ->where('resumen.series.unidades_por_estado.0.estado', 'disponible')
            ->where('resumen.series.unidades_por_estado.0.total', 1)
            ->where('resumen.series.unidades_por_estado.1.estado', 'asignado')
            ->where('resumen.series.unidades_por_estado.1.total', 1)
            ->where('resumen.series.unidades_por_estado.2.estado', 'reparacion')
            ->where('resumen.series.unidades_por_estado.2.total', 1)
            ->where('resumen.series.unidades_por_estado.3.estado', 'perdido')
            ->where('resumen.series.unidades_por_estado.3.total', 1)
            ->where('resumen.series.unidades_por_estado.4.estado', 'robado')
            ->where('resumen.series.unidades_por_estado.4.total', 1)
            ->where('resumen.series.unidades_por_estado.5.estado', 'baja')
            ->where('resumen.series.unidades_por_estado.5.total', 0)
            ->has('resumen.stock_bajo_detalle', 1)
            ->has('resumen.entregas_recientes', 1),
        );
});

it('el rango de fechas del dashboard excluye entregas y devoluciones fuera del periodo', function () {
    ['empresa' => $empresa, 'almacen' => $almacen, 'sucursal' => $sucursal] = escenarioDashboard();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    // Entrega/devolución adicionales, muy fuera del rango que vamos a pedir.
    EntregaUniforme::factory()->for($empresa)->for($sucursal)->create([
        'almacen_id' => $almacen->id,
        'fecha_entrega' => now()->subDays(90)->toDateString(),
    ]);
    Devolucion::factory()->for($empresa)->for($sucursal)->create([
        'almacen_id' => $almacen->id,
        'fecha' => now()->subDays(90)->toDateString(),
    ]);

    $desde = now()->subDays(3)->toDateString();
    $hasta = now()->toDateString();

    $this->actingAs($admin)
        ->get("/dashboard?empresa_id={$empresa->id}&desde={$desde}&hasta={$hasta}")
        ->assertInertia(fn ($page) => $page
            ->where('filtros.desde', $desde)
            ->where('filtros.hasta', $hasta)
            ->where('resumen.kpis.entregas_periodo', 1)
            ->where('resumen.kpis.devoluciones_periodo', 1)
            ->has('resumen.series.entregas_por_periodo', 4),
        );
});

it('el filtro de almacén acota existencias y unidades sin afectar a otros almacenes de la misma empresa', function () {
    ['empresa' => $empresa, 'almacen' => $almacenA] = escenarioDashboard();
    $almacenB = Almacen::factory()->paraEmpresa($empresa)->create();
    SaldoInventario::factory()->for($empresa)->for($almacenB)->create(['cantidad' => 1000, 'minimo' => 0]);
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)
        ->get("/dashboard?empresa_id={$empresa->id}&almacen_id={$almacenA->id}")
        ->assertInertia(fn ($page) => $page
            ->where('filtros.almacen_id', $almacenA->id)
            ->where('resumen.kpis.existencias_disponibles', 52),
        );
});

it('ignora silenciosamente un almacén que no abastece a la empresa filtrada (IDOR)', function () {
    ['empresa' => $empresa] = escenarioDashboard();
    $otraEmpresa = Empresa::factory()->create();
    $almacenAjeno = Almacen::factory()->paraEmpresa($otraEmpresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)
        ->get("/dashboard?empresa_id={$empresa->id}&almacen_id={$almacenAjeno->id}")
        ->assertInertia(fn ($page) => $page
            ->where('filtros.almacen_id', null)
            ->where('resumen.kpis.existencias_disponibles', 52),
        );
});

it('un rol restringido nunca ve los datos de una empresa fuera de su alcance, aunque la pida por query string', function () {
    ['empresa' => $empresaPropia] = escenarioDashboard();
    ['empresa' => $empresaAjena] = escenarioDashboard();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresaPropia]);

    $this->actingAs($supervisor)
        ->get("/dashboard?empresa_id={$empresaAjena->id}")
        ->assertInertia(fn ($page) => $page
            // empresa_id ajeno se ignora: cae a "todas las autorizadas" (no a
            // una empresa concreta), que en este caso es sólo la propia.
            ->where('filtros.empresa_id', null)
            ->where('resumen.kpis.colaboradores_activos', 2),
        );
});

it('sin filtro de empresa, el dashboard agrega todas las empresas autorizadas del usuario', function () {
    ['empresa' => $empresaA] = escenarioDashboard();
    ['empresa' => $empresaB] = escenarioDashboard();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->component('Panel')
            ->where('filtros.empresa_id', null)
            ->where('totalEmpresasIncluidas', 2)
            // 2 colaboradores activos por empresa (ver escenarioDashboard) × 2 empresas.
            ->where('resumen.kpis.colaboradores_activos', 4)
            ->where('resumen.kpis.entregas_periodo', 2)
            ->where('resumen.kpis.devoluciones_periodo', 2),
        );

    expect($empresaA->id)->not->toBe($empresaB->id);
});

it('un supervisor sin filtro sólo agrega SUS empresas autorizadas, nunca toda la plataforma', function () {
    ['empresa' => $empresaPropia] = escenarioDashboard();
    escenarioDashboard(); // otra empresa en la plataforma, ajena al supervisor.

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresaPropia]);

    $this->actingAs($supervisor)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('filtros.empresa_id', null)
            ->where('totalEmpresasIncluidas', 1)
            ->where('resumen.kpis.colaboradores_activos', 2),
        );
});

it('filtrando una empresa concreta, el dashboard sólo agrega esa empresa', function () {
    ['empresa' => $empresaA] = escenarioDashboard();
    escenarioDashboard();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get("/dashboard?empresa_id={$empresaA->id}")
        ->assertInertia(fn ($page) => $page
            ->where('filtros.empresa_id', $empresaA->id)
            ->where('totalEmpresasIncluidas', 1)
            ->where('resumen.kpis.colaboradores_activos', 2),
        );
});

/**
 * Regresión: `direccion` en `MovimientoInventario` está casteado a
 * `App\Enums\DireccionMovimiento` (enum). Usarlo directamente como clave de
 * `pluck()`/array (`$coleccion->pluck('total', 'direccion')`) provocaba
 * `TypeError: Cannot access offset of type App\Enums\DireccionMovimiento on
 * array` en cuanto existía algún movimiento de inventario en el rango del
 * Dashboard — es decir, para cualquier usuario autenticado con datos reales,
 * justo después de iniciar sesión.
 */
it('un administrador autenticado carga el dashboard sin TypeError tras el login', function () {
    ['empresa' => $empresa] = escenarioDashboard();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Panel'));
});

it('un usuario con acceso limitado a una empresa carga el dashboard sin error y sólo ve sus propios movimientos', function () {
    ['empresa' => $empresaPropia, 'almacen' => $almacenPropio] = escenarioDashboard();
    ['empresa' => $empresaAjena, 'almacen' => $almacenAjeno] = escenarioDashboard();

    MovimientoInventario::factory()->for($empresaPropia)->for($almacenPropio)->create([
        'direccion' => DireccionMovimiento::Entrada,
        'cantidad' => 4,
        'ocurrido_en' => now(),
    ]);
    MovimientoInventario::factory()->for($empresaAjena)->for($almacenAjeno)->create([
        'direccion' => DireccionMovimiento::Salida,
        'cantidad' => 9,
        'ocurrido_en' => now(),
    ]);

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresaPropia]);

    $this->actingAs($supervisor)
        ->get("/dashboard?empresa_id={$empresaPropia->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Panel')
            ->where('filtros.empresa_id', $empresaPropia->id)
            // Día de hoy = último elemento del rango de 30 días por defecto.
            ->where('resumen.series.movimientos_por_periodo.29.entradas', 4)
            ->where('resumen.series.movimientos_por_periodo.29.salidas', 0),
        );
});

it('calcula entradas y salidas reales en movimientos_por_periodo a partir de movimientos de inventario', function () {
    ['empresa' => $empresa, 'almacen' => $almacen] = escenarioDashboard();

    MovimientoInventario::factory()->for($empresa)->for($almacen)->create([
        'direccion' => DireccionMovimiento::Entrada,
        'cantidad' => 7,
        'ocurrido_en' => now(),
    ]);
    MovimientoInventario::factory()->for($empresa)->for($almacen)->create([
        'direccion' => DireccionMovimiento::Salida,
        'cantidad' => 3,
        'ocurrido_en' => now(),
    ]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)
        ->get("/dashboard?empresa_id={$empresa->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Panel')
            ->where('resumen.series.movimientos_por_periodo.29.fecha', now()->toDateString())
            ->where('resumen.series.movimientos_por_periodo.29.entradas', 7)
            ->where('resumen.series.movimientos_por_periodo.29.salidas', 3),
        );
});

/**
 * Regresión: `minimo` es `int unsigned` y `cantidad` es `int` (con
 * `saldos_inventario.cantidad <= minimo` garantizado por `scopeBajoMinimo()`).
 * `orderByRaw('cantidad - minimo asc')` delega la resta a MariaDB, que
 * promueve el resultado a BIGINT UNSIGNED por tener un operando UNSIGNED;
 * como el resultado real es negativo (p. ej. 2 - 10 = -8), MariaDB no puede
 * representarlo y lanza `SQLSTATE[22003]: Numeric value out of range: 1690`
 * — reproducido contra la BD real de desarrollo antes de este fix. La
 * expresión seleccionada — `(minimo - cantidad) DESC` — es siempre >= 0 bajo
 * ese mismo filtro, así que nunca desborda, y ordena igual (mayor faltante
 * primero).
 */
it('ordena stock_bajo_detalle por mayor faltante sin desbordar BIGINT UNSIGNED', function () {
    sembrarRolesPermisos();

    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();

    $activoA = Activo::factory()->for($empresa)->create(['nombre' => 'Activo A']);
    $activoB = Activo::factory()->for($empresa)->create(['nombre' => 'Activo B']);
    $activoC = Activo::factory()->for($empresa)->create(['nombre' => 'Activo C']);
    $activoD = Activo::factory()->for($empresa)->create(['nombre' => 'Activo D crítico']);

    // A: faltan 8. B: faltan 2. C: en el mínimo exacto, faltan 0 (sigue
    // contando por el `<=` de scopeBajoMinimo). D: cantidad 0, caso crítico.
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activoA)->create(['cantidad' => 2, 'minimo' => 10]);
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activoB)->create(['cantidad' => 8, 'minimo' => 10]);
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activoC)->create(['cantidad' => 10, 'minimo' => 10]);
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activoD)->create(['cantidad' => 0, 'minimo' => 7]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)
        ->get("/dashboard?empresa_id={$empresa->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Panel')
            ->has('resumen.stock_bajo_detalle', 4)
            // Orden por mayor faltante: A (8) > D (7) > B (2) > C (0).
            ->where('resumen.stock_bajo_detalle.0.activo', 'Activo A')
            ->where('resumen.stock_bajo_detalle.0.cantidad', 2)
            ->where('resumen.stock_bajo_detalle.0.minimo', 10)
            ->where('resumen.stock_bajo_detalle.1.activo', 'Activo D crítico')
            ->where('resumen.stock_bajo_detalle.1.cantidad', 0)
            ->where('resumen.stock_bajo_detalle.1.minimo', 7)
            ->where('resumen.stock_bajo_detalle.2.activo', 'Activo B')
            ->where('resumen.stock_bajo_detalle.2.cantidad', 8)
            ->where('resumen.stock_bajo_detalle.2.minimo', 10)
            ->where('resumen.stock_bajo_detalle.3.activo', 'Activo C')
            ->where('resumen.stock_bajo_detalle.3.cantidad', 10)
            ->where('resumen.stock_bajo_detalle.3.minimo', 10),
        );
});
