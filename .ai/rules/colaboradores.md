---
paths:
    - 'app/{Acciones/CambiarEmpresaColaborador.php,Servicios/ServicioCustodiaColaborador.php,Http/Requests/Colaboradores/CambiarEmpresaColaboradorRequest.php,Acciones/CrearEntregaUniforme.php}'
---

# Colaboradores

## Cambio de EMPRESA de colaborador ≠ cambio de servicio

Transferir un colaborador entre razones sociales es operación aparte: permiso `colaboradores.cambiar-empresa`, `Policy::cambiarEmpresa` (acceso a empresa ORIGEN) + Request `authorize()` exige acceso a empresa DESTINO (403, no error de validación). `CambiarEmpresaColaborador`: `lockForUpdate` del colaborador → re-chequea `ServicioCustodiaColaborador::tienePendientes` (unidades `estado=Asignada` incl. perdidas/robadas + renglones cantidad `entregado − SUM(devuelto en devoluciones confirmadas)`) → genera `numero_empleado` NUEVO para destino (la secuencia es per-empresa) → `servicio_actual_id = NULL` → auditoría `colaboradores/cambiar_empresa` con etiquetas humanas. Nunca devuelve nada automáticamente. `CrearEntregaUniforme` toma `lockForUpdate` del colaborador dentro de su tx y lee empresa/sucursal de la fila bloqueada — así el cambio de empresa y una entrega nueva se serializan. `CambiarServicioColaborador` (misma empresa) queda intacto.
