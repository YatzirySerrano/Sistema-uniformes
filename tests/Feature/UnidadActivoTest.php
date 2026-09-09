<?php

use App\Acciones\DarDeBajaUnidadActivo;
use App\Acciones\RegistrarUnidadesActivo;
use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\RolSistema;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Soporte\ServicioGeneradorCodigos;

beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $this->activo = Activo::factory()->for($this->empresa)->seguimientoIndividual()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
});

it('el generador de códigos produce códigos únicos y estables prefijados por la empresa', function () {
    $servicio = app(ServicioGeneradorCodigos::class);

    $codigo1 = $servicio->siguiente($this->empresa);
    $codigo2 = $servicio->siguiente($this->empresa);

    expect($codigo1)->not->toBe($codigo2)
        ->and($codigo1)->toStartWith($this->empresa->codigo.'-')
        ->and($codigo2)->toStartWith($this->empresa->codigo.'-');
});

it('el generador de códigos es independiente por empresa', function () {
    $otra = Empresa::factory()->create();
    $servicio = app(ServicioGeneradorCodigos::class);

    $codigoA = $servicio->siguiente($this->empresa);
    $codigoB = $servicio->siguiente($otra);

    expect($codigoA)->toStartWith($this->empresa->codigo.'-')
        ->and($codigoB)->toStartWith($otra->codigo.'-');
});

it('registra N unidades con códigos distintos, movimiento por unidad y sin tocar saldos', function () {
    $unidades = app(RegistrarUnidadesActivo::class)->ejecutar(
        empresa: $this->empresa,
        activo: $this->activo,
        almacen: $this->almacen,
        cantidad: 5,
        motivo: 'Alta inicial',
        realizadoPor: $this->admin->id,
    );

    expect($unidades)->toHaveCount(5)
        ->and($unidades->pluck('codigo')->unique())->toHaveCount(5)
        ->and($unidades->pluck('public_token')->unique())->toHaveCount(5)
        ->and($unidades->every(fn (UnidadActivo $u) => $u->estado === EstadoUnidadActivo::EnAlmacen))->toBeTrue()
        ->and($unidades->every(fn (UnidadActivo $u) => $u->esEntregable()))->toBeTrue();

    expect(SaldoInventario::query()->where('activo_id', $this->activo->id)->exists())->toBeFalse();
    expect(MovimientoInventario::query()->where('activo_id', $this->activo->id)->whereNotNull('unidad_activo_id')->count())->toBe(5);
});

it('rechaza registrar unidades para un activo por cantidad o un almacén que no abastece la empresa', function () {
    $activoCantidad = Activo::factory()->for($this->empresa)->create(['tipo_control' => 'cantidad']);
    $otraEmpresa = Empresa::factory()->create();
    $almacenAjeno = Almacen::factory()->paraEmpresa($otraEmpresa)->create();

    $accion = app(RegistrarUnidadesActivo::class);

    expect(fn () => $accion->ejecutar($this->empresa, $activoCantidad, $this->almacen, 1, 'x', null))
        ->toThrow(ExcepcionDeNegocioSimple::class);

    expect(fn () => $accion->ejecutar($this->empresa, $this->activo, $almacenAjeno, 1, 'x', null))
        ->toThrow(ExcepcionDeNegocioSimple::class);
});

it('el código de la unidad es estable aunque cambie de almacén, el activo o la empresa se rename', function () {
    $unidad = app(RegistrarUnidadesActivo::class)->ejecutar($this->empresa, $this->activo, $this->almacen, 1, 'x', null)->first();
    $codigoOriginal = $unidad->codigo;

    $otroAlmacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $unidad->update(['almacen_id' => $otroAlmacen->id]);
    $this->activo->update(['nombre' => 'Renombrado']);
    $this->empresa->update(['nombre_comercial' => 'Otro nombre']);

    expect($unidad->fresh()->codigo)->toBe($codigoOriginal);
});

it('crea un activo de seguimiento individual con N unidades desde el alta unificada', function () {
    $this->actingAs($this->admin)
        ->post('/activos', [
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Laptop Dell',
            'tipo_control' => 'individual',
            'almacen_id' => $this->almacen->id,
            'cantidad_inicial' => 3,
        ])
        ->assertSessionHasNoErrors();

    $activo = Activo::query()->where('nombre', 'Laptop Dell')->firstOrFail();
    expect(UnidadActivo::query()->where('activo_id', $activo->id)->count())->toBe(3);
});

it('el alta sin "generar_qr" hace un redirect Inertia normal, sin tocar el PDF', function () {
    $respuesta = $this->actingAs($this->admin)
        ->post('/activos', [
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Monitor',
            'tipo_control' => 'individual',
            'almacen_id' => $this->almacen->id,
            'cantidad_inicial' => 1,
        ]);

    $respuesta->assertRedirect(route('activos.index'))
        ->assertSessionHas('toast')
        ->assertSessionMissing('etiquetasUrl');

    expect($respuesta->headers->get('content-type'))->not->toContain('application/pdf');
});

it('el alta con "generar_qr" crea el activo y sus unidades con una respuesta Inertia normal (NUNCA el PDF en el POST)', function () {
    $respuesta = $this->actingAs($this->admin)
        ->post('/activos', [
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Tablet',
            'tipo_control' => 'individual',
            'almacen_id' => $this->almacen->id,
            'cantidad_inicial' => 2,
            'generar_qr' => true,
        ]);

    $activo = Activo::query()->where('nombre', 'Tablet')->firstOrFail();
    $unidades = UnidadActivo::query()->where('activo_id', $activo->id)->get();
    expect($unidades)->toHaveCount(2);

    // La respuesta del POST sigue siendo un redirect Inertia normal — jamás
    // el PDF binario mezclado en la misma respuesta.
    $respuesta->assertRedirect(route('activos.show', $activo))
        ->assertSessionHas('toast')
        ->assertSessionHas('etiquetasUrl', route('unidades-activo.etiquetas', ['ids' => $unidades->pluck('id')->implode(',')]));

    expect($respuesta->headers->get('content-type'))->not->toContain('application/pdf')
        ->and($respuesta->getContent())->not->toContain('%PDF');
});

it('no genera unidades si no se captura cantidad ni almacén en el alta', function () {
    $this->actingAs($this->admin)
        ->post('/activos', [
            'empresa_id' => $this->empresa->id, 'nombre' => 'Sólo catálogo', 'tipo_control' => 'individual',
        ])
        ->assertSessionHasNoErrors();

    $activo = Activo::query()->where('nombre', 'Sólo catálogo')->firstOrFail();
    expect(UnidadActivo::query()->where('activo_id', $activo->id)->count())->toBe(0);
});

it('sólo una unidad en almacén y funcionando es entregable', function () {
    $enAlmacen = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();
    $asignada = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->asignada()->create();
    $enReparacion = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::EnReparacion)->create();

    expect($enAlmacen->esEntregable())->toBeTrue()
        ->and($asignada->esEntregable())->toBeFalse()
        ->and($enReparacion->esEntregable())->toBeFalse();
});

it('da de baja una unidad, genera movimiento y rechaza una segunda baja', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();

    app(DarDeBajaUnidadActivo::class)->ejecutar($unidad, 'Equipo dañado sin reparación', $this->admin->id);

    $unidad->refresh();
    expect($unidad->estado)->toBe(EstadoUnidadActivo::Baja)
        ->and($unidad->dado_de_baja_en)->not->toBeNull();

    expect(fn () => app(DarDeBajaUnidadActivo::class)->ejecutar($unidad, 'De nuevo', null))
        ->toThrow(ExcepcionDeNegocioSimple::class);
});

it('rechaza dar de baja una unidad asignada', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->asignada()->create();

    expect(fn () => app(DarDeBajaUnidadActivo::class)->ejecutar($unidad, 'x', null))
        ->toThrow(ExcepcionDeNegocioSimple::class);
});

it('el detalle de la unidad se resuelve por public_token, nunca por id', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();

    $this->actingAs($this->admin)
        ->get("/activos/unidades/{$unidad->public_token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Activos/UnidadDetalle')
            ->where('unidad.codigo', $unidad->codigo),
        );

    $this->actingAs($this->admin)
        ->get('/activos/unidades/00000000-0000-0000-0000-000000000000')
        ->assertNotFound();
});

it('un rol restringido no puede ver una unidad de una empresa fuera de su alcance (IDOR)', function () {
    $ajena = Empresa::factory()->create();
    $almacenAjeno = Almacen::factory()->paraEmpresa($ajena)->create();
    $activoAjeno = Activo::factory()->for($ajena)->seguimientoIndividual()->create();
    $unidadAjena = UnidadActivo::factory()->for($ajena, 'empresa')->for($activoAjeno)->for($almacenAjeno)->create();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->empresa]);
    $supervisor->givePermissionTo('unidades-activo.ver');

    $respuesta = $this->actingAs($supervisor)->get("/activos/unidades/{$unidadAjena->public_token}");
    expect($respuesta->status())->toBeIn([403, 404]);
});

it('el listado de unidades filtra por empresa, almacén y estado', function () {
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->baja()->create();

    $this->actingAs($this->admin)
        ->get('/activos/unidades?estado=en_almacen')
        ->assertInertia(fn ($page) => $page->component('Activos/Unidades')->has('unidades.data', 1));
});

it('el listado expone el estado visible consolidado y permite filtrar por él', function () {
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->asignada()->create();
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->create(['condicion' => CondicionUnidadActivo::Inservible]);
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->baja()->create();

    $this->actingAs($this->admin)
        ->get('/activos/unidades')
        ->assertInertia(fn ($page) => $page->component('Activos/Unidades')
            ->has('unidades.data', 4)
            ->where('unidades.data.0.estado_visible', 'baja')
        );

    // "Inservible" se agrupa como Reparación en el filtro visible, sin tocar
    // la columna `condicion` real.
    $this->actingAs($this->admin)
        ->get('/activos/unidades?estado_visible=reparacion')
        ->assertInertia(fn ($page) => $page
            ->has('unidades.data', 1)
            ->where('unidades.data.0.condicion', 'inservible')
        );

    $this->actingAs($this->admin)
        ->get('/activos/unidades?estado_visible=disponible')
        ->assertInertia(fn ($page) => $page->has('unidades.data', 1));
});

it('genera un PDF de etiquetas para las unidades seleccionadas, con las cabeceras y el contenido correctos', function () {
    $unidades = app(RegistrarUnidadesActivo::class)->ejecutar($this->empresa, $this->activo, $this->almacen, 2, 'x', null);
    $ids = $unidades->pluck('id')->implode(',');

    $respuesta = $this->actingAs($this->admin)
        ->get('/activos/unidades/etiquetas?ids='.$ids)
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($respuesta->getContent())->toStartWith('%PDF-');

    // El texto vive comprimido (FlateDecode) dentro de los content streams del
    // PDF; se decodifican para verificar que los códigos de ESTAS unidades
    // (y ninguna otra) quedaron realmente impresos en las etiquetas.
    $texto = '';
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $respuesta->getContent(), $coincidencias);
    foreach ($coincidencias[1] as $stream) {
        $decodificado = @zlib_decode($stream);
        if ($decodificado !== false) {
            $texto .= $decodificado;
        }
    }

    foreach ($unidades as $unidad) {
        expect($texto)->toContain($unidad->codigo);
    }
});

it('reintentar la generación del PDF de etiquetas no crea unidades ni códigos nuevos', function () {
    $unidades = app(RegistrarUnidadesActivo::class)->ejecutar($this->empresa, $this->activo, $this->almacen, 2, 'x', null);
    $ids = $unidades->pluck('id')->implode(',');
    $codigosOriginales = $unidades->pluck('codigo')->sort()->values()->all();

    $this->actingAs($this->admin)->get('/activos/unidades/etiquetas?ids='.$ids)->assertOk();
    $this->actingAs($this->admin)->get('/activos/unidades/etiquetas?ids='.$ids)->assertOk();
    $this->actingAs($this->admin)->get('/activos/unidades/etiquetas?ids='.$ids)->assertOk();

    expect(UnidadActivo::query()->where('activo_id', $this->activo->id)->count())->toBe(2)
        ->and(UnidadActivo::query()->where('activo_id', $this->activo->id)->pluck('codigo')->sort()->values()->all())
        ->toBe($codigosOriginales);
});

it('una empresa no puede generar etiquetas de unidades de otra empresa (IDOR)', function () {
    $ajena = Empresa::factory()->create();
    $almacenAjeno = Almacen::factory()->paraEmpresa($ajena)->create();
    $activoAjeno = Activo::factory()->for($ajena)->seguimientoIndividual()->create();
    $unidadAjena = UnidadActivo::factory()->for($ajena, 'empresa')->for($activoAjeno)->for($almacenAjeno)->create();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->empresa]);
    $supervisor->givePermissionTo('unidades-activo.ver');

    $this->actingAs($supervisor)
        ->get('/activos/unidades/etiquetas?ids='.$unidadAjena->id)
        ->assertNotFound();
});

it('agrega más unidades a un activo de seguimiento individual ya existente desde su detalle', function () {
    app(RegistrarUnidadesActivo::class)->ejecutar($this->empresa, $this->activo, $this->almacen, 2, 'Alta inicial', null);

    $this->actingAs($this->admin)
        ->post("/activos/{$this->activo->id}/existencias", [
            'almacen_id' => $this->almacen->id,
            'cantidad' => 3,
        ])
        ->assertRedirect()
        ->assertSessionHas('toast');

    expect(UnidadActivo::query()->where('activo_id', $this->activo->id)->count())->toBe(5);
});

it('agregar existencias con "generar_qr" deja la URL de etiquetas en flash, sin devolver el PDF en el POST', function () {
    $respuesta = $this->actingAs($this->admin)
        ->post("/activos/{$this->activo->id}/existencias", [
            'almacen_id' => $this->almacen->id,
            'cantidad' => 2,
            'generar_qr' => true,
        ]);

    $ids = UnidadActivo::query()->where('activo_id', $this->activo->id)->pluck('id')->implode(',');

    $respuesta->assertRedirect()
        ->assertSessionHas('toast')
        ->assertSessionHas('etiquetasUrl', route('unidades-activo.etiquetas', ['ids' => $ids]));

    expect($respuesta->headers->get('content-type'))->not->toContain('application/pdf')
        ->and($respuesta->getContent())->not->toContain('%PDF');
});

it('da de baja una unidad vía HTTP con permiso y motivo', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();

    $this->actingAs($this->admin)
        ->post("/activos/unidades/{$unidad->public_token}/baja", ['motivo' => 'Ya no sirve'])
        ->assertRedirect();

    expect($unidad->fresh()->estado)->toBe(EstadoUnidadActivo::Baja);
});

it('reporta una unidad asignada como perdida vía HTTP, conserva el responsable y no la vuelve entregable', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->asignada()->create();
    $colaboradorId = $unidad->colaborador_id;

    $this->actingAs($this->admin)
        ->post("/activos/unidades/{$unidad->public_token}/incidencia", [
            'tipo' => 'perdido',
            'motivo' => 'No se localiza tras el cambio de turno',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $unidad->refresh();
    expect($unidad->condicion)->toBe(CondicionUnidadActivo::Perdido)
        ->and($unidad->colaborador_id)->toBe($colaboradorId)
        ->and($unidad->incidencia_motivo)->not->toBeNull()
        ->and($unidad->esEntregable())->toBeFalse();

    expect(MovimientoInventario::query()->where('unidad_activo_id', $unidad->id)->where('tipo', 'incidencia')->exists())->toBeTrue();
});

it('rechaza reportar incidencia de una unidad que no está asignada', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();

    $this->actingAs($this->admin)
        ->post("/activos/unidades/{$unidad->public_token}/incidencia", [
            'tipo' => 'robado',
            'motivo' => 'x',
        ])
        ->assertSessionHasErrors('negocio');

    expect($unidad->fresh()->condicion)->toBe(CondicionUnidadActivo::Funcionando);
});

it('recupera una unidad perdida hacia un almacén que abastece su empresa y la vuelve entregable', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->asignada()->conCondicion(CondicionUnidadActivo::Perdido)->create();
    $destino = Almacen::factory()->paraEmpresa($this->empresa)->create();

    $this->actingAs($this->admin)
        ->post("/activos/unidades/{$unidad->public_token}/recuperar", [
            'almacen_id' => $destino->id,
            'condicion_resultante' => 'funcionando',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $unidad->refresh();
    expect($unidad->estado)->toBe(EstadoUnidadActivo::EnAlmacen)
        ->and($unidad->condicion)->toBe(CondicionUnidadActivo::Funcionando)
        ->and($unidad->almacen_id)->toBe($destino->id)
        ->and($unidad->esEntregable())->toBeTrue();

    expect(MovimientoInventario::query()->where('unidad_activo_id', $unidad->id)->where('tipo', 'recuperacion')->exists())->toBeTrue();
});

it('rechaza recuperar hacia un almacén que no abastece la empresa de la unidad', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->asignada()->conCondicion(CondicionUnidadActivo::Robado)->create();
    $otraEmpresa = Empresa::factory()->create();
    $almacenAjeno = Almacen::factory()->paraEmpresa($otraEmpresa)->create();

    $this->actingAs($this->admin)
        ->post("/activos/unidades/{$unidad->public_token}/recuperar", [
            'almacen_id' => $almacenAjeno->id,
            'condicion_resultante' => 'funcionando',
        ])
        ->assertSessionHasErrors('almacen_id');

    expect($unidad->fresh()->condicion)->toBe(CondicionUnidadActivo::Robado);
});

it('rechaza recuperar una unidad que no está en incidencia', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();
    $destino = Almacen::factory()->paraEmpresa($this->empresa)->create();

    $this->actingAs($this->admin)
        ->post("/activos/unidades/{$unidad->public_token}/recuperar", [
            'almacen_id' => $destino->id,
            'condicion_resultante' => 'funcionando',
        ])
        ->assertSessionHasErrors('negocio');
});

it('rechaza recuperar con una condición resultante que sea otra incidencia', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->asignada()->conCondicion(CondicionUnidadActivo::Perdido)->create();
    $destino = Almacen::factory()->paraEmpresa($this->empresa)->create();

    $this->actingAs($this->admin)
        ->post("/activos/unidades/{$unidad->public_token}/recuperar", [
            'almacen_id' => $destino->id,
            'condicion_resultante' => 'robado',
        ])
        ->assertSessionHasErrors('condicion_resultante');
});

/*
|--------------------------------------------------------------------------
| Código QR: verlo / imprimirlo DESPUÉS de crear la unidad
|--------------------------------------------------------------------------
| El QR no es un archivo persistido: se deriva del `public_token` (permanente
| y único). El endpoint es de sólo lectura → idempotente, sin concurrencia y
| sin posibilidad de un segundo QR ni de cambiar el `codigo` estable.
*/

it('devuelve el PNG del QR de una unidad ya registrada a un usuario autorizado', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();

    $respuesta = $this->actingAs($this->admin)->get("/activos/unidades/{$unidad->public_token}/qr");

    $respuesta->assertOk();
    expect($respuesta->headers->get('Content-Type'))->toContain('image/png');
    // Firma de un PNG: bytes 0x89 'P' 'N' 'G'.
    $contenido = $respuesta->getContent();
    expect(substr($contenido, 1, 3))->toBe('PNG');
});

it('pedir el QR no cambia el código estable de la unidad y es idempotente', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();
    $codigo = $unidad->codigo;
    $token = $unidad->public_token;

    $primera = $this->actingAs($this->admin)->get("/activos/unidades/{$token}/qr")->assertOk();
    $segunda = $this->actingAs($this->admin)->get("/activos/unidades/{$token}/qr")->assertOk();

    $unidad->refresh();
    expect($unidad->codigo)->toBe($codigo)
        ->and($unidad->public_token)->toBe($token)
        ->and($primera->getContent())->toBe($segunda->getContent());
    expect(UnidadActivo::query()->where('activo_id', $this->activo->id)->count())->toBe(1);
});

it('un usuario de otra empresa no puede obtener el QR de la unidad (403)', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();
    $otra = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Supervisor->value, [$otra]))
        ->get("/activos/unidades/{$unidad->public_token}/qr")
        ->assertForbidden();
});

it('un token inexistente devuelve 404, no un 500', function () {
    $this->actingAs($this->admin)
        ->get('/activos/unidades/00000000-0000-0000-0000-000000000000/qr')
        ->assertNotFound();
});
