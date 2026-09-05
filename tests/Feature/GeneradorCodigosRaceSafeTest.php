<?php

use App\Models\Empresa;
use App\Soporte\ServicioGeneradorCodigos;
use App\Soporte\ServicioGeneradorCodigosGlobal;

/**
 * QA (Fase 9): `AlmacenController`, `TipoActivoController`, `SucursalController`,
 * `AreaController` y `ActivoController` generaban su `codigo` con
 * `count() + 1` + un bucle `while(...exists())` SIN lock — dos altas
 * concurrentes podían calcular el mismo siguiente número y la segunda
 * reventaba con una violación de unicidad (mismo patrón que el bug de folios
 * ya corregido). Cubre que los generadores centralizados son secuenciales,
 * sin huecos ni repeticiones, y correctamente aislados por ámbito/empresa.
 */
it('el generador global produce códigos secuenciales sin repetir bajo llamadas sucesivas', function () {
    $servicio = app(ServicioGeneradorCodigosGlobal::class);

    $codigos = collect(range(1, 15))->map(fn () => $servicio->siguiente('almacen', 'ALM'))->all();

    expect($codigos)->toBe([
        'ALM-0001', 'ALM-0002', 'ALM-0003', 'ALM-0004', 'ALM-0005',
        'ALM-0006', 'ALM-0007', 'ALM-0008', 'ALM-0009', 'ALM-0010',
        'ALM-0011', 'ALM-0012', 'ALM-0013', 'ALM-0014', 'ALM-0015',
    ])->and(collect($codigos)->unique())->toHaveCount(15);
});

it('el generador global aísla contadores por ámbito', function () {
    $servicio = app(ServicioGeneradorCodigosGlobal::class);

    expect($servicio->siguiente('almacen', 'ALM'))->toBe('ALM-0001')
        ->and($servicio->siguiente('tipo_activo', 'TAC'))->toBe('TAC-0001')
        ->and($servicio->siguiente('almacen', 'ALM'))->toBe('ALM-0002');
});

it('el generador por empresa produce códigos secuenciales con el prefijo indicado', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create(['codigo' => 'EMP-1']);
    $servicio = app(ServicioGeneradorCodigos::class);

    $codigos = collect(range(1, 10))->map(fn () => $servicio->siguienteConPrefijo($empresa, 'sucursal', 'SUC'))->all();

    expect($codigos)->toBe([
        'SUC-0001', 'SUC-0002', 'SUC-0003', 'SUC-0004', 'SUC-0005',
        'SUC-0006', 'SUC-0007', 'SUC-0008', 'SUC-0009', 'SUC-0010',
    ]);
});

it('el generador por empresa aísla el contador entre empresas distintas', function () {
    sembrarRolesPermisos();
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $servicio = app(ServicioGeneradorCodigos::class);

    expect($servicio->siguienteConPrefijo($empresaA, 'area', 'ARE'))->toBe('ARE-0001')
        ->and($servicio->siguienteConPrefijo($empresaB, 'area', 'ARE'))->toBe('ARE-0001')
        ->and($servicio->siguienteConPrefijo($empresaA, 'area', 'ARE'))->toBe('ARE-0002');
});

it('el generador por empresa aísla el contador entre ámbitos de la misma empresa', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $servicio = app(ServicioGeneradorCodigos::class);

    expect($servicio->siguienteConPrefijo($empresa, 'activo', 'ACT'))->toBe('ACT-0001')
        ->and($servicio->siguienteConPrefijo($empresa, 'sucursal', 'SUC'))->toBe('SUC-0001')
        ->and($servicio->siguienteConPrefijo($empresa, 'activo', 'ACT'))->toBe('ACT-0002');
});

it('el generador por empresa no colisiona con el ámbito unidad_activo ya existente', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $servicio = app(ServicioGeneradorCodigos::class);

    // `siguiente()` (unidad_activo) y `siguienteConPrefijo()` comparten la
    // misma tabla de contadores pero nunca el mismo `ambito`.
    expect($servicio->siguiente($empresa))->toBe($empresa->codigo.'-000001')
        ->and($servicio->siguienteConPrefijo($empresa, 'activo', 'ACT'))->toBe('ACT-0001')
        ->and($servicio->siguiente($empresa))->toBe($empresa->codigo.'-000002');
});
