---
paths:
    - 'app/Http/Controllers/Concerns/ExportaListado.php,app/Exports/ListadoExport.php,resources/views/reportes/listado-generico.blade.php,resources/js/components/sistema/BotonesExportar.vue'
---

# Sistema

## Excel/PDF por módulo (Fase 8): patrón compartido, no bespoke por módulo

Cada listado (Empresas, Sucursales, Áreas, Almacenes, Conjuntos, Movimientos, Devoluciones, Unidades, Colaboradores, Auditoría) exporta Excel/PDF reutilizando la MISMA consulta filtrada que su `index()` — nunca una aparte. Patrón: el controller extrae esa consulta a un método privado (`consulta<Modulo>()`, devuelve el Builder SIN paginar), lo usa tanto en `index()` (pagina + `through()`) como en `exportar()` (`->get()` + `->map()` a filas planas `array<int,string|int|null>`). `exportar()` usa el trait `ExportaListado::respuestaExportacion($formato, $filas, $encabezados, $titulo)`, que con `ListadoExport` (Excel) y `reportes/listado-generico.blade.php` (PDF landscape) cubre ambos formatos — NO crear una clase Export ni una vista blade nueva por módulo (a diferencia de `EntregasExport`/`InventarioExport`, que son previos a esta fase y con forma propia, sí se quedan como están). Ruta `GET <recurso>/exportar` (antes de wildcards `{id}`), gateada por el mismo permiso `viewAny`/`.ver` que `index()` — no se creó un permiso `exportar` nuevo. Frontend: `<BotonesExportar endpoint="/<recurso>/exportar" :filtros="filtros" />` en el slot `#acciones` de `EncabezadoPagina` — enlaces `<a>` normales (no visita Inertia), navegan con querystring `?<filtros>&formato=xlsx|pdf`.

Al testear: dompdf con fuentes no estándar (DejaVu Sans) subincrusta el texto y lo guarda como UTF-16BE dentro de content streams comprimidos (FlateDecode) — para buscar texto en un PDF de prueba hay que `zlib_decode` cada stream y quitar bytes `\x00` (ver `ExportacionesTest.php`). Generar PDFs reales de más de 2-3 módulos en la misma corrida de la suite completa agota el `memory_limit` de PHPUnit por acumulación — usar `Excel::fake()` + inspeccionar `$export->array()` (mismas filas que el PDF) para verificar contenido/aislamiento por módulo, y reservar la generación real de PDF para 1-2 casos que confirmen el pipeline (cabeceras, `%PDF-`).
