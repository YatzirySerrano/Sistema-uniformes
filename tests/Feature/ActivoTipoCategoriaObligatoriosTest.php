<?php

use App\Enums\PerfilTecnicoUnidad;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\CategoriaActivo;
use App\Models\Empresa;
use App\Models\TipoActivo;

/**
 * Tipo y categoría obligatorios en Activos, con la categoría como fuente
 * ESTRUCTURADA de su tipo (`categorias_activo.tipo_activo_id`, nunca por
 * nombre) y su perfil técnico gobernando el seguimiento individual.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();

    $this->prenda = TipoActivo::factory()->create(['nombre' => 'Prenda']);
    $this->transporte = TipoActivo::factory()->create(['nombre' => 'Transporte']);
    $this->pantalon = CategoriaActivo::factory()->create(['nombre' => 'Pantalón', 'tipo_activo_id' => $this->prenda->id]);
    $this->carro = CategoriaActivo::factory()->create(['nombre' => 'Carro', 'tipo_activo_id' => $this->transporte->id]);
    $this->carro->perfilTecnico()->create(['perfil' => PerfilTecnicoUnidad::Transporte]);

    $this->alta = fn (array $datos) => $this->actingAs($this->admin)->from('/activos/crear')->post('/activos', [
        'empresa_id' => $this->empresa->id,
        'nombre' => 'Activo de prueba',
        'tipo_control' => 'cantidad',
        ...$datos,
    ]);
});

it('exige el tipo de activo', function () {
    ($this->alta)(['categoria_id' => $this->pantalon->id])
        ->assertSessionHasErrors(['tipo_activo_id' => 'Selecciona el tipo de activo.']);

    expect(Activo::query()->count())->toBe(0);
});

it('exige la categoría', function () {
    ($this->alta)(['tipo_activo_id' => $this->prenda->id])
        ->assertSessionHasErrors(['categoria_id' => 'Selecciona la categoría del activo.']);

    expect(Activo::query()->count())->toBe(0);
});

it('el buscador de categorías entrega el tipo ligado para autoseleccionarlo (Pantalón → Prenda, Carro → Transporte)', function (string $categoria, string $tipo) {
    $opcion = $this->actingAs($this->admin)
        ->getJson('/categorias-activo/buscar?q='.urlencode($categoria))
        ->assertOk()
        ->json('categorias.0');

    expect($opcion['nombre'])->toBe($categoria)
        ->and($opcion['tipo_activo_id'])->toBe(TipoActivo::query()->where('nombre', $tipo)->value('id'))
        ->and($opcion['tipo'])->toBe($tipo);
})->with([
    'Pantalón deriva Prenda' => ['Pantalón', 'Prenda'],
    'Carro deriva Transporte' => ['Carro', 'Transporte'],
]);

it('rechaza una combinación manipulada de tipo y categoría incompatibles (Prenda + Carro)', function () {
    ($this->alta)(['tipo_activo_id' => $this->prenda->id, 'categoria_id' => $this->carro->id, 'tipo_control' => 'individual'])
        ->assertSessionHasErrors('categoria_id');

    expect(Activo::query()->count())->toBe(0);
});

it('cambiar el tipo en la edición invalida la categoría que pertenece a otro tipo', function () {
    $activo = Activo::factory()->for($this->empresa)->create([
        'tipo_activo_id' => $this->prenda->id,
        'categoria_id' => $this->pantalon->id,
    ]);

    $this->actingAs($this->admin)->from("/activos/{$activo->id}/editar")
        ->post("/activos/{$activo->id}", [
            'nombre' => $activo->nombre,
            'tipo_control' => 'cantidad',
            'tipo_activo_id' => $this->transporte->id,
            'categoria_id' => $this->pantalon->id,
        ])
        ->assertSessionHasErrors('categoria_id');

    expect($activo->fresh()->tipo_activo_id)->toBe($this->prenda->id);
});

it('una categoría con perfil técnico (Carro) exige seguimiento individual', function () {
    ($this->alta)(['tipo_activo_id' => $this->transporte->id, 'categoria_id' => $this->carro->id, 'tipo_control' => 'cantidad'])
        ->assertSessionHasErrors(['tipo_control' => 'Los activos con perfil Transporte requieren seguimiento individual para conservar su trazabilidad.']);

    ($this->alta)(['tipo_activo_id' => $this->transporte->id, 'categoria_id' => $this->carro->id, 'tipo_control' => 'individual'])
        ->assertSessionHasNoErrors();

    expect(Activo::query()->sole()->tipo_control->value)->toBe('individual');
});

it('un activo histórico sin clasificar se puede consultar, pero al guardar una edición exige tipo y categoría', function () {
    $historico = Activo::factory()->for($this->empresa)->create(['tipo_activo_id' => null, 'categoria_id' => null]);

    $this->actingAs($this->admin)->get("/activos/{$historico->id}/editar")->assertOk();

    $this->actingAs($this->admin)->from("/activos/{$historico->id}/editar")
        ->post("/activos/{$historico->id}", ['nombre' => 'Renombrado', 'tipo_control' => 'cantidad'])
        ->assertSessionHasErrors(['tipo_activo_id', 'categoria_id']);

    expect($historico->fresh())
        ->nombre->not->toBe('Renombrado')
        ->tipo_activo_id->toBeNull();

    $this->actingAs($this->admin)->from("/activos/{$historico->id}/editar")
        ->post("/activos/{$historico->id}", [
            'nombre' => 'Renombrado', 'tipo_control' => 'cantidad',
            'tipo_activo_id' => $this->prenda->id, 'categoria_id' => $this->pantalon->id,
        ])
        ->assertSessionHasNoErrors();

    expect($historico->fresh()->categoria_id)->toBe($this->pantalon->id);
});
