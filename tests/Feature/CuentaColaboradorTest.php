<?php

use App\Models\BitacoraAuditoria;
use App\Models\Colaborador;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * "Cuenta de acceso asociada" (User ↔ Colaborador, 1:1): ver y administrar
 * son permisos distintos de los datos laborales, y sin permiso de ver nada de
 * la cuenta sale del backend.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->colaborador = $this->datos['colaboradorA'];

    $this->usuarioConPermisos = function (array $permisos, array $empresas = []): User {
        $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        return tap(User::factory()->create(), function (User $u) use ($rol, $empresas): void {
            $u->assignRole($rol);
            $u->empresas()->sync(collect($empresas ?: [$this->datos['empresaA']])->pluck('id'));
        });
    };

    $this->cuenta = ($this->usuarioConPermisos)(['entregas.ver']);
    $this->cuenta->update(['name' => 'Yatziri Cuenta', 'email' => 'yatziri@empresa.test']);
    $this->colaborador->update(['usuario_id' => $this->cuenta->id]);

    $this->rh = ($this->usuarioConPermisos)(['colaboradores.ver', 'colaboradores.editar']);
    $this->lector = ($this->usuarioConPermisos)(['colaboradores.ver', 'colaboradores.usuario-ver']);
    $this->admin = ($this->usuarioConPermisos)(['colaboradores.ver', 'colaboradores.usuario-ver', 'colaboradores.usuario-administrar']);
});

it('con colaboradores.ver/editar pero sin permiso de cuenta no recibe ningún dato de la cuenta', function () {
    $respuesta = $this->actingAs($this->rh)->get("/colaboradores/{$this->colaborador->id}");

    $respuesta->assertOk()->assertInertia(fn ($page) => $page->where('cuenta', null));
    expect($respuesta->getContent())->not->toContain('yatziri@empresa.test');

    $this->actingAs($this->rh)->getJson("/colaboradores/{$this->colaborador->id}/cuentas-disponibles")->assertForbidden();
});

it('con permiso de ver recibe sólo nombre y correo, en modo lectura', function () {
    $this->actingAs($this->lector)->get("/colaboradores/{$this->colaborador->id}")
        ->assertInertia(fn ($page) => $page
            ->where('cuenta.usuario', ['id' => $this->cuenta->id, 'name' => 'Yatziri Cuenta', 'email' => 'yatziri@empresa.test'])
            ->where('cuenta.puede_administrar', false));

    $this->actingAs($this->lector)
        ->put("/colaboradores/{$this->colaborador->id}/cuenta", ['usuario_id' => null])
        ->assertForbidden();

    expect($this->colaborador->fresh()->usuario_id)->toBe($this->cuenta->id);
});

it('con permiso de administrar vincula y desvincula dentro de su alcance, con auditoría', function () {
    $nueva = ($this->usuarioConPermisos)([]);
    $nueva->update(['email' => 'carolina@empresa.test']);

    $this->actingAs($this->admin)
        ->getJson("/colaboradores/{$this->colaborador->id}/cuentas-disponibles?q=carolina")
        ->assertOk()
        ->assertExactJson(['cuentas' => [['id' => $nueva->id, 'name' => $nueva->name, 'email' => 'carolina@empresa.test']]]);

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$this->colaborador->id}/cuenta", ['usuario_id' => $nueva->id])
        ->assertSessionHasNoErrors();
    expect($this->colaborador->fresh()->usuario_id)->toBe($nueva->id);

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$this->colaborador->id}/cuenta", ['usuario_id' => null])
        ->assertSessionHasNoErrors();
    expect($this->colaborador->fresh()->usuario_id)->toBeNull()
        ->and(BitacoraAuditoria::query()->where('accion', 'desvincular_cuenta')->exists())->toBeTrue();
});

it('no vincula una cuenta que ya representa a otro colaborador ni una fuera del alcance de la empresa', function () {
    $otro = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();
    $deOtraEmpresa = ($this->usuarioConPermisos)([], [$this->datos['empresaB']]);

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$otro->id}/cuenta", ['usuario_id' => $this->cuenta->id])
        ->assertSessionHasErrors('negocio');

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$otro->id}/cuenta", ['usuario_id' => $deOtraEmpresa->id])
        ->assertSessionHasErrors('negocio');

    expect($otro->fresh()->usuario_id)->toBeNull();
});

it('la base de datos garantiza que una cuenta represente a un solo colaborador', function () {
    $otro = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();

    expect(fn () => $otro->update(['usuario_id' => $this->cuenta->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('no administra la cuenta de un colaborador de una empresa fuera de su alcance', function () {
    $ajeno = Colaborador::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->create();

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$ajeno->id}/cuenta", ['usuario_id' => null])
        ->assertForbidden();
});
