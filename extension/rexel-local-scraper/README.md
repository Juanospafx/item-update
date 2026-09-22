# Rexel Local Scraper (prototipo experimental)

## Instalar en Chrome, Edge o Brave

1. Abre `chrome://extensions` en Chrome/Brave o `edge://extensions` en Edge.
2. Activa **Modo de desarrollador**.
3. Pulsa **Cargar descomprimida**.
4. Selecciona esta carpeta completa: `extension/rexel-local-scraper`.
5. Fija la extensión **Rexel Local Scraper (Experimental)** en la barra.

No se instala ninguna dependencia y no es necesario empaquetar la extensión.
La extensión solo solicita almacenamiento local y acceso a Rexel. No requiere acceso al historial ni a URLs de archivos.

## Recorrido automático (recomendado)

1. En el panel abre **Rexel en navegador local — EXPERIMENTAL**.
2. Elige una categoría o **Todas las categorías y URLs activas**, y define el máximo de productos por URL.
3. Pulsa **Iniciar recorrido automático**. El panel crea códigos de un solo uso y los entrega internamente a la extensión; no necesitas copiar la URL del API ni ningún código.
4. La extensión abre una pestaña de Rexel y recorre las URLs configuradas una por una.
5. Si Rexel solicita inicio de sesión, CAPTCHA o verificación, complétalo directamente en esa pestaña. Después abre la extensión y pulsa **Continuar después del login**.
6. Revisa en el panel la vista previa consolidada y sus conteos. Todavía no se ha escrito ningún precio.
7. Solo si los datos son correctos, pulsa **Aplicar precios válidos del lote** y confirma.

El formulario para vincular manualmente una URL y un código se conserva como respaldo dentro de **Prueba manual de una sola URL**. La automatización requiere la versión 0.3.0 de la extensión y que el panel esté alojado en `https://item-update.brightronix.net`.

## Límites deliberados

- Procesa hasta 60 URLs activas por lote y como máximo 25 productos por URL (10 de forma predeterminada).
- Hace scroll para provocar carga diferida, pero no recorre paginación.
- Una lista virtualizada puede retirar productos anteriores del DOM y dejar una vista parcial.
- La detección de sesión usa formularios/mensajes explícitos. Una URL o un símbolo `$` por sí solos no se consideran prueba de autenticación.
- Prioriza `Your Price`, `Net Price` o `Tu Precio`. El precio alternativo solo se toma desde un selector de precio dentro de la tarjeta concreta; nunca desde un contenedor que incluya productos vecinos.

## Datos que se conservan

En el navegador se conservan temporalmente, mediante `chrome.storage.local`, la URL del API, los ID/tokens temporales de los trabajos del lote, las URLs objetivo, límites, pestaña e índice de progreso. El service worker puede suspenderse y reconstruir el estado desde ahí. Se puede borrar con **Desvincular**.

El servidor conserva el lote y sus trabajos, los hashes de códigos/tokens temporales, los resultados enviados, sus correspondencias exactas con `catalogo_web` y el resumen de aplicación. No recibe credenciales, cookies, tokens ni `localStorage` de Rexel. Si el mismo producto aparece con el mismo precio en varias URLs, solo se actualiza una vez; si llegan precios distintos para el mismo registro, se omiten por conflicto.

## Desactivar sin afectar el flujo anterior

Cambia `REXEL_EXTENSION_EXPERIMENT_ENABLED` a `false` en `web/rexel_extension_config.php`. Esto oculta la tarjeta experimental y hace que el API responda como desactivado. Los scripts Playwright, la sesión anterior y Procore no se modifican.
