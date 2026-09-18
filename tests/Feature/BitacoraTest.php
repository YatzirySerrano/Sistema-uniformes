<?php

use App\Acciones\AjustarInventario;
use App\Acciones\RegistrarEntradaInventario;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Area;
use App\Models\BitacoraAuditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\UnidadActivo;

it('transforma el before/after de una edición a "Campo | Antes | Ahora" legible y oculta las claves *_id', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create([
        'nombre_completo' => 'Juan Pérez',
        'puesto' => 'Auxiliar',
        'numero_empleado' => 'EMP-001',
    ]);

    $this->actingAs($admin)
        ->put("/colaboradores/{$colaborador->id}", [
            'numero_empleado' => 'EMP-001',
            'nombre_completo' => 'Juan Pérez',
            'curp' => $colaborador->curp,
            'sucursal_id' => $sucursal->id,
            'puesto' => 'Supervisor',
            'activo' => true,
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->get('/auditoria')
        ->assertInertia(fn ($page) => $page
            ->component('Auditoria/Index')
            ->where('registros.data.0.modulo', 'colaboradores')
            ->where('registros.data.0.accion', 'editar')
            ->where('registros.data.0.cambios', fn ($cambios) => collect($cambios)->contains(
                fn ($c) => $c['campo'] === 'Puesto' && $c['antes'] === 'Auxiliar' && $c['ahora'] === 'Supervisor',
            ))
            ->where('registros.data.0.cambios', fn ($cambios) => collect($cambios)
                ->pluck('campo')->doesntContain(fn ($campo) => str_contains(strtolower($campo), 'id')),
            ),
        );
});

it('un activar/desactivar queda con before/after "Estado" humano', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);
    $area = Area::factory()->for($empresa)->create(['activa' => true]);

    $this->actingAs($admin)->post("/areas/{$area->id}/estado")->assertRedirect();

    $this->actingAs($admin)
        ->get('/auditoria')
        ->assertInertia(fn ($page) => $page
            ->where('registros.data.0.accion', 'desactivar')
            ->where('registros.data.0.cambios', fn ($cambios) => collect($cambios)->contains(
                fn ($c) => $c['campo'] === 'Estado' && $c['antes'] === 'Activo' && $c['ahora'] === 'Inactivo',
            )),
        );
});

it('una incidencia de unidad queda con before/after de Condición usando las etiquetas del enum', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->seguimientoIndividual()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);
    $unidad = UnidadActivo::factory()->for($empresa)->for($activo)->for($almacen)->asignada()->create();

    $this->actingAs($admin)
        ->post("/activos/unidades/{$unidad->public_token}/incidencia", [
            'tipo' => 'perdido',
            'motivo' => 'No se localiza',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->get('/auditoria')
        ->assertInertia(fn ($page) => $page
            ->where('registros.data.0.accion', 'unidad_incidencia')
            ->where('registros.data.0.cambios', fn ($cambios) => collect($cambios)->contains(
                fn ($c) => $c['campo'] === 'Condición' && $c['antes'] === 'Funcionando' && $c['ahora'] === 'Perdido',
            )),
        );
});

it('un rol restringido no ve en la bitácora los eventos de una empresa fuera de su alcance', function () {
    sembrarRolesPermisos();
    $empresaPropia = Empresa::factory()->create();
    $empresaAjena = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresaPropia, $empresaAjena]);

    $areaPropia = Area::factory()->for($empresaPropia)->create(['activa' => true]);
    $areaAjena = Area::factory()->for($empresaAjena)->create(['activa' => true]);
    $this->actingAs($admin)->post("/areas/{$areaPropia->id}/estado");
    $this->actingAs($admin)->post("/areas/{$areaAjena->id}/estado");

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresaPropia]);
    $supervisor->givePermissionTo('auditoria.ver');

    $this->actingAs($supervisor)
        ->get('/auditoria')
        ->assertInertia(fn ($page) => $page
            ->has('registros.data', 1)
            ->where('registros.data.0.entidad', 'Area #'.$areaPropia->id),
        );
});

it('pagina la bitácora del lado del servidor respetando el tamaño de página configurado', function () {
    sembrarRolesPermisos();
    config(['uniformes.por_pagina' => 2]);
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    foreach (range(1, 3) as $i) {
        $area = Area::factory()->for($empresa)->create(['activa' => true]);
        $this->actingAs($admin)->post("/areas/{$area->id}/estado");
    }

    $this->actingAs($admin)
        ->get('/auditoria')
        ->assertInertia(fn ($page) => $page
            ->has('registros.data', 2)
            ->where('registros.total', 3),
        );

    $this->actingAs($admin)
        ->get('/auditoria?page=2')
        ->assertInertia(fn ($page) => $page->has('registros.data', 1));
});

/**
 * Regresión: `bitacora_auditoria.valores_anteriores`/`valores_nuevos` son
 * JSON histórico sin garantía de forma — algunas acciones (p. ej.
 * `CrearEntregaUniforme` guarda `$entrega->load('detalles')->toArray()`)
 * guardan arrays anidados, no sólo escalares. `DescripcionAuditoria` hacía
 * `(string) $valor` directo sobre esos valores y PHP lanzaba
 * `ErrorException: Array to string conversion`, tumbando TODO `/auditoria`
 * en cuanto existía un registro así — para cualquier usuario, Superadmin o
 * Admin, con alcance limitado o no.
 */
it('GET /auditoria responde 200 aunque existan registros históricos con arrays y estructuras anidadas', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    BitacoraAuditoria::query()->create([
        'empresa_id' => $empresa->id,
        'modulo' => 'entregas',
        'accion' => 'crear',
        'descripcion' => 'Entrega histórica con detalles anidados',
        'valores_anteriores' => null,
        'valores_nuevos' => [
            'folio' => 'ENT-0001',
            'detalles' => [
                ['activo_nombre_snapshot' => 'Camisa', 'cantidad' => 2],
                ['activo_nombre_snapshot' => 'Pantalón', 'cantidad' => 1],
            ],
        ],
    ]);

    BitacoraAuditoria::query()->create([
        'empresa_id' => $empresa->id,
        'modulo' => 'almacenes',
        'accion' => 'empresas',
        'descripcion' => 'Cambio de empresas abastecidas',
        'valores_anteriores' => ['empresas' => [1, 2]],
        'valores_nuevos' => ['empresas' => [1, 2, 3]],
    ]);

    $this->actingAs($admin)
        ->get('/auditoria')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auditoria/Index')
            ->has('registros.data', 2)
            // El JSON técnico crudo sigue disponible aparte del diff humano.
            ->has('registros.data.0.valores_nuevos')
            // `->latest()`: el registro de almacenes (creado después) va primero.
            ->where('registros.data.0.cambios', fn ($cambios) => collect($cambios)->contains(
                fn ($c) => $c['campo'] === 'Empresas' && $c['antes'] === '1, 2' && $c['ahora'] === '1, 2, 3',
            )),
        );
});

it('Superadmin también puede abrir /auditoria con registros de estructura compleja', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $superadmin = usuarioCon(RolSistema::Superadministrador->value);

    BitacoraAuditoria::query()->create([
        'empresa_id' => $empresa->id,
        'modulo' => 'roles',
        'accion' => 'permisos',
        'valores_anteriores' => ['permisos' => ['ver', 'editar']],
        'valores_nuevos' => ['permisos' => ['ver', 'editar', 'administrar']],
    ]);

    $this->actingAs($superadmin)
        ->get('/auditoria')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auditoria/Index')->has('registros.data', 1));
});

// ------------------------------------------------------------------
// Filtro por categoría (Creación/Actualización/Eliminación/Reactivación):
// se aplica en BACKEND antes de paginar/exportar; las acciones operativas
// (ajuste, entrada, incidencia…) se conservan sin forzarlas a una categoría.
// ------------------------------------------------------------------

it('el filtro de categoría "Eliminación" sólo devuelve desactivaciones/bajas reales, no ediciones normales ni acciones operativas', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $area = Area::factory()->for($empresa)->create(['activa' => true]);
    $this->actingAs($admin)->post("/areas/{$area->id}/estado")->assertRedirect(); // desactivar -> Eliminación

    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create(['puesto' => 'Auxiliar']);
    $this->actingAs($admin)->put("/colaboradores/{$colaborador->id}", [
        'numero_empleado' => $colaborador->numero_empleado,
        'nombre_completo' => $colaborador->nombre_completo,
        'curp' => $colaborador->curp,
        'sucursal_id' => $sucursal->id,
        'puesto' => 'Supervisor',
        'activo' => true,
    ])->assertSessionHasNoErrors(); // editar -> Actualización, no debe salir

    $this->actingAs($admin)
        ->get('/auditoria?categoria=eliminacion')
        ->assertInertia(fn ($page) => $page
            ->has('registros.data', 1)
            ->where('registros.data.0.accion', 'desactivar')
            ->where('registros.data.0.categoria', 'eliminacion'),
        );
});

it('el filtro de categoría "Reactivación" encuentra una activación aunque haya desactivaciones y ediciones de por medio', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);
    $area = Area::factory()->for($empresa)->create(['activa' => true]);

    $this->actingAs($admin)->post("/areas/{$area->id}/estado"); // desactivar
    $this->actingAs($admin)->post("/areas/{$area->id}/estado"); // activar

    $this->actingAs($admin)
        ->get('/auditoria?categoria=reactivacion')
        ->assertInertia(fn ($page) => $page
            ->has('registros.data', 1)
            ->where('registros.data.0.accion', 'activar')
            ->where('registros.data.0.categoria', 'reactivacion'),
        );
});

it('el filtro de categoría se aplica ANTES de paginar: el total refleja sólo lo filtrado', function () {
    sembrarRolesPermisos();
    config(['uniformes.por_pagina' => 2]);
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    foreach (range(1, 3) as $i) {
        $area = Area::factory()->for($empresa)->create(['activa' => true]);
        $this->actingAs($admin)->post("/areas/{$area->id}/estado"); // 3x desactivar -> Eliminación
    }
    $colaborador = Colaborador::factory()->for($empresa)->for(Sucursal::factory()->for($empresa))->create();
    $this->actingAs($admin)->put("/colaboradores/{$colaborador->id}", [
        'numero_empleado' => $colaborador->numero_empleado,
        'nombre_completo' => $colaborador->nombre_completo,
        'curp' => $colaborador->curp,
        'sucursal_id' => $colaborador->sucursal_id,
        'activo' => true,
    ]); // editar -> no debe contar

    $this->actingAs($admin)
        ->get('/auditoria?categoria=eliminacion')
        ->assertInertia(fn ($page) => $page
            ->has('registros.data', 2) // página 1 de 2 (por_pagina=2)
            ->where('registros.total', 3),
        );
});

it('una acción operativa (ajuste de inventario) no clasifica en ninguna categoría y sigue visible sin filtro', function () {
    sembrarRolesPermisos();
    $datos = escenarioMultiempresa();
    $admin = usuarioCon(RolSistema::Administrador->value, [$datos['empresaA']]);

    app(RegistrarEntradaInventario::class)->ejecutar(
        $datos['empresaA']->id, $datos['almacenA']->id,
        [['activo_id' => $datos['activoA']->id, 'talla_id' => $datos['tallaA']->id, 'cantidad' => 10]],
        'Alta', null,
    );
    app(AjustarInventario::class)->ejecutar(
        $datos['empresaA']->id, $datos['almacenA']->id, $datos['activoA']->id, $datos['tallaA']->id, 5, 'Conteo físico', null,
    );

    $this->actingAs($admin)
        ->get('/auditoria')
        ->assertInertia(fn ($page) => $page->where(
            'registros.data',
            fn ($data) => collect($data)->contains(fn ($r) => $r['accion'] === 'ajuste' && $r['categoria'] === null),
        ));

    // No debe aparecer en ninguna de las 4 categorías CRUD.
    foreach (['creacion', 'actualizacion', 'eliminacion', 'reactivacion'] as $categoria) {
        $this->actingAs($admin)
            ->get("/auditoria?categoria={$categoria}")
            ->assertInertia(fn ($page) => $page->where(
                'registros.data',
                fn ($data) => collect($data)->doesntContain(fn ($r) => $r['accion'] === 'ajuste'),
            ));
    }
});

it('el filtro de categoría expone las 4 opciones comprensibles al frontend', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)
        ->get('/auditoria')
        ->assertInertia(fn ($page) => $page
            ->has('categorias', 4)
            ->where('categorias', fn ($cats) => collect($cats)->pluck('valor')->all() === ['creacion', 'actualizacion', 'eliminacion', 'reactivacion']),
        );
});
