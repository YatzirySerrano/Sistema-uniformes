<?php

use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sucursal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    sembrarRolesPermisos();
});

/*
|--------------------------------------------------------------------------
| Alcance: Superadministrador y Administrador ven todas las empresas
|--------------------------------------------------------------------------
*/

it('un superadministrador ve todas las empresas', function () {
    Empresa::factory()->count(3)->create();

    $this->actingAs(usuarioCon(RolSistema::Superadministrador->value))
        ->get('/empresas')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Empresas/Index')
            ->where('empresas.total', 3)
        );
});

it('un administrador ve todas las empresas sin depender de empresa_usuario', function () {
    Empresa::factory()->count(3)->create();

    // Administrador sin ninguna asignación en empresa_usuario.
    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->get('/empresas')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('empresas.total', 3)
            ->where('puedeCrear', true)
        );
});

it('un administrador puede ver el detalle de cualquier empresa', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->get("/empresas/{$empresa->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Empresas/Detalle')
            ->where('empresa.id', $empresa->id)
        );
});

/*
|--------------------------------------------------------------------------
| Alcance restringido: Supervisor
|--------------------------------------------------------------------------
*/

it('un supervisor sólo ve en el listado las empresas asignadas', function () {
    $a = Empresa::factory()->create(['nombre_comercial' => 'Empresa A']);
    Empresa::factory()->create(['nombre_comercial' => 'Empresa B']);

    $this->actingAs(usuarioCon(RolSistema::Supervisor->value, [$a]))
        ->get('/empresas')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('empresas.total', 1)
            ->where('empresas.data.0.id', $a->id)
        );
});

it('un supervisor no puede ver el detalle de una empresa no asignada (IDOR)', function () {
    $a = Empresa::factory()->create();
    $b = Empresa::factory()->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$a]);

    $this->actingAs($supervisor)->get("/empresas/{$a->id}")->assertOk();
    $this->actingAs($supervisor)->get("/empresas/{$b->id}")->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Crear / editar / cambiar estado
|--------------------------------------------------------------------------
*/

it('un administrador puede registrar una empresa', function () {
    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/empresas', [
            'nombre_comercial' => 'Empresa del Admin',
            'telefono' => '55-1234-5678',
        ])
        ->assertRedirect('/empresas')
        ->assertSessionHas('toast');

    $empresa = Empresa::query()->where('nombre_comercial', 'Empresa del Admin')->first();
    expect($empresa)->not->toBeNull();
    expect($empresa->telefono)->toBe('5512345678');
    expect($empresa->codigo)->not->toBeEmpty();
});

it('un supervisor no puede registrar una empresa', function () {
    $this->actingAs(usuarioCon(RolSistema::Supervisor->value))
        ->post('/empresas', ['nombre_comercial' => 'Intento'])
        ->assertForbidden();

    expect(Empresa::query()->where('nombre_comercial', 'Intento')->exists())->toBeFalse();
});

it('un administrador puede editar cualquier empresa', function () {
    $empresa = Empresa::factory()->create(['nombre_comercial' => 'Antes']);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->put("/empresas/{$empresa->id}", [
            'nombre_comercial' => 'Después',
            'codigo' => $empresa->codigo,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($empresa->fresh()->nombre_comercial)->toBe('Después');
});

it('un supervisor no puede editar ni cambiar el estado de una empresa', function () {
    $empresa = Empresa::factory()->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);

    $this->actingAs($supervisor)->put("/empresas/{$empresa->id}", ['nombre_comercial' => 'X'])->assertForbidden();
    $this->actingAs($supervisor)->post("/empresas/{$empresa->id}/estado")->assertForbidden();
});

it('cambiar el estado de una empresa como administrador alterna el valor y audita', function () {
    $empresa = Empresa::factory()->create(['activa' => true]);
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post("/empresas/{$empresa->id}/estado")
        ->assertRedirect()
        ->assertSessionHas('toast');
    expect($empresa->fresh()->activa)->toBeFalse();

    $this->actingAs($admin)->post("/empresas/{$empresa->id}/estado");
    expect($empresa->fresh()->activa)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Sin "empresa activa": el selector global y su ruta ya no existen
|--------------------------------------------------------------------------
*/

it('ya no existe la ruta para fijar una empresa activa', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    $a = Empresa::factory()->create();

    $this->actingAs($admin)->post('/empresa-activa', ['empresa_id' => $a->id])->assertNotFound();
});

it('el detalle de empresa ya no expone es_empresa_activa', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    $a = Empresa::factory()->create();

    $this->actingAs($admin)
        ->get("/empresas/{$a->id}")
        ->assertInertia(fn ($page) => $page->missing('empresa.es_empresa_activa'));
});

it('las empresas autorizadas se comparten a todas las vistas para los combobox', function () {
    $a = Empresa::factory()->create();
    $b = Empresa::factory()->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$a, $b]);

    $this->actingAs($supervisor)
        ->get('/empresas')
        ->assertInertia(fn ($page) => $page->has('empresasAutorizadas', 2));
});

/*
|--------------------------------------------------------------------------
| Validaciones (nunca un 500 por entradas normales)
|--------------------------------------------------------------------------
*/

it('rechaza entradas inválidas con errores de validación en español', function () {
    $super = usuarioCon(RolSistema::Superadministrador->value);

    $this->actingAs($super)->from('/empresas')
        ->post('/empresas', [
            'nombre_comercial' => '',
            'correo' => 'no-es-correo',
            'telefono' => '123',
            'rfc' => '12345',
        ])
        ->assertRedirect('/empresas')
        ->assertSessionHasErrors(['nombre_comercial', 'correo', 'telefono', 'rfc']);
});

it('el mensaje de teléfono inválido indica que deben ser 10 dígitos', function () {
    $super = usuarioCon(RolSistema::Superadministrador->value);

    $this->actingAs($super)->from('/empresas')
        ->post('/empresas', ['nombre_comercial' => 'Con Teléfono Corto', 'telefono' => '12345'])
        ->assertSessionHasErrors(['telefono' => 'El teléfono debe contener 10 dígitos.']);
});

it('acepta un RFC y un teléfono con formato válido tras normalizar', function () {
    $super = usuarioCon(RolSistema::Superadministrador->value);

    $this->actingAs($super)
        ->post('/empresas', [
            'nombre_comercial' => 'Con Datos',
            'rfc' => 'abc010203xy1',
            'telefono' => '(777) 123 45 67',
        ])
        ->assertSessionHasNoErrors();

    $empresa = Empresa::query()->where('nombre_comercial', 'Con Datos')->first();
    expect($empresa->rfc)->toBe('ABC010203XY1');
    expect($empresa->telefono)->toBe('7771234567');
});

it('no produce un error 500 cuando un campo llega con un tipo inesperado', function () {
    $super = usuarioCon(RolSistema::Superadministrador->value);

    $this->actingAs($super)->from('/empresas')
        ->post('/empresas', ['nombre_comercial' => ['array', 'no', 'string']])
        ->assertRedirect('/empresas')
        ->assertSessionHasErrors('nombre_comercial');
});

/*
|--------------------------------------------------------------------------
| Contadores de sucursales y colaboradores (sólo activos)
|--------------------------------------------------------------------------
*/

it('el resumen cuenta únicamente las sucursales activas', function () {
    $empresa = Empresa::factory()->create();
    Sucursal::factory()->count(3)->for($empresa)->create(['activa' => true]);
    Sucursal::factory()->for($empresa)->create(['activa' => false]);

    $this->actingAs(usuarioCon(RolSistema::Superadministrador->value))
        ->get("/empresas/{$empresa->id}")
        ->assertInertia(fn ($page) => $page
            ->where('empresa.sucursales_activas', 3)
            ->where('empresa.sucursales_total', 4)
        );
});

it('el resumen cuenta únicamente los colaboradores activos', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    Colaborador::factory()->count(4)->for($empresa)->for($sucursal)->create();
    Colaborador::factory()->count(2)->for($empresa)->for($sucursal)->inactivo()->create();

    $this->actingAs(usuarioCon(RolSistema::Superadministrador->value))
        ->get("/empresas/{$empresa->id}")
        ->assertInertia(fn ($page) => $page
            ->where('empresa.colaboradores_activos', 4)
            ->where('empresa.colaboradores_total', 6)
        );
});

it('al desactivar una sucursal el contador de la empresa disminuye, y aumenta al reactivarla', function () {
    $empresa = Empresa::factory()->create();
    $sucursales = Sucursal::factory()->count(2)->for($empresa)->create(['activa' => true]);
    $super = usuarioCon(RolSistema::Superadministrador->value);

    $this->actingAs($super)->get("/empresas/{$empresa->id}")
        ->assertInertia(fn ($page) => $page->where('empresa.sucursales_activas', 2));

    $sucursales->first()->update(['activa' => false]);

    $this->actingAs($super)->get("/empresas/{$empresa->id}")
        ->assertInertia(fn ($page) => $page->where('empresa.sucursales_activas', 1));

    $sucursales->first()->update(['activa' => true]);

    $this->actingAs($super)->get("/empresas/{$empresa->id}")
        ->assertInertia(fn ($page) => $page->where('empresa.sucursales_activas', 2));
});

it('el detalle de una empresa recién creada informa 0 sucursales y 0 colaboradores', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->get("/empresas/{$empresa->id}")
        ->assertInertia(fn ($page) => $page
            ->where('empresa.sucursales_total', 0)
            ->where('empresa.sucursales_activas', 0)
            ->where('empresa.colaboradores_total', 0)
            ->where('empresa.colaboradores_activos', 0)
        );
});

/*
|--------------------------------------------------------------------------
| Búsqueda y filtros combinables
|--------------------------------------------------------------------------
*/

it('lista las empresas inactivas y permite filtrarlas por estado', function () {
    Empresa::factory()->create(['nombre_comercial' => 'Viva', 'activa' => true]);
    Empresa::factory()->create(['nombre_comercial' => 'Apagada', 'activa' => false]);
    $super = usuarioCon(RolSistema::Superadministrador->value);

    $this->actingAs($super)->get('/empresas')
        ->assertInertia(fn ($page) => $page->where('empresas.total', 2));

    $this->actingAs($super)->get('/empresas?estado=inactivas')
        ->assertInertia(fn ($page) => $page
            ->where('empresas.total', 1)
            ->where('empresas.data.0.nombre_comercial', 'Apagada')
        );

    $this->actingAs($super)->get('/empresas?estado=activas')
        ->assertInertia(fn ($page) => $page
            ->where('empresas.total', 1)
            ->where('empresas.data.0.nombre_comercial', 'Viva')
        );
});

it('un supervisor nunca ve empresas eliminadas, ni forzando el filtro por URL, y no recibe la opción', function () {
    $propia = Empresa::factory()->create(['nombre_comercial' => 'Viva']);
    $ajenaEliminada = Empresa::factory()->create(['nombre_comercial' => 'Apagada', 'activa' => false]);

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$propia, $ajenaEliminada]);

    $this->actingAs($supervisor)->get('/empresas')
        ->assertInertia(fn ($page) => $page
            ->where('empresas.total', 1)
            ->where('empresas.data.0.nombre_comercial', 'Viva')
            ->where('puedeVerEliminadas', false),
        );

    $this->actingAs($supervisor)->get('/empresas?estado=inactivas')
        ->assertInertia(fn ($page) => $page
            ->where('empresas.total', 1)
            ->where('empresas.data.0.nombre_comercial', 'Viva'),
        );
});

it('filtra por con/sin sucursales activas y por con/sin colaboradores activos', function () {
    $conSucursal = Empresa::factory()->create(['nombre_comercial' => 'Con Sucursal']);
    Sucursal::factory()->for($conSucursal)->create(['activa' => true]);

    $sinSucursal = Empresa::factory()->create(['nombre_comercial' => 'Sin Sucursal']);
    // Sucursal inactiva: no debe contar como "con sucursales".
    Sucursal::factory()->for($sinSucursal)->create(['activa' => false]);

    $sucursalConColab = Sucursal::factory()->for($conSucursal)->create();
    Colaborador::factory()->for($conSucursal)->for($sucursalConColab)->create();

    $super = usuarioCon(RolSistema::Superadministrador->value);

    $this->actingAs($super)->get('/empresas?sucursales=con')
        ->assertInertia(fn ($page) => $page
            ->where('empresas.total', 1)
            ->where('empresas.data.0.nombre_comercial', 'Con Sucursal')
        );

    $this->actingAs($super)->get('/empresas?sucursales=sin')
        ->assertInertia(fn ($page) => $page
            ->where('empresas.total', 1)
            ->where('empresas.data.0.nombre_comercial', 'Sin Sucursal')
        );

    $this->actingAs($super)->get('/empresas?colaboradores=sin')
        ->assertInertia(fn ($page) => $page
            ->where('empresas.total', 1)
            ->where('empresas.data.0.nombre_comercial', 'Sin Sucursal')
        );
});

it('respeta el orden alfabético descendente', function () {
    Empresa::factory()->create(['nombre_comercial' => 'Alfa']);
    Empresa::factory()->create(['nombre_comercial' => 'Zeta']);

    $this->actingAs(usuarioCon(RolSistema::Superadministrador->value))
        ->get('/empresas?orden=za')
        ->assertInertia(fn ($page) => $page
            ->where('empresas.data.0.nombre_comercial', 'Zeta')
            ->where('empresas.data.1.nombre_comercial', 'Alfa')
        );
});

/*
|--------------------------------------------------------------------------
| Logo: alta, edición, mensajes de error (bug de QA final)
|--------------------------------------------------------------------------
*/

it('registra una empresa sin logo correctamente', function () {
    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/empresas', ['nombre_comercial' => 'Sin Logo'])
        ->assertRedirect('/empresas')
        ->assertSessionHasNoErrors();

    expect(Empresa::query()->where('nombre_comercial', 'Sin Logo')->first()->logo_ruta)->toBeNull();
});

it('registra una empresa con logo PNG y lo persiste en storage', function () {
    Storage::fake('public');

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/empresas', [
            'nombre_comercial' => 'Con Logo PNG',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])
        ->assertRedirect('/empresas')
        ->assertSessionHasNoErrors();

    $empresa = Empresa::query()->where('nombre_comercial', 'Con Logo PNG')->first();
    expect($empresa->logo_ruta)->not->toBeNull();
    Storage::disk('public')->assertExists($empresa->logo_ruta);
});

it('registra una empresa con logo JPG y lo persiste en storage', function () {
    Storage::fake('public');

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/empresas', [
            'nombre_comercial' => 'Con Logo JPG',
            'logo' => UploadedFile::fake()->image('logo.jpg', 200, 200),
        ])
        ->assertRedirect('/empresas')
        ->assertSessionHasNoErrors();

    $empresa = Empresa::query()->where('nombre_comercial', 'Con Logo JPG')->first();
    expect($empresa->logo_ruta)->not->toBeNull();
    Storage::disk('public')->assertExists($empresa->logo_ruta);
});

it('el detalle de una empresa con logo expone logo_url', function () {
    Storage::fake('public');
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->post('/empresas', [
        'nombre_comercial' => 'Con Logo Detalle',
        'logo' => UploadedFile::fake()->image('logo.png'),
    ]);

    $empresa = Empresa::query()->where('nombre_comercial', 'Con Logo Detalle')->first();

    $this->actingAs($admin)->get("/empresas/{$empresa->id}")
        ->assertInertia(fn ($page) => $page->where('empresa.logo_url', fn (?string $url) => filled($url)));
});

it('editar una empresa sin subir un logo nuevo conserva el logo previamente cargado', function () {
    Storage::fake('public');
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->post('/empresas', [
        'nombre_comercial' => 'Editar Conserva Logo',
        'logo' => UploadedFile::fake()->image('logo.png'),
    ]);
    $empresa = Empresa::query()->where('nombre_comercial', 'Editar Conserva Logo')->first();
    $rutaOriginal = $empresa->logo_ruta;

    $this->actingAs($admin)->put("/empresas/{$empresa->id}", [
        'nombre_comercial' => 'Editar Conserva Logo Actualizada',
        'codigo' => $empresa->codigo,
    ])->assertSessionHasNoErrors();

    expect($empresa->fresh()->logo_ruta)->toBe($rutaOriginal);
    Storage::disk('public')->assertExists($rutaOriginal);
});

it('reemplazar el logo en edición guarda el nuevo archivo y borra el anterior', function () {
    Storage::fake('public');
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->post('/empresas', [
        'nombre_comercial' => 'Reemplazo Logo',
        'logo' => UploadedFile::fake()->image('logo.png'),
    ]);
    $empresa = Empresa::query()->where('nombre_comercial', 'Reemplazo Logo')->first();
    $rutaOriginal = $empresa->logo_ruta;

    $this->actingAs($admin)->put("/empresas/{$empresa->id}", [
        'nombre_comercial' => 'Reemplazo Logo',
        'codigo' => $empresa->codigo,
        'logo' => UploadedFile::fake()->image('logo-nuevo.png'),
    ])->assertSessionHasNoErrors();

    $rutaNueva = $empresa->fresh()->logo_ruta;
    expect($rutaNueva)->not->toBe($rutaOriginal);
    Storage::disk('public')->assertMissing($rutaOriginal);
    Storage::disk('public')->assertExists($rutaNueva);
});

it('rechaza un logo que supera los 2 MB con un mensaje claro de peso', function () {
    Storage::fake('public');

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/empresas')
        ->post('/empresas', [
            'nombre_comercial' => 'Logo Pesado',
            'logo' => UploadedFile::fake()->image('logo.png')->size(3000),
        ])
        ->assertSessionHasErrors(['logo' => 'El logotipo no puede superar los 2 MB.']);
});

it('rechaza un formato de logo no permitido con un mensaje claro de formato', function () {
    Storage::fake('public');

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/empresas')
        ->post('/empresas', [
            'nombre_comercial' => 'Logo Formato Invalido',
            // .webp es una imagen válida mimes:image pero no está en la lista permitida.
            'logo' => UploadedFile::fake()->image('logo.webp'),
        ])
        ->assertSessionHasErrors(['logo' => 'El logotipo debe ser un archivo PNG, JPG, JPEG o SVG.']);
});

it('rechaza un archivo que no es una imagen válida con un mensaje claro', function () {
    Storage::fake('public');

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/empresas')
        ->post('/empresas', [
            'nombre_comercial' => 'Logo No Es Imagen',
            'logo' => UploadedFile::fake()->create('logo.png', 10, 'application/pdf'),
        ])
        ->assertSessionHasErrors(['logo' => 'El archivo seleccionado no es una imagen válida.']);
});

it('muestra un mensaje claro cuando PHP rechaza la subida del logo antes de validar', function () {
    Storage::fake('public');
    $archivo = UploadedFile::fake()->image('logo.png');
    $archivoRechazado = new UploadedFile(
        $archivo->getPathname(),
        'logo.png',
        'image/png',
        UPLOAD_ERR_INI_SIZE,
        true,
    );

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->from('/empresas')
        ->post('/empresas', [
            'nombre_comercial' => 'Logo Rechazado Por PHP',
            'logo' => $archivoRechazado,
        ])
        ->assertSessionHasErrors(['logo' => 'El logotipo no pudo cargarse. Verifica que el archivo no supere los 2 MB.']);
});
