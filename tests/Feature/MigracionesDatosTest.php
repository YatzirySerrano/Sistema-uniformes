<?php

use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Models\TipoActivo;
use Illuminate\Support\Facades\DB;

/**
 * Prueba directa de las migraciones de datos del bloque de fundación,
 * ejecutando su `up()` sobre un estado legacy construido a mano (con
 * RefreshDatabase las migraciones corren antes de existir empresas).
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

it('migra automáticamente los saldos de una sucursal con un único almacén abastecedor', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $almacen = Almacen::factory()->for($empresa)->create();
    $almacen->sucursales()->attach($sucursal);
    $talla = Talla::factory()->for($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();

    $saldo = SaldoInventario::factory()->legacy($sucursal)->create([
        'empresa_id' => $empresa->id, 'activo_id' => $activo->id, 'talla_id' => $talla->id, 'cantidad' => 30,
    ]);

    correrMigracion('2026_09_02_000014_migrar_saldos_legacy_a_almacen.php');

    expect($saldo->fresh()->almacen_id)->toBe($almacen->id)
        ->and($saldo->fresh()->sucursal_id)->toBeNull();
    $this->assertDatabaseHas('movimientos_inventario', ['tipo' => 'migracion_legacy', 'almacen_id' => $almacen->id]);
});

it('NO adivina cuando la sucursal tiene varios almacenes o ninguno', function () {
    $empresa = Empresa::factory()->create();
    $talla = Talla::factory()->for($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();

    $ambigua = Sucursal::factory()->for($empresa)->create();
    Almacen::factory()->count(2)->for($empresa)->create()->each(fn (Almacen $a) => $a->sucursales()->attach($ambigua));

    $huerfana = Sucursal::factory()->for($empresa)->create();

    $s1 = SaldoInventario::factory()->legacy($ambigua)->create(['empresa_id' => $empresa->id, 'activo_id' => $activo->id, 'talla_id' => $talla->id, 'cantidad' => 10]);
    $s2 = SaldoInventario::factory()->legacy($huerfana)->create(['empresa_id' => $empresa->id, 'activo_id' => $activo->id, 'talla_id' => $talla->id, 'cantidad' => 10]);

    correrMigracion('2026_09_02_000014_migrar_saldos_legacy_a_almacen.php');

    expect($s1->fresh()->almacen_id)->toBeNull()
        ->and($s1->fresh()->sucursal_id)->toBe($ambigua->id)
        ->and($s2->fresh()->almacen_id)->toBeNull()
        ->and($s2->fresh()->sucursal_id)->toBe($huerfana->id);
});

it('la migración de saldos legacy es idempotente', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $almacen = Almacen::factory()->for($empresa)->create();
    $almacen->sucursales()->attach($sucursal);
    $talla = Talla::factory()->for($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();

    SaldoInventario::factory()->legacy($sucursal)->create([
        'empresa_id' => $empresa->id, 'activo_id' => $activo->id, 'talla_id' => $talla->id, 'cantidad' => 30,
    ]);

    correrMigracion('2026_09_02_000014_migrar_saldos_legacy_a_almacen.php');
    correrMigracion('2026_09_02_000014_migrar_saldos_legacy_a_almacen.php');

    expect(DB::table('movimientos_inventario')->where('tipo', 'migracion_legacy')->count())->toBe(1)
        ->and((int) SaldoInventario::query()->where('empresa_id', $empresa->id)->sum('cantidad'))->toBe(30);
});
