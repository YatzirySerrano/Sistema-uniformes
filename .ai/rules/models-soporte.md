---
paths:
    - 'app/Http/Controllers/InventarioFisicoController.php,app/Acciones/{CrearRondaInventarioFisico,EscanearUnidadInventarioFisico,FinalizarRondaInventarioFisico}.php,app/Servicios/ServicioResumenInventarioFisico.php,app/Models/InventarioFisico*.php,app/Soporte/ResolvedorUnidadEscaneada.php'
---

# Models Soporte

## Inventario físico: rondas de escaneo QR (módulo de verificación, snapshot congelado)

Módulo NUEVO (`inventarios_fisicos` + `inventario_fisico_unidades`, 2 migraciones create_). SÓLO verifica: nunca mueve stock ni cambia estado/condición/almacén/asignación de `UnidadActivo`.

- Snapshot: al iniciar la ronda (`CrearRondaInventarioFisico::ejecutar`, transacción + insert por chunks de 1000) se congelan las filas `esperada=true` de las unidades del alcance. `CrearRondaInventarioFisico::universo(empresaId, ?almacenId)` es la ÚNICA definición del universo (la reusa el endpoint `universo` de previsualización): unidad de la empresa, `estado != baja`, y si hay `almacen_id` de alcance, ese `almacen_id`. Perdidas/robadas/en reparación/asignadas SÍ entran (baja NO).
- `esperada` (bool) NO es redundante: distingue snapshot vs escaneo-sorpresa. Clasificación DERIVADA (`InventarioFisicoUnidad::clasificacion()`), nunca persistida: esperada+escaneado_en=encontrado, esperada+null=faltante, !esperada=no_esperado.
- Escaneo: endpoint JSON `POST inventarios-fisicos/{inventarioFisico}/escanear` {codigo}. `ResolvedorUnidadEscaneada` (parser PURO) acepta public_token, URL `/activos/unidades/{token}` del propio host, o `codigo` de unidad. La empresa la fija la RONDA (nunca el cliente); unidad de otra empresa → 422 opaco. Doble escaneo protegido por `UNIQUE(inventario_fisico_id, unidad_activo_id)` + `lockForUpdate` puntual + catch de QueryException (nunca lock de la ronda entera).
- Contadores: 1 sola consulta agregada con `DB::table(...)->selectRaw('sum(case when ...)')` (NO Eloquent — evita props inventadas en PHPStan). En `ServicioResumenInventarioFisico` (contadores + `consultaSeccion` paginada con eager loading + `filaResumen`).
- Finalizar (`FinalizarRondaInventarioFisico`, lockForUpdate) es irreversible → escaneos posteriores 422.
- Folio `INVF-AAAA-000001` vía `ServicioFolios::INVENTARIO_FISICO` (no generador nuevo). Permisos `inventario-fisico.ver` / `.administrar` (Supervisor ambos, Encargado sólo ver). `InventarioFisicoPolicy` revalida `puedeAccederEmpresa`. Auditoría sólo en crear/finalizar (nunca por escaneo). Export vía `ExportaListado` genérico. Frontend: escáner cámara con `jsqr` (no BarcodeDetector → funciona en Safari iOS), composable `useEscanerQr` + entrada manual de respaldo.
