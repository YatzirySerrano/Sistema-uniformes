<?php

use App\Enums\RolSistema;
use App\Models\Empresa;
use App\Models\Talla;
use App\Soporte\ContextoEmpresa;

beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
    $this->sesion = [ContextoEmpresa::SESSION_KEY => $this->empresa->id];
});

it('cada empresa nace con una talla comodín "sin variante"', function () {
    $comodin = $this->empresa->tallas()->where('es_comodin', true)->first();
    expect($comodin)->not->toBeNull()
        ->and($comodin->orden)->toBe(0);
});

it('crear una variante no pide orden y la coloca al final', function () {
    Talla::factory()->for($this->empresa)->create(['valor' => 'S', 'orden' => 1]);
    Talla::factory()->for($this->empresa)->create(['valor' => 'M', 'orden' => 2]);

    $this->actingAs($this->admin)->withSession($this->sesion)
        ->post('/tallas', ['valor' => 'L'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $nueva = Talla::query()->where('empresa_id', $this->empresa->id)->where('valor', 'L')->first();
    expect($nueva->orden)->toBe(3);
});

it('el listado de variantes excluye la comodín', function () {
    Talla::factory()->for($this->empresa)->create(['valor' => 'XL']);

    $this->actingAs($this->admin)->withSession($this->sesion)
        ->get('/tallas')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Activos/Tallas')
            ->where('tallas', fn ($tallas) => collect($tallas)->every(fn ($t) => $t['valor'] !== 'Sin variante'))
        );
});

it('reordena las variantes según la lista de IDs', function () {
    $a = Talla::factory()->for($this->empresa)->create(['valor' => 'A', 'orden' => 1]);
    $b = Talla::factory()->for($this->empresa)->create(['valor' => 'B', 'orden' => 2]);
    $c = Talla::factory()->for($this->empresa)->create(['valor' => 'C', 'orden' => 3]);

    $this->actingAs($this->admin)->withSession($this->sesion)
        ->post('/tallas/reordenar', ['orden' => [$c->id, $a->id, $b->id]])
        ->assertRedirect();

    expect($c->fresh()->orden)->toBe(1)
        ->and($a->fresh()->orden)->toBe(2)
        ->and($b->fresh()->orden)->toBe(3);
});

it('la comodín no se puede editar ni eliminar', function () {
    $comodin = $this->empresa->tallaComodin();

    $this->actingAs($this->admin)->withSession($this->sesion)
        ->put("/tallas/{$comodin->id}", ['valor' => 'Hackeada'])
        ->assertForbidden();

    $this->actingAs($this->admin)->withSession($this->sesion)
        ->delete("/tallas/{$comodin->id}")
        ->assertForbidden();

    expect($comodin->fresh()->valor)->toBe('Sin variante');
});

it('el alta rápida de variante devuelve la variante creada', function () {
    $this->actingAs($this->admin)->withSession($this->sesion)
        ->postJson('/tallas/rapido', ['valor' => '36R'])
        ->assertOk()
        ->assertJsonPath('talla.valor', '36R');
});

it('un supervisor no puede administrar variantes', function () {
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->empresa]);

    $this->actingAs($supervisor)->withSession($this->sesion)
        ->post('/tallas', ['valor' => 'X'])
        ->assertForbidden();
});
