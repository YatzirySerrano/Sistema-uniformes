---
paths:
  - 'app/Servicios/ServicioImportacion*.php,app/Http/Controllers/Importacion*Controller.php'
---

# Http Controllers

## Importadores Excel: dry-run/confirmar comparten una única transacción con rollback forzado
`ServicioImportacionColaboradores` sigue el mismo patrón que `ServicioImportacionMaestra`: un único método privado `procesar()` ejecuta SIEMPRE la resolución/creación real (incluida `GeneradorNumeroEmpleado::generar()`, que reserva un consecutivo con `lockForUpdate`) dentro de `DB::transaction()`; si es un análisis (`$persistir=false`) o quedó algún error/duplicado, se lanza una excepción marcador interna (`SimulacionImportacion*`) para forzar el rollback. Como `ServicioGeneradorCodigos::incrementar()` abre su propio `DB::transaction()` anidado (Laravel lo convierte en SAVEPOINT), el rollback exterior también deshace la reserva del consecutivo — así el análisis nunca consume secuencia y la confirmación es todo-o-nada. Nunca dupliques la lógica de resolución entre "vista previa" y "confirmación real": un solo código path, protegido por el rollback, evita que ambas fases diverjan.
