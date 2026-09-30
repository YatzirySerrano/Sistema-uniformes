---
paths:
  - 'resources/js/pages/Entregas/Crear.vue,app/Acciones/ConfirmarAcuseRecepcion.php,resources/views/acuses/comprobante.blade.php'
---

# Views Acuses

## Finalidad explícita por renglón, también en el acuse
En Nueva entrega la finalidad de cada renglón (activo, unidad o conjunto) arranca en `null` y se exige elegirla antes de firmar: una preselección "Uso personal" hacía que lo pensado para redistribuir se guardara como personal. El backend la mantiene `nullable` (null = "Sin clasificar", tratada con cautela: no se redistribuye). El snapshot del acuse guarda `finalidad` y `conjunto` por item. La plantilla muestra la columna Finalidad SÓLO si el snapshot trae esa clave, para que los acuses históricos se impriman igual que siempre.
