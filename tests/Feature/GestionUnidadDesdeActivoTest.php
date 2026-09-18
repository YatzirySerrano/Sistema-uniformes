<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\BitacoraAuditoria;
use App\Models\Empresa;
use App\Models\UnidadActivo;

/**
 * Objetivo: gestionar estado/condición de unidades de seguimiento individual
 * desde el DETALLE DEL ACTIVO (`Activos/Detalle.vue` → `GestionarUnidadDialog.vue`),
 * reutilizando `UnidadActivoController::buscar()` (ahora expone `public_token`/
 * `estado`/`condicion`, necesarios para localizar y accionar la unidad sin
 * abrir su página propia) y las acciones YA existentes
 * (`MarcarUnidadIncidencia`, `DarDeBajaUnidadActivo`, `RecuperarUnidadActivo`,
 * `RestaurarCondicionUnidadActivo`, sin cambios). Las reglas de transición de
 * cada acción ya están cubiertas en `UnidadActivoTest.php`/
 * `RestaurarCondicionUnidadTest.php`; aquí sólo se cubre lo que cambió: los
 * campos nuevos expuestos y el flujo completo búsqueda → token → acción.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $this->activo = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
});

it('la búsqueda de unidades expone public_token, estado y condición para poder accionarlas desde el detalle del activo', function () {
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($this->activo)->for($this->datos['almacenA'])->asignada()->create();

    $resultado = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$this->activo->id}")
        ->assertOk()->json('unidades.0');

    expect($resultado['public_token'])->toBe($unidad->public_token)
        ->and($resultado['estado'])->toBe('asignada')
        ->and($resultado['condicion'])->toBe('funcionando');
});

it('el detalle del activo expone las listas de condición sólo para activos de seguimiento individual', function () {
    $activoCantidad = Activo::factory()->for($this->datos['empresaA'])->create();

    $this->actingAs($this->admin)
        ->get("/activos/{$this->activo->id}")
        ->assertInertia(fn ($page) => $page
            ->has('condicionesIncidencia', 2)
            ->has('condicionesNoIncidencia', 3)
        );

    $this->actingAs($this->admin)
        ->get("/activos/{$activoCantidad->id}")
        ->assertInertia(fn ($page) => $page
            ->where('condicionesIncidencia', null)
            ->where('condicionesNoIncidencia', null)
        );
});

it('flujo completo: localizar una unidad por búsqueda y marcarle una incidencia usando su public_token', function () {
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($this->activo)->for($this->datos['almacenA'])->asignada()->create();

    $token = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$this->activo->id}")
        ->json('unidades.0.public_token');

    $this->actingAs($this->admin)
        ->post("/activos/unidades/{$token}/incidencia", [
            'tipo' => 'robado',
            'motivo' => 'Reportado por el colaborador',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($unidad->fresh()->condicion->value)->toBe('robado')
        ->and(BitacoraAuditoria::query()->where('accion', 'unidad_incidencia')->exists())->toBeTrue();
});

it('el flujo completo respeta el aislamiento de empresa: un rol restringido de otra empresa no ve la unidad en la búsqueda ni puede accionarla por token adivinado', function () {
    // Administrador/Superadministrador tienen alcance GLOBAL por diseño
    // (ver `.ai/rules/policies.md`); el aislamiento por empresa se prueba
    // con un rol restringido (Supervisor/Encargado).
    $otraEmpresa = Empresa::factory()->create();
    $supervisorAjeno = usuarioCon(RolSistema::Supervisor->value, [$otraEmpresa]);
    $unidadAjena = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($this->activo)->for($this->datos['almacenA'])->asignada()->create();

    $resultado = $this->actingAs($supervisorAjeno)
        ->getJson("/activos/unidades/buscar?activo_id={$this->activo->id}")
        ->json('unidades');
    expect($resultado)->toBe([]);

    $respuesta = $this->actingAs($supervisorAjeno)
        ->post("/activos/unidades/{$unidadAjena->public_token}/incidencia", [
            'tipo' => 'robado',
            'motivo' => 'Intento no autorizado',
        ]);
    expect($respuesta->status())->toBeIn([403, 404]);

    expect($unidadAjena->fresh()->condicion->value)->toBe('funcionando');
});
