<?php

use App\Acciones\RegistrarEntradaInventario;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\SaldoInventario;

beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
});

function entrada(array $override = []): array
{
    return array_merge([
        'empresa_id' => test()->datos['empresaA']->id,
        'almacen_id' => test()->datos['almacenA']->id,
        'motivo' => 'Compra OC-1001',
        'items' => [
            ['activo_id' => test()->datos['activoA']->id, 'talla_id' => test()->datos['tallaA']->id, 'cantidad' => 5],
        ],
    ], $override);
}

it('registra una entrada válida y crea el saldo por almacén', function () {
    $this->actingAs($this->admin)
        ->post('/inventario/entrada', entrada())
        ->assertRedirect('/inventario')
        ->assertSessionHasNoErrors();

    expect(SaldoInventario::query()->where('almacen_id', $this->datos['almacenA']->id)->value('cantidad'))->toBe(5);
});

it('un activo por cantidad SIN variantes no exige talla y usa la comodín', function () {
    $mouse = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Mouse', 'tipo_control' => 'cantidad']);

    $this->actingAs($this->admin)
        ->post('/inventario/entrada', entrada([
            'items' => [['activo_id' => $mouse->id, 'talla_id' => null, 'cantidad' => 8]],
        ]))
        ->assertRedirect('/inventario')
        ->assertSessionHasNoErrors();

    $saldo = SaldoInventario::query()->where('activo_id', $mouse->id)->first();
    $comodin = $this->datos['empresaA']->tallaComodin();
    expect($saldo->cantidad)->toBe(8)
        ->and($saldo->talla_id)->toBe($comodin->id);
});

it('exige la variante cuando el activo sí tiene variantes (error por fila)', function () {
    $this->actingAs($this->admin)
        ->from('/inventario/entrada')
        ->post('/inventario/entrada', entrada([
            'items' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => null, 'cantidad' => 3]],
        ]))
        ->assertSessionHasErrors('items.0.talla_id');
});

it('rechaza enviar variante a un activo que no usa variantes', function () {
    $cable = Activo::factory()->for($this->datos['empresaA'])->create(['tipo_control' => 'cantidad']);

    $this->actingAs($this->admin)
        ->from('/inventario/entrada')
        ->post('/inventario/entrada', entrada([
            'items' => [['activo_id' => $cable->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
        ]))
        ->assertSessionHasErrors('items.0.talla_id');
});

it('no permite activos serializados en esta pantalla', function () {
    $laptop = Activo::factory()->for($this->datos['empresaA'])->serializado()->create();

    $this->actingAs($this->admin)
        ->from('/inventario/entrada')
        ->post('/inventario/entrada', entrada([
            'items' => [['activo_id' => $laptop->id, 'talla_id' => null, 'cantidad' => 1]],
        ]))
        ->assertSessionHasErrors('items.0.activo_id');
});

it('marca la segunda fila cuando se repite activo + variante', function () {
    $this->actingAs($this->admin)
        ->from('/inventario/entrada')
        ->post('/inventario/entrada', entrada([
            'items' => [
                ['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2],
                ['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 3],
            ],
        ]))
        ->assertSessionHasErrors('items.1.activo_id');
});

it('exige cantidad mayor a 0 con error en la fila', function () {
    $this->actingAs($this->admin)
        ->from('/inventario/entrada')
        ->post('/inventario/entrada', entrada([
            'items' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 0]],
        ]))
        ->assertSessionHasErrors('items.0.cantidad');
});

it('rechaza un almacén de otra empresa o inactivo', function () {
    $this->actingAs($this->admin)->from('/inventario/entrada')
        ->post('/inventario/entrada', entrada(['almacen_id' => $this->datos['almacenB']->id]))
        ->assertSessionHasErrors('almacen_id');

    $this->datos['almacenA']->update(['activo' => false]);
    $this->actingAs($this->admin)->from('/inventario/entrada')
        ->post('/inventario/entrada', entrada())
        ->assertSessionHasErrors('almacen_id');
});

it('no explota (500) cuando items llega como cadena', function () {
    $this->actingAs($this->admin)->from('/inventario/entrada')
        ->post('/inventario/entrada', ['empresa_id' => $this->datos['empresaA']->id, 'almacen_id' => $this->datos['almacenA']->id, 'motivo' => 'x', 'items' => 'no-es-arreglo'])
        ->assertSessionHasErrors('items');
});

it('un supervisor sin permiso no puede registrar entradas', function () {
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);
    // El supervisor base sí tiene inventario.entrada; se le quita para la prueba.
    $supervisor->revokePermissionTo('inventario.entrada');
    $supervisor->roles->first()->revokePermissionTo('inventario.entrada');

    $this->actingAs($supervisor)
        ->post('/inventario/entrada', entrada())
        ->assertForbidden();
});

it('la acción resuelve la comodín para activos sin variante', function () {
    $gorra = Activo::factory()->for($this->datos['empresaA'])->create(['tipo_control' => 'cantidad']);

    app(RegistrarEntradaInventario::class)->ejecutar(
        $this->datos['empresaA']->id,
        $this->datos['almacenA']->id,
        [['activo_id' => $gorra->id, 'talla_id' => null, 'cantidad' => 4]],
        'Alta',
        null,
    );

    $saldo = SaldoInventario::query()->where('activo_id', $gorra->id)->first();
    expect($saldo->talla_id)->toBe($this->datos['empresaA']->tallaComodin()->id)
        ->and($saldo->cantidad)->toBe(4);
});
