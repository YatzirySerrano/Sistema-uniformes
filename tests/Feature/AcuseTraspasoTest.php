<?php

use App\Acciones\RegistrarTraspasoFirmado;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocio;
use App\Models\AcuseTraspaso;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\TraspasoInventario;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Firma obligatoria + acuse/PDF de Traspasos: un traspaso NUNCA existe sin su
 * firma (nace ya firmado, en UNA operación atómica) y el comprobante es
 * privado (misma Policy que ver el traspaso: acceso a AMBAS empresas).
 */
beforeEach(function () {
    Storage::fake('local');
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);
    $this->almacenA2 = Almacen::factory()->paraEmpresa($this->datos['empresaA'])->create(['nombre' => 'Almacén A-2']);
    $this->inventario = app(ServicioInventario::class);

    $this->cargarStock = function (int $empresaId, int $almacenId, int $activoId, ?int $tallaId, int $cantidad): void {
        $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
            empresaId: $empresaId, almacenId: $almacenId, activoId: $activoId, tallaId: $tallaId,
            tipo: TipoMovimiento::Inicial, cantidad: $cantidad,
        ));
    };

    $this->payloadValido = fn (array $overrides = []): array => array_merge([
        'empresa_origen_id' => $this->datos['empresaA']->id,
        'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaA']->id,
        'almacen_destino_id' => $this->almacenA2->id,
        'renglones' => [[
            'control' => 'cantidad',
            'activo_origen_id' => $this->datos['activoA']->id,
            'talla_id' => $this->datos['tallaA']->id,
            'cantidad' => 10,
        ]],
    ], $overrides);
});

// ---------------------------------------------------------------------------
// Firma obligatoria
// ---------------------------------------------------------------------------
it('sin firma, el endpoint rechaza el traspaso y no mueve nada', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', ($this->payloadValido)())
        ->assertSessionHasErrors('firma');

    expect(TraspasoInventario::count())->toBe(0)
        ->and(AcuseTraspaso::count())->toBe(0)
        ->and($this->inventario->saldoActual($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(100);
});

it('con una firma vacía/inválida, la acción rechaza y no crea traspaso ni acuse', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    expect(fn () => app(RegistrarTraspasoFirmado::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['empresaA']->id, $this->almacenA2->id,
        [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 10]],
        $this->admin, 'data:image/png;base64,AAAA', null, null, null, null,
    ))->toThrow(ExcepcionDeNegocio::class);

    expect(TraspasoInventario::count())->toBe(0)
        ->and(AcuseTraspaso::count())->toBe(0)
        ->and($this->inventario->saldoActual($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(100);
});

it('con firma válida, el traspaso se confirma y firma en una sola operación, con el responsable correcto', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    $respuesta = $this->actingAs($this->admin)
        ->post('/inventario/traspasos', array_merge(($this->payloadValido)(), ['firma' => firmaDemoBase64()]));

    $traspaso = TraspasoInventario::firstOrFail();
    $respuesta->assertRedirect(route('inventario.traspasos.show', $traspaso));

    $acuse = AcuseTraspaso::where('traspaso_inventario_id', $traspaso->id)->firstOrFail();
    expect($acuse->firmado_por)->toBe($this->admin->id)
        ->and($acuse->nombre_firmante_snapshot)->toBe($this->admin->name)
        ->and($acuse->empresa_origen_id)->toBe($this->datos['empresaA']->id)
        ->and($acuse->empresa_destino_id)->toBe($this->datos['empresaA']->id);
});

it('la firma queda persistida en disco privado y su hash coincide con el archivo', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', array_merge(($this->payloadValido)(), ['firma' => firmaDemoBase64()]));

    $acuse = AcuseTraspaso::firstOrFail();
    Storage::disk('local')->assertExists($acuse->ruta_firma);
    expect($acuse->hash_firma)->toHaveLength(64)
        ->and(hash('sha256', Storage::disk('local')->get($acuse->ruta_firma)))->toBe($acuse->hash_firma);
});

it('el snapshot queda persistido con folio, empresas, almacenes, renglones y responsable', function () {
    $this->datos['activoA']->update(['nombre' => 'Camisa']);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', array_merge(($this->payloadValido)(['motivo' => 'Reacomodo de temporada']), ['firma' => firmaDemoBase64()]));

    $traspaso = TraspasoInventario::firstOrFail();
    $acuse = AcuseTraspaso::firstOrFail();

    expect($acuse->snapshot_traspaso['traspaso']['folio'])->toBe($traspaso->folio)
        ->and($acuse->snapshot_traspaso['traspaso']['motivo'])->toBe('Reacomodo de temporada')
        ->and($acuse->snapshot_traspaso['empresa_origen']['id'])->toBe($this->datos['empresaA']->id)
        ->and($acuse->snapshot_traspaso['almacen_origen']['id'])->toBe($this->datos['almacenA']->id)
        ->and($acuse->snapshot_traspaso['almacen_destino']['id'])->toBe($this->almacenA2->id)
        ->and($acuse->snapshot_traspaso['items'][0]['activo_origen'])->toBe('Camisa')
        ->and($acuse->snapshot_traspaso['items'][0]['cantidad'])->toBe(10)
        ->and($acuse->snapshot_traspaso['responsable']['id'])->toBe($this->admin->id)
        ->and($acuse->hash_documento)->toHaveLength(64);

    // El snapshot no cambia aunque el activo se renombre después.
    $this->datos['activoA']->update(['nombre' => 'Camisa renombrada']);
    expect($acuse->fresh()->snapshot_traspaso['items'][0]['activo_origen'])->toBe('Camisa');
});

// ---------------------------------------------------------------------------
// Movimiento real de inventario
// ---------------------------------------------------------------------------
it('el stock origen disminuye, el stock destino aumenta y se registran ambos movimientos correlacionados', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', array_merge(($this->payloadValido)(), ['firma' => firmaDemoBase64()]));

    $traspaso = TraspasoInventario::firstOrFail();

    expect($this->inventario->saldoActual($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(90)
        ->and($this->inventario->saldoActual($this->datos['empresaA']->id, $this->almacenA2->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(10);

    $renglon = $traspaso->renglones()->firstOrFail();
    expect(MovimientoInventario::whereKey($renglon->movimiento_salida_id)->where('tipo', TipoMovimiento::TraspasoSalida->value)->exists())->toBeTrue()
        ->and(MovimientoInventario::whereKey($renglon->movimiento_entrada_id)->where('tipo', TipoMovimiento::TraspasoEntrada->value)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Atomicidad / rollback
// ---------------------------------------------------------------------------
it('si el traspaso falla DESPUÉS de validar la firma (stock insuficiente al bloquear), no queda traspaso, ni acuse, ni firma huérfana en disco', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 5);

    // Se salta la prevalidación del Form Request llamando la acción
    // directamente, para probar el rollback real de la transacción (stock
    // insuficiente detectado por `ServicioInventario` bajo `lockForUpdate`).
    expect(fn () => app(RegistrarTraspasoFirmado::class)->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['empresaA']->id, $this->almacenA2->id,
        [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 999]],
        $this->admin, firmaDemoBase64(), null, null, null, null,
    ))->toThrow(ExcepcionDeNegocio::class);

    expect(TraspasoInventario::count())->toBe(0)
        ->and(AcuseTraspaso::count())->toBe(0)
        ->and($this->inventario->saldoActual($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(5);

    // "un archivo → un dueño": la firma que se escribió antes de la
    // transacción se borra al fallar — no queda huérfana en disco.
    Storage::disk('local')->assertDirectoryEmpty("firmas/traspasos/{$this->datos['empresaA']->id}");
});

it('el Form Request ya rechaza un stock insuficiente antes de intentar mover nada (HTTP)', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 5);

    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', array_merge(
            ($this->payloadValido)(['renglones' => [[
                'control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id,
                'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 999,
            ]]]),
            ['firma' => firmaDemoBase64()],
        ))
        ->assertSessionHasErrors('renglones.0.cantidad');

    expect(TraspasoInventario::count())->toBe(0);
});

it('el almacén origen y destino no pueden ser el mismo dentro de la misma empresa (regla existente, ahora también con firma)', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', array_merge(
            ($this->payloadValido)(['almacen_destino_id' => $this->datos['almacenA']->id]),
            ['firma' => firmaDemoBase64()],
        ))
        ->assertSessionHasErrors('almacen_destino_id');

    expect(TraspasoInventario::count())->toBe(0);
});

it('cross-company: un usuario sin acceso a la empresa destino no puede confirmar el traspaso aunque traiga firma válida', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);
    $supervisor->givePermissionTo('inventario.transferir');

    $this->actingAs($supervisor)
        ->post('/inventario/traspasos', array_merge(
            ($this->payloadValido)(['empresa_destino_id' => $this->datos['empresaB']->id, 'almacen_destino_id' => $this->datos['almacenB']->id]),
            ['firma' => firmaDemoBase64()],
        ))
        ->assertSessionHasErrors('empresa_destino_id');

    expect(TraspasoInventario::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Doble submit / concurrencia
// ---------------------------------------------------------------------------
it('doble submit con la MISMA idempotency_key: el segundo intento se rechaza y no duplica el traspaso', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);
    $clave = (string) Str::uuid();

    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', array_merge(($this->payloadValido)(), ['firma' => firmaDemoBase64(), 'idempotency_key' => $clave]))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', array_merge(($this->payloadValido)(), ['firma' => firmaDemoBase64(), 'idempotency_key' => $clave]));

    expect(TraspasoInventario::count())->toBe(1);
});

it('una idempotency_key liberada tras un fallo permite un reintento legítimo', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 5);
    $clave = (string) Str::uuid();

    // Primer intento: stock insuficiente, falla — la clave debe liberarse.
    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', array_merge(
            ($this->payloadValido)(['renglones' => [[
                'control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id,
                'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 999,
            ]]]),
            ['firma' => firmaDemoBase64(), 'idempotency_key' => $clave],
        ))
        ->assertSessionHasErrors('renglones.0.cantidad');

    expect(Cache::has("traspasos:idempotencia:{$clave}"))->toBeFalse();

    // Segundo intento, misma clave, ahora con una cantidad dentro del saldo
    // real (5): debe pasar.
    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', array_merge(
            ($this->payloadValido)(['renglones' => [[
                'control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id,
                'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5,
            ]]]),
            ['firma' => firmaDemoBase64(), 'idempotency_key' => $clave],
        ))
        ->assertSessionHasNoErrors();

    expect(TraspasoInventario::count())->toBe(1);
});

it('concurrencia: dos traspasos que agotan el mismo saldo no pueden ambos tener éxito (lockForUpdate)', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 10);

    $accion = app(RegistrarTraspasoFirmado::class);
    $ejecutar = fn () => $accion->ejecutar(
        $this->datos['empresaA']->id, $this->datos['almacenA']->id,
        $this->datos['empresaA']->id, $this->almacenA2->id,
        [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 8]],
        $this->admin, firmaDemoBase64(), null, null, null, null,
    );

    // El primero se queda con 8 de 10; el segundo, sobre el saldo YA
    // actualizado (2), debe fallar sin dejar nada a medias.
    $ejecutar();
    expect(fn () => $ejecutar())->toThrow(ExcepcionDeNegocio::class);

    expect(TraspasoInventario::count())->toBe(1)
        ->and(AcuseTraspaso::count())->toBe(1)
        ->and($this->inventario->saldoActual($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id))->toBe(2);
});

// ---------------------------------------------------------------------------
// PDF / seguridad
// ---------------------------------------------------------------------------
it('el PDF del acuse está disponible y contiene el folio correcto del traspaso', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', array_merge(($this->payloadValido)(), ['firma' => firmaDemoBase64()]));

    $traspaso = TraspasoInventario::firstOrFail();
    $acuse = AcuseTraspaso::firstOrFail();

    $respuesta = $this->actingAs($this->admin)
        ->get("/acuses-traspaso/{$acuse->id}/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(substr($respuesta->getContent(), 0, 4))->toBe('%PDF');
    Storage::disk('local')->assertExists($acuse->fresh()->ruta_pdf);
    expect($respuesta->headers->get('content-disposition'))->toContain($traspaso->folio);
});

it('un usuario con acceso a AMBAS empresas y el permiso puede ver el detalle, el PDF y la firma', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', array_merge(
            ($this->payloadValido)(['empresa_destino_id' => $this->datos['empresaB']->id, 'almacen_destino_id' => $this->datos['almacenB']->id]),
            ['firma' => firmaDemoBase64()],
        ));

    $traspaso = TraspasoInventario::firstOrFail();
    $acuse = AcuseTraspaso::firstOrFail();

    $this->actingAs($this->admin)->get("/inventario/traspasos/{$traspaso->id}")->assertOk();
    $this->actingAs($this->admin)->get("/acuses-traspaso/{$acuse->id}/pdf")->assertOk();
    $this->actingAs($this->admin)->get("/acuses-traspaso/{$acuse->id}/firma")->assertOk()
        ->assertHeader('content-type', 'image/png');
});

it('autorización de consulta (detalle/PDF/firma): permiso inventario.ver + acceso a origen O destino — nunca hace falta acceso a ambas', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', array_merge(
            ($this->payloadValido)(['empresa_destino_id' => $this->datos['empresaB']->id, 'almacen_destino_id' => $this->datos['almacenB']->id]),
            ['firma' => firmaDemoBase64()],
        ));

    $traspaso = TraspasoInventario::firstOrFail();
    $acuse = AcuseTraspaso::firstOrFail();

    $verTodo = function ($usuario) use ($traspaso, $acuse) {
        $this->actingAs($usuario)->get("/inventario/traspasos/{$traspaso->id}")->assertOk();
        $this->actingAs($usuario)->get("/acuses-traspaso/{$acuse->id}/pdf")->assertOk();
        $this->actingAs($usuario)->get("/acuses-traspaso/{$acuse->id}/firma")->assertOk();
    };
    $rechazaTodo = function ($usuario) use ($traspaso, $acuse) {
        $this->actingAs($usuario)->get("/inventario/traspasos/{$traspaso->id}")->assertForbidden();
        $this->actingAs($usuario)->get("/acuses-traspaso/{$acuse->id}/pdf")->assertForbidden();
        $this->actingAs($usuario)->get("/acuses-traspaso/{$acuse->id}/firma")->assertForbidden();
    };

    // 1. Sólo empresa ORIGEN + permiso funcional (inventario.ver, ya incluido
    //    en Supervisor) → puede ver.
    $verTodo(usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]));

    // 2. Sólo empresa DESTINO + permiso funcional → puede ver.
    $verTodo(usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]));

    // 4. Ninguna de las dos empresas → 403, aunque tenga el permiso.
    $rechazaTodo(usuarioCon(RolSistema::Supervisor->value, [Empresa::factory()->create()]));

    // 5. Empresa ORIGEN pero SIN el permiso funcional (rol Colaborador no lo
    //    tiene) → 403: el acceso a la empresa nunca basta por sí solo.
    $rechazaTodo(usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]));

    // 6. Empresa DESTINO pero SIN el permiso funcional → 403.
    $rechazaTodo(usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaB']]));

    // 7. Manipulación directa del id (IDOR): un usuario con el permiso pero
    //    sin alcance a ninguna de las dos empresas recibe 403 puro — el
    //    cuerpo de la respuesta nunca revela el folio ni datos del traspaso
    //    ajeno, sólo el mensaje genérico de "no autorizado".
    $ajeno = usuarioCon(RolSistema::Supervisor->value, [Empresa::factory()->create()]);
    $respuestaPdf = $this->actingAs($ajeno)->get("/acuses-traspaso/{$acuse->id}/pdf");
    $respuestaFirma = $this->actingAs($ajeno)->get("/acuses-traspaso/{$acuse->id}/firma");
    $respuestaDetalle = $this->actingAs($ajeno)->get("/inventario/traspasos/{$traspaso->id}");

    $respuestaPdf->assertForbidden();
    $respuestaFirma->assertForbidden();
    $respuestaDetalle->assertForbidden();
    expect($respuestaPdf->getContent())->not->toContain($traspaso->folio);
    expect($respuestaDetalle->getContent())->not->toContain($traspaso->folio);
});

it('el acuse es 1:1 con el traspaso: no se puede crear un segundo acuse para el mismo traspaso', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', array_merge(($this->payloadValido)(), ['firma' => firmaDemoBase64()]));

    $traspaso = TraspasoInventario::firstOrFail();

    expect(fn () => AcuseTraspaso::query()->create([
        'traspaso_inventario_id' => $traspaso->id,
        'empresa_origen_id' => $traspaso->empresa_origen_id,
        'empresa_destino_id' => $traspaso->empresa_destino_id,
        'firmado_por' => $this->admin->id,
        'nombre_firmante_snapshot' => $this->admin->name,
        'ruta_firma' => 'firmas/traspasos/x.png',
        'hash_firma' => str_repeat('a', 64),
        'firmado_en' => now(),
        'snapshot_traspaso' => [],
        'hash_documento' => str_repeat('b', 64),
    ]))->toThrow(QueryException::class);
});

it('no se puede modificar un acuse ya firmado a través del flujo normal (no existe endpoint de edición)', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 100);

    $this->actingAs($this->admin)
        ->post('/inventario/traspasos', array_merge(($this->payloadValido)(), ['firma' => firmaDemoBase64()]));

    $acuse = AcuseTraspaso::firstOrFail();
    $hashOriginal = $acuse->hash_documento;
    $rutaOriginal = $acuse->ruta_firma;

    // No hay ninguna ruta PUT/PATCH para acuses-traspaso — sólo lectura
    // (pdf/firma) y regeneración del PDF, que no toca snapshot/firma/hash.
    $this->actingAs($this->admin)->post("/acuses-traspaso/{$acuse->id}/regenerar-pdf");

    expect($acuse->fresh()->hash_documento)->toBe($hashOriginal)
        ->and($acuse->fresh()->ruta_firma)->toBe($rutaOriginal);
});
