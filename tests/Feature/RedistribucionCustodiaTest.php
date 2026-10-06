<?php

use App\Acciones\RedistribuirCustodia;
use App\Acciones\RegistrarDevolucionFirmada;
use App\Enums\CondicionDevolucion;
use App\Enums\DireccionMovimiento;
use App\Enums\EstadoUnidadActivo;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\BitacoraAuditoria;
use App\Models\Colaborador;
use App\Models\EntregaUniforme;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioCustodiaColaborador;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Redistribución de custodia: un usuario con `entregas.crear` entrega desde
 * el almacén a un custodio (p. ej. un Supervisor); quien sólo tiene
 * `entregas.redistribuir` entrega a otros colaboradores ÚNICAMENTE lo que hoy
 * está bajo la custodia de su propio registro de colaborador, sin volver a
 * descontar stock del almacén.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 100,
    ));

    $this->celular = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Celular']);
    $this->unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($this->celular)->for($this->datos['almacenA'])->create();

    // Cuentas construidas SÓLO con permisos efectivos (rol personalizado),
    // nunca asumiendo lo que "debería" poder un Supervisor o un Encargado.
    $this->usuarioConPermisos = function (array $permisos): User {
        $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        return tap(User::factory()->create(), function (User $u) use ($rol): void {
            $u->assignRole($rol);
            $u->empresas()->sync([$this->datos['empresaA']->id]);
        });
    };

    // Redistribuidor: su cuenta está VINCULADA a su ficha (custodio).
    $this->supervisor = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.redistribuir']);
    $this->yatziri = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])
        ->create(['nombre_completo' => 'Yatziri Custodia', 'usuario_id' => $this->supervisor->id]);
    $this->juan = $this->datos['colaboradorA'];

    $this->payload = fn (Colaborador $colaborador, array $activos = [], array $unidades = [], array $extra = []): array => [
        'colaborador_id' => $colaborador->id,
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'idempotency_key' => (string) Str::uuid(),
        'activos' => $activos,
        'unidades' => $unidades,
        ...$extra,
    ];

    $this->camisas = fn (int $cantidad): array => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => $cantidad, 'finalidad' => 'uso_personal']];

    // El almacén entrega al custodio PARA REDISTRIBUIR (finalidad explícita).
    $this->entregarDesdeAlmacen = fn (Colaborador $colaborador, array $activos = [], array $unidades = [], string $finalidad = 'redistribucion') => $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)(
            $colaborador,
            array_map(fn (array $a): array => [...$a, 'finalidad' => $finalidad], $activos),
            array_map(fn (array $u): array => [...$u, 'finalidad' => $finalidad], $unidades),
            ['almacen_id' => $this->datos['almacenA']->id],
        ));

    $this->redistribuir = fn (User $usuario, Colaborador $colaborador, array $activos = [], array $unidades = []) => $this->actingAs($usuario)
        ->post('/entregas', ($this->payload)($colaborador, $activos, $unidades, ['origen' => 'custodia']));

    $this->stock = fn (): int => (int) SaldoInventario::query()
        ->where('almacen_id', $this->datos['almacenA']->id)
        ->where('activo_id', $this->datos['activoA']->id)
        ->value('cantidad');

    $this->custodiaCamisas = fn (Colaborador $colaborador): int => array_sum(array_column(
        app(ServicioCustodiaColaborador::class)->cantidadesRedistribuibles($colaborador->fresh(), true),
        'disponible',
    ));
});

it('el administrador entrega desde el almacén: descuenta stock y deja la custodia en el supervisor', function () {
    ($this->entregarDesdeAlmacen)($this->yatziri, ($this->camisas)(10))->assertSessionHasNoErrors();

    $entrega = EntregaUniforme::sole();

    expect(($this->stock)())->toBe(90)
        ->and(($this->custodiaCamisas)($this->yatziri))->toBe(10)
        ->and($entrega->colaborador_origen_id)->toBeNull()
        ->and($entrega->almacen_id)->toBe($this->datos['almacenA']->id);
});

it('el supervisor sólo ve en el selector lo que tiene bajo su custodia', function () {
    $this->actingAs($this->supervisor)
        ->getJson("/entregas/custodia/activos?empresa_id={$this->datos['empresaA']->id}&control=cantidad")
        ->assertOk()
        ->assertExactJson(['activos' => []]);

    ($this->entregarDesdeAlmacen)($this->yatziri, ($this->camisas)(10), [['unidad_activo_id' => $this->unidad->id, 'finalidad' => 'uso_personal']]);

    $this->actingAs($this->supervisor)
        ->getJson("/entregas/custodia/activos?empresa_id={$this->datos['empresaA']->id}&control=cantidad")
        ->assertOk()
        ->assertJsonCount(1, 'activos')
        ->assertJsonPath('activos.0.nombre', 'Camisa')
        ->assertJsonPath('activos.0.tallas.0.disponible', 10);

    $this->actingAs($this->supervisor)
        ->getJson("/entregas/custodia/unidades?empresa_id={$this->datos['empresaA']->id}&activo_id={$this->celular->id}")
        ->assertOk()
        ->assertJsonCount(1, 'unidades')
        ->assertJsonPath('unidades.0.codigo', $this->unidad->codigo);
});

it('el supervisor no ve lo que sigue en el almacén ni la custodia de otro supervisor', function () {
    $otroSupervisor = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.redistribuir']);
    Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create(['usuario_id' => $otroSupervisor->id]);

    ($this->entregarDesdeAlmacen)($this->yatziri, ($this->camisas)(10), [['unidad_activo_id' => $this->unidad->id, 'finalidad' => 'uso_personal']]);
    UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($this->celular)->for($this->datos['almacenA'])->create();

    $this->actingAs($otroSupervisor)
        ->getJson("/entregas/custodia/activos?empresa_id={$this->datos['empresaA']->id}&control=cantidad")
        ->assertExactJson(['activos' => []]);
    $this->actingAs($otroSupervisor)
        ->getJson("/entregas/custodia/activos?empresa_id={$this->datos['empresaA']->id}&control=individual")
        ->assertExactJson(['activos' => []]);
});

it('rechaza una unidad manipulada en el request que no está bajo la custodia del supervisor', function () {
    ($this->redistribuir)($this->supervisor, $this->juan, [], [['unidad_activo_id' => $this->unidad->id, 'finalidad' => 'uso_personal']])
        ->assertSessionHasErrors(['unidades.0.unidad_activo_id' => 'Esa unidad ya no está bajo tu custodia (fue entregada, devuelta o reportada) o no puede redistribuirse.']);

    expect($this->unidad->fresh()->estado)->toBe(EstadoUnidadActivo::EnAlmacen)
        ->and(EntregaUniforme::count())->toBe(0);
});

it('rechaza entregar más cantidad de la que el supervisor tiene en custodia', function () {
    ($this->entregarDesdeAlmacen)($this->yatziri, ($this->camisas)(10));

    ($this->redistribuir)($this->supervisor, $this->juan, ($this->camisas)(11))
        ->assertSessionHasErrors(['activos.0.cantidad' => 'En tu custodia sólo quedan 10 de Camisa talla M.']);

    expect(($this->custodiaCamisas)($this->yatziri))->toBe(10)
        ->and(EntregaUniforme::count())->toBe(1);
});

it('redistribuir cantidades mueve la custodia sin volver a descontar stock del almacén', function () {
    ($this->entregarDesdeAlmacen)($this->yatziri, ($this->camisas)(10));
    $movimientosDeStockAntes = MovimientoInventario::query()->where('direccion', '!=', DireccionMovimiento::SinEfecto)->count();

    ($this->redistribuir)($this->supervisor, $this->juan, ($this->camisas)(3))->assertSessionHasNoErrors();

    $redistribucion = EntregaUniforme::query()->where('colaborador_id', $this->juan->id)->sole();

    // Ningún movimiento de STOCK nuevo: sólo el evento de custodia (delta 0).
    expect(($this->stock)())->toBe(90)
        ->and(MovimientoInventario::query()->where('direccion', '!=', DireccionMovimiento::SinEfecto)->count())->toBe($movimientosDeStockAntes)
        ->and(MovimientoInventario::query()->where('tipo', TipoMovimiento::RedistribucionCustodia)->count())->toBe(1)
        ->and($redistribucion->colaborador_origen_id)->toBe($this->yatziri->id)
        ->and($redistribucion->almacen_id)->toBeNull()
        ->and(($this->custodiaCamisas)($this->yatziri))->toBe(7)
        ->and(($this->custodiaCamisas)($this->juan))->toBe(3);
});

it('redistribuir una unidad cambia su custodio sin generar otra salida de almacén', function () {
    ($this->entregarDesdeAlmacen)($this->yatziri, [], [['unidad_activo_id' => $this->unidad->id, 'finalidad' => 'uso_personal']]);
    $movimientosDeStockAntes = MovimientoInventario::query()->where('unidad_activo_id', $this->unidad->id)->where('direccion', '!=', DireccionMovimiento::SinEfecto)->count();

    ($this->redistribuir)($this->supervisor, $this->juan, [], [['unidad_activo_id' => $this->unidad->id, 'finalidad' => 'uso_personal']])->assertSessionHasNoErrors();

    $this->unidad->refresh();
    expect($this->unidad->colaborador_id)->toBe($this->juan->id)
        ->and($this->unidad->estado)->toBe(EstadoUnidadActivo::Asignada)
        ->and(MovimientoInventario::query()->where('unidad_activo_id', $this->unidad->id)->where('direccion', '!=', DireccionMovimiento::SinEfecto)->count())->toBe($movimientosDeStockAntes)
        ->and(MovimientoInventario::query()->where('unidad_activo_id', $this->unidad->id)->where('tipo', TipoMovimiento::RedistribucionCustodia)->count())->toBe(1);

    $this->actingAs($this->supervisor)
        ->getJson("/entregas/custodia/unidades?empresa_id={$this->datos['empresaA']->id}&activo_id={$this->celular->id}")
        ->assertExactJson(['unidades' => []]);

    $pendientesJuan = app(ServicioCustodiaColaborador::class)->pendientes($this->juan->fresh());
    expect(array_column($pendientesJuan, 'referencia'))->toContain($this->unidad->codigo);
});

it('una unidad no puede redistribuirse dos veces: la segunda operación ya no la encuentra en la custodia', function () {
    ($this->entregarDesdeAlmacen)($this->yatziri, [], [['unidad_activo_id' => $this->unidad->id, 'finalidad' => 'uso_personal']]);
    $pedro = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();
    $accion = app(RedistribuirCustodia::class);

    $accion->ejecutar($this->yatziri->id, $this->juan->id, $this->supervisor->id, now()->toDateString(), [], [['unidad_activo_id' => $this->unidad->id]]);

    expect(fn () => $accion->ejecutar($this->yatziri->id, $pedro->id, $this->supervisor->id, now()->toDateString(), [], [['unidad_activo_id' => $this->unidad->id]]))
        ->toThrow(ExcepcionDeNegocioSimple::class, 'Una de las unidades seleccionadas ya no está bajo tu custodia.');

    expect($this->unidad->fresh()->colaborador_id)->toBe($this->juan->id);
});

it('la acción rechaza el sobregiro de custodia aunque se salte la validación del request', function () {
    ($this->entregarDesdeAlmacen)($this->yatziri, ($this->camisas)(10));
    $accion = app(RedistribuirCustodia::class);

    $accion->ejecutar($this->yatziri->id, $this->juan->id, $this->supervisor->id, now()->toDateString(), ($this->camisas)(4), []);

    expect(fn () => $accion->ejecutar($this->yatziri->id, $this->juan->id, $this->supervisor->id, now()->toDateString(), ($this->camisas)(7), []))
        ->toThrow(ExcepcionDeNegocioSimple::class, 'Solicitaste 7 de Camisa talla M, pero en tu custodia sólo quedan 6.');

    expect(($this->custodiaCamisas)($this->yatziri))->toBe(6);
});

it('las devoluciones reducen la custodia y sólo la del almacén reingresa stock', function () {
    ($this->entregarDesdeAlmacen)($this->yatziri, ($this->camisas)(10));
    $entregaYatziri = EntregaUniforme::sole();

    ($this->redistribuir)($this->supervisor, $this->juan, ($this->camisas)(3));
    $entregaJuan = EntregaUniforme::query()->where('colaborador_id', $this->juan->id)->sole();

    app(RegistrarDevolucionFirmada::class)->ejecutar(
        $entregaJuan->id, $this->datos['almacenA']->id, now()->toDateString(),
        [['detalle_entrega_id' => $entregaJuan->detalles()->value('id'), 'cantidad' => 1, 'condicion' => CondicionDevolucion::Reutilizable->value]],
        [], $this->admin->id, null, null, firmaDemoBase64(), firmaDemoBase64(), true, null, null,
    );

    expect(($this->stock)())->toBe(91)
        ->and(($this->custodiaCamisas)($this->juan))->toBe(2)
        ->and(($this->custodiaCamisas)($this->yatziri))->toBe(7);

    // Yatziri ya no puede devolver las 3 que redistribuyó: sólo le quedan 7.
    expect(fn () => app(RegistrarDevolucionFirmada::class)->ejecutar(
        $entregaYatziri->id, $this->datos['almacenA']->id, now()->toDateString(),
        [['detalle_entrega_id' => $entregaYatziri->detalles()->value('id'), 'cantidad' => 8, 'condicion' => CondicionDevolucion::Reutilizable->value]],
        [], $this->admin->id, null, null, firmaDemoBase64(), firmaDemoBase64(), true, null, null,
    ))->toThrow(ExcepcionDeNegocioSimple::class, 'Intentas devolver 8 de Camisa, pero sólo quedan 7 pendientes de esta entrega.');
});

it('un usuario sin ficha de colaborador no tiene custodia que redistribuir', function () {
    $sinFicha = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.redistribuir']);

    ($this->redistribuir)($sinFicha, $this->juan, ($this->camisas)(1))
        ->assertSessionHasErrors(['origen' => 'Tu cuenta no está vinculada a una ficha de colaborador activa, así que no tienes activos bajo custodia que redistribuir.']);

    expect(EntregaUniforme::count())->toBe(0);
});

it('cada vía de entrega exige su propio permiso efectivo', function () {
    // Sólo `entregas.redistribuir`: no puede hacer salidas de almacén.
    $this->actingAs($this->supervisor)
        ->post('/entregas', ($this->payload)($this->juan, ($this->camisas)(1), [], ['almacen_id' => $this->datos['almacenA']->id]))
        ->assertForbidden();

    // Sólo `entregas.crear`: no puede redistribuir.
    $encargado = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.crear']);
    ($this->redistribuir)($encargado, $this->juan, ($this->camisas)(1))->assertForbidden();
    $this->actingAs($encargado)
        ->getJson("/entregas/custodia/activos?empresa_id={$this->datos['empresaA']->id}")
        ->assertForbidden();

    expect(($this->stock)())->toBe(100);
});

it('respeta el alcance de empresa del destinatario', function () {
    $ajeno = Colaborador::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->create();
    ($this->entregarDesdeAlmacen)($this->yatziri, ($this->camisas)(10));

    ($this->redistribuir)($this->supervisor, $ajeno, ($this->camisas)(1))
        ->assertForbidden();

    expect(($this->custodiaCamisas)($this->yatziri))->toBe(10);
});

it('respeta el alcance de sucursal del supervisor', function () {
    $otraSucursal = Sucursal::factory()->for($this->datos['empresaA'])->create();
    $fuera = Colaborador::factory()->for($this->datos['empresaA'])->for($otraSucursal)->create();
    $this->supervisor->sucursales()->attach($this->datos['sucursalA']);
    ($this->entregarDesdeAlmacen)($this->yatziri, ($this->camisas)(10));

    ($this->redistribuir)($this->supervisor, $fuera, ($this->camisas)(1))
        ->assertSessionHasErrors(['colaborador_id' => 'No tienes acceso a la sucursal de ese colaborador.']);

    expect(($this->custodiaCamisas)($this->yatziri))->toBe(10);
});

it('conserva la cadena Almacén → Supervisor → Colaborador en el detalle de cada entrega', function () {
    ($this->entregarDesdeAlmacen)($this->yatziri, ($this->camisas)(10));
    $original = EntregaUniforme::sole();
    ($this->redistribuir)($this->supervisor, $this->juan, ($this->camisas)(3));
    $redistribucion = EntregaUniforme::query()->where('colaborador_id', $this->juan->id)->sole();

    $this->actingAs($this->admin)->get("/entregas/{$redistribucion->id}")
        ->assertInertia(fn ($page) => $page
            ->where('entrega.origen_custodia.nombre_completo', 'Yatziri Custodia')
            ->where('entrega.items.0.recibido_de.folio', $original->folio)
            ->where('entrega.items.0.cantidad', 3));

    $this->actingAs($this->admin)->get("/entregas/{$original->id}")
        ->assertInertia(fn ($page) => $page
            ->where('entrega.origen_custodia', null)
            ->where('entrega.items.0.cantidad', 10)
            ->where('entrega.items.0.redistribuido_a.0.folio', $redistribucion->folio)
            ->where('entrega.items.0.redistribuido_a.0.cantidad', 3));
});

it('audita la redistribución con origen, destinatario y renglones legibles', function () {
    ($this->entregarDesdeAlmacen)($this->yatziri, ($this->camisas)(10));
    ($this->redistribuir)($this->supervisor, $this->juan, ($this->camisas)(3));

    $registro = BitacoraAuditoria::query()->where('modulo', 'entregas')->where('accion', 'redistribuir')->sole();

    expect($registro->usuario_id)->toBe($this->supervisor->id)
        ->and($registro->empresa_id)->toBe($this->datos['empresaA']->id)
        ->and($registro->valores_nuevos['origen'])->toStartWith('Yatziri Custodia')
        ->and($registro->valores_nuevos['destinatario'])->toStartWith($this->juan->nombre_completo)
        ->and($registro->valores_nuevos['renglones'])->toBe([[
            'activo' => 'Camisa', 'talla' => 'M', 'cantidad' => 3,
            'desde' => 'Para redistribuir', 'finalidad_destinatario' => 'Uso personal',
        ]]);
});

it('sólo con entregas.crear el único origen es el almacén', function () {
    $almacenista = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.crear']);

    $this->actingAs($almacenista)->get('/entregas/crear')
        ->assertInertia(fn ($page) => $page
            ->where('origenes', ['almacen' => true, 'custodia' => false])
            ->where('redistribuirSinVinculo', false)
            ->where('custodiaPropia', null));
});

it('sólo con entregas.redistribuir y ficha vinculada el único origen es su custodia', function () {
    $this->actingAs($this->supervisor)->get('/entregas/crear')
        ->assertInertia(fn ($page) => $page
            ->where('origenes', ['almacen' => false, 'custodia' => true])
            ->where('custodiaPropia.colaborador_id', $this->yatziri->id));
});

it('con ambos permisos y ficha vinculada puede elegir el origen', function () {
    $ambos = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.crear', 'entregas.redistribuir']);
    $this->yatziri->update(['usuario_id' => null]);
    $this->yatziri->update(['usuario_id' => $ambos->id]);

    $this->actingAs($ambos)->get('/entregas/crear')
        ->assertInertia(fn ($page) => $page->where('origenes', ['almacen' => true, 'custodia' => true]));
});

it('con ambos permisos pero sin ficha vinculada sólo opera desde almacén y se le explica por qué', function () {
    $ambos = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.crear', 'entregas.redistribuir']);

    $this->actingAs($ambos)->get('/entregas/crear')
        ->assertInertia(fn ($page) => $page
            ->where('origenes', ['almacen' => true, 'custodia' => false])
            ->where('redistribuirSinVinculo', true));

    ($this->entregarDesdeAlmacen)($this->juan, ($this->camisas)(1));
    $this->actingAs($ambos)
        ->post('/entregas', ($this->payload)($this->juan, ($this->camisas)(1), [], ['almacen_id' => $this->datos['almacenA']->id]))
        ->assertSessionHasNoErrors();

    expect(($this->stock)())->toBe(98);
});

it('vinculado pero sin custodia obtiene un estado vacío, nunca el inventario del almacén', function () {
    $this->actingAs($this->supervisor)
        ->getJson("/entregas/custodia/disponibilidad?empresa_id={$this->datos['empresaA']->id}")
        ->assertOk()
        ->assertJsonPath('saldos', [])
        ->assertJsonPath('total_unidades', 0);
});
