<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\Talla;

/**
 * "Agregar existencias" sólo debe ofrecer y aceptar variantes/tallas
 * REALMENTE asociadas al activo (`activo_talla`), nunca el catálogo global
 * completo — bug confirmado visualmente: el selector mostraba prácticamente
 * todas las tallas del sistema. La fuente única sigue siendo
 * `Activo::tallasElegibles()` (asociada + activa), la misma que ya usan
 * `activos/buscar` y `RegistrarEntradaInventario`.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();

    // Pantalón: sólo 34/36. Otra playera: sólo CH/M/G. Ninguna debe
    // ofrecerse en el selector de la otra.
    $this->pantalon = Activo::factory()->for($this->empresa)->create(['nombre' => 'Pantalón', 'tipo_control' => 'cantidad']);
    $this->talla34 = Talla::factory()->create(['valor' => '34']);
    $this->talla36 = Talla::factory()->create(['valor' => '36']);
    $this->pantalon->tallas()->attach([$this->talla34->id, $this->talla36->id]);

    $this->playera = Activo::factory()->for($this->empresa)->create(['nombre' => 'Playera', 'tipo_control' => 'cantidad']);
    $this->tallaCH = Talla::factory()->create(['valor' => 'CH']);
    $this->playera->tallas()->attach([$this->tallaCH->id]);

    // Talla activa en el catálogo global pero NO asociada a ningún activo de
    // este escenario — nunca debe aparecer en ninguno de los dos selectores.
    $this->tallaAjena = Talla::factory()->create(['valor' => 'XXL']);
});

it('el selector de variantes de "Agregar existencias" sólo trae las tallas asociadas al activo', function () {
    $this->actingAs($this->admin)
        ->getJson("/tallas/buscar?activo_id={$this->pantalon->id}")
        ->assertOk()
        ->assertJsonCount(2, 'tallas')
        ->assertJsonFragment(['id' => $this->talla34->id, 'valor' => '34'])
        ->assertJsonFragment(['id' => $this->talla36->id, 'valor' => '36'])
        ->assertJsonMissing(['id' => $this->tallaCH->id])
        ->assertJsonMissing(['id' => $this->tallaAjena->id]);

    $this->actingAs($this->admin)
        ->getJson("/tallas/buscar?activo_id={$this->playera->id}")
        ->assertOk()
        ->assertJsonCount(1, 'tallas')
        ->assertJsonFragment(['id' => $this->tallaCH->id])
        ->assertJsonMissing(['id' => $this->talla34->id]);
});

it('el buscador de tallas por activo respeta el término de búsqueda dentro de las variantes ya filtradas', function () {
    $this->actingAs($this->admin)
        ->getJson("/tallas/buscar?activo_id={$this->pantalon->id}&q=34")
        ->assertOk()
        ->assertJsonCount(1, 'tallas')
        ->assertJsonFragment(['id' => $this->talla34->id]);
});

it('un activo_id fuera del alcance de un rol restringido no filtra al catálogo global, devuelve vacío (defensa IDOR)', function () {
    // Administrador tiene alcance global por regla de negocio (empresas son
    // entidades de plataforma) — la defensa IDOR importa para roles
    // restringidos, acotados por `empresa_usuario`.
    $otraEmpresa = Empresa::factory()->create();
    $activoAjeno = Activo::factory()->for($otraEmpresa)->create(['tipo_control' => 'cantidad']);
    $tallaAjenaAlActivo = Talla::factory()->create(['valor' => '99']);
    $activoAjeno->tallas()->attach([$tallaAjenaAlActivo->id]);

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->empresa]);

    $this->actingAs($supervisor)
        ->getJson("/tallas/buscar?activo_id={$activoAjeno->id}")
        ->assertOk()
        ->assertJsonCount(0, 'tallas');

    // El mismo supervisor SÍ ve las tallas de un activo de su propia empresa.
    $this->actingAs($supervisor)
        ->getJson("/tallas/buscar?activo_id={$this->pantalon->id}")
        ->assertOk()
        ->assertJsonCount(2, 'tallas');
});

it('el backend ACEPTA agregar existencias con una variante realmente asociada al activo', function () {
    $this->actingAs($this->admin)
        ->post("/activos/{$this->pantalon->id}/existencias", [
            'almacen_id' => $this->almacen->id,
            'talla_id' => $this->talla34->id,
            'cantidad' => 5,
        ])
        ->assertSessionHasNoErrors();

    expect(SaldoInventario::query()
        ->where('activo_id', $this->pantalon->id)
        ->where('talla_id', $this->talla34->id)
        ->value('cantidad'))->toBe(5);
});

it('el backend RECHAZA agregar existencias con una variante que NO pertenece al activo, sin tocar stock ni movimientos', function () {
    $this->actingAs($this->admin)
        ->post("/activos/{$this->pantalon->id}/existencias", [
            'almacen_id' => $this->almacen->id,
            'talla_id' => $this->tallaCH->id, // pertenece a Playera, no a Pantalón
            'cantidad' => 5,
        ])
        ->assertSessionHasErrors('talla_id');

    expect(SaldoInventario::query()->where('activo_id', $this->pantalon->id)->exists())->toBeFalse()
        ->and(MovimientoInventario::query()->where('activo_id', $this->pantalon->id)->exists())->toBeFalse();
});

it('el backend RECHAZA una variante inventada/inexistente enviada manualmente, sin registrar nada parcial', function () {
    $this->actingAs($this->admin)
        ->post("/activos/{$this->pantalon->id}/existencias", [
            'almacen_id' => $this->almacen->id,
            'talla_id' => 999999,
            'cantidad' => 3,
        ])
        ->assertSessionHasErrors('talla_id');

    expect(SaldoInventario::query()->where('activo_id', $this->pantalon->id)->exists())->toBeFalse()
        ->and(MovimientoInventario::query()->where('activo_id', $this->pantalon->id)->exists())->toBeFalse();
});
