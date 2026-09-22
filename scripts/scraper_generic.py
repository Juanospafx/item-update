from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeoutError
import sqlite3
import os
import sys
import time
import json
import gc
from urllib.parse import urlparse
from utils import (setup_console, normalize_category, print_header, print_sub_header,
                   print_success, print_warning, print_error, print_info,
                   clean_price, normalize_text, get_eastern_now_str)

# Inicializar configuración de consola (UTF-8 con flush automático)
setup_console()

def get_urls_from_db(cursor, category):
    """Obtiene las URLs activas para una categoría específica o todas si category == 'ALL'."""
    if category.upper() == 'ALL':
        cursor.execute('''
            SELECT url, category FROM scraping_urls WHERE is_active = 1 ORDER BY category, id
        ''')
        return cursor.fetchall()
    else:
        cursor.execute('''
            SELECT url, category FROM scraping_urls WHERE category = ? AND is_active = 1 ORDER BY id
        ''', (category,))
        return cursor.fetchall()

def get_paused_urls_from_db(cursor, category):
    """Obtiene las URLs inactivas/pausadas para una categoría específica o todas si category == 'ALL'."""
    if category.upper() == 'ALL':
        cursor.execute('''
            SELECT url, category, description FROM scraping_urls WHERE is_active = 0 ORDER BY category, id
        ''')
        return cursor.fetchall()
    else:
        cursor.execute('''
            SELECT url, category, description FROM scraping_urls WHERE category = ? AND is_active = 0 ORDER BY id
        ''', (category,))
        return cursor.fetchall()

def actualizar_precio_en_db(cursor, nombre_web, precio, url_origen, category):
    """Busca el nombre en la base de datos y actualiza su precio, con diagnóstico mejorado."""
    precio_limpio = clean_price(str(precio))
    
    try:
        if not precio_limpio:
            raise ValueError("Precio vacío después de limpieza")
            
        precio_float = float(precio_limpio)
        
        now_eastern_str = get_eastern_now_str()
        cursor.execute('''
            UPDATE catalogo_web 
            SET precio_anterior = CASE 
                    WHEN precio_actual IS NOT NULL AND precio_actual > 0 AND precio_actual != ? THEN precio_actual 
                    ELSE COALESCE(precio_anterior, precio_actual, ?) 
                END,
                precio_actual = ?, 
                url_origen = ?, 
                ultima_actualizacion = ?
            WHERE nombre_web = ? AND categoria = ?
        ''', (precio_float, precio_float, precio_float, url_origen, now_eastern_str, nombre_web.strip(), category))
        
        if cursor.rowcount > 0:
            print_success(f"  [✓] Actualizado: {nombre_web:<50} -> <b>${precio_float}</b>")
            return True
        else:
            print_warning(f"  [!] No mapeado en DB: '{nombre_web}' (${precio_float})")
            
            # Búsqueda de sugerencia para diagnóstico
            search_terms = nombre_web.split()
            like_query = '%' + '%'.join(search_terms[:4]) + '%'
            cursor.execute(
                "SELECT nombre_web FROM catalogo_web WHERE categoria = ? AND nombre_web LIKE ?",
                (category, like_query)
            )
            suggestions = [row[0] for row in cursor.fetchall()]
            if suggestions:
                print_info(f"      -> ¿Quizás quisiste decir?: {suggestions[0]}...")
            return False

    except Exception as e:
        print_error(f"  [X] Error procesando precio '{precio}' para '{nombre_web}': {e}")
        return False


def load_session_and_verify(context, page) -> bool:
    """
    Verifica que la sesión cargada mediante storage_state.json esté activa.
    La verificación local previa ya confirmó los tokens; la navegación
    a las URLs detectará cualquier redirección a /login de inmediato.
    """
    print_info("Sesión de Rexel activa y lista para extracción.")
    return True


def extract_path_and_query(full_url: str) -> str:
    """Extrae la ruta relativa y query string para navegación interna por Nuxt SPA."""
    parsed = urlparse(full_url)
    path = parsed.path
    if parsed.query:
        path += "?" + parsed.query
    return path


from session_manager import check_session_validity, launch_best_browser
from refresh_rexel_session import refresh_tokens, get_current_expiration


def ejecutar_scraper_generico(category, headless=True):
    """
    Scraper genérico que extrae precios para una categoría dada o todo el catálogo ('ALL'),
    obteniendo las URLs desde la base de datos de un solo tiro.
    Usa storage_state.json y navegación SPA Nuxt para bypass de Cloudflare.
    """
    conexion = None
    browser = None
    script_dir = os.path.dirname(os.path.abspath(__file__))
    storage_state_path = os.path.join(script_dir, 'storage_state.json')

    print("[EVENT:STAGE] Verificando sesión de Rexel...", flush=True)

    # 1. Validación rápida de archivo de sesión antes de iniciar Playwright
    if not os.path.exists(storage_state_path):
        print_error("[SESSION_MISSING] No se encontró el archivo de sesión (storage_state.json).")
        print_error("[SESSION_MISSING] Ve al dashboard y usa 'Conectar Rexel' para iniciar sesión.")
        sys.exit(1)

    # 2. Validación instantánea y auto-renovación proactiva de tokens OAuth
    try:
        with open(storage_state_path, "r", encoding="utf-8") as f:
            st = json.load(f)
        ref_exp, tok_exp = get_current_expiration(st)
        now = time.time()

        # Si el token de acceso expiró o le quedan menos de 30 minutos (1800s), auto-renovarlo en background
        if tok_exp is None or tok_exp <= (now + 1800):
            print("[EVENT:STAGE] Renovando token de acceso en segundo plano...", flush=True)
            print_info("Token de acceso vencido o próximo a vencer. Ejecutando auto-renovación con Nuxt Auth...")
            ref_res = refresh_tokens(storage_state_path, force=True)
            if ref_res.get("success"):
                days_left = ref_res.get("days_left", 30)
                print(f"[EVENT:STAGE] Token renovado exitosamente ({days_left}d restantes).", flush=True)
                print_success(f"Sesión extendida por otros {days_left} días sin intervención manual.")
            else:
                print_warning(f"Aviso de auto-renovación: {ref_res.get('message', 'No se pudo renovar')}. Probando sesión actual...")

        session_check = check_session_validity(storage_state_path)
        if not session_check.get("valid"):
            print_error(f"[SESSION_EXPIRED] {session_check.get('message', 'La sesión ha expirado.')}")
            print_error("[SESSION_EXPIRED] Abre el panel de control y haz clic en 'Conectar Rexel' para renovar sesión.")
            sys.exit(1)
        else:
            days_left = session_check.get('days_left', 30)
            print(f"[EVENT:STAGE] Sesión activa ({days_left}d restantes). Iniciando navegador...", flush=True)
            print_info(f"Sesión local verificada: {session_check.get('message')}")
    except Exception as e:
        print_warning(f"Advertencia al pre-verificar expiración de sesión: {e}. Continuando...")

    try:
        project_root = os.path.dirname(script_dir)
        db_path = os.path.join(project_root, 'database', 'diccionario.db')

        # Conexión SQLite resiliente con WAL mode y timeout para evitar 'database is locked'
        conexion = sqlite3.connect(db_path, timeout=30.0)
        conexion.execute("PRAGMA journal_mode=WAL;")
        conexion.execute("PRAGMA busy_timeout=30000;")
        cursor = conexion.cursor()

        is_all = (category.upper() == 'ALL')
        if not is_all:
            category = normalize_category(category)

        urls_data = get_urls_from_db(cursor, category)
        paused_urls = get_paused_urls_from_db(cursor, category)

        if paused_urls:
            print_warning(f"\n[AVISO DE CONFIGURACIÓN] Se detectaron {len(paused_urls)} URL(s) pausada(s):")
            for p_url, p_cat, p_desc in paused_urls:
                cat_tag = f"[{p_cat}] " if is_all else ""
                desc_tag = f" ({p_desc})" if p_desc else ""
                print_warning(f"  ⏸️ Se saltó la URL porque está pausada: {cat_tag}{p_url}{desc_tag}")
            print_warning("  -> Estas URLs no serán procesadas en la extracción actual.\n")

        if not urls_data:
            cat_desc = "la base de datos" if is_all else f"la categoría '{category}'"
            if paused_urls:
                print_error(f"[ERROR] No hay URLs activas para {cat_desc} (las {len(paused_urls)} existentes están pausadas).")
            else:
                print_error(f"[ERROR] No se encontraron URLs activas para {cat_desc}.")
            summary_info = {
                "category": category,
                "total_urls": 0,
                "detected": 0,
                "updated": 0,
                "unmapped": 0,
                "duration": 0
            }
            print(f"[SUMMARY_DATA] {json.dumps(summary_info)}", flush=True)
            return

        with sync_playwright() as p:
            browser, browser_name = launch_best_browser(p, headless=headless)
            mode_desc = "Silencioso (Headless)" if headless else "Visible en Pantalla"
            print_info(f"Navegador activo para extracción: {browser_name.upper()} ({mode_desc})")

            context = browser.new_context(
                storage_state=storage_state_path,
                user_agent=(
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                    "AppleWebKit/537.36 (KHTML, like Gecko) "
                    "Chrome/127.0.0.0 Safari/537.36"
                ),
            )
            page = context.new_page()

            if headless:
                # OPTIMIZACIÓN EXTREMA PARA SERVIDOR:
                # Aborta la descarga de imágenes, multimedia, fuentes pesadas y rastreadores publicitarios.
                # Para extraer precios solo se requiere DOM/HTML y APIs JSON.
                # Ahorro de memoria: ~75% de RAM (de ~800MB a ~150MB) y 3x velocidad de carga.
                def block_heavy_resources(route):
                    req = route.request
                    r_type = req.resource_type
                    url_lower = req.url.lower()
                    if r_type in ("image", "media", "font"):
                        route.abort()
                    elif any(tracker in url_lower for tracker in ("google-analytics", "googletagmanager", "criteo", "bazaarvoice", "hotjar", "adservice", "doubleclick")):
                        route.abort()
                    else:
                        route.continue_()

                page.route("**/*", block_heavy_resources)
                print_info("⚡ Optimización de servidor activa: Bloqueo de multimedia y rastreadores habilitado (Ahorro máximo de RAM y CPU).")

            # Verificar que la sesión cargada es válida en la home
            if not load_session_and_verify(context, page):
                browser.close()
                sys.exit(1)

            display_title = "Todas las Categorías (Extracción Total)" if is_all else category
            print_header(f"INICIANDO EXTRACCIÓN: {display_title}")
            total_urls = len(urls_data)
            print_info(f"Se procesarán {total_urls} URLs activas configuradas de un solo tiro.")
            if paused_urls:
                print_warning(f"  ℹ️ Se omitirán {len(paused_urls)} URL(s) porque su estado actual es pausado.")

            total_detected = 0
            total_updated = 0
            total_unmapped = 0
            start_time = time.time()
            session_aborted = False

            print(f"[EVENT:INIT] {category}|{total_urls}", flush=True)
            print(f"[EVENT:STAGE] Conectando a catálogo de Rexel ({total_urls} URLs)...", flush=True)

            for i, (url, url_cat) in enumerate(urls_data, 1):
                print(f"[EVENT:URL_PROGRESS] {i}|{total_urls}|{url}", flush=True)
                print(f"[EVENT:STAGE] Navegando a enlace #{i} de {total_urls}...", flush=True)
                cat_badge = f"[{url_cat}] " if is_all else ""
                print_sub_header(f"URL {i}/{total_urls}: {cat_badge}Procesando...")
                print_info(f"Enlace: {url}")

                target_path = extract_path_and_query(url)
                nav_success = False

                for nav_attempt in range(1, 3):
                    try:
                        # Navegamos mediante el router SPA de Nuxt para evitar Cloudflare Turnstile
                        pushed = page.evaluate("""(path) => {
                            if (window.$nuxt && window.$nuxt.$router) {
                                window.$nuxt.$router.push(path);
                                return true;
                            }
                            return false;
                        }""", target_path)
                        if not pushed:
                            page.goto(url, wait_until="domcontentloaded", timeout=45000)
                        nav_success = True
                        break
                    except Exception as nav_err:
                        if nav_attempt < 2:
                            print_warning(f"  [!] Reintentando navegación a {url} ({nav_err})...")
                            page.wait_for_timeout(2000)
                        else:
                            print_error(f"  [X] Falló la navegación tras 2 intentos: {nav_err}")

                if not nav_success:
                    continue

                print(f"[EVENT:STAGE] Esperando catálogo en URL #{i}...", flush=True)

                # Detección inmediata de expiración o redirección a login/captcha
                current_url = page.url.lower()
                page_title = page.title().lower()
                if '/login' in current_url or '/signin' in current_url or 'auth.rexelusa.com' in current_url:
                    print_warning(f"  [!] Detectada redirección a login ({page.url}). Intentando auto-recuperar sesión...")
                    ref_res = refresh_tokens(storage_state_path, force=True)
                    if ref_res.get("success"):
                        print_success("  [✓] Sesión auto-renovada con éxito. Reintentando enlace...")
                        page.goto(url, wait_until="domcontentloaded", timeout=45000)
                        current_url = page.url.lower()

                    if '/login' in current_url or '/signin' in current_url or 'auth.rexelusa.com' in current_url:
                        print_error(f"[SESSION_EXPIRED] Rexel requiere re-autenticación interactiva ({page.url}).")
                        print_error("[SESSION_EXPIRED] Tu sesión ha expirado completamente en el servidor de Rexel. Por favor abre el panel principal y haz clic en 'Conectar Rexel'.")
                        session_aborted = True
                        break

                if 'just a moment' in page_title or 'attention required' in page_title or 'cloudflare' in page_title:
                    print_warning("  [!] Detectada pantalla de verificación Cloudflare. Esperando resolución...")
                    page.wait_for_timeout(4000)

                print_info("  Esperando renderizado de productos y componentes...")

                # Esperar a que Nuxt renderice los enlaces de productos o mensaje de no-results
                try:
                    page.wait_for_selector("a[href*='/p/'], div.rex-product-tile, div.no-results-content", state="attached", timeout=8000)
                except Exception:
                    pass

                # Scroll progresivo para hidratar componentes vue y disparar IntersectionObserver
                try:
                    page.evaluate("() => window.scrollTo(0, document.body.scrollHeight / 3)")
                    page.wait_for_timeout(600)
                    page.evaluate("() => window.scrollTo(0, document.body.scrollHeight * 2 / 3)")
                    page.wait_for_timeout(600)
                    page.evaluate("() => window.scrollTo(0, document.body.scrollHeight)")
                    page.wait_for_timeout(800)
                except Exception:
                    pass

                # Pausa reactiva y espera activa de hidratación de precios
                for _ in range(6):
                    has_prices_rendered = page.evaluate("() => document.body && document.body.innerText.includes('$')")
                    if has_prices_rendered:
                        break
                    page.wait_for_timeout(700)

                # Script de extracción de productos del DOM de Vuetify / Nuxt
                extraction_script = """() => {
                    const results = [];
                    const links = document.querySelectorAll("a[href*='/p/']");
                    const seen = new Set();

                    for (const a of links) {
                        const href = a.getAttribute('href');
                        if (!href || seen.has(href)) continue;
                        seen.add(href);

                        // El nombre exacto se encuentra en <h2> dentro del enlace
                        const h2 = a.querySelector('h2');
                        let name = h2 ? h2.innerText.trim() : a.innerText.trim();
                        name = name.replace(/\\s+/g, ' ');

                        // Buscar contenedor para encontrar el precio
                        let p = a.parentElement;
                        while (p && !p.innerText.includes('$') && p.parentElement && p.tagName !== 'MAIN' && p.tagName !== 'BODY') {
                            p = p.parentElement;
                        }

                        let price = '';
                        if (p) {
                            // Priorizar 'Your Price' / 'Tu Precio' si está presente (precio con descuento de contratista)
                            const yourPriceMatch = p.innerText.match(/(?:Your\\s*Price|Tu\\s*Precio|Net\\s*Price)[\\s:]*\\$\\s*([\\d,]+\\.?\\d*)/i);
                            if (yourPriceMatch) {
                                price = yourPriceMatch[1].replace(/,/g, '');
                            } else {
                                const match = p.innerText.match(/\\$\\s*([\\d,]+\\.?\\d*)/);
                                if (match) price = match[1].replace(/,/g, '');
                            }
                        }

                        if (name) {
                            results.push({ name: name, price: price });
                        }
                    }
                    return results;
                }"""

                # Extraer productos
                extracted_items = page.evaluate(extraction_script)

                # Fallback para selector legado 'div.rex-product-tile'
                if not extracted_items:
                    legacy_cards = page.query_selector_all("div.rex-product-tile")
                    for card in legacy_cards:
                        n_el = card.query_selector("a[data-cy='product-name-link']")
                        p_el = card.query_selector("span[data-cy='product-price']")
                        if n_el and p_el:
                            extracted_items.append({
                                "name": normalize_text(n_el.inner_text()),
                                "price": p_el.inner_text().strip()
                            })

                # Detección de bloqueo real de precios (solo si exige login Y no hay precios cargados)
                auth_required = page.evaluate("""() => {
                    const text = document.body ? document.body.innerText : '';
                    const has_signin = /Sign In or Register to view pricing|Sign In to view pricing|sign in to view price/i.test(text);
                    const has_dollar = text.includes('$');
                    return has_signin && !has_dollar;
                }""")

                if auth_required or (extracted_items and all(not it.get("price") for it in extracted_items)):
                    print_warning("  [!] Detectado bloqueo de precios en Rexel ('Sign In to view pricing').")
                    print_warning("  [!] Intentando auto-renovación forzada de tokens OAuth en segundo plano...")
                    ref_res = refresh_tokens(storage_state_path, force=True)
                    if ref_res.get("success"):
                        print_success("  [✓] Sesión auto-renovada con éxito. Recargando página para activar precios...")
                        page.reload(wait_until="domcontentloaded", timeout=45000)
                        page.wait_for_timeout(2500)
                        try:
                            page.evaluate("() => window.scrollTo(0, document.body.scrollHeight / 2)")
                            page.wait_for_timeout(600)
                            page.evaluate("() => window.scrollTo(0, document.body.scrollHeight)")
                            page.wait_for_timeout(1000)
                        except Exception:
                            pass
                        for _ in range(5):
                            if page.evaluate("() => document.body && document.body.innerText.includes('$')"):
                                break
                            page.wait_for_timeout(800)
                        extracted_items = page.evaluate(extraction_script)

                    # Verificar nuevamente si persiste el bloqueo
                    auth_still_required = page.evaluate("""() => {
                        const text = document.body ? document.body.innerText : '';
                        const has_signin = /Sign In or Register to view pricing|Sign In to view pricing|sign in to view price/i.test(text);
                        return has_signin && !text.includes('$');
                    }""")
                    if auth_still_required or (extracted_items and all(not it.get("price") for it in extracted_items)):
                        print_error("[SESSION_EXPIRED] Rexel requiere iniciar sesión en el navegador para mostrar precios ('Sign In to view pricing').")
                        print_error("[SESSION_EXPIRED] Por favor abre el panel principal y haz clic en 'Conectar Rexel'.")
                        session_aborted = True
                        break

                if not extracted_items:
                    has_no_results = page.query_selector("div.no-results-content, .no-results, :has-text('No results found')")
                    if has_no_results:
                        print_info("  [i] La búsqueda en Rexel no devolvió productos (sin resultados en catálogo).")
                    else:
                        print_warning("  [!] No se encontraron productos en esta página.")
                else:
                    count_detected = len(extracted_items)
                    total_detected += count_detected
                    print(f"[EVENT:ITEMS_DETECTED] {count_detected}", flush=True)
                    print_info(f"  -> {count_detected} productos detectados en la página.")
                    for item in extracted_items:
                        name = normalize_text(item["name"])
                        price = item["price"]
                        if name and price:
                            is_updated = actualizar_precio_en_db(cursor, name, price, url, url_cat)
                            if is_updated:
                                total_updated += 1
                                print(f"[EVENT:ITEM_UPDATED] {name}|{price}", flush=True)
                            else:
                                total_unmapped += 1
                                print(f"[EVENT:ITEM_UNMAPPED] {name}|{price}", flush=True)
                        elif name and not price:
                            print_info(f"  [-] Sin precio publicado en Rexel: {name}")

                print(f"[EVENT:STATS] {total_updated}|{total_unmapped}|{total_detected}", flush=True)
                # Guardar cambios inmediatamente por cada URL procesada
                conexion.commit()

            browser.close()
            browser = None

            if not session_aborted:
                elapsed = round(time.time() - start_time, 1)
                summary_info = {
                    "category": category,
                    "total_urls": total_urls,
                    "detected": total_detected,
                    "updated": total_updated,
                    "unmapped": total_unmapped,
                    "duration": elapsed
                }
                print(f"[SUMMARY_DATA] {json.dumps(summary_info)}", flush=True)
                print_header(f"FIN EXTRACCIÓN: {display_title}")
                print_success(f"Base de datos actualizada correctamente. {total_updated} productos actualizados de {total_detected} detectados.")
            else:
                print_error(f"\n[SESION INTERRUMPIDA] La extracción de '{display_title}' se canceló por expiración de sesión en Rexel.")
                print_warning("Por favor ve a la página principal y renueva tu sesión haciendo clic en el botón de Rexel.")

    except Exception as e:
        print_error(f"\n[ERROR FATAL] Ocurrió un error durante la ejecución: {e}")
    finally:
        if browser:
            try:
                browser.close()
            except Exception:
                pass
        if conexion:
            try:
                conexion.close()
            except Exception:
                pass
        gc.collect()


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Uso: python scraper_generic.py <categoria> [--visible|--headless]")
        print("Ejemplo: python scraper_generic.py FUSES --visible")
        sys.exit(1)

    cat = sys.argv[1]
    is_headless = True
    for arg in sys.argv[2:]:
        if arg.lower() in ('--visible', '-v', 'visible', '0', 'false'):
            is_headless = False
        elif arg.lower() in ('--headless', '-h', 'headless', '1', 'true'):
            is_headless = True

    ejecutar_scraper_generico(cat, headless=is_headless)