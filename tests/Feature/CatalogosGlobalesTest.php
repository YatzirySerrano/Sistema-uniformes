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
 * Catálogos GLOBALES de plataforma (redefinición funcional). Tipos,
 * categorías y variantes ya NO se habilitan por empresa: son visibles para
 * todas las empresas por igual. Catálogo global ≠ inventario compartido: los
 * activos y el stock siguen separados por empresa.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->a = Empresa::factory()->create(['nombre_comercial' => 'Empresa A']);
    $this->b = Empresa::factory()->create(['nombre_comercial' => 'Empresa B']);
    $this->c = Empresa::factory()->create(['nombre_comercial' => 'Empresa C']);
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->a]);
});

it('un tipo global aparece en el buscador sin importar la empresa', function () {
    TipoActivo::factory()->create(['nombre' => 'Prenda']);

    foreach ([$this->a, $this->b, $this->c] as $empresa) {
        $nombres = $this->actingAs($this->admin)
            ->getJson('/tipos-activo/buscar?empresa_id='.$empresa->id.'&q=prenda')
            ->assertOk()->json('tipos.*.nombre');
        expect($nombres)->toContain('Prenda');
    }

    // También sin empresa_id: el catálogo no depende de ella.
    $this->actingAs($this->admin)
        ->getJson('/tipos-activo/buscar?q=prenda')
        ->assertOk()->assertJsonCount(1, 'tipos');
});

it('categorías y variantes también son visibles sin importar la empresa', function () {
    CategoriaActivo::factory()->create(['nombre' => 'Camisola']);
    Talla::factory()->create(['valor' => 'M']);

    $this->actingAs($this->admin)
        ->getJson('/categorias-activo/buscar?q=camisola')
        ->assertOk()->assertJsonCount(1, 'categorias');
    $this->actingAs($this->admin)
        ->getJson('/tallas/buscar?q=M')
        ->assertOk()->assertJsonCount(1, 'tallas');
});

it('un activo de cualquier empresa puede usar cualquier tipo/categoría/variante activos', function () {
    $tipo = TipoActivo::factory()->create();
    $categoria = CategoriaActivo::factory()->create();
    $talla = Talla::factory()->create(['valor' => 'Z']);

    $this->actingAs($this->admin)->from('/activos/crear')
        ->post('/activos', [
            'empresa_id' => $this->a->id, 'nombre' => 'X', 'tipo_control' => 'cantidad',
            'tipo_activo_id' => $tipo->id, 'categoria_id' => $categoria->id,
            'tallas' => [$talla->id],
        ])
        ->assertSessionHasNoErrors();

    $activo = Activo::query()->where('nombre', 'X')->firstOrFail();
    expect($activo->tipo_activo_id)->toBe($tipo->id)
        ->and($activo->categoria_id)->toBe($categoria->id)
        ->and($activo->tallas()->whereKey($talla->id)->exists())->toBeTrue();
});

it('un activo NO puede usar un tipo/categoría/variante inactivos globalmente', function () {
    $tipoInactivo = TipoActivo::factory()->create(['activo' => false]);
    $categoriaInactiva = CategoriaActivo::factory()->inactiva()->create();
    $tallaInactiva = Talla::factory()->create(['activa' => false]);

    $this->actingAs($this->admin)->from('/activos/crear')
        ->post('/activos', [
            'empresa_id' => $this->a->id, 'nombre' => 'X', 'tipo_control' => 'cantidad',
            'tipo_activo_id' => $tipoInactivo->id, 'categoria_id' => $categoriaInactiva->id,
            'tallas' => [$tallaInactiva->id],
        ])
        ->assertSessionHasErrors(['tipo_activo_id', 'categoria_id', 'tallas.0']);
});

it('el alta inline queda visible de inmediato para cualquier empresa', function () {
    $this->actingAs($this->admin)
        ->postJson('/tipos-activo/rapido', ['nombre' => 'Herramienta'])
        ->assertOk();

    $otroAdmin = usuarioCon(RolSistema::Administrador->value, [$this->b]);
    $this->actingAs($otroAdmin)
        ->getJson('/tipos-activo/buscar?empresa_id='.$this->b->id.'&q=herramienta')
        ->assertOk()->assertJsonCount(1, 'tipos');
});

it('desactivar globalmente un tipo lo retira de nuevas selecciones en todas las empresas', function () {
    $tipo = TipoActivo::factory()->create(['nombre' => 'Prenda']);

    $this->actingAs($this->admin)->post("/tipos-activo/{$tipo->id}/estado")->assertRedirect();

    foreach ([$this->a, $this->b] as $empresa) {
        $this->actingAs($this->admin)
            ->getJson('/tipos-activo/buscar?empresa_id='.$empresa->id.'&q=prenda')
            ->assertJsonCount(0, 'tipos');
    }
});

it('un activo histórico conserva su tipo/categoría/variante aunque se desactiven globalmente', function () {
    $tipo = TipoActivo::factory()->create();
    $categoria = CategoriaActivo::factory()->create();
    $talla = Talla::factory()->create(['valor' => 'M']);
    $activo = Activo::factory()->for($this->a)->create([
        'tipo_activo_id' => $tipo->id, 'categoria_id' => $categoria->id,
    ]);
    $activo->tallas()->attach($talla);

    $tipo->update(['activo' => false]);
    $categoria->update(['activa' => false]);
    $talla->update(['activa' => false]);

    expect($activo->fresh()->tipo_activo_id)->toBe($tipo->id)
        ->and($activo->fresh()->categoria_id)->toBe($categoria->id)
        ->and($activo->tallas()->count())->toBe(1);
});

it('una variante compartida entre A y B mantiene el stock separado', function () {
    $talla = Talla::factory()->create(['valor' => 'M']);
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

it('un activo sin variante guarda talla_id NULL y no hay filas comodín', function () {
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

it('un rol restringido con el permiso administra el catálogo global sin necesitar acceso a ninguna empresa concreta', function () {
    $tipo = TipoActivo::factory()->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->a]);
    $supervisor->givePermissionTo('tipos-activo.administrar');

    $this->actingAs($supervisor)
        ->put("/tipos-activo/{$tipo->id}", ['nombre' => 'Renombrado por supervisor'])
        ->assertRedirect()->assertSessionHasNoErrors();
});

it('las rutas de habilitación por empresa ya no existen', function () {
    $tipo = TipoActivo::factory()->create();
    $categoria = CategoriaActivo::factory()->create();
    $talla = Talla::factory()->create();

    $this->actingAs($this->admin)->put("/tipos-activo/{$tipo->id}/empresas", ['empresa_ids' => [$this->a->id]])->assertNotFound();
    $this->actingAs($this->admin)->post("/tipos-activo/{$tipo->id}/empresa", ['empresa_id' => $this->a->id])->assertNotFound();
    $this->actingAs($this->admin)->put("/categorias-activo/{$categoria->id}/empresas", ['empresa_ids' => [$this->a->id]])->assertNotFound();
    $this->actingAs($this->admin)->post("/categorias-activo/{$categoria->id}/empresa", ['empresa_id' => $this->a->id])->assertNotFound();
    $this->actingAs($this->admin)->post("/tallas/{$talla->id}/empresa", ['empresa_id' => $this->a->id])->assertNotFound();
});

it('cross-company: registrar entrada con una variante inactiva se rechaza', function () {
    $almacen = Almacen::factory()->paraEmpresa($this->a)->create();
    $activo = Activo::factory()->for($this->a)->create(['tipo_control' => 'cantidad']);
    $tallaInactiva = Talla::factory()->create(['valor' => 'Z', 'activa' => false]);

    $this->actingAs($this->admin)->from('/inventario/entrada')->post('/inventario/entrada', [
        'empresa_id' => $this->a->id, 'almacen_id' => $almacen->id, 'motivo' => 'x',
        'items' => [['activo_id' => $activo->id, 'talla_id' => $tallaInactiva->id, 'cantidad' => 1]],
    ])->assertSessionHasErrors('items.0.talla_id');

    expect(SaldoInventario::query()->count())->toBe(0);
});
