# Verificaciones del prototipo Rexel MV3

Estas pruebas no contactan Rexel y no deben presentarse como una prueba real del flujo.

## JavaScript y detección conservadora de sesión

```powershell
node tests/rexel_extension_core_test.js
node tests/rexel_extension_batch_test.js
node --check extension/rexel-local-scraper/extractor.js
node --check extension/rexel-local-scraper/content-script.js
node --check extension/rexel-local-scraper/service-worker.js
node --check extension/rexel-local-scraper/popup.js
node --check extension/rexel-local-scraper/panel-bridge.js
```

## Extracción con HTML de ejemplo

Abre `tests/rexel_extension_extractor_test.html` en Chrome, Edge o Brave. Debe mostrar en verde:

`OK: HTML de ejemplo, Your Price, asociación por tarjeta y detección de sesión`

La prueba incluye dos tarjetas vecinas y confirma que el producto sin precio no herede el precio de la otra tarjeta.

## Backend aislado con SQLite temporal

Usa un PHP con `pdo_sqlite` habilitado:

```powershell
php tests/rexel_extension_backend_test.php
```

Valida URL y precios, autorización por sesión/token/lote, correspondencia exacta, preservación de `NULL` cuando falta el precio, transición de `precio_anterior`, consolidación de varias URLs y prevención de duplicados al reenviar/aplicar.

La automatización del recorrido y la sesión real de Rexel solo pueden comprobarse cargando la extensión 0.4.1 en el navegador del usuario. Las pruebas anteriores usan HTML/SQLite aislados y no constituyen una prueba real contra Rexel.
