<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Area;
use App\Models\Colaborador;
use App\Models\Conjunto;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\Suspension;
use App\Servicios\ServicioCascadaSuspension;

beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);
});

it('desactivar una empresa suspende por cascada a sus sucursales, colaboradores, áreas, activos y conjuntos activos, pero NUNCA al almacén compartido', function () {
    $empresa = $this->datos['empresaA'];
    $area = Area::factory()->for($empresa)->create(['activa' => true]);
    $conjunto = Conjunto::factory()->for($empresa)->create(['activo' => true]);
    $almacenCompartido = Almacen::factory()->paraEmpresa($empresa, $this->datos['empresaB'])->create(['activo' => true]);

    $this->actingAs($this->admin)
        ->post("/empresas/{$empresa->id}/estado")
        ->assertSessionHasNoErrors();

    expect($empresa->fresh()->activa)->toBeFalse()
        ->and($this->datos['sucursalA']->fresh()->activa)->toBeFalse()
        ->and($this->datos['colaboradorA']->fresh()->activo)->toBeFalse()
        ->and($area->fresh()->activa)->toBeFalse()
        ->and($this->datos['activoA']->fresh()->activo)->toBeFalse()
        ->and($conjunto->fresh()->activo)->toBeFalse()
        ->and($almacenCompartido->fresh()->activo)->toBeTrue(); // NUNCA se desactiva físicamente

    expect(Suspension::query()->where('causante_type', Empresa::class)->where('causante_id', $empresa->id)->whereNull('levantada_en')->count())
        ->toBe(5); // sucursal + colaborador + área + activo + conjunto
});

it('no crea suspensión para un dependiente que ya estaba inactivo antes de la cascada', function () {
    $empresa = $this->datos['empresaA'];
    $areaYaInactiva = Area::factory()->for($empresa)->create(['activa' => false]);

    $this->actingAs($this->admin)->post("/empresas/{$empresa->id}/estado");

    expect(Suspension::query()->where('entidad_type', Area::class)->where('entidad_id', $areaYaInactiva->id)->exists())->toBeFalse()
        ->and($areaYaInactiva->fresh()->activa)->toBeFalse(); // sigue inactiva, sin cambio
});

it('reactivar la empresa NO reactiva automáticamente lo que su cascada suspendió', function () {
    $empresa = $this->datos['empresaA'];

    $this->actingAs($this->admin)->post("/empresas/{$empresa->id}/estado"); // desactiva + cascada
    $this->actingAs($this->admin)->post("/empresas/{$empresa->id}/estado"); // reactiva la empresa

    expect($empresa->fresh()->activa)->toBeTrue()
        ->and($this->datos['sucursalA']->fresh()->activa)->toBeFalse() // sigue suspendida
        ->and(Suspension::query()->whereNull('levantada_en')->where('causante_id', $empresa->id)->count())->toBe(3); // sucursal + colaborador + activo
});

it('la reactivación selectiva sólo levanta los ids marcados, deja el resto suspendido', function () {
    $empresa = $this->datos['empresaA'];
    $area = Area::factory()->for($empresa)->create(['activa' => true]);

    $this->actingAs($this->admin)->post("/empresas/{$empresa->id}/estado"); // desactiva + cascada
    $this->actingAs($this->admin)->post("/empresas/{$empresa->id}/estado"); // reactiva la empresa (no cascada)

    $suspensionSucursal = Suspension::query()->where('entidad_type', Sucursal::class)->firstOrFail();
    $suspensionArea = Suspension::query()->where('entidad_type', Area::class)->firstOrFail();

    $this->actingAs($this->admin)
        ->post("/empresas/{$empresa->id}/suspendidos/reactivar", ['ids' => [$suspensionSucursal->id]])
        ->assertSessionHasNoErrors();

    expect($this->datos['sucursalA']->fresh()->activa)->toBeTrue()
        ->and($area->fresh()->activa)->toBeFalse()
        ->and($suspensionSucursal->fresh()->levantada_en)->not->toBeNull()
        ->and($suspensionArea->fresh()->levantada_en)->toBeNull();
});

it('no se puede reactivar una suspensión ajena pasando su id por otro causante', function () {
    $empresa = $this->datos['empresaA'];
    $this->actingAs($this->admin)->post("/empresas/{$empresa->id}/estado");
    $suspensionDeEmpresa = Suspension::query()->where('causante_type', Empresa::class)->firstOrFail();

    // Se intenta "colar" el id de una suspensión causada por la EMPRESA a
    // través del endpoint de otra empresa (empresaB) de la que el admin
    // también es responsable.
    $otraEmpresa = $this->datos['empresaB'];
    $this->actingAs($this->admin)
        ->post("/empresas/{$otraEmpresa->id}/suspendidos/reactivar", ['ids' => [$suspensionDeEmpresa->id]]);

    expect($suspensionDeEmpresa->fresh()->levantada_en)->toBeNull();
});

it('desactivar una sucursal suspende sólo a sus colaboradores', function () {
    $sucursal = $this->datos['sucursalA'];
    $otroColaborador = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalB'])->create(['activo' => true]);

    $this->actingAs($this->admin)->post("/sucursales/{$sucursal->id}/estado");

    expect($this->datos['colaboradorA']->fresh()->activo)->toBeFalse()
        ->and($otroColaborador->fresh()->activo)->toBeTrue();
});

it('desactivar un activo suspende sólo los conjuntos que lo usan como componente, no otros conjuntos', function () {
    $activo = $this->datos['activoA'];
    $conjuntoConEseActivo = Conjunto::factory()->for($this->datos['empresaA'])->create(['activo' => true]);
    $conjuntoConEseActivo->componentes()->create(['activo_id' => $activo->id, 'cantidad_requerida' => 1]);

    $otroActivo = Activo::factory()->for($this->datos['empresaA'])->create();
    $conjuntoSinEseActivo = Conjunto::factory()->for($this->datos['empresaA'])->create(['activo' => true]);
    $conjuntoSinEseActivo->componentes()->create(['activo_id' => $otroActivo->id, 'cantidad_requerida' => 1]);

    $this->actingAs($this->admin)->post("/activos/{$activo->id}/estado");

    expect($conjuntoConEseActivo->fresh()->activo)->toBeFalse()
        ->and($conjuntoSinEseActivo->fresh()->activo)->toBeTrue();
});

it('un usuario sin permiso no puede reactivar suspendidos', function () {
    $empresa = $this->datos['empresaA'];
    $this->actingAs($this->admin)->post("/empresas/{$empresa->id}/estado");
    $suspension = Suspension::query()->firstOrFail();

    $encargado = usuarioCon(RolSistema::Encargado->value, [$empresa]);

    $this->actingAs($encargado)
        ->post("/empresas/{$empresa->id}/suspendidos/reactivar", ['ids' => [$suspension->id]])
        ->assertForbidden();

    expect($suspension->fresh()->levantada_en)->toBeNull();
});

it('el servicio de cascada nunca toca históricos: sólo cambia el flag de estado del dependiente', function () {
    $empresa = $this->datos['empresaA'];
    $servicio = app(ServicioCascadaSuspension::class);

    $creadoAntes = $this->datos['colaboradorA']->created_at;

    $servicio->suspender($empresa, Colaborador::query()->where('empresa_id', $empresa->id), 'activo', null);

    $this->datos['colaboradorA']->refresh();
    expect($this->datos['colaboradorA']->activo)->toBeFalse()
        ->and($this->datos['colaboradorA']->created_at->equalTo($creadoAntes))->toBeTrue();
});
