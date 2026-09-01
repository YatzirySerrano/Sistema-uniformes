# Entregas, firmas y acuses

## Registrar una entrega

Flujo guiado en la UI: **Colaborador → Prendas → Resumen → Registrar**.

`CrearEntregaUniforme::ejecutar()` (transacción):

1. Valida que sucursal y colaborador pertenezcan a la empresa activa y que el
   colaborador esté adscrito a esa sucursal y activo.
2. Consolida renglones repetidos y descarta cantidades ≤ 0.
3. Genera folio `ENT-{año}-{consecutivo}` (`ServicioFolios`).
4. Crea `entregas_uniformes` (estado `pendiente_firma`) y `detalles_entrega` con
   **snapshot** del nombre de la prenda y el valor de la talla.
5. Por cada renglón registra un movimiento `entrega` en `ServicioInventario`
   (descuenta stock; si falta, revierte todo).
6. Audita (`modulo=entregas, accion=crear`).

El frontend deshabilita el botón durante el envío; el backend consolida y
valida de nuevo.

## Firma de recepción

- `components/entregas/PadFirma.vue`: lienzo con _pointer events_ (mouse, touch,
  dedo, lápiz), botón **Limpiar**, se re-dimensiona conservando el trazo. Área
  amplia, apta para móvil/tablet. La firma también puede capturarse desde el
  dispositivo del encargado.
- `ValidadorFirma` (backend): quita el prefijo `data:`, `base64_decode` estricto,
  rechaza imágenes < 800 bytes (lienzo vacío), valida tamaño máximo, tipo real
  por `getimagesizefromstring` + magic bytes (PNG/JPEG) y dimensiones mínimas.

## `ConfirmarAcuseRecepcion`

1. Verifica que la entrega esté `pendiente_firma` y sin acuse previo (con
   `lockForUpdate` para evitar doble firma concurrente).
2. Valida la firma.
3. Construye `snapshot_entrega` (empresa, sucursal, colaborador, encargado,
   entrega, renglones, fecha/hora) — **contenido firmado, inmutable**.
4. Calcula `hash_documento = SHA-256(snapshot)` y `hash_firma = SHA-256(bytes)`.
5. Guarda la firma en **disco privado** `storage/app/private/firmas/{empresa}/{uuid}.png`.
6. Crea `acuses_recepcion` (folio `ACU-{año}-{consecutivo}`, IP y user agent),
   marca la entrega `firmada` y audita.
7. **Después del commit** materializa el PDF (`ServicioAcusePdf`) en
   `storage/app/private/acuses/{empresa}/{uuid}.pdf` y guarda `ruta_pdf`.

Si la generación del PDF falla, el acuse sigue siendo válido con `ruta_pdf` nulo;
`POST /acuses/{acuse}/regenerar-pdf` (o la primera descarga) lo materializa.

## Snapshot inmutable

Si más tarde cambian el nombre de la prenda, de la empresa, del colaborador, el
logo o una talla, el comprobante histórico **no cambia**: el PDF se regenera
siempre desde `snapshot_entrega`. Probado en `AcuseFirmaTest`.

## Hash

SHA-256 para detectar alteración accidental o intencionada de la evidencia.
**No** equivale a FIEL / e.firma / Firma Electrónica Avanzada; el PDF lo aclara.

## Comprobante PDF

`ACUSE DE RECEPCIÓN` con logo y datos de la empresa, sucursal, colaborador,
número de empleado, responsable, fecha de entrega y de firma, tabla de prendas,
imagen de la firma, folio y las huellas SHA-256. Todo en español. Nombre de
descarga amigable: `acuse-uniformes-ACU-2026-000123.pdf` (el almacenamiento usa
UUID).

## Acceso a firma y PDF

Sólo por Controller con `AcuseRecepcionPolicy`:

- `verPdf` → `acuses.ver-pdf` **o** colaborador titular.
- `verFirma` → `acuses.ver-firma` **o** colaborador titular.
- Siempre dentro de una empresa autorizada. Nunca URL pública directa.

Probado: intruso 403, supervisor autorizado 200, titular 200, otra empresa 403
(`AcuseFirmaTest`).

## Correcciones

Una entrega firmada no se edita. `CorregirEntrega`:

- Exige motivo.
- Conserva la entrega original y el acuse (inmutable).
- Calcula el delta por renglón y compensa el inventario con movimientos
  `correccion` / `ajuste_salida`.
- Registra `correcciones_entrega` (antes/después) y audita.
- La entrega pasa a estado `corregida`.

## Devoluciones

`RegistrarDevolucion`: valida empresa/sucursal/colaborador/entrega/prenda/talla.
Cada renglón lleva `condicion` (`reutilizable`, `danado`, `baja`). Sólo las
`reutilizable` generan un movimiento `devolucion` que reingresa al inventario;
`danado` y `baja` quedan registradas pero no vuelven al stock disponible.
