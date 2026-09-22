import sys
import os
import time
import re

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
if SCRIPT_DIR not in sys.path:
    sys.path.insert(0, SCRIPT_DIR)

from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeoutError
try:
    from utils import launch_best_browser
except ImportError:
    from scripts.utils import launch_best_browser

# Forzar la codificación de salida a UTF-8 para evitar errores en Windows
if sys.platform == "win32":
    sys.stdout.reconfigure(encoding='utf-8')

def log_event(event_name, message):
    """Emite eventos estructurados para monitoreo en vivo desde el frontend."""
    print(f"[PROCORE_EVENT:{event_name}] {message}")
    sys.stdout.flush()

def upload_to_procore(email, password, excel_path, headless=False):
    """
    Inicia sesión en Procore y sube un archivo de catálogo/presupuesto.
    Soporta modo headless (sin ventana para ahorrar recursos) o con ventana para depuración.
    """
    print("=" * 60)
    print("🚀 INICIANDO MACRO DE SUBIDA AUTOMATIZADA A PROCORE")
    print(f"📦 Archivo destino: {excel_path}")
    print(f"🖥️ Modo de ejecución: {'Silencioso (Headless - Ahorro de recursos)' if headless else 'Con Ventana Visible (Supervisión)'}")
    print("=" * 60)
    sys.stdout.flush()

    if not os.path.exists(excel_path):
        print(f"[ERROR CRÍTICO] El archivo Excel no existe en la ruta: {excel_path}")
        return False

    log_event("INIT", "Iniciando navegador y configurando entorno...")

    with sync_playwright() as p:
        browser, browser_name = launch_best_browser(p, headless=headless)
        print(f"[PROCORE] Motor de navegador activo: {browser_name.upper()} ({'Headless' if headless else 'Visible'})")
        sys.stdout.flush()
        context = browser.new_context(
            viewport={"width": 1920, "height": 1080} if headless else None,
            no_viewport=not headless,
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36"
        )
        page = context.new_page()
        page.set_default_timeout(45000)

        try:
            # --- PASO 1: LOGIN EN PROCORE ---
            log_event("LOGIN_START", "Navegando a la página de inicio de sesión de Procore...")
            page.goto("https://login.procore.com/", wait_until="domcontentloaded")
            time.sleep(1)

            print("   - Ingresando correo electrónico...")
            page.wait_for_selector("input[type='email'], input[name='email']", state="visible", timeout=20000)
            page.fill("input[type='email'], input[name='email']", email)
            
            # Botón siguiente/login
            if page.locator("#login-btn").is_visible():
                page.click("#login-btn")
            elif page.locator("button:has-text('Continue'), button:has-text('Continuar')").is_visible():
                page.click("button:has-text('Continue'), button:has-text('Continuar')")
            elif page.locator("button[type='submit']").is_visible():
                page.click("button[type='submit']")

            print("   - Ingresando contraseña...")
            page.wait_for_selector("input[type='password']", state="visible", timeout=25000)
            page.fill("input[type='password']", password)
            
            if page.locator("#login-btn").is_visible():
                page.click("#login-btn")
            elif page.locator("button:has-text('Log In'), button:has-text('Iniciar sesión')").is_visible():
                page.click("button:has-text('Log In'), button:has-text('Iniciar sesión')")
            elif page.locator("button[type='submit']").is_visible():
                page.click("button[type='submit']")

            log_event("LOGIN_VERIFY", "Esperando autenticación y redirección al panel principal...")
            print("   - Verificando credenciales y esperando redirección...")

            # Esperar a que la URL salga de la pantalla de login o llegue al Hub/App
            try:
                page.wait_for_function(
                    "() => window.location.href.includes('/hub') || !window.location.href.includes('login.procore.com') || document.querySelector(\"a[href*='/company/'], .company-card, [data-qa*='company']\")",
                    timeout=30000
                )
            except Exception:
                pass

            page.wait_for_load_state("domcontentloaded")
            time.sleep(2)
            current_url = page.url
            print(f"   -> URL activa tras login: {current_url}")

            # Detección de posibles pantallas intermedias de selección de empresa o Procore Hub
            if "/hub" in current_url.lower() or page.locator("text=Choose a company, text=Seleccionar empresa, text=Select Company, .company-card, a[href*='/company/']").count() > 0:
                print("   [INFO] Detectada pantalla de Hub o selección de compañía. Seleccionando la primera disponible...")
                first_company = page.locator("a[href*='/company/'], a[href*='procore.com/'], button[data-qa*='company'], .company-card").first
                if first_company.count() > 0:
                    first_company.click()
                    page.wait_for_load_state("domcontentloaded")
                    time.sleep(2)

            log_event("LOGIN_SUCCESS", "Autenticación exitosa en Procore.")

            # --- PASO 2: NAVEGACIÓN A COST CATALOG ---
            log_event("NAV_CATALOG", "Accediendo a la herramienta Cost Catalog...")
            print("\n2. Navegando hacia la herramienta Cost Catalog...")

            # Estrategia A: Extraer ID de compañía de la URL o DOM para navegación directa
            company_id = None
            company_id_match = re.search(r'procore\.com/(?:companies/|company/)?(\d+)', page.url)
            if company_id_match:
                company_id = company_id_match.group(1)
            else:
                try:
                    meta_company = page.locator("meta[name='current-company-id'], meta[name='company-id']").first
                    if meta_company.count() > 0:
                        company_id = meta_company.get_attribute("content")
                except Exception:
                    pass
                if not company_id:
                    try:
                        comp_link = page.locator("a[href*='/company/']").first
                        if comp_link.count() > 0:
                            href = comp_link.get_attribute("href")
                            m = re.search(r'/(?:companies/|company/)?(\d+)/', href or '')
                            if m:
                                company_id = m.group(1)
                    except Exception:
                        pass

            direct_nav_success = False

            if company_id:
                direct_catalog_url = f"https://app.procore.com/{company_id}/company/cost_catalog"
                print(f"   - [Estrategia Directa] ID de Compañía detectado: {company_id}")
                print(f"   - Navegando directamente a: {direct_catalog_url}...")
                try:
                    page.goto(direct_catalog_url, wait_until="domcontentloaded", timeout=30000)
                    time.sleep(2)
                    if "cost_catalog" in page.url:
                        direct_nav_success = True
                        print("   -> Acceso directo a Cost Catalog confirmado.")
                except Exception as ex:
                    print(f"   - Aviso en navegación directa: {ex}. Intentando mediante enlaces y menú...")

            # Estrategia B: Enlace directo en el DOM a cost_catalog
            if not direct_nav_success:
                try:
                    direct_links = page.locator("a[href*='cost_catalog']")
                    if direct_links.count() > 0:
                        href = direct_links.first.get_attribute("href")
                        if href:
                            print(f"   - [Estrategia Enlace] Encontrado enlace a Cost Catalog: {href}")
                            target_url = href if href.startswith("http") else f"https://app.procore.com{href}"
                            page.goto(target_url, wait_until="domcontentloaded", timeout=30000)
                            time.sleep(2)
                            if "cost_catalog" in page.url:
                                direct_nav_success = True
                                print("   -> Acceso vía enlace confirmado.")
                except Exception as ex_link:
                    print(f"   - Aviso en enlace DOM: {ex_link}")

            # Estrategia C: Selector de Herramientas (Tool Picker) en el Header
            if not direct_nav_success:
                print("   - Buscando menú de herramientas (Tool Picker) en el header...")
                tool_picker_selectors = [
                    "#tool-picker-target",
                    "button[data-qa='tool-picker-target']",
                    "button[data-testid='tool-picker-target']",
                    "button[data-testid='tool-picker']",
                    "button[data-testid='tool-picker-trigger']",
                    "button[data-qa='tool-picker-button']",
                    "button[aria-label='Select Tool']",
                    "button[aria-label*='Tool']",
                    "button[aria-label*='Herramientas']",
                    "button:has-text('Tools')",
                    "button:has-text('Select Tool')",
                    "button:has-text('Herramientas')",
                    "header button:has(svg)",
                    "[role='banner'] button"
                ]

                tool_picker_found = False
                for sel in tool_picker_selectors:
                    if page.locator(sel).count() > 0 and page.locator(sel).first.is_visible():
                        print(f"   - Abriendo Tool Picker con selector: {sel}")
                        page.locator(sel).first.click()
                        tool_picker_found = True
                        break

                if not tool_picker_found:
                    print("   - Esperando carga de header/banner...")
                    try:
                        page.wait_for_selector("header, [role='banner'], nav, [data-testid='header-container']", timeout=15000)
                        for sel in tool_picker_selectors:
                            if page.locator(sel).count() > 0:
                                page.locator(sel).first.click()
                                tool_picker_found = True
                                break
                    except Exception:
                        pass

                time.sleep(1)

                # Clic en Cost Catalog en el menú desplegado
                print("   - Localizando enlace a 'Cost Catalog'...")
                catalog_link_selectors = [
                    "a[data-header='tools-menu-cost_catalog']",
                    "a[href*='cost_catalog']",
                    "a:has-text('Cost Catalog')",
                    "a:has-text('Catálogo de Costos')",
                    "[data-qa='tools-menu-cost_catalog']"
                ]

                clicked_catalog = False
                for link_sel in catalog_link_selectors:
                    if page.locator(link_sel).count() > 0 and page.locator(link_sel).first.is_visible():
                        print(f"   - Clic en Cost Catalog usando: {link_sel}")
                        page.locator(link_sel).first.click()
                        clicked_catalog = True
                        break

                if not clicked_catalog:
                    raise Exception("No se pudo localizar el enlace 'Cost Catalog' en el menú de herramientas.")

            # --- PASO 3: MENÚ DE ACCIONES E IMPORTACIÓN ---
            log_event("IMPORT_MENU", "Localizando menú de importación en la tabla de catálogo...")
            print("\n3. Accediendo al menú de importación de Cost Catalog...")

            page.wait_for_load_state("domcontentloaded")
            time.sleep(2)

            # Esperar a que los botones de acción del catálogo se carguen en la página
            print("   - Esperando a que cargue la interfaz de Cost Catalog...")
            try:
                page.wait_for_selector(
                    "button:has(svg[data-qa='ci-EllipsisVertical']), button:has(svg[name='EllipsisVertical']), button:has-text('Import'), button[aria-label='Icon Button']",
                    state="visible",
                    timeout=25000
                )
            except Exception:
                pass

            # Verificar si existe un botón directo de 'Import' o el menú Kebab (3 puntos verticales)
            direct_import_btn = page.locator("button:has-text('Import Catalog Items'), button:has-text('Import'), a:has-text('Import Catalog Items')")
            if direct_import_btn.count() > 0 and direct_import_btn.first.is_visible():
                print("   - Botón directo de importación encontrado. Haciendo clic...")
                direct_import_btn.first.click()
            else:
                print("   - Buscando menú de 3 puntos (Kebab)...")
                kebab_selectors = [
                    "button:has(svg[data-qa='ci-EllipsisVertical'])",
                    "button:has(svg[name='EllipsisVertical'])",
                    "button[aria-label='Icon Button']:has(svg[data-qa='ci-EllipsisVertical'])",
                    "button[aria-label='Icon Button']:has(svg[name='EllipsisVertical'])",
                    "button:has(svg.f45h)",
                    "button.StyledButton-core-12_53_1__sc-c5bhwh-3",
                    "button[aria-label='Icon Button']",
                    "svg[data-qa='ci-EllipsisVertical']",
                    "svg[name='EllipsisVertical']",
                    "button[data-qa*='Ellipsis']",
                    "button[aria-label*='More']",
                    "button[aria-label*='Actions']",
                    "button[aria-label*='Opciones']"
                ]

                kebab_clicked = False
                for k_sel in kebab_selectors:
                    try:
                        loc = page.locator(k_sel)
                        if loc.count() > 0:
                            target = loc.first
                            # Si es un SVG, preferir hacer clic en su botón padre
                            tag = target.evaluate("el => el.tagName.toLowerCase()")
                            if tag == 'svg':
                                parent_btn = target.locator("xpath=ancestor::button")
                                if parent_btn.count() > 0:
                                    target = parent_btn.first
                            print(f"   - Menú de 3 puntos encontrado con selector: {k_sel}")
                            target.scroll_into_view_if_needed()
                            target.click(force=True)
                            kebab_clicked = True
                            break
                    except Exception as ex_k:
                        print(f"   - Aviso al intentar selector {k_sel}: {ex_k}")

                if not kebab_clicked:
                    # Intento de búsqueda en iframes por si Procore carga la tabla dentro de un frame
                    for frame in page.frames:
                        if frame == page.main_frame:
                            continue
                        try:
                            for k_sel in kebab_selectors:
                                if frame.locator(k_sel).count() > 0:
                                    print(f"   - Menú de 3 puntos encontrado dentro de iframe ({frame.url}) con: {k_sel}")
                                    frame.locator(k_sel).first.click(force=True)
                                    kebab_clicked = True
                                    page = frame
                                    break
                            if kebab_clicked:
                                break
                        except Exception:
                            pass

                if not kebab_clicked:
                    raise Exception("No se encontró el menú de 3 puntos (Kebab) ni el botón de importación en Cost Catalog.")

                print("   -> Menú de 3 puntos abierto. Esperando opciones del menú desplegable...")
                page.wait_for_timeout(1000)

                # Clic en 'Import Catalog Items'
                print("   - Clic en 'Import Catalog Items'...")
                import_selectors = [
                    "a:has-text('Import Catalog Items')",
                    "button:has-text('Import Catalog Items')",
                    "[role='menuitem']:has-text('Import Catalog Items')",
                    "[role='menuitem']:has-text('Import')",
                    "text=Import Catalog Items",
                    "text=Importar elementos",
                    "[data-qa*='import']"
                ]

                clicked_import = False
                for imp_sel in import_selectors:
                    try:
                        if page.locator(imp_sel).count() > 0:
                            page.wait_for_selector(imp_sel, state="visible", timeout=10000)
                            page.locator(imp_sel).first.click()
                            clicked_import = True
                            print(f"   -> Clic en opción de importación con: {imp_sel}")
                            break
                    except Exception:
                        pass

                if not clicked_import:
                    page.wait_for_selector("a:has-text('Import Catalog Items'), button:has-text('Import Catalog Items'), text=Import Catalog Items", state="visible", timeout=15000)
                    page.locator("a:has-text('Import Catalog Items'), button:has-text('Import Catalog Items'), text=Import Catalog Items").first.click()

            # Clic en 'Import From Excel File'
            print("   - Clic en 'Import From Excel File'...")
            import_excel_selectors = [
                "a:has-text('Import From Excel File')",
                "button:has-text('Import From Excel File')",
                "[role='menuitem']:has-text('Import From Excel File')",
                "[role='menuitem']:has-text('Excel')",
                "text=Import From Excel File",
                "text=Importar desde archivo"
            ]

            clicked_excel = False
            for ie_sel in import_excel_selectors:
                try:
                    if page.locator(ie_sel).count() > 0:
                        page.wait_for_selector(ie_sel, state="visible", timeout=15000)
                        page.locator(ie_sel).first.click()
                        clicked_excel = True
                        print(f"   -> Clic en opción Excel con: {ie_sel}")
                        break
                except Exception:
                    pass

            if not clicked_excel:
                page.wait_for_selector("a:has-text('Import From Excel File'), button:has-text('Import From Excel File'), text=Import From Excel File, text=Importar desde archivo", state="visible", timeout=20000)
                page.locator("a:has-text('Import From Excel File'), button:has-text('Import From Excel File'), text=Import From Excel File, text=Importar desde archivo").first.click()

            # --- PASO 4: SUBIDA DEL ARCHIVO EXCEL ---
            log_event("UPLOADING", f"Subiendo archivo Excel: {os.path.basename(excel_path)}...")
            print("\n4. Seleccionando y entregando archivo Excel al asistente...")

            upload_btn_selectors = [
                "button:has-text('Upload Files')",
                "button:has-text('Cargar archivos')",
                "button:has-text('Upload')",
                "button[data-qa='upload-files-button']"
            ]

            upload_btn_found = None
            for u_sel in upload_btn_selectors:
                if page.locator(u_sel).count() > 0 and page.locator(u_sel).first.is_visible():
                    upload_btn_found = u_sel
                    break

            if not upload_btn_found:
                page.wait_for_selector("button:has-text('Upload Files'), button:has-text('Cargar archivos')", state="visible", timeout=30000)
                upload_btn_found = "button:has-text('Upload Files'), button:has-text('Cargar archivos')"

            print(f"   - Clic en selector de archivos ({upload_btn_found})...")
            with page.expect_file_chooser(timeout=30000) as fc_info:
                page.locator(upload_btn_found).first.click()

            file_chooser = fc_info.value
            file_chooser.set_files(excel_path)
            print("   -> Archivo Excel entregado exitosamente al formulario.")

            # --- PASO 5: ADJUNTAR Y FINALIZAR ---
            log_event("ATTACHING", "Adjuntando y procesando archivo en Procore...")
            print("\n5. Adjuntando archivo en Procore y esperando procesamiento...")

            attach_selectors = [
                "button[data-qa='qa-attach-button']",
                "button:has-text('Attach')",
                "button:has-text('Adjuntar')",
                "button[data-testid='attach-button']"
            ]

            attach_btn_found = None
            for a_sel in attach_selectors:
                if page.locator(a_sel).count() > 0 and page.locator(a_sel).first.is_visible():
                    attach_btn_found = a_sel
                    break

            if not attach_btn_found:
                page.wait_for_selector("button[data-qa='qa-attach-button'], button:has-text('Attach')", state="visible", timeout=30000)
                attach_btn_found = "button[data-qa='qa-attach-button'], button:has-text('Attach')"

            page.locator(attach_btn_found).first.click()
            print("   -> Clic en 'Attach'. Esperando validación y lectura de filas por Procore...")

            # Esperar botón Close (Procore procesa el Excel)
            close_selectors = [
                "button[label='Close']",
                "button:has-text('Close')",
                "button:has-text('Cerrar')",
                "button[data-qa='close-modal-button']"
            ]

            close_btn_found = None
            page.wait_for_selector("button[label='Close'], button:has-text('Close'), button:has-text('Cerrar')", state="visible", timeout=120000)
            
            for c_sel in close_selectors:
                if page.locator(c_sel).count() > 0 and page.locator(c_sel).first.is_visible():
                    close_btn_found = c_sel
                    break

            if close_btn_found:
                page.locator(close_btn_found).first.click()
                print("   -> Clic en 'Close'. Ventana de importación cerrada.")

            log_event("SUCCESS", "¡Archivo subido y procesado exitosamente en Procore!")
            print("\n" + "=" * 60)
            print("🎉 ¡ÉXITO TOTAL! La plantilla fue importada en Cost Catalog de Procore.")
            print("=" * 60)
            sys.stdout.flush()

            if not headless:
                time.sleep(3)
            return True

        except PlaywrightTimeoutError as e:
            log_event("ERROR", f"Tiempo de espera agotado: {e}")
            print(f"\n[ERROR DE TIEMPO DE ESPERA] No se pudo encontrar un elemento dentro del tiempo esperado.")
            print(f"  - Detalle técnico: {e}")
            print(f"  - URL al momento del fallo: {page.url}")

            # Captura de pantalla de diagnóstico automática
            diag_dir = os.path.dirname(excel_path)
            diag_path = os.path.join(diag_dir, "procore_error_screenshot.png")
            try:
                page.screenshot(path=diag_path, full_page=True)
                print(f"  - 📸 Captura de pantalla guardada para diagnóstico en: {diag_path}")
            except Exception as ex_sc:
                print(f"  - No se pudo capturar pantalla: {ex_sc}")

            if not headless:
                time.sleep(15)
            return False

        except Exception as e:
            log_event("ERROR", f"Error inesperado: {e}")
            print(f"\n[ERROR INESPERADO] Ocurrió una anomalía durante la automatización: {e}")
            print(f"  - URL al momento del fallo: {page.url}")

            diag_dir = os.path.dirname(excel_path)
            diag_path = os.path.join(diag_dir, "procore_error_screenshot.png")
            try:
                page.screenshot(path=diag_path, full_page=True)
                print(f"  - 📸 Captura de pantalla guardada en: {diag_path}")
            except Exception:
                pass

            if not headless:
                time.sleep(15)
            return False

        finally:
            print("\nFinalizando sesión de navegador...")
            try:
                browser.close()
            except Exception:
                pass

if __name__ == "__main__":
    run_headless = ("--headless" in sys.argv) or ("headless" in sys.argv)

    if "--use-db-creds" in sys.argv:
        # Modo seguro: credenciales leídas directamente desde app_config en SQLite
        from app_config import get_config
        procore_email = get_config("procore_email")
        procore_password = get_config("procore_password")

        if not procore_email or not procore_password:
            print("[ERROR CRÍTICO] No se encontraron credenciales de Procore en la base de datos.")
            print("Configura tus credenciales en el panel de Configuración.")
            sys.exit(1)

        # Buscar el archivo excel entre los argumentos que no sean opciones
        excel_candidates = [
            arg for arg in sys.argv[1:]
            if not arg.startswith("-") and arg.lower() != "headless"
        ]
        if not excel_candidates:
            print("Uso: python procore_uploader.py --use-db-creds <ruta_al_excel> [--headless]")
            sys.exit(1)
        excel_file = excel_candidates[0]
    else:
        if len(sys.argv) < 4:
            print("Uso: python procore_uploader.py --use-db-creds <ruta_al_excel> [--headless]")
            print("  o: python procore_uploader.py <email_procore> <password_procore> <ruta_al_excel> [--headless]")
            sys.exit(1)

        procore_email = sys.argv[1]
        procore_password = sys.argv[2]
    success = upload_to_procore(procore_email, procore_password, excel_file, headless=run_headless)
    if success:
        try:
            from app_config import set_config
            import re
            now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            set_config("procore_last_upload_global", now_str)
            m = re.search(r'Plantilla_([A-Za-z0-9_-]+)_Actualizada', os.path.basename(excel_file))
            if m:
                cat_name = m.group(1)
                set_config(f"procore_last_upload_{cat_name}", now_str)
        except Exception as e:
            pass
    sys.exit(0 if success else 1)