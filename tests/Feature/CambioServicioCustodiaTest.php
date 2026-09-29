<?php

use App\Acciones\RedistribuirCustodia;
use App\Acciones\RegistrarDevolucionFirmada;
use App\Enums\CondicionDevolucion;
use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\BitacoraAuditoria;
use App\Models\CambioServicioColaborador;
use App\Models\Colaborador;
use App\Models\Contrato;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use App\Models\Servicio;
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
 * Cambio de servicio con custodia: se revisa bien por bien qué se MANTIENE
 * con el colaborador, qué se DEVUELVE (devolución real firmada) y qué se
 * REDISTRIBUYE (redistribución real firmada), y el cambio sólo se completa
 * cuando la realidad coincide con lo decidido.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    // Quien gestiona el cambio: sólo permisos efectivos.
    $this->usuarioConPermisos = function (array $permisos): User {
        $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        return tap(User::factory()->create(), function (User $u) use ($rol): void {
            $u->assignRole($rol);
            $u->empresas()->sync([$this->datos['empresaA']->id]);
        });
    };
    $this->gestor = ($this->usuarioConPermisos)(['colaboradores.ver', 'colaboradores.editar', 'entregas.ver', 'entregas.redistribuir', 'devoluciones.crear']);

    $contrato = Contrato::factory()->for($this->datos['empresaA'])->create();
    $this->palmira = Servicio::factory()->for($contrato)->for($this->datos['sucursalA'])->create(['nombre' => 'Palmira']);
    $this->cuernavaca = Servicio::factory()->for($contrato)->for($this->datos['sucursalA'])->create(['nombre' => 'Cuernavaca']);

    $this->yatziri = $this->datos['colaboradorA'];
    $this->yatziri->update(['servicio_actual_id' => $this->palmira->id]);
    $this->carolina = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create(['nombre_completo' => 'Carolina Responsable']);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 20,
    ));

    $unidadDe = function (string $nombre): UnidadActivo {
        $activo = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => $nombre]);

        return UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activo)->for($this->datos['almacenA'])->create();
    };
    $this->laptop = $unidadDe('Laptop');
    $this->microondas = $unidadDe('Microondas');
    $this->radio = $unidadDe('Radio');

    $this->firmas = fn (): array => [
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'idempotency_key' => (string) Str::uuid(),
    ];

    $this->actingAs($this->admin)->post('/entregas', [
        ...($this->firmas)(),
        'colaborador_id' => $this->yatziri->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 6]],
        'unidades' => [['unidad_activo_id' => $this->laptop->id], ['unidad_activo_id' => $this->microondas->id], ['unidad_activo_id' => $this->radio->id]],
    ])->assertSessionHasNoErrors();
    $this->entregaOriginal = EntregaUniforme::sole();

    $this->iniciar = fn (?int $servicioId, ?User $usuario = null) => $this->actingAs($usuario ?? $this->gestor)
        ->post("/colaboradores/{$this->yatziri->id}/cambios-servicio", ['servicio_id' => $servicioId, 'motivo' => 'Rotación']);

    $this->cambio = fn (): CambioServicioColaborador => CambioServicioColaborador::query()->latest('id')->firstOrFail();

    /** Decisiones por código de unidad / 'camisas' => [mantener, devolver, redistribuir]. */
    $this->decidir = function (array $porBien, ?int $destinatarioId = null) {
        $cambio = ($this->cambio)();
        $renglones = [];
        foreach ($cambio->renglones as $r) {
            [$mantener, $devolver, $redistribuir] = $porBien[$r->unidad_codigo_snapshot ?? 'camisas'];
            $renglones[$r->id] = ['mantener' => $mantener, 'devolver' => $devolver, 'redistribuir' => $redistribuir, 'destinatario_id' => $redistribuir > 0 ? $destinatarioId : null];
        }

        return $this->actingAs($this->gestor)->put("/cambios-servicio/{$cambio->id}/decisiones", ['renglones' => $renglones]);
    };

    $this->mantenerTodo = fn () => ($this->decidir)([
        'camisas' => [6, 0, 0],
        $this->laptop->codigo => [1, 0, 0],
        $this->microondas->codigo => [1, 0, 0],
        $this->radio->codigo => [1, 0, 0],
    ]);

    $this->completar = fn () => $this->actingAs($this->gestor)->post('/cambios-servicio/'.($this->cambio)()->id.'/completar');

    $this->stock = fn (): int => (int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad');
    $this->camisasDe = fn (Colaborador $c): int => array_sum(array_column(app(ServicioCustodiaColaborador::class)->cantidadesRedistribuibles($c->fresh()), 'disponible'));
});

it('un colaborador sin custodia cambia de servicio de inmediato, sin revisión', function () {
    $this->actingAs($this->gestor)
        ->post("/colaboradores/{$this->carolina->id}/cambios-servicio", ['servicio_id' => $this->cuernavaca->id])
        ->assertSessionHasNoErrors();

    expect($this->carolina->fresh()->servicio_actual_id)->toBe($this->cuernavaca->id)
        ->and(CambioServicioColaborador::count())->toBe(0);
});

it('con custodia no cambia sin revisión: abre la revisión con cada bien y conserva el servicio', function () {
    ($this->iniciar)($this->cuernavaca->id)->assertRedirect('/cambios-servicio/'.($this->cambio)()->id);

    expect($this->yatziri->fresh()->servicio_actual_id)->toBe($this->palmira->id)
        ->and(($this->cambio)()->renglones)->toHaveCount(4);

    // El camino directo tampoco lo permite.
    $this->actingAs($this->gestor)
        ->post("/colaboradores/{$this->yatziri->id}/servicio", ['servicio_id' => $this->cuernavaca->id])
        ->assertSessionHasErrors('negocio');

    ($this->completar)()->assertSessionHasErrors('negocio');
    expect($this->yatziri->fresh()->servicio_actual_id)->toBe($this->palmira->id);
});

it('mantener todo: los bienes siguen con el mismo custodio y pasan a derivar del nuevo servicio', function () {
    ($this->iniciar)($this->cuernavaca->id);
    ($this->mantenerTodo)()->assertSessionHasNoErrors();

    ($this->completar)()->assertSessionHasNoErrors()->assertRedirect("/colaboradores/{$this->yatziri->id}");

    $this->laptop->refresh()->load('colaborador.servicioActual.contrato');
    expect($this->yatziri->fresh()->servicio_actual_id)->toBe($this->cuernavaca->id)
        ->and($this->laptop->colaborador_id)->toBe($this->yatziri->id)
        ->and($this->laptop->ubicacionOperativa()['servicio'] ?? null)->toBe('Cuernavaca')
        ->and(($this->camisasDe)($this->yatziri))->toBe(6)
        ->and(($this->cambio)()->estado)->toBe(CambioServicioColaborador::COMPLETADO);
});

it('resuelve mantener, devolver y redistribuir por cantidades parciales y por unidad, sin tocar stock al redistribuir', function () {
    ($this->iniciar)($this->cuernavaca->id);
    ($this->decidir)([
        'camisas' => [2, 2, 2],
        $this->laptop->codigo => [1, 0, 0],
        $this->microondas->codigo => [0, 0, 1],
        $this->radio->codigo => [0, 1, 0],
    ], $this->carolina->id)->assertSessionHasNoErrors();

    // Todavía falta registrar/firmar la devolución y la redistribución.
    ($this->completar)()->assertSessionHasErrors('negocio');

    $detalleCamisas = $this->entregaOriginal->detalles()->whereNull('unidad_activo_id')->value('id');
    $detalleRadio = $this->entregaOriginal->detalles()->where('unidad_activo_id', $this->radio->id)->value('id');
    app(RegistrarDevolucionFirmada::class)->ejecutar(
        $this->entregaOriginal->id, $this->datos['almacenA']->id, now()->toDateString(),
        [['detalle_entrega_id' => $detalleCamisas, 'cantidad' => 2, 'condicion' => CondicionDevolucion::Reutilizable->value]],
        [['detalle_entrega_id' => $detalleRadio, 'condicion' => CondicionUnidadActivo::Funcionando->value]],
        $this->gestor->id, 'Cambio de servicio', null, firmaDemoBase64(), firmaDemoBase64(), true, null, null,
    );
    expect(($this->stock)())->toBe(16);

    $cambio = ($this->cambio)();
    $this->actingAs($this->gestor)->post('/entregas', [
        ...($this->firmas)(),
        'origen' => 'custodia',
        'cambio_servicio_id' => $cambio->id,
        'colaborador_id' => $this->carolina->id,
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
        'unidades' => [['unidad_activo_id' => $this->microondas->id]],
    ])->assertSessionHasNoErrors()->assertRedirect("/cambios-servicio/{$cambio->id}");

    expect(($this->stock)())->toBe(16);

    ($this->completar)()->assertSessionHasNoErrors();

    expect($this->yatziri->fresh()->servicio_actual_id)->toBe($this->cuernavaca->id)
        ->and(($this->camisasDe)($this->yatziri))->toBe(2)
        ->and(($this->camisasDe)($this->carolina))->toBe(2)
        ->and($this->microondas->fresh()->colaborador_id)->toBe($this->carolina->id)
        ->and($this->radio->fresh()->estado)->toBe(EstadoUnidadActivo::EnAlmacen)
        ->and($this->laptop->fresh()->colaborador_id)->toBe($this->yatziri->id);

    $auditoria = BitacoraAuditoria::query()->where('accion', 'cambiar_servicio')->latest('id')->firstOrFail();
    expect($auditoria->descripcion)->toContain('Palmira → Cuernavaca')
        ->and($auditoria->valores_nuevos['custodia_mantenida'])->toContain('Camisa talla M x2', 'Laptop '.$this->laptop->codigo)
        ->and(implode(' ', $auditoria->valores_nuevos['custodia_devuelta']))->toContain('Camisa talla M x2', 'Radio '.$this->radio->codigo)
        ->and(implode(' ', $auditoria->valores_nuevos['custodia_redistribuida']))->toContain('Microondas '.$this->microondas->codigo.' → Carolina Responsable');
});

it('pasa a "sin servicio" después de resolver la custodia', function () {
    ($this->iniciar)(null);
    ($this->mantenerTodo)();
    ($this->completar)()->assertSessionHasNoErrors();

    expect($this->yatziri->fresh()->servicio_actual_id)->toBeNull();
});

it('de "sin servicio" a un servicio también exige revisar la custodia', function () {
    $this->yatziri->update(['servicio_actual_id' => null]);

    ($this->iniciar)($this->palmira->id);

    expect($this->yatziri->fresh()->servicio_actual_id)->toBeNull()
        ->and(($this->cambio)()->estaPendiente())->toBeTrue();
});

it('rechaza repartos que no cuadran con lo revisado', function () {
    ($this->iniciar)($this->cuernavaca->id);
    $renglon = ($this->cambio)()->renglones->firstWhere('unidad_activo_id', null);

    $this->actingAs($this->gestor)
        ->put('/cambios-servicio/'.($this->cambio)()->id.'/decisiones', ['renglones' => [
            $renglon->id => ['mantener' => 3, 'devolver' => 2, 'redistribuir' => 2, 'destinatario_id' => $this->carolina->id],
        ]])
        ->assertSessionHasErrors(["renglones.{$renglon->id}.mantener" => 'Reparte exactamente 6 entre mantener, devolver y redistribuir.']);

    expect($renglon->fresh()->cantidad_mantener)->toBe(0);
});

it('rechaza renglones ajenos a la revisión y destinatarios fuera de la empresa', function () {
    ($this->iniciar)($this->cuernavaca->id);
    $cambio = ($this->cambio)();
    $ajeno = Colaborador::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->create();
    $renglon = $cambio->renglones->firstWhere('unidad_activo_id', $this->microondas->id);

    $this->actingAs($this->gestor)
        ->put("/cambios-servicio/{$cambio->id}/decisiones", ['renglones' => [999999 => ['mantener' => 1, 'devolver' => 0, 'redistribuir' => 0]]])
        ->assertSessionHasErrors('renglones.999999');

    $this->actingAs($this->gestor)
        ->put("/cambios-servicio/{$cambio->id}/decisiones", ['renglones' => [
            $renglon->id => ['mantener' => 0, 'devolver' => 0, 'redistribuir' => 1, 'destinatario_id' => $ajeno->id],
        ]])
        ->assertSessionHasErrors("renglones.{$renglon->id}.destinatario_id");
});

it('no completa con una revisión obsoleta: si la custodia cambió después, se rechaza', function () {
    ($this->iniciar)($this->cuernavaca->id);
    ($this->mantenerTodo)();

    // Otra operación redistribuye el microondas después de la revisión.
    app(RedistribuirCustodia::class)->ejecutar($this->yatziri->id, $this->carolina->id, $this->admin->id, now()->toDateString(), [], [['unidad_activo_id' => $this->microondas->id]]);

    ($this->completar)()->assertSessionHasErrors('negocio');

    expect($this->yatziri->fresh()->servicio_actual_id)->toBe($this->palmira->id)
        ->and(($this->cambio)()->estaPendiente())->toBeTrue();
});

it('sin permiso para devolver ni redistribuir la pantalla lo indica y la redistribución en su nombre se niega', function () {
    $soloEditar = ($this->usuarioConPermisos)(['colaboradores.ver', 'colaboradores.editar', 'entregas.ver', 'entregas.crear']);
    ($this->iniciar)($this->cuernavaca->id, $soloEditar);
    $cambio = ($this->cambio)();

    $this->actingAs($soloEditar)->get("/cambios-servicio/{$cambio->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permisos.gestionar', true)
            ->where('permisos.devolver', false)
            ->where('permisos.redistribuir', false));

    $this->actingAs($soloEditar)->get("/entregas/crear?cambio_servicio={$cambio->id}")->assertForbidden();
});

it('el acceso "Ir a Devoluciones" del diálogo sólo aplica con custodia y con permiso para registrar devoluciones', function () {
    // Sin custodia: el diálogo no recibe resumen, así que no hay acceso especial.
    $this->actingAs($this->gestor)
        ->getJson("/colaboradores/{$this->carolina->id}/custodia")
        ->assertOk()
        ->assertJsonPath('tiene_pendientes', false)
        ->assertJsonPath('resumen', []);

    // Con custodia y `devoluciones.crear`: se ofrece el acceso.
    $this->actingAs($this->gestor)
        ->getJson("/colaboradores/{$this->yatziri->id}/custodia")
        ->assertJsonPath('tiene_pendientes', true);
    $this->actingAs($this->gestor)->get("/colaboradores/{$this->yatziri->id}")
        ->assertInertia(fn ($page) => $page->where('puedeRegistrarDevoluciones', true));

    // Con custodia pero sin `devoluciones.crear`: no se ofrece (evita un 403).
    $sinDevoluciones = ($this->usuarioConPermisos)(['colaboradores.ver', 'colaboradores.editar']);
    $this->actingAs($sinDevoluciones)->get("/colaboradores/{$this->yatziri->id}")
        ->assertInertia(fn ($page) => $page->where('puedeRegistrarDevoluciones', false));
    $this->actingAs($sinDevoluciones)->get("/devoluciones/crear?colaborador_id={$this->yatziri->id}")->assertForbidden();

    // "Revisar custodia y continuar" sigue abriendo la revisión, sin cambiar el servicio.
    ($this->iniciar)($this->cuernavaca->id, $sinDevoluciones)->assertRedirect('/cambios-servicio/'.($this->cambio)()->id);
    expect($this->yatziri->fresh()->servicio_actual_id)->toBe($this->palmira->id);
});
