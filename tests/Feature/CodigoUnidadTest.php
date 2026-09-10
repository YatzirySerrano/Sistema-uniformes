<?php

use App\Acciones\RegistrarUnidadesActivo;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\UnidadActivo;
use App\Servicios\ServicioEtiquetasQr;
use Illuminate\Support\Collection;

/**
 * El código VISIBLE de una unidad identificada nueva es `NOMBRE-ACTIVO-000001`,
 * con secuencia GLOBAL por nombre normalizado — independiente de
 * Empresa/Sucursal/Almacén. El `public_token` y el QR no dependen del código;
 * los códigos históricos no se renombran.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);

    $this->alta = fn (string $nombreActivo, int $cantidad = 1, ?Empresa $empresa = null, ?Almacen $almacen = null): Collection => app(RegistrarUnidadesActivo::class)->ejecutar(
        $empresa ?? $this->empresa,
        Activo::factory()->for($empresa ?? $this->empresa)->seguimientoIndividual()->create(['nombre' => $nombreActivo]),
        $almacen ?? $this->almacen,
        $cantidad,
        'Alta de prueba',
        $this->admin->id,
    );
});

it('la primera Tablet es TABLET-000001 y la segunda TABLET-000002', function () {
    expect(($this->alta)('Tablet')->first()->codigo)->toBe('TABLET-000001');
    expect(($this->alta)('Tablet')->first()->codigo)->toBe('TABLET-000002');
});

it('la secuencia por nombre es GLOBAL: una Tablet de OTRA empresa continúa la misma serie', function () {
    expect(($this->alta)('Tablet')->first()->codigo)->toBe('TABLET-000001');

    $otra = Empresa::factory()->create();
    $almacenOtra = Almacen::factory()->paraEmpresa($otra)->create();

    expect(($this->alta)('Tablet', 1, $otra, $almacenOtra)->first()->codigo)->toBe('TABLET-000002');
});

it('cada nombre de activo tiene su propia serie', function () {
    expect(($this->alta)('Celular')->first()->codigo)->toBe('CELULAR-000001');
    expect(($this->alta)('Laptop')->first()->codigo)->toBe('LAPTOP-000001');
});

it('normaliza acentos y espacios en el prefijo', function () {
    expect(($this->alta)('Cámara de seguridad')->first()->codigo)->toBe('CAMARA-DE-SEGURIDAD-000001');
});

it('un nombre sin slug utilizable cae en el prefijo UNIDAD', function () {
    expect(($this->alta)('日本語')->first()->codigo)->toBe('UNIDAD-000001');
});

it('un alta de varias unidades a la vez obtiene códigos consecutivos y sin colisión', function () {
    $codigos = ($this->alta)('Tablet', 5)->pluck('codigo');

    expect($codigos->all())->toBe(['TABLET-000001', 'TABLET-000002', 'TABLET-000003', 'TABLET-000004', 'TABLET-000005'])
        ->and(UnidadActivo::query()->whereIn('codigo', $codigos)->count())->toBe(5);
});

it('el código histórico BASE01-000011 no cambia y el QR sigue resolviendo por public_token', function () {
    $historica = UnidadActivo::factory()
        ->for($this->empresa)->for(Activo::factory()->for($this->empresa)->seguimientoIndividual())->for($this->almacen)
        ->create(['codigo' => 'BASE01-000011']);

    // Un alta nueva usa el formato nuevo; la histórica no se toca.
    ($this->alta)('Tablet');

    expect($historica->fresh()->codigo)->toBe('BASE01-000011');

    $token = $historica->public_token;
    expect(app(ServicioEtiquetasQr::class)->urlPublica($historica))->toEndWith("/activos/unidades/{$token}");

    // La búsqueda por código sigue funcionando con el formato nuevo.
    $nueva = UnidadActivo::query()->where('codigo', 'TABLET-000001')->firstOrFail();
    $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$nueva->activo_id}&almacen_id={$this->almacen->id}&q=TABLET-000001")
        ->assertOk()
        ->assertJsonPath('unidades.0.codigo', 'TABLET-000001');
});
