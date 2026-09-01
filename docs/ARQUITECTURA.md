# Arquitectura

## Flujo de una petición

```
Ruta (routes/sistema.php)
  → Controller delgado (App\Http\Controllers)
    → Form Request (validación de entrada + authorize)
      → Acción / Servicio (regla de negocio, transacción)
        → Modelo Eloquent
          → Base de datos
```

Los controladores no contienen lógica empresarial: orquestan (autorizan, resuelven
la empresa activa, delegan en una Acción y devuelven `Inertia::render` o `redirect`).

## Capas propias

| Carpeta            | Rol                                                                                               |
| ------------------ | ------------------------------------------------------------------------------------------------- |
| `app/Acciones/`    | Casos de uso escritos como clase con un método `ejecutar()`. Transaccionales.                     |
| `app/Servicios/`   | Lógica reutilizable entre acciones/controladores.                                                 |
| `app/Soporte/`     | Utilidades transversales sin estado de dominio (`ContextoEmpresa`, `Permisos`, `ValidadorFirma`). |
| `app/Excepciones/` | Errores de negocio; producen respuesta controlada en español.                                     |
| `app/Enums/`       | Enumeraciones de dominio con etiquetas en español.                                                |
| `app/Policies/`    | Autorización por recurso (IDOR + tenant).                                                         |
| `app/Exports/`     | Clases de exportación Excel (Maatwebsite).                                                        |

Se conservan los nombres/directorios de Laravel (`app/Http`, `app/Models`,
`app/Providers`, `database/`, `routes/`, etc.) y los sufijos `Controller`,
`Policy`, `Request`, `Factory`, `Seeder` porque habilitan el autodescubrimiento y
la resolución automática del framework.

## Acciones principales

- `CrearEntregaUniforme` — valida coherencia empresa/sucursal/colaborador/prenda, genera folio, crea cabecera + items con snapshot, descuenta inventario. Atómica.
- `ConfirmarAcuseRecepcion` — valida la firma, congela snapshot, guarda firma privada, calcula hashes, crea acuse, marca entrega firmada, materializa PDF post-commit.
- `RegistrarEntradaInventario` / `AjustarInventario` — entradas y ajustes con motivo obligatorio y auditoría.
- `RegistrarDevolucion` — devuelve prendas; sólo las reutilizables reingresan al inventario.
- `CorregirEntrega` — corrección administrativa de una entrega firmada sin tocar el acuse.

## Servicios clave

- `ServicioInventario` — **única puerta** de modificación del inventario. Transacción + `lockForUpdate`. Prohíbe stock negativo.
- `ServicioAuditoria` — **única puerta** de escritura de la bitácora.
- `ServicioFolios` — folios legibles y consecutivos por tipo/empresa/año, con bloqueo pesimista.
- `ServicioAcusePdf` — render de `resources/views/acuses/comprobante.blade.php` a PDF, almacenado en disco privado.
- `ServicioImportacionColaboradores` — análisis y confirmación de la importación de Excel.
- `ServicioDashboard`, `ServicioReportes` — consultas de panel y reportes acotadas a la empresa y sus sucursales.

## Frontend

SPA con Inertia. Layout por defecto `AppLayout` (sidebar). Props compartidos por
`HandleInertiaRequests`: usuario (roles + permisos), `contextoEmpresa` (empresa
activa, empresas y sucursales disponibles, tokens de branding) y `flash.toast`.

`usePermisos()` centraliza el acceso a esos props. `AppSidebar.vue` construye el
menú por dominio y oculta lo que el usuario no puede ver.
