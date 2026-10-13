<?php

use App\Acciones\RegistrarUnidadesActivo;
use App\Enums\RolSistema;
use App\Exports\ListadoExport;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\CategoriaActivo;
use App\Models\CategoriaActivoPerfilTecnico;
use App\Models\Empresa;
use App\Models\UnidadActivo;
use App\Models\User;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\Permission\Models\Role;

/**
 * Exportar (Excel / Ver PDF) de Activos → Unidades de seguimiento individual.
 *
 * Regresión: la pantalla comparte `filtros.no_disponible = false` y el botón
 * de exportar lo mandaba como `no_disponible=false`; la regla `boolean` lo
 * rechazaba y la descarga terminaba en un redirect mudo al listado. El
 * frontend ya lo omite (`lib/urlExportacion.ts`); aquí se cubre el backend:
 * filtros, alcance, columnas técnicas (Observaciones = columna `plan`) y
 * que la respuesta sea un archivo real.
 */
const ENCABEZADOS_UNIDADES = [
    'Código', 'Activo', 'Almacén', 'Colaborador', 'Servicio', 'Estado', 'Posesión', 'Condición',
    'Marca', 'Modelo', 'IMEI', 'Número', 'Operador', 'Observaciones',
];

beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);
    $this->supervisorA = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);
    $this->supervisorA->givePermissionTo('unidades-activo.ver');

    $this->activoConPerfil = function (Empresa $empresa, string $perfil, string $nombre): Activo {
        $categoria = CategoriaActivo::factory()->create();
        CategoriaActivoPerfilTecnico::query()->create(['categoria_activo_id' => $categoria->id, 'perfil' => $perfil]);

        return Activo::factory()->for($empresa)->seguimientoIndividual()->create([
            'categoria_id' => $categoria->id,
            'nombre' => $nombre,
        ]);
    };

    $this->registrar = fn (Empresa $empresa, Activo $activo, Almacen $almacen, array $especificacion): UnidadActivo => app(RegistrarUnidadesActivo::class)->ejecutar(
        empresa: $empresa,
        activo: $activo,
        almacen: $almacen,
        cantidad: 1,
        motivo: 'Alta',
        realizadoPor: $this->admin->id,
        especificaciones: [$especificacion],
    )->first();

    $this->celularA = ($this->registrar)(
        $this->datos['empresaA'],
        ($this->activoConPerfil)($this->datos['empresaA'], 'celular', 'iPhone 13'),
        $this->datos['almacenA'],
        ['marca' => 'Apple', 'modelo' => 'iPhone 13', 'imei' => '353883568904728', 'numero_telefonico' => '7771622045', 'operador' => 'Telcel', 'plan' => 'Incluye cargador original'],
    );
    $this->laptopA = ($this->registrar)(
        $this->datos['empresaA'],
        ($this->activoConPerfil)($this->datos['empresaA'], 'computadora', 'Laptop Dell'),
        $this->datos['almacenA'],
        ['marca' => 'Dell', 'modelo' => 'Latitude 5420', 'plan' => 'Incluye cargador y mochila'],
    );
    $this->tabletA = ($this->registrar)(
        $this->datos['empresaA'],
        ($this->activoConPerfil)($this->datos['empresaA'], 'tablet', 'Tablet Samsung'),
        $this->datos['almacenA'],
        ['marca' => 'Samsung', 'modelo' => 'Galaxy Tab A8', 'plan' => 'Con funda'],
    );
    $this->celularB = ($this->registrar)(
        $this->datos['empresaB'],
        ($this->activoConPerfil)($this->datos['empresaB'], 'celular', 'iPhone 14'),
        $this->datos['almacenB'],
        ['marca' => 'Apple', 'modelo' => 'iPhone 14', 'imei' => '353883568906681'],
    );

    /**
     * Filas exportadas (vía `Excel::fake()`) para el usuario y la query dada.
     * `$empresaResuelta` = empresa que el backend acepta del filtro (nombre de
     * archivo); null si no hay filtro o si está fuera de alcance.
     *
     * @return list<array<int, string|int|null>>
     */
    $this->filas = function (User $usuario, string $query = '', ?Empresa $empresaResuelta = null): array {
        Excel::fake();
        $filas = [];

        $this->actingAs($usuario)->get('/activos/unidades/exportar?'.$query)->assertOk();

        $segmentoEmpresa = $empresaResuelta === null ? 'todas-las-empresas' : Str::slug($empresaResuelta->nombre_comercial);
        Excel::assertDownloaded(
            'unidades-identificadas-'.$segmentoEmpresa.'-'.now()->toDateString().'.xlsx',
            function (ListadoExport $export) use (&$filas): bool {
                expect($export->headings())->toBe(ENCABEZADOS_UNIDADES);
                $filas = $export->array();

                return true;
            },
        );

        return $filas;
    };
});

it('el enlace que arma la pantalla (sin no_disponible=false) descarga el Excel; el valor "false" ya no lo manda', function () {
    // Lo que hoy manda la UI sin filtros.
    $this->actingAs($this->admin)->get('/activos/unidades/exportar?formato=xlsx')->assertOk();

    // Lo que mandaba antes: el backend sigue rechazándolo, pero ya no en
    // silencio — vuelve al listado con el toast global de error.
    $this->actingAs($this->admin)
        ->from('/activos/unidades')
        ->get('/activos/unidades/exportar?no_disponible=false&formato=xlsx')
        ->assertRedirect('/activos/unidades')
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'error'
            && str_starts_with($toast['message'], 'No se pudo exportar'));

    // `no_disponible=1` (filtro del KPI "No disponibles") sí se acepta.
    $this->actingAs($this->admin)->get('/activos/unidades/exportar?no_disponible=1&formato=xlsx')->assertOk();
});

it('descarga un .xlsx real con Content-Type y Content-Disposition correctos y las filas esperadas', function () {
    $respuesta = $this->actingAs($this->supervisorA)->get('/activos/unidades/exportar?formato=xlsx');

    $respuesta->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($respuesta->headers->get('content-disposition'))
        ->toStartWith('attachment;')
        ->toContain('unidades-identificadas-todas-las-empresas-'.now()->toDateString().'.xlsx');

    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    expect(filesize($ruta))->toBeGreaterThan(0)
        // Un .xlsx es un ZIP: firma "PK", nunca HTML disfrazado.
        ->and(file_get_contents($ruta, length: 2))->toBe('PK');

    $hoja = IOFactory::createReader('Xlsx')->load($ruta)->getSheetByName('Datos');
    $filas = $hoja->toArray(null, false, false, false);
    $indiceEncabezado = collect($filas)->search(fn (array $fila): bool => $fila[0] === 'Código');
    expect($indiceEncabezado)->not->toBeFalse()
        ->and(array_slice($filas[$indiceEncabezado], 0, count(ENCABEZADOS_UNIDADES)))->toBe(ENCABEZADOS_UNIDADES);

    $codigos = collect(array_slice($filas, $indiceEncabezado + 1))->pluck(0)->filter()->values()->all();
    expect($codigos)->toContain($this->celularA->codigo, $this->laptopA->codigo, $this->tabletA->codigo)
        ->not->toContain($this->celularB->codigo);
});

it('cada fila trae tantos valores como encabezados y Observaciones sale de la columna plan en Celular, Computadora y Tablet', function () {
    $porCodigo = collect(($this->filas)($this->admin))->keyBy(0);

    foreach ($porCodigo as $fila) {
        expect($fila)->toHaveCount(count(ENCABEZADOS_UNIDADES));
    }

    $observaciones = array_search('Observaciones', ENCABEZADOS_UNIDADES, true);
    expect($porCodigo[$this->celularA->codigo][$observaciones])->toBe('Incluye cargador original')
        ->and($porCodigo[$this->celularA->codigo][array_search('IMEI', ENCABEZADOS_UNIDADES, true)])->toBe('353883568904728')
        ->and($porCodigo[$this->celularA->codigo][array_search('Posesión', ENCABEZADOS_UNIDADES, true)])->toBe($this->celularA->estado->etiqueta())
        ->and($porCodigo[$this->celularA->codigo][array_search('Condición', ENCABEZADOS_UNIDADES, true)])->toBe($this->celularA->condicion->etiqueta())
        ->and($porCodigo[$this->laptopA->codigo][$observaciones])->toBe('Incluye cargador y mochila')
        ->and($porCodigo[$this->tabletA->codigo][$observaciones])->toBe('Con funda')
        ->and($porCodigo[$this->celularB->codigo][$observaciones])->toBeNull();
});

it('respeta los filtros de empresa, almacén, estado y búsqueda', function () {
    $codigos = fn (string $query, ?Empresa $empresa = null): array => array_column(($this->filas)($this->admin, $query, $empresa), 0);

    expect($codigos('empresa_id='.$this->datos['empresaB']->id, $this->datos['empresaB']))->toBe([$this->celularB->codigo]);

    expect($codigos('almacen_id='.$this->datos['almacenA']->id))
        ->toContain($this->celularA->codigo, $this->laptopA->codigo, $this->tabletA->codigo)
        ->not->toContain($this->celularB->codigo);

    $this->laptopA->update(['estado' => 'baja', 'dado_de_baja_en' => now(), 'motivo_baja' => 'Prueba']);
    expect($codigos('estado_visible=baja'))->toBe([$this->laptopA->codigo]);

    expect($codigos('buscar=iPhone'))->toEqualCanonicalizing([$this->celularA->codigo, $this->celularB->codigo]);

    // Combinados: empresa + almacén + estado + búsqueda.
    $combinado = http_build_query([
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'estado_visible' => 'disponible',
        'buscar' => 'iPhone',
    ]);
    expect($codigos($combinado, $this->datos['empresaA']))->toBe([$this->celularA->codigo]);
});

it('sin resultados exporta sólo encabezados, sin excepción', function () {
    expect(($this->filas)($this->admin, 'buscar=no-existe-nada'))->toBe([]);
});

it('una unidad sin especificación técnica no rompe la exportación', function () {
    $sinDatos = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')
        ->for(Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual())
        ->for($this->datos['almacenA'])
        ->create(['codigo' => 'SIN-ESPEC-1']);

    $fila = collect(($this->filas)($this->admin))->firstWhere(0, $sinDatos->codigo);

    expect($fila)->toHaveCount(count(ENCABEZADOS_UNIDADES))
        ->and(array_slice($fila, array_search('Marca', ENCABEZADOS_UNIDADES, true)))->toBe([null, null, null, null, null, null]);
});

it('manipular empresa_id o almacen_id fuera de alcance no exporta unidades ajenas', function () {
    $porEmpresa = array_column(($this->filas)($this->supervisorA, 'empresa_id='.$this->datos['empresaB']->id), 0);
    expect($porEmpresa)->not->toContain($this->celularB->codigo)
        ->toContain($this->celularA->codigo);

    $porAlmacen = array_column(($this->filas)($this->supervisorA, 'almacen_id='.$this->datos['almacenB']->id), 0);
    expect($porAlmacen)->toBe([]);
});

it('un rol personalizado con unidades-activo.ver exporta dentro de su alcance; sin el permiso recibe 403', function () {
    $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
    $rol->givePermissionTo('unidades-activo.ver');
    $personalizado = User::factory()->create();
    $personalizado->assignRole($rol);
    $personalizado->empresas()->sync([$this->datos['empresaA']->id]);

    $codigos = array_column(($this->filas)($personalizado), 0);
    expect($codigos)->toContain($this->celularA->codigo)->not->toContain($this->celularB->codigo);

    $rolSinPermiso = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
    $sinPermiso = User::factory()->create();
    $sinPermiso->assignRole($rolSinPermiso);
    $sinPermiso->empresas()->sync([$this->datos['empresaA']->id]);

    $this->actingAs($sinPermiso)->get('/activos/unidades/exportar?formato=xlsx')->assertForbidden();
    $this->actingAs($sinPermiso)->get('/activos/unidades/exportar?formato=pdf')->assertForbidden();
});

it('Ver PDF genera un PDF real (application/pdf, inline, %PDF-)', function () {
    $respuesta = $this->actingAs($this->supervisorA)->get('/activos/unidades/exportar?formato=pdf');

    $respuesta->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($respuesta->headers->get('content-disposition'))->toStartWith('inline;')->toContain('.pdf')
        ->and($respuesta->getContent())->toStartWith('%PDF-');
});

it('el PDF respeta filtros y alcance y rotula Observaciones', function () {
    Pdf::fake();

    $this->actingAs($this->supervisorA)
        ->get('/activos/unidades/exportar?formato=pdf&buscar=iPhone&empresa_id='.$this->datos['empresaB']->id)
        ->assertOk();

    Pdf::assertViewIs('reportes.listado-generico');
    Pdf::assertSee(['Observaciones', $this->celularA->codigo, 'Incluye cargador original']);
    Pdf::assertDontSee([$this->celularB->codigo, $this->laptopA->codigo, 'Plan']);
});
