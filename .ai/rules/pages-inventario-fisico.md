---
paths:
    - 'app/Acciones/CrearRondaInventarioFisico.php,app/Servicios/ServicioResumenInventarioFisico.php,app/Http/Controllers/InventarioFisicoController.php,resources/js/pages/InventarioFisico/**'
---

# Pages Inventario Fisico

## Inventario físico = UNA ronda INTEGRAL por empresa, multiusuario (diseño final 2026-09-30)

Reemplaza "ronda de almacén vs. general" y lo dicho sobre alcance/universo en `inventario-fisico.md`, `models-models.md` y `models-soporte.md`. Al crear sólo se captura empresa + nombre + observaciones (sin alcance ni almacén; `inventarios_fisicos.almacen_id` = NULL en rondas nuevas, las históricas lo conservan).

- **Cantidades**: `CrearRondaInventarioFisico::universoExistencias()` = `saldos_inventario` con cantidad>0 de la empresa en TODOS los almacenes de `almacen_empresa`; UN renglón por almacén+activo+variante (`inventario_fisico_existencias.almacen_id`, único `inv_fisico_existencia_almacen_unico`; migración aditiva `2026_09_30_204526`, backfill desde la ronda). Nunca sumar almacenes.
- **Unidades**: `universo()` = estado En almacén o Asignada + condición Funcionando/EnReparación/Inservible. Nunca Perdido/Robado/Baja. Snapshot congelado; Presente/Deshacer nunca tocan `UnidadActivo`. Clasificación derivada: sin verificar = `pendiente` con ronda abierta, `faltante` sólo cerrada.
- **Concurrencia** (orden de locks Ronda→fila; `VerificacionInventarioFisicoConcurrenteException` → 409 con la fila REAL + quién/cuándo): unidad → gana la primera verificación, repetir la propia es idempotente. Cantidad → el cliente manda `verificada_en_vista` (ISO; null = la veía pendiente); si no coincide con la vigente → 409; corrección consciente de un conteo ya registrado → bitácora `existencia_corregir_conteo`. Deshacer → `escaneado_en_vista`; sin él sólo se deshace la marca propia; bitácora `unidad_deshacer_presente` (quién deshizo + quién verificó). Ronda finalizada → 422 en todo.
- **Colaboración**: `usuario_id` = quien la INICIÓ ("Iniciada por", nunca "Responsable"); cualquiera con `administrar` (permiso efectivo + acceso a la empresa) verifica. Sin roles hardcodeados.
- **Correcciones**: cada diferencia se aplica al `almacen_id` DEL RENGLÓN (fallback al de la ronda sólo para históricos). Policy `aplicarCorrecciones` = `inventario.ajustar` + empresa + TODOS los almacenes de los renglones dentro de `AccesoEmpresa::almacenesAutorizados()` (activos y que abastecen a la empresa); uno fuera → 403 para todo el lote.
- Detalle: filtros verificación (`seccion`), estado operativo (`estado_unidad`, vía `UnidadActivo::scopeConEstadoVisible`) y almacén (`almacen_id`, sólo de `almacenesDeLaRonda()`); relaciones en `RELACIONES_FILA` (sin N+1). Export Excel por cantidad incluye Almacén + Verificado por; el acta PDF dice "Toda la empresa" y el almacén de cada renglón.
