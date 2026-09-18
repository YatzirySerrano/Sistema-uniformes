<?php

use App\Acciones\MarcarCondicionInventario;
use App\Acciones\RegistrarEntradaInventario;
use App\Acciones\RestaurarCondicionInventario;
use App\Enums\CondicionDevolucion;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocio;
use App\Excepciones\ExistenciasInsuficientesException;
use App\Models\BitacoraAuditoria;
use App\Models\CondicionInventario;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Servicios\ServicioEstadoInventario;

/**
 * Condición física de existencias POR CANTIDAD (Dañado / Baja), marcada
 * directamente desde el stock disponible — nunca desde una Devolución. Ver
 * `App\Acciones\{MarcarCondicionInventario,RestaurarCondicionInventario}` y
 * `App\Servicios\ServicioEstadoInventario`.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();

    app(RegistrarEntradaInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5]],
        'Alta inicial', null,
    );
});

it('marcar piezas como dañadas resta de disponible y queda persistido y trazable', function () {
    $registro = app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Danado, 2, 'Se detectó humedad', null,
    );

    expect(SaldoInventario::query()->where('almacen_id', $this->datos['almacenA']->id)->value('cantidad'))->toBe(3)
        ->and($registro->condicion)->toBe(CondicionDevolucion::Danado)
        ->and($registro->tipo)->toBe(TipoMovimiento::Incidencia)
        ->and($registro->cantidad)->toBe(2)
        ->and($registro->motivo)->toBe('Se detectó humedad')
        ->and(CondicionInventario::count())->toBe(1)
        ->and($registro->movimiento_inventario_id)->not->toBeNull();
});

it('dar de baja resta de disponible y NO puede restaurarse', function () {
    app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Baja, 2, 'Irrecuperable', null,
    );

    expect(SaldoInventario::query()->where('almacen_id', $this->datos['almacenA']->id)->value('cantidad'))->toBe(3);

    // Baja es terminal: no hay "dañadas" que restaurar aunque se acabe de
    // dar de baja algo (la restauración sólo opera sobre el balde de
    // "dañado", nunca sobre "baja").
    expect(fn () => app(RestaurarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        1, 'Intento inválido', null,
    ))->toThrow(ExcepcionDeNegocio::class);

    expect(SaldoInventario::query()->where('almacen_id', $this->datos['almacenA']->id)->value('cantidad'))->toBe(3);
});

it('no permite marcar más piezas de las disponibles', function () {
    expect(fn () => app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Danado, 999, 'Motivo', null,
    ))->toThrow(ExistenciasInsuficientesException::class);

    // Rollback total: ni el saldo ni el registro de condición se tocan.
    expect(SaldoInventario::query()->where('almacen_id', $this->datos['almacenA']->id)->value('cantidad'))->toBe(5)
        ->and(CondicionInventario::count())->toBe(0);
});

it('exige motivo y cantidad positiva', function () {
    expect(fn () => app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Danado, 1, '   ', null,
    ))->toThrow(ExcepcionDeNegocio::class);

    expect(fn () => app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Danado, 0, 'Motivo válido', null,
    ))->toThrow(ExcepcionDeNegocio::class);

    expect(CondicionInventario::count())->toBe(0);
});

it('rechaza marcar condición "reutilizable" (no es un destino válido de esta acción)', function () {
    expect(fn () => app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Reutilizable, 1, 'Motivo', null,
    ))->toThrow(ExcepcionDeNegocio::class);
});

it('rechaza una variante que no corresponde al activo', function () {
    $tallaAjena = Talla::factory()->create();

    expect(fn () => app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $tallaAjena->id,
        CondicionDevolucion::Danado, 1, 'Motivo', null,
    ))->toThrow(ExcepcionDeNegocio::class);
});

it('rechaza un almacén que no abastece a la empresa', function () {
    expect(fn () => app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenB']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Danado, 1, 'Motivo', null,
    ))->toThrow(ExcepcionDeNegocio::class);
});

it('restaura piezas dañadas de vuelta a disponible, sin exceder lo marcado', function () {
    app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Danado, 3, 'Se detectó humedad', null,
    );
    expect(SaldoInventario::query()->where('almacen_id', $this->datos['almacenA']->id)->value('cantidad'))->toBe(2);

    // No se puede restaurar más de lo dañado.
    expect(fn () => app(RestaurarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        4, 'Se reparó', null,
    ))->toThrow(ExcepcionDeNegocio::class);
    expect(SaldoInventario::query()->where('almacen_id', $this->datos['almacenA']->id)->value('cantidad'))->toBe(2);

    // Restaurar 2 de las 3 dañadas sí es válido.
    $registro = app(RestaurarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        2, 'Se repararon', null,
    );

    expect(SaldoInventario::query()->where('almacen_id', $this->datos['almacenA']->id)->value('cantidad'))->toBe(4)
        ->and($registro->tipo)->toBe(TipoMovimiento::Recuperacion);

    // Sólo queda 1 dañada disponible para restaurar; pedir 2 más falla.
    expect(fn () => app(RestaurarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        2, 'Exceso', null,
    ))->toThrow(ExcepcionDeNegocio::class);
});

it('la auditoría de marcar y restaurar condición es completa y legible', function () {
    app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Danado, 2, 'Diferencia detectada en inventario físico', null,
    );

    $marcar = BitacoraAuditoria::query()->where('modulo', 'inventario')->where('accion', 'condicion_marcar')->latest('id')->first();
    expect($marcar)->not->toBeNull()
        ->and($marcar->descripcion)->toContain($this->datos['activoA']->nombre)
        ->and($marcar->descripcion)->toContain($this->datos['almacenA']->nombre)
        ->and($marcar->descripcion)->toContain('Dañado')
        ->and($marcar->descripcion)->toContain('Diferencia detectada en inventario físico')
        ->and($marcar->motivo)->toBe('Diferencia detectada en inventario físico');

    app(RestaurarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        1, 'Se reparó', null,
    );

    $restaurar = BitacoraAuditoria::query()->where('modulo', 'inventario')->where('accion', 'condicion_restaurar')->latest('id')->first();
    expect($restaurar)->not->toBeNull()
        ->and($restaurar->descripcion)->toContain('restauradas')
        ->and($restaurar->descripcion)->toContain('Se reparó');
});

it('los KPIs "Existencias por estado" reflejan la condición marcada directamente, sumada a la de devoluciones', function () {
    app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Danado, 2, 'Motivo', null,
    );
    app(MarcarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        CondicionDevolucion::Baja, 1, 'Motivo', null,
    );

    $estado = app(ServicioEstadoInventario::class)->porActivo($this->datos['activoA']->fresh());

    expect($estado['resumen']['disponible'])->toBe(2)
        ->and($estado['resumen']['danado'])->toBe(2)
        ->and($estado['resumen']['baja'])->toBe(1)
        ->and($estado['resumen']['asignado'])->toBe(0);

    app(RestaurarCondicionInventario::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['activoA']->id, $this->datos['tallaA']->id,
        1, 'Se reparó', null,
    );

    $estadoTrasRestaurar = app(ServicioEstadoInventario::class)->porActivo($this->datos['activoA']->fresh());
    expect($estadoTrasRestaurar['resumen']['disponible'])->toBe(3)
        ->and($estadoTrasRestaurar['resumen']['danado'])->toBe(1)
        ->and($estadoTrasRestaurar['resumen']['baja'])->toBe(1);
});

// ------------------------------------------------------------------
// HTTP
// ------------------------------------------------------------------

it('el endpoint HTTP de marcar condición exige el permiso inventario.ajustar', function () {
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);

    $this->actingAs($sinPermiso)
        ->post('/inventario/condicion', [
            'empresa_id' => $this->datos['empresaA']->id,
            'almacen_id' => $this->datos['almacenA']->id,
            'activo_id' => $this->datos['activoA']->id,
            'talla_id' => $this->datos['tallaA']->id,
            'condicion' => 'danado',
            'cantidad' => 1,
            'motivo' => 'Motivo',
        ])
        ->assertForbidden();

    expect(CondicionInventario::count())->toBe(0);
});

it('un ajuste de condición por HTTP registra el movimiento y responde con éxito', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $this->actingAs($admin)
        ->post('/inventario/condicion', [
            'empresa_id' => $this->datos['empresaA']->id,
            'almacen_id' => $this->datos['almacenA']->id,
            'activo_id' => $this->datos['activoA']->id,
            'talla_id' => $this->datos['tallaA']->id,
            'condicion' => 'danado',
            'cantidad' => 2,
            'motivo' => 'Conteo físico',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(SaldoInventario::query()->where('almacen_id', $this->datos['almacenA']->id)->value('cantidad'))->toBe(3)
        ->and(CondicionInventario::count())->toBe(1);
});

it('el endpoint HTTP rechaza un almacén que no abastece a la empresa', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $this->actingAs($admin)
        ->post('/inventario/condicion', [
            'empresa_id' => $this->datos['empresaA']->id,
            'almacen_id' => $this->datos['almacenB']->id,
            'activo_id' => $this->datos['activoA']->id,
            'talla_id' => $this->datos['tallaA']->id,
            'condicion' => 'danado',
            'cantidad' => 1,
            'motivo' => 'Motivo',
        ])
        ->assertSessionHasErrors('almacen_id');

    expect(CondicionInventario::count())->toBe(0);
});
