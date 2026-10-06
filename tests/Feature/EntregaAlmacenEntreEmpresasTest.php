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
 * Entrega DIRECTA desde almacén a un colaborador de OTRA empresa: Yatziry
 * (`entregas.crear`, autorizada en INMAG y JSIG) entrega a Wilberth (INMAG)
 * camisas propiedad de JSIG desde un almacén de JSIG.
 * - DESTINO: empresa/sucursal laboral de Wilberth (`entrega.empresa_id`);
 * - ORIGEN: empresa propietaria del inventario (`empresa_inventario_id`) +
 *   almacén que la abastece; el stock que baja es el de JSIG;
 * - PROPIEDAD: `activos.empresa_id` / `unidades_activo.empresa_id` no cambian.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->jsig = tap($this->datos['empresaA'])->update(['nombre_comercial' => 'JSIG']);
    $this->inmag = tap($this->datos['empresaB'])->update(['nombre_comercial' => 'INMAG']);
    $this->almacenJsig = $this->datos['almacenA'];
    $this->almacenInmag = $this->datos['almacenB'];
    $this->camisa = $this->datos['activoA'];
    $this->tallaM = $this->datos['tallaA'];

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->jsig->id,
        almacenId: $this->almacenJsig->id,
        activoId: $this->camisa->id,
        tallaId: $this->tallaM->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 50,
    ));

    $this->wilberth = Colaborador::factory()->for($this->inmag)->for($this->datos['sucursalB'])->create(['nombre_completo' => 'Wilberth INMAG']);

    $this->usuarioConPermisos = function (array $permisos, array $empresas): User {
        $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        return tap(User::factory()->create(), function (User $u) use ($rol, $empresas): void {
            $u->assignRole($rol);
            $u->empresas()->sync(collect($empresas)->pluck('id'));
        });
    };

    // Yatziry: rol personalizado sólo con permisos efectivos.
    $this->yatziry = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.crear'], [$this->inmag, $this->jsig]);
    $this->admin = usuarioCon(RolSistema::Administrador->value);

    $this->camisas = fn (int $cantidad, string $finalidad = 'uso_personal'): array => [
        ['activo_id' => $this->camisa->id, 'talla_id' => $this->tallaM->id, 'cantidad' => $cantidad, 'finalidad' => $finalidad],
    ];

    $this->entregar = fn (User $usuario, array $extra = [], ?Colaborador $destino = null) => $this->actingAs($usuario)->post('/entregas', [
        'colaborador_id' => ($destino ?? $this->wilberth)->id,
        'empresa_inventario_id' => $this->jsig->id,
        'almacen_id' => $this->almacenJsig->id,
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'idempotency_key' => (string) Str::uuid(),
        'activos' => ($this->camisas)(5),
        ...$extra,
    ]);

    $this->stock = fn (Empresa $empresa, Almacen $almacen, ?Activo $activo = null): int => (int) SaldoInventario::query()
        ->where('empresa_id', $empresa->id)
        ->where('almacen_id', $almacen->id)
        ->where('activo_id', ($activo ?? $this->camisa)->id)
        ->value('cantidad');

    $this->custodia = fn (Colaborador $colaborador): int => array_sum(array_column(
        app(ServicioCustodiaColaborador::class)->cantidadesRedistribuibles($colaborador->fresh(), true),
        'disponible',
    ));

    $this->sinEntregas = fn () => expect(EntregaUniforme::count())->toBe(0)
        ->and(($this->stock)($this->jsig, $this->almacenJsig))->toBe(50);
});

it('entrega camisas de JSIG a Wilberth de INMAG: baja el stock de JSIG, la entrega es de INMAG y la propiedad no cambia', function () {
    ($this->entregar)($this->yatziry)->assertSessionHasNoErrors();

    $entrega = EntregaUniforme::sole();

    expect(($this->stock)($this->jsig, $this->almacenJsig))->toBe(45)
        ->and(SaldoInventario::query()->where('empresa_id', $this->inmag->id)->exists())->toBeFalse()
        ->and(($this->custodia)($this->wilberth))->toBe(5)
        ->and($this->camisa->fresh()->empresa_id)->toBe($this->jsig->id)
        ->and($this->wilberth->fresh()->empresa_id)->toBe($this->inmag->id)
        ->and($entrega->empresa_id)->toBe($this->inmag->id)
        ->and($entrega->sucursal_id)->toBe($this->datos['sucursalB']->id)
        ->and($entrega->almacen_id)->toBe($this->almacenJsig->id)
        ->and($entrega->empresaInventarioId())->toBe($this->jsig->id)
        ->and(MovimientoInventario::query()->where('referencia_id', $entrega->id)->where('tipo', TipoMovimiento::Entrega)->sole()->empresa_id)->toBe($this->jsig->id);
});

it('sin empresa propietaria explícita el origen sigue siendo la empresa del colaborador (no hay atajo hacia otra empresa)', function () {
    ($this->entregar)($this->yatziry, ['empresa_inventario_id' => null])
        ->assertSessionHasErrors(['almacen_id', 'activos.0.activo_id']);

    ($this->sinEntregas)();
});

it('sin acceso a JSIG no puede usar su inventario aunque sí pueda entregar a Wilberth', function () {
    $soloInmag = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.crear'], [$this->inmag]);

    ($this->entregar)($soloInmag)->assertSessionHasErrors(['empresa_inventario_id' => 'No tienes acceso a la empresa propietaria seleccionada.']);

    ($this->sinEntregas)();
});

it('rechaza una empresa propietaria inexistente enviada manipulando el request', function () {
    ($this->entregar)($this->yatziry, ['empresa_inventario_id' => 999999])
        ->assertSessionHasErrors(['empresa_inventario_id' => 'No tienes acceso a la empresa propietaria seleccionada.']);

    ($this->sinEntregas)();
});

it('rechaza un almacén que no abastece a la empresa propietaria o que está inactivo', function () {
    // Almacén de INMAG con empresa propietaria JSIG.
    ($this->entregar)($this->yatziry, ['almacen_id' => $this->almacenInmag->id])->assertSessionHasErrors('almacen_id');

    // Almacén de una empresa fuera del alcance de Yatziry.
    $ajena = Empresa::factory()->create();
    $almacenAjeno = Almacen::factory()->paraEmpresa($ajena)->create();
    ($this->entregar)($this->yatziry, ['almacen_id' => $almacenAjeno->id])->assertSessionHasErrors('almacen_id');

    // Almacén de JSIG desactivado: ya no se puede operar.
    $this->almacenJsig->update(['activo' => false]);
    ($this->entregar)($this->yatziry)->assertSessionHasErrors('negocio');

    expect(EntregaUniforme::count())->toBe(0);
});

it('rechaza un activo de INMAG enviado con empresa propietaria JSIG, y no mezcla propietarias en una entrega', function () {
    $pantalonInmag = Activo::factory()->for($this->inmag)->create(['nombre' => 'Pantalón INMAG']);
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->inmag->id, almacenId: $this->almacenInmag->id, activoId: $pantalonInmag->id,
        tallaId: null, tipo: TipoMovimiento::Inicial, cantidad: 10,
    ));
    $pantalon = ['activo_id' => $pantalonInmag->id, 'talla_id' => null, 'cantidad' => 2, 'finalidad' => 'uso_personal'];

    ($this->entregar)($this->yatziry, ['activos' => [$pantalon]])->assertSessionHasErrors('activos.0.activo_id');

    ($this->entregar)($this->yatziry, ['activos' => [...($this->camisas)(5), $pantalon]])->assertSessionHasErrors('activos.1.activo_id');

    ($this->entregar)($this->yatziry, [
        'empresa_inventario_id' => $this->inmag->id,
        'almacen_id' => $this->almacenInmag->id,
        'activos' => [...($this->camisas)(5), $pantalon],
    ])->assertSessionHasErrors('activos.0.activo_id');

    ($this->sinEntregas)();
    expect(($this->stock)($this->inmag, $this->almacenInmag, $pantalonInmag))->toBe(10);
});

it('una unidad de JSIG entregada a Wilberth conserva su propietaria, se ve en su custodia y en la distribución, y vuelve a JSIG', function () {
    $laptop = Activo::factory()->for($this->jsig)->seguimientoIndividual()->create(['nombre' => 'Laptop']);
    $unidad = UnidadActivo::factory()->for($this->jsig, 'empresa')->for($laptop)->for($this->almacenJsig)->create();

    ($this->entregar)($this->yatziry, ['activos' => [], 'unidades' => [['unidad_activo_id' => $unidad->id, 'finalidad' => 'redistribucion']]])
        ->assertSessionHasNoErrors();

    expect($unidad->fresh())
        ->empresa_id->toBe($this->jsig->id)
        ->colaborador_id->toBe($this->wilberth->id)
        ->estado->toBe(EstadoUnidadActivo::Asignada);

    $cuentaWilberth = ($this->usuarioConPermisos)(['activos.ver-custodia-propia', 'entregas.ver', 'entregas.redistribuir'], [$this->inmag]);
    $this->wilberth->update(['usuario_id' => $cuentaWilberth->id]);

    $this->actingAs($cuentaWilberth)->get('/mis-activos')->assertOk()
        ->assertInertia(fn ($page) => $page->where('redistribuir.0.codigo', $unidad->codigo));

    $distribucion = $this->actingAs($this->admin)->get("/activos/{$laptop->id}")->assertOk()->viewData('page')['props']['distribucion'];
    expect($distribucion['unidades'][0]['custodio'])->toMatchArray(['id' => $this->wilberth->id, 'empresa' => 'INMAG', 'otra_empresa' => true]);

    // Redistribuible después (su finalidad lo permite).
    $this->actingAs($cuentaWilberth)
        ->getJson("/entregas/custodia/unidades?empresa_id={$this->inmag->id}&activo_id={$laptop->id}")
        ->assertOk()
        ->assertJsonPath('unidades.0.codigo', $unidad->codigo);

    $entrega = EntregaUniforme::sole();
    $this->actingAs($this->admin)->post('/devoluciones', [
        'entrega_uniforme_id' => $entrega->id,
        'almacen_id' => $this->almacenJsig->id,
        'fecha' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'unidades' => [['detalle_entrega_id' => $entrega->detalles()->value('id'), 'condicion' => 'funcionando']],
    ])->assertSessionHasNoErrors();

    expect($unidad->fresh())
        ->empresa_id->toBe($this->jsig->id)
        ->estado->toBe(EstadoUnidadActivo::EnAlmacen)
        ->almacen_id->toBe($this->almacenJsig->id);
});

it('un conjunto de JSIG se entrega a Wilberth descontando el stock de JSIG', function () {
    $kit = Conjunto::factory()->for($this->jsig)->create(['nombre' => 'Kit JSIG', 'activo' => true]);
    $kit->componentes()->create(['activo_id' => $this->camisa->id, 'cantidad_requerida' => 2, 'talla_id' => $this->tallaM->id, 'talla_libre' => false]);

    ($this->entregar)($this->yatziry, ['activos' => [], 'conjuntos' => [['conjunto_id' => $kit->id, 'cantidad' => 3, 'finalidad' => 'uso_personal']]])
        ->assertSessionHasNoErrors();

    $entrega = EntregaUniforme::sole();

    expect(($this->stock)($this->jsig, $this->almacenJsig))->toBe(44)
        ->and($entrega->empresa_id)->toBe($this->inmag->id)
        ->and($entrega->detalles()->sole()->conjunto_id)->toBe($kit->id)
        ->and(($this->custodia)($this->wilberth))->toBe(6);
});

it('el rol base Encargado con el mismo alcance funciona igual que el rol personalizado', function () {
    $encargado = usuarioCon(RolSistema::Encargado->value, [$this->inmag, $this->jsig]);

    ($this->entregar)($encargado)->assertSessionHasNoErrors();

    expect(($this->stock)($this->jsig, $this->almacenJsig))->toBe(45);
});

it('sin entregas.crear responde 403 y no mueve stock', function () {
    $sinPermiso = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.redistribuir'], [$this->inmag, $this->jsig]);

    ($this->entregar)($sinPermiso)->assertForbidden();

    ($this->sinEntregas)();
});

it('lo recibido desde otra empresa propietaria se puede redistribuir después sin tocar el almacén', function () {
    ($this->entregar)($this->yatziry, ['activos' => ($this->camisas)(5, 'redistribucion')])->assertSessionHasNoErrors();

    $cuentaWilberth = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.redistribuir'], [$this->inmag]);
    $this->wilberth->update(['usuario_id' => $cuentaWilberth->id]);
    $companero = Colaborador::factory()->for($this->inmag)->for($this->datos['sucursalB'])->create();

    $this->actingAs($cuentaWilberth)->post('/entregas', [
        'origen' => 'custodia',
        'colaborador_id' => $companero->id,
        'empresa_id' => $this->inmag->id,
        'sucursal_id' => $this->datos['sucursalB']->id,
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'activos' => ($this->camisas)(2),
    ])->assertSessionHasNoErrors();

    expect(($this->custodia)($this->wilberth))->toBe(3)
        ->and(($this->custodia)($companero))->toBe(2)
        ->and(($this->stock)($this->jsig, $this->almacenJsig))->toBe(45);
});

it('reservas: dos usuarios sobre el mismo stock de JSIG nunca sobrevenden', function () {
    $otraEncargada = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.crear'], [$this->inmag, $this->jsig]);
    $reservar = fn (User $usuario, string $token, int $cantidad) => $this->actingAs($usuario)->postJson('/entregas/reserva', [
        'token' => $token,
        'empresa_id' => $this->jsig->id,
        'almacen_id' => $this->almacenJsig->id,
        'colaborador_id' => $this->wilberth->id,
        'activos' => ($this->camisas)($cantidad),
    ])->assertOk();

    $tokenYatziry = (string) Str::uuid();
    $reservar($this->yatziry, $tokenYatziry, 48)->assertJsonPath('ok', true);
    $reservar($otraEncargada, (string) Str::uuid(), 5)->assertJsonPath('ok', false);

    // Apartar no descuenta stock.
    expect(($this->stock)($this->jsig, $this->almacenJsig))->toBe(50);

    ($this->entregar)($this->yatziry, ['activos' => ($this->camisas)(48), 'reserva_token' => $tokenYatziry])->assertSessionHasNoErrors();
    ($this->entregar)($otraEncargada)->assertSessionHasErrors('activos.0.cantidad');

    expect(($this->stock)($this->jsig, $this->almacenJsig))->toBe(2)
        ->and(EntregaUniforme::count())->toBe(1);
});

it('la devolución de Wilberth regresa al stock de JSIG, nunca al de INMAG', function () {
    ($this->entregar)($this->yatziry)->assertSessionHasNoErrors();
    $entrega = EntregaUniforme::sole();

    $devolver = fn (Almacen $almacen) => $this->actingAs($this->admin)->post('/devoluciones', [
        'entrega_uniforme_id' => $entrega->id,
        'almacen_id' => $almacen->id,
        'fecha' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'activos' => [['detalle_entrega_id' => $entrega->detalles()->value('id'), 'cantidad' => 2, 'condicion' => 'reutilizable']],
    ]);

    $devolver($this->almacenInmag)->assertSessionHasErrors('almacen_id');
    $devolver($this->almacenJsig)->assertSessionHasNoErrors();

    expect(($this->stock)($this->jsig, $this->almacenJsig))->toBe(47)
        ->and(SaldoInventario::query()->where('empresa_id', $this->inmag->id)->exists())->toBeFalse()
        ->and(($this->custodia)($this->wilberth))->toBe(3);
});

it('la distribución actual del activo de JSIG muestra a Wilberth como custodio', function () {
    ($this->entregar)($this->yatziry)->assertSessionHasNoErrors();

    $distribucion = $this->actingAs($this->admin)->get("/activos/{$this->camisa->id}")->assertOk()->viewData('page')['props']['distribucion'];
    $fila = collect($distribucion['filas'])->firstWhere('custodio.id', $this->wilberth->id);

    expect($fila)->toMatchArray(['grupo' => 'uso_personal', 'cantidad' => 5])
        ->and($fila['custodio'])->toMatchArray(['empresa' => 'INMAG', 'otra_empresa' => true])
        ->and($distribucion['totales']['almacen'])->toBe(45)
        ->and(array_sum($distribucion['totales']))->toBe(50);
});
