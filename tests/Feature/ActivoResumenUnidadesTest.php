<?php

use App\Enums\CondicionUnidadActivo;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\UnidadActivo;
use App\Servicios\ServicioUnidadesActivo;

/**
 * G: los contadores del detalle del Activo se derivan de BD, nunca de
 * incrementos/decrementos en Vue. "En almacén" y "no_disponibles" son
 * MUTUAMENTE EXCLUYENTES (una misma unidad física nunca cuenta en las dos a
 * la vez): "En almacén" = disponible de verdad (estado EnAlmacen + condición
 * Funcionando); "no_disponibles" = el resto de las unidades en almacén
 * (condición distinta de Funcionando). "Asignadas" nunca incluye una unidad
 * devuelta y confirmada, aunque haya quedado en una condición no disponible.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $this->activo = Activo::factory()->for($this->empresa)->seguimientoIndividual()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
});

it('desglosa "En almacén" vs "No disponibles" SIN doble conteo (mutuamente excluyentes), y "Asignadas"/"Baja" quedan correctas', function () {
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->count(2)->create(); // funcionando, en almacén
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::Inservible)->create(); // en almacén, NO disponible
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::EnReparacion)->create(); // en almacén, NO disponible
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->asignada()->count(3)->create();
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->baja()->create();

    $this->actingAs($this->admin)
        ->get("/activos/{$this->activo->id}")
        ->assertInertia(fn ($page) => $page
            ->component('Activos/Detalle')
            ->where('resumenUnidades.en_almacen', 2) // sólo las 2 funcionando (disponibles de verdad)
            ->where('resumenUnidades.no_disponibles', 2) // inservible + en_reparacion, nunca contadas también en "en_almacen"
            ->where('resumenUnidades.asignada', 3)
            ->where('resumenUnidades.baja', 1)
        );
});

it('una unidad devuelta y confirmada como Inservible deja de contar como Asignada y como "En almacén" disponible: pasa completa a "no_disponibles"', function () {
    // Simula el estado tras una devolución confirmada con condición dañada:
    // estado vuelve a en_almacen, condicion queda Inservible, colaborador se limpia.
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->asignada()->create();

    app(ServicioUnidadesActivo::class)->devolver(
        $unidad, CondicionUnidadActivo::Inservible, $this->almacen->id, $this->admin->id, 'test', 0,
    );

    $this->actingAs($this->admin)
        ->get("/activos/{$this->activo->id}")
        ->assertInertia(fn ($page) => $page
            ->where('resumenUnidades.asignada', 0)
            // Es la MISMA única unidad que existe: nunca debe aparentar que
            // hay 2 (1 "en almacén" + 1 "no disponible").
            ->where('resumenUnidades.en_almacen', 0)
            ->where('resumenUnidades.no_disponibles', 1)
        );
});

it('una unidad en almacén marcada perdida o robada cuenta en "no_disponibles", nunca en "en_almacen"', function () {
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::Perdido)->create();
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::Robado)->create();

    $this->actingAs($this->admin)
        ->get("/activos/{$this->activo->id}")
        ->assertInertia(fn ($page) => $page
            ->where('resumenUnidades.en_almacen', 0)
            ->where('resumenUnidades.no_disponibles', 2)
        );
});

it('el link del KPI "No disponibles" (?no_disponible=1) devuelve EXACTAMENTE las mismas unidades que cuenta el KPI, sin faltantes', function () {
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::EnReparacion)->create();
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::Inservible)->create();
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::Perdido)->create();
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::Robado)->create();
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->count(2)->create(); // funcionando
    // Ruido que NO debe aparecer: asignada+perdida (no está "en almacén") y baja.
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->asignada()->conCondicion(CondicionUnidadActivo::Perdido)->create();
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->baja()->create();

    $kpi = $this->actingAs($this->admin)->get("/activos/{$this->activo->id}");
    $kpi->assertInertia(fn ($page) => $page
        ->where('resumenUnidades.en_almacen', 2)
        ->where('resumenUnidades.no_disponibles', 4));

    $this->actingAs($this->admin)
        ->get("/activos/unidades?activo_id={$this->activo->id}&no_disponible=1")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Activos/Unidades')
            ->where('unidades.data', fn ($data) => collect($data)->count() === 4
                && collect($data)->pluck('condicion')->sort()->values()->all() === ['en_reparacion', 'inservible', 'perdido', 'robado']
                && collect($data)->pluck('estado')->unique()->all() === ['en_almacen']));
});
