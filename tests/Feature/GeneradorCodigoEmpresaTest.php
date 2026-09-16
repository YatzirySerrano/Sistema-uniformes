<?php

use App\Models\Empresa;
use App\Soporte\GeneradorCodigoEmpresa;

/**
 * Regresión de la extracción de `EmpresaController::generarCodigo()` /
 * `baseYAmbitoCodigo()` hacia `App\Soporte\GeneradorCodigoEmpresa` (para que
 * el importador de base de datos maestra pueda reutilizar exactamente el
 * mismo algoritmo). El comportamiento debe ser idéntico al de antes del
 * refactor: prefijo = primeras 6 letras del nombre comercial en mayúsculas
 * (slug), consecutivo de 2 dígitos, reconciliado contra los códigos reales
 * ya usados con ese prefijo.
 */
it('genera el mismo formato de código que produce EmpresaController::store()', function (): void {
    $servicio = app(GeneradorCodigoEmpresa::class);

    expect($servicio->generar('Alimentos del Centro'))->toBe('ALIMEN01');
    expect($servicio->generar('Alimentos del Centro'))->toBe('ALIMEN02');
});

it('usa "EMP" cuando el nombre no aporta caracteres alfanuméricos para el slug', function (): void {
    $servicio = app(GeneradorCodigoEmpresa::class);

    expect($servicio->generar('***'))->toBe('EMP01');
});

it('reconcilia contra el mayor sufijo real ya usado con ese prefijo', function (): void {
    Empresa::factory()->create(['nombre_comercial' => 'Existente', 'codigo' => 'DASTI03']);

    $servicio = app(GeneradorCodigoEmpresa::class);

    expect($servicio->generar('Dasti Uniformes'))->toBe('DASTI04');
});

it('previsualizar() no reserva el consecutivo (no autoritativo)', function (): void {
    $servicio = app(GeneradorCodigoEmpresa::class);

    expect($servicio->previsualizar('Radios del Norte'))->toBe('RADIOS01');
    expect($servicio->previsualizar('Radios del Norte'))->toBe('RADIOS01'); // sigue viendo el mismo "siguiente", no incrementó
    expect($servicio->generar('Radios del Norte'))->toBe('RADIOS01'); // el real coincide con lo previsualizado
});

it('previsualizar() devuelve null para un nombre vacío', function (): void {
    $servicio = app(GeneradorCodigoEmpresa::class);

    expect($servicio->previsualizar(''))->toBeNull();
    expect($servicio->previsualizar('   '))->toBeNull();
});
