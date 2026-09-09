---
paths:
    - 'bootstrap/app.php,app/Providers/AppServiceProvider.php'
---

# Bootstrap Providers

## No usar trustProxies(at:'*') ni URL::forceRootUrl global — rompen el login local

Regresión verificada: `$middleware->trustProxies(at: '*')` en `bootstrap/app.php` hace que Laravel confíe en un `X-Forwarded-Proto: https` (que muchos proxies locales — Herd, Orbstack, VPN, extensiones — inyectan aunque la conexión sea http). Consecuencia: `$request->isSecure()` = true → Symfony marca la cookie de sesión y `XSRF-TOKEN` como `Secure` → el navegador sobre `http://127.0.0.1:8000` las descarta → la sesión no persiste tras `POST /login` → "el login no funciona con credenciales válidas". Además el redirect post-login sale como `https://127.0.0.1:8000/...` (conexión rechazada). `URL::forceRootUrl(config('app.url'))` global agrava: cualquier mismatch de host (localhost vs 127.0.0.1, `.test`, puerto) manda el redirect a otro origen sin cookie. NINGUNO de los dos es necesario para las URL firmadas de verificación: la notificación se envía SÍNCRONA dentro de la petición web del admin, así que `URL::temporarySignedRoute` ya usa el host/esquema reales. Para producción tras Nginx/TLS: configurar trusted proxies en el deploy (Laravel Cloud lo hace solo; Nginx self-managed → `$middleware->trustProxies(at: '<ip-del-proxy>')` con la IP real, nunca `'*'` salvo que la app sólo se alcance por el proxy). `env()` no se puede usar en `bootstrap/app.php` (Larastan lo prohíbe y `config()` aún no está disponible ahí).
