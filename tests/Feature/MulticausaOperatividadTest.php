<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\Conjunto;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\Suspension;
use App\Servicios\ServicioOperatividad;

/**
 * Blindaje multicausa (Fase 7): reactivar la suspensión propia de una
 * entidad NO basta si todavía depende de algo inactivo (otra
 * empresa/sucursal/componente). Ver `App\Servicios\ServicioOperatividad` y
 * `ServicioCascadaSuspension::reactivar()`.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->empresa = $this->datos['empresaA'];
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);
});

it('1. desactivar la empresa suspende, entre otros, al activo y a un conjunto que lo usa como componente', function () {
    $conjunto = Conjunto::factory()->for($this->empresa)->create(['activo' => true]);
    $conjunto->componentes()->create(['activo_id' => $this->datos['activoA']->id, 'cantidad_requerida' => 1]);

    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/estado");

    expect($this->datos['activoA']->fresh()->activo)->toBeFalse()
        ->and($conjunto->fresh()->activo)->toBeFalse()
        ->and(Suspension::query()->where('entidad_type', Activo::class)->where('entidad_id', $this->datos['activoA']->id)->whereNull('levantada_en')->exists())->toBeTrue()
        ->and(Suspension::query()->where('entidad_type', Conjunto::class)->where('entidad_id', $conjunto->id)->whereNull('levantada_en')->exists())->toBeTrue();
});

it('2. reactivar la empresa y elegir sólo el conjunto (sin el activo) NO deja al conjunto operativo', function () {
    $conjunto = Conjunto::factory()->for($this->empresa)->create(['activo' => true]);
    $conjunto->componentes()->create(['activo_id' => $this->datos['activoA']->id, 'cantidad_requerida' => 1]);

    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/estado"); // desactiva + cascada
    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/estado"); // reactiva la empresa

    $suspensionConjunto = Suspension::query()->where('entidad_type', Conjunto::class)->where('entidad_id', $conjunto->id)->firstOrFail();

    $respuesta = $this->actingAs($this->admin)
        ->post("/empresas/{$this->empresa->id}/suspendidos/reactivar", ['ids' => [$suspensionConjunto->id]]);

    $respuesta->assertSessionHasNoErrors();

    expect($conjunto->fresh()->activo)->toBeFalse() // sigue inactivo: el activo componente aún lo está
        ->and($this->datos['activoA']->fresh()->activo)->toBeFalse()
        ->and($suspensionConjunto->fresh()->levantada_en)->toBeNull(); // la suspensión se conserva
});

it('3. reactivar primero el activo permite que el conjunto sí pueda reactivarse después', function () {
    $conjunto = Conjunto::factory()->for($this->empresa)->create(['activo' => true]);
    $conjunto->componentes()->create(['activo_id' => $this->datos['activoA']->id, 'cantidad_requerida' => 1]);

    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/estado");
    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/estado");

    $suspensionActivo = Suspension::query()->where('entidad_type', Activo::class)->where('entidad_id', $this->datos['activoA']->id)->firstOrFail();
    $suspensionConjunto = Suspension::query()->where('entidad_type', Conjunto::class)->where('entidad_id', $conjunto->id)->firstOrFail();

    // Paso 1: reactivar sólo el activo.
    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/suspendidos/reactivar", ['ids' => [$suspensionActivo->id]]);
    expect($this->datos['activoA']->fresh()->activo)->toBeTrue();

    // Paso 2: ahora sí, reactivar el conjunto.
    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/suspendidos/reactivar", ['ids' => [$suspensionConjunto->id]]);

    expect($conjunto->fresh()->activo)->toBeTrue()
        ->and($suspensionConjunto->fresh()->levantada_en)->not->toBeNull();
});

it('4. un conjunto con dos activos, uno operativo y otro inactivo, no es reactivable', function () {
    $activoOperativo = Activo::factory()->for($this->empresa)->create(['activo' => true]);
    $activoInactivo = Activo::factory()->for($this->empresa)->create(['activo' => false]);

    $conjunto = Conjunto::factory()->for($this->empresa)->create(['activo' => false]);
    $conjunto->componentes()->create(['activo_id' => $activoOperativo->id, 'cantidad_requerida' => 1]);
    $conjunto->componentes()->create(['activo_id' => $activoInactivo->id, 'cantidad_requerida' => 1]);

    $operatividad = app(ServicioOperatividad::class);

    expect($operatividad->esOperativo($conjunto))->toBeFalse();
    $motivos = $operatividad->dependenciasNoOperativas($conjunto);
    expect($motivos)->toHaveCount(1)
        ->and($motivos[0])->toContain($activoInactivo->nombre);
});

it('5. desactivar la empresa suspende tanto la sucursal como el colaborador', function () {
    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/estado");

    expect($this->datos['sucursalA']->fresh()->activa)->toBeFalse()
        ->and($this->datos['colaboradorA']->fresh()->activo)->toBeFalse()
        ->and(Suspension::query()->where('entidad_type', Sucursal::class)->where('entidad_id', $this->datos['sucursalA']->id)->whereNull('levantada_en')->exists())->toBeTrue()
        ->and(Suspension::query()->where('entidad_type', Colaborador::class)->where('entidad_id', $this->datos['colaboradorA']->id)->whereNull('levantada_en')->exists())->toBeTrue();
});

it('6. reactivar sólo el colaborador dejando la sucursal suspendida NO lo deja operativo', function () {
    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/estado");
    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/estado"); // reactiva empresa

    $suspensionColaborador = Suspension::query()->where('entidad_type', Colaborador::class)->where('entidad_id', $this->datos['colaboradorA']->id)->firstOrFail();

    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/suspendidos/reactivar", ['ids' => [$suspensionColaborador->id]]);

    expect($this->datos['colaboradorA']->fresh()->activo)->toBeFalse()
        ->and($this->datos['sucursalA']->fresh()->activa)->toBeFalse()
        ->and($suspensionColaborador->fresh()->levantada_en)->toBeNull();
});

it('7. reactivar primero la sucursal y luego el colaborador sí lo deja operativo', function () {
    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/estado");
    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/estado"); // reactiva empresa

    $suspensionSucursal = Suspension::query()->where('entidad_type', Sucursal::class)->where('entidad_id', $this->datos['sucursalA']->id)->firstOrFail();
    $suspensionColaborador = Suspension::query()->where('entidad_type', Colaborador::class)->where('entidad_id', $this->datos['colaboradorA']->id)->firstOrFail();

    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/suspendidos/reactivar", ['ids' => [$suspensionSucursal->id]]);
    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/suspendidos/reactivar", ['ids' => [$suspensionColaborador->id]]);

    expect($this->datos['sucursalA']->fresh()->activa)->toBeTrue()
        ->and($this->datos['colaboradorA']->fresh()->activo)->toBeTrue()
        ->and($suspensionColaborador->fresh()->levantada_en)->not->toBeNull();
});

it('8. un registro manualmente inactivo antes de la cascada nunca revive al reactivar el padre', function () {
    $this->datos['colaboradorA']->update(['activo' => false]); // inactivo manualmente, ANTES de la cascada

    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/estado"); // desactiva + cascada
    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/estado"); // reactiva empresa

    expect(Suspension::query()->where('entidad_type', Colaborador::class)->where('entidad_id', $this->datos['colaboradorA']->id)->exists())->toBeFalse();

    // No hay suspensión suya que reactivar: nada en el checklist lo revive.
    $suspensionesDisponibles = Suspension::query()->where('causante_type', Empresa::class)->where('causante_id', $this->empresa->id)->whereNull('levantada_en')->pluck('id');
    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/suspendidos/reactivar", ['ids' => $suspensionesDisponibles->all()]);

    expect($this->datos['colaboradorA']->fresh()->activo)->toBeFalse();
});

it('9. dos suspensiones vigentes de distintos causantes son independientes: levantar una no afecta la otra', function () {
    // Causante 1: Activo (desactivado directamente) suspende su Conjunto.
    $conjunto = Conjunto::factory()->for($this->empresa)->create(['activo' => true]);
    $conjunto->componentes()->create(['activo_id' => $this->datos['activoA']->id, 'cantidad_requerida' => 1]);
    $this->actingAs($this->admin)->post("/activos/{$this->datos['activoA']->id}/estado");

    // Causante 2: Sucursal (desactivada directamente) suspende su Colaborador.
    $this->actingAs($this->admin)->post("/sucursales/{$this->datos['sucursalA']->id}/estado");

    $suspensionConjunto = Suspension::query()->where('causante_type', Activo::class)->where('entidad_type', Conjunto::class)->firstOrFail();
    $suspensionColaborador = Suspension::query()->where('causante_type', Sucursal::class)->where('entidad_type', Colaborador::class)->firstOrFail();

    // Reactivar el activo (causante 1) y, con él operativo, levantar la
    // suspensión del conjunto que causó.
    $this->actingAs($this->admin)->post("/activos/{$this->datos['activoA']->id}/estado"); // reactiva el activo
    $this->actingAs($this->admin)->post("/activos/{$this->datos['activoA']->id}/suspendidos/reactivar", ['ids' => [$suspensionConjunto->id]]);

    expect($conjunto->fresh()->activo)->toBeTrue()
        ->and($suspensionConjunto->fresh()->levantada_en)->not->toBeNull()
        // La suspensión del colaborador (causante distinto) permanece intacta.
        ->and($suspensionColaborador->fresh()->levantada_en)->toBeNull()
        ->and($this->datos['colaboradorA']->fresh()->activo)->toBeFalse();
});

it('10. la cascada y la reactivación nunca tocan históricos, sólo el flag de estado', function () {
    $creadoAntes = $this->datos['colaboradorA']->created_at;

    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/estado");
    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/estado");

    $suspensionSucursal = Suspension::query()->where('entidad_type', Sucursal::class)->where('entidad_id', $this->datos['sucursalA']->id)->firstOrFail();
    $suspensionColaborador = Suspension::query()->where('entidad_type', Colaborador::class)->where('entidad_id', $this->datos['colaboradorA']->id)->firstOrFail();
    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/suspendidos/reactivar", ['ids' => [$suspensionSucursal->id]]);
    $this->actingAs($this->admin)->post("/empresas/{$this->empresa->id}/suspendidos/reactivar", ['ids' => [$suspensionColaborador->id]]);

    $this->datos['colaboradorA']->refresh();
    expect($this->datos['colaboradorA']->activo)->toBeTrue()
        ->and($this->datos['colaboradorA']->created_at->equalTo($creadoAntes))->toBeTrue();
});
