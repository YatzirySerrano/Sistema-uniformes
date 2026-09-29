<?php

use App\Enums\TipoMovimiento;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\Sucursal;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Usar una empresa / sucursal como CONTEXTO de una operación (selectores de
 * Entregas, Devoluciones, etc.) depende del alcance autorizado del usuario,
 * no de poder navegar los módulos Empresas / Sucursales.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->empresaC = Empresa::factory()->create(['nombre_comercial' => 'Empresa C']);

    $this->usuarioConPermisos = function (array $permisos, array $empresas): User {
        $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        return tap(User::factory()->create(), function (User $u) use ($rol, $empresas): void {
            $u->assignRole($rol);
            $u->empresas()->sync(collect($empresas)->pluck('id'));
        });
    };

    // Operativo de inventario: entrega, pero NO ve los módulos Empresas ni Sucursales.
    $this->operativo = ($this->usuarioConPermisos)(
        ['entregas.ver', 'entregas.crear', 'inventario.ver', 'activos.ver', 'almacenes.ver'],
        [$this->datos['empresaA'], $this->empresaC],
    );
});

it('sin empresas.ver obtiene sólo sus empresas autorizadas en el selector, con los datos mínimos', function () {
    $this->actingAs($this->operativo)
        ->getJson('/empresas/buscar')
        ->assertOk()
        ->assertExactJson(['empresas' => [
            ['id' => $this->datos['empresaA']->id, 'codigo' => $this->datos['empresaA']->codigo, 'nombre_comercial' => 'Empresa A'],
            ['id' => $this->empresaC->id, 'codigo' => $this->empresaC->codigo, 'nombre_comercial' => 'Empresa C'],
        ]]);
});

it('sin empresas.ver no puede entrar al módulo Empresas ni a su detalle', function () {
    $this->actingAs($this->operativo)->get('/empresas')->assertForbidden();
    $this->actingAs($this->operativo)->get("/empresas/{$this->datos['empresaA']->id}")->assertForbidden();

    $this->actingAs($this->operativo)->get('/entregas/crear')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user.permisos', fn ($permisos) => ! collect($permisos)->contains('empresas.ver')));
});

it('con empresas.ver sí accede al módulo Empresas conforme a la Policy', function () {
    $conModulo = ($this->usuarioConPermisos)(['empresas.ver'], [$this->datos['empresaA']]);

    $this->actingAs($conModulo)->get('/empresas')->assertOk();
    $this->actingAs($conModulo)->get("/empresas/{$this->datos['empresaA']->id}")->assertOk();
    $this->actingAs($conModulo)->get("/empresas/{$this->datos['empresaB']->id}")->assertForbidden();
});

it('sin sucursales.ver obtiene sólo las sucursales de su alcance, sin abrir el módulo Sucursales', function () {
    $sucursalC = Sucursal::factory()->for($this->empresaC)->create(['nombre' => 'Sucursal C']);

    $this->actingAs($this->operativo)
        ->getJson("/sucursales/buscar?empresa_id={$this->empresaC->id}")
        ->assertOk()
        ->assertExactJson(['sucursales' => [['id' => $sucursalC->id, 'nombre' => 'Sucursal C']]]);

    $this->actingAs($this->operativo)
        ->getJson("/sucursales/buscar?empresa_id={$this->datos['empresaB']->id}")
        ->assertExactJson(['sucursales' => []]);

    $this->actingAs($this->operativo)->get('/sucursales')->assertForbidden();
});

it('respeta la restricción por sucursal asignada', function () {
    $otra = Sucursal::factory()->for($this->datos['empresaA'])->create();
    $this->operativo->sucursales()->attach($this->datos['sucursalA']);

    $this->actingAs($this->operativo)
        ->getJson("/sucursales/buscar?empresa_id={$this->datos['empresaA']->id}")
        ->assertJsonCount(1, 'sucursales')
        ->assertJsonPath('sucursales.0.id', $this->datos['sucursalA']->id)
        ->assertJsonMissing(['id' => $otra->id]);
});

it('registra una entrega completa sin empresas.ver, sucursales.ver ni colaboradores.ver', function () {
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 5,
    ));
    Storage::fake('local');
    Mail::fake();

    $this->actingAs($this->operativo)
        ->getJson("/colaboradores/buscar?empresa_id={$this->datos['empresaA']->id}&sucursal_id={$this->datos['sucursalA']->id}")
        ->assertOk()
        ->assertJsonPath('colaboradores.0.id', $this->datos['colaboradorA']->id);

    $this->actingAs($this->operativo)->post('/entregas', [
        'colaborador_id' => $this->datos['colaboradorA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'idempotency_key' => (string) Str::uuid(),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(EntregaUniforme::query()->where('colaborador_id', $this->datos['colaboradorA']->id)->exists())->toBeTrue();
});
