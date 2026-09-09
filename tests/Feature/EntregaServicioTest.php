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
 * `entregas_uniformes.servicio_id` es un SNAPSHOT histórico. Regla nueva: el
 * servicio de la entrega NO lo elige el formulario — SIEMPRE se deriva del
 * servicio operativo VIGENTE del colaborador (`servicio_actual_id`) en el
 * backend. Un `servicio_id` manipulado en el body se ignora. Un colaborador
 * sin servicio (personal administrativo) puede recibir entregas con snapshot
 * nulo. El snapshot es independiente de cambios de servicio posteriores.
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

it('deriva el snapshot del servicio operativo vigente del colaborador', function () {
    $this->datos['colaboradorA']->update(['servicio_actual_id' => $this->servicio->id]);

    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)())
        ->assertSessionHasNoErrors();

    $entrega = EntregaUniforme::query()->where('colaborador_id', $this->datos['colaboradorA']->id)->firstOrFail();
    expect($entrega->servicio_id)->toBe($this->servicio->id);
});

it('ignora un servicio_id manipulado en el body: el snapshot siempre es el servicio vigente del colaborador', function () {
    $this->datos['colaboradorA']->update(['servicio_actual_id' => $this->servicio->id]);

    $contratoAjeno = Contrato::factory()->for($this->datos['empresaB'])->create();
    $servicioAjeno = Servicio::factory()->for($contratoAjeno)->for($this->datos['sucursalB'])->create();

    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)(['servicio_id' => $servicioAjeno->id]))
        ->assertSessionHasNoErrors();

    $entrega = EntregaUniforme::query()->where('colaborador_id', $this->datos['colaboradorA']->id)->firstOrFail();
    expect($entrega->servicio_id)->toBe($this->servicio->id);
});

it('permite la entrega de un colaborador SIN servicio y la guarda con snapshot nulo', function () {
    expect($this->datos['colaboradorA']->servicio_actual_id)->toBeNull();

    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)())
        ->assertSessionHasNoErrors();

    $entrega = EntregaUniforme::query()->where('colaborador_id', $this->datos['colaboradorA']->id)->firstOrFail();
    expect($entrega->servicio_id)->toBeNull();
    // El acuse se genera igual (no se bloquea por falta de servicio).
    expect($entrega->fresh()->acuse)->not->toBeNull();
});

it('no infiere el servicio a partir de la sucursal del colaborador', function () {
    // El colaborador tiene sucursal pero ningún servicio operativo, y existe un
    // servicio anclado a esa misma sucursal: aun así el snapshot debe ser nulo.
    Servicio::factory()->for($this->contrato)->for($this->datos['sucursalA'])->create(['nombre' => 'Otro puesto de la sucursal']);

    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)())
        ->assertSessionHasNoErrors();

    $entrega = EntregaUniforme::query()->where('colaborador_id', $this->datos['colaboradorA']->id)->firstOrFail();
    expect($entrega->servicio_id)->toBeNull();
});

it('bloquea la entrega con mensaje humano si el servicio vigente del colaborador está inactivo', function () {
    $servicioInactivo = Servicio::factory()->for($this->contrato)->for($this->datos['sucursalA'])->inactivo()->create();
    $this->datos['colaboradorA']->update(['servicio_actual_id' => $servicioInactivo->id]);

    $this->actingAs($this->admin)
        ->from('/entregas/crear')
        ->post('/entregas', ($this->payload)())
        ->assertSessionHasErrors('servicio_id');

    expect(session('errors')->get('servicio_id')[0])->toContain('inactivo');
    expect(EntregaUniforme::query()->where('colaborador_id', $this->datos['colaboradorA']->id)->exists())->toBeFalse();
});

it('bloquea la entrega si el contrato del servicio vigente del colaborador está inactivo', function () {
    $contratoInactivo = Contrato::factory()->for($this->datos['empresaA'])->inactivo()->create();
    $servicioDeContratoInactivo = Servicio::factory()->for($contratoInactivo)->for($this->datos['sucursalA'])->create();
    $this->datos['colaboradorA']->update(['servicio_actual_id' => $servicioDeContratoInactivo->id]);

    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)())
        ->assertSessionHasErrors('servicio_id');

    expect(EntregaUniforme::query()->where('colaborador_id', $this->datos['colaboradorA']->id)->exists())->toBeFalse();
});

it('CASO 2: cambiar el servicio del colaborador después NO altera una entrega ya registrada', function () {
    $this->datos['colaboradorA']->update(['servicio_actual_id' => $this->servicio->id]);

    $this->actingAs($this->admin)->post('/entregas', ($this->payload)());
    $entrega = EntregaUniforme::query()->where('colaborador_id', $this->datos['colaboradorA']->id)->firstOrFail();
    expect($entrega->servicio_id)->toBe($this->servicio->id);

    $otroServicio = Servicio::factory()->for($this->contrato)->for($this->datos['sucursalA'])->create(['nombre' => 'Polab Cuernavaca']);
    app(CambiarServicioColaborador::class)->ejecutar($this->datos['colaboradorA'], $otroServicio->id);

    expect($entrega->fresh()->servicio_id)->toBe($this->servicio->id);
    expect($this->datos['colaboradorA']->fresh()->servicio_actual_id)->toBe($otroServicio->id);
});
