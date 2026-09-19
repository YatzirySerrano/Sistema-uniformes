<?php

use App\Acciones\ConfirmarAcuseDevolucion;
use App\Acciones\ConfirmarAcuseRecepcion;
use App\Acciones\CrearEntregaUniforme;
use App\Acciones\RegistrarDevolucion;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Talla;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioEstadoInventario;
use App\Servicios\ServicioInventario;

/**
 * Estado ACTUAL de un activo por cantidad (Disponible / Asignado / Dañado /
 * Baja). Cubre los 10 casos funcionales acordados antes de implementar:
 * Disponible = `SaldoInventario`; Asignado = entregado (firmada/corregida) −
 * devuelto (cualquier condición, confirmada); Dañado/Baja = histórico de
 * `detalles_devolucion.condicion` en devoluciones confirmadas.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $this->inventario = app(ServicioInventario::class);
    $this->estado = app(ServicioEstadoInventario::class);

    $this->cargarStock = function (int $empresaId, int $almacenId, int $activoId, ?int $tallaId, int $cantidad): void {
        $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
            empresaId: $empresaId, almacenId: $almacenId, activoId: $activoId, tallaId: $tallaId,
            tipo: TipoMovimiento::Inicial, cantidad: $cantidad,
        ));
    };

    // Entrega YA FIRMADA (sólo así cuenta como "Asignado" — una entrega
    // pendiente de firma todavía no está en posesión del colaborador).
    $this->entregarFirmada = function (int $cantidad, ?int $tallaId, ?int $almacenId = null) {
        $entrega = app(CrearEntregaUniforme::class)->ejecutar(
            $this->datos['colaboradorA']->id,
            $almacenId ?? $this->datos['almacenA']->id,
            $this->admin->id,
            now()->toDateString(),
            [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $tallaId, 'cantidad' => $cantidad]],
            [],
            [],
        );
        app(ConfirmarAcuseRecepcion::class)->ejecutar($entrega, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

        return $entrega->fresh();
    };

    // Devolución CONFIRMADA (sólo así reingresa/afecta el estado — una
    // devolución pendiente de firma no debe moverse todavía).
    $this->devolverConfirmada = function (int $detalleEntregaId, int $cantidad, string $condicion, ?int $almacenId = null) {
        $devolucion = app(RegistrarDevolucion::class)->ejecutar(
            $this->entregaActual->id,
            $almacenId ?? $this->datos['almacenA']->id,
            now()->toDateString(),
            [['detalle_entrega_id' => $detalleEntregaId, 'cantidad' => $cantidad, 'condicion' => $condicion]],
            [],
            $this->admin->id,
        );
        app(ConfirmarAcuseDevolucion::class)->ejecutar($devolucion, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

        return $devolucion->fresh();
    };
});

it('CASO 1: una entrada de 20 deja todo disponible, sin asignado/dañado/baja', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 20);

    $resultado = $this->estado->porActivo($this->datos['activoA']);

    expect($resultado['resumen'])->toBe(['disponible' => 20, 'asignado' => 0, 'danado' => 0, 'baja' => 0, 'robo_extravio' => 0]);
});

it('CASO 2: entregar 5 (firmada) mueve 5 de disponible a asignado', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 20);
    ($this->entregarFirmada)(5, $this->datos['tallaA']->id);

    $resultado = $this->estado->porActivo($this->datos['activoA']);

    expect($resultado['resumen'])->toBe(['disponible' => 15, 'asignado' => 5, 'danado' => 0, 'baja' => 0, 'robo_extravio' => 0]);
});

it('CASO 3: devolver 1 reutilizable (confirmada) regresa a disponible y libera asignado', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 20);
    $this->entregaActual = ($this->entregarFirmada)(5, $this->datos['tallaA']->id);
    $detalle = $this->entregaActual->detalles->first();

    ($this->devolverConfirmada)($detalle->id, 1, 'reutilizable');

    $resultado = $this->estado->porActivo($this->datos['activoA']);

    expect($resultado['resumen'])->toBe(['disponible' => 16, 'asignado' => 4, 'danado' => 0, 'baja' => 0, 'robo_extravio' => 0]);
});

it('CASO 4: entrada 20, entregar 5, devolver 1 dañada -> disponible 15, asignado 4, dañado 1', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 20);
    $this->entregaActual = ($this->entregarFirmada)(5, $this->datos['tallaA']->id);
    $detalle = $this->entregaActual->detalles->first();

    ($this->devolverConfirmada)($detalle->id, 1, 'danado');

    $resultado = $this->estado->porActivo($this->datos['activoA']);

    expect($resultado['resumen'])->toBe(['disponible' => 15, 'asignado' => 4, 'danado' => 1, 'baja' => 0, 'robo_extravio' => 0]);
});

it('CASO 5: una devolución de baja no reingresa a disponible y sí incrementa baja', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 20);
    $this->entregaActual = ($this->entregarFirmada)(5, $this->datos['tallaA']->id);
    $detalle = $this->entregaActual->detalles->first();

    ($this->devolverConfirmada)($detalle->id, 2, 'baja');

    $resultado = $this->estado->porActivo($this->datos['activoA']);

    expect($resultado['resumen'])->toBe(['disponible' => 15, 'asignado' => 3, 'danado' => 0, 'baja' => 2, 'robo_extravio' => 0]);
});

it('CASO 6: operar sobre la talla S no altera la talla M', function () {
    $tallaS = Talla::factory()->create(['valor' => 'S']);
    $this->datos['activoA']->tallas()->attach($tallaS);

    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 20);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $tallaS->id, 10);
    ($this->entregarFirmada)(5, $tallaS->id);

    $resultado = $this->estado->porActivo($this->datos['activoA']);
    $porTalla = collect($resultado['desglose'][0]['variantes'])->keyBy('talla_id');

    expect($porTalla[$this->datos['tallaA']->id]['disponible'])->toBe(20)
        ->and($porTalla[$this->datos['tallaA']->id]['asignado'])->toBe(0)
        ->and($porTalla[$tallaS->id]['disponible'])->toBe(5)
        ->and($porTalla[$tallaS->id]['asignado'])->toBe(5);
});

it('CASO 7: operar sobre el almacén A no altera el almacén B (de la misma empresa)', function () {
    $almacenA2 = Almacen::factory()->paraEmpresa($this->datos['empresaA'])->create(['nombre' => 'Almacén A-2']);

    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 20);
    ($this->cargarStock)($this->datos['empresaA']->id, $almacenA2->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 10);
    ($this->entregarFirmada)(5, $this->datos['tallaA']->id, $this->datos['almacenA']->id);

    $resultado = $this->estado->porActivo($this->datos['activoA']);
    $porAlmacen = collect($resultado['desglose'])->keyBy('almacen_id');

    expect($porAlmacen[$this->datos['almacenA']->id]['variantes'][0]['disponible'])->toBe(15)
        ->and($porAlmacen[$this->datos['almacenA']->id]['variantes'][0]['asignado'])->toBe(5)
        ->and($porAlmacen[$almacenA2->id]['variantes'][0]['disponible'])->toBe(10)
        ->and($porAlmacen[$almacenA2->id]['variantes'][0]['asignado'])->toBe(0);
});

it('CASO 8: un almacén compartido por dos empresas mantiene las cantidades totalmente separadas', function () {
    $almacenCompartido = Almacen::factory()->paraEmpresa($this->datos['empresaA'], $this->datos['empresaB'])->create(['nombre' => 'Almacén Compartido']);
    $activoB = Activo::factory()->for($this->datos['empresaB'])->create(['nombre' => 'Camisa Empresa B']);
    $activoB->tallas()->attach($this->datos['tallaA']);

    ($this->cargarStock)($this->datos['empresaA']->id, $almacenCompartido->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 20);
    ($this->cargarStock)($this->datos['empresaB']->id, $almacenCompartido->id, $activoB->id, $this->datos['tallaA']->id, 999);

    $resultado = $this->estado->porActivo($this->datos['activoA']);

    expect($resultado['resumen'])->toBe(['disponible' => 20, 'asignado' => 0, 'danado' => 0, 'baja' => 0, 'robo_extravio' => 0]);
});

it('CASO 9: un activo sin variantes funciona con talla_id null (nunca 0)', function () {
    $activoSinVariante = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Laptop Dell']);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $activoSinVariante->id, null, 8);

    $resultado = $this->estado->porActivo($activoSinVariante);

    expect($resultado['resumen'])->toBe(['disponible' => 8, 'asignado' => 0, 'danado' => 0, 'baja' => 0, 'robo_extravio' => 0])
        ->and($resultado['desglose'][0]['variantes'][0]['talla_id'])->toBeNull();
});

it('CASO 10: dos devoluciones parciales confirmadas del mismo renglón no duplican ni pierden estado', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 20);
    $this->entregaActual = ($this->entregarFirmada)(10, $this->datos['tallaA']->id);
    $detalle = $this->entregaActual->detalles->first();

    ($this->devolverConfirmada)($detalle->id, 3, 'reutilizable');
    ($this->devolverConfirmada)($detalle->id, 2, 'danado');
    ($this->devolverConfirmada)($detalle->id, 5, 'reutilizable');

    $resultado = $this->estado->porActivo($this->datos['activoA']);

    // Entregado 10; devuelto 3+2+5=10 -> asignado 0. Disponible: 20-10+3+5=18. Dañado: 2.
    expect($resultado['resumen'])->toBe(['disponible' => 18, 'asignado' => 0, 'danado' => 2, 'baja' => 0, 'robo_extravio' => 0]);
});
