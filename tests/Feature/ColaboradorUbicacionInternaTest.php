<?php

use App\Acciones\CambiarEmpresaColaborador;
use App\Enums\EstadoEntrega;
use App\Enums\RolSistema;
use App\Excepciones\ExcepcionDeNegocio;
use App\Models\Area;
use App\Models\BitacoraAuditoria;
use App\Models\Colaborador;
use App\Models\Contrato;
use App\Models\DetalleEntrega;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\Servicio;
use App\Models\Sucursal;
use App\Servicios\ServicioHistoricoColaborador;
use App\Soporte\DescripcionAuditoria;

/**
 * Objetivo 9-14: Editar colaborador permite cambiar Sucursal y Área/
 * Departamento SÓLO dentro de la empresa ACTUAL — sin tocar Empresa, sin
 * generar un nuevo número de empleado, sin limpiar el servicio, y sin
 * confundirse con `CambiarEmpresaColaborador` (transferencia entre
 * empresas) ni con `CambiarServicioColaborador` (operaciones separadas que
 * NO se modifican aquí).
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresaA = Empresa::factory()->create(['nombre_comercial' => 'AMBIQ']);
    $this->empresaB = Empresa::factory()->create(['nombre_comercial' => 'ESTRATEGIAS']);
    $this->sucursalZapata = Sucursal::factory()->for($this->empresaA)->create(['nombre' => 'Zapata']);
    $this->sucursalOtraA = Sucursal::factory()->for($this->empresaA)->create(['nombre' => 'Civac']);
    $this->sucursalB = Sucursal::factory()->for($this->empresaB)->create(['nombre' => 'Jiutepec']);
    $this->areaA1 = Area::factory()->for($this->empresaA)->create(['nombre' => 'Almacén']);
    $this->areaA2 = Area::factory()->for($this->empresaA)->create(['nombre' => 'Administración']);
    $this->areaB = Area::factory()->for($this->empresaB)->create(['nombre' => 'Ventas']);
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresaA, $this->empresaB]);
});

function payloadEdicion(Colaborador $colaborador, array $extra = []): array
{
    return array_merge([
        'numero_empleado' => $colaborador->numero_empleado,
        'nombre_completo' => $colaborador->nombre_completo,
        'curp' => $colaborador->curp,
        'sucursal_id' => $colaborador->sucursal_id,
        'activo' => true,
    ], $extra);
}

it('1. permite cambiar la sucursal a otra sucursal de la MISMA empresa', function () {
    $colaborador = Colaborador::factory()->for($this->empresaA)->for($this->sucursalZapata)->create();

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$colaborador->id}", payloadEdicion($colaborador, ['sucursal_id' => $this->sucursalOtraA->id]))
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->sucursal_id)->toBe($this->sucursalOtraA->id)
        ->and($colaborador->fresh()->empresa_id)->toBe($this->empresaA->id);
});

it('2. permite cambiar el área a otra área de la MISMA empresa', function () {
    $colaborador = Colaborador::factory()->for($this->empresaA)->for($this->sucursalZapata)->create(['area_id' => $this->areaA1->id]);

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$colaborador->id}", payloadEdicion($colaborador, ['area_id' => $this->areaA2->id]))
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->area_id)->toBe($this->areaA2->id);
});

it('3. permite cambiar sucursal y área a la vez, dentro de la misma empresa', function () {
    $colaborador = Colaborador::factory()->for($this->empresaA)->for($this->sucursalZapata)->create(['area_id' => $this->areaA1->id]);

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$colaborador->id}", payloadEdicion($colaborador, [
            'sucursal_id' => $this->sucursalOtraA->id,
            'area_id' => $this->areaA2->id,
        ]))
        ->assertSessionHasNoErrors();

    $colaborador->refresh();
    expect($colaborador->sucursal_id)->toBe($this->sucursalOtraA->id)
        ->and($colaborador->area_id)->toBe($this->areaA2->id);
});

it('4. rechaza una sucursal de OTRA empresa', function () {
    $colaborador = Colaborador::factory()->for($this->empresaA)->for($this->sucursalZapata)->create();

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$colaborador->id}", payloadEdicion($colaborador, ['sucursal_id' => $this->sucursalB->id]))
        ->assertSessionHasErrors('sucursal_id');

    expect($colaborador->fresh()->sucursal_id)->toBe($this->sucursalZapata->id);
});

it('5. rechaza un área de OTRA empresa', function () {
    $colaborador = Colaborador::factory()->for($this->empresaA)->for($this->sucursalZapata)->create(['area_id' => $this->areaA1->id]);

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$colaborador->id}", payloadEdicion($colaborador, ['area_id' => $this->areaB->id]))
        ->assertSessionHasErrors('area_id');

    expect($colaborador->fresh()->area_id)->toBe($this->areaA1->id);
});

it('6. Editar colaborador NO puede cambiar la empresa (empresa_id manipulado en el request se ignora)', function () {
    $colaborador = Colaborador::factory()->for($this->empresaA)->for($this->sucursalZapata)->create();

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$colaborador->id}", payloadEdicion($colaborador, [
            'empresa_id' => $this->empresaB->id,
            'sucursal_id' => $this->sucursalOtraA->id,
        ]))
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->empresa_id)->toBe($this->empresaA->id);
});

it('7. cambiar sucursal/área NO genera un nuevo número de empleado', function () {
    $colaborador = Colaborador::factory()->for($this->empresaA)->for($this->sucursalZapata)->create(['numero_empleado' => 'EMP-777']);

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$colaborador->id}", payloadEdicion($colaborador, ['sucursal_id' => $this->sucursalOtraA->id]))
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->numero_empleado)->toBe('EMP-777');
});

it('8. cambiar sucursal/área NO limpia el servicio operativo vigente', function () {
    $servicio = Servicio::factory()->for(Contrato::factory()->for($this->empresaA))->for($this->sucursalZapata)->create();
    $colaborador = Colaborador::factory()->for($this->empresaA)->for($this->sucursalZapata)->create(['servicio_actual_id' => $servicio->id]);

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$colaborador->id}", payloadEdicion($colaborador, ['sucursal_id' => $this->sucursalOtraA->id]))
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->servicio_actual_id)->toBe($servicio->id);
});

it('9. la auditoría muestra la sucursal anterior/nueva y el área anterior/nueva de forma legible, no ids crudos', function () {
    // `area` (texto) es el espejo que sincroniza `ColaboradorController` en
    // cada guardado real; se fija aquí a mano porque el factory no lo
    // deriva de `area_id` por sí solo.
    $colaborador = Colaborador::factory()->for($this->empresaA)->for($this->sucursalZapata)->create(['area_id' => $this->areaA1->id, 'area' => $this->areaA1->nombre]);

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$colaborador->id}", payloadEdicion($colaborador, [
            'sucursal_id' => $this->sucursalOtraA->id,
            'area_id' => $this->areaA2->id,
        ]))
        ->assertSessionHasNoErrors();

    $registro = BitacoraAuditoria::query()->where('tipo_entidad', Colaborador::class)->where('entidad_id', $colaborador->id)->where('accion', 'editar')->latest('id')->first();

    expect($registro)->not->toBeNull()
        ->and($registro->valores_anteriores['sucursal'])->toBe('Zapata')
        ->and($registro->valores_nuevos['sucursal'])->toBe('Civac')
        ->and($registro->valores_anteriores['area'])->toBe('Almacén')
        ->and($registro->valores_nuevos['area'])->toBe('Administración');

    $descripcionAuditoria = app(DescripcionAuditoria::class);
    $cambios = $descripcionAuditoria->cambios(Colaborador::class, $registro->valores_anteriores, $registro->valores_nuevos);

    expect(collect($cambios))->toContain(['campo' => 'Sucursal', 'antes' => 'Zapata', 'ahora' => 'Civac'])
        ->and(collect($cambios))->toContain(['campo' => 'Área', 'antes' => 'Almacén', 'ahora' => 'Administración']);
});

it('9b. editar un colaborador SIN cambiar sucursal no agrega una clave "sucursal" ficticia a la auditoría', function () {
    $colaborador = Colaborador::factory()->for($this->empresaA)->for($this->sucursalZapata)->create(['puesto' => 'Auxiliar']);

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$colaborador->id}", payloadEdicion($colaborador, ['puesto' => 'Supervisor']))
        ->assertSessionHasNoErrors();

    $registro = BitacoraAuditoria::query()->where('tipo_entidad', Colaborador::class)->where('entidad_id', $colaborador->id)->where('accion', 'editar')->latest('id')->first();

    expect($registro->valores_nuevos)->not->toHaveKey('sucursal');
});

it('10. el histórico laboral refleja el cambio de sucursal/área como un movimiento interno, sin crear un nuevo periodo de empresa', function () {
    $colaborador = Colaborador::factory()->for($this->empresaA)->for($this->sucursalZapata)->create(['area_id' => $this->areaA1->id, 'area' => $this->areaA1->nombre]);

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$colaborador->id}", payloadEdicion($colaborador, [
            'sucursal_id' => $this->sucursalOtraA->id,
            'area_id' => $this->areaA2->id,
        ]))
        ->assertSessionHasNoErrors();

    $periodos = app(ServicioHistoricoColaborador::class)->construir($colaborador->fresh());

    // Sigue siendo UN solo periodo (misma empresa, no se simuló una
    // transferencia) con el movimiento interno registrado dentro de él.
    expect($periodos)->toHaveCount(1)
        ->and($periodos[0]['movimientosInternos'])->toHaveCount(1)
        ->and($periodos[0]['movimientosInternos'][0]['sucursal_anterior'])->toBe('Zapata')
        ->and($periodos[0]['movimientosInternos'][0]['sucursal_nueva'])->toBe('Civac')
        ->and($periodos[0]['movimientosInternos'][0]['area_anterior'])->toBe('Almacén')
        ->and($periodos[0]['movimientosInternos'][0]['area_nueva'])->toBe('Administración');
});

it('11. Cambiar servicio sigue siendo una operación separada: editar colaborador no acepta servicio_id', function () {
    $servicioOriginal = Servicio::factory()->for(Contrato::factory()->for($this->empresaA))->for($this->sucursalZapata)->create();
    $servicioNuevo = Servicio::factory()->for(Contrato::factory()->for($this->empresaA))->for($this->sucursalZapata)->create();
    $colaborador = Colaborador::factory()->for($this->empresaA)->for($this->sucursalZapata)->create(['servicio_actual_id' => $servicioOriginal->id]);

    $this->actingAs($this->admin)
        ->put("/colaboradores/{$colaborador->id}", payloadEdicion($colaborador, ['servicio_id' => $servicioNuevo->id]))
        ->assertSessionHasNoErrors();

    // El campo `servicio_id` enviado en el formulario general se ignora: no
    // existe esa regla en `GuardarColaboradorRequest`, sólo
    // `CambiarServicioColaboradorRequest` (ruta separada) lo acepta.
    expect($colaborador->fresh()->servicio_actual_id)->toBe($servicioOriginal->id);
});

it('12. la custodia pendiente sigue bloqueando la transferencia entre empresas (CambiarEmpresaColaborador intacto)', function () {
    $colaborador = Colaborador::factory()->for($this->empresaA)->for($this->sucursalZapata)->create();
    $entrega = EntregaUniforme::factory()->for($this->empresaA)->for($this->sucursalZapata)->for($colaborador)
        ->create(['estado' => EstadoEntrega::Firmada]);
    DetalleEntrega::factory()->for($entrega, 'entrega')->create([
        'unidad_activo_id' => null,
        'cantidad' => 2,
        'activo_nombre_snapshot' => 'Camisa',
        'talla_valor_snapshot' => 'M',
    ]);

    expect(fn () => app(CambiarEmpresaColaborador::class)->ejecutar(
        $colaborador, $this->empresaB->id, $this->sucursalB->id, $this->areaB->id, 'Prueba de bloqueo',
    ))->toThrow(ExcepcionDeNegocio::class);

    expect($colaborador->fresh()->empresa_id)->toBe($this->empresaA->id);
});
