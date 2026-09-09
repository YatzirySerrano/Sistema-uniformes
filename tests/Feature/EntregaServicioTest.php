<?php

use App\Acciones\CambiarServicioColaborador;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Contrato;
use App\Models\EntregaUniforme;
use App\Models\Servicio;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * `entregas_uniformes.servicio_id` es un SNAPSHOT histórico del servicio de
 * destino al momento de la entrega — independiente de
 * `colaborador.servicio_actual_id` (la ubicación VIGENTE, ver
 * `CambiarServicioColaborador`). Nunca se derivan entre sí.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->contrato = Contrato::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Laboratorios Polab']);
    $this->servicio = Servicio::factory()->for($this->contrato)->for($this->datos['sucursalA'])->create(['nombre' => 'Polab Jiutepec']);
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 20,
    ));

    $this->payload = fn (array $extra = []): array => [
        'colaborador_id' => $this->datos['colaboradorA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'idempotency_key' => (string) Str::uuid(),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
        ...$extra,
    ];
});

it('guarda el servicio elegido como snapshot histórico de la entrega', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)(['servicio_id' => $this->servicio->id]))
        ->assertSessionHasNoErrors();

    $entrega = EntregaUniforme::query()->where('colaborador_id', $this->datos['colaboradorA']->id)->firstOrFail();
    expect($entrega->servicio_id)->toBe($this->servicio->id);
});

it('una entrega sin servicio_id se registra igual, con snapshot nulo (entrega interna)', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)())
        ->assertSessionHasNoErrors();

    $entrega = EntregaUniforme::query()->where('colaborador_id', $this->datos['colaboradorA']->id)->firstOrFail();
    expect($entrega->servicio_id)->toBeNull();
});

it('rechaza un servicio de una empresa distinta a la del colaborador', function () {
    $contratoAjeno = Contrato::factory()->for($this->datos['empresaB'])->create();
    $servicioAjeno = Servicio::factory()->for($contratoAjeno)->for($this->datos['sucursalB'])->create();

    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)(['servicio_id' => $servicioAjeno->id]))
        ->assertSessionHasErrors('servicio_id');

    expect(EntregaUniforme::query()->where('colaborador_id', $this->datos['colaboradorA']->id)->exists())->toBeFalse();
});

it('rechaza un servicio cuyo contrato está inactivo', function () {
    $contratoInactivo = Contrato::factory()->for($this->datos['empresaA'])->inactivo()->create();
    $servicioDeContratoInactivo = Servicio::factory()->for($contratoInactivo)->for($this->datos['sucursalA'])->create();

    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)(['servicio_id' => $servicioDeContratoInactivo->id]))
        ->assertSessionHasErrors('servicio_id');
});

it('CASO 2 (lado entrega): cambiar el servicio actual del colaborador después no altera entregas ya registradas', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)(['servicio_id' => $this->servicio->id]));

    $entrega = EntregaUniforme::query()->where('colaborador_id', $this->datos['colaboradorA']->id)->firstOrFail();

    $otroServicio = Servicio::factory()->for($this->contrato)->for($this->datos['sucursalA'])->create(['nombre' => 'Polab Cuernavaca']);
    app(CambiarServicioColaborador::class)->ejecutar($this->datos['colaboradorA'], $otroServicio->id);

    expect($entrega->fresh()->servicio_id)->toBe($this->servicio->id);
    expect($this->datos['colaboradorA']->fresh()->servicio_actual_id)->toBe($otroServicio->id);
});
