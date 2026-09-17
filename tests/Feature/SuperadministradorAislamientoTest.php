<?php

use App\Enums\RolSistema;
use App\Exports\ListadoExport;
use App\Models\BitacoraAuditoria;
use App\Models\Empresa;
use App\Models\User;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;

/**
 * El Superadministrador es exclusivo del equipo técnico/proveedor; el
 * Administrador (usuario del cliente) no debe poder verlo ni saber que
 * existe: ni en listados, ni por URL manipulada, ni en Roles y permisos, ni
 * en Auditoría. El backend es la fuente de verdad — nunca un `v-if` en Vue.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
    $this->superadmin = usuarioCon(RolSistema::Superadministrador->value, [$this->empresa]);
});

it('1. un administrador no ve al superadministrador en el listado de Usuarios', function () {
    $respuesta = $this->actingAs($this->admin)->get('/usuarios');

    $ids = collect($respuesta->viewData('page')['props']['usuarios']['data'])->pluck('id');
    expect($ids)->not->toContain($this->superadmin->id);
});

it('1b. un administrador no ve al superadministrador en la exportación de Usuarios', function () {
    Excel::fake();

    $this->actingAs($this->admin)->get('/usuarios/exportar')->assertOk();

    Excel::assertDownloaded(
        'usuarios-todas-las-empresas-'.now()->toDateString().'.xlsx',
        function (ListadoExport $export): bool {
            $nombres = collect($export->array())->pluck(0);
            expect($nombres)->not->toContain($this->superadmin->name);

            return true;
        },
    );
});

it('2. un administrador no puede abrir por URL el detalle/edición de un superadministrador', function () {
    $this->actingAs($this->admin)
        ->get("/usuarios/{$this->superadmin->id}/editar")
        ->assertForbidden();
});

it('3. un administrador no puede editar ni modificar a un superadministrador aunque manipule el request', function () {
    $this->actingAs($this->admin)
        ->put("/usuarios/{$this->superadmin->id}", [
            'name' => 'Nombre Manipulado',
            'email' => $this->superadmin->email,
            'roles' => [RolSistema::Superadministrador->value],
            'empresas' => [$this->empresa->id],
        ])
        ->assertForbidden();

    expect($this->superadmin->fresh()->name)->not->toBe('Nombre Manipulado');

    $this->actingAs($this->admin)
        ->post("/usuarios/{$this->superadmin->id}/estado")
        ->assertForbidden();

    expect($this->superadmin->fresh()->activo)->toBeTrue();
});

it('4. un administrador no puede asignar el rol superadministrador a otro usuario, ni al crearlo ni al editarlo', function () {
    $this->actingAs($this->admin)->post('/usuarios', [
        'name' => 'Intento Elevacion',
        'email' => 'intento.elevacion@empresa.test',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'roles' => [RolSistema::Encargado->value, RolSistema::Superadministrador->value],
        'empresas' => [$this->empresa->id],
    ])->assertSessionHasNoErrors();

    $nuevo = User::query()->where('email', 'intento.elevacion@empresa.test')->firstOrFail();
    expect($nuevo->hasRole(RolSistema::Superadministrador->value))->toBeFalse()
        ->and($nuevo->hasRole(RolSistema::Encargado->value))->toBeTrue();

    $objetivo = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);

    $this->actingAs($this->admin)->put("/usuarios/{$objetivo->id}", [
        'name' => $objetivo->name,
        'email' => $objetivo->email,
        'roles' => [RolSistema::Superadministrador->value],
        'empresas' => [$this->empresa->id],
    ])->assertSessionHasNoErrors();

    expect($objetivo->fresh()->hasRole(RolSistema::Superadministrador->value))->toBeFalse();
});

it('5. un administrador no ve el rol superadministrador en Roles y permisos, ni en su exportación', function () {
    $respuesta = $this->actingAs($this->admin)->get('/roles');

    $nombres = collect($respuesta->viewData('page')['props']['roles'])->pluck('name');
    expect($nombres)->not->toContain(RolSistema::Superadministrador->value);

    Excel::fake();
    $this->actingAs($this->admin)->get('/roles/exportar')->assertOk();
    Excel::assertDownloaded(
        'roles-y-permisos-todas-las-empresas-'.now()->toDateString().'.xlsx',
        function (ListadoExport $export): bool {
            $nombres = collect($export->array())->pluck(0);
            expect($nombres)->not->toContain('Superadministrador');

            return true;
        },
    );
});

it('5b. un administrador no puede editar el rol superadministrador por URL manipulada', function () {
    $rol = Role::where('name', RolSistema::Superadministrador->value)->firstOrFail();

    $this->actingAs($this->admin)
        ->put("/roles/{$rol->id}", ['name' => RolSistema::Superadministrador->value, 'permisos' => ['activos.ver']])
        ->assertForbidden();
});

it('6. un administrador no ve en Auditoría los eventos realizados por un superadministrador', function () {
    BitacoraAuditoria::query()->create([
        'usuario_id' => $this->superadmin->id,
        'nombre_usuario_snapshot' => $this->superadmin->name,
        'empresa_id' => $this->empresa->id,
        'modulo' => 'empresas',
        'accion' => 'editar',
        'descripcion' => 'Acción del superadmin',
    ]);
    BitacoraAuditoria::query()->create([
        'usuario_id' => $this->admin->id,
        'nombre_usuario_snapshot' => $this->admin->name,
        'empresa_id' => $this->empresa->id,
        'modulo' => 'empresas',
        'accion' => 'editar',
        'descripcion' => 'Acción del administrador',
    ]);

    $respuesta = $this->actingAs($this->admin)->get('/auditoria');

    $descripciones = collect($respuesta->viewData('page')['props']['registros']['data'])->pluck('descripcion');
    expect($descripciones)->toContain('Acción del administrador')
        ->not->toContain('Acción del superadmin');

    // El total del paginador debe coincidir con lo realmente visible — nunca
    // un conteo que incluya lo filtrado.
    expect($respuesta->viewData('page')['props']['registros']['total'])->toBe(1);
});

it('7. un superadministrador sí ve todo: usuarios, roles y auditoría de otros superadministradores', function () {
    BitacoraAuditoria::query()->create([
        'usuario_id' => $this->superadmin->id,
        'nombre_usuario_snapshot' => $this->superadmin->name,
        'empresa_id' => $this->empresa->id,
        'modulo' => 'empresas',
        'accion' => 'editar',
        'descripcion' => 'Acción del superadmin',
    ]);

    $usuarios = collect($this->actingAs($this->superadmin)->get('/usuarios')
        ->viewData('page')['props']['usuarios']['data'])->pluck('id');
    expect($usuarios)->toContain($this->superadmin->id);

    $roles = collect($this->actingAs($this->superadmin)->get('/roles')
        ->viewData('page')['props']['roles'])->pluck('name');
    expect($roles)->toContain(RolSistema::Superadministrador->value);

    $registros = collect($this->actingAs($this->superadmin)->get('/auditoria')
        ->viewData('page')['props']['registros']['data'])->pluck('descripcion');
    expect($registros)->toContain('Acción del superadmin');

    $this->actingAs($this->superadmin)
        ->get("/usuarios/{$this->superadmin->id}/editar")
        ->assertOk();
});
