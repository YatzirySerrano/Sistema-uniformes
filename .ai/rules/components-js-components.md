---
paths:
  - 'resources/js/pages/auth/Login.vue,resources/js/pages/auth/ConfirmPassword.vue,resources/js/pages/settings/Security.vue,resources/js/components/PasskeyVerify.vue,resources/js/components/ManagePasskeys.vue'
---

# Components Js Components

## Passkeys ocultos en TODO el frontend visible (Login, Confirmar contraseña, Seguridad) — backend intacto
Passkeys está deliberadamente oculto en las 3 pantallas donde antes era visible: `Login.vue` (ya estaba, ver nota previa), `ConfirmPassword.vue` (`<PasskeyVerify>` comentado, junto con sus imports de rutas) y `settings/Security.vue` (import/uso de `ManagePasskeys` quitado, `ManagePasskeysProps` fuera del type `Props`). Decisión de producto (2026-09), no un bug. Backend/Fortify/WebAuthn/rutas/`SecurityController` (sigue mandando `canManagePasskeys`/`passkeys`) intactos a propósito, para poder reactivar sin tocar backend. Componentes `ManagePasskeys.vue`/`PasskeyRegister.vue`/`PasskeyItem.vue`/`PasskeyVerify.vue` se quedan sin borrar (huérfanos pero listos). Para reactivar cualquiera de las 3 pantallas: descomentar/reimportar tal como indica el comentario en cada archivo. NO tocar 2FA (`ManageTwoFactor`), que es independiente y sigue visible.
