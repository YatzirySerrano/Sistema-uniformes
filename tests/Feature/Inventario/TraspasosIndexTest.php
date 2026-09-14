<?php

use App\Acciones\RegistrarTraspasoInventario;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\TraspasoInventario;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;

/**
 * "Traspasos de inventario" (`/inventario/traspasos`, controller
 * `indexTraspasos()`) es la pantalla canónica de cara al usuario: consulta
 * `TraspasoInventario` como entidad raíz — NUNCA se reconstruye agrupando
 * `movimientos_inventario` — así que nunca mezcla Entregas/Devoluciones/
 * entradas técnicas, y cada traspaso aparece EXACTAMENTE una vez (nunca como
 * dos tarjetas "salida"/"entrada"). El historial técnico completo sigue
 * intacto en `/inventario/movimientos`.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);
    $this->inventario = app(ServicioInventario::class);

    $this->cargarStock = function (int $empresaId, int $almacenId, int $activoId, ?int $tallaId, int $cantidad): void {
        $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
            empresaId: $empresaId, almacenId: $almacenId, activoId: $activoId, tallaId: $tallaId,
            tipo: TipoMovimiento::Inicial, cantidad: $cantidad,
        ));
    };

    $this->traspasar = fn (array $overrides = []): TraspasoInventario => app(RegistrarTraspasoInventario::class)->ejecutar(
        $overrides['empresa_origen_id'] ?? $this->datos['empresaA']->id,
        $overrides['almacen_origen_id'] ?? $this->datos['almacenA']->id,
        $overrides['empresa_destino_id'] ?? $this->datos['empresaB']->id,
        $overrides['almacen_destino_id'] ?? $this->datos['almacenB']->id,
        $overrides['renglones'] ?? [[
            'control' => 'cantidad',
            'activo_origen_id' => $this->datos['activoA']->id,
            'talla_id' => $this->datos['tallaA']->id,
            'cantidad' => 3,
        ]],
        $this->admin->id,
        $overrides['motivo'] ?? null,
    );
});

it('lista SÓLO traspasos: crear una Entrega/entrada/otro movimiento técnico no los agrega al listado', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);
    $traspaso = ($this->traspasar)();

    // Movimientos técnicos que NO deben aparecer en /inventario/traspasos.
    MovimientoInventario::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'tipo' => TipoMovimiento::Entrada,
    ]);
    MovimientoInventario::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'tipo' => TipoMovimiento::Entrega,
    ]);
    MovimientoInventario::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'tipo' => TipoMovimiento::Devolucion,
    ]);

    $this->actingAs($this->admin)->get('/inventario/traspasos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventario/Traspasos/Index')
            ->where('traspasos.total', 1)
            ->where('traspasos.data.0.id', $traspaso->id)
            ->where('traspasos.data.0.folio', $traspaso->folio));
});

it('cada traspaso aparece EXACTAMENTE una vez aunque tenga varios renglones (dos movimientos correlacionados)', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);
    $otroActivo = Activo::factory()->for($this->datos['empresaA'])->create();
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $otroActivo->id, null, 50);

    $traspaso = ($this->traspasar)([
        'renglones' => [
            ['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2],
            ['control' => 'cantidad', 'activo_origen_id' => $otroActivo->id, 'talla_id' => null, 'cantidad' => 5],
        ],
    ]);

    // Dos renglones = cuatro movimientos reales (salida+entrada por renglón)
    // en el ledger técnico, pero UNA sola fila en el listado de traspasos.
    expect(MovimientoInventario::query()->where('referencia_id', $traspaso->id)->where('referencia_tipo', TraspasoInventario::class)->count())->toBe(4);

    $this->actingAs($this->admin)->get('/inventario/traspasos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('traspasos.total', 1)
            ->where('traspasos.data.0.renglones', 2)
            ->where('traspasos.data.0.unidades', 7));
});

it('el detalle enlazado desde el listado va DIRECTO a Traspaso Detalle (nunca a un Movimiento suelto)', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);
    $traspaso = ($this->traspasar)();

    $this->actingAs($this->admin)->get("/inventario/traspasos/{$traspaso->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventario/Traspasos/Detalle')
            ->where('traspaso.folio', $traspaso->folio)
            ->has('traspaso.renglones', 1));
});

it('filtra por empresa y por almacén respetando ambos lados del traspaso (origen o destino)', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);
    $traspaso = ($this->traspasar)();

    // Filtrar por la empresa DESTINO también debe encontrarlo.
    $this->actingAs($this->admin)->get("/inventario/traspasos?empresa_id={$this->datos['empresaB']->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('traspasos.total', 1));

    // Filtrar por el almacén DESTINO también debe encontrarlo.
    $this->actingAs($this->admin)->get("/inventario/traspasos?almacen_id={$this->datos['almacenB']->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('traspasos.total', 1));

    // Una empresa ajena no debe encontrarlo.
    $empresaAjena = Empresa::factory()->create();
    $this->actingAs($this->admin)->get("/inventario/traspasos?empresa_id={$empresaAjena->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('traspasos.total', 0));
});

it('sin el permiso inventario.ver, el listado responde 403', function () {
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);

    $this->actingAs($sinPermiso)->get('/inventario/traspasos')->assertForbidden();
});

it('acceso por EMPRESA: un usuario con acceso a UNA sola de las dos empresas (origen o destino) SÍ ve el traspaso en el listado', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);
    $traspaso = ($this->traspasar)();

    $soloOrigen = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);
    $soloDestino = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);
    $ajeno = usuarioCon(RolSistema::Supervisor->value, [Empresa::factory()->create()]);

    $this->actingAs($soloOrigen)->get('/inventario/traspasos')
        ->assertInertia(fn ($page) => $page->where('traspasos.total', 1)->where('traspasos.data.0.id', $traspaso->id));

    $this->actingAs($soloDestino)->get('/inventario/traspasos')
        ->assertInertia(fn ($page) => $page->where('traspasos.total', 1)->where('traspasos.data.0.id', $traspaso->id));

    $this->actingAs($ajeno)->get('/inventario/traspasos')
        ->assertInertia(fn ($page) => $page->where('traspasos.total', 0));
});
