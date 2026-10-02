<?php

use App\Acciones\RegistrarDevolucionFirmada;
use App\Enums\CondicionDevolucion;
use App\Enums\CondicionUnidadActivo;
use App\Enums\DireccionMovimiento;
use App\Enums\EstadoUnidadActivo;
use App\Enums\FinalidadCustodia;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\BitacoraAuditoria;
use App\Models\Colaborador;
use App\Models\DetalleEntrega;
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
 * Finalidad de la custodia por renglón (uso personal / para redistribuir):
 * una sola custodia, sin inventarios paralelos. Lo personal no se entrega por
 * error: sólo con `entregas.redistribuir-propios`.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 50,
    ));

    $laptopActivo = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Laptop']);
    $this->laptop = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($laptopActivo)->for($this->datos['almacenA'])->create();

    $this->usuarioConPermisos = function (array $permisos): User {
        $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        return tap(User::factory()->create(), function (User $u) use ($rol): void {
            $u->assignRole($rol);
            $u->empresas()->sync([$this->datos['empresaA']->id]);
        });
    };

    $this->supervisor = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.redistribuir']);
    $this->yatziri = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create(['usuario_id' => $this->supervisor->id]);
    $this->juan = $this->datos['colaboradorA'];

    $this->firmas = fn (): array => [
        'fecha_entrega' => now()->toDateString(), 'firma' => firmaDemoBase64(), 'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true, 'idempotency_key' => (string) Str::uuid(),
    ];

    $this->camisas = fn (int $n, string $finalidad, array $extra = []): array => [
        'activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => $n, 'finalidad' => $finalidad, ...$extra,
    ];

    // Almacén → Yatziri en UNA entrega: laptop de uso personal + 10 camisas para repartir.
    $this->actingAs($this->admin)->post('/entregas', [
        ...($this->firmas)(),
        'colaborador_id' => $this->yatziri->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activos' => [($this->camisas)(10, 'redistribucion'), ($this->camisas)(2, 'uso_personal')],
        'unidades' => [['unidad_activo_id' => $this->laptop->id, 'finalidad' => 'uso_personal']],
    ])->assertSessionHasNoErrors();
    $this->entrega = EntregaUniforme::sole();

    $this->redistribuir = fn (User $u, array $activos = [], array $unidades = []) => $this->actingAs($u)->post('/entregas', [
        ...($this->firmas)(), 'origen' => 'custodia', 'colaborador_id' => $this->juan->id,
        'activos' => $activos, 'unidades' => $unidades,
    ]);

    $this->saldo = fn (): int => (int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad');
});

it('una misma entrega registra renglones de uso personal y para redistribuir y descuenta el almacén una sola vez', function () {
    $finalidades = $this->entrega->detalles()->pluck('finalidad')->map(fn ($f) => $f?->value)->sort()->values()->all();

    expect($finalidades)->toBe(['redistribucion', 'uso_personal', 'uso_personal'])
        ->and(($this->saldo)())->toBe(38)
        ->and(app(ServicioCustodiaColaborador::class)->totalPiezasPendientes($this->yatziri))->toBe(13);
});

it('sin permiso extra, "desde mi custodia" sólo ofrece lo recibido para redistribuir', function () {
    $this->actingAs($this->supervisor)
        ->getJson("/entregas/custodia/activos?empresa_id={$this->datos['empresaA']->id}&control=cantidad")
        ->assertJsonCount(1, 'activos')
        ->assertJsonPath('activos.0.bolsa', 'redistribucion')
        ->assertJsonPath('activos.0.tallas.0.disponible', 10);

    $this->actingAs($this->supervisor)
        ->getJson("/entregas/custodia/activos?empresa_id={$this->datos['empresaA']->id}&control=individual")
        ->assertExactJson(['activos' => []]);
});

it('un bien personal no se puede entregar sin el permiso, ni manipulando el request', function () {
    ($this->redistribuir)($this->supervisor, [], [['unidad_activo_id' => $this->laptop->id]])
        ->assertSessionHasErrors(['unidades.0.unidad_activo_id' => "La unidad {$this->laptop->codigo} es de uso personal (o sin clasificar) y no tienes permiso para reasignarla."]);

    ($this->redistribuir)($this->supervisor, [($this->camisas)(1, 'uso_personal', ['bolsa' => 'personal'])])
        ->assertSessionHasErrors(['activos.0.activo_id' => 'No tienes permiso para reasignar activos de uso personal de tu custodia.']);

    expect($this->laptop->fresh()->colaborador_id)->toBe($this->yatziri->id)
        ->and(EntregaUniforme::count())->toBe(1);
});

it('con permiso de reasignar personales lo transfiere sin tocar el almacén y el destinatario recibe su propia finalidad', function () {
    $conPropios = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.redistribuir', 'entregas.redistribuir-propios']);
    $this->yatziri->update(['usuario_id' => $conPropios->id]);
    $movimientosDeStock = MovimientoInventario::query()->where('direccion', '!=', DireccionMovimiento::SinEfecto)->count();

    $this->actingAs($conPropios)
        ->getJson("/entregas/custodia/activos?empresa_id={$this->datos['empresaA']->id}&control=individual")
        ->assertJsonPath('activos.0.bolsa', 'personal');

    ($this->redistribuir)($conPropios, [], [['unidad_activo_id' => $this->laptop->id, 'finalidad' => 'uso_personal']])
        ->assertSessionHasNoErrors();

    $detalleJuan = DetalleEntrega::query()->where('unidad_activo_id', $this->laptop->id)->latest('id')->first();
    expect($this->laptop->fresh()->colaborador_id)->toBe($this->juan->id)
        ->and($detalleJuan->finalidad)->toBe(FinalidadCustodia::UsoPersonal)
        ->and(MovimientoInventario::query()->where('direccion', '!=', DireccionMovimiento::SinEfecto)->count())->toBe($movimientosDeStock)
        ->and(($this->saldo)())->toBe(38);

    $auditoria = BitacoraAuditoria::query()->where('accion', 'redistribuir')->sole();
    expect($auditoria->valores_nuevos['renglones'][0]['desde'])->toBe('Uso personal / sin clasificar');
});

it('lo recibido para redistribuir llega al destinatario con la finalidad que se indique', function () {
    ($this->redistribuir)($this->supervisor, [($this->camisas)(3, 'uso_personal')])->assertSessionHasNoErrors();

    $detalleJuan = DetalleEntrega::query()->whereHas('entrega', fn ($q) => $q->where('colaborador_id', $this->juan->id))->sole();
    expect($detalleJuan->finalidad)->toBe(FinalidadCustodia::UsoPersonal)
        ->and(($this->saldo)())->toBe(38);
});

it('la devolución funciona igual para lo personal y lo redistribuible y reingresa al almacén', function () {
    $detalles = $this->entrega->detalles()->get();
    $personal = $detalles->first(fn ($d) => $d->unidad_activo_id === null && $d->finalidad === FinalidadCustodia::UsoPersonal);
    $redistribuible = $detalles->first(fn ($d) => $d->finalidad === FinalidadCustodia::Redistribucion);
    $laptop = $detalles->firstWhere('unidad_activo_id', $this->laptop->id);

    app(RegistrarDevolucionFirmada::class)->ejecutar(
        $this->entrega->id, $this->datos['almacenA']->id, now()->toDateString(),
        [
            ['detalle_entrega_id' => $personal->id, 'cantidad' => 2, 'condicion' => CondicionDevolucion::Reutilizable->value],
            ['detalle_entrega_id' => $redistribuible->id, 'cantidad' => 4, 'condicion' => CondicionDevolucion::Reutilizable->value],
        ],
        [['detalle_entrega_id' => $laptop->id, 'condicion' => CondicionUnidadActivo::Funcionando->value]],
        $this->admin->id, null, null, firmaDemoBase64(), firmaDemoBase64(), true, null, null,
    );

    expect(($this->saldo)())->toBe(44)
        ->and($this->laptop->fresh()->estado)->toBe(EstadoUnidadActivo::EnAlmacen)
        ->and(app(ServicioCustodiaColaborador::class)->totalPiezasPendientes($this->yatziri))->toBe(6);
});

it('lo histórico sin clasificar sigue en la custodia, no se ofrece para redistribuir y su finalidad ya no se puede reclasificar', function () {
    $sinClasificar = $this->entrega->detalles()->where('finalidad', 'redistribucion')->first();
    $sinClasificar->update(['finalidad' => null]);

    $this->actingAs($this->supervisor)
        ->getJson("/entregas/custodia/activos?empresa_id={$this->datos['empresaA']->id}&control=cantidad")
        ->assertExactJson(['activos' => []]);
    expect(app(ServicioCustodiaColaborador::class)->totalPiezasPendientes($this->yatziri))->toBe(13);

    // La finalidad se decide al entregar y queda como trazabilidad: el
    // endpoint de reclasificación desde la custodia ya no existe, ni siquiera
    // para quien puede registrar entregas.
    $this->actingAs($this->admin)
        ->put("/entregas/renglones/{$sinClasificar->id}/finalidad", ['finalidad' => 'redistribucion'])
        ->assertNotFound();

    expect($sinClasificar->fresh()->finalidad)->toBeNull()
        ->and(BitacoraAuditoria::query()->where('accion', 'clasificar_finalidad')->exists())->toBeFalse();
});
