<?php

use App\Enums\EstadoEntrega;
use App\Models\CorreccionEntrega;
use App\Models\Empresa;
use App\Models\EntregaUniforme;

/**
 * Bug de QA: `EntregasDemoSeeder` firmaba la entrega ANTES de corregirla,
 * violando la inmutabilidad de una entrega firmada (`CorregirEntrega` lanza
 * `ExcepcionDeNegocioSimple` en ese caso — la regla es correcta y no se
 * toca). El fix reordena el seeder: crear → corregir (aún pendiente) →
 * firmar. Corre el `DatabaseSeeder` completo (igual que `php artisan
 * db:seed`) SIEMPRE contra la base de datos de pruebas — nunca la real.
 */
it('el seeder completo corre sin excepciones y genera el escenario "corregida" sin tocar una entrega firmada', function () {
    $this->seed();

    $empresa = Empresa::query()->where('codigo', 'EMP-A')->firstOrFail();

    $corregida = EntregaUniforme::query()
        ->where('empresa_id', $empresa->id)
        ->whereHas('correcciones')
        ->latest('id')
        ->first();

    expect($corregida)->not->toBeNull()
        ->and($corregida->estado)->toBe(EstadoEntrega::Firmada)
        ->and(CorreccionEntrega::query()->where('entrega_uniforme_id', $corregida->id)->exists())->toBeTrue();
});
