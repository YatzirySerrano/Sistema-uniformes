<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Models\UnidadActivo;

/**
 * Coherencia entre el resumen de Almacén ("Activos con existencia") y el
 * listado de Activos filtrado por ese mismo almacén ("Ver activos aquí"):
 * ambos deben describir el MISMO universo de activos distintos. Ver
 * `App\Http\Controllers\AlmacenController::contarActivosConExistencia()` y
 * `App\Http\Controllers\ActivoController::consultaActivos()`.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    // activoA (de escenarioMultiempresa) ya tiene la variante M asociada.
    SaldoInventario::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'cantidad' => 5,
    ]);

    // Segunda variante del MISMO activo, también con existencia: no debe
    // contar como un segundo activo.
    $tallaL = Talla::factory()->create(['valor' => 'L']);
    $this->datos['activoA']->tallas()->attach($tallaL);
    SaldoInventario::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $tallaL->id,
        'cantidad' => 3,
    ]);

    // Segundo activo por CANTIDAD distinto, con existencia.
    $this->activoB = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Pantalón']);
    SaldoInventario::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->activoB->id,
        'talla_id' => null,
        'cantidad' => 10,
    ]);

    // Dos activos de SEGUIMIENTO INDIVIDUAL distintos, cada uno con unidades
    // entregables en el almacén — una de ellas con 2 unidades (no debe
    // duplicar el activo).
    $this->activoInd1 = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Laptop']);
    UnidadActivo::factory()->count(2)->create([
        'empresa_id' => $this->datos['empresaA']->id,
        'activo_id' => $this->activoInd1->id,
        'almacen_id' => $this->datos['almacenA']->id,
    ]);

    $this->activoInd2 = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Celular']);
    UnidadActivo::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id,
        'activo_id' => $this->activoInd2->id,
        'almacen_id' => $this->datos['almacenA']->id,
    ]);
});

it('el KPI "Activos con existencia" del almacén cuenta 4 activos distintos (2 por cantidad + 2 individuales)', function () {
    $this->actingAs($this->admin)
        ->get("/almacenes/{$this->datos['almacenA']->id}")
        ->assertInertia(fn ($page) => $page->where('resumen.activos_con_existencia', 4));
});

it('"Ver activos aquí" (listado de Activos filtrado por almacén) devuelve exactamente esos 4 activos', function () {
    $respuesta = $this->actingAs($this->admin)->get("/activos?almacen_id={$this->datos['almacenA']->id}");

    $ids = collect($respuesta->viewData('page')['props']['activos'])->pluck('id')->sort()->values();

    expect($ids->all())->toEqualCanonicalizing([
        $this->datos['activoA']->id,
        $this->activoB->id,
        $this->activoInd1->id,
        $this->activoInd2->id,
    ]);
});

it('un activo individual sin unidades entregables en ese almacén NO aparece al filtrar por almacén', function () {
    $activoIndAjeno = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Tablet']);
    UnidadActivo::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id,
        'activo_id' => $activoIndAjeno->id,
        'almacen_id' => $this->datos['almacenB']->id,
    ]);

    $respuesta = $this->actingAs($this->admin)->get("/activos?almacen_id={$this->datos['almacenA']->id}");
    $ids = collect($respuesta->viewData('page')['props']['activos'])->pluck('id');

    expect($ids)->not->toContain($activoIndAjeno->id);
});

it('sin filtro de almacén, la card de un activo por cantidad muestra el total de TODOS los almacenes', function () {
    // El activo A tiene 5 (M) + 3 (L) en almacén A. Se agrega otro saldo en
    // otro almacén para confirmar que el total global los suma todos.
    SaldoInventario::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenB']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => null,
        'cantidad' => 7,
    ]);

    $respuesta = $this->actingAs($this->admin)->get('/activos');
    $fila = collect($respuesta->viewData('page')['props']['activos'])->firstWhere('id', $this->datos['activoA']->id);

    expect($fila['existencias'])->toBe(5 + 3 + 7);
});

it('con filtro de almacén, la card de un activo por cantidad muestra SÓLO la existencia de ese almacén', function () {
    SaldoInventario::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenB']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => null,
        'cantidad' => 7,
    ]);

    $respuesta = $this->actingAs($this->admin)->get("/activos?almacen_id={$this->datos['almacenA']->id}");
    $fila = collect($respuesta->viewData('page')['props']['activos'])->firstWhere('id', $this->datos['activoA']->id);

    // Sólo lo que hay en almacén A (5 + 3), nunca los 7 de almacén B.
    expect($fila['existencias'])->toBe(5 + 3);
});

it('con filtro de almacén, un activo de seguimiento individual muestra el conteo real de unidades entregables ahí', function () {
    $respuesta = $this->actingAs($this->admin)->get("/activos?almacen_id={$this->datos['almacenA']->id}");
    $fila = collect($respuesta->viewData('page')['props']['activos'])->firstWhere('id', $this->activoInd1->id);

    expect($fila['existencias'])->toBe(2);
});
