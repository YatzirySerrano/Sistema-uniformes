<?php

use App\Http\Controllers\ActivoController;
use App\Http\Controllers\AcuseController;
use App\Http\Controllers\AcuseDevolucionController;
use App\Http\Controllers\AlmacenController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\BitacoraController;
use App\Http\Controllers\CatalogoActivoController;
use App\Http\Controllers\CategoriaActivoController;
use App\Http\Controllers\ColaboradorController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\ConjuntoController;
use App\Http\Controllers\CorreccionEntregaController;
use App\Http\Controllers\DevolucionController;
use App\Http\Controllers\DocumentoExpedienteController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\EntregaController;
use App\Http\Controllers\ImportacionColaboradorController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\MovimientoInventarioController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\TallaController;
use App\Http\Controllers\TipoActivoController;
use App\Http\Controllers\UnidadActivoController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified', 'usuario.activo'])->group(function (): void {
    Route::get('dashboard', [PanelController::class, 'index'])->name('dashboard');

    // Guía ilustrada del sistema: estática, sin datos de negocio, disponible
    // para cualquier usuario autenticado sin importar su rol.
    Route::get('ayuda', fn () => Inertia::render('Ayuda/Index'))->name('ayuda');

    // Colaboradores
    Route::get('colaboradores', [ColaboradorController::class, 'index'])->name('colaboradores.index');
    Route::get('colaboradores/importar', [ImportacionColaboradorController::class, 'create'])->name('colaboradores.importar');
    Route::get('colaboradores/importar/plantilla', [ImportacionColaboradorController::class, 'plantilla'])->name('colaboradores.importar.plantilla');
    Route::post('colaboradores/importar/analizar', [ImportacionColaboradorController::class, 'analizar'])->name('colaboradores.importar.analizar');
    Route::post('colaboradores/importar/confirmar', [ImportacionColaboradorController::class, 'confirmar'])->name('colaboradores.importar.confirmar');
    Route::get('colaboradores/buscar', [ColaboradorController::class, 'buscar'])->name('colaboradores.buscar');
    Route::get('colaboradores/siguiente-numero', [ColaboradorController::class, 'siguienteNumeroEmpleado'])->name('colaboradores.siguiente-numero');
    Route::get('colaboradores/exportar', [ColaboradorController::class, 'exportar'])->name('colaboradores.exportar');
    Route::get('colaboradores/crear', [ColaboradorController::class, 'create'])->name('colaboradores.create');
    Route::post('colaboradores', [ColaboradorController::class, 'store'])->name('colaboradores.store');
    Route::get('colaboradores/{colaborador}/foto', [ColaboradorController::class, 'foto'])->name('colaboradores.foto');
    Route::post('colaboradores/{colaborador}/foto', [ColaboradorController::class, 'actualizarFoto'])->name('colaboradores.foto.actualizar');
    Route::get('colaboradores/{colaborador}/editar', [ColaboradorController::class, 'edit'])->name('colaboradores.edit');
    Route::put('colaboradores/{colaborador}', [ColaboradorController::class, 'update'])->name('colaboradores.update');
    Route::post('colaboradores/{colaborador}/estado', [ColaboradorController::class, 'toggle'])->name('colaboradores.toggle');

    // Expediente digital del colaborador
    Route::get('colaboradores/{colaborador}/expediente', [DocumentoExpedienteController::class, 'index'])->name('colaboradores.expediente.index');
    Route::post('colaboradores/{colaborador}/expediente', [DocumentoExpedienteController::class, 'store'])->name('colaboradores.expediente.store');
    Route::post('colaboradores/{colaborador}/expediente/{documento}/version', [DocumentoExpedienteController::class, 'nuevaVersion'])->name('colaboradores.expediente.version');
    Route::put('colaboradores/{colaborador}/expediente/{documento}', [DocumentoExpedienteController::class, 'update'])->name('colaboradores.expediente.update');
    Route::post('colaboradores/{colaborador}/expediente/{documento}/estado', [DocumentoExpedienteController::class, 'toggle'])->name('colaboradores.expediente.toggle');
    Route::get('colaboradores/{colaborador}/expediente/{documento}/descargar', [DocumentoExpedienteController::class, 'descargar'])->name('colaboradores.expediente.descargar');
    Route::get('colaboradores/{colaborador}/expediente/{documento}/ver', [DocumentoExpedienteController::class, 'ver'])->name('colaboradores.expediente.ver');
    Route::get('colaboradores/{colaborador}/expediente/{documento}/versiones', [DocumentoExpedienteController::class, 'versiones'])->name('colaboradores.expediente.versiones');
    Route::get('colaboradores/{colaborador}/expediente/{documento}/versiones/{version}/descargar', [DocumentoExpedienteController::class, 'descargarVersion'])->name('colaboradores.expediente.version-descargar');

    // Perfil del colaborador (una sola ruta de un segmento: va después de las
    // rutas literales de arriba para no capturarlas).
    Route::get('colaboradores/{colaborador}', [ColaboradorController::class, 'show'])->name('colaboradores.show');

    // Áreas / Departamentos
    Route::get('areas', [AreaController::class, 'index'])->name('areas.index');
    Route::get('areas/buscar', [AreaController::class, 'buscar'])->name('areas.buscar');
    Route::get('areas/siguiente-codigo', [AreaController::class, 'siguienteCodigo'])->name('areas.siguiente-codigo');
    Route::get('areas/exportar', [AreaController::class, 'exportar'])->name('areas.exportar');
    Route::post('areas', [AreaController::class, 'store'])->name('areas.store');
    Route::get('areas/{area}', [AreaController::class, 'show'])->name('areas.show');
    Route::put('areas/{area}', [AreaController::class, 'update'])->name('areas.update');
    Route::post('areas/{area}/estado', [AreaController::class, 'toggle'])->name('areas.toggle');

    // Activos y variantes / tallas
    Route::redirect('prendas', 'activos');
    Route::get('activos', [ActivoController::class, 'index'])->name('activos.index');
    Route::get('activos/buscar', [ActivoController::class, 'buscar'])->name('activos.buscar');
    Route::get('activos/siguiente-codigo', [ActivoController::class, 'siguienteCodigo'])->name('activos.siguiente-codigo');
    Route::get('activos/crear', [ActivoController::class, 'create'])->name('activos.create');
    Route::post('activos', [ActivoController::class, 'store'])->name('activos.store');

    // Unidades de seguimiento individual (dentro del hub de Activos). Deben
    // registrarse ANTES de `activos/{activo}` para que "unidades" no sea
    // capturado como un id de activo por el binding implícito.
    Route::get('activos/unidades', [UnidadActivoController::class, 'index'])->name('unidades-activo.index');
    Route::get('activos/unidades/buscar', [UnidadActivoController::class, 'buscar'])->name('unidades-activo.buscar');
    Route::get('activos/unidades/etiquetas', [UnidadActivoController::class, 'generarEtiquetas'])->name('unidades-activo.etiquetas');
    Route::get('activos/unidades/exportar', [UnidadActivoController::class, 'exportar'])->name('unidades-activo.exportar');
    Route::get('activos/unidades/{unidad:public_token}', [UnidadActivoController::class, 'show'])->name('unidades-activo.show');
    Route::get('activos/unidades/{unidad:public_token}/qr', [UnidadActivoController::class, 'qr'])->name('unidades-activo.qr');
    Route::post('activos/unidades/{unidad:public_token}/baja', [UnidadActivoController::class, 'darDeBaja'])->name('unidades-activo.baja');
    Route::post('activos/unidades/{unidad:public_token}/incidencia', [UnidadActivoController::class, 'marcarIncidencia'])->name('unidades-activo.incidencia');
    Route::post('activos/unidades/{unidad:public_token}/recuperar', [UnidadActivoController::class, 'recuperar'])->name('unidades-activo.recuperar');

    Route::get('activos/{activo}', [ActivoController::class, 'show'])->name('activos.show');
    Route::get('activos/{activo}/editar', [ActivoController::class, 'edit'])->name('activos.edit');
    Route::post('activos/{activo}', [ActivoController::class, 'update'])->name('activos.update'); // POST por subida de imagen
    Route::post('activos/{activo}/estado', [ActivoController::class, 'toggle'])->name('activos.toggle');
    Route::post('activos/{activo}/existencias', [ActivoController::class, 'agregarExistencias'])->name('activos.existencias');
    Route::post('activos/{activo}/suspendidos/reactivar', [ActivoController::class, 'reactivarSuspendidos'])->name('activos.suspendidos.reactivar');

    Route::get('tallas', [TallaController::class, 'index'])->name('tallas.index');
    Route::get('tallas/buscar', [TallaController::class, 'buscar'])->name('tallas.buscar');
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
    Route::get('inventario/movimientos/exportar', [MovimientoInventarioController::class, 'exportar'])->name('inventario.movimientos.exportar');

    // Conjuntos
    Route::get('conjuntos', [ConjuntoController::class, 'index'])->name('conjuntos.index');
    Route::get('conjuntos/buscar', [ConjuntoController::class, 'buscar'])->name('conjuntos.buscar');
    Route::get('conjuntos/siguiente-codigo', [ConjuntoController::class, 'siguienteCodigo'])->name('conjuntos.siguiente-codigo');
    Route::get('conjuntos/crear', [ConjuntoController::class, 'create'])->name('conjuntos.create');
    Route::get('conjuntos/exportar', [ConjuntoController::class, 'exportar'])->name('conjuntos.exportar');
    Route::post('conjuntos', [ConjuntoController::class, 'store'])->name('conjuntos.store');
    Route::get('conjuntos/{conjunto}', [ConjuntoController::class, 'show'])->name('conjuntos.show');
    Route::get('conjuntos/{conjunto}/editar', [ConjuntoController::class, 'edit'])->name('conjuntos.edit');
    Route::put('conjuntos/{conjunto}', [ConjuntoController::class, 'update'])->name('conjuntos.update');
    Route::post('conjuntos/{conjunto}/estado', [ConjuntoController::class, 'toggle'])->name('conjuntos.toggle');

    // Entregas
    Route::get('entregas', [EntregaController::class, 'index'])->name('entregas.index');
    Route::get('entregas/crear', [EntregaController::class, 'create'])->name('entregas.create');
    Route::get('entregas/buscar', [EntregaController::class, 'buscar'])->name('entregas.buscar');
    Route::get('entregas/disponibilidad', [EntregaController::class, 'disponibilidad'])->name('entregas.disponibilidad');
    // Consulta del documento de identidad del colaborador durante el flujo de
    // firma (autorización de mínimo privilegio, ver EntregaController).
    Route::get('entregas/documento-identidad/{colaborador}', [EntregaController::class, 'documentoIdentidad'])->name('entregas.documento-identidad');
    Route::get('entregas/documento-identidad/{colaborador}/ver', [EntregaController::class, 'verDocumentoIdentidad'])->name('entregas.documento-identidad.ver');
    Route::post('entregas', [EntregaController::class, 'store'])->name('entregas.store');
    Route::get('entregas/{entrega}', [EntregaController::class, 'show'])->name('entregas.show');
    Route::get('entregas/{entrega}/corregir', [CorreccionEntregaController::class, 'create'])->name('entregas.corregir.create');
    Route::post('entregas/{entrega}/corregir', [CorreccionEntregaController::class, 'store'])->name('entregas.corregir.store');

    // Acuses / firma / comprobantes
    Route::get('entregas/{entrega}/firmar', [AcuseController::class, 'firmar'])->name('acuses.firmar');
    Route::post('entregas/{entrega}/firmar', [AcuseController::class, 'confirmar'])->name('acuses.confirmar');
    Route::get('acuses/{acuse}/pdf', [AcuseController::class, 'pdf'])->name('acuses.pdf');
    Route::get('acuses/{acuse}/firma', [AcuseController::class, 'firma'])->name('acuses.firma');
    Route::get('acuses/{acuse}/firma-operador', [AcuseController::class, 'firmaOperador'])->name('acuses.firma-operador');
    Route::post('acuses/{acuse}/regenerar-pdf', [AcuseController::class, 'regenerarPdf'])->name('acuses.regenerar-pdf');

    // Devoluciones
    Route::get('devoluciones', [DevolucionController::class, 'index'])->name('devoluciones.index');
    Route::get('devoluciones/crear', [DevolucionController::class, 'create'])->name('devoluciones.create');
    Route::get('devoluciones/exportar', [DevolucionController::class, 'exportar'])->name('devoluciones.exportar');
    Route::post('devoluciones', [DevolucionController::class, 'store'])->name('devoluciones.store');
    Route::get('devoluciones/{devolucion}/firmar', [AcuseDevolucionController::class, 'firmar'])->name('devoluciones.firmar');
    Route::post('devoluciones/{devolucion}/firmar', [AcuseDevolucionController::class, 'confirmar'])->name('devoluciones.confirmar');
    Route::get('acuses-devolucion/{acuse}/pdf', [AcuseDevolucionController::class, 'pdf'])->name('acuses-devolucion.pdf');
    Route::get('acuses-devolucion/{acuse}/firma', [AcuseDevolucionController::class, 'firma'])->name('acuses-devolucion.firma');
    Route::get('acuses-devolucion/{acuse}/firma-operador', [AcuseDevolucionController::class, 'firmaOperador'])->name('acuses-devolucion.firma-operador');
    Route::post('acuses-devolucion/{acuse}/regenerar-pdf', [AcuseDevolucionController::class, 'regenerarPdf'])->name('acuses-devolucion.regenerar-pdf');

    // Reportes
    Route::get('reportes', [ReporteController::class, 'index'])->name('reportes.index');
    Route::get('reportes/entregas/exportar', [ReporteController::class, 'exportarEntregas'])->name('reportes.entregas.exportar');
    Route::get('reportes/inventario/exportar', [ReporteController::class, 'exportarInventario'])->name('reportes.inventario.exportar');

    // Administración
    Route::get('empresas', [EmpresaController::class, 'index'])->name('empresas.index');
    Route::get('empresas/buscar', [EmpresaController::class, 'buscar'])->name('empresas.buscar');
    Route::get('empresas/siguiente-codigo', [EmpresaController::class, 'siguienteCodigo'])->name('empresas.siguiente-codigo');
    Route::get('empresas/exportar', [EmpresaController::class, 'exportar'])->name('empresas.exportar');
    Route::post('empresas', [EmpresaController::class, 'store'])->name('empresas.store');
    Route::get('empresas/{empresa}', [EmpresaController::class, 'show'])->name('empresas.show');
    Route::put('empresas/{empresa}', [EmpresaController::class, 'update'])->name('empresas.update');
    Route::post('empresas/{empresa}/estado', [EmpresaController::class, 'toggleEstado'])->name('empresas.estado');
    Route::post('empresas/{empresa}/suspendidos/reactivar', [EmpresaController::class, 'reactivarSuspendidos'])->name('empresas.suspendidos.reactivar');

    // Configuración (personalización visual GLOBAL de la instancia, no por empresa)
    Route::get('configuracion', [ConfiguracionController::class, 'edit'])->name('configuracion.edit');
    Route::post('configuracion', [ConfiguracionController::class, 'update'])->name('configuracion.update');

    // Almacenes
    Route::get('almacenes', [AlmacenController::class, 'index'])->name('almacenes.index');
    Route::get('almacenes/buscar', [AlmacenController::class, 'buscar'])->name('almacenes.buscar');
    Route::get('almacenes/colaboradores-buscar', [AlmacenController::class, 'colaboradoresBuscar'])->name('almacenes.colaboradores-buscar');
    Route::get('almacenes/siguiente-codigo', [AlmacenController::class, 'siguienteCodigo'])->name('almacenes.siguiente-codigo');
    Route::get('almacenes/exportar', [AlmacenController::class, 'exportar'])->name('almacenes.exportar');
    Route::post('almacenes', [AlmacenController::class, 'store'])->name('almacenes.store');
    Route::get('almacenes/{almacen}', [AlmacenController::class, 'show'])->name('almacenes.show');
    Route::put('almacenes/{almacen}', [AlmacenController::class, 'update'])->name('almacenes.update');
    Route::post('almacenes/{almacen}/estado', [AlmacenController::class, 'toggle'])->name('almacenes.toggle');

    Route::get('sucursales', [SucursalController::class, 'index'])->name('sucursales.index');
    Route::get('sucursales/buscar', [SucursalController::class, 'buscar'])->name('sucursales.buscar');
    Route::get('sucursales/siguiente-codigo', [SucursalController::class, 'siguienteCodigo'])->name('sucursales.siguiente-codigo');
    Route::get('sucursales/exportar', [SucursalController::class, 'exportar'])->name('sucursales.exportar');
    Route::post('sucursales', [SucursalController::class, 'store'])->name('sucursales.store');
    Route::get('sucursales/{sucursal}', [SucursalController::class, 'show'])->name('sucursales.show');
    Route::put('sucursales/{sucursal}', [SucursalController::class, 'update'])->name('sucursales.update');
    Route::post('sucursales/{sucursal}/estado', [SucursalController::class, 'toggle'])->name('sucursales.toggle');
    Route::post('sucursales/{sucursal}/suspendidos/reactivar', [SucursalController::class, 'reactivarSuspendidos'])->name('sucursales.suspendidos.reactivar');

    Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
    Route::get('usuarios/exportar', [UsuarioController::class, 'exportar'])->name('usuarios.exportar');
    Route::get('usuarios/crear', [UsuarioController::class, 'create'])->name('usuarios.create');
    Route::post('usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
    Route::get('usuarios/{usuario}/editar', [UsuarioController::class, 'edit'])->name('usuarios.edit');
    Route::put('usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');
    Route::post('usuarios/{usuario}/estado', [UsuarioController::class, 'toggle'])->name('usuarios.toggle');

    Route::get('roles', [RolController::class, 'index'])->name('roles.index');
    Route::get('roles/exportar', [RolController::class, 'exportar'])->name('roles.exportar');
    Route::post('roles', [RolController::class, 'store'])->name('roles.store');
    Route::put('roles/{rol}', [RolController::class, 'update'])->name('roles.update');
    Route::delete('roles/{rol}', [RolController::class, 'destroy'])->name('roles.destroy');

    Route::get('auditoria', [BitacoraController::class, 'index'])->name('auditoria.index');
    Route::get('auditoria/exportar', [BitacoraController::class, 'exportar'])->name('auditoria.exportar');
});
