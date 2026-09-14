---
paths:
    - 'app/{Acciones/CambiarEmpresaColaborador.php,Servicios/ServicioCustodiaColaborador.php,Http/Requests/Colaboradores/CambiarEmpresaColaboradorRequest.php,Acciones/CrearEntregaUniforme.php}'
---

# Colaboradores

## Cambio de EMPRESA de colaborador ≠ cambio de servicio

Transferir un colaborador entre razones sociales es operación aparte: permiso `colaboradores.cambiar-empresa`, `Policy::cambiarEmpresa` (acceso a empresa ORIGEN) + Request `authorize()` exige acceso a empresa DESTINO (403, no error de validación). `CambiarEmpresaColaborador`: `lockForUpdate` del colaborador → re-chequea `ServicioCustodiaColaborador::tienePendientes` (unidades `estado=Asignada` incl. perdidas/robadas + renglones cantidad `entregado − SUM(devuelto en devoluciones confirmadas)`) → genera `numero_empleado` NUEVO para destino (la secuencia es per-empresa) → `servicio_actual_id = NULL` → auditoría `colaboradores/cambiar_empresa` con etiquetas humanas. Nunca devuelve nada automáticamente. `CrearEntregaUniforme` toma `lockForUpdate` del colaborador dentro de su tx y lee empresa/sucursal de la fila bloqueada — así el cambio de empresa y una entrega nueva se serializan. `CambiarServicioColaborador` (misma empresa) queda intacto.

## Histórico laboral: `transferencias_colaborador` (IDs) + `bitacora_auditoria` (respaldo legado)

Cada llamada a `CambiarEmpresaColaborador::ejecutar()` TAMBIÉN inserta una fila en `transferencias_colaborador` (migración `create_transferencias_colaborador_table`, append-only, sin `updated_at`) con los IDs reales de origen/destino (empresa, sucursal, área, servicio) + `numero_empleado_anterior/nuevo` + `usuario_id` + `ocurrido_en` — ver `App\Models\TransferenciaColaborador`. Es la fuente PRIMARIA de `App\Servicios\ServicioHistoricoColaborador::construir()`, que arma los periodos del histórico laboral (`GET colaboradores/{c}/historico`, sólo alcance global — `ColaboradorPolicy::verHistorico`). Para transferencias ANTERIORES a que existiera esta tabla, el servicio cae a `bitacora_auditoria` (acción `cambiar_empresa`, sólo nombres) como respaldo — nunca duplica: toma los eventos de bitácora más antiguos, restando tantos como filas estructuradas existan (comparar por timestamp entre `now()` de la bitácora y de la fila estructurada de la MISMA transferencia es frágil, difieren en microsegundos). El primer periodo (antes de la primera transición conocida) usa `colaborador.created_at` etiquetado explícitamente como "fecha de alta del registro" — nunca se inventa una fecha de contratación real. Los `fecha_inicio`/`fecha_fin` de cada periodo viajan como `CarbonInterface` internamente (nunca strings ISO) hasta el borde final del payload, porque comparar fechas ISO como texto en las queries de `servicios/entregas/devolucionesDelPeriodo` ordena mal.
