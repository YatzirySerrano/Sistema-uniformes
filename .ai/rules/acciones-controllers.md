---
paths:
    - 'app/{Acciones/{IniciarCambioServicio,GuardarDecisionesCambioServicio,CompletarCambioServicio,CancelarCambioServicio,CambiarServicioColaborador}.php,Servicios/ServicioCambioServicio.php,Models/CambioServicio*.php,Http/Controllers/CambioServicioController.php}'
---

# Acciones Controllers

## Cambio de servicio con custodia = revisión mantener/devolver/redistribuir

Con custodia, cualquier cambio real de `servicio_actual_id` (A→B, A→null, null→A) abre `CambioServicioColaborador` (plan por renglón de entrega o unidad). Sólo guarda DECISIONES; "resuelto" se deriva siempre en `ServicioCambioServicio::evaluar()` de devoluciones CONFIRMADAS y redistribuciones hechas después de los `corte_*` (ids máximos al iniciar) — nunca se persiste. `CompletarCambioServicio` evalúa con el colaborador bloqueado y rechaza snapshots viejos (`no_coincide`, custodia nueva). Devolver/redistribuir usan los flujos reales firmados (Devoluciones; Entregas con `cambio_servicio_id`, autorizado por `CambioServicioColaboradorPolicy::redistribuirCustodia` = `entregas.redistribuir` + alcance). `CambiarServicioColaborador::ejecutar()` directo sigue rechazando si hay custodia; `aplicar()` es para el colaborador ya bloqueado. Cambio de EMPRESA sigue estricto (custodia en cero).
