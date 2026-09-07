<?php

use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\SecuenciaCodigo;
use App\Models\Sucursal;
use App\Soporte\GeneradorNumeroEmpleado;

beforeEach(function () {
    sembrarRolesPermisos();
});

/**
 * Crea una sucursal y devuelve los datos mínimos para dar de alta un
 * colaborador vía POST, con el nombre indicado.
 */
function datosColaborador(Empresa $empresa, Sucursal $sucursal, string $nombre): array
{
    return [
        'empresa_id' => $empresa->id,
        'nombre_completo' => $nombre,
        'sucursal_id' => $sucursal->id,
        // Intento de manipulación: el backend debe ignorarlo siempre.
        'numero_empleado' => 'TEST123',
    ];
}

/*
|--------------------------------------------------------------------------
| El backend es la única fuente del número de empleado
|--------------------------------------------------------------------------
*/

it('genera el número de empleado en el backend e ignora cualquier valor manipulado por el cliente', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post('/colaboradores', datosColaborador($empresa, $sucursal, 'Yatziry Serrano'))
        ->assertRedirect('/colaboradores')
        ->assertSessionHasNoErrors();

    $colaborador = Colaborador::query()->where('nombre_completo', 'Yatziry Serrano')->firstOrFail();

    expect($colaborador->numero_empleado)->toBe('YS0001');
    expect($colaborador->numero_empleado)->not->toBe('TEST123');
});

it('la edición nunca cambia el número de empleado aunque el cliente mande uno distinto', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create(['numero_empleado' => 'YS0007']);
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->put("/colaboradores/{$colaborador->id}", [
            'nombre_completo' => 'Nombre Editado',
            'sucursal_id' => $sucursal->id,
            'numero_empleado' => 'OTRO-999',
        ])
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->numero_empleado)->toBe('YS0007');
});

/*
|--------------------------------------------------------------------------
| Iniciales: primera + última componente del nombre, ASCII, con fallback
|--------------------------------------------------------------------------
*/

it('usa la primera letra del primer nombre y la primera del último componente', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->post('/colaboradores', datosColaborador($empresa, $sucursal, 'Juan Pérez'));
    $this->actingAs($admin)->post('/colaboradores', datosColaborador($empresa, $sucursal, 'María Fernanda López Hernández'));

    expect(Colaborador::query()->where('nombre_completo', 'Juan Pérez')->value('numero_empleado'))->toBe('JP0001');
    expect(Colaborador::query()->where('nombre_completo', 'María Fernanda López Hernández')->value('numero_empleado'))->toBe('MH0002');
});

it('normaliza acentos y eñes a ASCII para las iniciales', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->post('/colaboradores', datosColaborador($empresa, $sucursal, 'Ángel Núñez'));

    expect(Colaborador::query()->where('nombre_completo', 'Ángel Núñez')->value('numero_empleado'))->toBe('AN0001');
});

it('un nombre de una sola palabra usa únicamente esa inicial, sin inventar una segunda letra', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->post('/colaboradores', datosColaborador($empresa, $sucursal, 'Carlos'));

    expect(Colaborador::query()->where('nombre_completo', 'Carlos')->value('numero_empleado'))->toBe('C0001');
});

/*
|--------------------------------------------------------------------------
| Consecutivo: por empresa, 4 dígitos mínimo, sin rollover al pasar 9999
|--------------------------------------------------------------------------
*/

it('el consecutivo es compartido por empresa sin importar las iniciales de cada persona', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->post('/colaboradores', datosColaborador($empresa, $sucursal, 'Yatziry Serrano'));
    $this->actingAs($admin)->post('/colaboradores', datosColaborador($empresa, $sucursal, 'Juan Pérez'));
    $this->actingAs($admin)->post('/colaboradores', datosColaborador($empresa, $sucursal, 'Ana Ruiz'));

    expect(Colaborador::query()->where('nombre_completo', 'Yatziry Serrano')->value('numero_empleado'))->toBe('YS0001');
    expect(Colaborador::query()->where('nombre_completo', 'Juan Pérez')->value('numero_empleado'))->toBe('JP0002');
    expect(Colaborador::query()->where('nombre_completo', 'Ana Ruiz')->value('numero_empleado'))->toBe('AR0003');
});

it('el consecutivo no hace rollover al superar 9999: sigue creciendo a 5 dígitos', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    SecuenciaCodigo::query()->create(['empresa_id' => $empresa->id, 'ambito' => 'colaborador', 'ultimo_valor' => 9998]);

    $this->actingAs($admin)->post('/colaboradores', datosColaborador($empresa, $sucursal, 'Beta Uno'));
    $this->actingAs($admin)->post('/colaboradores', datosColaborador($empresa, $sucursal, 'Gama Dos'));

    expect(Colaborador::query()->where('nombre_completo', 'Beta Uno')->value('numero_empleado'))->toBe('BU9999');
    expect(Colaborador::query()->where('nombre_completo', 'Gama Dos')->value('numero_empleado'))->toBe('GD10000');
});

it('empresas distintas tienen secuencias de consecutivo independientes', function () {
    $empresaA = Empresa::factory()->create();
    $sucursalA = Sucursal::factory()->for($empresaA)->create();
    $empresaB = Empresa::factory()->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->post('/colaboradores', datosColaborador($empresaA, $sucursalA, 'Uno De A'));
    $this->actingAs($admin)->post('/colaboradores', datosColaborador($empresaB, $sucursalB, 'Uno De B'));

    expect(Colaborador::query()->where('nombre_completo', 'Uno De A')->value('numero_empleado'))->toBe('UA0001');
    expect(Colaborador::query()->where('nombre_completo', 'Uno De B')->value('numero_empleado'))->toBe('UB0001');
});

/*
|--------------------------------------------------------------------------
| Inicialización desde históricos: no reutiliza seriales ya usados
|--------------------------------------------------------------------------
*/

it('al inicializar la secuencia analiza los históricos y arranca por encima del mayor sufijo numérico', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    // Históricos con formato distinto, nunca tocados.
    Colaborador::factory()->for($empresa)->for($sucursal)->create(['numero_empleado' => 'J4659']);
    Colaborador::factory()->for($empresa)->for($sucursal)->create(['numero_empleado' => 'J4790']);
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->post('/colaboradores', datosColaborador($empresa, $sucursal, 'Nuevo Ingreso'));

    expect(Colaborador::query()->where('numero_empleado', 'J4659')->exists())->toBeTrue();
    expect(Colaborador::query()->where('numero_empleado', 'J4790')->exists())->toBeTrue();
    expect(Colaborador::query()->where('nombre_completo', 'Nuevo Ingreso')->value('numero_empleado'))->toBe('NI4791');
});

/*
|--------------------------------------------------------------------------
| Concurrencia / unicidad
|--------------------------------------------------------------------------
*/

it('dos altas seguidas para la misma empresa nunca obtienen el mismo consecutivo', function () {
    $empresa = Empresa::factory()->create();
    $generador = app(GeneradorNumeroEmpleado::class);

    $primero = $generador->generar($empresa, 'Persona Uno');
    $segundo = $generador->generar($empresa, 'Persona Dos');

    expect($primero)->not->toBe($segundo);
});

/*
|--------------------------------------------------------------------------
| Previsualización: no autoritativa, no reserva el consecutivo
|--------------------------------------------------------------------------
*/

it('la previsualización del número de empleado no reserva el consecutivo', function () {
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $primera = $this->actingAs($admin)
        ->get("/colaboradores/siguiente-numero?empresa_id={$empresa->id}&nombre_completo=".urlencode('Yatziry Serrano'))
        ->assertOk()
        ->json('numero_empleado');

    $segunda = $this->actingAs($admin)
        ->get("/colaboradores/siguiente-numero?empresa_id={$empresa->id}&nombre_completo=".urlencode('Yatziry Serrano'))
        ->assertOk()
        ->json('numero_empleado');

    expect($primera)->toBe('YS0001');
    expect($segunda)->toBe('YS0001');
    expect(SecuenciaCodigo::query()->where('empresa_id', $empresa->id)->where('ambito', 'colaborador')->exists())->toBeFalse();
});

it('la previsualización sin empresa o sin nombre no revienta y responde null', function () {
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/colaboradores/siguiente-numero')
        ->assertOk()
        ->assertJson(['numero_empleado' => null]);

    $this->actingAs($admin)
        ->get("/colaboradores/siguiente-numero?empresa_id={$empresa->id}")
        ->assertOk()
        ->assertJson(['numero_empleado' => null]);
});

it('la previsualización ignora una empresa fuera del alcance del usuario', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);

    $this->actingAs($supervisor)
        ->get("/colaboradores/siguiente-numero?empresa_id={$ajena->id}&nombre_completo=Alguien")
        ->assertOk()
        ->assertJson(['numero_empleado' => null]);
});

it('el toast de alta confirma el número de empleado definitivamente asignado', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $respuesta = $this->actingAs($admin)
        ->post('/colaboradores', datosColaborador($empresa, $sucursal, 'Confirmo Toast'));

    $colaborador = Colaborador::query()->where('nombre_completo', 'Confirmo Toast')->firstOrFail();

    $respuesta->assertSessionHas('toast', fn ($toast) => str_contains($toast['message'], $colaborador->numero_empleado));
});
