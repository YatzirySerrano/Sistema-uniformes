<?php

use App\Acciones\RedistribuirCustodia;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\Contrato;
use App\Models\Servicio;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * La ubicación operativa de lo asignado se deriva del servicio vigente del
 * colaborador: mientras conserve custodia, no puede SALIR de su servicio
 * (los bienes "se mudarían" solos). Primero se resuelve la custodia con
 * operaciones reales (devolución o redistribución).
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $contrato = Contrato::factory()->for($this->datos['empresaA'])->create();
    $this->palmira = Servicio::factory()->for($contrato)->for($this->datos['sucursalA'])->create(['nombre' => 'Palmira']);
    $this->cuernavaca = Servicio::factory()->for($contrato)->for($this->datos['sucursalA'])->create(['nombre' => 'Cuernavaca']);

    $this->yatziri = $this->datos['colaboradorA'];
    $this->yatziri->update(['servicio_actual_id' => $this->palmira->id]);
    $this->carolina = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 20,
    ));

    $microondas = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Microondas']);
    $this->unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($microondas)->for($this->datos['almacenA'])->create();

    $this->actingAs($this->admin)->post('/entregas', [
        'colaborador_id' => $this->yatziri->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'idempotency_key' => (string) Str::uuid(),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 7]],
        'unidades' => [['unidad_activo_id' => $this->unidad->id]],
    ])->assertSessionHasNoErrors();
});

it('un colaborador sin custodia cambia de servicio normalmente', function () {
    $this->actingAs($this->admin)
        ->post("/colaboradores/{$this->carolina->id}/servicio", ['servicio_id' => $this->cuernavaca->id])
        ->assertSessionHasNoErrors();

    expect($this->carolina->fresh()->servicio_actual_id)->toBe($this->cuernavaca->id);
});

it('con custodia pendiente el cambio de servicio se rechaza y explica qué debe resolverse', function () {
    $mensaje = "No es posible cambiar a {$this->yatziri->nombre_completo} de servicio mientras tenga bienes bajo custodia (Camisa talla M: 7; Microondas {$this->unidad->codigo}). Devuélvelos al almacén o entrégalos a quien quede como responsable y vuelve a intentarlo.";

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$this->yatziri->id}/servicio", ['servicio_id' => $this->cuernavaca->id])
        ->assertSessionHasErrors(['negocio' => $mensaje]);

    // Dejarlo "sin servicio" también es salir de Palmira.
    $this->actingAs($this->admin)
        ->post("/colaboradores/{$this->yatziri->id}/servicio", ['servicio_id' => null])
        ->assertSessionHasErrors('negocio');

    expect($this->yatziri->fresh()->servicio_actual_id)->toBe($this->palmira->id);

    $this->actingAs($this->admin)
        ->getJson("/colaboradores/{$this->yatziri->id}/custodia")
        ->assertOk()
        ->assertJsonPath('tiene_pendientes', true)
        ->assertJsonPath('resumen', ['Camisa talla M: 7', "Microondas {$this->unidad->codigo}"]);
});

it('después de redistribuir toda la custodia a quien queda responsable, sí puede cambiar de servicio', function () {
    app(RedistribuirCustodia::class)->ejecutar(
        $this->yatziri->id,
        $this->carolina->id,
        $this->admin->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 7]],
        [['unidad_activo_id' => $this->unidad->id]],
    );

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$this->yatziri->id}/servicio", ['servicio_id' => $this->cuernavaca->id])
        ->assertSessionHasNoErrors();

    expect($this->yatziri->fresh()->servicio_actual_id)->toBe($this->cuernavaca->id)
        ->and($this->unidad->fresh()->colaborador_id)->toBe($this->carolina->id);
});
