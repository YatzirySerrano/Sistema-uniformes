<?php

use App\Enums\EstadoEntrega;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\DetalleEntrega;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\Sucursal;
use App\Models\UnidadActivo;

/**
 * Desglose de los contadores del detalle de Activo (Fase QA): los 4
 * contadores del detalle enlazan a `/activos/unidades` con filtros reales, y
 * el listado expone datos suficientes para identificar cada unidad detrás de
 * cada contador (colaborador + N.º empleado + folio/fecha de origen para
 * "asignadas"; motivo/fecha de baja para "baja"; sin cota extra para
 * "en almacén"/"no disponibles").
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->sucursal = Sucursal::factory()->for($this->empresa)->create();
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $this->activo = Activo::factory()->for($this->empresa)->seguimientoIndividual()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
});

it('el filtro "en almacén" del contador devuelve sólo esas unidades', function () {
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->asignada()->create();

    $this->actingAs($this->admin)
        ->get("/activos/unidades?activo_id={$this->activo->id}&estado=en_almacen")
        ->assertInertia(fn ($page) => $page->has('unidades.data', 1)->where('unidades.data.0.estado', 'en_almacen'));
});

it('el filtro "no disponibles" (en almacén + estado_visible=reparacion) replica exactamente al contador', function () {
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create(); // funcionando, cuenta aparte
    $enReparacion = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->create(['condicion' => 'en_reparacion']);
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->asignada()->create(['condicion' => 'en_reparacion']); // asignada, no debe contar aquí

    $this->actingAs($this->admin)
        ->get("/activos/unidades?activo_id={$this->activo->id}&estado=en_almacen&estado_visible=reparacion")
        ->assertInertia(fn ($page) => $page->has('unidades.data', 1)->where('unidades.data.0.id', $enReparacion->id));
});

it('el filtro "asignadas" muestra colaborador, N.º de empleado y el folio/fecha de la entrega de origen', function () {
    $colaborador = Colaborador::factory()->for($this->empresa)->for($this->sucursal)->create(['numero_empleado' => 'EMP-042']);
    $entrega = EntregaUniforme::factory()->for($this->empresa)->for($this->sucursal)->for($colaborador)
        ->create(['estado' => EstadoEntrega::Firmada, 'folio' => 'ENT-2026-000777', 'fecha_entrega' => '2026-05-01']);
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->asignada()->create(['colaborador_id' => $colaborador->id]);
    DetalleEntrega::factory()->for($entrega, 'entrega')->create([
        'activo_id' => $this->activo->id,
        'talla_id' => null,
        'unidad_activo_id' => $unidad->id,
        'cantidad' => 1,
    ]);

    $this->actingAs($this->admin)
        ->get("/activos/unidades?activo_id={$this->activo->id}&estado=asignada")
        ->assertInertia(fn ($page) => $page
            ->has('unidades.data', 1)
            ->where('unidades.data.0.numero_empleado', 'EMP-042')
            ->where('unidades.data.0.folio_origen', 'ENT-2026-000777')
            ->where('unidades.data.0.fecha_asignacion', '2026-05-01'));
});

it('el filtro "baja" muestra fecha y motivo de baja', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->baja()->create();

    $this->actingAs($this->admin)
        ->get("/activos/unidades?activo_id={$this->activo->id}&estado=baja")
        ->assertInertia(fn ($page) => $page
            ->has('unidades.data', 1)
            ->where('unidades.data.0.motivo_baja', 'Baja de prueba')
            ->whereType('unidades.data.0.dado_de_baja_en', 'string'));
});

it('una unidad asignada de OTRO colaborador nunca expone folio/fecha de una unidad ajena (sin fuga entre filas)', function () {
    $colaboradorA = Colaborador::factory()->for($this->empresa)->for($this->sucursal)->create();
    $colaboradorB = Colaborador::factory()->for($this->empresa)->for($this->sucursal)->create();
    $entregaA = EntregaUniforme::factory()->for($this->empresa)->for($this->sucursal)->for($colaboradorA)
        ->create(['estado' => EstadoEntrega::Firmada, 'folio' => 'ENT-2026-000001']);
    $unidadA = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->asignada()->create(['colaborador_id' => $colaboradorA->id]);
    DetalleEntrega::factory()->for($entregaA, 'entrega')->create([
        'activo_id' => $this->activo->id, 'talla_id' => null, 'unidad_activo_id' => $unidadA->id, 'cantidad' => 1,
    ]);
    // Unidad B asignada, pero SIN detalle de entrega asociado.
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->asignada()->create(['colaborador_id' => $colaboradorB->id]);

    $respuesta = $this->actingAs($this->admin)
        ->get("/activos/unidades?activo_id={$this->activo->id}&estado=asignada");
    $respuesta->assertInertia(fn ($page) => $page->has('unidades.data', 2));

    $unidadB = UnidadActivo::query()->where('colaborador_id', $colaboradorB->id)->firstOrFail();
    $folios = collect($respuesta->viewData('page')['props']['unidades']['data'])->pluck('folio_origen', 'id');

    expect($folios[$unidadA->id])->toBe('ENT-2026-000001')
        ->and($folios[$unidadB->id])->toBeNull();
});
