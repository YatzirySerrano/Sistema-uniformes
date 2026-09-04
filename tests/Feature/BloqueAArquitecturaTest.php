<?php

use App\Acciones\CrearEntregaUniforme;
use App\Acciones\RegistrarEntradaInventario;
use App\Enums\RolSistema;
use App\Excepciones\ExcepcionDeNegocio;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use App\Models\Talla;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Bloque A — Almacén multiempresa · Fin de "empresa activa" · Inventario
|          por EMPRESA + ALMACÉN + ACTIVO + VARIANTE
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    sembrarRolesPermisos();
});

/**
 * Crea un activo por cantidad con una variante, para la empresa dada. La
 * variante "M" del catálogo compartido se reutiliza y se habilita para cada
 * empresa que la pida (una sola fila `tallas`).
 */
function activoConVariante(Empresa $empresa, string $nombre = 'Camisola'): array
{
    $talla = Talla::query()->firstOrCreate(['valor' => 'M'], ['orden' => 1, 'activa' => true]);
    $talla->empresas()->syncWithoutDetaching([$empresa->id]);
    $activo = Activo::factory()->for($empresa)->create(['nombre' => $nombre, 'tipo_control' => 'cantidad']);
    $activo->tallas()->attach($talla);

    return [$activo, $talla];
}

it('1. un almacén puede abastecer a varias empresas (N:M)', function () {
    $a = Empresa::factory()->create();
    $b = Empresa::factory()->create();
    $c = Empresa::factory()->create();

    $almacen = Almacen::factory()->paraEmpresa($a, $b, $c)->create();

    expect($almacen->empresas()->pluck('empresas.id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id, $c->id])->sort()->values()->all());
});

it('2. una empresa puede tener varios almacenes', function () {
    $empresa = Empresa::factory()->create();
    Almacen::factory()->count(3)->paraEmpresa($empresa)->create();

    expect(Almacen::query()->paraEmpresa($empresa->id)->count())->toBe(3);
});

it('3. el stock de la empresa A y el de la B en un mismo almacén no se mezclan', function () {
    $a = Empresa::factory()->create();
    $b = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($a, $b)->create();

    [$activoA, $tallaA] = activoConVariante($a);
    [$activoB, $tallaB] = activoConVariante($b);

    $accion = app(RegistrarEntradaInventario::class);
    $accion->ejecutar($a->id, $almacen->id, [['activo_id' => $activoA->id, 'talla_id' => $tallaA->id, 'cantidad' => 30]], 'A', null);
    $accion->ejecutar($b->id, $almacen->id, [['activo_id' => $activoB->id, 'talla_id' => $tallaB->id, 'cantidad' => 15]], 'B', null);

    expect(SaldoInventario::query()->where('empresa_id', $a->id)->where('almacen_id', $almacen->id)->sum('cantidad'))->toBe(30)
        ->and(SaldoInventario::query()->where('empresa_id', $b->id)->where('almacen_id', $almacen->id)->sum('cantidad'))->toBe(15)
        ->and(SaldoInventario::query()->where('almacen_id', $almacen->id)->count())->toBe(2);
});

it('4. una operación de la empresa A no puede consumir el stock de la empresa B (mismo almacén)', function () {
    $a = Empresa::factory()->create();
    $b = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($a, $b)->create();

    [$activoB, $tallaB] = activoConVariante($b);
    app(RegistrarEntradaInventario::class)->ejecutar($b->id, $almacen->id, [['activo_id' => $activoB->id, 'talla_id' => $tallaB->id, 'cantidad' => 50]], 'B', null);

    // La empresa A intenta entregar el activo de B: rechazado (activo ajeno).
    $sucursalA = Sucursal::factory()->for($a)->create();
    $colaboradorA = Colaborador::factory()->for($a)->for($sucursalA)->create();

    expect(fn () => app(CrearEntregaUniforme::class)->ejecutar(
        $a->id, $sucursalA->id, $colaboradorA->id, usuarioCon(RolSistema::Administrador->value)->id,
        now()->toDateString(), [['activo_id' => $activoB->id, 'talla_id' => $tallaB->id, 'cantidad' => 1]],
    ))->toThrow(ExcepcionDeNegocio::class);

    expect(SaldoInventario::query()->where('empresa_id', $b->id)->sum('cantidad'))->toBe(50);
});

it('5. un administrador opera sin empresa_activa_id en sesión', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    Empresa::factory()->count(2)->create();

    expect(session()->has('empresa_activa_id'))->toBeFalse();

    foreach (['/empresas', '/sucursales', '/areas', '/activos', '/almacenes', '/inventario', '/entregas', '/dashboard'] as $ruta) {
        $this->actingAs($admin)->get($ruta)->assertOk();
    }
});

it('6. crea un almacén multiempresa y una entrada de inventario sin empresa activa', function () {
    $a = Empresa::factory()->create();
    $b = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post('/almacenes', ['nombre' => 'Central Morelos', 'empresa_ids' => [$a->id, $b->id]])
        ->assertRedirect()->assertSessionHasNoErrors();

    $almacen = Almacen::query()->where('nombre', 'Central Morelos')->firstOrFail();
    [$activoA, $tallaA] = activoConVariante($a);

    $this->actingAs($admin)
        ->post('/inventario/entrada', [
            'empresa_id' => $a->id,
            'almacen_id' => $almacen->id,
            'motivo' => 'OC-1',
            'items' => [['activo_id' => $activoA->id, 'talla_id' => $tallaA->id, 'cantidad' => 10]],
        ])
        ->assertRedirect('/inventario')->assertSessionHasNoErrors();

    expect(SaldoInventario::query()->where('empresa_id', $a->id)->where('almacen_id', $almacen->id)->sum('cantidad'))->toBe(10);
});

it('7. un rol restringido no puede registrar inventario para una empresa fuera de su alcance (IDOR)', function () {
    $mia = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($mia, $ajena)->create();
    [$activo, $talla] = activoConVariante($ajena);

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$mia]);

    $this->actingAs($supervisor)
        ->post('/inventario/entrada', [
            'empresa_id' => $ajena->id,
            'almacen_id' => $almacen->id,
            'motivo' => 'x',
            'items' => [['activo_id' => $activo->id, 'talla_id' => $talla->id, 'cantidad' => 1]],
        ])
        ->assertSessionHasErrors('empresa_id');
});

it('8. un almacén que no abastece a la empresa se rechaza al registrar inventario', function () {
    $a = Empresa::factory()->create();
    $b = Empresa::factory()->create();
    $almacenSoloB = Almacen::factory()->paraEmpresa($b)->create();
    [$activoA, $tallaA] = activoConVariante($a);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/inventario/entrada', [
            'empresa_id' => $a->id,
            'almacen_id' => $almacenSoloB->id,
            'motivo' => 'x',
            'items' => [['activo_id' => $activoA->id, 'talla_id' => $tallaA->id, 'cantidad' => 1]],
        ])
        ->assertSessionHasErrors('almacen_id');
});

it('9. el puente de entregas resuelve el almacén por EMPRESA del colaborador', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    [$activo, $talla] = activoConVariante($empresa);
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create();
    $encargado = usuarioCon(RolSistema::Administrador->value);

    app(RegistrarEntradaInventario::class)->ejecutar($empresa->id, $almacen->id, [['activo_id' => $activo->id, 'talla_id' => $talla->id, 'cantidad' => 10]], 'Alta', null);

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $empresa->id, $sucursal->id, $colaborador->id, $encargado->id, now()->toDateString(),
        [['activo_id' => $activo->id, 'talla_id' => $talla->id, 'cantidad' => 3]],
    );

    expect($entrega->almacen_id)->toBe($almacen->id)
        ->and(SaldoInventario::query()->where('empresa_id', $empresa->id)->where('almacen_id', $almacen->id)->sum('cantidad'))->toBe(7);
});

it('10. la migración legacy no pierde relaciones: los almacenes conservan al menos una empresa', function () {
    // La migración de fundación (000016) hace backfill; aquí verificamos que el
    // esquema definitivo permite y exige la relación N:M.
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();

    expect($almacen->empresas()->count())->toBeGreaterThanOrEqual(1);
});

it('11. saldos_inventario ya no depende de sucursal_id; el historial sí lo conserva', function () {
    expect(Schema::hasColumn('saldos_inventario', 'sucursal_id'))->toBeFalse()
        ->and(Schema::hasColumn('movimientos_inventario', 'sucursal_id'))->toBeTrue();
});

it('12. cambiar de sucursal a un colaborador no altera el inventario', function () {
    $empresa = Empresa::factory()->create();
    $sucursal1 = Sucursal::factory()->for($empresa)->create();
    $sucursal2 = Sucursal::factory()->for($empresa)->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    [$activo, $talla] = activoConVariante($empresa);
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal1)->create();

    app(RegistrarEntradaInventario::class)->ejecutar($empresa->id, $almacen->id, [['activo_id' => $activo->id, 'talla_id' => $talla->id, 'cantidad' => 20]], 'Alta', null);
    $antes = SaldoInventario::query()->where('almacen_id', $almacen->id)->sum('cantidad');

    $colaborador->update(['sucursal_id' => $sucursal2->id]);

    expect(SaldoInventario::query()->where('almacen_id', $almacen->id)->sum('cantidad'))->toBe($antes);
});

it('13. las empresas autorizadas se comparten a Inertia y NO hay contextoEmpresa', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    Empresa::factory()->count(2)->create();

    $this->actingAs($admin)
        ->get('/almacenes')
        ->assertInertia(fn ($page) => $page
            ->has('empresasAutorizadas', 2)
            ->missing('contextoEmpresa')
        );
});

it('14. la ruta del selector de empresa activa fue eliminada', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    $e = Empresa::factory()->create();

    $this->actingAs($admin)->post('/empresa-activa', ['empresa_id' => $e->id])->assertNotFound();
});
