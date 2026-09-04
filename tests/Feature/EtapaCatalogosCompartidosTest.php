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
 * Etapa 1 — Catálogos compartidos. Tipos, categorías y variantes son de
 * plataforma y se habilitan por empresa (N:M). Catálogo compartido ≠ inventario
 * compartido: los activos y el stock siguen separados por empresa.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->a = Empresa::factory()->create(['nombre_comercial' => 'Empresa A']);
    $this->b = Empresa::factory()->create(['nombre_comercial' => 'Empresa B']);
    $this->c = Empresa::factory()->create(['nombre_comercial' => 'Empresa C']);
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->a]);
});

it('A+B) un tipo habilitado para A y B aparece en el buscador de ambas', function () {
    $tipo = TipoActivo::factory()->paraEmpresa($this->a, $this->b)->create(['nombre' => 'Prenda']);

    foreach ([$this->a, $this->b] as $empresa) {
        $nombres = $this->actingAs($this->admin)
            ->getJson('/tipos-activo/buscar?empresa_id='.$empresa->id.'&q=prenda')
            ->assertOk()->json('tipos.*.nombre');
        expect($nombres)->toContain('Prenda');
    }

    expect($tipo->empresas()->count())->toBe(2);
});

it('C) un catálogo NO habilitado para C no aparece en C', function () {
    TipoActivo::factory()->paraEmpresa($this->a, $this->b)->create(['nombre' => 'Prenda']);
    CategoriaActivo::factory()->paraEmpresa($this->a, $this->b)->create(['nombre' => 'Camisola']);
    Talla::factory()->paraEmpresa($this->a, $this->b)->create(['valor' => 'M']);

    $this->actingAs($this->admin)
        ->getJson('/tipos-activo/buscar?empresa_id='.$this->c->id.'&q=prenda')
        ->assertOk()->assertJsonCount(0, 'tipos');
    $this->actingAs($this->admin)
        ->getJson('/categorias-activo/buscar?empresa_id='.$this->c->id.'&q=camisola')
        ->assertOk()->assertJsonCount(0, 'categorias');
    $this->actingAs($this->admin)
        ->getJson('/tallas/buscar?empresa_id='.$this->c->id.'&q=M')
        ->assertOk()->assertJsonCount(0, 'tallas');
});

it('C) un activo de A no puede usar un tipo/categoría/variante no habilitado para A', function () {
    $tipoSoloB = TipoActivo::factory()->paraEmpresa($this->b)->create();
    $categoriaSoloB = CategoriaActivo::factory()->paraEmpresa($this->b)->create();
    $tallaSoloB = Talla::factory()->paraEmpresa($this->b)->create(['valor' => 'Z']);

    $this->actingAs($this->admin)->from('/activos/crear')
        ->post('/activos', [
            'empresa_id' => $this->a->id, 'nombre' => 'X', 'tipo_control' => 'cantidad',
            'tipo_activo_id' => $tipoSoloB->id, 'categoria_id' => $categoriaSoloB->id,
            'tallas' => [$tallaSoloB->id],
        ])
        ->assertSessionHasErrors(['tipo_activo_id', 'categoria_id', 'tallas.0']);
});

it('D+E) alta inline habilita sólo la empresa actual; el admin luego habilita otra', function () {
    $this->actingAs($this->admin)
        ->postJson('/tipos-activo/rapido', ['nombre' => 'Herramienta', 'empresa_id' => $this->a->id])
        ->assertOk();

    $tipo = TipoActivo::query()->where('nombre', 'Herramienta')->first();
    expect($tipo->empresas()->pluck('empresas.id')->all())->toBe([$this->a->id]);

    $this->actingAs($this->admin)
        ->put("/tipos-activo/{$tipo->id}/empresas", ['empresa_ids' => [$this->a->id, $this->b->id]])
        ->assertRedirect();
    expect($tipo->fresh()->empresas()->count())->toBe(2);
});

it('F) deshabilitar A no afecta a B', function () {
    $tipo = TipoActivo::factory()->paraEmpresa($this->a, $this->b)->create(['nombre' => 'Prenda']);

    $this->actingAs($this->admin)
        ->put("/tipos-activo/{$tipo->id}/empresas", ['empresa_ids' => [$this->b->id]])
        ->assertRedirect();

    expect($tipo->fresh()->empresas()->whereKey($this->a->id)->exists())->toBeFalse()
        ->and($tipo->fresh()->empresas()->whereKey($this->b->id)->exists())->toBeTrue();

    $this->actingAs($this->admin)
        ->getJson('/tipos-activo/buscar?empresa_id='.$this->b->id.'&q=prenda')
        ->assertJsonCount(1, 'tipos');
});

it('G) desactivar globalmente un tipo lo retira de nuevas selecciones en todas las empresas', function () {
    $tipo = TipoActivo::factory()->paraEmpresa($this->a, $this->b)->create(['nombre' => 'Prenda']);

    $this->actingAs($this->admin)->post("/tipos-activo/{$tipo->id}/estado")->assertRedirect();

    foreach ([$this->a, $this->b] as $empresa) {
        $this->actingAs($this->admin)
            ->getJson('/tipos-activo/buscar?empresa_id='.$empresa->id.'&q=prenda')
            ->assertJsonCount(0, 'tipos');
    }
});

it('H) un activo histórico conserva su tipo/categoría/variante aunque se deshabiliten', function () {
    $tipo = TipoActivo::factory()->paraEmpresa($this->a)->create();
    $categoria = CategoriaActivo::factory()->paraEmpresa($this->a)->create();
    $talla = Talla::factory()->paraEmpresa($this->a)->create(['valor' => 'M']);
    $activo = Activo::factory()->for($this->a)->create([
        'tipo_activo_id' => $tipo->id, 'categoria_id' => $categoria->id,
    ]);
    $activo->tallas()->attach($talla);

    // Deshabilitar de A y desactivar globalmente.
    $tipo->empresas()->detach($this->a->id);
    $categoria->empresas()->detach($this->a->id);
    $talla->empresas()->detach($this->a->id);
    $tipo->update(['activo' => false]);

    expect($activo->fresh()->tipo_activo_id)->toBe($tipo->id)
        ->and($activo->fresh()->categoria_id)->toBe($categoria->id)
        ->and($activo->tallas()->count())->toBe(1);
});

it('I) la variante "M" compartida entre A y B mantiene el stock separado', function () {
    $talla = Talla::factory()->paraEmpresa($this->a, $this->b)->create(['valor' => 'M']);
    $almacen = Almacen::factory()->paraEmpresa($this->a, $this->b)->create(['nombre' => 'Central']);
    $activoA = Activo::factory()->for($this->a)->create(['tipo_control' => 'cantidad']);
    $activoB = Activo::factory()->for($this->b)->create(['tipo_control' => 'cantidad']);
    $activoA->tallas()->attach($talla);
    $activoB->tallas()->attach($talla);

    $this->actingAs($this->admin)->post('/inventario/entrada', [
        'empresa_id' => $this->a->id, 'almacen_id' => $almacen->id, 'motivo' => 'A',
        'items' => [['activo_id' => $activoA->id, 'talla_id' => $talla->id, 'cantidad' => 30]],
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/inventario/entrada', [
        'empresa_id' => $this->b->id, 'almacen_id' => $almacen->id, 'motivo' => 'B',
        'items' => [['activo_id' => $activoB->id, 'talla_id' => $talla->id, 'cantidad' => 12]],
    ])->assertSessionHasNoErrors();

    expect(SaldoInventario::query()->where('empresa_id', $this->a->id)->where('talla_id', $talla->id)->value('cantidad'))->toBe(30)
        ->and(SaldoInventario::query()->where('empresa_id', $this->b->id)->where('talla_id', $talla->id)->value('cantidad'))->toBe(12);
});

it('J+K) un activo sin variante guarda talla_id NULL y no hay filas comodín', function () {
    $almacen = Almacen::factory()->paraEmpresa($this->a)->create();
    $mouse = Activo::factory()->for($this->a)->create(['tipo_control' => 'cantidad']);

    $this->actingAs($this->admin)->post('/inventario/entrada', [
        'empresa_id' => $this->a->id, 'almacen_id' => $almacen->id, 'motivo' => 'Compra',
        'items' => [['activo_id' => $mouse->id, 'talla_id' => null, 'cantidad' => 6]],
    ])->assertSessionHasNoErrors();

    $saldo = SaldoInventario::query()->where('activo_id', $mouse->id)->first();
    expect($saldo->talla_id)->toBeNull()
        ->and($saldo->cantidad)->toBe(6)
        ->and(Talla::query()->where('valor', 'like', '%sin variante%')->count())->toBe(0);
});

it('L) un rol restringido no puede habilitar un catálogo para empresas fuera de su alcance', function () {
    $tipo = TipoActivo::factory()->paraEmpresa($this->a)->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->a]);
    $supervisor->givePermissionTo('tipos-activo.administrar');

    $this->actingAs($supervisor)
        ->put("/tipos-activo/{$tipo->id}/empresas", ['empresa_ids' => [$this->a->id, $this->b->id]])
        ->assertSessionHasErrors('empresa_ids.1');

    expect($tipo->fresh()->empresas()->whereKey($this->b->id)->exists())->toBeFalse();
});

it('M) cross-company manipulado: registrar entrada con variante no habilitada se rechaza', function () {
    $almacen = Almacen::factory()->paraEmpresa($this->a)->create();
    $activo = Activo::factory()->for($this->a)->create(['tipo_control' => 'cantidad']);
    $tallaSoloB = Talla::factory()->paraEmpresa($this->b)->create(['valor' => 'Z']);

    $this->actingAs($this->admin)->from('/inventario/entrada')->post('/inventario/entrada', [
        'empresa_id' => $this->a->id, 'almacen_id' => $almacen->id, 'motivo' => 'x',
        'items' => [['activo_id' => $activo->id, 'talla_id' => $tallaSoloB->id, 'cantidad' => 1]],
    ])->assertSessionHasErrors('items.0.talla_id');

    expect(SaldoInventario::query()->count())->toBe(0);
});
