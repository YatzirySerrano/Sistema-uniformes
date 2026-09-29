<?php

use App\Enums\RolSistema;
use App\Exports\ListadoExport;
use App\Models\BitacoraAuditoria;
use App\Models\Empresa;
use App\Models\User;
use App\Servicios\ServicioAuditoria;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;

/**
 * Privacidad TOTAL e HISTÓRICA de las acciones del Superadministrador en la
 * bitácora: ningún otro usuario — Administrador, roles base o personalizados,
 * con `auditoria.ver` — puede verlas, buscarlas, filtrarlas, exportarlas ni
 * inferirlas, y eso no cambia si el actor pierde el rol o se elimina.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->superadmin = usuarioCon(RolSistema::Superadministrador->value, [$this->empresa]);
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);

    // Registra por la ÚNICA puerta real de escritura, como el actor dado.
    $this->registrarComo = function (User $actor, string $modulo, string $descripcion): BitacoraAuditoria {
        Auth::login($actor);
        $registro = app(ServicioAuditoria::class)->registrar($modulo, 'editar', [
            'empresa_id' => $this->empresa->id,
            'descripcion' => $descripcion,
        ]);
        Auth::logout();

        return $registro;
    };

    ($this->registrarComo)($this->superadmin, 'datos', 'Acción secreta del superadmin');
    ($this->registrarComo)($this->admin, 'empresas', 'Acción del administrador');

    $this->descripciones = function (User $observador, string $query = ''): array {
        return collect($this->actingAs($observador)->get("/auditoria{$query}")
            ->assertOk()
            ->viewData('page')['props']['registros']['data'])->pluck('descripcion')->all();
    };
});

it('congela en el registro si el actor era superadministrador al momento de la acción', function () {
    expect(BitacoraAuditoria::query()->where('descripcion', 'Acción secreta del superadmin')->value('realizada_por_superadministrador'))->toBeTrue()
        ->and(BitacoraAuditoria::query()->where('descripcion', 'Acción del administrador')->value('realizada_por_superadministrador'))->toBeFalse();
});

it('el superadministrador ve también las acciones de superadministradores', function () {
    expect(($this->descripciones)($this->superadmin))
        ->toContain('Acción secreta del superadmin', 'Acción del administrador');
});

it('ningún observador que no sea superadministrador ve acciones del superadministrador', function (User $observador) {
    expect(($this->descripciones)($observador))
        ->toContain('Acción del administrador')
        ->not->toContain('Acción secreta del superadmin');
})->with([
    'Administrador' => fn () => $this->admin,
    'Supervisor con auditoria.ver' => fn () => usuarioCon(RolSistema::Supervisor->value, [$this->empresa])
        ->givePermissionTo('auditoria.ver'),
    'Rol personalizado con auditoria.ver' => function () {
        $usuario = User::factory()->create();
        $usuario->assignRole(Role::create(['name' => 'auditor-externo', 'guard_name' => 'web'])->givePermissionTo('auditoria.ver'));
        $usuario->empresas()->sync([$this->empresa->id]);

        return $usuario;
    },
]);

it('ni la búsqueda ni los filtros permiten recuperarlas', function (string $query) {
    expect(($this->descripciones)($this->admin, $query))->not->toContain('Acción secreta del superadmin');
})->with([
    'búsqueda por descripción' => '?buscar=secreta',
    'búsqueda por nombre del actor' => fn () => '?buscar='.urlencode($this->superadmin->name),
    'filtro de módulo' => '?modulo=datos',
    'filtro de acción' => '?accion=editar',
    'filtro de categoría' => '?categoria=actualizacion',
]);

it('el total paginado y el catálogo de módulos no delatan su existencia', function () {
    $this->actingAs($this->admin)->get('/auditoria')
        ->assertInertia(fn ($page) => $page
            ->where('registros.total', 1)
            ->where('modulos', ['empresas']));
});

it('la exportación tampoco las incluye', function () {
    Excel::fake();

    $this->actingAs($this->admin)->get('/auditoria/exportar?formato=xlsx')->assertOk();

    Excel::assertDownloaded(
        'auditoria-todas-las-empresas-'.now()->toDateString().'.xlsx',
        function (ListadoExport $export): bool {
            $descripciones = collect($export->array())->pluck(4);

            return $descripciones->contains('Acción del administrador')
                && ! $descripciones->contains('Acción secreta del superadmin');
        },
    );
});

it('quitarle el rol al actor después NO vuelve visible su acción histórica', function () {
    $this->superadmin->syncRoles([RolSistema::Encargado->value]);

    expect(($this->descripciones)($this->admin))->not->toContain('Acción secreta del superadmin');
});

it('eliminar al actor NO vuelve visible su acción histórica', function () {
    $this->superadmin->delete();

    expect(BitacoraAuditoria::query()->where('descripcion', 'Acción secreta del superadmin')->value('usuario_id'))->toBeNull()
        ->and(($this->descripciones)($this->admin))->not->toContain('Acción secreta del superadmin');
});

it('un registro histórico sin snapshot cuyo actor fue eliminado queda oculto por precaución', function () {
    BitacoraAuditoria::query()->create([
        'usuario_id' => null,
        'nombre_usuario_snapshot' => 'Actor eliminado',
        'empresa_id' => $this->empresa->id,
        'modulo' => 'empresas',
        'accion' => 'editar',
        'descripcion' => 'Histórico sin evidencia',
    ]);

    expect(($this->descripciones)($this->admin))->not->toContain('Histórico sin evidencia')
        ->and(($this->descripciones)($this->superadmin))->toContain('Histórico sin evidencia');
});

it('el backfill sólo marca lo que puede inferirse y deja sin valor lo que no', function () {
    $degradado = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);
    $filaLegado = fn (?User $actor, string $descripcion, ?string $nombre = null): int => BitacoraAuditoria::query()->create([
        'usuario_id' => $actor?->id,
        'nombre_usuario_snapshot' => $actor?->name ?? $nombre,
        'modulo' => 'empresas',
        'accion' => 'editar',
        'descripcion' => $descripcion,
    ])->id;

    $deSuperadmin = $filaLegado($this->superadmin, 'legado superadmin');
    $deAdmin = $filaLegado($this->admin, 'legado admin');
    $deSistema = $filaLegado(null, 'legado sistema');
    $deEliminado = $filaLegado(null, 'legado eliminado', 'Alguien que ya no existe');
    $deDegradado = $filaLegado($degradado, 'legado degradado');
    // Evidencia auditada: `$degradado` tuvo el rol superadministrador.
    $filaCambioRol = $filaLegado($this->admin, 'cambio de rol');
    BitacoraAuditoria::query()->whereKey($filaCambioRol)->update([
        'modulo' => 'usuarios',
        'tipo_entidad' => User::class,
        'entidad_id' => $degradado->id,
        'valores_anteriores' => json_encode(['roles' => [RolSistema::Superadministrador->value]]),
        'valores_nuevos' => json_encode(['roles' => [RolSistema::Encargado->value]]),
    ]);

    $migracion = require database_path('migrations/2026_09_28_235009_add_realizada_por_superadministrador_a_bitacora_auditoria_table.php');
    $migracion->down();
    $migracion->up();

    $bandera = fn (int $id): ?bool => BitacoraAuditoria::query()->whereKey($id)->value('realizada_por_superadministrador');

    expect($bandera($deSuperadmin))->toBeTrue()
        ->and($bandera($deDegradado))->toBeTrue()
        ->and($bandera($deSistema))->toBeFalse()
        ->and($bandera($deAdmin))->toBeNull()
        ->and($bandera($deEliminado))->toBeNull();
});
