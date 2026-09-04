<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Talla;

/**
 * Hotfix del Bloque A: al elegir una empresa en "Registrar entrada de
 * inventario" sólo deben ofrecerse (y aceptarse) los almacenes vinculados a esa
 * empresa vía `almacen_empresa`. Nunca los de otra empresa, aunque el usuario
 * tenga alcance global o manipule el request.
 */
beforeEach(function () {
    sembrarRolesPermisos();

    $this->empresaA = Empresa::factory()->create(['nombre_comercial' => 'SIESA']);
    $this->empresaB = Empresa::factory()->create(['nombre_comercial' => 'Industrias del Valle']);

    // Almacén Sur: sólo abastece a SIESA (empresa A).
    $this->almacenSur = Almacen::factory()->paraEmpresa($this->empresaA)
        ->create(['nombre' => 'Almacén Sur', 'codigo' => 'ALM-SUR']);

    // Almacén Central: compartido entre A y B.
    $this->almacenCentral = Almacen::factory()->paraEmpresa($this->empresaA, $this->empresaB)
        ->create(['nombre' => 'Almacén Central', 'codigo' => 'ALM-CEN']);

    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresaA]);
});

function buscarAlmacenesJson(Empresa $empresa): array
{
    return test()->actingAs(test()->admin)
        ->getJson('/almacenes/buscar?empresa_id='.$empresa->id)
        ->assertOk()
        ->json('almacenes');
}

it('A: /almacenes/buscar sólo incluye Almacén Sur para la empresa que abastece', function () {
    $paraA = collect(buscarAlmacenesJson($this->empresaA))->pluck('nombre');
    $paraB = collect(buscarAlmacenesJson($this->empresaB))->pluck('nombre');

    expect($paraA)->toContain('Almacén Sur')
        ->and($paraB)->not->toContain('Almacén Sur');
});

it('C: el almacén compartido aparece para ambas empresas', function () {
    expect(collect(buscarAlmacenesJson($this->empresaA))->pluck('nombre'))->toContain('Almacén Central')
        ->and(collect(buscarAlmacenesJson($this->empresaB))->pluck('nombre'))->toContain('Almacén Central');
});

it('D: un almacén inactivo no aparece aunque abastezca a la empresa', function () {
    $this->almacenSur->update(['activo' => false]);

    expect(collect(buscarAlmacenesJson($this->empresaA))->pluck('nombre'))->not->toContain('Almacén Sur');
});

it('E: un rol restringido no obtiene almacenes de una empresa fuera de su alcance', function () {
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->empresaA]);

    // empresa_id de una empresa a la que NO tiene acceso: lista vacía, nunca los
    // almacenes de su propia empresa "por defecto".
    $this->actingAs($supervisor)
        ->getJson('/almacenes/buscar?empresa_id='.$this->empresaB->id)
        ->assertOk()
        ->assertJsonCount(0, 'almacenes');
});

it('un empresa_id inválido no cae de vuelta a "todas mis empresas"', function () {
    $this->actingAs($this->admin)
        ->getJson('/almacenes/buscar?empresa_id=999999')
        ->assertOk()
        ->assertJsonCount(0, 'almacenes');
});

it('sin empresa_id devuelve todos los almacenes activos autorizados', function () {
    $nombres = $this->actingAs($this->admin)
        ->getJson('/almacenes/buscar')
        ->assertOk()
        ->json('almacenes');

    expect(collect($nombres)->pluck('nombre'))
        ->toContain('Almacén Sur')
        ->toContain('Almacén Central');
});

it('B: registrar entrada con empresa B + Almacén Sur se rechaza con error de validación', function () {
    $activoB = Activo::factory()->for($this->empresaB)->create(['nombre' => 'Playera B', 'tipo_control' => 'cantidad']);

    $this->actingAs($this->admin)
        ->from('/inventario/entrada')
        ->post('/inventario/entrada', [
            'empresa_id' => $this->empresaB->id,
            'almacen_id' => $this->almacenSur->id,
            'motivo' => 'Intento cruzado',
            'items' => [['activo_id' => $activoB->id, 'talla_id' => null, 'cantidad' => 3]],
        ])
        ->assertSessionHasErrors('almacen_id');

    expect(SaldoInventario::query()->where('almacen_id', $this->almacenSur->id)->count())->toBe(0);
});

it('el stock se mantiene separado por empresa dentro del almacén compartido', function () {
    // "M" es una sola variante del catálogo compartido, habilitada para A y B.
    $talla = Talla::factory()->paraEmpresa($this->empresaA, $this->empresaB)->create(['valor' => 'M']);
    $activoA = Activo::factory()->for($this->empresaA)->create(['nombre' => 'Camisa A', 'tipo_control' => 'cantidad']);
    $activoB = Activo::factory()->for($this->empresaB)->create(['nombre' => 'Camisa B', 'tipo_control' => 'cantidad']);
    $activoA->tallas()->attach($talla);
    $activoB->tallas()->attach($talla);
    $tallaA = $talla;
    $tallaB = $talla;

    // Empresa A + Almacén Central.
    $this->actingAs($this->admin)->post('/inventario/entrada', [
        'empresa_id' => $this->empresaA->id,
        'almacen_id' => $this->almacenCentral->id,
        'motivo' => 'Carga A',
        'items' => [['activo_id' => $activoA->id, 'talla_id' => $tallaA->id, 'cantidad' => 20]],
    ])->assertSessionHasNoErrors();

    // Empresa B + Almacén Central (mismo almacén físico).
    $this->actingAs($this->admin)->post('/inventario/entrada', [
        'empresa_id' => $this->empresaB->id,
        'almacen_id' => $this->almacenCentral->id,
        'motivo' => 'Carga B',
        'items' => [['activo_id' => $activoB->id, 'talla_id' => $tallaB->id, 'cantidad' => 15]],
    ])->assertSessionHasNoErrors();

    $saldoA = SaldoInventario::query()
        ->where('empresa_id', $this->empresaA->id)
        ->where('almacen_id', $this->almacenCentral->id)
        ->first();
    $saldoB = SaldoInventario::query()
        ->where('empresa_id', $this->empresaB->id)
        ->where('almacen_id', $this->almacenCentral->id)
        ->first();

    expect($saldoA->cantidad)->toBe(20)
        ->and($saldoB->cantidad)->toBe(15)
        ->and(SaldoInventario::query()->where('almacen_id', $this->almacenCentral->id)->count())->toBe(2);
});

it('empresa B + almacén compartido registra correctamente', function () {
    $activoB = Activo::factory()->for($this->empresaB)->create(['nombre' => 'Gorra B', 'tipo_control' => 'cantidad']);

    $this->actingAs($this->admin)->post('/inventario/entrada', [
        'empresa_id' => $this->empresaB->id,
        'almacen_id' => $this->almacenCentral->id,
        'motivo' => 'Carga B',
        'items' => [['activo_id' => $activoB->id, 'talla_id' => null, 'cantidad' => 7]],
    ])->assertRedirect('/inventario')->assertSessionHasNoErrors();

    expect(SaldoInventario::query()
        ->where('empresa_id', $this->empresaB->id)
        ->where('almacen_id', $this->almacenCentral->id)
        ->value('cantidad'))->toBe(7);
});
