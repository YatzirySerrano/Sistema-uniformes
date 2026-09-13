<?php

use App\Acciones\ConfirmarAcuseDevolucion;
use App\Acciones\CrearEntregaUniforme;
use App\Acciones\RegistrarDevolucion;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Storage;

/**
 * Fuente única de "custodia pendiente" (`ServicioCustodiaColaborador`)
 * aplicada al selector "Entrega de origen" de Devoluciones: una entrega sólo
 * debe ofrecerse mientras le quede algo REALMENTE pendiente (cantidad no
 * cubierta por devoluciones CONFIRMADAS, o una unidad todavía asignada).
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    Storage::fake('local');

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 20,
    ));
});

function confirmarDevolucionCantidad($test, $entrega, $detalle, int $cantidad): void
{
    $devolucion = app(RegistrarDevolucion::class)->ejecutar(
        $entrega->id, $test->datos['almacenA']->id, now()->toDateString(),
        [['detalle_entrega_id' => $detalle->id, 'cantidad' => $cantidad, 'condicion' => 'reutilizable']],
        [], $test->admin->id,
    );
    app(ConfirmarAcuseDevolucion::class)->ejecutar($devolucion, firmaDemoBase64(), firmaDemoBase64(), true, $test->admin->id, null, null);
}

it('una entrega totalmente devuelta y CONFIRMADA deja de aparecer en el buscador de entregas de origen', function () {
    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]], [], [],
    );
    confirmarDevolucionCantidad($this, $entrega, $entrega->detalles->first(), 1);

    $this->actingAs($this->admin)
        ->getJson('/entregas/buscar')
        ->assertOk()
        ->assertJsonMissing(['id' => $entrega->id]);
});

it('una devolución PARCIAL confirmada mantiene la entrega disponible y sólo expone la cantidad restante', function () {
    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 3]], [], [],
    );
    confirmarDevolucionCantidad($this, $entrega, $entrega->detalles->first(), 1);

    $this->actingAs($this->admin)
        ->getJson('/entregas/buscar')
        ->assertOk()
        ->assertJsonFragment(['id' => $entrega->id]);

    $this->actingAs($this->admin)
        ->get("/devoluciones/crear?entrega_id={$entrega->id}")
        ->assertInertia(fn ($page) => $page
            ->component('Devoluciones/Crear')
            ->where('entrega.renglones.0.pendiente', 2));
});

it('una unidad identificada ya devuelta y confirmada no vuelve a ofrecerse, pero los renglones de cantidad de la misma entrega siguen disponibles', function () {
    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoIndividual)->for($this->datos['almacenA'])->create();

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
        [['unidad_activo_id' => $unidad->id]], [],
    );
    $detalleUnidad = $entrega->detalles->firstWhere('unidad_activo_id', $unidad->id);

    $devolucion = app(RegistrarDevolucion::class)->ejecutar(
        $entrega->id, $this->datos['almacenA']->id, now()->toDateString(), [],
        [['detalle_entrega_id' => $detalleUnidad->id, 'condicion' => 'funcionando']], $this->admin->id,
    );
    app(ConfirmarAcuseDevolucion::class)->ejecutar($devolucion, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    // La entrega sigue disponible: el renglón de cantidad sigue pendiente.
    $this->actingAs($this->admin)
        ->getJson('/entregas/buscar')
        ->assertOk()
        ->assertJsonFragment(['id' => $entrega->id]);

    $respuesta = $this->actingAs($this->admin)->get("/devoluciones/crear?entrega_id={$entrega->id}");
    $renglones = $respuesta->viewData('page')['props']['entrega']['renglones'];

    expect($renglones)->toHaveCount(1)
        ->and($renglones[0]['es_unidad'])->toBeFalse()
        ->and($renglones[0]['pendiente'])->toBe(2);
});

it('una devolución PENDIENTE DE FIRMA no reduce lo mostrado como pendiente ni oculta la entrega (nunca cuenta como liberada)', function () {
    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 3]], [], [],
    );

    // Registrada pero SIN confirmar (sigue pendiente_firma).
    app(RegistrarDevolucion::class)->ejecutar(
        $entrega->id, $this->datos['almacenA']->id, now()->toDateString(),
        [['detalle_entrega_id' => $entrega->detalles->first()->id, 'cantidad' => 1, 'condicion' => 'reutilizable']],
        [], $this->admin->id,
    );

    $this->actingAs($this->admin)
        ->getJson('/entregas/buscar')
        ->assertOk()
        ->assertJsonFragment(['id' => $entrega->id]);

    $this->actingAs($this->admin)
        ->get("/devoluciones/crear?entrega_id={$entrega->id}")
        ->assertInertia(fn ($page) => $page
            ->where('entrega.renglones.0.pendiente', 3));
});
