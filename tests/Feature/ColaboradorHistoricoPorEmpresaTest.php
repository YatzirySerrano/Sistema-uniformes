<?php

use App\Enums\EstadoDevolucion;
use App\Enums\EstadoEntrega;
use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\Sucursal;

/**
 * H: para Admin/Superadmin (alcance global) el histórico de un colaborador
 * trasladado debe quedar trazable por empresa de ORIGEN — nunca se debe leer
 * un total agregado como si perteneciera todo a la empresa ACTUAL. Un usuario
 * restringido a la empresa actual sigue sin ver nada de la empresa anterior
 * (aislamiento ya implementado, no se debe romper).
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->dasti = Empresa::factory()->create(['nombre_comercial' => 'DASTI']);
    $this->siesa = Empresa::factory()->create(['nombre_comercial' => 'SIESA']);
    $sucDasti = Sucursal::factory()->for($this->dasti)->create();
    $sucSiesa = Sucursal::factory()->for($this->siesa)->create();

    // Colaborador YA trasladado: empresa actual = SIESA.
    $this->colaborador = Colaborador::factory()->for($this->siesa)->for($sucSiesa)->create();

    // Histórico en DASTI (empresa anterior).
    $this->entregaDasti1 = EntregaUniforme::factory()->for($this->dasti)->for($sucDasti)->for($this->colaborador)
        ->create(['estado' => EstadoEntrega::Firmada]);
    $this->entregaDasti2 = EntregaUniforme::factory()->for($this->dasti)->for($sucDasti)->for($this->colaborador)
        ->create(['estado' => EstadoEntrega::Firmada]);
    Devolucion::factory()->for($this->dasti)->for($sucDasti)->for($this->colaborador)
        ->create(['estado' => EstadoDevolucion::Confirmada]);

    // Histórico en SIESA (empresa actual).
    EntregaUniforme::factory()->for($this->siesa)->for($sucSiesa)->for($this->colaborador)
        ->create(['estado' => EstadoEntrega::Firmada]);

    $this->admin = usuarioCon(RolSistema::Administrador->value); // alcance global
    $this->supervisorSiesa = usuarioCon(RolSistema::Supervisor->value, [$this->siesa]);
});

it('admin/superadmin ve el histórico desglosado por empresa de origen, y el desglose suma correctamente al total', function () {
    $respuesta = $this->actingAs($this->admin)->get("/colaboradores/{$this->colaborador->id}");

    $props = $respuesta->viewData('page')['props'];
    $historico = collect($props['historicoPorEmpresa'])->keyBy('empresa');

    expect($props['kpis']['entregas'])->toBe(3)
        ->and($props['kpis']['devoluciones'])->toBe(1)
        ->and($historico['DASTI']['entregas'])->toBe(2)
        ->and($historico['DASTI']['devoluciones'])->toBe(1)
        ->and($historico['SIESA']['entregas'])->toBe(1)
        ->and($historico['SIESA']['devoluciones'])->toBe(0)
        ->and($historico->sum('entregas'))->toBe($props['kpis']['entregas'])
        ->and($historico->sum('devoluciones'))->toBe($props['kpis']['devoluciones']);
});

it('un supervisor restringido a la empresa actual NO ve el desglose ni las cifras históricas de la empresa anterior', function () {
    $respuesta = $this->actingAs($this->supervisorSiesa)->get("/colaboradores/{$this->colaborador->id}");

    $props = $respuesta->viewData('page')['props'];

    expect($props['historicoPorEmpresa'])->toBeNull()
        ->and($props['kpis']['entregas'])->toBe(1) // sólo SIESA, nunca las 2 de DASTI
        ->and($props['kpis']['devoluciones'])->toBe(0);
});
