---
paths:
    - resources/js/components/sistema/SubidaArchivo.vue
---

# Components Sistema

## Subida de archivos: componente reusable, no <input type=file> suelto

`components/sistema/SubidaArchivo.vue` reemplaza los `<input type="file">` sueltos (logo de Empresa, imagen de Activo, Excel de importación de Colaboradores). Da botón + arrastrar/soltar, vista previa (imagen o nombre), "Cambiar"/"Quitar", texto de formatos/peso máximo. `v-model` es un `File | null` normal — sigue funcionando con `useForm` de Inertia tal cual (serializa `multipart/form-data` automáticamente si hay un `File` en el payload), no cambia el flujo de envío. Props clave: `tipo="imagen"|"documento"`, `archivo-actual-url` (preview de lo ya guardado), `formatos-etiqueta`, `peso-maximo-mb`. Usar este componente para cualquier subida nueva, nunca un input nativo.
