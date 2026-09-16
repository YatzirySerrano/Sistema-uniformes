<?php

use App\Enums\RolSistema;
use App\Models\Colaborador;
use Illuminate\Support\Str;

/**
 * CURP obligatoria y única en Colaboradores (ronda 2026-09-15): identidad de
 * la persona, distinta de `numero_empleado` (identidad laboral/operativa,
 * ver [[NumeroEmpleadoTest]]). Se guarda siempre en mayúsculas, sin espacios,
 * y su unicidad es GLOBAL (no por empresa) — se mantiene reservada aunque el
 * colaborador quede soft-deleted.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
});

it('rechaza el alta de un colaborador sin CURP', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $this->actingAs($admin)->post('/colaboradores', [
        'empresa_id' => $this->datos['empresaA']->id,
        'nombre_completo' => 'Sin Curp',
        'sucursal_id' => $this->datos['sucursalA']->id,
    ])->assertSessionHasErrors('curp');

    expect(Colaborador::query()->where('nombre_completo', 'Sin Curp')->exists())->toBeFalse();
});

it('crea el colaborador cuando la CURP es válida', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $curp = curpDeQaValida();

    $this->actingAs($admin)->post('/colaboradores', [
        'empresa_id' => $this->datos['empresaA']->id,
        'nombre_completo' => 'Con Curp Valida',
        'curp' => $curp,
        'sucursal_id' => $this->datos['sucursalA']->id,
    ])->assertSessionHasNoErrors();

    expect(Colaborador::query()->where('nombre_completo', 'Con Curp Valida')->value('curp'))->toBe($curp);
});

it('normaliza la CURP a mayúsculas', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $curp = curpDeQaValida();

    $this->actingAs($admin)->post('/colaboradores', [
        'empresa_id' => $this->datos['empresaA']->id,
        'nombre_completo' => 'Curp Minuscula',
        'curp' => Str::lower($curp),
        'sucursal_id' => $this->datos['sucursalA']->id,
    ])->assertSessionHasNoErrors();

    expect(Colaborador::query()->where('nombre_completo', 'Curp Minuscula')->value('curp'))->toBe($curp);
});

it('recorta espacios alrededor de la CURP antes de validar', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $curp = curpDeQaValida();

    $this->actingAs($admin)->post('/colaboradores', [
        'empresa_id' => $this->datos['empresaA']->id,
        'nombre_completo' => 'Curp Con Espacios',
        'curp' => "  {$curp}  ",
        'sucursal_id' => $this->datos['sucursalA']->id,
    ])->assertSessionHasNoErrors();

    expect(Colaborador::query()->where('nombre_completo', 'Curp Con Espacios')->value('curp'))->toBe($curp);
});

it('rechaza una CURP con longitud distinta de 18', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $this->actingAs($admin)->post('/colaboradores', [
        'empresa_id' => $this->datos['empresaA']->id,
        'nombre_completo' => 'Curp Corta',
        'curp' => 'PELJ850101HDFRZN0', // 17 caracteres
        'sucursal_id' => $this->datos['sucursalA']->id,
    ])->assertSessionHasErrors('curp');

    expect(Colaborador::query()->where('nombre_completo', 'Curp Corta')->exists())->toBeFalse();
});

it('rechaza una CURP con formato claramente inválido', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $this->actingAs($admin)->post('/colaboradores', [
        'empresa_id' => $this->datos['empresaA']->id,
        'nombre_completo' => 'Curp Invalida',
        'curp' => '123456789012345678', // 18 caracteres, pero sin estructura de CURP
        'sucursal_id' => $this->datos['sucursalA']->id,
    ])->assertSessionHasErrors('curp');

    expect(Colaborador::query()->where('nombre_completo', 'Curp Invalida')->exists())->toBeFalse();
});

it('rechaza una CURP ya usada por otro colaborador', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $curpExistente = Colaborador::factory()
        ->for($this->datos['empresaA'])->for($this->datos['sucursalA'])
        ->create()->curp;

    $this->actingAs($admin)->post('/colaboradores', [
        'empresa_id' => $this->datos['empresaA']->id,
        'nombre_completo' => 'Curp Duplicada',
        'curp' => $curpExistente,
        'sucursal_id' => $this->datos['sucursalA']->id,
    ])->assertSessionHasErrors('curp');

    expect(Colaborador::query()->where('nombre_completo', 'Curp Duplicada')->exists())->toBeFalse();
});

it('editar un colaborador conservando su propia CURP no genera error de duplicado', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $colaborador = Colaborador::factory()
        ->for($this->datos['empresaA'])->for($this->datos['sucursalA'])
        ->create();

    $this->actingAs($admin)->put("/colaboradores/{$colaborador->id}", [
        'nombre_completo' => 'Nombre Actualizado',
        'curp' => $colaborador->curp,
        'sucursal_id' => $this->datos['sucursalA']->id,
    ])->assertSessionHasNoErrors();

    expect($colaborador->fresh()->curp)->toBe($colaborador->curp);
});

it('editar un colaborador no permite tomar la CURP de otro', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $otro = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();
    $colaborador = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();
    $curpOriginal = $colaborador->curp;

    $this->actingAs($admin)->put("/colaboradores/{$colaborador->id}", [
        'nombre_completo' => $colaborador->nombre_completo,
        'curp' => $otro->curp,
        'sucursal_id' => $this->datos['sucursalA']->id,
    ])->assertSessionHasErrors('curp');

    expect($colaborador->fresh()->curp)->toBe($curpOriginal);
});

it('un colaborador soft-deleted mantiene su CURP reservada: nadie más puede usarla', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $eliminado = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();
    $curpReservada = $eliminado->curp;
    $eliminado->delete();

    expect($eliminado->trashed())->toBeTrue();

    $this->actingAs($admin)->post('/colaboradores', [
        'empresa_id' => $this->datos['empresaA']->id,
        'nombre_completo' => 'Intento Reusar Curp',
        'curp' => $curpReservada,
        'sucursal_id' => $this->datos['sucursalA']->id,
    ])->assertSessionHasErrors('curp');

    expect(Colaborador::query()->where('nombre_completo', 'Intento Reusar Curp')->exists())->toBeFalse();
});

it('busca colaboradores por CURP en el listado', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $encontrado = Colaborador::factory()
        ->for($this->datos['empresaA'])->for($this->datos['sucursalA'])
        ->create(['nombre_completo' => 'Persona Buscada']);
    Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();

    $this->actingAs($admin)
        ->get('/colaboradores?buscar='.$encontrado->curp)
        ->assertInertia(fn ($page) => $page
            ->where('colaboradores.total', 1)
            ->where('colaboradores.data.0.id', $encontrado->id)
        );
});

it('agregar CURP no afecta la generación del número de empleado', function () {
    $admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $this->actingAs($admin)->post('/colaboradores', [
        'empresa_id' => $this->datos['empresaA']->id,
        'nombre_completo' => 'Numero Empleado Ok',
        'curp' => curpDeQaValida(),
        'sucursal_id' => $this->datos['sucursalA']->id,
    ])->assertSessionHasNoErrors();

    $colaborador = Colaborador::query()->where('nombre_completo', 'Numero Empleado Ok')->firstOrFail();
    expect($colaborador->numero_empleado)->not->toBeEmpty();
});

it('sin permiso de crear colaboradores, el alta se rechaza aunque la CURP sea válida', function () {
    // Encargado tiene `colaboradores.ver` pero NO `colaboradores.crear`.
    $encargado = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaA']]);

    $this->actingAs($encargado)->post('/colaboradores', [
        'empresa_id' => $this->datos['empresaA']->id,
        'nombre_completo' => 'Sin Permiso',
        'curp' => curpDeQaValida(),
        'sucursal_id' => $this->datos['sucursalA']->id,
    ])->assertForbidden();

    expect(Colaborador::query()->where('nombre_completo', 'Sin Permiso')->exists())->toBeFalse();
});
