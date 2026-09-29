<?php

use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\Conjunto;
use App\Models\EntregaUniforme;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioCustodiaColaborador;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Un conjunto recibido desde almacén puede redistribuirse desde custodia: el
 * conjunto es sólo contexto; lo que cambia de custodio son sus componentes
 * reales, sin volver a descontar inventario.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
    $rol->syncPermissions(['entregas.ver', 'entregas.redistribuir']);
    $this->redistribuidor = tap(User::factory()->create(), function (User $u) use ($rol): void {
        $u->assignRole($rol);
        $u->empresas()->sync([$this->datos['empresaA']->id]);
    });

    $this->yatziri = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create(['usuario_id' => $this->redistribuidor->id]);
    $this->juan = $this->datos['colaboradorA'];

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 10,
    ));

    $radio = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Radio']);
    $this->unidadRadio = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($radio)->for($this->datos['almacenA'])->create();

    $this->kit = Conjunto::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Kit Guardia', 'activo' => true]);
    $this->kit->componentes()->create(['activo_id' => $this->datos['activoA']->id, 'cantidad_requerida' => 2, 'talla_id' => $this->datos['tallaA']->id, 'talla_libre' => false]);
    $this->kit->componentes()->create(['activo_id' => $radio->id, 'cantidad_requerida' => 1, 'talla_libre' => false]);

    $this->firmas = fn (): array => [
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'idempotency_key' => (string) Str::uuid(),
    ];

    // Almacén → Yatziri: 1 Kit Guardia (2 camisas + 1 radio).
    $this->actingAs($this->admin)->post('/entregas', [
        ...($this->firmas)(),
        'colaborador_id' => $this->yatziri->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'conjuntos' => [['conjunto_id' => $this->kit->id, 'cantidad' => 1]],
    ])->assertSessionHasNoErrors();

    $this->redistribuirKit = fn (array $extra = []) => $this->actingAs($this->redistribuidor)->post('/entregas', [
        ...($this->firmas)(),
        'origen' => 'custodia',
        'colaborador_id' => $this->juan->id,
        'conjuntos' => [['conjunto_id' => $this->kit->id, 'cantidad' => 1]],
        ...$extra,
    ]);

    $this->kitsCompletos = fn (Colaborador $c): int => collect(app(ServicioCustodiaColaborador::class)->conjuntosRedistribuibles($c->fresh()))
        ->firstWhere('conjunto_id', $this->kit->id)['completos'] ?? 0;
});

it('redistribuye un conjunto recibido: los componentes cambian de custodio sin volver a descontar inventario', function () {
    $stockAntes = (int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad');
    $movimientosAntes = MovimientoInventario::count();

    ($this->redistribuirKit)()->assertSessionHasNoErrors();

    expect((int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad'))->toBe($stockAntes)
        ->and(MovimientoInventario::count())->toBe($movimientosAntes)
        ->and($this->unidadRadio->fresh()->colaborador_id)->toBe($this->juan->id)
        ->and(($this->kitsCompletos)($this->yatziri))->toBe(0)
        ->and(($this->kitsCompletos)($this->juan))->toBe(1);
});

it('la trazabilidad conserva el conjunto como contexto y los componentes como realidad física', function () {
    ($this->redistribuirKit)();

    $redistribucion = EntregaUniforme::query()->where('colaborador_id', $this->juan->id)->sole();
    $renglones = $redistribucion->detalles()->orderBy('id')->get();

    expect($renglones)->toHaveCount(2)
        ->and($renglones->pluck('conjunto_nombre_snapshot')->unique()->all())->toBe(['Kit Guardia'])
        ->and($renglones->firstWhere('unidad_activo_id', null)->cantidad)->toBe(2)
        ->and($renglones->firstWhere('unidad_activo_id', null)->detalle_origen_id)->not->toBeNull()
        ->and($renglones->firstWhere('unidad_activo_id', $this->unidadRadio->id))->not->toBeNull();
});

it('un conjunto incompleto no aparece como completo ni puede redistribuirse entero', function () {
    // Yatziri ya entregó por separado una de las dos camisas del kit.
    $this->actingAs($this->redistribuidor)->post('/entregas', [
        ...($this->firmas)(),
        'origen' => 'custodia',
        'colaborador_id' => $this->juan->id,
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->redistribuidor)
        ->getJson("/entregas/custodia/conjuntos?empresa_id={$this->datos['empresaA']->id}")
        ->assertOk()
        ->assertJsonPath('conjuntos.0.completos', 0)
        ->assertJsonPath('conjuntos.0.componentes.0.disponible', 1)
        ->assertJsonPath('conjuntos.0.componentes.0.requerido', 2);

    ($this->redistribuirKit)()
        ->assertSessionHasErrors(['conjuntos.0.cantidad' => 'Ese conjunto no está completo en tu custodia. Entrega sus piezas por separado.']);

    expect($this->unidadRadio->fresh()->colaborador_id)->toBe($this->yatziri->id);
});

it('no redistribuye componentes que el custodio ya no posee', function () {
    ($this->redistribuirKit)()->assertSessionHasNoErrors();

    ($this->redistribuirKit)()->assertSessionHasErrors('conjuntos.0.cantidad');

    expect(EntregaUniforme::query()->where('colaborador_id', $this->juan->id)->count())->toBe(1);
});
