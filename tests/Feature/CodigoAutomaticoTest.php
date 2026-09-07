<?php

use App\Enums\RolSistema;
use App\Models\Almacen;
use App\Models\Area;
use App\Models\Empresa;
use App\Models\SecuenciaCodigo;
use App\Models\SecuenciaCodigoGlobal;
use App\Models\Sucursal;

beforeEach(function () {
    sembrarRolesPermisos();
});

/*
|--------------------------------------------------------------------------
| Bug crítico: secuencia atrasada nunca vuelve a emitir un código ya usado
|--------------------------------------------------------------------------
| Reproduce exactamente el reporte: ALM-0001/0002/0003 ya existen (p. ej.
| por un seeder que los insertó directo) pero el contador global quedó en 2.
| El siguiente alta debe reconciliar y emitir ALM-0004, nunca repetir
| ALM-0003 ni producir un 500 por "Duplicate entry".
*/

it('reconcilia una secuencia global atrasada: ALM-0003 existente + contador en 2 → siguiente ALM-0004', function () {
    Almacen::factory()->create(['codigo' => 'ALM-0001']);
    Almacen::factory()->create(['codigo' => 'ALM-0002']);
    Almacen::factory()->create(['codigo' => 'ALM-0003']);
    SecuenciaCodigoGlobal::query()->create(['ambito' => 'almacen', 'ultimo_valor' => 2]);

    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post('/almacenes', ['nombre' => 'Nuevo Almacén', 'empresa_ids' => [$empresa->id]])
        ->assertSessionHasNoErrors();

    $nuevo = Almacen::query()->where('nombre', 'Nuevo Almacén')->firstOrFail();
    expect($nuevo->codigo)->toBe('ALM-0004');
});

it('reconcilia una secuencia por empresa atrasada para Sucursal (SUC), Área (ARE) y Activo (ACT)', function () {
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    Sucursal::factory()->for($empresa)->create(['codigo' => 'SUC-0001']);
    Sucursal::factory()->for($empresa)->create(['codigo' => 'SUC-0002']);
    SecuenciaCodigo::query()->create(['empresa_id' => $empresa->id, 'ambito' => 'sucursal', 'ultimo_valor' => 1]);

    $this->actingAs($admin)
        ->post('/sucursales', ['nombre' => 'Nueva', 'empresa_id' => $empresa->id])
        ->assertSessionHasNoErrors();
    expect(Sucursal::query()->where('nombre', 'Nueva')->value('codigo'))->toBe('SUC-0003');

    Area::factory()->for($empresa)->create(['codigo' => 'ARE-0001']);
    SecuenciaCodigo::query()->create(['empresa_id' => $empresa->id, 'ambito' => 'area', 'ultimo_valor' => 0]);

    $this->actingAs($admin)
        ->post('/areas', ['nombre' => 'Nueva Área', 'empresa_id' => $empresa->id])
        ->assertSessionHasNoErrors();
    expect(Area::query()->where('nombre', 'Nueva Área')->value('codigo'))->toBe('ARE-0002');
});

it('la secuencia global de Almacén no revienta con un 500 aunque el contador nunca se haya inicializado', function () {
    Almacen::factory()->create(['codigo' => 'ALM-0001']);
    // Ninguna fila en secuencias_codigo_globales todavía para "almacen".

    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post('/almacenes', ['nombre' => 'Primero Con Reconciliación', 'empresa_ids' => [$empresa->id]])
        ->assertSessionHasNoErrors();

    expect(Almacen::query()->where('nombre', 'Primero Con Reconciliación')->value('codigo'))->toBe('ALM-0002');
});

/*
|--------------------------------------------------------------------------
| Dos altas consecutivas nunca colisionan (proxy de concurrencia)
|--------------------------------------------------------------------------
*/

it('dos altas de almacén consecutivas obtienen códigos distintos', function () {
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->post('/almacenes', ['nombre' => 'Uno', 'empresa_ids' => [$empresa->id]]);
    $this->actingAs($admin)->post('/almacenes', ['nombre' => 'Dos', 'empresa_ids' => [$empresa->id]]);

    $codigos = Almacen::query()->whereIn('nombre', ['Uno', 'Dos'])->pluck('codigo');
    expect($codigos->unique())->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Históricos jamás se tocan
|--------------------------------------------------------------------------
*/

it('los códigos históricos permanecen intactos tras reconciliar', function () {
    $historico = Almacen::factory()->create(['codigo' => 'ALM-0003']);
    SecuenciaCodigoGlobal::query()->create(['ambito' => 'almacen', 'ultimo_valor' => 2]);

    $empresa = Empresa::factory()->create();
    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/almacenes', ['nombre' => 'Nuevo', 'empresa_ids' => [$empresa->id]]);

    expect($historico->fresh()->codigo)->toBe('ALM-0003');
});

/*
|--------------------------------------------------------------------------
| Empresa: código autogenerado a partir del nombre, sin captura manual
|--------------------------------------------------------------------------
*/

it('el código de empresa lo genera el backend a partir del nombre comercial, ignorando un valor manipulado', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post('/empresas', ['nombre_comercial' => 'Distribuidora Central', 'codigo' => 'HACKEADO'])
        ->assertSessionHasNoErrors();

    $empresa = Empresa::query()->where('nombre_comercial', 'Distribuidora Central')->firstOrFail();
    expect($empresa->codigo)->not->toBe('HACKEADO');
    expect($empresa->codigo)->toMatch('/^DISTRI\d{2,}$/');
});

it('la edición de empresa nunca cambia el código ya asignado', function () {
    $empresa = Empresa::factory()->create();
    $codigoOriginal = $empresa->codigo;
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->put("/empresas/{$empresa->id}", ['nombre_comercial' => 'Nombre Nuevo', 'codigo' => 'OTRO-CODIGO'])
        ->assertSessionHasNoErrors();

    expect($empresa->fresh()->codigo)->toBe($codigoOriginal);
});

/*
|--------------------------------------------------------------------------
| Edición: el código es inmutable en todos los módulos autogenerados
|--------------------------------------------------------------------------
*/

it('editar Sucursal, Área o Activo nunca cambia su código autogenerado', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $area = Area::factory()->for($empresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $codigoSucursal = $sucursal->codigo;
    $this->actingAs($admin)->put("/sucursales/{$sucursal->id}", ['nombre' => 'Editada', 'codigo' => 'FALSO'])
        ->assertSessionHasNoErrors();
    expect($sucursal->fresh()->codigo)->toBe($codigoSucursal);

    $codigoArea = $area->codigo;
    $this->actingAs($admin)->put("/areas/{$area->id}", ['nombre' => 'Editada', 'codigo' => 'FALSO'])
        ->assertSessionHasNoErrors();
    expect($area->fresh()->codigo)->toBe($codigoArea);
});

/*
|--------------------------------------------------------------------------
| Previsualización: no reserva, respeta permisos y alcance
|--------------------------------------------------------------------------
*/

it('la previsualización de código de almacén no reserva el consecutivo', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);

    $primera = $this->actingAs($admin)->get('/almacenes/siguiente-codigo')->assertOk()->json('codigo');
    $segunda = $this->actingAs($admin)->get('/almacenes/siguiente-codigo')->assertOk()->json('codigo');

    expect($primera)->toBe($segunda);
    expect(SecuenciaCodigoGlobal::query()->where('ambito', 'almacen')->exists())->toBeFalse();
});

it('la previsualización de código de sucursal no reserva el consecutivo y respeta el alcance de empresa', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);
    $supervisor->givePermissionTo('sucursales.crear');

    $primera = $this->actingAs($supervisor)
        ->get("/sucursales/siguiente-codigo?empresa_id={$miEmpresa->id}")
        ->assertOk()->json('codigo');
    $segunda = $this->actingAs($supervisor)
        ->get("/sucursales/siguiente-codigo?empresa_id={$miEmpresa->id}")
        ->assertOk()->json('codigo');
    expect($primera)->toBe($segunda);
    expect(SecuenciaCodigo::query()->where('empresa_id', $miEmpresa->id)->where('ambito', 'sucursal')->exists())->toBeFalse();

    // Fuera de su alcance: 403, nunca filtra silenciosamente ni expone el código de otra empresa.
    $this->actingAs($supervisor)
        ->get("/sucursales/siguiente-codigo?empresa_id={$ajena->id}")
        ->assertForbidden();
});

it('un usuario sin permiso de crear el módulo no puede consultar su previsualización de código', function () {
    $empresa = Empresa::factory()->create();
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value, [$empresa]);

    $this->actingAs($sinPermiso)->get('/almacenes/siguiente-codigo')->assertForbidden();
    $this->actingAs($sinPermiso)->get("/sucursales/siguiente-codigo?empresa_id={$empresa->id}")->assertForbidden();
    $this->actingAs($sinPermiso)->get("/areas/siguiente-codigo?empresa_id={$empresa->id}")->assertForbidden();
    $this->actingAs($sinPermiso)->get("/activos/siguiente-codigo?empresa_id={$empresa->id}")->assertForbidden();
    $this->actingAs($sinPermiso)->get('/empresas/siguiente-codigo?nombre_comercial=Prueba')->assertForbidden();
});

it('el alta real de almacén puede diferir de la previsualización si otro usuario se adelantó', function () {
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $preview = $this->actingAs($admin)->get('/almacenes/siguiente-codigo')->assertOk()->json('codigo');
    expect($preview)->toBe('ALM-0001');

    // "Usuario B" crea un almacén antes de que A guarde.
    Almacen::factory()->create();

    $this->actingAs($admin)->post('/almacenes', ['nombre' => 'De Usuario A', 'empresa_ids' => [$empresa->id]]);

    $codigoReal = Almacen::query()->where('nombre', 'De Usuario A')->value('codigo');
    expect($codigoReal)->not->toBe($preview);
    expect($codigoReal)->toMatch('/^ALM-\d{4,}$/');
    expect(Almacen::query()->where('codigo', $codigoReal)->count())->toBe(1);
});
