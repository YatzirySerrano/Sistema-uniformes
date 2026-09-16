---
paths:
  - 'app/Http/Controllers/ReporteController.php,app/Servicios/ServicioReportes.php'
---

# Http Controllers Servicios

## Collection<array-shape> reenviada entre métodos: usar array plano, no Collection
PHPStan/Larastan (nivel 7) trata el genérico de `Illuminate\Support\Collection` como INVARIANTE. Si un método devuelve `Collection<int, array{...}>` y ese valor viaja como parámetro a OTRO método con la MISMA forma declarada (aunque el docblock sea textualmente idéntico, incluso usando un alias `@phpstan-type` compartido), PHPStan puede reportar un falso positivo de tipo en cuanto uno de los campos internos es nullable (`?string`). No es un problema de nombres de tipos: reproducido y confirmado quitando la nulabilidad (pasa) y reintroduciéndola (falla), con alias canónico en ambos lados.

Solución real (no suprimir con `@phpstan-ignore`): cuando una sub-colección calculada en un Servicio va a pasar por la firma de 2+ métodos, conviértela a ARRAY PLANO en el punto de origen (`->values()->all()`) en vez de dejarla como objeto `Collection`. Los arrays con forma (`array<int, Shape>`) son estructuralmente covariantes y no sufren este problema. Ver `ServicioReportes::metricasEntregas()` (campos `top_activos`/`por_sucursal`) y su consumo en `ReporteController::graficasEntregas()` (usa `array_map`/`array_column` en vez de `->map()`/`->pluck()`).
