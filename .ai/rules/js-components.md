---
paths:
    - 'resources/js/pages/auth/Login.vue,resources/js/components/PasskeyVerify.vue'
---

# Js Components

## Passkey oculto en Login a propósito (backend/Fortify/WebAuthn intactos)

`<PasskeyVerify />` está deliberadamente comentado/quitado de `Login.vue` (no se ofrece la opción "Sign in with a passkey" todavía) — decisión de producto, no un bug ni una regresión. NO reactivar quitando el comentario sin que el usuario lo pida explícitamente. Backend, rutas, Fortify/WebAuthn y las tablas de passkeys siguen intactos; `ManagePasskeys.vue`/`PasskeyRegister.vue` en `settings/Security.vue` (gestión de passkeys del propio usuario) NO se tocaron — sólo Login. Para reactivar: descomentar el import y volver a poner `<PasskeyVerify />` justo después del bloque de `status` en `Login.vue`.
