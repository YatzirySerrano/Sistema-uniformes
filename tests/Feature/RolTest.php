<?php

use App\Enums\RolSistema;
use App\Exports\ListadoExport;
use App\Soporte\ContextoExportacion;
use App\Soporte\Permisos;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    sembrarRolesPermisos();
});

it('un rol base del sistema no puede eliminarse', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    $rolBase = Role::where('name', RolSistema::Encargado->value)->firstOrFail();

    $this->actingAs($admin)
        ->delete("/roles/{$rolBase->id}")
        ->assertForbidden();

    expect(Role::query()->find($rolBase->id))->not->toBeNull();
});

it('un rol con usuarios asignados no puede eliminarse', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    $rol = Role::create(['name' => 'rol_con_usuarios', 'guard_name' => 'web']);
    usuarioCon('rol_con_usuarios');

    $this->actingAs($admin)
        ->delete("/roles/{$rol->id}")
        ->assertStatus(422);

    expect(Role::query()->find($rol->id))->not->toBeNull();
});

it('un rol personalizado sin usuarios sí puede eliminarse', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    $rol = Role::create(['name' => 'rol_temporal', 'guard_name' => 'web']);

    $this->actingAs($admin)
        ->delete("/roles/{$rol->id}")
        ->assertRedirect();

    expect(Role::query()->find($rol->id))->toBeNull();
});

it('el rol Superadministrador no puede modificarse', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    $superadmin = Role::where('name', RolSistema::Superadministrador->value)->firstOrFail();

    $this->actingAs($admin)
        ->put("/roles/{$superadmin->id}", [
            'name' => RolSistema::Superadministrador->value,
            'permisos' => [],
        ])
        ->assertForbidden();
});

it('un rol base no puede renombrarse pero sí puede cambiar sus permisos', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    $rolBase = Role::where('name', RolSistema::Encargado->value)->firstOrFail();

    $this->actingAs($admin)
        ->put("/roles/{$rolBase->id}", [
            'name' => 'nombre_nuevo',
            'permisos' => ['activos.ver'],
        ])
        ->assertRedirect();

    $rolBase->refresh();

    expect($rolBase->name)->toBe(RolSistema::Encargado->value)
        ->and($rolBase->permissions->pluck('name')->all())->toBe(['activos.ver']);
});

it('crea un rol personalizado con los permisos seleccionados', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post('/roles', [
            'name' => 'auxiliar_reportes',
            'permisos' => ['reportes.ver', 'auditoria.ver'],
        ])
        ->assertRedirect();

    $rol = Role::where('name', 'auxiliar_reportes')->firstOrFail();
    expect($rol->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['auditoria.ver', 'reportes.ver']);
});

it('rechaza permisos que no existen en el catálogo', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post('/roles', [
            'name' => 'rol_invalido',
            'permisos' => ['no.existe'],
        ])
        ->assertSessionHasErrors('permisos.0');

    expect(Role::where('name', 'rol_invalido')->exists())->toBeFalse();
});

it('un usuario sin permiso roles.ver no puede acceder al listado', function () {
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value);

    $this->actingAs($sinPermiso)
        ->get('/roles')
        ->assertForbidden();
});

it('el listado de roles incluye los grupos de permisos del catálogo', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/roles')
        ->assertInertia(fn ($page) => $page
            ->component('Roles/Index')
            ->where('gruposPermisos', Permisos::GRUPOS)
        );
});

it('busca roles por nombre y por permiso asociado', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    Role::create(['name' => 'auditor_externo', 'guard_name' => 'web'])->syncPermissions(['auditoria.ver']);
    Role::create(['name' => 'gestor_activos', 'guard_name' => 'web'])->syncPermissions(['activos.ver']);

    $porNombre = collect($this->actingAs($admin)->get('/roles?buscar=auditor')
        ->viewData('page')['props']['roles'])->pluck('name');
    expect($porNombre)->toContain('auditor_externo')->not->toContain('gestor_activos');

    $porPermiso = collect($this->actingAs($admin)->get('/roles?buscar=activos.ver')
        ->viewData('page')['props']['roles'])->pluck('name');
    expect($porPermiso)->toContain('gestor_activos')->not->toContain('auditor_externo');
});

it('filtra roles por tipo base o personalizado', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    Role::create(['name' => 'rol_a_medida', 'guard_name' => 'web']);

    $base = collect($this->actingAs($admin)->get('/roles?tipo=base')
        ->viewData('page')['props']['roles'])->pluck('name');
    expect($base)->toContain(RolSistema::Encargado->value)->not->toContain('rol_a_medida');

    $personalizados = collect($this->actingAs($admin)->get('/roles?tipo=personalizados')
        ->viewData('page')['props']['roles'])->pluck('name');
    expect($personalizados)->toContain('rol_a_medida')->not->toContain(RolSistema::Encargado->value);
});

it('exporta roles a Excel respetando los filtros y sin claves técnicas de permiso', function () {
    Excel::fake();
    $admin = usuarioCon(RolSistema::Administrador->value);
    Role::create(['name' => 'reporteria', 'guard_name' => 'web'])->syncPermissions(['reportes.ver']);

    $this->actingAs($admin)->get('/roles/exportar?tipo=personalizados')->assertOk();

    Excel::assertDownloaded('roles-y-permisos-todas-las-empresas-'.now()->toDateString().'.xlsx', function (ListadoExport $export): bool {
        $filas = $export->array();
        $nombres = collect($filas)->pluck(0);

        expect($nombres)->toContain('Reporteria')
            ->and($nombres)->not->toContain('Encargado'); // rol base, filtrado

        $texto = collect($filas)->flatmap(fn (array $f): array => $f)->implode(' | ');
        expect($texto)->not->toContain('reportes.ver'); // etiquetas legibles, no claves

        return true;
    });
});

it('exporta roles a PDF', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);

    $respuesta = $this->actingAs($admin)->get('/roles/exportar?formato=pdf')->assertOk();

    expect($respuesta->getContent())->toStartWith('%PDF-');
});

it('la plantilla PDF de roles agrupa los permisos por categoría en listas, no como un bloque de texto corrido', function () {
    // Prueba la PLANTILLA en aislamiento con datos ya agrupados (misma forma
    // que produce `RolController::rolParaPdf()`): así se cubre exactamente
    // el bug reportado — "los permisos aparecen en una sola línea muy
    // larga" — sin depender de poder inspeccionar el binario del PDF.
    $html = view('reportes.roles-permisos', [
        'contexto' => new ContextoExportacion('Roles y permisos', null, [], 1),
        'roles' => [[
            'etiqueta' => 'Multi Permiso',
            'base' => false,
            'usuarios' => 3,
            'total_permisos' => 4,
            'grupos' => collect([
                'Reportes' => ['Ver reportes', 'Exportar reportes'],
                'Empresas' => ['Ver empresas', 'Editar empresas'],
            ]),
        ]],
    ])->render();

    // Cada categoría es su propio título + <ul>, no un único ítem
    // "Reportes: Ver, Exportar · Empresas: Ver, Editar" en una sola celda.
    expect(substr_count($html, '<ul class="categoria-lista">'))->toBe(2)
        ->and($html)->toContain('Reportes')
        ->and($html)->toContain('Empresas')
        ->and($html)->toContain('Ver reportes')
        ->and($html)->toContain('Editar empresas')
        ->and($html)->not->toContain('Reportes: Ver reportes, Exportar reportes · Empresas');
});

it('el endpoint de exportación de roles exige el permiso roles.ver', function () {
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value);

    $this->actingAs($sinPermiso)->get('/roles/exportar')->assertForbidden();
});
