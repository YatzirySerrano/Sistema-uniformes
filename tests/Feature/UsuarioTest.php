<?php

use App\Enums\RolSistema;
use App\Exports\ListadoExport;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
});

it('sólo quien puede desactivar usuarios ve los eliminados, ni forzando el filtro por URL', function () {
    $vivo = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);
    $vivo->update(['name' => 'Vivo']);
    $apagado = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);
    $apagado->update(['name' => 'Apagado', 'activo' => false]);

    // Rol personalizado con permiso para ver el listado pero no para
    // eliminar/restaurar usuarios (los roles restringidos base ni siquiera
    // tienen "usuarios.ver").
    Role::findOrCreate('visor_de_usuarios', 'web')->syncPermissions(['usuarios.ver']);
    $visor = User::factory()->create();
    $visor->assignRole('visor_de_usuarios');
    $visor->empresas()->sync([$this->empresa->id]);

    $this->actingAs($this->admin)
        ->get('/usuarios')
        ->assertInertia(fn ($page) => $page
            ->where('puedeVerEliminados', true)
        );

    $this->actingAs($visor)
        ->get('/usuarios')
        ->assertInertia(fn ($page) => $page
            ->where('puedeVerEliminados', false)
        );

    $respuesta = $this->actingAs($visor)->get('/usuarios');
    $nombres = collect($respuesta->viewData('page')['props']['usuarios']['data'])->pluck('name');
    expect($nombres)->toContain('Vivo')->not->toContain('Apagado');
});

it('un usuario no puede desactivarse a sí mismo ni a un superadministrador', function () {
    $superadmin = usuarioCon(RolSistema::Superadministrador->value, [$this->empresa]);

    $this->actingAs($this->admin)
        ->post("/usuarios/{$this->admin->id}/estado")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->post("/usuarios/{$superadmin->id}/estado")
        ->assertForbidden();
});

it('cambia el estado de un usuario y renombra los mensajes a eliminar/restaurar', function () {
    $objetivo = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);

    $this->actingAs($this->admin)
        ->post("/usuarios/{$objetivo->id}/estado")
        ->assertRedirect()
        ->assertSessionHas('toast.message', 'Usuario eliminado.');

    expect($objetivo->fresh()->activo)->toBeFalse();

    $this->actingAs($this->admin)
        ->post("/usuarios/{$objetivo->id}/estado")
        ->assertRedirect()
        ->assertSessionHas('toast.message', 'Usuario restaurado.');

    expect($objetivo->fresh()->activo)->toBeTrue();
});

it('marca puedeCambiarEstado en falso para el propio usuario y para un superadministrador', function () {
    $superadmin = usuarioCon(RolSistema::Superadministrador->value, [$this->empresa]);
    usuarioCon(RolSistema::Encargado->value, [$this->empresa]);

    $respuesta = $this->actingAs($this->admin)->get('/usuarios');
    $filas = collect($respuesta->viewData('page')['props']['usuarios']['data'])->keyBy('id');

    expect($filas[$this->admin->id]['puedeCambiarEstado'])->toBeFalse()
        ->and($filas[$superadmin->id]['puedeCambiarEstado'])->toBeFalse();
});

it('busca usuarios por nombre y por correo', function () {
    $ana = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);
    $ana->update(['name' => 'Ana Ramírez', 'email' => 'ana.r@empresa.test']);
    $beto = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);
    $beto->update(['name' => 'Beto López', 'email' => 'beto@otra.test']);

    $porNombre = collect($this->actingAs($this->admin)->get('/usuarios?buscar=ram')
        ->viewData('page')['props']['usuarios']['data'])->pluck('name');
    expect($porNombre)->toContain('Ana Ramírez')->not->toContain('Beto López');

    $porCorreo = collect($this->actingAs($this->admin)->get('/usuarios?buscar=otra.test')
        ->viewData('page')['props']['usuarios']['data'])->pluck('name');
    expect($porCorreo)->toContain('Beto López')->not->toContain('Ana Ramírez');
});

it('filtra usuarios por rol y por estado', function () {
    $encargado = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);
    $encargado->update(['name' => 'Solo Encargado']);
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->empresa]);
    $supervisor->update(['name' => 'Solo Supervisor']);
    $eliminado = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);
    $eliminado->update(['name' => 'Eliminado', 'activo' => false]);

    $porRol = collect($this->actingAs($this->admin)->get('/usuarios?rol='.RolSistema::Supervisor->value)
        ->viewData('page')['props']['usuarios']['data'])->pluck('name');
    expect($porRol)->toContain('Solo Supervisor')->not->toContain('Solo Encargado');

    $eliminados = collect($this->actingAs($this->admin)->get('/usuarios?estado=eliminados')
        ->viewData('page')['props']['usuarios']['data'])->pluck('name');
    expect($eliminados)->toContain('Eliminado')->not->toContain('Solo Encargado');
});

it('filtra usuarios por empresa asignada', function () {
    $otraEmpresa = Empresa::factory()->create();
    $deLaEmpresa = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);
    $deLaEmpresa->update(['name' => 'De la empresa']);
    $deOtra = usuarioCon(RolSistema::Encargado->value, [$otraEmpresa]);
    $deOtra->update(['name' => 'De otra empresa']);

    $filas = collect($this->actingAs($this->admin)->get("/usuarios?empresa_id={$this->empresa->id}")
        ->viewData('page')['props']['usuarios']['data'])->pluck('name');

    expect($filas)->toContain('De la empresa')->not->toContain('De otra empresa');
});

it('exporta el listado de usuarios a Excel respetando los filtros y sin datos sensibles', function () {
    Excel::fake();

    $ana = usuarioCon(RolSistema::Supervisor->value, [$this->empresa]);
    $ana->update(['name' => 'Ana Export', 'email' => 'ana.export@empresa.test']);
    $otro = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);
    $otro->update(['name' => 'Otro Rol']);

    $this->actingAs($this->admin)->get('/usuarios/exportar?rol='.RolSistema::Supervisor->value)->assertOk();

    Excel::assertDownloaded('usuarios-todas-las-empresas-'.now()->toDateString().'.xlsx', function (ListadoExport $export): bool {
        $filas = $export->array();
        $planas = collect($filas)->flatmap(fn (array $f): array => $f)->map(fn ($v): string => (string) $v);

        // La fila del supervisor está; la del encargado (filtrada) no.
        expect(collect($filas)->pluck(0))->toContain('Ana Export')->not->toContain('Otro Rol');
        // Nunca se exporta el hash de contraseña ni tokens.
        expect($planas->contains(fn (string $v): bool => Str::startsWith($v, '$2y$')))->toBeFalse();

        return true;
    });
});

it('exporta el listado de usuarios a PDF', function () {
    usuarioCon(RolSistema::Encargado->value, [$this->empresa]);

    $respuesta = $this->actingAs($this->admin)->get('/usuarios/exportar?formato=pdf')->assertOk();

    expect($respuesta->getContent())->toStartWith('%PDF-');
    $respuesta->assertHeader('content-type', 'application/pdf');
});

it('el endpoint de exportación de usuarios exige el permiso usuarios.ver', function () {
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value, [$this->empresa]);

    $this->actingAs($sinPermiso)->get('/usuarios/exportar')->assertForbidden();
});

it('crea un usuario con contraseña y confirmación válidas y le envía el correo de verificación', function () {
    Notification::fake();

    $this->actingAs($this->admin)->post('/usuarios', [
        'name' => 'Nueva Persona',
        'email' => 'nueva.persona@empresa.test',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'roles' => [RolSistema::Encargado->value],
        'empresas' => [$this->empresa->id],
    ])->assertRedirect('/usuarios')->assertSessionHasNoErrors();

    $usuario = User::query()->where('email', 'nueva.persona@empresa.test')->firstOrFail();

    expect($usuario->email_verified_at)->toBeNull()
        ->and(Hash::check('Password123', $usuario->password))->toBeTrue();

    Notification::assertSentTo($usuario, VerifyEmail::class);
});

it('rechaza crear un usuario si la confirmación de contraseña no coincide (el backend es la fuente de verdad)', function () {
    Notification::fake();

    $this->actingAs($this->admin)->post('/usuarios', [
        'name' => 'Con Error',
        'email' => 'con.error@empresa.test',
        'password' => 'Password123',
        'password_confirmation' => 'Password124',
        'roles' => [RolSistema::Encargado->value],
        'empresas' => [$this->empresa->id],
    ])->assertSessionHasErrors('password');

    expect(User::query()->where('email', 'con.error@empresa.test')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('al editar un usuario, dejar la contraseña en blanco no la cambia', function () {
    $objetivo = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);
    $hashOriginal = $objetivo->password;

    $this->actingAs($this->admin)->put("/usuarios/{$objetivo->id}", [
        'name' => 'Nombre Editado',
        'email' => $objetivo->email,
        'password' => '',
        'password_confirmation' => '',
        'roles' => $objetivo->getRoleNames()->all(),
        'empresas' => [$this->empresa->id],
    ])->assertRedirect('/usuarios')->assertSessionHasNoErrors();

    expect($objetivo->fresh()->password)->toBe($hashOriginal)
        ->and($objetivo->fresh()->name)->toBe('Nombre Editado');
});
