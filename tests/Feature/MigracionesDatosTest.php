<?php

use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Estado definitivo del esquema tras los bloques A + Catálogos compartidos
|--------------------------------------------------------------------------
| Las migraciones ya corrieron (RefreshDatabase). El esquema resultante debe
| reflejar: Almacén↔Empresa N:M, inventario por almacén, catálogos compartidos
| (tipos/categorías/variantes sin `empresa_id`, habilitados por pivote) y
| `talla_id` nullable ("sin variante" = NULL, sin fila comodín).
*/

it('la tabla legacy almacen_sucursal ya no existe', function () {
    expect(Schema::hasTable('almacen_sucursal'))->toBeFalse();
});

it('almacenes ya no tiene la columna empresa_id y existe la pivote almacen_empresa', function () {
    expect(Schema::hasColumn('almacenes', 'empresa_id'))->toBeFalse()
        ->and(Schema::hasTable('almacen_empresa'))->toBeTrue()
        ->and(Schema::hasColumns('almacen_empresa', ['almacen_id', 'empresa_id']))->toBeTrue();
});

it('saldos_inventario ya no tiene sucursal_id pero movimientos_inventario sí (procedencia)', function () {
    expect(Schema::hasColumn('saldos_inventario', 'sucursal_id'))->toBeFalse()
        ->and(Schema::hasColumn('saldos_inventario', 'almacen_id'))->toBeTrue()
        ->and(Schema::hasColumn('movimientos_inventario', 'sucursal_id'))->toBeTrue();
});

it('los catálogos son compartidos: sin empresa_id ni es_comodin, con pivotes por empresa', function () {
    foreach (['tipos_activo', 'categorias_activo', 'tallas'] as $tabla) {
        expect(Schema::hasColumn($tabla, 'empresa_id'))->toBeFalse();
    }

    expect(Schema::hasColumn('tallas', 'es_comodin'))->toBeFalse()
        ->and(Schema::hasColumn('tallas', 'valor_normalizado'))->toBeTrue()
        ->and(Schema::hasTable('tipo_activo_empresa'))->toBeTrue()
        ->and(Schema::hasTable('categoria_activo_empresa'))->toBeTrue()
        ->and(Schema::hasTable('talla_empresa'))->toBeTrue();
});

it('el inventario admite "sin variante": talla_id es nullable', function () {
    $columna = collect(Schema::getColumns('saldos_inventario'))
        ->firstWhere('name', 'talla_id');

    expect($columna['nullable'])->toBeTrue();
});
