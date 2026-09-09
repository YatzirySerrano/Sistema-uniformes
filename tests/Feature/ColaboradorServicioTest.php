<?php

use App\Enums\RolSistema;
use App\Models\BitacoraAuditoria;
use App\Models\Colaborador;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\Servicio;
use App\Models\Sucursal;

beforeEach(function () {
    sembrarRolesPermisos();
});

it('asigna el servicio actual de un colaborador sin servicio previo', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $contrato = Contrato::factory()->for($empresa)->create();
    $servicio = Servicio::factory()->for($contrato)->for($sucursal)->create();
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post("/colaboradores/{$colaborador->id}/servicio", [
            'servicio_id' => $servicio->id,
            'motivo' => 'Asignación inicial',
        ])
        ->assertSessionHas('toast')
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->servicio_actual_id)->toBe($servicio->id);
});

it('CASO 2: cambiar de servicio actualiza la ubicación vigente sin tocar entregas históricas', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $contrato = Contrato::factory()->for($empresa)->create();
    $servicioJiutepec = Servicio::factory()->for($contrato)->for($sucursal)->create(['nombre' => 'Polab Jiutepec']);
    $servicioCuernavaca = Servicio::factory()->for($contrato)->for($sucursal)->create(['nombre' => 'Polab Cuernavaca']);
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create(['servicio_actual_id' => $servicioJiutepec->id]);

    $entregaHistorica = EntregaUniforme::factory()->for($empresa)->for($sucursal)->for($colaborador)->create([
        'servicio_id' => $servicioJiutepec->id,
    ]);

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post("/colaboradores/{$colaborador->id}/servicio", ['servicio_id' => $servicioCuernavaca->id])
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->servicio_actual_id)->toBe($servicioCuernavaca->id);
    // La entrega histórica conserva su snapshot original — nunca se toca.
    expect($entregaHistorica->fresh()->servicio_id)->toBe($servicioJiutepec->id);
});

it('permite dejar al colaborador sin servicio asignado (servicio_id nullable)', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $contrato = Contrato::factory()->for($empresa)->create();
    $servicio = Servicio::factory()->for($contrato)->for($sucursal)->create();
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create(['servicio_actual_id' => $servicio->id]);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post("/colaboradores/{$colaborador->id}/servicio", ['servicio_id' => null])
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->servicio_actual_id)->toBeNull();
});

it('CASO 4: rechaza asignar un servicio de una empresa distinta a la del colaborador', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $sucursalA = Sucursal::factory()->for($empresaA)->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();
    $contratoB = Contrato::factory()->for($empresaB)->create();
    $servicioAjeno = Servicio::factory()->for($contratoB)->for($sucursalB)->create();
    $colaborador = Colaborador::factory()->for($empresaA)->for($sucursalA)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post("/colaboradores/{$colaborador->id}/servicio", ['servicio_id' => $servicioAjeno->id])
        ->assertSessionHasErrors('servicio_id');

    expect($colaborador->fresh()->servicio_actual_id)->toBeNull();
});

it('rechaza asignar un servicio inactivo', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $contrato = Contrato::factory()->for($empresa)->create();
    $servicioInactivo = Servicio::factory()->for($contrato)->for($sucursal)->inactivo()->create();
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post("/colaboradores/{$colaborador->id}/servicio", ['servicio_id' => $servicioInactivo->id])
        ->assertSessionHasErrors('servicio_id');

    expect($colaborador->fresh()->servicio_actual_id)->toBeNull();
});

it('rechaza asignar un servicio cuyo contrato está inactivo', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $contratoInactivo = Contrato::factory()->for($empresa)->inactivo()->create();
    $servicio = Servicio::factory()->for($contratoInactivo)->for($sucursal)->create();
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post("/colaboradores/{$colaborador->id}/servicio", ['servicio_id' => $servicio->id])
        ->assertSessionHasErrors('servicio_id');

    expect($colaborador->fresh()->servicio_actual_id)->toBeNull();
});

it('registra en auditoría el cambio de servicio con servicio/contrato anterior y nuevo', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $contrato = Contrato::factory()->for($empresa)->create(['nombre' => 'Laboratorios Polab']);
    $servicio = Servicio::factory()->for($contrato)->for($sucursal)->create(['nombre' => 'Polab Cuernavaca']);
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post("/colaboradores/{$colaborador->id}/servicio", [
            'servicio_id' => $servicio->id,
            'motivo' => 'Reasignación de guardia',
        ]);

    $entrada = BitacoraAuditoria::query()
        ->where('modulo', 'colaboradores')
        ->where('accion', 'cambiar_servicio')
        ->latest('id')
        ->first();

    expect($entrada)->not->toBeNull();
    expect($entrada->motivo)->toBe('Reasignación de guardia');
    expect($entrada->valores_nuevos['servicio'])->toBe('Polab Cuernavaca');
    expect($entrada->valores_nuevos['contrato'])->toBe('Laboratorios Polab');
    expect($entrada->valores_anteriores['servicio'])->toBe('Sin servicio');
});

it('cambiar servicio no crea entregas ni devoluciones ni toca inventario', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $contrato = Contrato::factory()->for($empresa)->create();
    $servicio = Servicio::factory()->for($contrato)->for($sucursal)->create();
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post("/colaboradores/{$colaborador->id}/servicio", ['servicio_id' => $servicio->id]);

    expect(EntregaUniforme::query()->where('colaborador_id', $colaborador->id)->count())->toBe(0);
});

it('un encargado sin permiso de edición de colaboradores no puede cambiar el servicio', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $contrato = Contrato::factory()->for($empresa)->create();
    $servicio = Servicio::factory()->for($contrato)->for($sucursal)->create();
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create();
    $encargado = usuarioCon(RolSistema::Encargado->value, [$empresa]);

    $this->actingAs($encargado)
        ->post("/colaboradores/{$colaborador->id}/servicio", ['servicio_id' => $servicio->id])
        ->assertForbidden();
});
