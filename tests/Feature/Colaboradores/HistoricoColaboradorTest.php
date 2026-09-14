<?php

use App\Enums\EstadoEntrega;
use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\Sucursal;

/**
 * Histórico laboral del colaborador (periodos por empresa). Sólo alcance
 * global puede consultarlo — mismo criterio que el desglose agregado que ya
 * existía en el perfil del colaborador.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->dasti = Empresa::factory()->create(['nombre_comercial' => 'DASTI']);
    $this->siesa = Empresa::factory()->create(['nombre_comercial' => 'SIESA']);
    $this->sucDasti = Sucursal::factory()->for($this->dasti)->create();
    $this->sucSiesa = Sucursal::factory()->for($this->siesa)->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value);
});

it('el admin (alcance global) ve el histórico con la empresa actual marcada', function () {
    $c = Colaborador::factory()->for($this->dasti)->for($this->sucDasti)->create();

    $this->actingAs($this->admin)
        ->get("/colaboradores/{$c->id}/historico")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Colaboradores/Historico')
            ->has('periodos', 1)
            ->where('periodos.0.actual', true)
            ->where('periodos.0.empresa_id', $this->dasti->id)
            ->where('periodos.0.fecha_fin', null));
});

it('un supervisor restringido a otra empresa recibe 403 al pedir el histórico', function () {
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->dasti]);
    $supervisor->givePermissionTo('colaboradores.ver');
    $c = Colaborador::factory()->for($this->dasti)->for($this->sucDasti)->create();

    $this->actingAs($supervisor)
        ->get("/colaboradores/{$c->id}/historico")
        ->assertForbidden();
});

it('un supervisor restringido con acceso a la empresa del colaborador igual recibe 403 (sólo alcance global)', function () {
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->dasti]);
    $supervisor->givePermissionTo('colaboradores.ver');
    $c = Colaborador::factory()->for($this->dasti)->for($this->sucDasti)->create();

    // Aunque el supervisor SÍ tiene acceso a DASTI, el histórico laboral
    // completo permanece exclusivo de Admin/Superadmin: no debe ganar acceso
    // a información histórica entre empresas ni siquiera de "su" colaborador.
    $this->actingAs($supervisor)
        ->get("/colaboradores/{$c->id}/historico")
        ->assertForbidden();
});

it('el botón "Ver histórico laboral" no se ofrece a un supervisor restringido', function () {
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->dasti]);
    $supervisor->givePermissionTo('colaboradores.ver');
    $c = Colaborador::factory()->for($this->dasti)->for($this->sucDasti)->create();

    $this->actingAs($supervisor)
        ->get("/colaboradores/{$c->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('puedeVerHistorico', false));
});

it('tras una transferencia, el histórico separa el periodo anterior (cerrado) del actual (abierto)', function () {
    $c = Colaborador::factory()->for($this->dasti)->for($this->sucDasti)->create();

    $this->actingAs($this->admin)->post("/colaboradores/{$c->id}/cambiar-empresa", [
        'empresa_destino_id' => $this->siesa->id,
        'sucursal_destino_id' => $this->sucSiesa->id,
        'motivo' => 'Reasignación de contrato',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get("/colaboradores/{$c->id}/historico")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Colaboradores/Historico')
            ->has('periodos', 2)
            ->where('periodos.0.actual', false)
            ->where('periodos.0.empresa_id', $this->dasti->id)
            ->where('periodos.1.actual', true)
            ->where('periodos.1.empresa_id', $this->siesa->id)
            ->where('periodos.1.fecha_fin', null));
});

it('las entregas quedan agrupadas en el periodo de la empresa correcta, sin fuga cruzada', function () {
    $c = Colaborador::factory()->for($this->dasti)->for($this->sucDasti)->create();
    $entregaDasti = EntregaUniforme::factory()->for($this->dasti)->for($this->sucDasti)->for($c)
        ->create(['estado' => EstadoEntrega::Firmada, 'fecha_entrega' => now()->subDays(5)->toDateString()]);

    $this->actingAs($this->admin)->post("/colaboradores/{$c->id}/cambiar-empresa", [
        'empresa_destino_id' => $this->siesa->id,
        'sucursal_destino_id' => $this->sucSiesa->id,
        'motivo' => 'x',
    ])->assertSessionHasNoErrors();

    $entregaSiesa = EntregaUniforme::factory()->for($this->siesa)->for($this->sucSiesa)->for($c)
        ->create(['estado' => EstadoEntrega::Firmada, 'fecha_entrega' => now()->toDateString()]);

    $this->actingAs($this->admin)
        ->get("/colaboradores/{$c->id}/historico")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('periodos.0.entregas', 1)
            ->where('periodos.0.entregas.0.id', $entregaDasti->id)
            ->has('periodos.1.entregas', 1)
            ->where('periodos.1.entregas.0.id', $entregaSiesa->id));
});
