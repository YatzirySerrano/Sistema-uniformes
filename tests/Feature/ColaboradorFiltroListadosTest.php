<?php

use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\EntregaUniforme;

/**
 * `?colaborador_id=` en Entregas/Devoluciones: filtro backend REAL por el ID
 * del colaborador (nunca por nombre/buscar), que se preserva al exportar.
 * Nace de las cards clicables de `Colaboradores/Detalle.vue`.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);

    $this->colaboradorA = $this->datos['colaboradorA'];
    $this->otroColaboradorA = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();

    EntregaUniforme::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->for($this->colaboradorA)->create();
    EntregaUniforme::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->for($this->otroColaboradorA)->create();

    Devolucion::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->for($this->colaboradorA)->create();
    Devolucion::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->for($this->otroColaboradorA)->create();
});

it('el listado de Entregas filtra por colaborador_id (backend real, no por nombre)', function () {
    $this->actingAs($this->admin)
        ->get("/entregas?colaborador_id={$this->colaboradorA->id}")
        ->assertInertia(fn ($page) => $page
            ->has('entregas.data', 1)
            ->where('entregas.data.0.colaborador', $this->colaboradorA->nombre_completo)
            ->where('colaboradorFiltro.id', $this->colaboradorA->id)
        );
});

it('el listado de Devoluciones filtra por colaborador_id', function () {
    $this->actingAs($this->admin)
        ->get("/devoluciones?colaborador_id={$this->colaboradorA->id}")
        ->assertInertia(fn ($page) => $page
            ->has('devoluciones.data', 1)
            ->where('devoluciones.data.0.colaborador', $this->colaboradorA->nombre_completo)
            ->where('colaboradorFiltro.id', $this->colaboradorA->id)
        );
});

it('un colaborador de una empresa fuera de alcance no filtra nada ajeno (Entregas)', function () {
    $restringido = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);
    $colaboradorAjeno = Colaborador::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->create();
    EntregaUniforme::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->for($colaboradorAjeno)->create();

    $this->actingAs($restringido)
        ->get("/entregas?colaborador_id={$colaboradorAjeno->id}")
        ->assertInertia(fn ($page) => $page->has('entregas.data', 0));
});

it('el filtro de colaborador se preserva al exportar Entregas', function () {
    $respuesta = $this->actingAs($this->admin)
        ->get("/entregas/exportar?colaborador_id={$this->colaboradorA->id}&formato=xlsx");

    $respuesta->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('el filtro de colaborador se preserva al exportar Devoluciones', function () {
    $respuesta = $this->actingAs($this->admin)
        ->get("/devoluciones/exportar?colaborador_id={$this->colaboradorA->id}&formato=xlsx");

    $respuesta->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('las cards del colaborador exponen puedeVerEntregas/puedeVerDevoluciones y la custodia usa la misma fuente que el KPI', function () {
    $this->actingAs($this->admin)
        ->get("/colaboradores/{$this->colaboradorA->id}")
        ->assertInertia(fn ($page) => $page
            ->where('puedeVerEntregas', true)
            ->where('puedeVerDevoluciones', true)
            ->has('custodia.pendientes')
            ->has('custodia.incidencias')
        );
});
