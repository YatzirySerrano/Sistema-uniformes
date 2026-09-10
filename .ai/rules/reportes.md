---
paths:
    - 'app/Acciones/RegistrarUnidadesActivo.php,app/Soporte/NormalizadorNombre.php,resources/views/reportes/etiquetas_unidades.blade.php'
---

# Reportes

## Código visible de UnidadActivo: NOMBRE-ACTIVO-000001, secuencia global por slug

Las unidades identificadas NUEVAS reciben `codigo = SLUG(activo.nombre)-000001` vía `ServicioGeneradorCodigosGlobal::siguiente("unidad:{$slug}", $slug, 6)` en `RegistrarUnidadesActivo` — NO `{Empresa::codigo}-000001`. El slug lo da `NormalizadorNombre::codigoActivo()` (`Str::upper(Str::slug($nombre,'-'))`, recortado a 30 chars para caber en `varchar(40)` con `-000001`, fallback `UNIDAD`). La secuencia es GLOBAL por slug (fila `unidad:tablet` en `secuencias_codigo_globales`): DASTI crea `TABLET-000001`, INMAG crea `TABLET-000002` — nunca colisionan. `public_token` y el QR (`ServicioEtiquetasQr`) NO dependen del código, no cambian. El ámbito viejo `unidad_activo` en `secuencias_codigo` queda intacto; los códigos históricos (`DASTI01-000011`) NO se renombran (sin migración). Un traspaso interempresa conserva `codigo`/`public_token`. La etiqueta QR muestra código + nombre de activo prominentes; la empresa es un dato secundario pequeño (mutable). El código se genera SÓLO al nacer la unidad.
