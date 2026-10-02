<?php

use App\Acciones\CrearRondaInventarioFisico;
use App\Enums\DireccionMovimiento;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Contrato;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\Servicio;
use App\Models\Sucursal;
use App\Models\UnidadActivo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El Dashboard se arma por PERMISOS EFECTIVOS (Spatie), nunca por nombre de
 * rol: cada sección sólo se consulta y se envía si el usuario puede abrir el
 * módulo destino de sus cards. Los "roles reales" se reproducen por su
 * combinación de permisos (PDF de roles y permisos del 28/09/2026), con un
 * rol personalizado de nombre arbitrario, justamente para demostrar que el
 * nombre no importa.
 */

/**
 * @var array<string, list<string>>
 */
const CLAVES_POR_SECCION_DASHBOARD = [
    'colaboradores' => ['kpis.colaboradores_activos', 'kpis.colaboradores_sin_servicio'],
    'activos' => ['kpis.activos_activos'],
    'inventario' => [
        'kpis.existencias_disponibles', 'kpis.activos_stock_bajo',
        'series.movimientos_por_periodo', 'series.existencias_por_almacen', 'series.stock_por_categoria',
        'stock_bajo_detalle',
    ],
    'entregas' => ['kpis.entregas_periodo', 'series.entregas_por_periodo', 'entregas_recientes'],
    'devoluciones' => ['kpis.devoluciones_periodo', 'series.devoluciones_por_periodo'],
    'almacenes' => ['kpis.almacenes_activos'],
    'unidades' => [
        'kpis.unidades_disponibles', 'kpis.unidades_asignadas', 'kpis.unidades_en_reparacion',
        'kpis.unidades_perdidas', 'kpis.unidades_robadas', 'series.unidades_por_estado',
    ],
    'inventario_fisico' => ['kpis.rondas_inventario_fisico_en_proceso'],
    'empresas' => ['kpis.empresas_activas'],
    'sucursales' => ['kpis.sucursales_activas'],
    'contratos' => ['kpis.contratos_activos'],
    'servicios' => ['kpis.servicios_activos'],
];

/**
 * Tablas que consulta cada sección — para demostrar que una sección no
 * autorizada ni siquiera se CONSULTA (no sólo que no se envía).
 *
 * @var array<string, list<string>>
 */
const TABLAS_POR_SECCION_DASHBOARD = [
    'inventario' => ['saldos_inventario', 'movimientos_inventario'],
    'entregas' => ['entregas_uniformes'],
    'devoluciones' => ['devoluciones'],
    'unidades' => ['unidades_activo'],
    'inventario_fisico' => ['inventarios_fisicos'],
    'contratos' => ['contratos'],
    'servicios' => ['servicios'],
];

/**
 * @return array{empresa: Empresa, almacen: Almacen, sucursal: Sucursal}
 */
function escenarioDashboardPermisos(): array
{
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create(['activo' => true]);
    $registra = User::factory()->create();

    $activo = Activo::factory()->for($empresa)->create();
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 7, 'minimo' => 10]);
    MovimientoInventario::factory()->for($empresa)->for($almacen)->create([
        'direccion' => DireccionMovimiento::Entrada, 'cantidad' => 3, 'ocurrido_en' => now(),
    ]);

    $individual = Activo::factory()->for($empresa)->seguimientoIndividual()->create();
    UnidadActivo::factory()->for($empresa)->for($individual)->for($almacen)->create();

    EntregaUniforme::factory()->for($empresa)->for($sucursal)->create([
        'almacen_id' => $almacen->id, 'colaborador_id' => $colaborador->id,
        'encargado_id' => $registra->id, 'fecha_entrega' => now()->toDateString(),
    ]);
    Devolucion::factory()->for($empresa)->for($sucursal)->create([
        'almacen_id' => $almacen->id, 'colaborador_id' => $colaborador->id,
        'registrada_por' => $registra->id, 'fecha' => now()->toDateString(),
    ]);

    // Estructura: 1 contrato activo + 1 inactivo, 1 servicio activo + 1
    // inactivo. El colaborador de arriba no tiene servicio asignado.
    $contrato = Contrato::factory()->for($empresa)->create(['activo' => true]);
    Contrato::factory()->for($empresa)->create(['activo' => false]);
    Servicio::factory()->for($contrato)->for($sucursal)->create(['activo' => true]);
    Servicio::factory()->for($contrato)->for($sucursal)->create(['activo' => false]);

    return compact('empresa', 'almacen', 'sucursal');
}

/**
 * Usuario con un rol PERSONALIZADO (nombre aleatorio, no base) que tiene
 * exactamente los permisos dados.
 *
 * @param  list<string>  $permisos
 * @param  array<int, Empresa>  $empresas
 */
function usuarioConPermisos(array $permisos, array $empresas): User
{
    $rol = Role::create(['name' => 'personalizado-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
    $rol->syncPermissions($permisos);

    $usuario = User::factory()->create();
    $usuario->assignRole($rol);
    $usuario->empresas()->sync(collect($empresas)->pluck('id'));

    return $usuario;
}

/**
 * @return array<string, mixed>
 */
function resumenDelDashboard(TestCase $test, User $usuario, string $query = ''): array
{
    $resumen = null;
    $test->actingAs($usuario)->get("/dashboard{$query}")
        ->assertOk()
        ->assertInertia(function ($page) use (&$resumen): void {
            $resumen = $page->toArray()['props']['resumen'];
        });

    return $resumen;
}

/**
 * @param  array<string, mixed>  $resumen
 * @param  list<string>  $esperadas
 */
function assertSeccionesDashboard(array $resumen, array $esperadas): void
{
    expect($resumen['secciones'])->toEqualCanonicalizing($esperadas);

    foreach (CLAVES_POR_SECCION_DASHBOARD as $seccion => $claves) {
        foreach ($claves as $clave) {
            $mensaje = "La clave {$clave} (sección {$seccion}) no respeta los permisos efectivos.";

            if (in_array($seccion, $esperadas, true)) {
                expect(data_get($resumen, $clave))->not->toBeNull($mensaje);
            } else {
                expect(data_get($resumen, $clave))->toBeNull($mensaje);
            }
        }
    }
}

beforeEach(function () {
    sembrarRolesPermisos();
    ['empresa' => $this->empresa, 'almacen' => $this->almacen, 'sucursal' => $this->sucursal] = escenarioDashboardPermisos();
});

it('con sólo colaboradores.ver recibe colaboradores y nada de inventario, entregas, devoluciones, unidades ni almacenes', function () {
    $usuario = usuarioConPermisos(['colaboradores.ver'], [$this->empresa]);

    $resumen = resumenDelDashboard($this, $usuario);

    assertSeccionesDashboard($resumen, ['colaboradores']);
    expect($resumen['kpis']['colaboradores_activos'])->toBe(1);
});

it('una sección no autorizada ni siquiera se consulta en la base de datos', function () {
    $usuario = usuarioConPermisos(['colaboradores.ver'], [$this->empresa]);
    $this->actingAs($usuario);

    DB::enableQueryLog();
    $this->get('/dashboard')->assertOk();
    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");

    // Control positivo: la sección autorizada sí se consultó.
    expect($sql)->toContain('"colaboradores"');

    foreach (TABLAS_POR_SECCION_DASHBOARD as $tablas) {
        foreach ($tablas as $tabla) {
            expect($sql)->not->toContain("\"{$tabla}\"");
        }
    }
});

it('inventario.ajustar por sí solo no da métricas de inventario (caso Inspector)', function () {
    // Permisos exactos del rol "Inspector" del PDF.
    $inspector = usuarioConPermisos([
        'activos.ver', 'activos.administrar', 'inventario.ajustar',
        'unidades-activo.ver', 'unidades-activo.administrar',
    ], [$this->empresa]);

    $resumen = resumenDelDashboard($this, $inspector);

    assertSeccionesDashboard($resumen, ['activos', 'unidades']);
    expect($resumen['kpis'])->not->toHaveKey('existencias_disponibles');
});

it('agregar inventario.ver en runtime hace aparecer el bloque de inventario sin cambiar de rol', function () {
    $usuario = usuarioConPermisos(['inventario.ajustar'], [$this->empresa]);

    assertSeccionesDashboard(resumenDelDashboard($this, $usuario), []);

    $usuario->givePermissionTo('inventario.ver');

    $resumen = resumenDelDashboard($this, $usuario->fresh());
    assertSeccionesDashboard($resumen, ['inventario']);
    expect($resumen['kpis']['existencias_disponibles'])->toBe(7);

    $usuario->revokePermissionTo('inventario.ver');
    assertSeccionesDashboard(resumenDelDashboard($this, $usuario->fresh()), []);
});

it('permisos relacionados no implican inventario.ver', function (string $permiso, string $seccion) {
    $usuario = usuarioConPermisos([$permiso], [$this->empresa]);

    assertSeccionesDashboard(resumenDelDashboard($this, $usuario), [$seccion]);
})->with([
    'inventario-fisico.ver' => ['inventario-fisico.ver', 'inventario_fisico'],
    'activos.ver' => ['activos.ver', 'activos'],
    'almacenes.ver' => ['almacenes.ver', 'almacenes'],
    'unidades-activo.ver' => ['unidades-activo.ver', 'unidades'],
]);

it('cada card visible apunta a un módulo que el mismo usuario sí puede abrir', function (string $permiso, string $clave, string $destino) {
    $usuario = usuarioConPermisos([$permiso], [$this->empresa]);

    expect(data_get(resumenDelDashboard($this, $usuario), $clave))->not->toBeNull();

    $this->actingAs($usuario)->get($destino)->assertOk();
})->with([
    'colaboradores' => ['colaboradores.ver', 'kpis.colaboradores_activos', '/colaboradores?estado=activos'],
    'activos' => ['activos.ver', 'kpis.activos_activos', '/activos?estado=activos'],
    'inventario' => ['inventario.ver', 'kpis.existencias_disponibles', '/inventario'],
    'stock bajo' => ['inventario.ver', 'kpis.activos_stock_bajo', '/inventario?estado_stock=bajo_minimo'],
    'entregas' => ['entregas.ver', 'kpis.entregas_periodo', '/entregas'],
    'devoluciones' => ['devoluciones.ver', 'kpis.devoluciones_periodo', '/devoluciones'],
    'almacenes' => ['almacenes.ver', 'kpis.almacenes_activos', '/almacenes?estado=activos'],
    'unidades' => ['unidades-activo.ver', 'kpis.unidades_disponibles', '/activos/unidades?estado_visible=disponible'],
    'inventario físico' => ['inventario-fisico.ver', 'kpis.rondas_inventario_fisico_en_proceso', '/inventarios-fisicos?estado=en_proceso'],
]);

it('sin la card, el acceso manual al módulo sigue respondiendo 403', function () {
    $usuario = usuarioConPermisos(['colaboradores.ver'], [$this->empresa]);

    $this->actingAs($usuario)->get('/entregas')->assertForbidden();
    $this->actingAs($usuario)->get('/devoluciones')->assertForbidden();
    $this->actingAs($usuario)->get('/inventario')->assertForbidden();
});

it('un usuario sin ninguna sección autorizada recibe un dashboard válido y vacío (rol Colaborador)', function () {
    $colaborador = usuarioCon(RolSistema::Colaborador->value, [$this->empresa]);

    $this->actingAs($colaborador)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Panel')
            ->where('resumen.secciones', [])
            ->where('resumen.kpis', [])
            ->where('resumen.series', [])
            ->missing('resumen.entregas_recientes')
            ->missing('resumen.stock_bajo_detalle')
            ->where('filtrosDisponibles', ['sucursal' => false, 'almacen' => false, 'fechas' => false]));
});

it('el superadministrador recibe todas las secciones', function () {
    $superadmin = usuarioCon(RolSistema::Superadministrador->value);

    assertSeccionesDashboard(resumenDelDashboard($this, $superadmin), array_keys(CLAVES_POR_SECCION_DASHBOARD));
});

it('el administrador recibe las secciones de sus permisos reales', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);

    assertSeccionesDashboard(resumenDelDashboard($this, $admin), array_keys(CLAVES_POR_SECCION_DASHBOARD));

    // Sin tocar código: quitarle un permiso al rol retira su sección.
    Role::findByName(RolSistema::Administrador->value)->revokePermissionTo('entregas.ver');
    $secciones = resumenDelDashboard($this, $admin->fresh())['secciones'];
    expect($secciones)->not->toContain('entregas')->toContain('devoluciones');
});

it('reproduce los roles reales por su combinación de permisos', function (array $permisos, array $secciones) {
    $usuario = usuarioConPermisos($permisos, [$this->empresa]);

    assertSeccionesDashboard(resumenDelDashboard($this, $usuario), $secciones);
})->with([
    'Auxiliar De Rrhh' => [[
        'colaboradores.ver', 'colaboradores.crear', 'colaboradores.editar', 'colaboradores.cambiar-empresa',
        'colaboradores.desactivar', 'colaboradores.importar', 'colaboradores.expediente-ver',
        'colaboradores.expediente-administrar', 'colaboradores.expediente-descargar',
        'activos.ver', 'inventario-fisico.ver', 'inventario-fisico.administrar',
    ], ['colaboradores', 'activos', 'inventario_fisico']],
    'Auxiliar Encargado Almacén' => [[
        'almacenes.ver', 'almacenes.crear', 'almacenes.editar', 'almacenes.administrar',
        'activos.ver', 'activos.crear', 'activos.editar', 'activos.administrar',
        'tallas.administrar', 'tipos-activo.administrar', 'categorias-activo.administrar',
        'inventario.ver', 'inventario.entrada', 'inventario.ajustar', 'inventario.minimos', 'inventario.transferir',
        'unidades-activo.ver', 'unidades-activo.administrar',
        'conjuntos.ver', 'conjuntos.crear', 'conjuntos.editar', 'conjuntos.administrar',
        'inventario-fisico.ver', 'inventario-fisico.administrar',
    ], ['almacenes', 'activos', 'inventario', 'unidades', 'inventario_fisico']],
    'Encargado Rrhh' => [[
        'empresas.ver', 'sucursales.ver',
        'contratos.ver', 'contratos.crear', 'contratos.editar', 'contratos.administrar',
        'servicios.ver', 'servicios.crear', 'servicios.editar', 'servicios.administrar',
        'colaboradores.ver', 'colaboradores.crear', 'colaboradores.editar', 'colaboradores.cambiar-empresa',
        'colaboradores.desactivar', 'colaboradores.importar', 'colaboradores.expediente-ver',
        'colaboradores.expediente-administrar', 'colaboradores.expediente-descargar',
        'areas.ver', 'areas.crear', 'areas.editar', 'areas.desactivar',
    ], ['colaboradores', 'empresas', 'sucursales', 'contratos', 'servicios']],
    'Inspector' => [[
        'activos.ver', 'activos.administrar', 'inventario.ajustar',
        'unidades-activo.ver', 'unidades-activo.administrar',
    ], ['activos', 'unidades']],
    'Supervisor (PDF)' => [[
        'empresas.ver', 'sucursales.ver',
        'contratos.ver', 'contratos.crear', 'contratos.editar',
        'servicios.ver', 'servicios.crear', 'servicios.editar',
        'colaboradores.ver', 'colaboradores.crear', 'colaboradores.editar', 'colaboradores.importar',
        'colaboradores.expediente-ver', 'colaboradores.expediente-administrar', 'colaboradores.expediente-descargar',
        'areas.ver', 'areas.crear', 'areas.editar',
        'almacenes.ver', 'activos.ver',
        'inventario.ver', 'inventario.entrada', 'inventario.minimos',
        'unidades-activo.ver', 'unidades-activo.administrar',
        'conjuntos.ver', 'conjuntos.crear', 'conjuntos.editar',
        'inventario-fisico.ver', 'inventario-fisico.administrar',
        'entregas.ver', 'entregas.crear',
        'acuses.ver', 'acuses.firmar', 'acuses.ver-pdf', 'acuses.ver-firma',
        'devoluciones.ver', 'devoluciones.crear', 'devoluciones.confirmar', 'devoluciones.ver-pdf', 'devoluciones.ver-firma',
    ], ['colaboradores', 'activos', 'inventario', 'entregas', 'devoluciones', 'almacenes', 'unidades', 'inventario_fisico', 'empresas', 'sucursales', 'contratos', 'servicios']],
]);

it('los filtros visibles dependen de las secciones y del acceso a sus buscadores', function () {
    // Colaboradores sin `sucursales.ver`: no puede usar el buscador de sucursales.
    $rrhh = usuarioConPermisos(['colaboradores.ver'], [$this->empresa]);
    $this->actingAs($rrhh)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('filtrosDisponibles', ['sucursal' => false, 'almacen' => false, 'fechas' => false]));

    $almacenista = usuarioConPermisos(['inventario.ver', 'almacenes.ver'], [$this->empresa]);
    $this->actingAs($almacenista)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('filtrosDisponibles', ['sucursal' => false, 'almacen' => true, 'fechas' => true]));
});

it('un filtro no disponible se ignora aunque venga en el query string', function () {
    $otroAlmacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $inspector = usuarioConPermisos(['unidades-activo.ver'], [$this->empresa]);

    $this->actingAs($inspector)
        ->get("/dashboard?almacen_id={$otroAlmacen->id}")
        ->assertInertia(fn ($page) => $page
            ->where('filtros.almacen_id', null)
            ->where('resumen.kpis.unidades_disponibles', 1));
});

it('respeta el alcance por empresa: nunca agrega datos de una empresa no autorizada', function () {
    ['empresa' => $ajena] = escenarioDashboardPermisos();
    $usuario = usuarioConPermisos(['inventario.ver', 'colaboradores.ver'], [$this->empresa]);

    $resumen = resumenDelDashboard($this, $usuario, "?empresa_id={$ajena->id}");

    expect($resumen['kpis']['existencias_disponibles'])->toBe(7)
        ->and($resumen['kpis']['colaboradores_activos'])->toBe(1);
});

it('respeta el alcance por sucursal: filtra por una sucursal autorizada e ignora una ajena', function () {
    $otraSucursal = Sucursal::factory()->for($this->empresa)->create();
    Colaborador::factory()->for($this->empresa)->for($otraSucursal)->count(2)->create(['activo' => true]);
    ['sucursal' => $sucursalAjena] = escenarioDashboardPermisos();
    $usuario = usuarioConPermisos(['colaboradores.ver', 'sucursales.ver'], [$this->empresa]);

    expect(resumenDelDashboard($this, $usuario, "?sucursal_id={$otraSucursal->id}")['kpis']['colaboradores_activos'])->toBe(2)
        ->and(resumenDelDashboard($this, $usuario, "?sucursal_id={$sucursalAjena->id}")['kpis']['colaboradores_activos'])->toBe(3);
});

it('respeta el alcance por almacén: filtra por un almacén autorizado e ignora uno ajeno', function () {
    $otroAlmacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    SaldoInventario::factory()->for($this->empresa)->for($otroAlmacen)->create(['cantidad' => 100, 'minimo' => 0]);
    ['almacen' => $almacenAjeno] = escenarioDashboardPermisos();
    $usuario = usuarioConPermisos(['inventario.ver', 'almacenes.ver'], [$this->empresa]);

    expect(resumenDelDashboard($this, $usuario, "?almacen_id={$this->almacen->id}")['kpis']['existencias_disponibles'])->toBe(7)
        ->and(resumenDelDashboard($this, $usuario, "?almacen_id={$almacenAjeno->id}")['kpis']['existencias_disponibles'])->toBe(107);
});

it('la card de rondas cuenta sólo las rondas en proceso de las empresas autorizadas', function () {
    $usuario = usuarioConPermisos(['inventario-fisico.ver'], [$this->empresa]);
    app(CrearRondaInventarioFisico::class)->ejecutar($this->empresa, 'Ronda', null, $usuario->id);
    ['empresa' => $ajena, 'almacen' => $almacenAjeno] = escenarioDashboardPermisos();
    app(CrearRondaInventarioFisico::class)->ejecutar($ajena, 'Ajena', null, $usuario->id);

    expect(resumenDelDashboard($this, $usuario)['kpis']['rondas_inventario_fisico_en_proceso'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Estructura de la organización (perfiles administrativos)
|--------------------------------------------------------------------------
*/

const PERMISOS_ESTRUCTURA = ['colaboradores.ver', 'empresas.ver', 'sucursales.ver', 'contratos.ver', 'servicios.ver'];

it('con permisos de estructura recibe empresas, sucursales, contratos, servicios y colaboradores sin servicio', function () {
    $resumen = resumenDelDashboard($this, usuarioConPermisos(PERMISOS_ESTRUCTURA, [$this->empresa]));

    assertSeccionesDashboard($resumen, ['colaboradores', 'empresas', 'sucursales', 'contratos', 'servicios']);
    expect($resumen['kpis'])->toMatchArray([
        'colaboradores_activos' => 1,
        'colaboradores_sin_servicio' => 1,
        'empresas_activas' => 1,
        'sucursales_activas' => 1,
        'contratos_activos' => 1,
        'servicios_activos' => 1,
    ]);
});

it('quitar contratos.ver retira sólo lo de contratos, sin cambiar de rol, y ya no consulta la tabla', function () {
    $usuario = usuarioConPermisos(PERMISOS_ESTRUCTURA, [$this->empresa]);
    $usuario->roles()->first()->revokePermissionTo('contratos.ver');
    $usuario = $usuario->fresh();

    $this->actingAs($usuario);
    DB::enableQueryLog();
    $resumen = resumenDelDashboard($this, $usuario);
    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");

    assertSeccionesDashboard($resumen, ['colaboradores', 'empresas', 'sucursales', 'servicios']);
    expect($sql)->not->toContain('from "contratos" where "empresa_id"');
});

it('dos roles distintos con los mismos permisos efectivos reciben el mismo dashboard', function () {
    $a = resumenDelDashboard($this, usuarioConPermisos(PERMISOS_ESTRUCTURA, [$this->empresa]));
    $b = resumenDelDashboard($this, usuarioConPermisos(PERMISOS_ESTRUCTURA, [$this->empresa]));

    expect($b)->toEqual($a);
});

it('el filtro de empresa acota las métricas de estructura y nunca suma una empresa no autorizada', function () {
    ['empresa' => $otra] = escenarioDashboardPermisos();
    ['empresa' => $ajena] = escenarioDashboardPermisos();
    $usuario = usuarioConPermisos(PERMISOS_ESTRUCTURA, [$this->empresa, $otra]);

    $todas = resumenDelDashboard($this, $usuario)['kpis'];
    $una = resumenDelDashboard($this, $usuario, "?empresa_id={$otra->id}")['kpis'];
    $forzada = resumenDelDashboard($this, $usuario, "?empresa_id={$ajena->id}")['kpis'];

    expect($todas)->toMatchArray(['empresas_activas' => 2, 'contratos_activos' => 2, 'servicios_activos' => 2, 'sucursales_activas' => 2])
        ->and($una)->toMatchArray(['empresas_activas' => 1, 'contratos_activos' => 1, 'servicios_activos' => 1, 'sucursales_activas' => 1])
        ->and($forzada)->toMatchArray($todas);
});

it('el filtro de sucursal acota servicios y colaboradores sin servicio', function () {
    $otraSucursal = Sucursal::factory()->for($this->empresa)->create();
    Colaborador::factory()->for($this->empresa)->for($otraSucursal)->count(2)->create(['activo' => true, 'servicio_actual_id' => null]);
    Servicio::factory()->for(Contrato::factory()->for($this->empresa))->for($otraSucursal)->count(3)->create(['activo' => true]);
    $usuario = usuarioConPermisos(PERMISOS_ESTRUCTURA, [$this->empresa]);

    $kpis = resumenDelDashboard($this, $usuario, "?sucursal_id={$otraSucursal->id}")['kpis'];

    expect($kpis['servicios_activos'])->toBe(3)
        ->and($kpis['colaboradores_sin_servicio'])->toBe(2);
});

it('un perfil restringido a una sucursal sólo cuenta sus sucursales y servicios', function () {
    $otraSucursal = Sucursal::factory()->for($this->empresa)->create();
    Servicio::factory()->for(Contrato::factory()->for($this->empresa))->for($otraSucursal)->count(3)->create(['activo' => true]);
    $usuario = usuarioConPermisos(PERMISOS_ESTRUCTURA, [$this->empresa]);
    $usuario->sucursales()->sync([$this->sucursal->id]);

    $kpis = resumenDelDashboard($this, $usuario->fresh())['kpis'];

    expect($kpis['sucursales_activas'])->toBe(1)
        ->and($kpis['servicios_activos'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Colaboradores: alcance de sucursales autorizadas
|--------------------------------------------------------------------------
| Ambos KPIs de colaboradores ("activos" y "sin servicio") contaban personal
| de sucursales NO autorizadas de una empresa autorizada cuando no había una
| sucursal elegida. Ahora usan el mismo `$sucursalesAlcance` que Sucursales
| y Servicios.
*/

describe('colaboradores por sucursal autorizada', function () {
    beforeEach(function () {
        $this->sucursalA = Sucursal::factory()->for($this->empresa)->create();
        $this->sucursalB = Sucursal::factory()->for($this->empresa)->create();
        Colaborador::factory()->for($this->empresa)->for($this->sucursalA)->count(10)->create(['activo' => true, 'servicio_actual_id' => null]);
        Colaborador::factory()->for($this->empresa)->for($this->sucursalB)->count(20)->create(['activo' => true, 'servicio_actual_id' => null]);

        $this->restringido = function (array $sucursales): User {
            $usuario = usuarioConPermisos(['colaboradores.ver', 'sucursales.ver'], [$this->empresa]);
            $usuario->sucursales()->sync(collect($sucursales)->pluck('id'));

            return $usuario->fresh();
        };
    });

    it('A: autorizado sólo para A, sin filtro → cuenta 10, no 30', function () {
        $kpis = resumenDelDashboard($this, ($this->restringido)([$this->sucursalA]))['kpis'];

        expect($kpis['colaboradores_activos'])->toBe(10)
            ->and($kpis['colaboradores_sin_servicio'])->toBe(10);
    });

    it('B: autorizado sólo para A, eligiendo A → 10', function () {
        $kpis = resumenDelDashboard($this, ($this->restringido)([$this->sucursalA]), "?sucursal_id={$this->sucursalA->id}")['kpis'];

        expect($kpis['colaboradores_activos'])->toBe(10)
            ->and($kpis['colaboradores_sin_servicio'])->toBe(10);
    });

    it('C: autorizado para A y B, sin filtro → 30', function () {
        $kpis = resumenDelDashboard($this, ($this->restringido)([$this->sucursalA, $this->sucursalB]))['kpis'];

        expect($kpis['colaboradores_activos'])->toBe(30)
            ->and($kpis['colaboradores_sin_servicio'])->toBe(30);
    });

    it('D: forzar por URL una sucursal no autorizada no cuenta a su personal', function () {
        $kpis = resumenDelDashboard($this, ($this->restringido)([$this->sucursalA]), "?sucursal_id={$this->sucursalB->id}")['kpis'];

        expect($kpis['colaboradores_activos'])->toBe(10)
            ->and($kpis['colaboradores_sin_servicio'])->toBe(10);
    });

    it('E: "activos" y "sin servicio" comparten el mismo alcance (sin servicio ⊆ activos en cada escenario)', function () {
        // 4 de A ya tienen servicio: dejan de contar como "sin servicio" pero
        // siguen siendo activos; los de B nunca entran para quien sólo ve A.
        $servicio = Servicio::factory()->for(Contrato::factory()->for($this->empresa))->for($this->sucursalA)->create();
        Colaborador::query()->where('sucursal_id', $this->sucursalA->id)->limit(4)->get()
            ->each(fn (Colaborador $c) => $c->update(['servicio_actual_id' => $servicio->id]));

        foreach ([[$this->sucursalA], [$this->sucursalA, $this->sucursalB]] as $i => $sucursales) {
            $kpis = resumenDelDashboard($this, ($this->restringido)($sucursales))['kpis'];
            [$activos, $sinServicio] = $i === 0 ? [10, 6] : [30, 26];

            expect($kpis['colaboradores_activos'])->toBe($activos)
                ->and($kpis['colaboradores_sin_servicio'])->toBe($sinServicio);
        }
    });
});
