<?php

use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * @return array{
 *     empresaA: Empresa,
 *     empresaB: Empresa,
 *     sucursalA: Sucursal,
 *     sucursalB: Sucursal,
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

    $tallaA = Talla::factory()->for($empresaA)->create(['valor' => 'M']);
    Talla::factory()->for($empresaB)->create(['valor' => 'M']);

    $activoA = Activo::factory()->for($empresaA)->create(['nombre' => 'Camisa']);
    $activoA->tallas()->attach($tallaA);

    $colaboradorA = Colaborador::factory()->for($empresaA)->for($sucursalA)->create();

    return compact('empresaA', 'empresaB', 'sucursalA', 'sucursalB', 'activoA', 'tallaA', 'colaboradorA');
}

/**
 * Crea un usuario con el rol indicado, asociado a las empresas dadas, y con la
 * empresa activa fijada en sesión.
 *
 * @param  array<int, Empresa>  $empresas
 */
function usuarioCon(string $rol, array $empresas = [], ?Empresa $empresaActiva = null): User
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
