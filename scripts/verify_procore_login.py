import sys
import os
import time
import re

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
if SCRIPT_DIR not in sys.path:
    sys.path.insert(0, SCRIPT_DIR)

from playwright.sync_api import sync_playwright
try:
    from utils import setup_console, launch_best_browser
except ImportError:
    from scripts.utils import setup_console, launch_best_browser

# Configurar consola
setup_console()

def verify_procore(email, password, headless=False):
    mode_desc = "Silencioso (Headless)" if headless else "Ventana Visible"
    print(f"--- Verificando credenciales de Procore ({mode_desc}) para: {email} ---")
    
    with sync_playwright() as p:
        browser, browser_name = launch_best_browser(p, headless=headless)
        print(f"[PROCORE_VERIFY] Navegador activo: {browser_name.upper()} ({mode_desc})")
        sys.stdout.flush()
        context = browser.new_context(
            viewport={"width": 1920, "height": 1080} if headless else None,
            no_viewport=not headless,
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36"
        )
        page = context.new_page()
        page.set_default_timeout(35000)

        try:
            print("Navegando a login.procore.com...")
            page.goto("https://login.procore.com/", wait_until="domcontentloaded")
            time.sleep(1)
            
            print("Ingresando correo electrónico...")
            page.wait_for_selector("input[type='email'], input[name='email']", state="visible", timeout=15000)
            page.fill("input[type='email'], input[name='email']", email)
            
            # Clic en Continuar / Siguiente
            if page.locator("#login-btn").is_visible():
                page.click("#login-btn")
            elif page.locator("button:has-text('Continue'), button:has-text('Continuar')").is_visible():
                page.click("button:has-text('Continue'), button:has-text('Continuar')")
            elif page.locator("button[type='submit']").is_visible():
                page.click("button[type='submit']")
            
            print("Ingresando contraseña...")
            page.wait_for_selector("input[type='password']", state="visible", timeout=15000)
            page.fill("input[type='password']", password)
            
            print("Iniciando sesión...")
            if page.locator("#login-btn").is_visible():
                page.click("#login-btn")
            elif page.locator("button:has-text('Log In'), button:has-text('Iniciar sesión')").is_visible():
                page.click("button:has-text('Log In'), button:has-text('Iniciar sesión')")
            elif page.locator("button[type='submit']").is_visible():
                page.click("button[type='submit']")
            
            print("Esperando confirmación de acceso y redirección...")
            
            # Polling activo durante hasta 30 segundos para detectar éxito o error de inmediato
            start_wait = time.time()
            max_wait = 30
            is_authenticated = False
            
            while time.time() - start_wait < max_wait:
                current_url = page.url.lower()
                
                # A) Éxito: Procore Hub, App o gestión de empresas
                if "/hub" in current_url or "app.procore.com" in current_url or "/companies" in current_url:
                    print(f"  -> Acceso confirmado al entorno de Procore (Hub/App): {page.url}")
                    is_authenticated = True
                    break

                # B) Elementos clave de cuenta logueada en el DOM (solo fuera de pantalla de login)
                if "login.procore.com/sessions" not in current_url:
                    auth_selectors = [".company-card", "a[href*='/company/']", "[data-qa*='company']", "[data-qa*='user-avatar']", ".user-profile"]
                    auth_texts = ["Choose a company", "Seleccionar empresa", "Procore Hub", "Select a Company"]
                    has_auth_el = any(page.locator(sel).count() > 0 for sel in auth_selectors)
                    has_auth_txt = any(page.get_by_text(txt).count() > 0 for txt in auth_texts)

                    if (has_auth_el or has_auth_txt) and not page.locator("input[type='password']").is_visible():
                        print(f"  -> Elemento autenticado detectado en página: {page.url}")
                        is_authenticated = True
                        break

                # C) Detección de error explícito de credenciales
                error_selectors = [
                    "div[data-qa='session flash error']",
                    "div[data-qa='error message']",
                    ".flash-error",
                    ".alert-danger",
                    "div:has-text('Invalid email or password')",
                    "div:has-text('Correo electrónico o contraseña no válidos')",
                    "div:has-text('Incorrect email or password')"
                ]
                for sel in error_selectors:
                    if page.locator(sel).is_visible():
                        err_text = page.locator(sel).inner_text().strip()
                        print(f"\n[ERROR] Autenticación fallida: {err_text}")
                        return False

                time.sleep(1)

            if is_authenticated:
                print(f"[EXITO] Credenciales de Procore válidas y sesión iniciada correctamente.")
                return True
            else:
                print(f"\n[ERROR] No se pudo confirmar la sesión en Procore tras 30s. URL final: {page.url}")
                return False

        except Exception as e:
            print(f"\n[ERROR] Error durante la verificación de Procore: {str(e)}")
            print(f"      - URL Final: {page.url}")
            return False
        finally:
            browser.close()

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Uso: python verify_procore_login.py <email> <password> [--headless]")
        sys.exit(1)
    
    email = sys.argv[1]
    password = sys.argv[2]
    headless = "--headless" in sys.argv
    
    if verify_procore(email, password, headless=headless):
        sys.exit(0) # Código 0 = Éxito
    else:
        sys.exit(1) # Código 1 = Error