<?php

use App\Acciones\RegistrarTraspasoInventario;
use App\Enums\CondicionUnidadActivo;
use App\Enums\RolSistema;
use App\Enums\TipoControlActivo;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocio;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\BitacoraAuditoria;
use App\Models\CategoriaActivo;
use App\Models\MovimientoInventario;
use App\Models\Talla;
use App\Models\TraspasoInventario;
use App\Models\TraspasoRenglon;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;

/**
 * Traspasos de inventario: entre almacenes de la misma empresa y entre
 * empresas distintas, por cantidad y por unidades identificadas. La historia
 * previa nunca se reescribe; cada traspaso es atómico.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value);
    $this->inventario = app(ServicioInventario::class);

    // Segundo almacén para la empresa A (traspaso misma empresa A → A2).
    $this->almacenA2 = Almacen::factory()->paraEmpresa($this->datos['empresaA'])->create(['nombre' => 'Almacén A-2']);

    $this->cargarStock = function (int $empresaId, int $almacenId, int $activoId, ?int $tallaId, int $cantidad): void {
        $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
            empresaId: $empresaId, almacenId: $almacenId, activoId: $activoId, tallaId: $tallaId,
            tipo: TipoMovimiento::Inicial, cantidad: $cantidad,
        ));
    };

    $this->traspasar = fn (array $payload): TraspasoInventario => app(RegistrarTraspasoInventario::class)->ejecutar(
        $payload['empresa_origen_id'], $payload['almacen_origen_id'],
        $payload['empresa_destino_id'], $payload['almacen_destino_id'],
        $payload['renglones'], $this->admin->id, $payload['motivo'] ?? null,
    );
});

// ---------------------------------------------------------------------------
// Misma empresa / cantidad
// ---------------------------------------------------------------------------
it('traspasa por cantidad entre dos almacenes de la misma empresa restando origen y sumando destino', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    $traspaso = ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
        'renglones' => [[
            'control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id,
            'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 30,
        ]],
    ]);

    expect($traspaso->tipo)->toBe(TraspasoInventario::TIPO_MISMA_EMPRESA);
    expect($this->inventario->saldoActual($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(70);
    expect($this->inventario->saldoActual($this->datos['empresaA']->id, $this->almacenA2->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(30);

    $renglon = $traspaso->renglones()->first();
    expect($renglon->movimiento_salida_id)->not->toBeNull();
    expect($renglon->movimiento_entrada_id)->not->toBeNull();
    expect(MovimientoInventario::findOrFail($renglon->movimiento_salida_id)->tipo)->toBe(TipoMovimiento::TraspasoSalida);
    expect((int) MovimientoInventario::findOrFail($renglon->movimiento_entrada_id)->referencia_id)->toBe($traspaso->id);
    expect(MovimientoInventario::findOrFail($renglon->movimiento_entrada_id)->referencia_tipo)->toBe(TraspasoInventario::class);
});

it('rechaza el traspaso cuando el stock del origen es insuficiente y no aplica nada', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 10);

    expect(fn () => ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
        'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 50]],
    ]))->toThrow(ExcepcionDeNegocio::class);

    expect($this->inventario->saldoActual($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(10);
    expect(TraspasoInventario::count())->toBe(0);
    expect(TraspasoRenglon::count())->toBe(0);
});

it('rechaza traspaso a mismo almacén en la misma empresa', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 10);

    expect(fn () => ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->datos['almacenA']->id,
        'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5]],
    ]))->toThrow(ExcepcionDeNegocio::class);
});

it('con dos traspasos concurrentes sobre el mismo saldo el segundo falla y el saldo queda coherente', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 50);

    $hacer = fn () => ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
        'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 30]],
    ]);

    $hacer();
    expect($hacer)->toThrow(ExcepcionDeNegocio::class);

    expect($this->inventario->saldoActual($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(20);
    expect($this->inventario->saldoActual($this->datos['empresaA']->id, $this->almacenA2->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(30);
});

// ---------------------------------------------------------------------------
// Interempresa / cantidad + homologación
// ---------------------------------------------------------------------------
it('traspasa por cantidad entre empresas reutilizando el activo equivalente existente en destino, sin duplicar', function () {
    $this->datos['activoA']->update(['nombre' => 'Camisa']);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 500);

    $activoDestino = Activo::factory()->for($this->datos['empresaB'])->create([
        'nombre' => 'Camisa', 'tipo_control' => TipoControlActivo::Cantidad, 'tipo_activo_id' => null, 'categoria_id' => null,
    ]);
    $activoDestino->tallas()->attach($this->datos['tallaA']);
    ($this->cargarStock)($this->datos['empresaB']->id, $this->datos['almacenB']->id, $activoDestino->id, $this->datos['tallaA']->id, 100);

    $antesActivos = Activo::count();

    $traspaso = ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaB']->id, 'almacen_destino_id' => $this->datos['almacenB']->id,
        'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 300]],
    ]);

    expect($traspaso->tipo)->toBe(TraspasoInventario::TIPO_INTEREMPRESA);
    expect(Activo::count())->toBe($antesActivos); // no se creó ninguno
    expect($this->inventario->saldoActual($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(200);
    expect($this->inventario->saldoActual($this->datos['empresaB']->id, $this->datos['almacenB']->id, $activoDestino->id, $this->datos['tallaA']->id))->toBe(400);
    expect($traspaso->renglones()->first()->activo_destino_id)->toBe($activoDestino->id);
    expect($traspaso->renglones()->first()->activo_destino_creado)->toBeFalse();
});

it('crea el activo destino una sola vez cuando no existe equivalente, y traspasos posteriores lo reutilizan', function () {
    $this->datos['activoA']->update(['nombre' => 'Radio Motorola']);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 500);

    $payload = [
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaB']->id, 'almacen_destino_id' => $this->datos['almacenB']->id,
        'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 100]],
    ];

    $t1 = ($this->traspasar)($payload);
    $creado = Activo::where('empresa_id', $this->datos['empresaB']->id)->where('nombre', 'Radio Motorola')->get();
    expect($creado)->toHaveCount(1);
    expect($creado->first()->codigo)->toStartWith('ACT-');
    expect($t1->renglones()->first()->activo_destino_creado)->toBeTrue();
    expect($creado->first()->tallas()->pluck('tallas.id')->all())->toBe([$this->datos['tallaA']->id]);

    $t2 = ($this->traspasar)($payload);
    expect(Activo::where('empresa_id', $this->datos['empresaB']->id)->where('nombre', 'Radio Motorola')->count())->toBe(1);
    expect($t2->renglones()->first()->activo_destino_id)->toBe($creado->first()->id);
    expect($t2->renglones()->first()->activo_destino_creado)->toBeFalse();
    expect($this->inventario->saldoActual($this->datos['empresaB']->id, $this->datos['almacenB']->id, $creado->first()->id, $this->datos['tallaA']->id))->toBe(200);
});

it('no homologa activos sólo por el nombre: distinta categoría crea uno nuevo en destino', function () {
    $catA = CategoriaActivo::factory()->create();
    $this->datos['activoA']->update(['nombre' => 'Camisa', 'categoria_id' => $catA->id]);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    // Mismo nombre en destino pero SIN categoría → no es equivalente.
    $otro = Activo::factory()->for($this->datos['empresaB'])->create(['nombre' => 'Camisa', 'categoria_id' => null, 'tipo_control' => TipoControlActivo::Cantidad, 'tipo_activo_id' => null]);
    $otro->tallas()->attach($this->datos['tallaA']);

    ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaB']->id, 'almacen_destino_id' => $this->datos['almacenB']->id,
        'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 10]],
    ]);

    expect(Activo::where('empresa_id', $this->datos['empresaB']->id)->where('nombre', 'Camisa')->count())->toBe(2);
});

it('se detiene ante homologación ambigua (varios candidatos) sin elegir first() y sin aplicar nada', function () {
    $this->datos['activoA']->update(['nombre' => 'Camisa']);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    foreach (range(1, 2) as $_) {
        $c = Activo::factory()->for($this->datos['empresaB'])->create(['nombre' => 'Camisa', 'categoria_id' => null, 'tipo_activo_id' => null, 'tipo_control' => TipoControlActivo::Cantidad]);
        $c->tallas()->attach($this->datos['tallaA']);
    }

    expect(fn () => ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaB']->id, 'almacen_destino_id' => $this->datos['almacenB']->id,
        'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 10]],
    ]))->toThrow(ExcepcionDeNegocio::class);

    expect(TraspasoInventario::count())->toBe(0);
    expect($this->inventario->saldoActual($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(100);
});

it('el activo origen se conserva aunque quede en saldo 0 tras el traspaso', function () {
    $this->datos['activoA']->update(['nombre' => 'Camisa']);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 40);

    ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaB']->id, 'almacen_destino_id' => $this->datos['almacenB']->id,
        'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 40]],
    ]);

    $this->datos['activoA']->refresh();
    expect($this->datos['activoA']->activo)->toBeTrue();
    expect($this->datos['activoA']->trashed())->toBeFalse();
    expect($this->inventario->saldoActual($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(0);
});

// ---------------------------------------------------------------------------
// Unidades identificadas
// ---------------------------------------------------------------------------
it('traspasa una unidad entre almacenes de la misma empresa conservando id, codigo y public_token', function () {
    $activoInd = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Laptop']);
    $unidad = UnidadActivo::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id, 'activo_id' => $activoInd->id, 'almacen_id' => $this->datos['almacenA']->id,
    ]);
    $codigo = $unidad->codigo;
    $token = $unidad->public_token;

    ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
        'renglones' => [['control' => 'individual', 'activo_origen_id' => $activoInd->id, 'unidad_ids' => [$unidad->id]]],
    ]);

    $unidad->refresh();
    expect($unidad->almacen_id)->toBe($this->almacenA2->id);
    expect($unidad->codigo)->toBe($codigo);
    expect($unidad->public_token)->toBe($token);
    expect(UnidadActivo::withTrashed()->count())->toBe(1);
});

it('traspaso interempresa de unidad: misma fila, mismo public_token, contexto destino correcto', function () {
    $activoInd = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Radio']);
    $unidad = UnidadActivo::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id, 'activo_id' => $activoInd->id, 'almacen_id' => $this->datos['almacenA']->id,
    ]);
    $id = $unidad->id;
    $codigo = $unidad->codigo;
    $token = $unidad->public_token;

    ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaB']->id, 'almacen_destino_id' => $this->datos['almacenB']->id,
        'renglones' => [['control' => 'individual', 'activo_origen_id' => $activoInd->id, 'unidad_ids' => [$unidad->id]]],
    ]);

    expect(UnidadActivo::count())->toBe(1);
    $unidad = UnidadActivo::findOrFail($id);
    expect($unidad->public_token)->toBe($token);
    expect($unidad->codigo)->toBe($codigo);
    expect($unidad->empresa_id)->toBe($this->datos['empresaB']->id);
    expect($unidad->almacen_id)->toBe($this->datos['almacenB']->id);
    $destino = Activo::where('empresa_id', $this->datos['empresaB']->id)->where('nombre', 'Radio')->first();
    expect($unidad->activo_id)->toBe($destino->id);
    expect(MovimientoInventario::where('unidad_activo_id', $id)->where('tipo', TipoMovimiento::TraspasoSalida->value)->exists())->toBeTrue();
    expect(MovimientoInventario::where('unidad_activo_id', $id)->where('tipo', TipoMovimiento::TraspasoEntrada->value)->exists())->toBeTrue();
});

it('no permite traspasar una unidad asignada, en reparación o dada de baja', function () {
    $activoInd = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();

    foreach ([
        UnidadActivo::factory()->asignada(),
        UnidadActivo::factory()->conCondicion(CondicionUnidadActivo::EnReparacion),
        UnidadActivo::factory()->baja(),
    ] as $factory) {
        $u = $factory->create(['empresa_id' => $this->datos['empresaA']->id, 'activo_id' => $activoInd->id, 'almacen_id' => $this->datos['almacenA']->id]);

        expect(fn () => ($this->traspasar)([
            'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
            'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
            'renglones' => [['control' => 'individual', 'activo_origen_id' => $activoInd->id, 'unidad_ids' => [$u->id]]],
        ]))->toThrow(ExcepcionDeNegocio::class);
    }
});

// ---------------------------------------------------------------------------
// Múltiple + atomicidad
// ---------------------------------------------------------------------------
it('un traspaso multi-renglón es atómico: si un renglón falla, ninguno se aplica ni deja activos destino fantasma', function () {
    $this->datos['activoA']->update(['nombre' => 'Camisa']);
    $activoB = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Pantalón Nuevo XYZ']);
    $activoB->tallas()->attach($this->datos['tallaA']);

    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $activoB->id, $this->datos['tallaA']->id, 5);

    $antesActivos = Activo::count();

    expect(fn () => ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaB']->id, 'almacen_destino_id' => $this->datos['almacenB']->id,
        'renglones' => [
            ['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 20],
            ['control' => 'cantidad', 'activo_origen_id' => $activoB->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 999], // sin stock
        ],
    ]))->toThrow(ExcepcionDeNegocio::class);

    expect(TraspasoInventario::count())->toBe(0);
    expect(Activo::count())->toBe($antesActivos); // no quedó "Camisa" ni "Pantalón..." fantasma en B
    expect($this->inventario->saldoActual($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(100);
});

// ---------------------------------------------------------------------------
// HTTP + permisos + auditoría
// ---------------------------------------------------------------------------
it('el endpoint registra el traspaso y lo audita nombrando origen y destino', function () {
    $this->datos['activoA']->update(['nombre' => 'Camisa']);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', [
            'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
            'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
            'motivo' => 'Reacomodo',
            'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 25]],
        ])
        ->assertRedirect(route('inventario.movimientos'))
        ->assertSessionHas('toast')
        ->assertSessionHasNoErrors();

    $traspaso = TraspasoInventario::firstOrFail();
    expect($traspaso->folio)->toStartWith('TRA-');
    $entrada = BitacoraAuditoria::where('modulo', 'inventario')->where('accion', 'traspaso')->latest('id')->first();
    expect($entrada)->not->toBeNull();
    expect($entrada->descripcion)->toContain($traspaso->folio);
});

it('el listado de Movimientos expone la referencia legible (folio del traspaso) y datos para las cards', function () {
    $this->datos['activoA']->update(['nombre' => 'Camisa']);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    $traspaso = ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
        'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 10]],
    ]);

    $this->actingAs($this->admin)
        ->get('/inventario/movimientos')
        ->assertInertia(fn ($p) => $p
            ->component('Inventario/Movimientos')
            ->where('movimientos.data', fn ($filas) => collect($filas)->contains(fn ($m) => $m['referencia'] === "Traspaso {$traspaso->folio}"
                && $m['tipo_etiqueta'] !== null
                && $m['activo'] === 'Camisa'
                && $m['direccion'] !== null
                && $m['existencia_anterior'] !== null)));
});

it('el selector de unidad de Traspasos con solo_disponibles=1 excluye asignadas / en reparación / etc. y trae datos descriptivos', function () {
    $activo = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Tablet']);
    $disponible = UnidadActivo::factory()->for($this->datos['empresaA'])->for($activo)->for($this->datos['almacenA'])->create(['observaciones' => 'Negra 10 pulgadas']);
    UnidadActivo::factory()->for($this->datos['empresaA'])->for($activo)->for($this->datos['almacenA'])->create(['estado' => 'asignada']);
    UnidadActivo::factory()->for($this->datos['empresaA'])->for($activo)->for($this->datos['almacenA'])->create(['condicion' => 'en_reparacion']);

    $unidades = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$activo->id}&almacen_id={$this->datos['almacenA']->id}&solo_disponibles=1")
        ->assertOk()
        ->json('unidades');

    expect(collect($unidades)->pluck('id')->all())->toBe([$disponible->id]);
    expect($unidades[0])->toMatchArray([
        'codigo' => $disponible->codigo,
        'activo' => 'Tablet',
        'observaciones' => 'Negra 10 pulgadas',
    ]);
    expect($unidades[0]['estado_visible_etiqueta'])->not->toBeNull();

    // Búsqueda por observaciones y por nombre de activo.
    expect($this->actingAs($this->admin)->getJson("/activos/unidades/buscar?activo_id={$activo->id}&solo_disponibles=1&q=Negra")->json('unidades'))->toHaveCount(1);
    expect($this->actingAs($this->admin)->getJson("/activos/unidades/buscar?activo_id={$activo->id}&solo_disponibles=1&q=Tablet")->json('unidades'))->toHaveCount(1);
});

it('sin solo_disponibles el selector de unidad sigue devolviendo las no entregables marcadas (entregas)', function () {
    $activo = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    UnidadActivo::factory()->for($this->datos['empresaA'])->for($activo)->for($this->datos['almacenA'])->create();
    UnidadActivo::factory()->for($this->datos['empresaA'])->for($activo)->for($this->datos['almacenA'])->create(['condicion' => 'en_reparacion']);

    $unidades = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$activo->id}&almacen_id={$this->datos['almacenA']->id}")
        ->assertOk()->json('unidades');

    expect($unidades)->toHaveCount(2)
        ->and(collect($unidades)->firstWhere('entregable', false)['motivo_no_entregable'])->toBe('En reparación');
});

it('un usuario sin acceso a la empresa destino no puede traspasar hacia ella', function () {
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);
    $supervisor->givePermissionTo('inventario.transferir');
    $this->datos['activoA']->update(['nombre' => 'Camisa']);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    $this->actingAs($supervisor)
        ->from('/inventario/traspasos/crear')
        ->post('/inventario/traspasos', [
            'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
            'empresa_destino_id' => $this->datos['empresaB']->id, 'almacen_destino_id' => $this->datos['almacenB']->id,
            'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 10]],
        ])
        ->assertSessionHasErrors('empresa_destino_id');

    expect(TraspasoInventario::count())->toBe(0);
});

it('sin permiso inventario.transferir el endpoint responde 403', function () {
    $encargado = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaA']]);

    $this->actingAs($encargado)
        ->post('/inventario/traspasos', [
            'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
            'empresa_destino_id' => $this->almacenA2->id, 'almacen_destino_id' => $this->almacenA2->id,
            'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'cantidad' => 1]],
        ])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Stock POR VARIANTE (bug del "40"): el saldo mostrado y prevalidado es el de
// la (empresa+almacén+activo+talla) exacta, nunca el agregado del activo.
// ---------------------------------------------------------------------------
it('activos/buscar devuelve el saldo POR VARIANTE en el almacén, además del agregado', function () {
    $tallaXs = Talla::factory()->create(['valor' => 'XS']);
    $tallaS = Talla::factory()->create(['valor' => 'S']);
    $calcetas = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Calcetas']);
    $calcetas->tallas()->attach([$tallaXs->id, $tallaS->id]);

    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $calcetas->id, $tallaXs->id, 20);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $calcetas->id, $tallaS->id, 20);

    $respuesta = $this->actingAs($this->admin)
        ->getJson("/activos/buscar?empresa_id={$this->datos['empresaA']->id}&almacen_id={$this->datos['almacenA']->id}&control=cantidad&q=Calcetas")
        ->assertOk()
        ->json('activos.0');

    expect($respuesta['disponible'])->toBe(40); // agregado (lo que NO debe pintar la UI tras elegir talla)
    $porTalla = collect($respuesta['tallas'])->keyBy('valor');
    expect($porTalla['XS']['disponible'])->toBe(20)
        ->and($porTalla['S']['disponible'])->toBe(20);
});

it('el Form Request prevalida la cantidad contra el saldo EXACTO de la variante y nombra activo, variante y almacén', function () {
    $tallaXs = Talla::factory()->create(['valor' => 'XS']);
    $tallaS = Talla::factory()->create(['valor' => 'S']);
    $calcetas = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Calcetas']);
    $calcetas->tallas()->attach([$tallaXs->id, $tallaS->id]);

    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $calcetas->id, $tallaXs->id, 20);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $calcetas->id, $tallaS->id, 20);

    // El frontend "dejó pasar" cantidad 21 de la variante XS (saldo real 20).
    $respuesta = $this->actingAs($this->admin)
        ->from('/inventario/traspasos/crear')
        ->post('/inventario/traspasos', [
            'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
            'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
            'renglones' => [[
                'control' => 'cantidad', 'activo_origen_id' => $calcetas->id,
                'talla_id' => $tallaXs->id, 'cantidad' => 21,
            ]],
        ])
        ->assertSessionHasErrors('renglones.0.cantidad');

    $mensaje = session('errors')->get('renglones.0.cantidad')[0];
    expect($mensaje)->toContain('Calcetas')
        ->and($mensaje)->toContain('XS')
        ->and($mensaje)->toContain('Almacén A')
        ->and($mensaje)->toContain('20');

    expect(TraspasoInventario::count())->toBe(0);
});

it('el mensaje de concurrencia reutiliza los datos de la excepción y no dice "Error al registrar traspaso"', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 20);

    $hacer = fn (int $cantidad) => ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
        'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => $cantidad]],
    ]);

    $hacer(15); // deja 5 en origen

    try {
        $hacer(15); // ahora sólo hay 5: debe fallar con el wording de concurrencia
        $this->fail('Se esperaba ExcepcionDeNegocio por stock insuficiente.');
    } catch (ExcepcionDeNegocio $e) {
        expect($e->getMessage())->toStartWith('El stock disponible cambió.')
            ->and($e->getMessage())->toContain('Camisa')
            ->and($e->getMessage())->toContain('Almacén A')
            ->and($e->getMessage())->toContain('5');
    }

    // Rollback total del segundo intento.
    expect($this->inventario->saldoActual($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(5)
        ->and(TraspasoInventario::count())->toBe(1);
});
