<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\ImagenUnidadActivo;
use App\Models\UnidadActivo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * L/M/N: foto OPCIONAL 1:1 por UnidadActivo, tabla dedicada
 * `imagenes_unidad_activo` (separada de `evidencias`), reutilizando la
 * infraestructura ya auditada (`ServicioEvidencias`: disco privado, MIME
 * real, hash, cleanup). Nunca toca codigo/public_token/estado/condicion.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    Storage::fake('local');
    $this->empresa = Empresa::factory()->create();
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $this->activo = Activo::factory()->for($this->empresa)->seguimientoIndividual()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
});

it('una unidad sin foto sigue funcionando con normalidad', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();

    $this->actingAs($this->admin)
        ->get("/activos/unidades/{$unidad->public_token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('unidad.imagen_url', null));
});

it('el alta múltiple asocia cada foto EXACTAMENTE a su unidad por índice, nunca cruzadas', function () {
    $this->actingAs($this->admin)->post('/activos', [
        'empresa_id' => $this->empresa->id,
        'nombre' => 'Radio portátil',
        'tipo_control' => 'individual',
        'almacen_id' => $this->almacen->id,
        'cantidad_inicial' => 2,
        'imagenes' => [
            0 => UploadedFile::fake()->image('a.jpg', 20, 20),
            1 => UploadedFile::fake()->image('b.jpg', 40, 40),
        ],
    ])->assertSessionHasNoErrors();

    $activo = Activo::query()->where('nombre', 'Radio portátil')->firstOrFail();
    $unidades = UnidadActivo::query()->where('activo_id', $activo->id)->orderBy('id')->get();
    expect($unidades)->toHaveCount(2);

    $imagenes = ImagenUnidadActivo::query()->whereIn('unidad_activo_id', $unidades->pluck('id'))->get()->keyBy('unidad_activo_id');
    expect($imagenes)->toHaveCount(2);

    $hashUnidad0 = $imagenes[$unidades[0]->id]->hash_sha256;
    $hashUnidad1 = $imagenes[$unidades[1]->id]->hash_sha256;
    expect($hashUnidad0)->not->toBe($hashUnidad1);

    foreach ($imagenes as $imagen) {
        Storage::disk('local')->assertExists($imagen->ruta);
    }
});

it('MIME inválido (contenido real no es imagen) es rechazado aunque el nombre/extensión digan lo contrario', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();

    $this->actingAs($this->admin)
        ->post("/activos/unidades/{$unidad->public_token}/imagen", [
            'imagen' => UploadedFile::fake()->create('foto.jpg', 5, 'image/jpeg'), // bytes basura, mime declarado falso
        ])
        ->assertSessionHasErrors('negocio');

    expect(ImagenUnidadActivo::query()->where('unidad_activo_id', $unidad->id)->exists())->toBeFalse();
});

it('un error a mitad del alta múltiple no deja fotos huérfanas en disco (la del índice 0 se limpia aunque el índice 1 falle)', function () {
    $this->actingAs($this->admin)
        ->post('/activos', [
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Taladro',
            'tipo_control' => 'individual',
            'almacen_id' => $this->almacen->id,
            'cantidad_inicial' => 2,
            'imagenes' => [
                0 => UploadedFile::fake()->image('ok.jpg'),
                1 => UploadedFile::fake()->create('malo.jpg', 5, 'image/jpeg'),
            ],
        ])
        ->assertSessionHasErrors('negocio');

    expect(Activo::query()->where('nombre', 'Taladro')->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles("unidades/{$this->empresa->id}"))->toBe([]);
});

it('sube, reemplaza y quita la foto de una unidad sin cambiar codigo/public_token/estado/condicion', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();
    $codigo = $unidad->codigo;
    $token = $unidad->public_token;

    $this->actingAs($this->admin)
        ->post("/activos/unidades/{$unidad->public_token}/imagen", ['imagen' => UploadedFile::fake()->image('v1.jpg')])
        ->assertRedirect()->assertSessionHasNoErrors();

    $rutaV1 = ImagenUnidadActivo::query()->where('unidad_activo_id', $unidad->id)->firstOrFail()->ruta;
    Storage::disk('local')->assertExists($rutaV1);

    $this->actingAs($this->admin)
        ->post("/activos/unidades/{$unidad->public_token}/imagen", ['imagen' => UploadedFile::fake()->image('v2.jpg')])
        ->assertRedirect()->assertSessionHasNoErrors();

    $rutaV2 = ImagenUnidadActivo::query()->where('unidad_activo_id', $unidad->id)->firstOrFail()->ruta;
    expect($rutaV2)->not->toBe($rutaV1);
    Storage::disk('local')->assertExists($rutaV2);
    Storage::disk('local')->assertMissing($rutaV1); // el archivo anterior se limpia al reemplazar

    $this->actingAs($this->admin)
        ->delete("/activos/unidades/{$unidad->public_token}/imagen")
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(ImagenUnidadActivo::query()->where('unidad_activo_id', $unidad->id)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing($rutaV2);

    $unidad->refresh();
    expect($unidad->codigo)->toBe($codigo)
        ->and($unidad->public_token)->toBe($token)
        ->and($unidad->estado->value)->toBe('en_almacen')
        ->and($unidad->condicion->value)->toBe('funcionando');
});

it('un usuario sin acceso a la empresa NO puede ver la foto privada de la unidad por URL directa (cross-empresa)', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();
    $this->actingAs($this->admin)
        ->post("/activos/unidades/{$unidad->public_token}/imagen", ['imagen' => UploadedFile::fake()->image('secreta.jpg')])
        ->assertSessionHasNoErrors();

    $otra = Empresa::factory()->create();
    $ajeno = usuarioCon(RolSistema::Supervisor->value, [$otra]);
    $ajeno->givePermissionTo('unidades-activo.ver');

    $this->actingAs($ajeno)
        ->get("/activos/unidades/{$unidad->public_token}/imagen")
        ->assertForbidden();
});

it('un usuario sin permiso administrar no puede subir ni quitar la foto (403)', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();
    $sinPermiso = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);

    $this->actingAs($sinPermiso)
        ->post("/activos/unidades/{$unidad->public_token}/imagen", ['imagen' => UploadedFile::fake()->image('x.jpg')])
        ->assertForbidden();

    $this->actingAs($sinPermiso)
        ->delete("/activos/unidades/{$unidad->public_token}/imagen")
        ->assertForbidden();
});

it('agregar existencias a un activo existente también asocia fotos por índice correctamente', function () {
    $this->actingAs($this->admin)
        ->post("/activos/{$this->activo->id}/existencias", [
            'almacen_id' => $this->almacen->id,
            'cantidad' => 2,
            'imagenes' => [1 => UploadedFile::fake()->image('solo-la-segunda.jpg')],
        ])
        ->assertSessionHasNoErrors();

    $unidades = UnidadActivo::query()->where('activo_id', $this->activo->id)->orderBy('id')->get();
    expect($unidades)->toHaveCount(2)
        ->and(ImagenUnidadActivo::query()->where('unidad_activo_id', $unidades[0]->id)->exists())->toBeFalse()
        ->and(ImagenUnidadActivo::query()->where('unidad_activo_id', $unidades[1]->id)->exists())->toBeTrue();
});
