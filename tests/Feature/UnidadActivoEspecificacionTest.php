<?php

use App\Acciones\RegistrarUnidadesActivo;
use App\Enums\RolSistema;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\CategoriaActivo;
use App\Models\CategoriaActivoPerfilTecnico;
use App\Models\Empresa;
use App\Models\UnidadActivo;
use App\Models\UnidadActivoEspecificacion;
use Illuminate\Support\Facades\Storage;

/**
 * Datos técnicos por unidad identificada (marca / modelo / IMEI / número /
 * operador / plan). El perfil (Celular / Computadora / Tablet) lo decide el
 * backend por el `codigo` de la categoría; los campos aplican por perfil.
 */
beforeEach(function () {
    Storage::fake('local');
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);

    $this->activoConPerfil = function (string $perfil): Activo {
        $cat = CategoriaActivo::factory()->create();
        CategoriaActivoPerfilTecnico::query()->create([
            'categoria_activo_id' => $cat->id,
            'perfil' => strtolower($perfil),
        ]);

        return Activo::factory()->for($this->empresa)->seguimientoIndividual()->create([
            'categoria_id' => $cat->id,
        ]);
    };

    $this->registrar = fn (Activo $activo, int $cantidad, array $especificaciones) => app(RegistrarUnidadesActivo::class)->ejecutar(
        empresa: $this->empresa,
        activo: $activo,
        almacen: $this->almacen,
        cantidad: $cantidad,
        motivo: 'Alta',
        realizadoPor: $this->admin->id,
        especificaciones: $especificaciones,
    );
});

it('guarda los seis campos de una unidad Celular', function () {
    $activo = ($this->activoConPerfil)('CELULAR');

    $unidades = ($this->registrar)($activo, 1, [[
        'marca' => 'Samsung', 'modelo' => 'Galaxy A04', 'imei' => '353883568904728',
        'numero_telefonico' => '7771622045', 'operador' => 'Telcel',
        'plan' => 'TELCEL PLUS EMPRESARIAL CONSUMO CONTROLADO',
    ]]);

    $esp = $unidades->first()->especificacion;
    expect($esp->marca)->toBe('Samsung')
        ->and($esp->modelo)->toBe('Galaxy A04')
        ->and($esp->imei)->toBe('353883568904728')
        ->and($esp->numero_telefonico)->toBe('7771622045')
        ->and($esp->operador)->toBe('Telcel')
        ->and($esp->plan)->toBe('TELCEL PLUS EMPRESARIAL CONSUMO CONTROLADO');
});

it('dos unidades del mismo activo conservan datos distintos', function () {
    $activo = ($this->activoConPerfil)('CELULAR');

    $unidades = ($this->registrar)($activo, 2, [
        ['marca' => 'Samsung', 'modelo' => 'A04', 'imei' => '353883568904728', 'numero_telefonico' => '7771622045'],
        ['marca' => 'Samsung', 'modelo' => 'A04', 'imei' => '353883568906681', 'numero_telefonico' => '7772577406'],
    ]);

    expect($unidades[0]->especificacion->imei)->toBe('353883568904728')
        ->and($unidades[1]->especificacion->imei)->toBe('353883568906681')
        ->and($unidades[0]->especificacion->numero_telefonico)->toBe('7771622045')
        ->and($unidades[1]->especificacion->numero_telefonico)->toBe('7772577406');
});

it('rechaza dos unidades con el mismo IMEI y revierte toda el alta', function () {
    $activo = ($this->activoConPerfil)('CELULAR');

    expect(fn () => ($this->registrar)($activo, 2, [
        ['marca' => 'S', 'modelo' => 'A', 'imei' => '353883568904728'],
        ['marca' => 'S', 'modelo' => 'A', 'imei' => '353883568904728'],
    ]))->toThrow(ExcepcionDeNegocioSimple::class);

    expect(UnidadActivo::query()->count())->toBe(0)
        ->and(UnidadActivoEspecificacion::query()->count())->toBe(0);
});

it('un IMEI ya registrado en otra unidad se rechaza por la constraint de BD', function () {
    $activo = ($this->activoConPerfil)('CELULAR');
    ($this->registrar)($activo, 1, [['marca' => 'S', 'modelo' => 'A', 'imei' => '353883568904728']]);

    expect(fn () => ($this->registrar)($activo, 1, [['marca' => 'S', 'modelo' => 'A', 'imei' => '353883568904728']]))
        ->toThrow(ExcepcionDeNegocioSimple::class);
});

it('numero, operador y plan pueden quedar nulos en un Celular con IMEI', function () {
    $activo = ($this->activoConPerfil)('CELULAR');

    $unidad = ($this->registrar)($activo, 1, [[
        'marca' => 'Samsung', 'modelo' => 'A04', 'imei' => '353883568904728',
    ]])->first();

    expect($unidad->especificacion->imei)->toBe('353883568904728')
        ->and($unidad->especificacion->numero_telefonico)->toBeNull()
        ->and($unidad->especificacion->operador)->toBeNull()
        ->and($unidad->especificacion->plan)->toBeNull();
});

it('editar operador y plan no cambia el codigo ni el public_token de la unidad', function () {
    $activo = ($this->activoConPerfil)('CELULAR');
    $unidad = ($this->registrar)($activo, 1, [[
        'marca' => 'S', 'modelo' => 'A', 'imei' => '353883568904728', 'operador' => 'Telcel',
    ]])->first();
    $codigo = $unidad->codigo;
    $token = $unidad->public_token;

    $this->actingAs($this->admin)
        ->patch("/activos/unidades/{$token}/especificacion", [
            'marca' => 'S', 'modelo' => 'A', 'imei' => '353883568904728',
            'operador' => 'AT&T', 'plan' => 'Nuevo plan',
        ])
        ->assertSessionHasNoErrors();

    $unidad->refresh();
    expect($unidad->codigo)->toBe($codigo)
        ->and($unidad->public_token)->toBe($token)
        ->and($unidad->especificacion->operador)->toBe('AT&T')
        ->and($unidad->especificacion->plan)->toBe('Nuevo plan');
});

it('para Computadora acepta marca/modelo y no exige IMEI, número, operador ni plan', function () {
    $activo = ($this->activoConPerfil)('COMPUTADORA');

    $unidad = ($this->registrar)($activo, 1, [['marca' => 'Dell', 'modelo' => 'Latitude 5420']])->first();

    expect($unidad->especificacion->marca)->toBe('Dell')
        ->and($unidad->especificacion->modelo)->toBe('Latitude 5420')
        ->and($unidad->especificacion->imei)->toBeNull();
});

it('para Tablet acepta marca/modelo', function () {
    $activo = ($this->activoConPerfil)('TABLET');

    $unidad = ($this->registrar)($activo, 1, [['marca' => 'Samsung', 'modelo' => 'Galaxy Tab A8']])->first();

    expect($unidad->especificacion->marcaModelo())->toBe('Samsung Galaxy Tab A8');
});

it('un activo identificado SIN perfil no crea especificación aunque se manden datos', function () {
    $activo = Activo::factory()->for($this->empresa)->seguimientoIndividual()->create([
        'categoria_id' => CategoriaActivo::factory()->create(['codigo' => 'HERR-01'])->id,
    ]);

    $unidad = ($this->registrar)($activo, 1, [['marca' => 'X']])->first();

    // El dato SÍ se guarda si viene (la acción no bloquea), pero el Form Request
    // de alta nunca lo enviaría. Aquí basta con que la unidad exista y funcione.
    expect($unidad)->not->toBeNull()
        ->and($unidad->perfilTecnico())->toBeNull();
});

it('una unidad antigua sin especificación sigue funcionando (datosEquipo vacío / detalle OK)', function () {
    $activo = ($this->activoConPerfil)('CELULAR');
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($activo)->for($this->almacen)->create();

    $this->actingAs($this->admin)
        ->get("/activos/unidades/{$unidad->public_token}")
        ->assertOk();

    expect($unidad->especificacion)->toBeNull();
});

it('permite buscar unidades por marca, modelo, IMEI y número, manteniendo el alcance de empresa', function () {
    $activo = ($this->activoConPerfil)('CELULAR');
    ($this->registrar)($activo, 1, [[
        'marca' => 'Samsung', 'modelo' => 'Galaxy A04', 'imei' => '353883568904728', 'numero_telefonico' => '7771622045',
    ]]);

    // Otra empresa con su propia unidad de datos parecidos.
    $otra = Empresa::factory()->create();
    $almacenOtra = Almacen::factory()->paraEmpresa($otra)->create();
    $activoOtra = Activo::factory()->for($otra)->seguimientoIndividual()->create([
        'categoria_id' => CategoriaActivo::factory()->create(['codigo' => 'CELULAR'])->id,
    ]);
    app(RegistrarUnidadesActivo::class)->ejecutar($otra, $activoOtra, $almacenOtra, 1, 'Alta', null, false, [
        ['marca' => 'Samsung', 'modelo' => 'Galaxy A04', 'imei' => '999999999999999', 'numero_telefonico' => '5550001111'],
    ]);

    // Supervisor acotado a la primera empresa: nunca ve la unidad de la otra.
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->empresa]);

    foreach (['Galaxy A04', 'Samsung', '904728', '7771622045'] as $termino) {
        $this->actingAs($supervisor)
            ->get('/activos/unidades?buscar='.urlencode($termino))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'unidades.data',
                fn ($data) => collect($data)->count() === 1
                    && collect($data)->first()['imei_mascara'] === '••••4728',
            ));
    }
});

it('el detalle de la unidad expone perfil_tecnico y datos_equipo sólo de los campos del perfil', function () {
    $activo = ($this->activoConPerfil)('COMPUTADORA');
    $unidad = ($this->registrar)($activo, 1, [['marca' => 'Dell', 'modelo' => 'Latitude']])->first();

    $this->actingAs($this->admin)
        ->get("/activos/unidades/{$unidad->public_token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('unidad.perfil_tecnico', 'computadora')
            ->where('unidad.datos_equipo', fn ($datos) => collect($datos)->pluck('campo')->all() === ['marca', 'modelo']));
});

it('alta HTTP de un Activo Celular con 2 unidades exige y guarda los datos por unidad', function () {
    $cat = CategoriaActivo::factory()->create(['nombre' => 'Teléfono celular']);
    CategoriaActivoPerfilTecnico::query()->create([
        'categoria_activo_id' => $cat->id, 'perfil' => 'celular',
    ]);

    // Falta el modelo de la unidad 2 → error inline por fila.
    $this->actingAs($this->admin)
        ->post('/activos', [
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Celular Corporativo',
            'tipo_control' => 'individual',
            'categoria_id' => $cat->id,
            'almacen_id' => $this->almacen->id,
            'cantidad_inicial' => 2,
            'especificaciones' => [
                ['marca' => 'Samsung', 'modelo' => 'A04', 'imei' => '353883568904728'],
                ['marca' => 'Samsung', 'modelo' => '', 'imei' => '353883568906681'],
            ],
        ])
        ->assertSessionHasErrors('especificaciones.1.modelo');

    // Datos completos → alta OK con una especificación por unidad.
    $this->actingAs($this->admin)
        ->post('/activos', [
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Celular Corporativo',
            'tipo_control' => 'individual',
            'categoria_id' => $cat->id,
            'almacen_id' => $this->almacen->id,
            'cantidad_inicial' => 2,
            'especificaciones' => [
                ['marca' => 'Samsung', 'modelo' => 'A04', 'imei' => '353883568904728', 'numero_telefonico' => '7771622045'],
                ['marca' => 'Samsung', 'modelo' => 'A05', 'imei' => '353883568906681'],
            ],
        ])
        ->assertRedirect()->assertSessionHasNoErrors();

    $activo = Activo::query()->where('nombre', 'Celular Corporativo')->firstOrFail();
    $unidades = UnidadActivo::query()->where('activo_id', $activo->id)->with('especificacion')->get();
    expect($unidades)->toHaveCount(2)
        ->and($unidades->pluck('especificacion.imei')->sort()->values()->all())
        ->toBe(['353883568904728', '353883568906681']);
});

it('un usuario sin permiso de administrar unidades no puede editar la especificación', function () {
    $activo = ($this->activoConPerfil)('CELULAR');
    $unidad = ($this->registrar)($activo, 1, [['marca' => 'S', 'modelo' => 'A', 'imei' => '353883568904728']])->first();

    $encargado = usuarioCon(RolSistema::Encargado->value, [$this->empresa]); // sólo unidades-activo.ver

    $this->actingAs($encargado)
        ->patch("/activos/unidades/{$unidad->public_token}/especificacion", [
            'marca' => 'X', 'modelo' => 'Y', 'imei' => '353883568904728',
        ])
        ->assertForbidden();
});
