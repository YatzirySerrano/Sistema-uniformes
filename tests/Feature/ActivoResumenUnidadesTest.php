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
 * incrementos/decrementos en Vue. "En almacén" = presencia física (incluye
 * unidades no disponibles); "no_disponibles" desglosa cuántas de esas no
 * pueden asignarse ahora mismo; "Asignadas" nunca incluye una unidad
 * devuelta y confirmada, aunque haya quedado en una condición no disponible.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $this->activo = Activo::factory()->for($this->empresa)->seguimientoIndividual()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
});

it('desglosa "En almacén" vs "No disponibles" sin restar unas de otras, y "Asignadas"/"Baja" quedan correctas', function () {
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
            ->where('resumenUnidades.en_almacen', 4) // 2 funcionando + 1 inservible + 1 en_reparacion, TODAS físicamente presentes
            ->where('resumenUnidades.no_disponibles', 2) // inservible + en_reparacion
            ->where('resumenUnidades.asignada', 3)
            ->where('resumenUnidades.baja', 1)
        );
});

it('una unidad devuelta y confirmada como Inservible deja de contar como Asignada y pasa a "no_disponibles"', function () {
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
            ->where('resumenUnidades.en_almacen', 1)
            ->where('resumenUnidades.no_disponibles', 1)
        );
});
