<?php

use App\Http\Controllers\ActivoController;
use App\Http\Controllers\AcuseController;
use App\Http\Controllers\AlmacenController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\BitacoraController;
use App\Http\Controllers\CatalogoActivoController;
use App\Http\Controllers\CategoriaActivoController;
use App\Http\Controllers\ColaboradorController;
use App\Http\Controllers\CorreccionEntregaController;
use App\Http\Controllers\DevolucionController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\EntregaController;
use App\Http\Controllers\ImportacionColaboradorController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\MovimientoInventarioController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\PersonalizacionEmpresaController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\TallaController;
use App\Http\Controllers\TipoActivoController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'usuario.activo'])->group(function (): void {
    Route::get('dashboard', [PanelController::class, 'index'])->name('dashboard');

    // Portal del colaborador
    Route::get('portal/mis-entregas', [PortalController::class, 'index'])->name('portal.mis-entregas');

    // Colaboradores
    Route::get('colaboradores', [ColaboradorController::class, 'index'])->name('colaboradores.index');
    Route::get('colaboradores/importar', [ImportacionColaboradorController::class, 'create'])->name('colaboradores.importar');
    Route::get('colaboradores/importar/plantilla', [ImportacionColaboradorController::class, 'plantilla'])->name('colaboradores.importar.plantilla');
    Route::post('colaboradores/importar/analizar', [ImportacionColaboradorController::class, 'analizar'])->name('colaboradores.importar.analizar');
    Route::post('colaboradores/importar/confirmar', [ImportacionColaboradorController::class, 'confirmar'])->name('colaboradores.importar.confirmar');
    Route::get('colaboradores/crear', [ColaboradorController::class, 'create'])->name('colaboradores.create');
    Route::post('colaboradores', [ColaboradorController::class, 'store'])->name('colaboradores.store');
    Route::get('colaboradores/{colaborador}/editar', [ColaboradorController::class, 'edit'])->name('colaboradores.edit');
    Route::put('colaboradores/{colaborador}', [ColaboradorController::class, 'update'])->name('colaboradores.update');
    Route::post('colaboradores/{colaborador}/estado', [ColaboradorController::class, 'toggle'])->name('colaboradores.toggle');

    // Áreas / Departamentos
    Route::get('areas', [AreaController::class, 'index'])->name('areas.index');
    Route::post('areas', [AreaController::class, 'store'])->name('areas.store');
    Route::get('areas/{area}', [AreaController::class, 'show'])->name('areas.show');
    Route::put('areas/{area}', [AreaController::class, 'update'])->name('areas.update');
    Route::post('areas/{area}/estado', [AreaController::class, 'toggle'])->name('areas.toggle');

    // Activos y variantes / tallas
    Route::redirect('prendas', 'activos');
    Route::get('activos', [ActivoController::class, 'index'])->name('activos.index');
    Route::get('activos/buscar', [ActivoController::class, 'buscar'])->name('activos.buscar');
    Route::get('activos/crear', [ActivoController::class, 'create'])->name('activos.create');
    Route::post('activos', [ActivoController::class, 'store'])->name('activos.store');
    Route::get('activos/{activo}', [ActivoController::class, 'show'])->name('activos.show');
    Route::get('activos/{activo}/editar', [ActivoController::class, 'edit'])->name('activos.edit');
    Route::post('activos/{activo}', [ActivoController::class, 'update'])->name('activos.update'); // POST por subida de imagen
    Route::post('activos/{activo}/estado', [ActivoController::class, 'toggle'])->name('activos.toggle');

    Route::get('tallas', [TallaController::class, 'index'])->name('tallas.index');
    Route::post('tallas', [TallaController::class, 'store'])->name('tallas.store');
    Route::post('tallas/rapido', [TallaController::class, 'rapido'])->name('tallas.rapido');
    Route::post('tallas/reordenar', [TallaController::class, 'reordenar'])->name('tallas.reordenar');
    Route::put('tallas/{talla}', [TallaController::class, 'update'])->name('tallas.update');
    Route::delete('tallas/{talla}', [TallaController::class, 'destroy'])->name('tallas.destroy');

    // Catálogos de activos: tipos y categorías (dentro del área de Activos)
    Route::get('activos-catalogos', [CatalogoActivoController::class, 'index'])->name('activos.catalogos');
    Route::get('tipos-activo/buscar', [TipoActivoController::class, 'buscar'])->name('tipos-activo.buscar');
    Route::post('tipos-activo', [TipoActivoController::class, 'store'])->name('tipos-activo.store');
    Route::post('tipos-activo/rapido', [TipoActivoController::class, 'rapido'])->name('tipos-activo.rapido');
    Route::put('tipos-activo/{tipo}', [TipoActivoController::class, 'update'])->name('tipos-activo.update');
    Route::post('tipos-activo/{tipo}/estado', [TipoActivoController::class, 'toggle'])->name('tipos-activo.toggle');
    Route::get('categorias-activo/buscar', [CategoriaActivoController::class, 'buscar'])->name('categorias-activo.buscar');
    Route::post('categorias-activo', [CategoriaActivoController::class, 'store'])->name('categorias-activo.store');
    Route::post('categorias-activo/rapido', [CategoriaActivoController::class, 'rapido'])->name('categorias-activo.rapido');
    Route::put('categorias-activo/{categoria}', [CategoriaActivoController::class, 'update'])->name('categorias-activo.update');
    Route::post('categorias-activo/{categoria}/estado', [CategoriaActivoController::class, 'toggle'])->name('categorias-activo.toggle');

    // Inventario por almacén
    Route::get('inventario', [InventarioController::class, 'index'])->name('inventario.index');
    Route::get('inventario/entrada', [InventarioController::class, 'formularioEntrada'])->name('inventario.entrada-formulario');
    Route::post('inventario/entrada', [InventarioController::class, 'entrada'])->name('inventario.entrada');
    Route::post('inventario/ajuste', [InventarioController::class, 'ajuste'])->name('inventario.ajuste');
    Route::post('inventario/minimos', [InventarioController::class, 'minimos'])->name('inventario.minimos');
    Route::get('inventario/movimientos', [MovimientoInventarioController::class, 'index'])->name('inventario.movimientos');

    // Entregas
    Route::get('entregas', [EntregaController::class, 'index'])->name('entregas.index');
    Route::get('entregas/crear', [EntregaController::class, 'create'])->name('entregas.create');
    Route::get('entregas/disponibilidad', [EntregaController::class, 'disponibilidad'])->name('entregas.disponibilidad');
    Route::post('entregas', [EntregaController::class, 'store'])->name('entregas.store');
    Route::get('entregas/{entrega}', [EntregaController::class, 'show'])->name('entregas.show');
    Route::get('entregas/{entrega}/corregir', [CorreccionEntregaController::class, 'create'])->name('entregas.corregir.create');
    Route::post('entregas/{entrega}/corregir', [CorreccionEntregaController::class, 'store'])->name('entregas.corregir.store');

    // Acuses / firma / comprobantes
    Route::get('entregas/{entrega}/firmar', [AcuseController::class, 'firmar'])->name('acuses.firmar');
    Route::post('entregas/{entrega}/firmar', [AcuseController::class, 'confirmar'])->name('acuses.confirmar');
    Route::get('acuses/{acuse}/pdf', [AcuseController::class, 'pdf'])->name('acuses.pdf');
    Route::get('acuses/{acuse}/firma', [AcuseController::class, 'firma'])->name('acuses.firma');
    Route::post('acuses/{acuse}/regenerar-pdf', [AcuseController::class, 'regenerarPdf'])->name('acuses.regenerar-pdf');

    // Devoluciones
    Route::get('devoluciones', [DevolucionController::class, 'index'])->name('devoluciones.index');
    Route::get('devoluciones/crear', [DevolucionController::class, 'create'])->name('devoluciones.create');
    Route::post('devoluciones', [DevolucionController::class, 'store'])->name('devoluciones.store');

    // Reportes
    Route::get('reportes', [ReporteController::class, 'index'])->name('reportes.index');
    Route::get('reportes/entregas/exportar', [ReporteController::class, 'exportarEntregas'])->name('reportes.entregas.exportar');
    Route::get('reportes/inventario/exportar', [ReporteController::class, 'exportarInventario'])->name('reportes.inventario.exportar');

    // Administración
    Route::get('empresas', [EmpresaController::class, 'index'])->name('empresas.index');
    Route::get('empresas/buscar', [EmpresaController::class, 'buscar'])->name('empresas.buscar');
    Route::post('empresas', [EmpresaController::class, 'store'])->name('empresas.store');
    Route::get('empresas/{empresa}', [EmpresaController::class, 'show'])->name('empresas.show');
    Route::put('empresas/{empresa}', [EmpresaController::class, 'update'])->name('empresas.update');
    Route::post('empresas/{empresa}/estado', [EmpresaController::class, 'toggleEstado'])->name('empresas.estado');

    Route::get('empresas/{empresa}/personalizacion', [PersonalizacionEmpresaController::class, 'edit'])->name('personalizacion.edit');
    Route::post('empresas/{empresa}/personalizacion', [PersonalizacionEmpresaController::class, 'update'])->name('personalizacion.update');

    // Almacenes
    Route::get('almacenes', [AlmacenController::class, 'index'])->name('almacenes.index');
    Route::get('almacenes/buscar', [AlmacenController::class, 'buscar'])->name('almacenes.buscar');
    Route::get('almacenes/colaboradores-buscar', [AlmacenController::class, 'colaboradoresBuscar'])->name('almacenes.colaboradores-buscar');
    Route::post('almacenes', [AlmacenController::class, 'store'])->name('almacenes.store');
    Route::get('almacenes/{almacen}', [AlmacenController::class, 'show'])->name('almacenes.show');
    Route::put('almacenes/{almacen}', [AlmacenController::class, 'update'])->name('almacenes.update');
    Route::post('almacenes/{almacen}/estado', [AlmacenController::class, 'toggle'])->name('almacenes.toggle');

    Route::get('sucursales', [SucursalController::class, 'index'])->name('sucursales.index');
    Route::post('sucursales', [SucursalController::class, 'store'])->name('sucursales.store');
    Route::get('sucursales/{sucursal}', [SucursalController::class, 'show'])->name('sucursales.show');
    Route::put('sucursales/{sucursal}', [SucursalController::class, 'update'])->name('sucursales.update');
    Route::post('sucursales/{sucursal}/estado', [SucursalController::class, 'toggle'])->name('sucursales.toggle');

    Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
    Route::get('usuarios/crear', [UsuarioController::class, 'create'])->name('usuarios.create');
    Route::post('usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
    Route::get('usuarios/{usuario}/editar', [UsuarioController::class, 'edit'])->name('usuarios.edit');
    Route::put('usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');
    Route::post('usuarios/{usuario}/estado', [UsuarioController::class, 'toggle'])->name('usuarios.toggle');

    Route::get('roles', [RolController::class, 'index'])->name('roles.index');
    Route::post('roles', [RolController::class, 'store'])->name('roles.store');
    Route::put('roles/{rol}', [RolController::class, 'update'])->name('roles.update');
    Route::delete('roles/{rol}', [RolController::class, 'destroy'])->name('roles.destroy');

    Route::get('auditoria', [BitacoraController::class, 'index'])->name('auditoria.index');
});
