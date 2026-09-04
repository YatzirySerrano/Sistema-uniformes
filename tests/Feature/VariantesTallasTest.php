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
    Talla::factory()->create(['valor' => 'M']);

    expect(Talla::query()->where('valor', 'like', '%sin variante%')->count())->toBe(0)
        ->and(Talla::query()->get())->each->not->toHaveKey('es_comodin');
});

it('crear una variante no pide orden y la coloca al final', function () {
    Talla::factory()->create(['valor' => 'S', 'orden' => 1]);
    Talla::factory()->create(['valor' => 'M', 'orden' => 2]);

    $this->actingAs($this->admin)
        ->post('/tallas', ['valor' => 'L'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $nueva = Talla::query()->where('valor', 'L')->first();
    expect($nueva->orden)->toBe(3);
});

it('el alta queda visible de inmediato para todas las empresas', function () {
    $this->actingAs($this->admin)
        ->post('/tallas', ['valor' => 'XL'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $talla = Talla::query()->where('valor', 'XL')->first();

    foreach ([$this->empresa, $this->otra] as $empresa) {
        $otroAdmin = usuarioCon(RolSistema::Administrador->value, [$empresa]);
        $this->actingAs($otroAdmin)
            ->getJson('/tallas/buscar?q=XL')
            ->assertOk()->assertJsonFragment(['id' => $talla->id]);
    }
});

it('el listado de variantes no muestra ninguna "Sin variante"', function () {
    Talla::factory()->create(['valor' => 'XL']);

    $this->actingAs($this->admin)
        ->get('/tallas')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Activos/Tallas')
            ->where('tallas', fn ($tallas) => collect($tallas)->every(fn ($t) => stripos($t['valor'], 'sin variante') === false)),
        );
});

it('reordena el catálogo de variantes según la lista de IDs', function () {
    $a = Talla::factory()->create(['valor' => 'A', 'orden' => 1]);
    $b = Talla::factory()->create(['valor' => 'B', 'orden' => 2]);
    $c = Talla::factory()->create(['valor' => 'C', 'orden' => 3]);

    $this->actingAs($this->admin)
        ->post('/tallas/reordenar', ['orden' => [$c->id, $a->id, $b->id]])
        ->assertRedirect();

    expect($c->fresh()->orden)->toBe(1)
        ->and($a->fresh()->orden)->toBe(2)
        ->and($b->fresh()->orden)->toBe(3);
});

it('el mismo valor de variante no se duplica a nivel plataforma', function () {
    Talla::factory()->create(['valor' => 'M']);

    $this->actingAs($this->admin)
        ->postJson('/tallas/rapido', ['valor' => '  m '])
        ->assertStatus(422)->assertJsonValidationErrors('valor');
});

it('el alta rápida de variante devuelve la variante creada, visible para todas las empresas', function () {
    $this->actingAs($this->admin)
        ->postJson('/tallas/rapido', ['valor' => '36R'])
        ->assertOk()
        ->assertJsonPath('talla.valor', '36R');

    $talla = Talla::query()->where('valor', '36R')->first();
    expect($talla)->not->toBeNull();

    $otroAdmin = usuarioCon(RolSistema::Administrador->value, [$this->otra]);
    $this->actingAs($otroAdmin)
        ->getJson('/tallas/buscar?q=36R')
        ->assertOk()->assertJsonFragment(['id' => $talla->id]);
});

it('un supervisor no puede administrar variantes', function () {
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->empresa]);

    $this->actingAs($supervisor)
        ->post('/tallas', ['valor' => 'X'])
        ->assertForbidden();
});

it('la ruta de habilitación por empresa ya no existe', function () {
    $talla = Talla::factory()->create(['valor' => 'U']);

    $this->actingAs($this->admin)
        ->post("/tallas/{$talla->id}/empresa", ['empresa_id' => $this->otra->id])
        ->assertNotFound();
});
