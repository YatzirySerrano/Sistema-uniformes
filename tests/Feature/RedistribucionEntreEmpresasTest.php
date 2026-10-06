<?php

use App\Enums\EstadoUnidadActivo;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Conjunto;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
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
 * Redistribución ENTRE EMPRESAS: Arturo es colaborador de JSIG, pero como
 * usuario está autorizado en JSIG, B y C. Lo que tiene bajo custodia (bienes
 * propiedad de JSIG) puede entregarlo a colaboradores de cualquiera de esas
 * empresas. Tres conceptos separados:
 * - ORIGEN: la custodia real de Arturo;
 * - DESTINO: empresa + sucursal + colaborador dentro del alcance del usuario;
 * - PROPIEDAD: `activos.empresa_id` / `unidades_activo.empresa_id` (JSIG,
 *   nunca cambia; una devolución reingresa a SU inventario).
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->jsig = $this->datos['empresaA'];
    $this->empresaB = $this->datos['empresaB'];
    $this->empresaC = Empresa::factory()->create(['nombre_comercial' => 'Empresa C']);
    $this->empresaD = Empresa::factory()->create(['nombre_comercial' => 'Empresa D']);

    $this->sucursalJsig = $this->datos['sucursalA'];
    $this->sucursalJsig2 = Sucursal::factory()->for($this->jsig)->create();
    $this->sucursalB = $this->datos['sucursalB'];
    $this->sucursalB2 = Sucursal::factory()->for($this->empresaB)->create();
    $this->sucursalC = Sucursal::factory()->for($this->empresaC)->create();
    $this->sucursalD = Sucursal::factory()->for($this->empresaD)->create();

    $this->almacenJsig = $this->datos['almacenA'];
    $this->almacenB = $this->datos['almacenB'];
    $this->camisa = $this->datos['activoA'];
    $this->tallaM = $this->datos['tallaA'];

    $this->admin = usuarioCon(RolSistema::Administrador->value);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->jsig->id,
        almacenId: $this->almacenJsig->id,
        activoId: $this->camisa->id,
        tallaId: $this->tallaM->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 100,
    ));

    $this->celular = Activo::factory()->for($this->jsig)->seguimientoIndividual()->create(['nombre' => 'Celular']);
    $this->unidad = UnidadActivo::factory()->for($this->jsig, 'empresa')->for($this->celular)->for($this->almacenJsig)->create();

    // Cuenta con rol PERSONALIZADO definida sólo por permisos efectivos.
    $this->usuarioConPermisos = function (array $permisos, array $empresas, array $sucursales = []): User {
        $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        return tap(User::factory()->create(), function (User $u) use ($rol, $empresas, $sucursales): void {
            $u->assignRole($rol);
            $u->empresas()->sync(collect($empresas)->pluck('id'));
            $u->sucursales()->sync(collect($sucursales)->pluck('id'));
        });
    };

    // Arturo: rol base Supervisor pero SIN `entregas.crear` (no saca stock
    // del almacén) y con `entregas.redistribuir`. En B sólo la sucursal B.
    Role::findByName(RolSistema::Supervisor->value, 'web')->syncPermissions(['entregas.ver', 'entregas.redistribuir']);
    $this->arturoUsuario = tap(User::factory()->create(), function (User $u): void {
        $u->assignRole(RolSistema::Supervisor->value);
        $u->empresas()->sync([$this->jsig->id, $this->empresaB->id, $this->empresaC->id]);
        $u->sucursales()->sync([$this->sucursalB->id]);
    });
    $this->arturo = Colaborador::factory()->for($this->jsig)->for($this->sucursalJsig)
        ->create(['nombre_completo' => 'Arturo Custodio', 'usuario_id' => $this->arturoUsuario->id]);

    $this->colaboradorB = Colaborador::factory()->for($this->empresaB)->for($this->sucursalB)->create(['nombre_completo' => 'Beto B']);
    $this->colaboradorB2 = Colaborador::factory()->for($this->empresaB)->for($this->sucursalB2)->create();
    $this->colaboradorC = Colaborador::factory()->for($this->empresaC)->for($this->sucursalC)->create();
    $this->colaboradorD = Colaborador::factory()->for($this->empresaD)->for($this->sucursalD)->create();
    $this->colaboradorJsig2 = Colaborador::factory()->for($this->jsig)->for($this->sucursalJsig2)->create();

    $this->firmas = fn (): array => [
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'idempotency_key' => (string) Str::uuid(),
    ];

    $this->camisas = fn (int $cantidad, string $finalidad = 'redistribucion'): array => [
        ['activo_id' => $this->camisa->id, 'talla_id' => $this->tallaM->id, 'cantidad' => $cantidad, 'finalidad' => $finalidad],
    ];

    // Yatziry (almacén) entrega a Arturo 20 camisas M + 1 celular, PARA REDISTRIBUIR.
    $this->actingAs($this->admin)->post('/entregas', [
        ...($this->firmas)(),
        'colaborador_id' => $this->arturo->id,
        'almacen_id' => $this->almacenJsig->id,
        'activos' => ($this->camisas)(20),
        'unidades' => [['unidad_activo_id' => $this->unidad->id, 'finalidad' => 'redistribucion']],
    ])->assertSessionHasNoErrors();

    // Destino por defecto = empresa + sucursal del destinatario; `$extra`
    // permite manipular el request.
    $this->redistribuir = fn (User $usuario, Colaborador $destinatario, array $activos = [], array $unidades = [], array $extra = []) => $this->actingAs($usuario)->post('/entregas', [
        ...($this->firmas)(),
        'origen' => 'custodia',
        'colaborador_id' => $destinatario->id,
        'empresa_id' => $destinatario->empresa_id,
        'sucursal_id' => $destinatario->sucursal_id,
        'activos' => $activos,
        'unidades' => $unidades,
        ...$extra,
    ]);

    $this->custodia = fn (Colaborador $colaborador): int => array_sum(array_column(
        app(ServicioCustodiaColaborador::class)->cantidadesRedistribuibles($colaborador->fresh(), true),
        'disponible',
    ));

    /** Foto completa de los saldos: (empresa, almacén, activo, talla) => cantidad. */
    $this->saldos = fn (): array => SaldoInventario::query()->get()
        ->mapWithKeys(fn (SaldoInventario $s): array => ["{$s->empresa_id}-{$s->almacen_id}-{$s->activo_id}-{$s->talla_id}" => (int) $s->cantidad])
        ->all();

    $this->stockJsig = fn (): int => (int) SaldoInventario::query()
        ->where('empresa_id', $this->jsig->id)
        ->where('almacen_id', $this->almacenJsig->id)
        ->where('activo_id', $this->camisa->id)
        ->value('cantidad');
});

it('redistribuye 5 camisas a un colaborador de la Empresa B sin tocar el almacén y sin cambiar la propiedad', function () {
    $saldosAntes = ($this->saldos)();

    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB, ($this->camisas)(5))->assertSessionHasNoErrors();

    $entrega = EntregaUniforme::query()->where('colaborador_id', $this->colaboradorB->id)->sole();

    expect(($this->custodia)($this->arturo))->toBe(15)
        ->and(($this->custodia)($this->colaboradorB))->toBe(5)
        ->and(($this->saldos)())->toBe($saldosAntes)
        ->and(($this->stockJsig)())->toBe(80)
        ->and($entrega->empresa_id)->toBe($this->empresaB->id)
        ->and($entrega->sucursal_id)->toBe($this->sucursalB->id)
        ->and($entrega->almacen_id)->toBeNull()
        ->and($entrega->colaborador_origen_id)->toBe($this->arturo->id)
        ->and($entrega->empresaInventarioId())->toBe($this->jsig->id)
        ->and($this->camisa->fresh()->empresa_id)->toBe($this->jsig->id);
});

it('redistribuye igual hacia la Empresa C', function () {
    $saldosAntes = ($this->saldos)();

    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorC, ($this->camisas)(5))->assertSessionHasNoErrors();

    expect(($this->custodia)($this->arturo))->toBe(15)
        ->and(($this->custodia)($this->colaboradorC))->toBe(5)
        ->and(($this->saldos)())->toBe($saldosAntes)
        ->and(EntregaUniforme::query()->where('colaborador_id', $this->colaboradorC->id)->value('empresa_id'))->toBe($this->empresaC->id);
});

it('el selector de custodia muestra los mismos bienes sea cual sea la empresa destino autorizada', function () {
    foreach ([$this->jsig, $this->empresaB, $this->empresaC] as $empresa) {
        $this->actingAs($this->arturoUsuario)
            ->getJson("/entregas/custodia/activos?empresa_id={$empresa->id}&control=cantidad")
            ->assertOk()
            ->assertJsonPath('activos.0.nombre', 'Camisa')
            ->assertJsonPath('activos.0.tallas.0.disponible', 20);
    }

    $this->actingAs($this->arturoUsuario)
        ->getJson("/entregas/custodia/activos?empresa_id={$this->empresaD->id}&control=cantidad")
        ->assertOk()
        ->assertExactJson(['activos' => []]);
});

it('rechaza una Empresa D no autorizada aunque se manipule el request', function () {
    // Destinatario de D: sin acceso a su empresa → 403, nada se revela.
    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorD, ($this->camisas)(5))->assertForbidden();

    // Empresa D declarada con un destinatario autorizado de B.
    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB, ($this->camisas)(5), [], [
        'empresa_id' => $this->empresaD->id,
        'sucursal_id' => $this->sucursalD->id,
    ])->assertSessionHasErrors(['empresa_id' => 'No tienes acceso a la empresa destino seleccionada.']);

    expect(EntregaUniforme::query()->whereNotNull('colaborador_origen_id')->count())->toBe(0)
        ->and(($this->custodia)($this->arturo))->toBe(20);
});

it('rechaza una sucursal de otra empresa o fuera del alcance del usuario', function () {
    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB, ($this->camisas)(5), [], ['sucursal_id' => $this->sucursalC->id])
        ->assertSessionHasErrors(['sucursal_id' => 'La sucursal no pertenece a la empresa destino seleccionada.']);

    // B2 es de B pero Arturo sólo tiene asignada la sucursal B.
    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB, ($this->camisas)(5), [], ['sucursal_id' => $this->sucursalB2->id])
        ->assertSessionHasErrors(['sucursal_id' => 'No tienes acceso a la sucursal destino seleccionada.']);

    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB2, ($this->camisas)(5))
        ->assertSessionHasErrors(['colaborador_id' => 'No tienes acceso a la sucursal de ese colaborador.']);

    expect(EntregaUniforme::query()->whereNotNull('colaborador_origen_id')->count())->toBe(0);
});

it('omitir empresa_id y sucursal_id no salta el alcance: el destino se deriva del destinatario y se valida igual', function () {
    $sinDestino = ['empresa_id' => null, 'sucursal_id' => null];

    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB2, ($this->camisas)(5), [], $sinDestino)
        ->assertSessionHasErrors(['colaborador_id' => 'No tienes acceso a la sucursal de ese colaborador.']);
    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorD, ($this->camisas)(5), [], $sinDestino)->assertForbidden();

    // Sólo una de las dos tampoco se acepta.
    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB, ($this->camisas)(5), [], ['sucursal_id' => null])
        ->assertSessionHasErrors('sucursal_id');

    expect(EntregaUniforme::query()->whereNotNull('colaborador_origen_id')->count())->toBe(0);

    // Destinatario autorizado sin los campos: válido, destino = el suyo.
    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB, ($this->camisas)(2), [], $sinDestino)->assertSessionHasNoErrors();

    expect(EntregaUniforme::query()->where('colaborador_id', $this->colaboradorB->id)->sole())
        ->empresa_id->toBe($this->empresaB->id)
        ->sucursal_id->toBe($this->sucursalB->id);
});

it('el destinatario debe pertenecer exactamente a la empresa y sucursal seleccionadas', function () {
    // Empresa B / sucursal B seleccionadas, destinatario de C.
    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorC, ($this->camisas)(5), [], [
        'empresa_id' => $this->empresaB->id,
        'sucursal_id' => $this->sucursalB->id,
    ])->assertSessionHasErrors(['colaborador_id' => 'El colaborador no pertenece a la empresa y sucursal destino seleccionadas.']);

    // Misma empresa (JSIG), otra sucursal autorizada.
    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorJsig2, ($this->camisas)(5), [], [
        'sucursal_id' => $this->sucursalJsig->id,
    ])->assertSessionHasErrors(['colaborador_id' => 'El colaborador no pertenece a la empresa y sucursal destino seleccionadas.']);

    expect(EntregaUniforme::query()->whereNotNull('colaborador_origen_id')->count())->toBe(0);
});

it('sin entregas.redistribuir se rechaza aunque tenga custodia y acceso a la empresa destino', function () {
    $sinPermiso = ($this->usuarioConPermisos)(['entregas.ver'], [$this->jsig, $this->empresaB]);
    $this->arturo->update(['usuario_id' => $sinPermiso->id]);

    ($this->redistribuir)($sinPermiso, $this->colaboradorB, ($this->camisas)(5))->assertForbidden();

    expect(($this->custodia)($this->arturo))->toBe(20);
});

it('un rol personalizado con los mismos permisos efectivos redistribuye igual', function () {
    $personalizado = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.redistribuir'], [$this->jsig, $this->empresaB, $this->empresaC]);
    $this->arturo->update(['usuario_id' => $personalizado->id]);

    ($this->redistribuir)($personalizado, $this->colaboradorB, ($this->camisas)(5))->assertSessionHasNoErrors();

    expect(($this->custodia)($this->arturo))->toBe(15)
        ->and(($this->custodia)($this->colaboradorB))->toBe(5);
});

it('redistribuir no concede salida de almacén: sin entregas.crear sigue sin poder sacar stock', function () {
    $saldosAntes = ($this->saldos)();

    $this->actingAs($this->arturoUsuario)->post('/entregas', [
        ...($this->firmas)(),
        'colaborador_id' => $this->colaboradorB->id,
        'almacen_id' => $this->almacenJsig->id,
        'activos' => ($this->camisas)(5),
    ])->assertForbidden();

    expect(($this->saldos)())->toBe($saldosAntes);
});

it('la devolución desde la Empresa B reingresa al inventario de JSIG, en un almacén que abastezca a JSIG', function () {
    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB, ($this->camisas)(5))->assertSessionHasNoErrors();
    $entrega = EntregaUniforme::query()->where('colaborador_id', $this->colaboradorB->id)->sole();
    $detalle = $entrega->detalles()->sole();

    $devolver = fn (Almacen $almacen) => $this->actingAs($this->admin)->post('/devoluciones', [
        'entrega_uniforme_id' => $entrega->id,
        'almacen_id' => $almacen->id,
        'fecha' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'activos' => [['detalle_entrega_id' => $detalle->id, 'cantidad' => 3, 'condicion' => 'reutilizable']],
    ]);

    // El almacén B sólo abastece a B: no es destino válido de bienes de JSIG.
    $devolver($this->almacenB)->assertSessionHasErrors('almacen_id');

    $devolver($this->almacenJsig)->assertSessionHasNoErrors();

    expect(($this->stockJsig)())->toBe(83)
        ->and(SaldoInventario::query()->where('empresa_id', $this->empresaB->id)->exists())->toBeFalse()
        ->and(($this->custodia)($this->colaboradorB))->toBe(2)
        ->and(($this->custodia)($this->arturo))->toBe(15);
});

it('una unidad de JSIG redistribuida a B conserva su propietaria, sigue visible y puede redistribuirse y devolverse', function () {
    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB, [], [['unidad_activo_id' => $this->unidad->id, 'finalidad' => 'redistribucion']])
        ->assertSessionHasNoErrors();

    expect($this->unidad->fresh())
        ->empresa_id->toBe($this->jsig->id)
        ->colaborador_id->toBe($this->colaboradorB->id)
        ->estado->toBe(EstadoUnidadActivo::Asignada);

    // Visible en la custodia de B, aunque su dueña sea JSIG: panel acotado a B
    // y "Mis activos" del propio colaborador.
    $servicio = app(ServicioCustodiaColaborador::class);
    expect(collect($servicio->pendientes($this->colaboradorB, [$this->empresaB->id]))->pluck('unidad_activo_id')->all())->toBe([$this->unidad->id])
        ->and($servicio->totalPiezasPendientes($this->colaboradorB, [$this->empresaB->id]))->toBe(1);

    $usuarioB = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.redistribuir', 'activos.ver-custodia-propia'], [$this->empresaB]);
    $this->colaboradorB->update(['usuario_id' => $usuarioB->id]);

    $this->actingAs($usuarioB)->get('/mis-activos')->assertOk()
        ->assertInertia(fn ($page) => $page->where('redistribuir.0.codigo', $this->unidad->codigo));

    // Redistribuible otra vez desde B: no la bloquea la empresa del custodio.
    $this->actingAs($usuarioB)
        ->getJson("/entregas/custodia/unidades?empresa_id={$this->empresaB->id}&activo_id={$this->celular->id}")
        ->assertOk()
        ->assertJsonPath('unidades.0.codigo', $this->unidad->codigo);

    $otroB = Colaborador::factory()->for($this->empresaB)->for($this->sucursalB)->create();
    ($this->redistribuir)($usuarioB, $otroB, [], [['unidad_activo_id' => $this->unidad->id, 'finalidad' => 'uso_personal']])
        ->assertSessionHasNoErrors();

    expect($this->unidad->fresh())->empresa_id->toBe($this->jsig->id)->colaborador_id->toBe($otroB->id);

    // Devolución: vuelve a un almacén de JSIG, sigue siendo de JSIG.
    $entrega = EntregaUniforme::query()->where('colaborador_id', $otroB->id)->sole();
    $this->actingAs($this->admin)->post('/devoluciones', [
        'entrega_uniforme_id' => $entrega->id,
        'almacen_id' => $this->almacenJsig->id,
        'fecha' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'unidades' => [['detalle_entrega_id' => $entrega->detalles()->value('id'), 'condicion' => 'funcionando']],
    ])->assertSessionHasNoErrors();

    expect($this->unidad->fresh())
        ->empresa_id->toBe($this->jsig->id)
        ->estado->toBe(EstadoUnidadActivo::EnAlmacen)
        ->almacen_id->toBe($this->almacenJsig->id)
        ->colaborador_id->toBeNull();
});

it('rechaza una entrega con bienes de empresas propietarias distintas y no mueve nada', function () {
    // Arturo además recibe una gorra propiedad de B (desde el almacén de B).
    $gorraB = Activo::factory()->for($this->empresaB)->create(['nombre' => 'Gorra B']);
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->empresaB->id,
        almacenId: $this->almacenB->id,
        activoId: $gorraB->id,
        tallaId: null,
        tipo: TipoMovimiento::Inicial,
        cantidad: 10,
    ));
    $deB = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.crear'], [$this->empresaB]);
    $custodioB = Colaborador::factory()->for($this->empresaB)->for($this->sucursalB)->create();
    $this->actingAs($deB)->post('/entregas', [
        ...($this->firmas)(),
        'colaborador_id' => $custodioB->id,
        'almacen_id' => $this->almacenB->id,
        'activos' => [['activo_id' => $gorraB->id, 'talla_id' => null, 'cantidad' => 4, 'finalidad' => 'redistribucion']],
    ])->assertSessionHasNoErrors();

    // El custodio de B redistribuye las 4 gorras a Arturo (destino JSIG):
    // ahora Arturo tiene camisas de JSIG y gorras de B.
    $usuarioB = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.redistribuir'], [$this->empresaB, $this->jsig]);
    $custodioB->update(['usuario_id' => $usuarioB->id]);
    ($this->redistribuir)($usuarioB, $this->arturo, [['activo_id' => $gorraB->id, 'talla_id' => null, 'cantidad' => 4, 'finalidad' => 'redistribucion']])
        ->assertSessionHasNoErrors();

    $saldosAntes = ($this->saldos)();
    $entregasAntes = EntregaUniforme::count();

    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB, [
        ...($this->camisas)(2),
        ['activo_id' => $gorraB->id, 'talla_id' => null, 'cantidad' => 1, 'finalidad' => 'redistribucion'],
    ])->assertSessionHasErrors(['negocio' => 'Una entrega sólo puede incluir bienes de una misma empresa propietaria. Registra los de cada empresa en entregas separadas.']);

    expect(EntregaUniforme::count())->toBe($entregasAntes)
        ->and(($this->saldos)())->toBe($saldosAntes)
        ->and(($this->custodia)($this->arturo))->toBe(24);
});

it('un conjunto de JSIG en custodia se redistribuye a otra empresa sólo si está completo en la custodia real', function () {
    $kit = Conjunto::factory()->for($this->jsig)->create(['nombre' => 'Kit JSIG', 'activo' => true]);
    $kit->componentes()->create(['activo_id' => $this->camisa->id, 'cantidad_requerida' => 2, 'talla_id' => $this->tallaM->id, 'talla_libre' => false]);
    $otroKit = Conjunto::factory()->for($this->jsig)->create(['nombre' => 'Kit no recibido', 'activo' => true]);
    $otroKit->componentes()->create(['activo_id' => $this->camisa->id, 'cantidad_requerida' => 1, 'talla_id' => $this->tallaM->id, 'talla_libre' => false]);

    $this->actingAs($this->admin)->post('/entregas', [
        ...($this->firmas)(),
        'colaborador_id' => $this->arturo->id,
        'almacen_id' => $this->almacenJsig->id,
        'conjuntos' => [['conjunto_id' => $kit->id, 'cantidad' => 1, 'finalidad' => 'redistribucion']],
    ])->assertSessionHasNoErrors();

    $saldosAntes = ($this->saldos)();

    // Un conjunto que Arturo nunca recibió como tal no se ofrece ni se acepta.
    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB, [], [], [
        'conjuntos' => [['conjunto_id' => $otroKit->id, 'cantidad' => 1, 'finalidad' => 'redistribucion']],
    ])->assertSessionHasErrors('conjuntos.0.cantidad');

    ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB, [], [], [
        'conjuntos' => [['conjunto_id' => $kit->id, 'cantidad' => 1, 'finalidad' => 'redistribucion']],
    ])->assertSessionHasNoErrors();

    $entrega = EntregaUniforme::query()->where('colaborador_id', $this->colaboradorB->id)->sole();

    expect($entrega->empresa_id)->toBe($this->empresaB->id)
        ->and($entrega->detalles()->sole()->conjunto_id)->toBe($kit->id)
        ->and(($this->custodia)($this->colaboradorB))->toBe(2)
        ->and(($this->custodia)($this->arturo))->toBe(20)
        ->and(($this->saldos)())->toBe($saldosAntes);
});

/*
 * "Distribución actual del activo" (detalle del activo de JSIG): responde
 * DÓNDE están hoy sus piezas, aunque el custodio sea de otra empresa.
 */
describe('distribución actual del activo con custodia en otra empresa', function () {
    beforeEach(function () {
        $this->empresaB->update(['nombre_comercial' => 'Estrategias']);
        $this->sucursalB->update(['nombre' => 'Legionarios']);
        $this->colaboradorB->update(['nombre_completo' => 'Miguel Aguilar Mondragón']);

        $this->distribucion = fn (Activo $activo): array => $this->actingAs($this->admin)
            ->get("/activos/{$activo->id}")->assertOk()->viewData('page')['props']['distribucion'];

        $this->filaDe = fn (array $distribucion, Colaborador $colaborador): array => collect($distribucion['filas'])
            ->where('custodio.id', $colaborador->id)->values()->all();
    });

    it('muestra a Miguel con sus 2 piezas en el grupo de su finalidad real, sin duplicar', function (string $finalidad, string $grupo) {
        ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB, ($this->camisas)(2, $finalidad))->assertSessionHasNoErrors();

        $distribucion = ($this->distribucion)($this->camisa);
        $miguel = ($this->filaDe)($distribucion, $this->colaboradorB);

        expect($miguel)->toHaveCount(1)
            ->and($miguel[0])->toMatchArray(['grupo' => $grupo, 'talla' => 'M', 'cantidad' => 2])
            ->and($miguel[0]['custodio'])->toMatchArray([
                'nombre_completo' => 'Miguel Aguilar Mondragón',
                'empresa' => 'Estrategias',
                'sucursal' => 'Legionarios',
                'otra_empresa' => true,
            ])
            ->and(($this->filaDe)($distribucion, $this->arturo)[0])->toMatchArray(['grupo' => 'redistribucion', 'cantidad' => 18])
            ->and(($this->filaDe)($distribucion, $this->arturo)[0]['custodio']['otra_empresa'])->toBeFalse()
            ->and($distribucion['totales'][$grupo])->toBe($grupo === 'redistribucion' ? 20 : 2)
            // Almacén + custodias vigentes = existencia lógica (100 iniciales).
            ->and(array_sum($distribucion['totales']))->toBe(100)
            ->and($distribucion['totales']['almacen'])->toBe(80)
            ->and($this->camisa->fresh()->empresa_id)->toBe($this->jsig->id);
    })->with([
        'uso personal' => ['uso_personal', 'uso_personal'],
        'para redistribuir' => ['redistribucion', 'redistribucion'],
    ]);

    it('un renglón histórico sin finalidad cuenta en "Sin clasificar"', function () {
        ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB, ($this->camisas)(2))->assertSessionHasNoErrors();
        EntregaUniforme::query()->where('colaborador_id', $this->colaboradorB->id)->sole()->detalles()->update(['finalidad' => null]);

        $distribucion = ($this->distribucion)($this->camisa);

        expect(($this->filaDe)($distribucion, $this->colaboradorB)[0])->toMatchArray(['grupo' => 'sin_clasificar', 'cantidad' => 2])
            ->and($distribucion['totales'])->toBe(['almacen' => 80, 'uso_personal' => 0, 'redistribucion' => 18, 'sin_clasificar' => 2]);
    });

    it('una unidad de JSIG asignada a Miguel figura con su custodio real y sin cambiar de dueña', function () {
        ($this->redistribuir)($this->arturoUsuario, $this->colaboradorB, [], [['unidad_activo_id' => $this->unidad->id, 'finalidad' => 'uso_personal']])
            ->assertSessionHasNoErrors();

        $distribucion = ($this->distribucion)($this->celular);

        expect($distribucion['unidades'])->toHaveCount(1)
            ->and($distribucion['unidades'][0])->toMatchArray(['codigo' => $this->unidad->codigo, 'grupo' => 'uso_personal'])
            ->and($distribucion['unidades'][0]['custodio'])->toMatchArray([
                'id' => $this->colaboradorB->id,
                'empresa' => 'Estrategias',
                'sucursal' => 'Legionarios',
                'otra_empresa' => true,
            ])
            ->and($distribucion['totales'])->toBe(['almacen' => 0, 'uso_personal' => 1, 'redistribucion' => 0, 'sin_clasificar' => 0])
            ->and($this->unidad->fresh()->empresa_id)->toBe($this->jsig->id);
    });
});
