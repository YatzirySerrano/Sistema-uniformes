---
paths:
    - 'app/{Enums/{FinalidadCustodia,TipoReserva}.php,Servicios/ServicioCustodiaColaborador.php,Acciones/{RedistribuirCustodia,CrearEntregaUniforme}.php,Http/Requests/Entregas/GuardarEntregaRequest.php,Http/Controllers/{EntregaController,MisActivosController}.php}'
---

# Requests Entregas Controllers

## Finalidad de custodia por renglón y bolsas (personal / redistribuir)

`detalles_entrega.finalidad` (`FinalidadCustodia`: uso_personal | redistribucion; NULL = sin clasificar/histórico) — una sola custodia, sin saldos paralelos. Bolsas (`ServicioCustodiaColaborador::BOLSA_*`): "redistribucion" = finalidad redistribucion; "personal" = uso_personal + NULL (nunca se infiere). Selectores/validación "desde mi custodia" sólo ofrecen la bolsa redistribución salvo `entregas.redistribuir-propios` (Policy `redistribuirPropios`) o contexto de revisión de cambio de servicio. Renglones sueltos indican `bolsa` de origen y `finalidad` para el destinatario (no se hereda). Unidades: bolsa = finalidad de su renglón MÁS RECIENTE (`soloBolsa`/`bolsaDeUnidad`). Conjuntos desde custodia: sólo bolsa redistribución. Clasificar/reclasificar = `EntregaUniformePolicy::clasificarFinalidad` (`entregas.crear` + empresa), auditado. "Mis activos" (`/mis-activos`, `activos.ver-custodia-propia`) es vista ligera del colaborador vinculado; nunca abre `activos.show`/unidades (siguen con sus Policies).
