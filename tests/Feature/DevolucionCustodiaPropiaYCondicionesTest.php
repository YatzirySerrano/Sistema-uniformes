<?php

use App\Acciones\CrearEntregaUniforme;
use App\Enums\CondicionDevolucion;
use App\Enums\EstadoUnidadActivo;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\DetalleDevolucion;
use App\Models\Devolucion;
use App\Models\MovimientoInventario;
use App\Models\Reserva;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Policies\DevolucionPolicy;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioCustodiaColaborador;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * (1) Nadie se recibe a sí mismo la devolución de su custodia salvo con
 * `devoluciones.procesar-custodia-propia` (nunca por nombre de rol; quién
 * hizo la entrega es irrelevante). (2) Un renglón por cantidad puede
 * repartirse entre varias condiciones dentro de UNA sola devolución.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->entregador = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaA']]);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 20,
    ));
    $laptop = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Laptop']);
    $this->unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($laptop)->for($this->datos['almacenA'])->create();

    // Entrega hecha por OTRO usuario (el encargado): 8 piezas + 1 laptop.
    $this->entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->entregador->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 8]],
        [['unidad_activo_id' => $this->unidad->id]], [],
    );
    $this->detalle = $this->entrega->detalles->firstWhere('unidad_activo_id', null);
    $this->detalleUnidad = $this->entrega->detalles->firstWhere('unidad_activo_id', $this->unidad->id);

    $this->rolConPermisos = function (array $permisos): User {
        $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        return tap(User::factory()->create(), function (User $u) use ($rol): void {
            $u->assignRole($rol);
            $u->empresas()->sync([$this->datos['empresaA']->id]);
        });
    };
    $basicos = ['devoluciones.ver', 'devoluciones.crear', 'devoluciones.confirmar'];
    $this->basicos = $basicos;

    // La cuenta que REPRESENTA al colaborador que devuelve.
    $this->propio = ($this->rolConPermisos)($basicos);
    $this->datos['colaboradorA']->update(['usuario_id' => $this->propio->id]);
    $this->otro = ($this->rolConPermisos)($basicos);

    $this->devolver = fn (User $usuario, array $activos = [], array $unidades = [], ?string $token = null) => $this->actingAs($usuario)->post('/devoluciones', array_filter([
        'entrega_uniforme_id' => $this->entrega->id, 'almacen_id' => $this->datos['almacenA']->id,
        'fecha' => now()->toDateString(), 'motivo' => 'Fin de servicio',
        'activos' => $activos, 'unidades' => $unidades,
        'firma' => firmaDemoBase64(), 'firma_operador' => firmaDemoBase64(), 'aceptacion' => true,
        'reserva_token' => $token,
    ], fn ($v) => $v !== null && $v !== []));
    $this->fila = fn (int $cantidad, string $condicion = 'reutilizable'): array => [
        'detalle_entrega_id' => $this->detalle->id, 'cantidad' => $cantidad, 'condicion' => $condicion,
    ];
    $this->filaDividida = fn (int $cantidad, array $reparto): array => [
        'detalle_entrega_id' => $this->detalle->id, 'cantidad' => $cantidad,
        'condiciones' => collect($reparto)->map(fn ($n, $c) => ['condicion' => $c, 'cantidad' => $n])->values()->all(),
    ];
    $this->saldo = fn (): int => (int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad');
    $this->pendiente = fn (): int => app(ServicioCustodiaColaborador::class)->pendienteDeDetalle($this->detalle->fresh());
});

/*
|--------------------------------------------------------------------------
| Custodia propia: la regla es POR RENGLÓN según su finalidad
|--------------------------------------------------------------------------
*/

describe('custodia propia por renglón', function () {
    beforeEach(function () {
        $inventario = app(ServicioInventario::class);
        $alta = function (Activo $activo, int $cantidad) use ($inventario): void {
            $inventario->registrarMovimiento(new MovimientoInventarioDatos(
                empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
                activoId: $activo->id, tallaId: null, tipo: TipoMovimiento::Inicial, cantidad: $cantidad,
            ));
        };
        $playera = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Playera']);
        $gorra = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Gorra']);
        $alta($playera, 20);
        $alta($gorra, 20);
        $radioActivo = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Radio']);
        $this->laptopPersonal = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($this->unidad->activo)->for($this->datos['almacenA'])->create();
        $this->radio = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($radioActivo)->for($this->datos['almacenA'])->create();

        // Entrega MIXTA al colaborador que la cuenta `propio` representa,
        // hecha por otro usuario (el encargado).
        $this->mixta = app(CrearEntregaUniforme::class)->ejecutar(
            $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->entregador->id, now()->toDateString(),
            [
                ['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2, 'finalidad' => 'uso_personal'],
                ['activo_id' => $playera->id, 'talla_id' => null, 'cantidad' => 8, 'finalidad' => 'redistribucion'],
                ['activo_id' => $gorra->id, 'talla_id' => null, 'cantidad' => 3, 'finalidad' => null],
            ],
            [
                ['unidad_activo_id' => $this->laptopPersonal->id, 'finalidad' => 'uso_personal'],
                ['unidad_activo_id' => $this->radio->id, 'finalidad' => 'redistribucion'],
            ],
            [],
        );
        $this->personal = $this->mixta->detalles->firstWhere('activo_id', $this->datos['activoA']->id);
        $this->redistribucion = $this->mixta->detalles->firstWhere('activo_id', $playera->id);
        $this->sinClasificar = $this->mixta->detalles->firstWhere('activo_id', $gorra->id);
        $this->unidadPersonal = $this->mixta->detalles->firstWhere('unidad_activo_id', $this->laptopPersonal->id);
        $this->unidadRedistribucion = $this->mixta->detalles->firstWhere('unidad_activo_id', $this->radio->id);

        $this->devolverMixta = fn (User $usuario, array $activos = [], array $unidades = [], ?string $token = null) => $this->actingAs($usuario)->post('/devoluciones', array_filter([
            'entrega_uniforme_id' => $this->mixta->id, 'almacen_id' => $this->datos['almacenA']->id,
            'fecha' => now()->toDateString(), 'motivo' => 'Fin de servicio',
            'activos' => $activos, 'unidades' => $unidades,
            'firma' => firmaDemoBase64(), 'firma_operador' => firmaDemoBase64(), 'aceptacion' => true,
            'reserva_token' => $token,
        ], fn ($v) => $v !== null && $v !== []));
        $this->renglon = fn ($detalle, ?int $cantidad = null): array => [
            'detalle_entrega_id' => $detalle->id, 'cantidad' => $cantidad ?? $detalle->cantidad, 'condicion' => 'reutilizable',
        ];
        $this->unidadFila = fn ($detalle): array => ['detalle_entrega_id' => $detalle->id, 'condicion' => 'funcionando'];
        $this->reservarMixta = fn (User $usuario, array $activos, array $unidades = []) => $this->actingAs($usuario)->postJson('/devoluciones/reserva', [
            'token' => (string) Str::uuid(), 'entrega_uniforme_id' => $this->mixta->id, 'activos' => $activos, 'unidades' => $unidades,
        ]);
        $this->todo = fn (): array => [
            [($this->renglon)($this->personal), ($this->renglon)($this->redistribucion), ($this->renglon)($this->sinClasificar)],
            [($this->unidadFila)($this->unidadPersonal), ($this->unidadFila)($this->unidadRedistribucion)],
        ];
    });

    it('sin permiso: la pantalla carga, bloquea sólo lo personal / sin clasificar propio y deja usable lo de redistribución', function () {
        $this->actingAs($this->propio)->get("/devoluciones/crear?entrega_id={$this->mixta->id}")
            ->assertInertia(function (AssertableInertia $p): void {
                $p->where('bloqueoCustodiaPropia', null);
                $porId = collect($p->toArray()['props']['entrega']['renglones'])->keyBy('detalle_entrega_id');
                expect($porId[$this->personal->id])->toMatchArray(['puede_devolver' => false, 'motivo_bloqueo' => DevolucionPolicy::MENSAJE_USO_PERSONAL_PROPIO])
                    ->and($porId[$this->sinClasificar->id])->toMatchArray(['puede_devolver' => false, 'motivo_bloqueo' => DevolucionPolicy::MENSAJE_SIN_CLASIFICAR_PROPIO])
                    ->and($porId[$this->unidadPersonal->id])->toMatchArray(['puede_devolver' => false, 'motivo_bloqueo' => DevolucionPolicy::MENSAJE_USO_PERSONAL_PROPIO])
                    ->and($porId[$this->redistribucion->id])->toMatchArray(['puede_devolver' => true, 'motivo_bloqueo' => null])
                    ->and($porId[$this->unidadRedistribucion->id])->toMatchArray(['puede_devolver' => true, 'motivo_bloqueo' => null]);
            });
    });

    it('sin permiso: devuelve con permisos normales lo de redistribución propio (cantidad y unidad), sin tocar finalidad', function () {
        ($this->devolverMixta)($this->propio, [($this->renglon)($this->redistribucion)], [($this->unidadFila)($this->unidadRedistribucion)])
            ->assertSessionHasNoErrors();

        expect(Devolucion::query()->where('entrega_uniforme_id', $this->mixta->id)->count())->toBe(1)
            ->and(app(ServicioCustodiaColaborador::class)->pendienteDeDetalle($this->redistribucion->fresh()))->toBe(0)
            ->and($this->radio->fresh()->estado)->toBe(EstadoUnidadActivo::EnAlmacen)
            ->and(app(ServicioCustodiaColaborador::class)->pendienteDeDetalle($this->personal->fresh()))->toBe(2)
            ->and($this->redistribucion->fresh()->finalidad?->value)->toBe('redistribucion')
            ->and($this->personal->fresh()->finalidad?->value)->toBe('uso_personal')
            ->and($this->sinClasificar->fresh()->finalidad)->toBeNull();
    });

    it('sin permiso: una petición manipulada con algo personal o sin clasificar propio se rechaza completa y no crea nada', function (string $caso, string $mensaje) {
        $saldoAntes = (int) SaldoInventario::query()->sum('cantidad');
        $movimientosAntes = MovimientoInventario::query()->count();
        [$activos, $unidades] = match ($caso) {
            'personal' => [[($this->renglon)($this->redistribucion), ($this->renglon)($this->personal)], []],
            'sin clasificar' => [[($this->renglon)($this->sinClasificar)], []],
            'unidad personal' => [[], [($this->unidadFila)($this->unidadRedistribucion), ($this->unidadFila)($this->unidadPersonal)]],
        };

        ($this->devolverMixta)($this->propio, $activos, $unidades)->assertSessionHasErrors(['negocio' => $mensaje]);

        expect(Devolucion::query()->count())->toBe(0)
            ->and(DetalleDevolucion::query()->count())->toBe(0)
            ->and((int) SaldoInventario::query()->sum('cantidad'))->toBe($saldoAntes)
            ->and(MovimientoInventario::query()->count())->toBe($movimientosAntes)
            ->and($this->laptopPersonal->fresh()->estado)->toBe(EstadoUnidadActivo::Asignada)
            ->and($this->radio->fresh()->estado)->toBe(EstadoUnidadActivo::Asignada);
    })->with([
        'uso personal' => ['personal', DevolucionPolicy::MENSAJE_USO_PERSONAL_PROPIO],
        'sin clasificar' => ['sin clasificar', DevolucionPolicy::MENSAJE_SIN_CLASIFICAR_PROPIO],
        'unidad de uso personal' => ['unidad personal', DevolucionPolicy::MENSAJE_USO_PERSONAL_PROPIO],
    ]);

    it('apartado: sólo redistribución propia se aparta; si incluye algo personal propio se rechaza sin crear reserva', function () {
        ($this->reservarMixta)($this->propio, [['detalle_entrega_id' => $this->redistribucion->id, 'cantidad' => 8]], [['detalle_entrega_id' => $this->unidadRedistribucion->id]])
            ->assertOk()->assertJsonPath('ok', true);
        expect(Reserva::query()->count())->toBe(1);

        ($this->reservarMixta)($this->propio, [['detalle_entrega_id' => $this->redistribucion->id, 'cantidad' => 8], ['detalle_entrega_id' => $this->personal->id, 'cantidad' => 2]])
            ->assertStatus(422)->assertJsonPath('message', DevolucionPolicy::MENSAJE_USO_PERSONAL_PROPIO);
        ($this->reservarMixta)($this->propio, [], [['detalle_entrega_id' => $this->unidadPersonal->id]])
            ->assertStatus(422)->assertJsonPath('message', DevolucionPolicy::MENSAJE_USO_PERSONAL_PROPIO);
        expect(Reserva::query()->count())->toBe(1);

        // La lectura periódica de custodia sigue funcionando igual.
        $this->actingAs($this->propio)->getJson("/devoluciones/disponibilidad?entrega_uniforme_id={$this->mixta->id}")
            ->assertOk()->assertJsonCount(3, 'renglones')->assertJsonCount(2, 'unidades');
    });

    it('si todo lo devolvible es personal / sin clasificar propio, la página avisa en general', function () {
        ($this->devolverMixta)($this->otro, [($this->renglon)($this->redistribucion)], [($this->unidadFila)($this->unidadRedistribucion)])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->propio)->get("/devoluciones/crear?entrega_id={$this->mixta->id}")
            ->assertInertia(fn (AssertableInertia $p) => $p->where('bloqueoCustodiaPropia', DevolucionPolicy::MENSAJE_USO_PERSONAL_PROPIO));
    });

    it('con el permiso especial (rol personalizado) devuelve las tres finalidades y las unidades; al retirarlo vuelve a rechazar', function () {
        $rol = $this->propio->roles()->first();
        $rol->givePermissionTo('devoluciones.procesar-custodia-propia');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$activos, $unidades] = ($this->todo)();
        $activos[0]['cantidad'] = 1;
        ($this->devolverMixta)($this->propio, $activos, $unidades)->assertSessionHasNoErrors();
        expect(Devolucion::query()->count())->toBe(1);

        $rol->revokePermissionTo('devoluciones.procesar-custodia-propia');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        ($this->devolverMixta)($this->propio->fresh(), [($this->renglon)($this->personal, 1)])
            ->assertSessionHasErrors(['negocio' => DevolucionPolicy::MENSAJE_USO_PERSONAL_PROPIO]);
        expect(Devolucion::query()->count())->toBe(1);
    });

    it('el permiso especial asignado directamente al usuario también cuenta', function () {
        $this->propio->givePermissionTo('devoluciones.procesar-custodia-propia');

        ($this->devolverMixta)($this->propio, [($this->renglon)($this->sinClasificar)], [($this->unidadFila)($this->unidadPersonal)])
            ->assertSessionHasNoErrors();
    });

    it('otro usuario autorizado recibe todo, aunque no haya hecho la entrega', function () {
        expect($this->mixta->registrada_por ?? $this->entregador->id)->not->toBe($this->otro->id);
        [$activos, $unidades] = ($this->todo)();

        ($this->devolverMixta)($this->otro, $activos, $unidades)->assertSessionHasNoErrors();

        expect(DetalleDevolucion::query()->count())->toBe(5);
    });

    it('una cuenta sin ficha de colaborador, o con otra ficha, no se ve afectada', function () {
        Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create(['usuario_id' => $this->otro->id]);
        $sinFicha = ($this->rolConPermisos)($this->basicos);

        ($this->devolverMixta)($sinFicha, [($this->renglon)($this->personal, 1)])->assertSessionHasNoErrors();
        ($this->devolverMixta)($this->otro, [($this->renglon)($this->personal, 1), ($this->renglon)($this->sinClasificar)])->assertSessionHasNoErrors();

        expect(Devolucion::query()->count())->toBe(2);
    });
});

/*
|--------------------------------------------------------------------------
| Condición única (compatibilidad) y dividir por condición
|--------------------------------------------------------------------------
*/

it('condición única: la petición de siempre devuelve 8 reutilizables en una fila y reingresa 8', function () {
    $saldo = ($this->saldo)();

    ($this->devolver)($this->otro, [($this->fila)(8)])->assertSessionHasNoErrors();

    $detalles = DetalleDevolucion::query()->whereNull('unidad_activo_id')->get();
    expect($detalles)->toHaveCount(1)
        ->and($detalles[0]->cantidad)->toBe(8)
        ->and($detalles[0]->condicion)->toBe(CondicionDevolucion::Reutilizable)
        ->and(($this->saldo)())->toBe($saldo + 8)
        ->and(($this->pendiente)())->toBe(0);
});

it('dividir 8 en 6 reutilizables, 1 dañada y 1 baja: una devolución, custodia −8 y sólo 6 reingresan', function () {
    $saldo = ($this->saldo)();
    $movimientosAntes = MovimientoInventario::query()->where('tipo', TipoMovimiento::Devolucion)->count();

    ($this->devolver)($this->otro, [($this->filaDividida)(8, ['reutilizable' => 6, 'danado' => 1, 'baja' => 1])])
        ->assertSessionHasNoErrors();

    $devolucion = Devolucion::query()->sole();
    $partes = $devolucion->detalles()->orderBy('id')->get();
    expect($partes->pluck('detalle_entrega_id')->unique()->all())->toBe([$this->detalle->id])
        ->and($partes->map(fn ($d) => [$d->condicion, $d->cantidad, $d->reingresa_inventario])->all())->toBe([
            [CondicionDevolucion::Reutilizable, 6, true],
            [CondicionDevolucion::Danado, 1, false],
            [CondicionDevolucion::Baja, 1, false],
        ])
        ->and($partes->sum('cantidad'))->toBe(8)
        ->and(($this->pendiente)())->toBe(0)
        ->and(($this->saldo)())->toBe($saldo + 6)
        ->and(MovimientoInventario::query()->where('tipo', TipoMovimiento::Devolucion)->count())->toBe($movimientosAntes + 1)
        ->and((int) MovimientoInventario::query()->where('tipo', TipoMovimiento::Devolucion)->latest('id')->value('cantidad'))->toBe(6);

    // El comprobante conserva la distribución renglón por renglón.
    expect(collect($devolucion->acuse->snapshot_devolucion['items'] ?? [])->map(fn ($i) => [$i['condicion'], $i['cantidad']])->all())
        ->toBe([['Reutilizable', 6], ['Dañado', 1], ['Baja', 1]]);
});

it('las cantidades en cero se omiten y no crean filas', function () {
    ($this->devolver)($this->otro, [($this->filaDividida)(5, ['reutilizable' => 5, 'danado' => 0])])->assertSessionHasNoErrors();

    expect(DetalleDevolucion::query()->whereNull('unidad_activo_id')->count())->toBe(1);
});

it('rechaza una distribución que no cuadra o con datos inválidos, sin crear nada', function (array $fila, string $campo, ?string $mensaje) {
    $saldo = ($this->saldo)();
    $fila = array_merge(['detalle_entrega_id' => $this->detalle->id], $fila);

    $respuesta = ($this->devolver)($this->otro, [$fila]);
    $mensaje === null
        ? $respuesta->assertSessionHasErrors($campo)
        : $respuesta->assertSessionHasErrors([$campo => $mensaje]);

    expect(Devolucion::query()->count())->toBe(0)
        ->and(($this->saldo)())->toBe($saldo)
        ->and(($this->pendiente)())->toBe(8);
})->with([
    'falta 1' => [['cantidad' => 8, 'condiciones' => [['condicion' => 'reutilizable', 'cantidad' => 6], ['condicion' => 'danado', 'cantidad' => 1]]], 'activos.0.condiciones', 'Falta asignar condición a 1 pieza.'],
    'sobra 1' => [['cantidad' => 8, 'condiciones' => [['condicion' => 'reutilizable', 'cantidad' => 6], ['condicion' => 'danado', 'cantidad' => 2], ['condicion' => 'baja', 'cantidad' => 1]]], 'activos.0.condiciones', 'La distribución por condición supera la cantidad a devolver por 1 pieza.'],
    'negativa' => [['cantidad' => 8, 'condiciones' => [['condicion' => 'reutilizable', 'cantidad' => 9], ['condicion' => 'danado', 'cantidad' => -1]]], 'activos.0.condiciones.1.cantidad', null],
    'no entera' => [['cantidad' => 8, 'condiciones' => [['condicion' => 'reutilizable', 'cantidad' => 7.5], ['condicion' => 'danado', 'cantidad' => 0.5]]], 'activos.0.condiciones.0.cantidad', null],
    'condición inválida' => [['cantidad' => 8, 'condiciones' => [['condicion' => 'robo_extravio', 'cantidad' => 8]]], 'activos.0.condiciones.0.condicion', null],
    'condición repetida' => [['cantidad' => 8, 'condiciones' => [['condicion' => 'danado', 'cantidad' => 4], ['condicion' => 'danado', 'cantidad' => 4]]], 'activos.0.condiciones', 'Cada condición sólo puede aparecer una vez en la distribución.'],
    'total > custodia' => [['cantidad' => 9, 'condiciones' => [['condicion' => 'reutilizable', 'cantidad' => 9]]], 'negocio', null],
]);

it('lo apartado por otra devolución también limita la distribución; el propio apartado no se descuenta', function () {
    $tokenAjeno = (string) Str::uuid();
    $this->actingAs($this->entregador)->postJson('/devoluciones/reserva', [
        'token' => $tokenAjeno, 'entrega_uniforme_id' => $this->entrega->id,
        'activos' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3]], 'unidades' => [],
    ])->assertOk();

    $token = (string) Str::uuid();
    $this->actingAs($this->otro)->postJson('/devoluciones/reserva', [
        'token' => $token, 'entrega_uniforme_id' => $this->entrega->id,
        'activos' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 5]], 'unidades' => [],
    ])->assertOk()->assertJsonPath('ok', true)->assertJsonPath('lineas_cantidad.0.disponible_efectivo', 5);

    $this->actingAs($this->otro)->postJson('/devoluciones/reserva', [
        'token' => $token, 'entrega_uniforme_id' => $this->entrega->id,
        'activos' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 6]], 'unidades' => [],
    ])->assertOk()->assertJsonPath('ok', false);

    // Confirma 5 repartidas con su propio token.
    $this->actingAs($this->otro)->postJson('/devoluciones/reserva', [
        'token' => $token, 'entrega_uniforme_id' => $this->entrega->id,
        'activos' => [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 5]], 'unidades' => [],
    ])->assertJsonPath('ok', true);
    ($this->devolver)($this->otro, [($this->filaDividida)(5, ['reutilizable' => 4, 'baja' => 1])], [], $token)->assertSessionHasNoErrors();

    expect(($this->pendiente)())->toBe(3)
        ->and(Reserva::query()->where('token', $token)->value('consumida_en'))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Finalidad: sólo lectura en la custodia del colaborador
|--------------------------------------------------------------------------
*/

it('la custodia del colaborador muestra la finalidad sin control para cambiarla, aun con colaboradores.editar y entregas.crear', function () {
    $editor = ($this->rolConPermisos)(['colaboradores.ver', 'colaboradores.editar', 'entregas.crear']);
    $finalidadOriginal = $this->detalle->fresh()->finalidad;

    $this->actingAs($editor)->get("/colaboradores/{$this->datos['colaboradorA']->id}")
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->missing('puedeClasificarFinalidad')
            ->has('custodia.pendientes.0.finalidad_etiqueta')
            ->etc());

    $this->actingAs($editor)->put("/entregas/renglones/{$this->detalle->id}/finalidad", ['finalidad' => 'redistribucion'])->assertNotFound();
    $this->actingAs($editor)->put("/colaboradores/{$this->datos['colaboradorA']->id}", ['finalidad' => 'redistribucion']);

    expect($this->detalle->fresh()->finalidad)->toBe($finalidadOriginal);
});

it('la migración de datos registra el permiso una sola vez y no lo asigna a ningún rol ni usuario', function () {
    $nombre = 'devoluciones.procesar-custodia-propia';
    DB::table('permissions')->where('name', $nombre)->delete();
    $asignacionesAntes = DB::table('role_has_permissions')->count();
    $migracion = require database_path('migrations/2026_10_02_000001_registrar_permiso_procesar_custodia_propia_devoluciones.php');

    $migracion->up();
    $migracion->up();

    $permisoId = DB::table('permissions')->where('name', $nombre)->where('guard_name', 'web')->value('id');
    expect(DB::table('permissions')->where('name', $nombre)->count())->toBe(1)
        ->and(DB::table('role_has_permissions')->where('permission_id', $permisoId)->exists())->toBeFalse()
        ->and(DB::table('model_has_permissions')->where('permission_id', $permisoId)->exists())->toBeFalse()
        ->and(DB::table('role_has_permissions')->count())->toBe($asignacionesAntes);
});
