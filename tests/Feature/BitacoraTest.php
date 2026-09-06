<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Area;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\UnidadActivo;

it('transforma el before/after de una edición a "Campo | Antes | Ahora" legible y oculta las claves *_id', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create([
        'nombre_completo' => 'Juan Pérez',
        'puesto' => 'Auxiliar',
        'numero_empleado' => 'EMP-001',
    ]);

    $this->actingAs($admin)
        ->put("/colaboradores/{$colaborador->id}", [
            'numero_empleado' => 'EMP-001',
            'nombre_completo' => 'Juan Pérez',
            'sucursal_id' => $sucursal->id,
            'puesto' => 'Supervisor',
            'activo' => true,
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->get('/auditoria')
        ->assertInertia(fn ($page) => $page
            ->component('Auditoria/Index')
            ->where('registros.data.0.modulo', 'colaboradores')
            ->where('registros.data.0.accion', 'editar')
            ->where('registros.data.0.cambios', fn ($cambios) => collect($cambios)->contains(
                fn ($c) => $c['campo'] === 'Puesto' && $c['antes'] === 'Auxiliar' && $c['ahora'] === 'Supervisor',
            ))
            ->where('registros.data.0.cambios', fn ($cambios) => collect($cambios)
                ->pluck('campo')->doesntContain(fn ($campo) => str_contains(strtolower($campo), 'id')),
            ),
        );
});

it('un activar/desactivar queda con before/after "Estado" humano', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);
    $area = Area::factory()->for($empresa)->create(['activa' => true]);

    $this->actingAs($admin)->post("/areas/{$area->id}/estado")->assertRedirect();

    $this->actingAs($admin)
        ->get('/auditoria')
        ->assertInertia(fn ($page) => $page
            ->where('registros.data.0.accion', 'desactivar')
            ->where('registros.data.0.cambios', fn ($cambios) => collect($cambios)->contains(
                fn ($c) => $c['campo'] === 'Estado' && $c['antes'] === 'Activo' && $c['ahora'] === 'Inactivo',
            )),
        );
});

it('una incidencia de unidad queda con before/after de Condición usando las etiquetas del enum', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->seguimientoIndividual()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);
    $unidad = UnidadActivo::factory()->for($empresa)->for($activo)->for($almacen)->asignada()->create();

    $this->actingAs($admin)
        ->post("/activos/unidades/{$unidad->public_token}/incidencia", [
            'tipo' => 'perdido',
            'motivo' => 'No se localiza',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->get('/auditoria')
        ->assertInertia(fn ($page) => $page
            ->where('registros.data.0.accion', 'unidad_incidencia')
            ->where('registros.data.0.cambios', fn ($cambios) => collect($cambios)->contains(
                fn ($c) => $c['campo'] === 'Condición' && $c['antes'] === 'Funcionando' && $c['ahora'] === 'Perdido',
            )),
        );
});

it('un rol restringido no ve en la bitácora los eventos de una empresa fuera de su alcance', function () {
    sembrarRolesPermisos();
    $empresaPropia = Empresa::factory()->create();
    $empresaAjena = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresaPropia, $empresaAjena]);

    $areaPropia = Area::factory()->for($empresaPropia)->create(['activa' => true]);
    $areaAjena = Area::factory()->for($empresaAjena)->create(['activa' => true]);
    $this->actingAs($admin)->post("/areas/{$areaPropia->id}/estado");
    $this->actingAs($admin)->post("/areas/{$areaAjena->id}/estado");

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresaPropia]);
    $supervisor->givePermissionTo('auditoria.ver');

    $this->actingAs($supervisor)
        ->get('/auditoria')
        ->assertInertia(fn ($page) => $page
            ->has('registros.data', 1)
            ->where('registros.data.0.entidad', 'Area #'.$areaPropia->id),
        );
});

it('pagina la bitácora del lado del servidor respetando el tamaño de página configurado', function () {
    sembrarRolesPermisos();
    config(['uniformes.por_pagina' => 2]);
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    foreach (range(1, 3) as $i) {
        $area = Area::factory()->for($empresa)->create(['activa' => true]);
        $this->actingAs($admin)->post("/areas/{$area->id}/estado");
    }

    $this->actingAs($admin)
        ->get('/auditoria')
        ->assertInertia(fn ($page) => $page
            ->has('registros.data', 2)
            ->where('registros.total', 3),
        );

    $this->actingAs($admin)
        ->get('/auditoria?page=2')
        ->assertInertia(fn ($page) => $page->has('registros.data', 1));
});
