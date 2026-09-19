<?php

use App\Acciones\MarcarCondicionInventario;
use App\Acciones\MarcarUnidadIncidencia;
use App\Acciones\RegistrarEntradaInventario;
use App\Acciones\RestaurarCondicionInventario;
use App\Enums\CondicionDevolucion;
use App\Enums\CondicionUnidadActivo;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\MovimientoInventario;
use App\Models\UnidadActivo;

/**
 * Bug real (QA manual): marcar un activo POR CANTIDAD como "Dañado" generaba
 * un `MovimientoInventario` que se mostraba como "Pérdida / robo" en el
 * historial — porque `MarcarCondicionInventario` reutiliza (correctamente)
 * `TipoMovimiento::Incidencia`, el mismo que usa `MarcarUnidadIncidencia`
 * para una pérdida/robo REAL de una `UnidadActivo`. `MovimientoInventario::
 * etiquetaEfectiva()` distingue ambos casos de forma ESTRUCTURAL —nunca por
 * texto libre de `motivo`—: sólo el cambio de condición de inventario deja
 * una fila en `condiciones_inventario` enlazada al movimiento. La pérdida o
 * robo real de una unidad conserva su etiqueta exacta: nunca se toca.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    app(RegistrarEntradaInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5]],
        'Alta inicial', null,
    );
});

it('marcar un activo por cantidad como dañado NUNCA se muestra como "Pérdida / robo"', function () {
    $registro = app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Danado, 2, 'Se detectó humedad', null,
    );

    $movimiento = MovimientoInventario::query()->whereKey($registro->movimiento_inventario_id)->firstOrFail();

    expect($movimiento->etiquetaEfectiva())->toBe('Marcado como dañado')
        ->and($movimiento->etiquetaEfectiva())->not->toBe('Pérdida / robo');
});

it('dar de baja un activo por cantidad se muestra como "Baja", nunca "Pérdida / robo"', function () {
    $registro = app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Baja, 1, 'Irrecuperable', null,
    );

    $movimiento = MovimientoInventario::query()->whereKey($registro->movimiento_inventario_id)->firstOrFail();

    expect($movimiento->etiquetaEfectiva())->toBe('Baja');
});

it('restaurar piezas dañadas se muestra como restauración, no como una entrada nueva', function () {
    app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Danado, 2, 'Se detectó humedad', null,
    );

    $registro = app(RestaurarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        1, 'Se reparó', null,
    );

    $movimiento = MovimientoInventario::query()->whereKey($registro->movimiento_inventario_id)->firstOrFail();

    expect($movimiento->etiquetaEfectiva())->toBe('Restauración de piezas dañadas')
        ->and($movimiento->etiquetaEfectiva())->not->toBe('Entrada');
});

it('una pérdida/robo real de una unidad conserva exactamente su clasificación de "Pérdida / robo"', function () {
    $activoUnidad = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoUnidad)->for($this->datos['almacenA'])->asignada()->create();

    app(MarcarUnidadIncidencia::class)->ejecutar($unidad, CondicionUnidadActivo::Robado, 'Reportado por el colaborador', null, $this->admin->id);

    $movimiento = MovimientoInventario::query()
        ->where('unidad_activo_id', $unidad->id)
        ->where('tipo', 'incidencia')
        ->firstOrFail();

    expect($movimiento->etiquetaEfectiva())->toBe('Pérdida / robo');
});

it('el detalle del movimiento HTTP expone la etiqueta corregida, no la genérica del tipo', function () {
    $registro = app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Danado, 2, 'Se detectó humedad', null,
    );
    $movimiento = MovimientoInventario::query()->whereKey($registro->movimiento_inventario_id)->firstOrFail();

    $this->actingAs($this->admin)
        ->get("/inventario/movimientos/{$movimiento->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('movimiento.tipo', 'incidencia')
            ->where('movimiento.tipo_etiqueta', 'Marcado como dañado')
            ->where('movimiento.activo_codigo', $this->datos['activoA']->codigo));
});

it('el listado de movimientos HTTP también expone la etiqueta corregida', function () {
    app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Danado, 2, 'Se detectó humedad', null,
    );

    $this->actingAs($this->admin)
        ->get('/inventario/movimientos?empresa_id='.$this->datos['empresaA']->id.'&tipo=incidencia')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('movimientos.data', 1)
            ->where('movimientos.data.0.tipo_etiqueta', 'Marcado como dañado'));
});
