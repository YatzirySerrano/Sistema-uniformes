---
paths:
    - resources/js/components/sistema/SubidaArchivo.vue
    - resources/js/components/sistema/PadFirma.vue
---

# Components Sistema

## Subida de archivos: componente reusable, no <input type=file> suelto

`components/sistema/SubidaArchivo.vue` reemplaza los `<input type="file">` sueltos (logo de Empresa, imagen de Activo, Excel de importación de Colaboradores). Da botón + arrastrar/soltar, vista previa (imagen o nombre), "Cambiar"/"Quitar", texto de formatos/peso máximo. `v-model` es un `File | null` normal — sigue funcionando con `useForm` de Inertia tal cual (serializa `multipart/form-data` automáticamente si hay un `File` en el payload), no cambia el flujo de envío. Props clave: `tipo="imagen"|"documento"`, `archivo-actual-url` (preview de lo ya guardado), `formatos-etiqueta`, `peso-maximo-mb`. Usar este componente para cualquier subida nueva, nunca un input nativo.

## PadFirma: robusto a montarse oculto (paso con v-show)

`PadFirma` dimensiona su canvas leyendo `contenedor.clientWidth`. Si se monta dentro de un contenedor `display:none` (p. ej. un paso del asistente de Entregas con `v-show`), ese ancho es 0 y el canvas quedaba 0×0 y sin poder dibujar al reaparecer (regresión del flujo único de entrega). Corrección aplicada en `ajustarTamano()`: si `ancho === 0` NO reconfigura el canvas (no lo colapsa); un `ResizeObserver` sobre `contenedor` recalibra automáticamente al pasar de 0 a ancho real. Además expone `recalibrar()` (llamado desde `Crear.vue` en `watch(paso)` + `nextTick` al entrar al paso 3) y `iniciar()` autocalibra si `ctx` es null antes del primer trazo. La firma se preserva entre ocultamientos mediante un `respaldo` (data URL) que sólo se limpia en `limpiar()`. No reescribir el componente para nuevos contextos ocultos: ya lo soporta.
