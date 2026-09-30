<?php

use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * `activos.ver-custodia-propia`: consultar SÓLO la custodia del colaborador
 * vinculado, sin acceso al módulo Activos ni a datos ajenos.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 30,
    ));

    $this->usuarioConPermisos = function (array $permisos): User {
        $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        return tap(User::factory()->create(), function (User $u) use ($rol): void {
            $u->assignRole($rol);
            $u->empresas()->sync([$this->datos['empresaA']->id]);
        });
    };

    $this->propio = ($this->usuarioConPermisos)(['activos.ver-custodia-propia']);
    $this->yo = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create(['usuario_id' => $this->propio->id]);
    $this->ajeno = $this->datos['colaboradorA'];

    $laptop = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Laptop']);
    $this->miLaptop = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($laptop)->for($this->datos['almacenA'])->create();
    $this->laptopAjena = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($laptop)->for($this->datos['almacenA'])->create();

    $entregar = fn (Colaborador $c, array $activos, array $unidades) => $this->actingAs($this->admin)->post('/entregas', [
        'colaborador_id' => $c->id, 'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(), 'firma' => firmaDemoBase64(), 'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true, 'idempotency_key' => (string) Str::uuid(),
        'activos' => $activos, 'unidades' => $unidades,
    ])->assertSessionHasNoErrors();

    $camisas = fn (int $n, string $finalidad): array => ['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => $n, 'finalidad' => $finalidad];

    $entregar($this->yo, [$camisas(8, 'redistribucion'), $camisas(1, 'uso_personal')], [['unidad_activo_id' => $this->miLaptop->id, 'finalidad' => 'uso_personal']]);
    $entregar($this->ajeno, [$camisas(5, 'uso_personal')], [['unidad_activo_id' => $this->laptopAjena->id, 'finalidad' => 'uso_personal']]);
});

it('sólo con ver-custodia-propia ve únicamente su custodia, separada por finalidad', function () {
    $respuesta = $this->actingAs($this->propio)->get('/mis-activos');

    $respuesta->assertOk()->assertInertia(fn ($page) => $page
        ->component('Activos/MisActivos')
        ->count('personales', 2)
        ->count('redistribuir', 1)
        ->where('redistribuir.0.cantidad', 8)
        ->where('personales', fn ($filas) => collect($filas)->pluck('codigo')->filter()->values()->all() === [$this->miLaptop->codigo]));

    expect($respuesta->getContent())
        ->not->toContain($this->laptopAjena->codigo)
        ->not->toContain($this->ajeno->nombre_completo);
});

it('no puede abrir el módulo Activos ni el detalle de un activo o unidad por URL', function () {
    $this->actingAs($this->propio)->get('/activos')->assertForbidden();
    $this->actingAs($this->propio)->get("/activos/{$this->miLaptop->activo_id}")->assertForbidden();
    $this->actingAs($this->propio)->get("/activos/unidades/{$this->laptopAjena->public_token}")->assertForbidden();
});

it('sin ficha vinculada recibe un estado vacío con mensaje, sin datos de nadie', function () {
    $sinFicha = ($this->usuarioConPermisos)(['activos.ver-custodia-propia']);

    $this->actingAs($sinFicha)->get('/mis-activos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('colaborador', null)->count('personales', 0)->count('redistribuir', 0));
});

it('sin ninguno de los dos permisos no entra; con activos.ver conserva el módulo global', function () {
    $nada = ($this->usuarioConPermisos)([]);
    $this->actingAs($nada)->get('/mis-activos')->assertForbidden();

    $global = ($this->usuarioConPermisos)(['activos.ver']);
    $this->actingAs($global)->get('/activos')->assertOk();
});
