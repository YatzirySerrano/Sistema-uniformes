<?php

use App\Enums\CondicionUnidadActivo;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\CondicionInventario;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * "Corregir existencia" (`inventario.ajustar`) y la condición física
 * (`activos.condicion` para existencias por cantidad,
 * `unidades-activo.condicion` para unidades) son capacidades independientes,
 * decididas sólo por los permisos efectivos de un rol cualquiera.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 10,
    ));

    $this->usuarioConPermisos = function (array $permisos): User {
        $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $rol->syncPermissions(['activos.ver', 'unidades-activo.ver', ...$permisos]);

        return tap(User::factory()->create(), function (User $u) use ($rol): void {
            $u->assignRole($rol);
            $u->empresas()->sync([$this->datos['empresaA']->id]);
        });
    };

    $this->existencia = fn (array $extra): array => [
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'motivo' => 'Revisión física',
        ...$extra,
    ];

    $this->corregir = fn (User $u) => $this->actingAs($u)->post('/inventario/ajuste', ($this->existencia)(['existencia_objetivo' => 8]));
    $this->marcarDanado = fn (User $u) => $this->actingAs($u)->post('/inventario/condicion', ($this->existencia)(['condicion' => 'danado', 'cantidad' => 1]));

    $celular = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $this->unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($celular)->for($this->datos['almacenA'])->create();
    $this->danarUnidad = fn (User $u) => $this->actingAs($u)->post("/activos/unidades/{$this->unidad->public_token}/danar", [
        'condicion_resultante' => CondicionUnidadActivo::EnReparacion->value,
        'motivo' => 'Pantalla rota',
    ]);

    $this->saldo = fn (): int => (int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad');
});

it('con permiso de condición pero sin inventario.ajustar cambia la condición y no puede corregir existencias', function () {
    $inspector = ($this->usuarioConPermisos)(['activos.condicion', 'unidades-activo.condicion']);

    ($this->marcarDanado)($inspector)->assertSessionHasNoErrors()->assertRedirect();
    ($this->danarUnidad)($inspector)->assertSessionHasNoErrors()->assertRedirect();
    ($this->corregir)($inspector)->assertForbidden();

    expect(CondicionInventario::query()->sum('cantidad'))->toEqual(1)
        ->and($this->unidad->fresh()->condicion)->toBe(CondicionUnidadActivo::EnReparacion)
        ->and(($this->saldo)())->toBe(9);
});

it('con inventario.ajustar pero sin permiso de condición corrige existencias y no puede cambiar la condición', function () {
    $ajustador = ($this->usuarioConPermisos)(['inventario.ajustar', 'unidades-activo.administrar']);

    ($this->corregir)($ajustador)->assertSessionHasNoErrors()->assertRedirect();
    ($this->marcarDanado)($ajustador)->assertForbidden();
    ($this->danarUnidad)($ajustador)->assertForbidden();

    expect(($this->saldo)())->toBe(8)
        ->and(CondicionInventario::count())->toBe(0)
        ->and($this->unidad->fresh()->condicion)->toBe(CondicionUnidadActivo::Funcionando);
});

it('con ambos permisos puede corregir y cambiar la condición', function () {
    $ambos = ($this->usuarioConPermisos)(['inventario.ajustar', 'activos.condicion']);

    ($this->corregir)($ambos)->assertSessionHasNoErrors();
    ($this->marcarDanado)($ambos)->assertSessionHasNoErrors();

    expect(($this->saldo)())->toBe(7);
});

it('sin ninguno de los dos no puede hacer ninguna de las dos acciones', function () {
    $lector = ($this->usuarioConPermisos)([]);

    ($this->corregir)($lector)->assertForbidden();
    ($this->marcarDanado)($lector)->assertForbidden();
    ($this->danarUnidad)($lector)->assertForbidden();

    expect(($this->saldo)())->toBe(10);
});

it('el detalle del activo ofrece cada acción según su propio permiso', function () {
    $inspector = ($this->usuarioConPermisos)(['activos.condicion']);

    $this->actingAs($inspector)->get("/activos/{$this->datos['activoA']->id}")
        ->assertInertia(fn ($page) => $page
            ->where('permisos.condicion_inventario', true)
            ->where('permisos.ajustar_inventario', false));
});
