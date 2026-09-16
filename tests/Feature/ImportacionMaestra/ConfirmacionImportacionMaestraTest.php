<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Area;
use App\Models\BitacoraAuditoria;
use App\Models\Colaborador;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Servicio;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Models\UnidadActivo;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    sembrarRolesPermisos();
    $this->superadmin = usuarioCon(RolSistema::Superadministrador->value);
});

it('importa las 10 hojas de forma completa, atómica y con auditoría resumen', function (): void {
    ['curp' => $curp, 'hojas' => $hojas] = workbookMaestroCompleto();

    $analisis = prevalidarWorkbookMaestro($this, $this->superadmin, $hojas);

    expect($analisis['errores'])->toBe([]);
    expect($analisis['resumen']['EMPRESAS']['validos'])->toBe(2);
    expect($analisis['resumen']['SUCURSALES']['validos'])->toBe(2);
    expect($analisis['resumen']['CONTRATOS']['validos'])->toBe(1);
    expect($analisis['resumen']['SERVICIOS']['validos'])->toBe(1);
    expect($analisis['resumen']['COLABORADORES']['validos'])->toBe(1);
    expect($analisis['resumen']['TALLAS']['validos'])->toBe(1);
    expect($analisis['resumen']['ACTIVOS']['validos'])->toBe(2);
    expect($analisis['resumen']['ACTIVO_TALLA']['validos'])->toBe(1);
    expect($analisis['resumen']['ALMACENES']['validos'])->toBe(1);
    expect($analisis['resumen']['UNIDADES_ACTIVO']['validos'])->toBe(1);

    // `prevalidar()` nunca persiste (rollback siempre, es un dry run real).
    expect(Empresa::query()->count())->toBe(0);

    $this->actingAs($this->superadmin)
        ->post('/datos/importar-maestro/confirmar', ['token' => $analisis['token']])
        ->assertRedirect('/datos/importar-maestro');

    expect(Empresa::query()->count())->toBe(2);
    expect(Sucursal::query()->count())->toBe(2);
    expect(Contrato::query()->count())->toBe(1);
    expect(Servicio::query()->count())->toBe(1);
    expect(Talla::query()->count())->toBe(1);
    expect(Activo::query()->count())->toBe(2);
    expect(Almacen::query()->count())->toBe(1);
    expect(Colaborador::query()->count())->toBe(1);
    expect(UnidadActivo::query()->count())->toBe(1);
    expect(Area::query()->count())->toBe(1);

    $sucursal = Sucursal::query()->where('nombre', 'MEX')->firstOrFail();
    expect($sucursal->codigo)->toStartWith('SUC-');

    $contrato = Contrato::query()->firstOrFail();
    expect($contrato->codigo)->toStartWith('CON-');

    $servicio = Servicio::query()->firstOrFail();
    expect($servicio->codigo)->toStartWith('SER-');

    $activoCantidad = Activo::query()->where('nombre', 'Camisola')->firstOrFail();
    expect($activoCantidad->codigo)->toStartWith('ACT-');
    // Sin hoja EXISTENCIAS_INICIALES: activos por cantidad quedan en 0, sin
    // fila en saldos_inventario (se crea perezosamente en el primer movimiento).
    expect(SaldoInventario::query()->where('activo_id', $activoCantidad->id)->exists())->toBeFalse();

    $almacen = Almacen::query()->firstOrFail();
    expect($almacen->codigo)->toStartWith('ALM-');
    expect($almacen->empresas()->count())->toBe(2);

    $unidad = UnidadActivo::query()->firstOrFail();
    expect($unidad->colaborador_id)->not->toBeNull();
    expect($unidad->estado->value)->toBe('asignada');
    expect($unidad->especificacion?->imei)->toBe('123456789012345');

    $colaborador = Colaborador::query()->firstOrFail();
    expect($colaborador->curp)->toBe($curp);
    expect($colaborador->numero_empleado)->not->toBeNull();
    expect($colaborador->servicio_actual_id)->not->toBeNull();
    expect($colaborador->area_id)->not->toBeNull();

    // Una sola entrada de auditoría RESUMEN para toda la importación (nunca
    // una por fila) además de la que registra `RegistrarUnidadesActivo` por
    // cada unidad dada de alta (ver docblock de `ServicioImportacionMaestra`).
    expect(
        BitacoraAuditoria::query()->where('modulo', 'datos')->where('accion', 'importacion_maestra')->count(),
    )->toBe(1);

    // El temporal se borra tras confirmar.
    expect(Storage::disk('local')->allFiles('importaciones-maestras'))->toBe([]);
});

it('no persiste nada si una fila falla al confirmar (atomicidad)', function (): void {
    ['hojas' => $hojas] = workbookMaestroCompleto();
    // Rompe una fila de la ÚLTIMA hoja: el almacén referenciado no existe.
    $hojas['UNIDADES_ACTIVO'][0]['almacen'] = 'Almacén Que No Existe';

    $analisis = prevalidarWorkbookMaestro($this, $this->superadmin, $hojas);
    expect($analisis['errores'])->not->toBe([]);

    $this->actingAs($this->superadmin)
        ->post('/datos/importar-maestro/confirmar', ['token' => $analisis['token']])
        ->assertStatus(422);

    expect(Empresa::query()->count())->toBe(0);
    expect(Sucursal::query()->count())->toBe(0);
    expect(Contrato::query()->count())->toBe(0);
    expect(Servicio::query()->count())->toBe(0);
    expect(Colaborador::query()->count())->toBe(0);
    expect(Talla::query()->count())->toBe(0);
    expect(Activo::query()->count())->toBe(0);
    expect(Almacen::query()->count())->toBe(0);
    expect(UnidadActivo::query()->count())->toBe(0);
});

it('bloquea la importación completa (prevalidar y confirmar) si la base ya contiene información', function (): void {
    Empresa::factory()->create();

    ['hojas' => $hojas] = workbookMaestroCompleto();
    $archivo = construirWorkbookMaestro($hojas);

    $this->actingAs($this->superadmin)
        ->post('/datos/importar-maestro/prevalidar', ['archivo' => $archivo])
        ->assertStatus(422);

    $this->actingAs($this->superadmin)
        ->post('/datos/importar-maestro/confirmar', ['token' => 'cualquiera'])
        ->assertStatus(422);
});
