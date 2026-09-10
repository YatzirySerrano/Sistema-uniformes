<?php

use App\Acciones\RegistrarEntregaFirmada;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioAcusePdf;
use App\Servicios\ServicioInventario;
use App\Soporte\ContextoExportacion;
use App\Soporte\FechaHora;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Los timestamps se guardan en UTC y se presentan en
 * `config('uniformes.zona_horaria')` — nunca con `addHours()` a mano. Las
 * fechas de negocio (día sin hora) no se desplazan.
 */
it('FechaHora::local convierte a la zona de presentación configurada, sin offset fijo', function () {
    $utc = CarbonImmutable::parse('2026-09-09 16:34:00', 'UTC');

    config()->set('uniformes.zona_horaria', 'America/Mexico_City');
    expect(FechaHora::local($utc))->toBe('09/09/2026 10:34');

    // Cambiar la config cambia el resultado: no hay un -6h quemado.
    config()->set('uniformes.zona_horaria', 'UTC');
    expect(FechaHora::local($utc))->toBe('09/09/2026 16:34');

    expect(FechaHora::local(null))->toBe('');
});

it('ContextoExportacion::generadoEnLocal usa la zona de presentación', function () {
    config()->set('uniformes.zona_horaria', 'America/Mexico_City');
    Carbon::setTestNow(Carbon::parse('2026-09-09 16:34:00', 'UTC'));

    $contexto = new ContextoExportacion('Reporte', null, [], 0);

    expect($contexto->generadoEnLocal())->toBe('09/09/2026 10:34');

    Carbon::setTestNow();
});

it('el PDF del acuse imprime la hora de firma en la zona local, y el hash del snapshot no cambia', function () {
    Storage::fake('local');
    Mail::fake();
    config()->set('uniformes.zona_horaria', 'America/Mexico_City');
    Carbon::setTestNow(Carbon::parse('2026-09-09 16:34:00', 'UTC'));

    $datos = escenarioMultiempresa();
    $admin = usuarioCon(RolSistema::Administrador->value, [$datos['empresaA']]);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $datos['empresaA']->id, almacenId: $datos['almacenA']->id,
        activoId: $datos['activoA']->id, tallaId: $datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 10,
    ));

    $acuse = app(RegistrarEntregaFirmada::class)->ejecutar(
        $datos['colaboradorA']->id, $datos['almacenA']->id, $admin->id, now()->toDateString(),
        [['activo_id' => $datos['activoA']->id, 'talla_id' => $datos['tallaA']->id, 'cantidad' => 2]],
        [], [], null, null, firmaDemoBase64(), firmaDemoBase64(), true, null, null,
    );

    // El timestamp real en BD sigue en UTC.
    expect($acuse->firmado_en->format('H:i'))->toBe('16:34');

    // El hash del documento es re-verificable desde el snapshot inmutable
    // (el cambio de formato del blade no toca el snapshot).
    $recalculado = hash('sha256', json_encode(
        $acuse->snapshot_entrega,
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ));
    expect($recalculado)->toBe($acuse->hash_documento);

    // El PDF muestra 10:34 (hora local), no 16:34.
    $ruta = app(ServicioAcusePdf::class)->generar($acuse->fresh());
    $pdf = Storage::disk('local')->get($ruta);
    $texto = collect(explode('stream', $pdf))
        ->map(fn ($s) => @zlib_decode(ltrim($s)) ?: '')
        ->implode(' ');
    $texto = str_replace("\x00", '', $texto);

    expect($texto)->toContain('10:34')
        ->and($texto)->not->toContain('16:34');

    Carbon::setTestNow();
});
