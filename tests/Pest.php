<?php

use App\Enums\CategoriaDocumentoExpediente;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\DocumentoExpediente;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Siembra roles y permisos base del sistema para las pruebas.
 */
function sembrarRolesPermisos(): void
{
    (new RolesPermisosSeeder)->run();
}

/**
 * Crea un escenario mínimo multiempresa: dos empresas con una sucursal cada
 * una, tallas y un activo por empresa, y devuelve las referencias.
 *
 * Cada empresa recibe un almacén activo vinculado a ella (pivote
 * `almacen_empresa`), de modo que las operaciones (entradas, entregas,
 * devoluciones) puedan resolver el almacén de origen del stock de forma
 * inequívoca.
 *
 * @return array{
 *     empresaA: Empresa,
 *     empresaB: Empresa,
 *     sucursalA: Sucursal,
 *     sucursalB: Sucursal,
 *     almacenA: Almacen,
 *     almacenB: Almacen,
 *     activoA: Activo,
 *     tallaA: Talla,
 *     colaboradorA: Colaborador
 * }
 */
function escenarioMultiempresa(): array
{
    sembrarRolesPermisos();

    $empresaA = Empresa::factory()->create(['nombre_comercial' => 'Empresa A']);
    $empresaB = Empresa::factory()->create(['nombre_comercial' => 'Empresa B']);

    $sucursalA = Sucursal::factory()->for($empresaA)->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();

    $almacenA = Almacen::factory()->paraEmpresa($empresaA)->create(['nombre' => 'Almacén A']);
    $almacenB = Almacen::factory()->paraEmpresa($empresaB)->create(['nombre' => 'Almacén B']);

    // Variante del catálogo COMPARTIDO habilitada para ambas empresas.
    $tallaA = Talla::factory()->create(['valor' => 'M']);

    $activoA = Activo::factory()->for($empresaA)->create(['nombre' => 'Camisa']);
    $activoA->tallas()->attach($tallaA);

    $colaboradorA = Colaborador::factory()->for($empresaA)->for($sucursalA)->create();

    return compact('empresaA', 'empresaB', 'sucursalA', 'sucursalB', 'almacenA', 'almacenB', 'activoA', 'tallaA', 'colaboradorA');
}

/**
 * Crea un usuario con el rol indicado, asociado a las empresas dadas. Ya no
 * existe "empresa activa": el contexto de empresa se determina en cada
 * petición (formulario / filtro / recurso).
 *
 * @param  array<int, Empresa>  $empresas
 */
function usuarioCon(string $rol, array $empresas = []): User
{
    $usuario = User::factory()->create();
    $usuario->assignRole($rol);
    $usuario->empresas()->sync(collect($empresas)->pluck('id'));

    return $usuario;
}

/**
 * Genera una firma PNG válida (data URI) para las pruebas de acuse.
 */
function firmaDemoBase64(): string
{
    $img = imagecreatetruecolor(360, 140);
    $blanco = imagecolorallocate($img, 255, 255, 255);
    $tinta = imagecolorallocate($img, 20, 30, 80);
    imagefill($img, 0, 0, $blanco);
    imagesetthickness($img, 2);
    $y = 70;
    for ($x = 10; $x < 350; $x += 4) {
        $y2 = 70 + (int) (40 * sin($x / 18));
        imageline($img, $x, $y, $x + 4, $y2, $tinta);
        $y = $y2;
    }
    ob_start();
    imagepng($img);
    $binario = (string) ob_get_clean();
    imagedestroy($img);

    return 'data:image/png;base64,'.base64_encode($binario);
}

/**
 * CURP estructuralmente válida (mismo patrón que
 * `GuardarColaboradorRequest::REGEX_CURP`) y única para pruebas: cada llamada
 * genera una distinta, para no chocar con el nuevo constraint único de BD.
 */
function curpDeQaValida(): string
{
    return fake()->unique()->regexify(
        '[A-Z][AEIOU][A-Z]{2}[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|1[0-9]|2[0-8])'
        .'[HM](DF|NL|JC|MC|BC|GT|VZ|SL)[BCDFGHJKLMNPQRSTVWXYZ]{3}[A-Z0-9][0-9]',
    );
}

/**
 * RFC (persona moral, 12 caracteres) estructuralmente válido y único para
 * pruebas, mismo patrón que `GuardarEmpresaRequest`.
 */
function rfcDeQaValido(): string
{
    return Str::upper(fake()->unique()->bothify('???######???'));
}

/**
 * Encabezados fijos de las 10 hojas del importador de base de datos maestra
 * (`App\Servicios\ServicioImportacionMaestra::ENCABEZADOS`, duplicado aquí a
 * propósito: un test que rompiera si alguien cambia el formato real es
 * justamente la señal que queremos).
 *
 * @return array<string, list<string>>
 */
function encabezadosImportacionMaestra(): array
{
    return [
        'EMPRESAS' => ['nombre_comercial', 'razon_social', 'rfc', 'telefono', 'correo', 'direccion', 'activa'],
        'SUCURSALES' => ['empresa', 'nombre', 'direccion', 'telefono', 'activa'],
        'CONTRATOS' => ['empresa', 'nombre', 'descripcion', 'fecha_inicio', 'fecha_fin', 'activo'],
        'SERVICIOS' => ['empresa', 'contrato', 'sucursal', 'nombre', 'direccion', 'activo'],
        'COLABORADORES' => ['empresa', 'sucursal', 'nombre_completo', 'curp', 'puesto', 'area', 'servicio', 'correo', 'activo'],
        'TALLAS' => ['valor'],
        'ACTIVOS' => ['empresa', 'nombre', 'categoria', 'tipo_control', 'activo'],
        'ACTIVO_TALLA' => ['empresa', 'activo', 'talla'],
        'ALMACENES' => ['nombre', 'direccion', 'telefono', 'correo', 'empresas_abastecidas', 'activo'],
        'UNIDADES_ACTIVO' => [
            'empresa', 'activo', 'almacen', 'estado', 'condicion', 'colaborador', 'colaborador_curp',
            'marca', 'modelo', 'imei', 'numero_telefonico', 'operador', 'plan', 'observaciones',
        ],
    ];
}

/**
 * Construye un .xlsx real con las 10 hojas fijas del importador de base de
 * datos maestra, en el orden exacto que el servicio espera. Sólo hace falta
 * pasar las hojas que le interesen a la prueba: las demás quedan vacías (sin
 * filas — válido, el servicio no exige que toda hoja tenga datos), y cada
 * fila puede traer sólo las columnas que le importen a la prueba (el resto
 * queda en blanco).
 *
 * @param  array<string, list<array<string, mixed>>>  $hojas  nombre de hoja => filas, cada fila como columna => valor
 */
function construirWorkbookMaestro(array $hojas): UploadedFile
{
    $encabezados = encabezadosImportacionMaestra();

    $spreadsheet = new Spreadsheet;
    $spreadsheet->removeSheetByIndex(0);

    foreach (array_keys($encabezados) as $nombreHoja) {
        $hoja = $spreadsheet->createSheet();
        $hoja->setTitle($nombreHoja);
        $hoja->fromArray($encabezados[$nombreHoja], null, 'A1');

        foreach (array_values($hojas[$nombreHoja] ?? []) as $indice => $fila) {
            $filaOrdenada = array_map(fn (string $columna) => $fila[$columna] ?? null, $encabezados[$nombreHoja]);
            $hoja->fromArray($filaOrdenada, null, 'A'.($indice + 2));
        }
    }

    $ruta = tempnam(sys_get_temp_dir(), 'maestro_').'.xlsx';
    (new Xlsx($spreadsheet))->save($ruta);

    return new UploadedFile($ruta, 'BD_MAESTRA_REAL_2026.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/**
 * Fixture completa de las 10 hojas del importador de base de datos maestra:
 * 2 empresas, 1 sucursal por empresa, 1 contrato, 1 servicio, 1 colaborador
 * (con la CURP devuelta en `curp`, para poder referenciarla en
 * `colaborador_curp` de UNIDADES_ACTIVO), 1 talla, 2 activos (uno por
 * cantidad y uno de seguimiento individual), un vínculo activo_talla, 1
 * almacén compartido por ambas empresas y 1 unidad identificada asignada al
 * colaborador. Ejercita las 10 hojas en un solo archivo.
 *
 * @return array{curp: string, hojas: array<string, list<array<string, mixed>>>}
 */
function workbookMaestroCompleto(): array
{
    $curp = curpDeQaValida();

    return [
        'curp' => $curp,
        'hojas' => [
            'EMPRESAS' => [
                ['nombre_comercial' => 'INMAG', 'rfc' => rfcDeQaValido(), 'activa' => true],
                ['nombre_comercial' => 'ESTRATEGIAS', 'rfc' => rfcDeQaValido(), 'activa' => true],
            ],
            'SUCURSALES' => [
                ['empresa' => 'INMAG', 'nombre' => 'MEX', 'activa' => true],
                ['empresa' => 'ESTRATEGIAS', 'nombre' => 'ZACATECAS', 'activa' => true],
            ],
            'CONTRATOS' => [
                ['empresa' => 'INMAG', 'nombre' => 'AUTOMOTORES PEDREGAL', 'activo' => true],
            ],
            'SERVICIOS' => [
                ['empresa' => 'INMAG', 'contrato' => 'AUTOMOTORES PEDREGAL', 'sucursal' => 'MEX', 'nombre' => 'SONIC PEDREGAL', 'activo' => true],
            ],
            'COLABORADORES' => [
                ['empresa' => 'INMAG', 'sucursal' => 'MEX', 'nombre_completo' => 'Juan Perez Lopez', 'curp' => $curp, 'servicio' => 'SONIC PEDREGAL', 'area' => 'Operaciones', 'activo' => true],
            ],
            'TALLAS' => [
                ['valor' => 'Unica'],
            ],
            'ACTIVOS' => [
                ['empresa' => 'INMAG', 'nombre' => 'Camisola', 'categoria' => 'Prenda', 'tipo_control' => 'cantidad', 'activo' => true],
                ['empresa' => 'INMAG', 'nombre' => 'Radio', 'categoria' => 'Dispositivo', 'tipo_control' => 'individual', 'activo' => true],
            ],
            'ACTIVO_TALLA' => [
                ['empresa' => 'INMAG', 'activo' => 'Camisola', 'talla' => 'Unica'],
            ],
            'ALMACENES' => [
                ['nombre' => 'Almacen Central', 'empresas_abastecidas' => 'INMAG;ESTRATEGIAS', 'activo' => true],
            ],
            'UNIDADES_ACTIVO' => [
                ['empresa' => 'INMAG', 'activo' => 'Radio', 'almacen' => 'Almacen Central', 'colaborador_curp' => $curp, 'marca' => 'Motorola', 'imei' => '123456789012345'],
            ],
        ],
    ];
}

/**
 * Envía el archivo a `/datos/importar-maestro/prevalidar` como el usuario
 * dado y devuelve el prop `analisis` de la respuesta Inertia (mismo patrón
 * de captura que `DashboardKpiCoherenciaTest::kpisDelDashboard()`).
 *
 * @param  array<string, list<array<string, mixed>>>  $hojas
 * @return array<string, mixed>
 */
function prevalidarWorkbookMaestro(TestCase $test, User $usuario, array $hojas): array
{
    $archivo = construirWorkbookMaestro($hojas);

    $analisis = null;
    $test->actingAs($usuario)
        ->post('/datos/importar-maestro/prevalidar', ['archivo' => $archivo])
        ->assertOk()
        ->assertInertia(function ($page) use (&$analisis): void {
            $analisis = $page->toArray()['props']['analisis'];
        });

    return $analisis;
}

/**
 * Encabezados de la plantilla del importador de colaboradores
 * (`App\Servicios\ServicioImportacionColaboradores::COLUMNAS`), duplicado
 * aquí a propósito: si alguien cambia el formato real sin actualizar esto,
 * el test debe romperse.
 *
 * @return list<string>
 */
function encabezadosImportacionColaboradores(): array
{
    return ['nombre_completo', 'curp', 'puesto', 'area', 'correo', 'sucursal_codigo'];
}

/**
 * Construye un .xlsx real de una sola hoja para el importador de
 * colaboradores. `$encabezados` es opcional (por defecto, la plantilla
 * actual) para poder simular encabezados incompletos/viejos (p. ej. con
 * `numero_empleado` todavía presente) en las pruebas de columnas.
 *
 * @param  list<array<string, mixed>>  $filas  cada fila como columna => valor
 * @param  list<string>|null  $encabezados
 */
function construirExcelColaboradores(array $filas, ?array $encabezados = null): UploadedFile
{
    $encabezados ??= encabezadosImportacionColaboradores();

    $spreadsheet = new Spreadsheet;
    $hoja = $spreadsheet->getActiveSheet();
    $hoja->fromArray($encabezados, null, 'A1');

    foreach (array_values($filas) as $indice => $fila) {
        $filaOrdenada = array_map(fn (string $columna) => $fila[$columna] ?? null, $encabezados);
        $hoja->fromArray($filaOrdenada, null, 'A'.($indice + 2));
    }

    $ruta = tempnam(sys_get_temp_dir(), 'colaboradores_').'.xlsx';
    (new Xlsx($spreadsheet))->save($ruta);

    return new UploadedFile($ruta, 'colaboradores.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/**
 * Envía el archivo a `/colaboradores/importar/analizar` como el usuario dado
 * y devuelve el prop `analisis` de la respuesta Inertia (mismo patrón que
 * `prevalidarWorkbookMaestro`).
 *
 * @return array<string, mixed>
 */
function analizarImportacionColaboradores(TestCase $test, User $usuario, Empresa $empresa, UploadedFile $archivo): array
{
    $analisis = null;
    $test->actingAs($usuario)
        ->post('/colaboradores/importar/analizar', ['empresa_id' => $empresa->id, 'archivo' => $archivo])
        ->assertOk()
        ->assertInertia(function ($page) use (&$analisis): void {
            $analisis = $page->toArray()['props']['analisis'];
        });

    return $analisis;
}

/**
 * Sube un documento de identidad (categoría `Identificacion`) al expediente
 * del colaborador, como administrador. Devuelve el `DocumentoExpediente`.
 * Compartido entre las pruebas de identidad de Entregas y Devoluciones
 * (`App\Servicios\ServicioIdentidadColaborador`): ambos flujos consultan el
 * mismo slot del expediente y necesitan poder dejarlo prellenado por igual.
 */
function subirIdentificacion(TestCase $test, User $admin, int $colaboradorId, string $archivo = 'ine.jpg'): DocumentoExpediente
{
    $upload = str_ends_with($archivo, '.pdf')
        ? UploadedFile::fake()->create($archivo, 120, 'application/pdf')
        : UploadedFile::fake()->image($archivo);

    $test->actingAs($admin)->post("/colaboradores/{$colaboradorId}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Identificacion->value,
        'nombre' => 'INE',
        'archivo' => $upload,
    ])->assertRedirect();

    return DocumentoExpediente::query()->where('colaborador_id', $colaboradorId)->latest('id')->firstOrFail();
}
