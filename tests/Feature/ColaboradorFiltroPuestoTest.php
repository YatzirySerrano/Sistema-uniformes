<?php

use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\Sucursal;

/**
 * Filtro "Puesto" del listado de colaboradores: `puesto` es texto libre, así
 * que se filtra por el valor normalizado (mayúsculas / espacios) y las
 * opciones son los valores distintos que existen dentro del alcance.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);
    $this->sucursalA2 = Sucursal::factory()->for($this->datos['empresaA'])->create();

    $crear = fn ($empresa, $sucursal, string $puesto, string $nombre) => Colaborador::factory()->for($empresa)->for($sucursal)
        ->create(['puesto' => $puesto, 'nombre_completo' => $nombre]);

    $crear($this->datos['empresaA'], $this->datos['sucursalA'], 'Guardia', 'Ana Guardia A1');
    $crear($this->datos['empresaA'], $this->sucursalA2, ' guardia ', 'Beto Guardia A2');
    $crear($this->datos['empresaA'], $this->datos['sucursalA'], 'Supervisor', 'Carla Supervisora');
    $crear($this->datos['empresaB'], $this->datos['sucursalB'], 'GUARDIA', 'Dani Guardia B');

    $this->nombres = fn (array $query): array => collect(
        $this->actingAs($this->admin)->get('/colaboradores?'.http_build_query($query))->viewData('page')['props']['colaboradores']['data']
    )->pluck('nombre_completo')->sort()->values()->all();
});

it('filtra por puesto sin distinguir mayúsculas ni espacios', function () {
    expect(($this->nombres)(['puesto' => 'guardia']))->toBe(['Ana Guardia A1', 'Beto Guardia A2', 'Dani Guardia B']);
});

it('combina el puesto con la empresa y con la sucursal', function () {
    expect(($this->nombres)(['puesto' => 'Guardia', 'empresa_id' => $this->datos['empresaA']->id]))
        ->toBe(['Ana Guardia A1', 'Beto Guardia A2']);

    expect(($this->nombres)(['puesto' => 'Guardia', 'empresa_id' => $this->datos['empresaA']->id, 'sucursal_id' => $this->sucursalA2->id]))
        ->toBe(['Beto Guardia A2']);
});

it('combina con la búsqueda y conserva el filtro en la paginación', function () {
    expect(($this->nombres)(['puesto' => 'guardia', 'buscar' => 'Beto']))->toBe(['Beto Guardia A2']);

    config(['uniformes.por_pagina' => 1]);
    $pagina = $this->actingAs($this->admin)->get('/colaboradores?puesto=guardia')->viewData('page')['props'];

    expect($pagina['filtros']['puesto'])->toBe('guardia')
        ->and($pagina['colaboradores']['next_page_url'])->toContain('puesto=guardia');
});

it('ofrece los puestos distintos existentes dentro del alcance, agrupando variantes', function () {
    $nombres = collect($this->actingAs($this->admin)
        ->getJson("/colaboradores/puestos?empresa_id={$this->datos['empresaA']->id}&q=r")
        ->assertOk()
        ->json('puestos'))->pluck('nombre');

    // "Guardia" y " guardia " son una sola opción; el de la empresa B no cuenta.
    expect($nombres->filter(fn (string $n): bool => strtolower($n) === 'guardia')->count())->toBe(1)
        ->and($nombres)->toContain('Supervisor');

    $restringido = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);
    $this->actingAs($restringido)
        ->getJson('/colaboradores/puestos?q=super')
        ->assertExactJson(['puestos' => []]);
});
