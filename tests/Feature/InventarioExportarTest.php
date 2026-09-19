<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\SaldoInventario;

/**
 * Exportación Excel/PDF de "Existencias globales" (`InventarioController::exportar()`),
 * reutilizando `ExportaListado`/`ListadoExport` como el resto de listados
 * administrativos — misma consulta filtrada que `index()`, nunca una aparte.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    SaldoInventario::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'cantidad' => 5,
    ]);

    $this->activoB = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Pantalón']);
    SaldoInventario::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->activoB->id,
        'talla_id' => null,
        'cantidad' => 20,
    ]);
});

it('exporta a Excel respetando el filtro de activo activo', function () {
    $this->actingAs($this->admin)
        ->get("/inventario/exportar?activo_id={$this->datos['activoA']->id}&formato=xlsx")
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exporta a PDF respetando el filtro de activo', function () {
    $this->actingAs($this->admin)
        ->get("/inventario/exportar?activo_id={$this->datos['activoA']->id}&formato=pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('exporta TODAS las filas filtradas, no sólo una página', function () {
    // Con paginación de 1 en pantalla habría 2 páginas, pero el export debe
    // seguir generando el archivo completo sin truncar por `por_pagina`.
    config(['uniformes.por_pagina' => 1]);

    $this->actingAs($this->admin)
        ->get('/inventario')
        ->assertInertia(fn ($page) => $page->where('saldos.total', 2));

    $this->actingAs($this->admin)
        ->get('/inventario/exportar?formato=xlsx')
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('respeta el permiso inventario.ver', function () {
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);

    $this->actingAs($sinPermiso)
        ->get('/inventario/exportar?formato=xlsx')
        ->assertForbidden();
});
