<?php

use App\Enums\RolSistema;
use App\Models\Empresa;
use App\Models\Talla;

beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->otra = Empresa::factory()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
});

it('no existe ninguna talla comodín "sin variante"', function () {
    Talla::factory()->paraEmpresa($this->empresa)->create(['valor' => 'M']);

    expect(Talla::query()->where('valor', 'like', '%sin variante%')->count())->toBe(0)
        ->and(Talla::query()->get())->each->not->toHaveKey('es_comodin');
});

it('crear una variante no pide orden y la coloca al final', function () {
    Talla::factory()->create(['valor' => 'S', 'orden' => 1])->empresas()->attach($this->empresa);
    Talla::factory()->create(['valor' => 'M', 'orden' => 2])->empresas()->attach($this->empresa);

    $this->actingAs($this->admin)
        ->post('/tallas', ['valor' => 'L', 'empresa_ids' => [$this->empresa->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $nueva = Talla::query()->where('valor', 'L')->first();
    expect($nueva->orden)->toBe(3)
        ->and($nueva->empresas()->whereKey($this->empresa->id)->exists())->toBeTrue();
});

it('el alta se habilita para las empresas indicadas', function () {
    $this->actingAs($this->admin)
        ->post('/tallas', ['valor' => 'XL', 'empresa_ids' => [$this->empresa->id, $this->otra->id]])
        ->assertRedirect()->assertSessionHasNoErrors();

    $talla = Talla::query()->where('valor', 'XL')->first();
    expect($talla->empresas()->count())->toBe(2);
});

it('el listado de variantes no muestra ninguna "Sin variante"', function () {
    Talla::factory()->paraEmpresa($this->empresa)->create(['valor' => 'XL']);

    $this->actingAs($this->admin)
        ->get('/tallas?empresa_id='.$this->empresa->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Activos/Tallas')
            ->where('tallas', fn ($tallas) => collect($tallas)->every(fn ($t) => stripos($t['valor'], 'sin variante') === false)),
        );
});

it('reordena el catálogo de variantes según la lista de IDs', function () {
    $a = Talla::factory()->paraEmpresa($this->empresa)->create(['valor' => 'A', 'orden' => 1]);
    $b = Talla::factory()->paraEmpresa($this->empresa)->create(['valor' => 'B', 'orden' => 2]);
    $c = Talla::factory()->paraEmpresa($this->empresa)->create(['valor' => 'C', 'orden' => 3]);

    $this->actingAs($this->admin)
        ->post('/tallas/reordenar', ['orden' => [$c->id, $a->id, $b->id]])
        ->assertRedirect();

    expect($c->fresh()->orden)->toBe(1)
        ->and($a->fresh()->orden)->toBe(2)
        ->and($b->fresh()->orden)->toBe(3);
});

it('habilita / deshabilita una variante para una empresa concreta', function () {
    $talla = Talla::factory()->paraEmpresa($this->empresa)->create(['valor' => 'U']);

    $this->actingAs($this->admin)
        ->post("/tallas/{$talla->id}/empresa", ['empresa_id' => $this->otra->id])
        ->assertRedirect();
    expect($talla->empresas()->whereKey($this->otra->id)->exists())->toBeTrue();

    $this->actingAs($this->admin)
        ->post("/tallas/{$talla->id}/empresa", ['empresa_id' => $this->otra->id])
        ->assertRedirect();
    expect($talla->empresas()->whereKey($this->otra->id)->exists())->toBeFalse();
});

it('el mismo valor de variante no se duplica a nivel plataforma', function () {
    Talla::factory()->paraEmpresa($this->empresa)->create(['valor' => 'M']);

    $this->actingAs($this->admin)
        ->postJson('/tallas/rapido', ['valor' => '  m ', 'empresa_id' => $this->empresa->id])
        ->assertStatus(422)->assertJsonValidationErrors('valor');
});

it('el alta rápida de variante devuelve la variante creada y la habilita para la empresa', function () {
    $this->actingAs($this->admin)
        ->postJson('/tallas/rapido', ['valor' => '36R', 'empresa_id' => $this->empresa->id])
        ->assertOk()
        ->assertJsonPath('talla.valor', '36R');

    expect(Talla::query()->where('valor', '36R')->first()->empresas()->whereKey($this->empresa->id)->exists())->toBeTrue();
});

it('un supervisor no puede administrar variantes', function () {
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->empresa]);

    $this->actingAs($supervisor)
        ->post('/tallas', ['valor' => 'X', 'empresa_ids' => [$this->empresa->id]])
        ->assertForbidden();
});
