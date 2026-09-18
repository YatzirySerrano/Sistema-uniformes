<?php

use App\Enums\EstadoDevolucion;
use App\Enums\EstadoEntrega;
use App\Models\Devolucion;
use App\Models\EntregaUniforme;
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

it('resume una lista de registros anidados (p. ej. detalles de una entrega) en vez de volcar JSON crudo', function () {
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
        ->and($fila['ahora'])->toBe('2 elementos');
});

it('oculta la clave "renglones" del diff genérico: tiene su propia sección en Auditoría de Traspasos', function () {
    $servicio = new DescripcionAuditoria;

    $cambios = $servicio->cambios(null, null, [
        'folio' => 'TRA-000001',
        'renglones' => [
            ['control' => 'cantidad', 'activo_origen' => 'Camisa', 'talla' => 'M', 'cantidad' => 10, 'unidad_codigo' => null, 'activo_destino' => 'Camisa'],
        ],
    ]);

    expect(cambio($cambios, 'Renglones'))->toBeNull()
        ->and(cambio($cambios, 'Folio'))->not->toBeNull();
});

it('humaniza un array de un solo registro anidado en singular', function () {
    $servicio = new DescripcionAuditoria;

    $cambios = $servicio->cambios(null, null, [
        'detalles' => [['activo' => 'Camisa', 'cantidad' => 2]],
    ]);

    expect(cambio($cambios, 'Detalles')['ahora'])->toBe('1 elemento');
});

it('humaniza el estado de una EntregaUniforme usando su etiqueta', function () {
    $servicio = new DescripcionAuditoria;

    $cambios = $servicio->cambios(EntregaUniforme::class, [
        'estado' => EstadoEntrega::PendienteFirma->value,
    ], [
        'estado' => EstadoEntrega::Firmada->value,
    ]);

    expect(cambio($cambios, 'Estado'))->toBe([
        'campo' => 'Estado',
        'antes' => 'Pendiente de firma',
        'ahora' => 'Firmada',
    ]);
});

it('humaniza el estado de una Devolución usando su etiqueta', function () {
    $servicio = new DescripcionAuditoria;

    $cambios = $servicio->cambios(Devolucion::class, [
        'estado' => EstadoDevolucion::PendienteFirma->value,
    ], [
        'estado' => EstadoDevolucion::Confirmada->value,
    ]);

    expect(cambio($cambios, 'Estado'))->toBe([
        'campo' => 'Estado',
        'antes' => 'Pendiente de firma',
        'ahora' => 'Confirmada',
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

// ------------------------------------------------------------------
// categoria(): clasificación CRUD comprensible (Creación/Actualización/
// Eliminación/Reactivación) para el filtro y las insignias de Auditoría.
// NUNCA heurísticas de texto sobre la descripción — sólo el cambio real
// capturado en activo/activa/deleted_at, o el verbo exacto de la acción.
// ------------------------------------------------------------------

it('crear -> creación; editar -> actualización (por el verbo exacto de la acción, sin antes/después)', function () {
    $servicio = new DescripcionAuditoria;

    expect($servicio->categoria('crear', null, null))->toBe('creacion')
        ->and($servicio->categoria('conjunto_crear', null, null))->toBe('creacion')
        ->and($servicio->categoria('editar', null, null))->toBe('actualizacion')
        ->and($servicio->categoria('area_editar', null, null))->toBe('actualizacion');
});

it('activo/activa true->false es Eliminación y false->true es Reactivación, con prioridad sobre el nombre de la acción', function () {
    $servicio = new DescripcionAuditoria;

    // La acción se llama "editar" pero el cambio real es un apagado: manda
    // el dato real, nunca el nombre de la acción.
    expect($servicio->categoria('editar', ['activo' => true], ['activo' => false]))->toBe('eliminacion')
        ->and($servicio->categoria('editar', ['activa' => false], ['activa' => true]))->toBe('reactivacion');
});

it('desactivar/eliminar -> Eliminación; activar -> Reactivación (por el verbo, cuando no hay antes/después capturado)', function () {
    $servicio = new DescripcionAuditoria;

    expect($servicio->categoria('desactivar', null, null))->toBe('eliminacion')
        ->and($servicio->categoria('conjunto_desactivar', null, null))->toBe('eliminacion')
        ->and($servicio->categoria('eliminar', null, null))->toBe('eliminacion')
        ->and($servicio->categoria('activar', null, null))->toBe('reactivacion')
        ->and($servicio->categoria('conjunto_activar', null, null))->toBe('reactivacion');
});

it('deleted_at nulo->con valor es Eliminación; con valor->nulo (restore) es Reactivación', function () {
    $servicio = new DescripcionAuditoria;

    expect($servicio->categoria('editar', ['deleted_at' => null], ['deleted_at' => '2026-01-01 10:00:00']))->toBe('eliminacion')
        ->and($servicio->categoria('editar', ['deleted_at' => '2026-01-01 10:00:00'], ['deleted_at' => null]))->toBe('reactivacion');
});

it('una acción operativa (sin verbo CRUD y sin cambio de estado) no clasifica en ninguna categoría', function () {
    $servicio = new DescripcionAuditoria;

    expect($servicio->categoria('ajuste', null, null))->toBeNull()
        ->and($servicio->categoria('entrada', null, null))->toBeNull()
        ->and($servicio->categoria('unidad_baja', null, null))->toBeNull()
        ->and($servicio->categoria('unidad_incidencia', null, null))->toBeNull()
        ->and($servicio->categoria('unidad_recuperacion', null, null))->toBeNull()
        ->and($servicio->categoria('confirmar', null, null))->toBeNull();
});
