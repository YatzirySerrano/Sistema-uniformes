---
paths:
    - 'app/Http/Controllers/{Almacen,TipoActivo,Sucursal,Area,Activo}Controller.php,app/Soporte/ServicioGeneradorCodigos*.php'
---

# Soporte

## Todo código autogenerado usa ServicioGeneradorCodigos(Global), nunca count()+1

Ningún generador de `codigo` puede usar `count()+1`/`max()+1` + un bucle `while(...exists())` como única defensa — dos altas concurrentes calculan el mismo siguiente número y la segunda revienta con una violación de unicidad (mismo bug ya visto en folios). Catálogo por empresa (Sucursal/Área/Activo, prefijo literal SUC/ARE/ACT) → `App\Soporte\ServicioGeneradorCodigos::siguienteConPrefijo(Empresa, ambito, prefijo)` (tabla `secuencias_codigo`, comparte contador con `siguiente()` de `[[unidad_activo]]` pero nunca el mismo `ambito`). Catálogo global de plataforma sin empresa (Almacén ALM, Tipo de activo TAC) → `App\Soporte\ServicioGeneradorCodigosGlobal::siguiente(ambito, prefijo)` (tabla `secuencias_codigo_globales`, una fila por ámbito — nunca reutilices `secuencias_codigo` con `empresa_id` nulo: MySQL no impone unicidad entre NULLs, así que dos altas concurrentes sin fila previa crearían dos contadores). Al introducir un contador nuevo sobre datos ya existentes, la migración debe sembrar `ultimo_valor` a partir del MÁXIMO consecutivo REAL ya usado (regex `^PREFIJO-(\d+)$` sobre la columna `codigo`, ignorando códigos capturados a mano con otro formato) — nunca arrancar en 0 si ya hay filas, y nunca vía `count()` (ignora huecos/bajas).
