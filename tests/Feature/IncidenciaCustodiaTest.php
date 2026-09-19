<?php

use App\Acciones\RegistrarIncidenciaCustodia;
use App\Enums\EstadoEntrega;
use App\Enums\RolSistema;
use App\Enums\TipoIncidenciaCustodia;
use App\Excepciones\ExcepcionDeNegocio;
use App\Models\Activo;
use App\Models\BitacoraAuditoria;
use App\Models\Colaborador;
use App\Models\DetalleEntrega;
use App\Models\EntregaUniforme;
use App\Models\IncidenciaCustodia;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Servicios\ServicioCustodiaColaborador;

/**
 * Robo / pérdida de activos POR CANTIDAD bajo custodia de un colaborador
 * (nunca almacén: las piezas ya habían salido desde la entrega). Ver
 * `App\Acciones\RegistrarIncidenciaCustodia` y
 * `App\Servicios\ServicioCustodiaColaborador::pendientesPorDetalle()`.
 *
 * Caso de referencia: 5 camisas entregadas, se reportan 2 robadas ->
 * pendiente de devolución baja a 3, "robadas" queda en 2, y el almacén
 * NUNCA se toca (esas piezas ya habían salido desde la entrega).
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    // Saldo inicial del almacén: representa lo que quedó DESPUÉS de la
    // entrega (nunca se modifica por la incidencia).
    $this->saldo = SaldoInventario::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'cantidad' => 10,
    ]);

    $this->entrega = EntregaUniforme::factory()
        ->for($this->datos['empresaA'])
        ->for($this->datos['sucursalA'])
        ->for($this->datos['colaboradorA'])
        ->create(['estado' => EstadoEntrega::Firmada, 'almacen_id' => $this->datos['almacenA']->id]);

    $this->detalle = DetalleEntrega::factory()->for($this->entrega, 'entrega')->create([
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'unidad_activo_id' => null,
        'cantidad' => 5,
        'activo_nombre_snapshot' => $this->datos['activoA']->nombre,
        'talla_valor_snapshot' => $this->datos['tallaA']->valor,
    ]);
});

it('registrar 2 robadas de 5 bajo custodia NO descuenta el almacén', function () {
    $cantidadAlmacenAntes = SaldoInventario::query()->whereKey($this->saldo->id)->value('cantidad');

    app(RegistrarIncidenciaCustodia::class)->ejecutar(
        $this->datos['colaboradorA'], $this->detalle->id, TipoIncidenciaCustodia::Robado, 2, 'Reportado por el colaborador', null, null,
    );

    expect(SaldoInventario::query()->whereKey($this->saldo->id)->value('cantidad'))->toBe($cantidadAlmacenAntes);
});

it('la custodia pendiente pasa correctamente de 5 a 3 tras reportar 2 robadas', function () {
    $pendienteAntes = app(ServicioCustodiaColaborador::class)->pendienteDeDetalle($this->detalle);
    expect($pendienteAntes)->toBe(5);

    app(RegistrarIncidenciaCustodia::class)->ejecutar(
        $this->datos['colaboradorA'], $this->detalle->id, TipoIncidenciaCustodia::Robado, 2, 'Motivo', null, null,
    );

    expect(app(ServicioCustodiaColaborador::class)->pendienteDeDetalle($this->detalle))->toBe(3);
});

it('registra la incidencia con tipo, cantidad, colaborador, entrega origen y variante correctos', function () {
    $registro = app(RegistrarIncidenciaCustodia::class)->ejecutar(
        $this->datos['colaboradorA'], $this->detalle->id, TipoIncidenciaCustodia::Robado, 2, 'Motivo', 'Observación libre', $this->admin->id,
    );

    expect($registro->tipo)->toBe(TipoIncidenciaCustodia::Robado)
        ->and($registro->cantidad)->toBe(2)
        ->and($registro->colaborador_id)->toBe($this->datos['colaboradorA']->id)
        ->and($registro->entrega_uniforme_id)->toBe($this->entrega->id)
        ->and($registro->detalle_entrega_id)->toBe($this->detalle->id)
        ->and($registro->activo_id)->toBe($this->datos['activoA']->id)
        ->and($registro->talla_id)->toBe($this->datos['tallaA']->id)
        ->and($registro->empresa_id)->toBe($this->datos['empresaA']->id)
        ->and($registro->motivo)->toBe('Motivo')
        ->and($registro->observacion)->toBe('Observación libre')
        ->and($registro->registrado_por)->toBe($this->admin->id);
});

it('no permite reportar más piezas de las pendientes de devolución', function () {
    expect(fn () => app(RegistrarIncidenciaCustodia::class)->ejecutar(
        $this->datos['colaboradorA'], $this->detalle->id, TipoIncidenciaCustodia::Robado, 6, 'Motivo', null, null,
    ))->toThrow(ExcepcionDeNegocio::class);

    expect(app(ServicioCustodiaColaborador::class)->pendienteDeDetalle($this->detalle))->toBe(5)
        ->and(IncidenciaCustodia::count())->toBe(0);
});

it('reportar el resto acumulado tampoco puede exceder lo pendiente (2 + 4 > 5)', function () {
    app(RegistrarIncidenciaCustodia::class)->ejecutar(
        $this->datos['colaboradorA'], $this->detalle->id, TipoIncidenciaCustodia::Robado, 2, 'Primer reporte', null, null,
    );

    expect(fn () => app(RegistrarIncidenciaCustodia::class)->ejecutar(
        $this->datos['colaboradorA'], $this->detalle->id, TipoIncidenciaCustodia::Perdido, 4, 'Segundo reporte', null, null,
    ))->toThrow(ExcepcionDeNegocio::class);

    expect(app(ServicioCustodiaColaborador::class)->pendienteDeDetalle($this->detalle))->toBe(3);
});

it('rechaza un detalle de entrega que no pertenece a este colaborador', function () {
    $otroColaborador = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();

    expect(fn () => app(RegistrarIncidenciaCustodia::class)->ejecutar(
        $otroColaborador, $this->detalle->id, TipoIncidenciaCustodia::Robado, 1, 'Motivo', null, null,
    ))->toThrow(ExcepcionDeNegocio::class);

    expect(IncidenciaCustodia::count())->toBe(0);
});

it('rechaza un renglón que corresponde a una unidad identificada (no a cantidad)', function () {
    $activoInd = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoInd)->for($this->datos['almacenA'])->asignada()->create([
        'colaborador_id' => $this->datos['colaboradorA']->id,
    ]);
    $detalleUnidad = DetalleEntrega::factory()->for($this->entrega, 'entrega')->create([
        'activo_id' => $activoInd->id,
        'talla_id' => null,
        'unidad_activo_id' => $unidad->id,
        'cantidad' => 1,
        'activo_nombre_snapshot' => $activoInd->nombre,
    ]);

    expect(fn () => app(RegistrarIncidenciaCustodia::class)->ejecutar(
        $this->datos['colaboradorA'], $detalleUnidad->id, TipoIncidenciaCustodia::Robado, 1, 'Motivo', null, null,
    ))->toThrow(ExcepcionDeNegocio::class);
});

it('exige motivo y cantidad positiva', function () {
    expect(fn () => app(RegistrarIncidenciaCustodia::class)->ejecutar(
        $this->datos['colaboradorA'], $this->detalle->id, TipoIncidenciaCustodia::Robado, 1, '   ', null, null,
    ))->toThrow(ExcepcionDeNegocio::class);

    expect(fn () => app(RegistrarIncidenciaCustodia::class)->ejecutar(
        $this->datos['colaboradorA'], $this->detalle->id, TipoIncidenciaCustodia::Robado, 0, 'Motivo válido', null, null,
    ))->toThrow(ExcepcionDeNegocio::class);

    expect(IncidenciaCustodia::count())->toBe(0);
});

it('registra en auditoría de forma legible', function () {
    app(RegistrarIncidenciaCustodia::class)->ejecutar(
        $this->datos['colaboradorA'], $this->detalle->id, TipoIncidenciaCustodia::Robado, 2, 'Motivo detallado', null, null,
    );

    $registro = BitacoraAuditoria::query()->where('modulo', 'custodia')->where('accion', 'incidencia_registrar')->latest('id')->first();

    expect($registro)->not->toBeNull()
        ->and($registro->descripcion)->toContain($this->datos['colaboradorA']->nombre_completo)
        ->and($registro->descripcion)->toContain('Robado')
        ->and($registro->descripcion)->toContain('Motivo detallado')
        ->and($registro->motivo)->toBe('Motivo detallado');
});

it('el "Perdido" sigue el mismo principio que "Robado"', function () {
    app(RegistrarIncidenciaCustodia::class)->ejecutar(
        $this->datos['colaboradorA'], $this->detalle->id, TipoIncidenciaCustodia::Perdido, 1, 'Se perdió', null, null,
    );

    expect(app(ServicioCustodiaColaborador::class)->pendienteDeDetalle($this->detalle))->toBe(4)
        ->and(SaldoInventario::query()->whereKey($this->saldo->id)->value('cantidad'))->toBe(10);
});

// ------------------------------------------------------------------
// HTTP
// ------------------------------------------------------------------

it('el endpoint HTTP exige el permiso devoluciones.crear', function () {
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);

    $this->actingAs($sinPermiso)
        ->post("/colaboradores/{$this->datos['colaboradorA']->id}/custodia/incidencia", [
            'detalle_entrega_id' => $this->detalle->id,
            'tipo' => 'robado',
            'cantidad' => 1,
            'motivo' => 'Motivo',
        ])
        ->assertForbidden();

    expect(IncidenciaCustodia::count())->toBe(0);
});

it('un reporte HTTP válido actualiza el KPI "Activos asignados" del colaborador', function () {
    $antes = app(ServicioCustodiaColaborador::class)->totalPiezasPendientes($this->datos['colaboradorA']);
    expect($antes)->toBe(5);

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$this->datos['colaboradorA']->id}/custodia/incidencia", [
            'detalle_entrega_id' => $this->detalle->id,
            'tipo' => 'robado',
            'cantidad' => 2,
            'motivo' => 'Reportado por el colaborador',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(app(ServicioCustodiaColaborador::class)->totalPiezasPendientes($this->datos['colaboradorA']))->toBe(3);
});

it('el KPI y el panel de custodia del perfil reflejan la incidencia reportada', function () {
    $this->actingAs($this->admin)->post("/colaboradores/{$this->datos['colaboradorA']->id}/custodia/incidencia", [
        'detalle_entrega_id' => $this->detalle->id,
        'tipo' => 'robado',
        'cantidad' => 2,
        'motivo' => 'Reportado por el colaborador',
    ]);

    $this->actingAs($this->admin)
        ->get("/colaboradores/{$this->datos['colaboradorA']->id}")
        ->assertInertia(fn ($page) => $page
            ->where('kpis.activos_asignados', 3)
            ->where('custodia.incidencias.0.cantidad', 2)
            ->where('custodia.incidencias.0.tipo', 'robado')
        );
});
