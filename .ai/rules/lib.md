---
paths:
    - 'app/Soporte/FechaHora.php,app/Soporte/ContextoExportacion.php,resources/views/acuses/*.blade.php,resources/views/reportes/*.blade.php,resources/js/lib/fecha.ts'
---

# Lib

## Fecha/hora de presentación: FechaHora::local en PDFs, fechaHora() en la web

`config('app.timezone')` es UTC. La zona de PRESENTACIÓN es `config('uniformes.zona_horaria')` (America/Mexico_City). Server-side: usa SIEMPRE `App\Soporte\FechaHora::local(?DateTimeInterface, $formato='d/m/Y H:i')` para imprimir un timestamp en un PDF/export — nunca `$carbon->format(...)` a secas ni `addHours()`. Los blades de acuse (`comprobante`, `comprobante-devolucion`) y de reporte lo usan; `ContextoExportacion::generadoEnLocal()` para la línea "Generado:". Web: `resources/js/lib/fecha.ts::fechaHora(iso)` usa `Intl.DateTimeFormat` con `timeZone` = prop Inertia compartida `zonaHoraria` (en `HandleInertiaRequests::share`), así web y PDF muestran lo mismo sin importar el navegador. NO tocar `construirSnapshot` en `ConfirmarAcuse*` (alimenta `hash_documento`): sólo el `->format()` del blade cambia, y eso es hash-safe. Fechas de NEGOCIO (`fecha_entrega`, `devolucion.fecha`) son cadenas `d/m/Y` del snapshot, día sin hora — no se convierten.
