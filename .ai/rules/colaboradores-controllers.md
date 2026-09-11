---
paths:
    - 'app/{Servicios/ServicioExpediente.php,Acciones/SubirVersionDocumentoExpediente.php,Http/Requests/Colaboradores/ActualizarDocumentoExpedienteRequest.php,Http/Controllers/DocumentoExpedienteController.php}'
---

# Colaboradores Controllers

## Expediente: aislamiento multiempresa POR VERSIÓN + categoría inmutable

Tras un traslado de colaborador entre empresas:

- **LECTURA** (`index`/`versiones`/`descargar`/`ver`/`descargarVersion`): autorizan vía `ServicioExpediente::puedeAbrirExpediente($user,$colaborador,'ver'|'descargar')` (permiso `expediente-ver`/`-descargar` + acceso a la empresa ACTUAL **o** `tieneAccesoHistorico` = acceso a la empresa de origen de ≥1 versión de categoría EMPRESARIAL). NO usan `ColaboradorPolicy::verExpediente/descargarExpediente` (siguen existiendo para la pestaña embebida del perfil). `GET /colaboradores/{id}` (perfil) sigue 403 para un usuario sólo-origen — es intencional.
- **ESCRITURA** (`store`/`nuevaVersion`/`update`/`toggle`): SIN CAMBIOS, exigen `administrarExpediente` (empresa ACTUAL).
- **Visibilidad por versión** (`ServicioExpediente::usuarioPuedeVerVersion/versionesVisibles/versionVigenteVisible/slotVisible`, única fuente): personales (`CategoriaDocumentoExpediente::viajaConLaPersona()` → Identificación/Fiscal/SeguridadSocial) sólo para el CUSTODIO ACTUAL (o alcance global) — un usuario de acceso sólo-histórico NO las ve. Empresariales por `documento_expediente_version_empresa` (1:1 con `documento_expediente_versiones`, escrito en `SubirDocumento/SubirVersion` con la empresa vigente): la ve quien acceda a la empresa de origen de esa versión. La "versión vigente" que ve un usuario es la más alta ENTRE LAS VISIBLES, nunca la global. Fail-closed si falta empresa de origen. `payload.puedeDescargar` = `puedeAbrirExpediente(...,'descargar')`.
- La CATEGORÍA del slot es INMUTABLE en cuanto tiene ≥1 versión (`ActualizarDocumentoExpedienteRequest::withValidator` con `exists()`) — define la frontera histórica de visibilidad.
- Entregas/Devoluciones/Acuses ya aíslan solos por su `empresa_id` congelado.
