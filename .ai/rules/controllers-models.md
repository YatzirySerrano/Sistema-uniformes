---
paths:
    - 'app/Http/Controllers/EntregaController.php,app/Models/DocumentoExpediente.php'
---

# Controllers Models

## Documento de identidad del colaborador durante una entrega (mínimo privilegio)

Durante el flujo de firma, el encargado puede consultar el INE del colaborador vía `GET entregas/documento-identidad/{colaborador}` (metadata JSON) y `.../ver` (stream inline privado). Es SÓLO para verificación VISUAL humana: nada de OCR / biometría / comparación automática, y el sistema nunca afirma "identidad verificada". El documento = el `DocumentoExpediente` ACTIVO más reciente en `CategoriaDocumentoExpediente::Identificacion` (nada hardcodeado por id) y se sirve su `versionActual` (versión de número más alto). Autorización de mínimo privilegio en `EntregaController::autorizarConsultaIdentidad()`: `can('create', EntregaUniforme::class)` + `puedeAccederEmpresa($colaborador->empresa_id)` — NO exige `colaboradores.expediente-descargar` (un Encargado, que no lo tiene, sí puede ver el INE por aquí pero no navegar el expediente). Sin identificación → `{disponible:false}` + `ver` 404 (nunca 500). El INE NO se adjunta al correo, NO va al acuse/PDF/snapshot, y consultarlo no modifica el expediente. `ServicioExpediente::esPrevisualizable()` filtra los mimes (jpg/png/webp/pdf).
