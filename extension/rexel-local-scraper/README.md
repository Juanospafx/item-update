# Rexel Local Scraper (prototipo experimental)

## Instalar en Chrome, Edge o Brave

1. Abre `chrome://extensions` en Chrome/Brave o `edge://extensions` en Edge.
2. Activa **Modo de desarrollador**.
3. Pulsa **Cargar descomprimida**.
4. Selecciona esta carpeta completa: `extension/rexel-local-scraper`.
5. Fija la extensión **Rexel Local Scraper (Experimental)** en la barra.

No se instala ninguna dependencia y no es necesario empaquetar la extensión.
La extensión solo solicita almacenamiento local y acceso a Rexel. No requiere acceso al historial ni a URLs de archivos.

## Primera prueba

1. En el panel abre **Rexel en navegador local — EXPERIMENTAL**.
2. Elige una sola URL configurada, deja el límite en 10 y crea el trabajo.
3. Abre la extensión, pega la URL del API que muestra el panel y el código temporal.
4. Pulsa **Vincular trabajo** y acepta únicamente el permiso para el origen de tu panel.
5. Pulsa **Abrir Rexel**.
6. Si Rexel solicita inicio de sesión, CAPTCHA o verificación, complétalo directamente en esa pestaña. Después abre la extensión y pulsa **Continuar / extraer**.
7. Revisa en el panel la vista previa y sus conteos. Todavía no se ha escrito ningún precio.
8. Solo si los datos son correctos, pulsa **Aplicar precios válidos** y confirma.

## Límites deliberados

- Procesa una URL y como máximo 25 productos por trabajo (10 de forma predeterminada).
- Hace scroll para provocar carga diferida, pero no recorre paginación.
- Una lista virtualizada puede retirar productos anteriores del DOM y dejar una vista parcial.
- La detección de sesión usa formularios/mensajes explícitos. Una URL o un símbolo `$` por sí solos no se consideran prueba de autenticación.
- Prioriza `Your Price`, `Net Price` o `Tu Precio`. El precio alternativo solo se toma desde un selector de precio dentro de la tarjeta concreta; nunca desde un contenedor que incluya productos vecinos.

## Datos que se conservan

En el navegador se conserva temporalmente, mediante `chrome.storage.local`, la URL del API, ID/token temporal del trabajo, URL objetivo, límite, pestaña y progreso. El service worker puede suspenderse y reconstruir el estado desde ahí. Se puede borrar con **Desvincular**.

El servidor conserva el trabajo, el hash del código/token temporal, el resultado enviado, su correspondencia exacta con `catalogo_web` y el resumen de aplicación. No recibe credenciales, cookies, tokens ni `localStorage` de Rexel.

## Desactivar sin afectar el flujo anterior

Cambia `REXEL_EXTENSION_EXPERIMENT_ENABLED` a `false` en `web/rexel_extension_config.php`. Esto oculta la tarjeta experimental y hace que el API responda como desactivado. Los scripts Playwright, la sesión anterior y Procore no se modifican.
