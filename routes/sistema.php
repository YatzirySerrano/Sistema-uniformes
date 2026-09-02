<?php

use App\Http\Controllers\AcuseController;
use App\Http\Controllers\BitacoraController;
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
use App\Http\Controllers\PrendaController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\SelectorEmpresaController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\TallaController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'usuario.activo'])->group(function (): void {
    Route::get('dashboard', [PanelController::class, 'index'])->name('dashboard');

    Route::post('empresa-activa', [SelectorEmpresaController::class, 'update'])->name('empresa-activa.update');

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

    // Prendas y tallas
    Route::get('prendas', [PrendaController::class, 'index'])->name('prendas.index');
    Route::get('prendas/crear', [PrendaController::class, 'create'])->name('prendas.create');
    Route::post('prendas', [PrendaController::class, 'store'])->name('prendas.store');
    Route::get('prendas/{prenda}', [PrendaController::class, 'show'])->name('prendas.show');
    Route::get('prendas/{prenda}/editar', [PrendaController::class, 'edit'])->name('prendas.edit');
    Route::post('prendas/{prenda}', [PrendaController::class, 'update'])->name('prendas.update'); // POST por subida de imagen

    Route::get('tallas', [TallaController::class, 'index'])->name('tallas.index');
    Route::post('tallas', [TallaController::class, 'store'])->name('tallas.store');
    Route::put('tallas/{talla}', [TallaController::class, 'update'])->name('tallas.update');
    Route::delete('tallas/{talla}', [TallaController::class, 'destroy'])->name('tallas.destroy');

    // Inventario
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
    Route::post('empresas', [EmpresaController::class, 'store'])->name('empresas.store');
    Route::get('empresas/{empresa}', [EmpresaController::class, 'show'])->name('empresas.show');
    Route::put('empresas/{empresa}', [EmpresaController::class, 'update'])->name('empresas.update');
    Route::post('empresas/{empresa}/estado', [EmpresaController::class, 'toggleEstado'])->name('empresas.estado');

    Route::get('personalizacion', [PersonalizacionEmpresaController::class, 'edit'])->name('personalizacion.edit');
    Route::post('personalizacion', [PersonalizacionEmpresaController::class, 'update'])->name('personalizacion.update');

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
