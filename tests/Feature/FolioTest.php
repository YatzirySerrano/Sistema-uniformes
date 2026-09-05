<?php

use App\Models\Folio;
use App\Servicios\ServicioFolios;
use Illuminate\Support\Facades\Schema;

/**
 * Bug crítico de QA: los folios de Entrega/Acuse/Devolución son ÚNICOS A
 * NIVEL DE TODA LA PLATAFORMA (`entregas_uniformes.folio` no lleva código de
 * empresa), pero el contador anterior se particionaba por `empresa_id` — en
 * cuanto una segunda empresa registraba su primer documento del año, ambas
 * generaban "ENT-2026-000001" y el INSERT reventaba con
 * `UniqueConstraintViolationException`. La secuencia ahora es GLOBAL por
 * tipo+año (ver migración `..._000031_globalizar_secuencia_folios`).
 */
it('dos empresas distintas registrando su primer documento del año NUNCA reciben el mismo folio', function () {
    $servicio = app(ServicioFolios::class);

    $folioEmpresaA = $servicio->siguiente(ServicioFolios::ENTREGA);
    $folioEmpresaB = $servicio->siguiente(ServicioFolios::ENTREGA);

    expect($folioEmpresaA)->toBe('ENT-'.now()->format('Y').'-000001')
        ->and($folioEmpresaB)->toBe('ENT-'.now()->format('Y').'-000002')
        ->and($folioEmpresaA)->not->toBe($folioEmpresaB);
});

it('genera folios consecutivos sin huecos ni repeticiones para muchas llamadas seguidas', function () {
    $servicio = app(ServicioFolios::class);

    $folios = collect(range(1, 25))->map(fn () => $servicio->siguiente(ServicioFolios::ENTREGA));

    expect($folios->unique())->toHaveCount(25)
        ->and($folios->last())->toBe('ENT-'.now()->format('Y').'-000025');
});

it('cada tipo de documento tiene su propio contador independiente', function () {
    $servicio = app(ServicioFolios::class);

    $entrega1 = $servicio->siguiente(ServicioFolios::ENTREGA);
    $acuse1 = $servicio->siguiente(ServicioFolios::ACUSE);
    $devolucion1 = $servicio->siguiente(ServicioFolios::DEVOLUCION);
    $entrega2 = $servicio->siguiente(ServicioFolios::ENTREGA);

    expect($entrega1)->toBe('ENT-'.now()->format('Y').'-000001')
        ->and($acuse1)->toBe('ACU-'.now()->format('Y').'-000001')
        ->and($devolucion1)->toBe('DEV-'.now()->format('Y').'-000001')
        ->and($entrega2)->toBe('ENT-'.now()->format('Y').'-000002');
});

it('cada año tiene su propia secuencia', function () {
    $servicio = app(ServicioFolios::class);

    $folioEsteAnio = $servicio->siguiente(ServicioFolios::ENTREGA);
    $folioOtroAnio = $servicio->siguiente(ServicioFolios::ENTREGA, 2030);

    expect($folioEsteAnio)->toBe('ENT-'.now()->format('Y').'-000001')
        ->and($folioOtroAnio)->toBe('ENT-2030-000001');
});

it('la tabla folios ya no tiene columna empresa_id: el contador es global', function () {
    expect(Schema::hasColumn('folios', 'empresa_id'))->toBeFalse();

    $servicio = app(ServicioFolios::class);
    $servicio->siguiente(ServicioFolios::ENTREGA);

    expect(Folio::query()->where('tipo', 'entrega')->count())->toBe(1);
});
