<?php

use App\Models\Activo;
use App\Models\Empresa;
use App\Models\TipoActivo;
use Illuminate\Support\Facades\Schema;

/**
 * Prueba de las migraciones de datos, ejecutando su `up()` sobre un estado
 * construido a mano (con RefreshDatabase las migraciones corren antes de existir
 * empresas).
 */
function correrMigracion(string $archivo): void
{
    $migracion = require database_path('migrations/'.$archivo);
    $migracion->up();
}

it('convierte el tipo "Uniforme / Prenda" en "Prenda" sin perder activos', function () {
    $empresa = Empresa::factory()->create();
    $mixto = TipoActivo::factory()->for($empresa)->create(['nombre' => 'Uniforme / Prenda']);
    $activo = Activo::factory()->for($empresa)->create(['tipo_activo_id' => $mixto->id]);

    correrMigracion('2026_09_02_000009_corregir_tipo_activo_uniforme_a_prenda.php');

    expect(TipoActivo::query()->where('empresa_id', $empresa->id)->where('nombre', 'Uniforme / Prenda')->exists())->toBeFalse()
        ->and($mixto->fresh()->nombre)->toBe('Prenda')
        ->and($activo->fresh()->tipo_activo_id)->toBe($mixto->id);
});

it('si ya existe "Prenda", reasigna los activos del tipo mixto y lo elimina', function () {
    $empresa = Empresa::factory()->create();
    $prenda = TipoActivo::factory()->for($empresa)->create(['nombre' => 'Prenda']);
    $mixto = TipoActivo::factory()->for($empresa)->create(['nombre' => 'Uniforme / Prenda']);
    $activo = Activo::factory()->for($empresa)->create(['tipo_activo_id' => $mixto->id]);

    correrMigracion('2026_09_02_000009_corregir_tipo_activo_uniforme_a_prenda.php');

    expect(TipoActivo::query()->whereKey($mixto->id)->exists())->toBeFalse()
        ->and($activo->fresh()->tipo_activo_id)->toBe($prenda->id);
});

/*
|--------------------------------------------------------------------------
| Estado definitivo del esquema tras el Bloque A (forward-only)
|--------------------------------------------------------------------------
| Las migraciones ya corrieron (RefreshDatabase). El esquema resultante debe
| reflejar la arquitectura Almacén↔Empresa N:M y el inventario por almacén.
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
