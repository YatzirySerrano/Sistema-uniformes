<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\CategoriaActivo;
use App\Models\Empresa;
use App\Models\TipoActivo;

beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create(['nombre_comercial' => 'Empresa A']);
    $this->otra = Empresa::factory()->create(['nombre_comercial' => 'Empresa B']);
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
});

/** Alta de activo con los campos mínimos + overrides. */
function activoPayload(array $override = []): array
{
    return array_merge([
        'empresa_id' => test()->empresa->id,
        'nombre' => 'Extensión eléctrica 10 m',
        'tipo_control' => 'cantidad',
    ], $override);
}

/*
|--------------------------------------------------------------------------
| Opcionalidad de tipo y categoría (A–E)
|--------------------------------------------------------------------------
*/

it('A) guarda un activo sin tipo ni categoría', function () {
    $this->actingAs($this->admin)
        ->post('/activos', activoPayload())
        ->assertRedirect('/activos')
        ->assertSessionHasNoErrors();

    $activo = Activo::query()->where('nombre', 'Extensión eléctrica 10 m')->first();
    expect($activo->tipo_activo_id)->toBeNull()
        ->and($activo->categoria_id)->toBeNull()
        ->and($activo->categoria)->toBeNull();
});

it('B) guarda un activo sólo con tipo', function () {
    $tipo = TipoActivo::factory()->for($this->empresa)->create(['nombre' => 'Herramienta']);

    $this->actingAs($this->admin)
        ->post('/activos', activoPayload(['tipo_activo_id' => $tipo->id]))
        ->assertRedirect()->assertSessionHasNoErrors();

    $activo = Activo::query()->where('nombre', 'Extensión eléctrica 10 m')->first();
    expect($activo->tipo_activo_id)->toBe($tipo->id)
        ->and($activo->categoria_id)->toBeNull();
});

it('C) guarda un activo sólo con categoría', function () {
    $categoria = CategoriaActivo::factory()->for($this->empresa)->create(['nombre' => 'Material promocional']);

    $this->actingAs($this->admin)
        ->post('/activos', activoPayload(['categoria_id' => $categoria->id]))
        ->assertRedirect()->assertSessionHasNoErrors();

    $activo = Activo::query()->where('nombre', 'Extensión eléctrica 10 m')->first();
    expect($activo->tipo_activo_id)->toBeNull()
        ->and($activo->categoria_id)->toBe($categoria->id)
        ->and($activo->categoria)->toBe('Material promocional');
});

it('D) guarda un activo con tipo y categoría coherentes', function () {
    $tipo = TipoActivo::factory()->for($this->empresa)->create(['nombre' => 'Equipo de cómputo']);
    $categoria = CategoriaActivo::factory()->for($this->empresa)->create(['nombre' => 'Laptop', 'tipo_activo_id' => $tipo->id]);

    $this->actingAs($this->admin)
        ->post('/activos', activoPayload(['tipo_activo_id' => $tipo->id, 'categoria_id' => $categoria->id]))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(Activo::query()->where('nombre', 'Extensión eléctrica 10 m')->first()->categoria_id)->toBe($categoria->id);
});

it('E) rechaza tipo o categoría de otra empresa', function () {
    $tipoAjeno = TipoActivo::factory()->for($this->otra)->create();
    $categoriaAjena = CategoriaActivo::factory()->for($this->otra)->create();

    $this->actingAs($this->admin)->from('/activos/crear')
        ->post('/activos', activoPayload(['tipo_activo_id' => $tipoAjeno->id]))
        ->assertSessionHasErrors('tipo_activo_id');

    $this->actingAs($this->admin)->from('/activos/crear')
        ->post('/activos', activoPayload(['categoria_id' => $categoriaAjena->id]))
        ->assertSessionHasErrors('categoria_id');
});

it('rechaza una categoría ligada a un tipo distinto del elegido (coherencia)', function () {
    $tipoA = TipoActivo::factory()->for($this->empresa)->create(['nombre' => 'Prenda']);
    $tipoB = TipoActivo::factory()->for($this->empresa)->create(['nombre' => 'Equipo de cómputo']);
    $categoriaDeA = CategoriaActivo::factory()->for($this->empresa)->create(['nombre' => 'Camisola', 'tipo_activo_id' => $tipoA->id]);

    $this->actingAs($this->admin)->from('/activos/crear')
        ->post('/activos', activoPayload(['tipo_activo_id' => $tipoB->id, 'categoria_id' => $categoriaDeA->id]))
        ->assertSessionHasErrors('categoria_id');
});

it('permite una categoría sin tipo aunque el activo lleve tipo', function () {
    $tipo = TipoActivo::factory()->for($this->empresa)->create();
    $categoriaSinTipo = CategoriaActivo::factory()->for($this->empresa)->create(['tipo_activo_id' => null]);

    $this->actingAs($this->admin)
        ->post('/activos', activoPayload(['tipo_activo_id' => $tipo->id, 'categoria_id' => $categoriaSinTipo->id]))
        ->assertRedirect()->assertSessionHasNoErrors();
});

/*
|--------------------------------------------------------------------------
| Buscadores (F–H, M, N)
|--------------------------------------------------------------------------
*/

it('F) /tipos-activo/buscar sólo devuelve tipos de la empresa indicada', function () {
    TipoActivo::factory()->for($this->empresa)->create(['nombre' => 'Prenda propia']);
    TipoActivo::factory()->for($this->otra)->create(['nombre' => 'Prenda ajena']);

    $nombres = $this->actingAs($this->admin)
        ->getJson('/tipos-activo/buscar?empresa_id='.$this->empresa->id.'&q=prenda')
        ->assertOk()->json('tipos.*.nombre');

    expect($nombres)->toContain('Prenda propia')->not->toContain('Prenda ajena');
});

it('G) /categorias-activo/buscar sólo devuelve categorías de la empresa indicada', function () {
    CategoriaActivo::factory()->for($this->empresa)->create(['nombre' => 'Camisola propia']);
    CategoriaActivo::factory()->for($this->otra)->create(['nombre' => 'Camisola ajena']);

    $nombres = $this->actingAs($this->admin)
        ->getJson('/categorias-activo/buscar?empresa_id='.$this->empresa->id.'&q=camisola')
        ->assertOk()->json('categorias.*.nombre');

    expect($nombres)->toContain('Camisola propia')->not->toContain('Camisola ajena');
});

it('H) empresa fuera de alcance no filtra hacia otras empresas (lista vacía)', function () {
    TipoActivo::factory()->for($this->empresa)->create();
    CategoriaActivo::factory()->for($this->empresa)->create();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->empresa]);

    $this->actingAs($supervisor)
        ->getJson('/tipos-activo/buscar?empresa_id='.$this->otra->id)
        ->assertOk()->assertJsonCount(0, 'tipos');

    $this->actingAs($supervisor)
        ->getJson('/categorias-activo/buscar?empresa_id='.$this->otra->id)
        ->assertOk()->assertJsonCount(0, 'categorias');
});

it('M) un tipo desactivado no aparece en el buscador', function () {
    TipoActivo::factory()->for($this->empresa)->create(['nombre' => 'Obsoleto', 'activo' => false]);

    $this->actingAs($this->admin)
        ->getJson('/tipos-activo/buscar?empresa_id='.$this->empresa->id.'&q=obsoleto')
        ->assertOk()->assertJsonCount(0, 'tipos');
});

it('N) una categoría desactivada no aparece en el buscador', function () {
    CategoriaActivo::factory()->for($this->empresa)->inactiva()->create(['nombre' => 'Obsoleta']);

    $this->actingAs($this->admin)
        ->getJson('/categorias-activo/buscar?empresa_id='.$this->empresa->id.'&q=obsoleta')
        ->assertOk()->assertJsonCount(0, 'categorias');
});

it('el buscador de categorías prioriza el tipo elegido y siempre incluye las sin tipo', function () {
    $tipoA = TipoActivo::factory()->for($this->empresa)->create();
    $tipoB = TipoActivo::factory()->for($this->empresa)->create();
    CategoriaActivo::factory()->for($this->empresa)->create(['nombre' => 'De A', 'tipo_activo_id' => $tipoA->id]);
    CategoriaActivo::factory()->for($this->empresa)->create(['nombre' => 'De B', 'tipo_activo_id' => $tipoB->id]);
    CategoriaActivo::factory()->for($this->empresa)->create(['nombre' => 'Sin tipo', 'tipo_activo_id' => null]);

    $nombres = $this->actingAs($this->admin)
        ->getJson('/categorias-activo/buscar?empresa_id='.$this->empresa->id.'&tipo_activo_id='.$tipoA->id)
        ->assertOk()->json('categorias.*.nombre');

    expect($nombres)->toContain('De A')->toContain('Sin tipo')->not->toContain('De B');
});

it('los buscadores de catálogo exigen permiso de lectura de activos', function () {
    $colaborador = usuarioCon(RolSistema::Colaborador->value, [$this->empresa]);

    $this->actingAs($colaborador)->getJson('/tipos-activo/buscar?empresa_id='.$this->empresa->id)->assertForbidden();
    $this->actingAs($colaborador)->getJson('/categorias-activo/buscar?empresa_id='.$this->empresa->id)->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Alta rápida y duplicados normalizados (I–L, P, Q)
|--------------------------------------------------------------------------
*/

it('I) el alta rápida de tipo persiste y queda disponible en el buscador', function () {
    $this->actingAs($this->admin)
        ->postJson('/tipos-activo/rapido', ['nombre' => '  Equipo de protección  ', 'empresa_id' => $this->empresa->id])
        ->assertOk()
        ->assertJsonPath('tipo.nombre', 'Equipo de protección');

    $this->assertDatabaseHas('tipos_activo', [
        'empresa_id' => $this->empresa->id,
        'nombre_normalizado' => 'equipo de protección',
    ]);

    $this->actingAs($this->admin)
        ->getJson('/tipos-activo/buscar?empresa_id='.$this->empresa->id.'&q=protección')
        ->assertJsonCount(1, 'tipos');
});

it('J) el alta rápida de categoría persiste (con tipo relacionado opcional)', function () {
    $tipo = TipoActivo::factory()->for($this->empresa)->create();

    $this->actingAs($this->admin)
        ->postJson('/categorias-activo/rapido', [
            'nombre' => 'Teléfono celular',
            'tipo_activo_id' => $tipo->id,
            'empresa_id' => $this->empresa->id,
        ])
        ->assertOk()
        ->assertJsonPath('categoria.nombre', 'Teléfono celular')
        ->assertJsonPath('categoria.tipo_activo_id', $tipo->id);
});

it('K) rechaza un duplicado que sólo difiere en mayúsculas o espacios', function () {
    TipoActivo::factory()->for($this->empresa)->create(['nombre' => 'Prenda']);
    CategoriaActivo::factory()->for($this->empresa)->create(['nombre' => 'Camisola']);

    // Alta rápida
    $this->actingAs($this->admin)
        ->postJson('/tipos-activo/rapido', ['nombre' => '  PRENDA ', 'empresa_id' => $this->empresa->id])
        ->assertStatus(422)->assertJsonValidationErrors('nombre');

    // Formulario completo
    $this->actingAs($this->admin)->from('/activos-catalogos')
        ->post('/tipos-activo', ['nombre' => ' prenda', 'empresa_id' => $this->empresa->id])
        ->assertSessionHasErrors('nombre');

    $this->actingAs($this->admin)->from('/activos-catalogos')
        ->post('/categorias-activo', ['nombre' => 'CAMISOLA', 'empresa_id' => $this->empresa->id])
        ->assertSessionHasErrors('nombre');

    expect(TipoActivo::query()->where('empresa_id', $this->empresa->id)->count())->toBe(1)
        ->and(CategoriaActivo::query()->where('empresa_id', $this->empresa->id)->count())->toBe(1);
});

it('L) el mismo nombre en otra empresa sí se permite', function () {
    TipoActivo::factory()->for($this->empresa)->create(['nombre' => 'Prenda']);
    $adminOtra = usuarioCon(RolSistema::Administrador->value, [$this->otra]);

    $this->actingAs($adminOtra)
        ->postJson('/tipos-activo/rapido', ['nombre' => 'Prenda', 'empresa_id' => $this->otra->id])
        ->assertOk();

    expect(TipoActivo::query()->where('nombre_normalizado', 'prenda')->count())->toBe(2);
});

it('P) un rol sin el permiso no puede crear tipos ni categorías', function () {
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->empresa]);

    $this->actingAs($supervisor)
        ->postJson('/tipos-activo/rapido', ['nombre' => 'X', 'empresa_id' => $this->empresa->id])
        ->assertForbidden();
    $this->actingAs($supervisor)
        ->postJson('/categorias-activo/rapido', ['nombre' => 'X', 'empresa_id' => $this->empresa->id])
        ->assertForbidden();
});

it('Q) el administrador sí puede crear tipos y categorías', function () {
    $this->actingAs($this->admin)
        ->postJson('/tipos-activo/rapido', ['nombre' => 'Vehículo', 'empresa_id' => $this->empresa->id])
        ->assertOk();
    $this->actingAs($this->admin)
        ->postJson('/categorias-activo/rapido', ['nombre' => 'Camioneta', 'empresa_id' => $this->empresa->id])
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| Activos existentes conservan tipo / categoría desactivados (O)
|--------------------------------------------------------------------------
*/

it('O) un activo conserva y muestra su tipo y categoría aunque se desactiven', function () {
    $tipo = TipoActivo::factory()->for($this->empresa)->create(['nombre' => 'Prenda']);
    $categoria = CategoriaActivo::factory()->for($this->empresa)->create(['nombre' => 'Camisola', 'tipo_activo_id' => $tipo->id]);
    $activo = Activo::factory()->for($this->empresa)->create([
        'tipo_activo_id' => $tipo->id,
        'categoria_id' => $categoria->id,
    ]);

    $tipo->update(['activo' => false]);
    $categoria->update(['activa' => false]);

    // La relación se conserva (no se pone a null).
    expect($activo->fresh()->tipo_activo_id)->toBe($tipo->id)
        ->and($activo->fresh()->categoria_id)->toBe($categoria->id);

    // La pantalla de edición muestra sus nombres aunque estén desactivados.
    $this->actingAs($this->admin)
        ->get("/activos/{$activo->id}/editar")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Activos/Formulario')
            ->where('seleccion.tipo.nombre', 'Prenda')
            ->where('seleccion.categoria.nombre', 'Camisola'),
        );
});
