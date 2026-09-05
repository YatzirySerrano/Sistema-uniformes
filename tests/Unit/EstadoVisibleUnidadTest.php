<?php

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\EstadoVisibleUnidad;
use App\Models\UnidadActivo;

function unidadDePrueba(EstadoUnidadActivo $estado, CondicionUnidadActivo $condicion): UnidadActivo
{
    return UnidadActivo::factory()->make([
        'empresa_id' => 1,
        'activo_id' => 1,
        'almacen_id' => 1,
        'estado' => $estado,
        'condicion' => $condicion,
    ]);
}

it('en almacén y funcionando es Disponible', function () {
    $unidad = unidadDePrueba(EstadoUnidadActivo::EnAlmacen, CondicionUnidadActivo::Funcionando);

    expect($unidad->estadoVisible())->toBe(EstadoVisibleUnidad::Disponible)
        ->and($unidad->esEntregable())->toBeTrue();
});

it('asignada y funcionando es Asignado', function () {
    $unidad = unidadDePrueba(EstadoUnidadActivo::Asignada, CondicionUnidadActivo::Funcionando);

    expect($unidad->estadoVisible())->toBe(EstadoVisibleUnidad::Asignado)
        ->and($unidad->esEntregable())->toBeFalse();
});

it('en reparación es Reparación sin importar el estado de posesión', function () {
    expect(unidadDePrueba(EstadoUnidadActivo::EnAlmacen, CondicionUnidadActivo::EnReparacion)->estadoVisible())
        ->toBe(EstadoVisibleUnidad::Reparacion);
    expect(unidadDePrueba(EstadoUnidadActivo::Asignada, CondicionUnidadActivo::EnReparacion)->estadoVisible())
        ->toBe(EstadoVisibleUnidad::Reparacion);
});

it('inservible se agrupa como Reparación pero NO se convierte en Baja', function () {
    $unidad = unidadDePrueba(EstadoUnidadActivo::EnAlmacen, CondicionUnidadActivo::Inservible);

    expect($unidad->estadoVisible())->toBe(EstadoVisibleUnidad::Reparacion)
        ->and($unidad->estado)->toBe(EstadoUnidadActivo::EnAlmacen)
        ->and($unidad->condicion)->toBe(CondicionUnidadActivo::Inservible)
        ->and($unidad->esEntregable())->toBeFalse();
});

it('perdido es Perdido sin importar el estado de posesión', function () {
    expect(unidadDePrueba(EstadoUnidadActivo::EnAlmacen, CondicionUnidadActivo::Perdido)->estadoVisible())
        ->toBe(EstadoVisibleUnidad::Perdido);
    expect(unidadDePrueba(EstadoUnidadActivo::Asignada, CondicionUnidadActivo::Perdido)->estadoVisible())
        ->toBe(EstadoVisibleUnidad::Perdido);
});

it('robado es Robado y tiene prioridad sobre perdido/reparación', function () {
    expect(unidadDePrueba(EstadoUnidadActivo::Asignada, CondicionUnidadActivo::Robado)->estadoVisible())
        ->toBe(EstadoVisibleUnidad::Robado);
});

it('baja es Baja sin importar la condición', function () {
    expect(unidadDePrueba(EstadoUnidadActivo::Baja, CondicionUnidadActivo::Funcionando)->estadoVisible())
        ->toBe(EstadoVisibleUnidad::Baja);
    expect(unidadDePrueba(EstadoUnidadActivo::Baja, CondicionUnidadActivo::Robado)->estadoVisible())
        ->toBe(EstadoVisibleUnidad::Baja);
});

it('esEntregable() sigue exigiendo en_almacen Y funcionando exactamente', function () {
    expect(unidadDePrueba(EstadoUnidadActivo::EnAlmacen, CondicionUnidadActivo::Inservible)->esEntregable())->toBeFalse();
    expect(unidadDePrueba(EstadoUnidadActivo::Asignada, CondicionUnidadActivo::Funcionando)->esEntregable())->toBeFalse();
    expect(unidadDePrueba(EstadoUnidadActivo::EnAlmacen, CondicionUnidadActivo::Funcionando)->esEntregable())->toBeTrue();
});
