<?php

use App\Models\UnidadActivo;
use App\Soporte\DescripcionAuditoria;

function cambio(array $cambios, string $campo): ?array
{
    return collect($cambios)->firstWhere('campo', $campo);
}

it('humaniza tipos escalares simples: string, bool, null, integer', function () {
    $servicio = new DescripcionAuditoria;

    $cambios = $servicio->cambios(null, [
        'nombre' => 'Antes',
        'activo' => true,
        'notas' => 'algo',
        'cantidad' => 5,
    ], [
        'nombre' => 'Después',
        'activo' => false,
        'notas' => null,
        'cantidad' => 8,
    ]);

    expect(cambio($cambios, 'Nombre'))->toBe(['campo' => 'Nombre', 'antes' => 'Antes', 'ahora' => 'Después'])
        ->and(cambio($cambios, 'Estado'))->toBe(['campo' => 'Estado', 'antes' => 'Activo', 'ahora' => 'Inactivo'])
        ->and(cambio($cambios, 'Notas'))->toBe(['campo' => 'Notas', 'antes' => 'algo', 'ahora' => '—'])
        ->and(cambio($cambios, 'Cantidad'))->toBe(['campo' => 'Cantidad', 'antes' => '5', 'ahora' => '8']);
});

it('no reporta cambio cuando antes y después son iguales, incluyendo null implícito', function () {
    $servicio = new DescripcionAuditoria;

    $cambios = $servicio->cambios(null, ['nombre' => 'Igual'], ['nombre' => 'Igual']);

    expect($cambios)->toBe([]);
});

it('humaniza un enum de UnidadActivo (estado/condición) usando sus etiquetas', function () {
    $servicio = new DescripcionAuditoria;

    $cambios = $servicio->cambios(UnidadActivo::class, [
        'estado' => 'en_almacen',
        'condicion' => 'funcionando',
    ], [
        'estado' => 'asignada',
        'condicion' => 'perdido',
    ]);

    expect(cambio($cambios, 'Estado'))->toBe(['campo' => 'Estado', 'antes' => 'En almacén', 'ahora' => 'Asignada'])
        ->and(cambio($cambios, 'Condición'))->toBe(['campo' => 'Condición', 'antes' => 'Funcionando', 'ahora' => 'Perdido']);
});

it('no lanza y humaniza un array indexado simple como lista separada por comas', function () {
    $servicio = new DescripcionAuditoria;

    $cambios = $servicio->cambios(null, [
        'empresa_ids' => [1, 2],
    ], [
        'empresa_ids' => [1, 2, 3],
    ]);

    expect(cambio($cambios, 'Empresa ids'))->toBe([
        'campo' => 'Empresa ids',
        'antes' => '1, 2',
        'ahora' => '1, 2, 3',
    ]);
});

it('no lanza y humaniza un array asociativo simple como pares campo: valor', function () {
    $servicio = new DescripcionAuditoria;

    $cambios = $servicio->cambios(null, [
        'permisos' => ['ver' => true, 'editar' => false],
    ], [
        'permisos' => ['ver' => true, 'editar' => true],
    ]);

    $fila = cambio($cambios, 'Permisos');

    expect($fila)->not->toBeNull()
        ->and($fila['antes'])->toBe('ver: Sí; editar: No')
        ->and($fila['ahora'])->toBe('ver: Sí; editar: Sí');
});

it('no lanza y humaniza un array anidado como JSON legible', function () {
    $servicio = new DescripcionAuditoria;

    $cambios = $servicio->cambios(null, null, [
        'detalles' => [
            ['activo' => 'Camisa', 'cantidad' => 2],
            ['activo' => 'Pantalón', 'cantidad' => 1],
        ],
    ]);

    $fila = cambio($cambios, 'Detalles');

    expect($fila)->not->toBeNull()
        ->and($fila['antes'])->toBe('—')
        ->and(json_decode($fila['ahora'], true))->toBe([
            ['activo' => 'Camisa', 'cantidad' => 2],
            ['activo' => 'Pantalón', 'cantidad' => 1],
        ]);
});

it('no lanza cuando antes y después traen estructuras completamente distintas para la misma clave', function () {
    $servicio = new DescripcionAuditoria;

    $cambios = $servicio->cambios(null, [
        'datos' => ['a' => 1],
    ], [
        'datos' => [1, 2, 3],
    ]);

    expect(cambio($cambios, 'Datos'))->not->toBeNull();
});

it('trata un array vacío como "sin valor" en la representación humana', function () {
    $servicio = new DescripcionAuditoria;

    $cambios = $servicio->cambios(null, ['items' => []], ['items' => [1]]);

    $fila = cambio($cambios, 'Items');

    expect($fila['antes'])->toBe('—')
        ->and($fila['ahora'])->toBe('1');
});

it('oculta claves *_id y las claves técnicas fijas sin importar el tipo de valor', function () {
    $servicio = new DescripcionAuditoria;

    $cambios = $servicio->cambios(null, [
        'colaborador_id' => 1,
        'created_at' => '2026-01-01',
    ], [
        'colaborador_id' => 2,
        'created_at' => '2026-01-02',
    ]);

    expect($cambios)->toBe([]);
});
