---
paths:
  - 'tests/**'
---

# Tests

## No usar test() dentro de funciones a nivel de módulo en archivos Pest
Un helper declarado como `function foo() { return test()->algo->metodo(...); }` a nivel de archivo Pest se comporta de forma no fiable cuando el archivo tiene varios `it()` (el segundo/tercer test que lo llama no registra efectos como `Mail::queue`, aunque el código de negocio sí corre). Verificado en la ronda de QA de correos. En su lugar: define el helper como closure enlazado en `beforeEach` (`$this->hacer = fn () => $this->confirmar->ejecutar(...)`) y llámalo con `($this->hacer)()`, o inlínea la llamada. Contexto de pruebas: SQLite `:memory:` reinicia el autoincremento por test, así que un candado de idempotencia en caché por id necesita `Cache::forget('clave:1')` en `beforeEach` (nunca `Cache::flush()`, que tira la caché de permisos de Spatie).
