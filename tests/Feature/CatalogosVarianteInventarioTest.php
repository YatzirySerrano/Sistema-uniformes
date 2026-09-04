<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\CategoriaActivo;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Models\TipoActivo;

/**
 * Sub-bloque correctivo de catálogos compartidos: una variante deshabilitada
 * para una empresa NO debe ofrecerse ni aceptarse en el inventario de esa
 * empresa (backend + frontend consistentes), pero sigue disponible donde esté
 * habilitada. Toggles con mensaje que nombra la empresa afectada.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->a = Empresa::factory()->create(['nombre_comercial' => 'Empresa A']);
    $this->b = Empresa::factory()->create(['nombre_comercial' => 'Empresa B']);
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->a]);

    // "M" compartida, habilitada para A y B. Un activo por cantidad en cada empresa.
    $this->m = Talla::factory()->paraEmpresa($this->a, $this->b)->create(['valor' => 'M']);
    $this->activoA = Activo::factory()->for($this->a)->create(['nombre' => 'Camisa A', 'tipo_control' => 'cantidad']);
    $this->activoB = Activo::factory()->for($this->b)->create(['nombre' => 'Camisa B', 'tipo_control' => 'cantidad']);
    $this->activoA->tallas()->attach($this->m);
    $this->activoB->tallas()->attach($this->m);
    $this->almacen = Almacen::factory()->paraEmpresa($this->a, $this->b)->create();
});

it('una variante deshabilitada para la empresa NO aparece en /activos/buscar de esa empresa', function () {
    // Deshabilitar "M" para la empresa A.
    $this->actingAs($this->admin)
        ->post("/tallas/{$this->m->id}/empresa", ['empresa_id' => $this->a->id])
        ->assertRedirect();

    $r = $this->actingAs($this->admin)
        ->getJson('/activos/buscar?empresa_id='.$this->a->id.'&control=cantidad&q=Camisa A')
        ->assertOk()->json('activos.0');

    expect($r['usa_variantes'])->toBeTrue()
        ->and($r['tallas'])->toBe([]);
});

it('la misma variante SIGUE disponible en la empresa B donde está habilitada', function () {
    $this->actingAs($this->admin)->post("/tallas/{$this->m->id}/empresa", ['empresa_id' => $this->a->id]);

    $r = $this->actingAs($this->admin)
        ->getJson('/activos/buscar?empresa_id='.$this->b->id.'&control=cantidad&q=Camisa B')
        ->assertOk()->json('activos.0');

    expect(collect($r['tallas'])->pluck('valor'))->toContain('M');
});

it('registrar entrada con una variante deshabilitada para la empresa se rechaza', function () {
    $this->actingAs($this->admin)->post("/tallas/{$this->m->id}/empresa", ['empresa_id' => $this->a->id]);

    $this->actingAs($this->admin)->from('/inventario/entrada')->post('/inventario/entrada', [
        'empresa_id' => $this->a->id, 'almacen_id' => $this->almacen->id, 'motivo' => 'x',
        'items' => [['activo_id' => $this->activoA->id, 'talla_id' => $this->m->id, 'cantidad' => 5]],
    ])->assertSessionHasErrors(['items.0.talla_id', 'items.0.activo_id']);

    expect(SaldoInventario::query()->count())->toBe(0);
});

it('un activo con variantes pero ninguna habilitada para la empresa se rechaza con mensaje claro', function () {
    // Deshabilitar "M" para A → el activo A queda con variantes pero 0 elegibles.
    $this->actingAs($this->admin)->post("/tallas/{$this->m->id}/empresa", ['empresa_id' => $this->a->id]);

    $this->actingAs($this->admin)->from('/inventario/entrada')->post('/inventario/entrada', [
        'empresa_id' => $this->a->id, 'almacen_id' => $this->almacen->id, 'motivo' => 'x',
        'items' => [['activo_id' => $this->activoA->id, 'talla_id' => null, 'cantidad' => 5]],
    ])->assertSessionHasErrors('items.0.activo_id');
});

it('registrar entrada con la variante habilitada sigue funcionando', function () {
    $this->actingAs($this->admin)->post('/inventario/entrada', [
        'empresa_id' => $this->a->id, 'almacen_id' => $this->almacen->id, 'motivo' => 'ok',
        'items' => [['activo_id' => $this->activoA->id, 'talla_id' => $this->m->id, 'cantidad' => 5]],
    ])->assertRedirect('/inventario')->assertSessionHasNoErrors();

    expect(SaldoInventario::query()->where('empresa_id', $this->a->id)->value('cantidad'))->toBe(5);
});

it('un ajuste puede corregir existencias de una variante ya deshabilitada (histórico)', function () {
    // Crear stock mientras "M" está habilitada.
    $this->actingAs($this->admin)->post('/inventario/entrada', [
        'empresa_id' => $this->a->id, 'almacen_id' => $this->almacen->id, 'motivo' => 'ok',
        'items' => [['activo_id' => $this->activoA->id, 'talla_id' => $this->m->id, 'cantidad' => 5]],
    ])->assertSessionHasNoErrors();

    // Ahora deshabilitar "M" para A.
    $this->actingAs($this->admin)->post("/tallas/{$this->m->id}/empresa", ['empresa_id' => $this->a->id]);

    // El ajuste de esa fila histórica sigue permitido.
    $this->actingAs($this->admin)->post('/inventario/ajuste', [
        'empresa_id' => $this->a->id, 'almacen_id' => $this->almacen->id,
        'activo_id' => $this->activoA->id, 'talla_id' => $this->m->id,
        'existencia_objetivo' => 2, 'motivo' => 'Merma',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(SaldoInventario::query()->where('activo_id', $this->activoA->id)->value('cantidad'))->toBe(2);
});

it('el toggle de variante por empresa devuelve un mensaje que nombra la empresa', function () {
    $this->actingAs($this->admin)
        ->post("/tallas/{$this->m->id}/empresa", ['empresa_id' => $this->a->id])
        ->assertSessionHas('toast', fn ($t) => str_contains($t['message'], 'Empresa A') && str_contains($t['message'], 'deshabilitada'));

    $this->actingAs($this->admin)
        ->post("/tallas/{$this->m->id}/empresa", ['empresa_id' => $this->a->id])
        ->assertSessionHas('toast', fn ($t) => str_contains($t['message'], 'Empresa A') && str_contains($t['message'], 'habilitada'));
});

it('los toggles de tipo y categoría por empresa nombran la empresa afectada', function () {
    $tipo = TipoActivo::factory()->paraEmpresa($this->a)->create(['nombre' => 'Prenda']);
    $categoria = CategoriaActivo::factory()->paraEmpresa($this->a)->create(['nombre' => 'Camisola']);

    $this->actingAs($this->admin)
        ->post("/tipos-activo/{$tipo->id}/empresa", ['empresa_id' => $this->b->id])
        ->assertSessionHas('toast', fn ($t) => str_contains($t['message'], 'Empresa B') && str_contains($t['message'], 'habilitado'));

    $this->actingAs($this->admin)
        ->post("/categorias-activo/{$categoria->id}/empresa", ['empresa_id' => $this->b->id])
        ->assertSessionHas('toast', fn ($t) => str_contains($t['message'], 'Empresa B'));

    expect($tipo->empresas()->whereKey($this->b->id)->exists())->toBeTrue()
        ->and($categoria->empresas()->whereKey($this->b->id)->exists())->toBeTrue();
});

it('el toggle de variante por empresa exige permiso y acceso a la empresa', function () {
    $colaborador = usuarioCon(RolSistema::Colaborador->value, [$this->a]);
    $this->actingAs($colaborador)
        ->post("/tallas/{$this->m->id}/empresa", ['empresa_id' => $this->a->id])
        ->assertForbidden();

    // Supervisor con el permiso pero SIN acceso a la empresa objetivo.
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->a]);
    $supervisor->givePermissionTo('tallas.administrar');
    $this->actingAs($supervisor)
        ->post("/tallas/{$this->m->id}/empresa", ['empresa_id' => $this->b->id])
        ->assertForbidden();
});

it('la pantalla de variantes muestra el detalle de empresas y el contexto de empresa', function () {
    $this->actingAs($this->admin)
        ->get('/tallas?empresa_id='.$this->a->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Activos/Tallas')
            ->where('tallas.0.valor', 'M')
            ->where('tallas.0.habilitada', true)
            ->has('tallas.0.empresas', 2)
            ->where('empresaSeleccionadaId', $this->a->id),
        );
});
