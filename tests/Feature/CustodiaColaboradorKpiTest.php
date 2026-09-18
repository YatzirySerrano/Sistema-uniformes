<?php

use App\Acciones\CrearEntregaUniforme;
use App\Acciones\RegistrarDevolucion;
use App\Enums\EstadoEntrega;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioCustodiaColaborador;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Storage;

/**
 * KPI "Activos asignados" del perfil de colaborador (`ColaboradorController::show`):
 * debe reflejar las piezas físicas REALES bajo custodia, combinando unidades
 * identificadas asignadas y artículos por cantidad con saldo pendiente — nunca
 * sólo `UnidadActivo`. Cubre `ServicioCustodiaColaborador::totalPiezasPendientes()`.
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
        cantidad: 50,
    ));

    $this->custodia = app(ServicioCustodiaColaborador::class);

    // Closure enlazada en `beforeEach` (no una función a nivel de módulo, ver
    // `.ai/rules/tests.md`): crea y firma una entrega por cantidad para
    // `colaboradorA`.
    $this->entregarCantidad = function (int $cantidad) {
        $entrega = app(CrearEntregaUniforme::class)->ejecutar(
            $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
            [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => $cantidad]], [], [],
        );
        $entrega->update(['estado' => EstadoEntrega::Firmada]);

        return $entrega;
    };
});

it('un colaborador sin entregas tiene 0 piezas bajo custodia', function () {
    expect($this->custodia->totalPiezasPendientes($this->datos['colaboradorA']))->toBe(0);
});

it('cuenta el saldo pendiente de un renglón por cantidad sin devoluciones', function () {
    ($this->entregarCantidad)(5);

    expect($this->custodia->totalPiezasPendientes($this->datos['colaboradorA']))->toBe(5);
});

it('cuenta cada unidad identificada asignada como 1 pieza', function () {
    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    UnidadActivo::factory()->asignada()
        ->for($this->datos['empresaA'], 'empresa')
        ->for($activoIndividual)
        ->for($this->datos['almacenA'])
        ->create(['colaborador_id' => $this->datos['colaboradorA']->id]);

    expect($this->custodia->totalPiezasPendientes($this->datos['colaboradorA']))->toBe(1);
});

it('suma piezas por cantidad y unidades individuales sin duplicar ni confundir tipos', function () {
    ($this->entregarCantidad)(3);

    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    UnidadActivo::factory()->asignada()
        ->for($this->datos['empresaA'], 'empresa')
        ->for($activoIndividual)
        ->for($this->datos['almacenA'])
        ->create(['colaborador_id' => $this->datos['colaboradorA']->id]);

    expect($this->custodia->totalPiezasPendientes($this->datos['colaboradorA']))->toBe(4);
});

it('una devolución PARCIAL confirmada descuenta sólo lo devuelto, el colaborador no aparece en 0', function () {
    $entrega = ($this->entregarCantidad)(5);

    $this->actingAs($this->admin)->post('/devoluciones', [
        'entrega_uniforme_id' => $entrega->id,
        'colaborador_id' => $this->datos['colaboradorA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'activos' => [[
            'detalle_entrega_id' => $entrega->detalles->first()->id,
            'cantidad' => 2,
            'condicion' => 'reutilizable',
        ]],
    ])->assertSessionHasNoErrors();

    expect($this->custodia->totalPiezasPendientes($this->datos['colaboradorA']))->toBe(3);
});

it('una devolución TOTAL confirmada deja la custodia en cero', function () {
    $entrega = ($this->entregarCantidad)(5);

    $this->actingAs($this->admin)->post('/devoluciones', [
        'entrega_uniforme_id' => $entrega->id,
        'colaborador_id' => $this->datos['colaboradorA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'activos' => [[
            'detalle_entrega_id' => $entrega->detalles->first()->id,
            'cantidad' => 5,
            'condicion' => 'reutilizable',
        ]],
    ])->assertSessionHasNoErrors();

    expect($this->custodia->totalPiezasPendientes($this->datos['colaboradorA']))->toBe(0);
});

it('una devolución PENDIENTE DE FIRMA todavía no libera custodia', function () {
    $entrega = ($this->entregarCantidad)(4);

    // Se crea la SOLICITUD (pendiente_firma) sin pasar por
    // `ConfirmarAcuseDevolucion`: el flujo real siempre corre ambas dentro de
    // la misma transacción, pero el KPI debe ser correcto incluso si sólo
    // existe la solicitud.
    app(RegistrarDevolucion::class)->ejecutar(
        $entrega->id,
        $this->datos['almacenA']->id,
        now()->toDateString(),
        [['detalle_entrega_id' => $entrega->detalles->first()->id, 'cantidad' => 4, 'condicion' => 'reutilizable']],
        [],
        $this->admin->id,
    );

    expect($this->custodia->totalPiezasPendientes($this->datos['colaboradorA']))->toBe(4);
});

it('suma el pendiente de MÚLTIPLES entregas del mismo colaborador', function () {
    ($this->entregarCantidad)(2);
    ($this->entregarCantidad)(3);

    expect($this->custodia->totalPiezasPendientes($this->datos['colaboradorA']))->toBe(5);
});

it('acota el total a las empresas autorizadas (aislamiento histórico tras cambio de empresa)', function () {
    ($this->entregarCantidad)(3);

    expect($this->custodia->totalPiezasPendientes($this->datos['colaboradorA'], [$this->datos['empresaB']->id]))->toBe(0)
        ->and($this->custodia->totalPiezasPendientes($this->datos['colaboradorA'], [$this->datos['empresaA']->id]))->toBe(3);
});

it('el perfil del colaborador expone el KPI correcto vía HTTP, no sólo 0 por falta de UnidadActivo', function () {
    ($this->entregarCantidad)(5);

    $this->actingAs($this->admin)
        ->get("/colaboradores/{$this->datos['colaboradorA']->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('kpis.activos_asignados', 5));
});
