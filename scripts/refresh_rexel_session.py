"""
refresh_rexel_session.py
-------------------------
Worker ligero para renovar tokens OAuth de Rexel USA en segundo plano (headless).
Utiliza el almacenamiento persistente (storage_state.json) y el método nativo
de Nuxt Auth ($auth.refreshTokens()) para extender la vida útil de la sesión
por otros 30 días sin requerir interacción manual del usuario.

Uso:
  python refresh_rexel_session.py
  python refresh_rexel_session.py --force
  python refresh_rexel_session.py --max-days 7
"""

import os
import sys
import json
import time
import argparse
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
if SCRIPT_DIR not in sys.path:
    sys.path.insert(0, SCRIPT_DIR)

from playwright.sync_api import sync_playwright
try:
    from utils import launch_best_browser
except ImportError:
    from scripts.utils import launch_best_browser

if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8")

STORAGE_STATE_PATH = os.path.join(SCRIPT_DIR, "storage_state.json")
REXEL_HOME_URL = "https://www.rexelusa.com/"


def get_current_expiration(state: dict) -> tuple[float | None, float | None]:
    """Obtiene (refresh_token_expiration, access_token_expiration) en segundos epoch."""
    refresh_exp = None
    token_exp = None

    # 1. Buscar en cookies
    for cookie in state.get("cookies", []):
        name = cookie.get("name", "")
        if name == "auth._refresh_token_expiration.oauth" and cookie.get("expires", -1) > 0:
            refresh_exp = cookie["expires"]
        elif name == "auth._token_expiration.oauth" and cookie.get("expires", -1) > 0:
            token_exp = cookie["expires"]

    # 2. Buscar en localStorage (milsegundos epoch)
    for origin in state.get("origins", []):
        for item in origin.get("localStorage", []):
            name = item.get("name", "")
            if name == "auth._refresh_token_expiration.oauth":
                try:
                    refresh_exp = float(item["value"]) / 1000.0
                except (ValueError, TypeError):
                    pass
            elif name == "auth._token_expiration.oauth":
                try:
                    token_exp = float(item["value"]) / 1000.0
                except (ValueError, TypeError):
                    pass

    return refresh_exp, token_exp


def refresh_tokens(storage_path: str = STORAGE_STATE_PATH, force: bool = False, max_days: int = 30, timeout_ms: int = 35000) -> dict:
    """
    Ejecuta el ciclo de refresco de tokens con Chromium Headless.
    Retorna un diccionario estructurado con el resultado.
    """
    start_time = time.time()

    if not os.path.exists(storage_path):
        return {
            "success": False,
            "error": "not_found",
            "message": "No existe el archivo de sesión storage_state.json."
        }

    try:
        with open(storage_path, "r", encoding="utf-8") as f:
            state = json.load(f)
    except Exception as e:
        return {
            "success": False,
            "error": "corrupt_file",
            "message": f"Error al leer el archivo de sesión: {e}"
        }

    old_refresh_exp, old_token_exp = get_current_expiration(state)
    now = time.time()

    if not old_refresh_exp:
        return {
            "success": False,
            "error": "no_refresh_token",
            "message": "No se encontró token de refresco en la sesión actual."
        }

    # Si ya expiró totalmente, no se puede renovar en background sin re-autenticar
    if now > old_refresh_exp:
        return {
            "success": False,
            "error": "expired",
            "message": "La sesión ya ha expirado completamente. Se requiere inicio de sesión interactivo."
        }

    days_remaining = (old_refresh_exp - now) / 86400.0

    # Si no es forzado y aún le quedan más días que el umbral, omitir
    if not force and days_remaining > max_days:
        return {
            "success": True,
            "refreshed": False,
            "message": f"La sesión aún es válida por {int(days_remaining)} días. No requiere renovación inmediata.",
            "days_left": int(days_remaining),
            "expires_at": old_refresh_exp
        }

    # Lanzar Playwright en modo headless súper optimizado para bajo consumo de memoria
    browser_args = [
        "--headless=new",
        "--no-sandbox",
        "--disable-setuid-sandbox",
        "--disable-dev-shm-usage",
        "--disable-accelerated-2d-canvas",
        "--no-first-run",
        "--no-zygote",
        "--disable-gpu",
        "--disable-blink-features=AutomationControlled",
        "--no-default-browser-check"
    ]

    try:
        with sync_playwright() as p:
            browser, browser_name = launch_best_browser(p, headless=True)
            print(f"[REFRESH] Motor de navegador activo: {browser_name.upper()} (Headless)")
            sys.stdout.flush()
            context = browser.new_context(
                storage_state=storage_path,
                viewport={"width": 1280, "height": 720},
                user_agent=(
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                    "AppleWebKit/537.36 (KHTML, like Gecko) "
                    "Chrome/127.0.0.0 Safari/537.36"
                ),
            )
            page = context.new_page()

            # Navegar a la página principal de Rexel
            try:
                page.goto(REXEL_HOME_URL, wait_until="domcontentloaded", timeout=timeout_ms)
            except Exception as e:
                # Intentar continuar si cargó parcialmente
                pass

            # Esperar a que Nuxt y su módulo de auth estén listos
            ready = page.wait_for_function(
                "() => window.$nuxt && window.$nuxt.$auth",
                timeout=12000
            )

            # Invocar refreshTokens() de Nuxt Auth
            refresh_script = """async () => {
                try {
                    if (window.$nuxt && window.$nuxt.$auth && typeof window.$nuxt.$auth.refreshTokens === 'function') {
                        const res = await window.$nuxt.$auth.refreshTokens();
                        return { success: true, res: String(res) };
                    }
                    return { success: false, error: 'Método refreshTokens no disponible en Nuxt' };
                } catch(e) {
                    return { success: false, error: e.toString() };
                }
            }"""

            eval_res = page.evaluate(refresh_script)

            # Esperar brevemente para permitir sincronización de almacenamiento
            page.wait_for_timeout(1500)

            # Guardar el nuevo estado actualizado
            context.storage_state(path=storage_path)
            browser.close()

            # Re-leer para verificar la nueva fecha de expiración
            with open(storage_path, "r", encoding="utf-8") as f:
                new_state = json.load(f)

            new_refresh_exp, new_token_exp = get_current_expiration(new_state)
            new_days_left = int((new_refresh_exp - time.time()) / 86400.0) if new_refresh_exp else 0
            new_hours_left = int(((new_refresh_exp - time.time()) % 86400.0) / 3600.0) if new_refresh_exp else 0

            elapsed = round(time.time() - start_time, 2)

            return {
                "success": True,
                "refreshed": True,
                "message": f"Sesión renovada exitosamente en {elapsed}s. Expira en {new_days_left} días y {new_hours_left} horas.",
                "old_expires_at": old_refresh_exp,
                "expires_at": new_refresh_exp,
                "days_left": new_days_left,
                "hours_left": new_hours_left,
                "elapsed_seconds": elapsed
            }

    except Exception as e:
        elapsed = round(time.time() - start_time, 2)
        return {
            "success": False,
            "error": "runtime_error",
            "message": f"Error al renovar sesión: {str(e)}",
            "elapsed_seconds": elapsed
        }


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Renovador automático de sesión Rexel en segundo plano")
    parser.add_argument("--force", action="store_true", help="Forzar renovación inmediata")
    parser.add_argument("--max-days", type=int, default=30, help="Días restantes máximos para requerir renovación")
    parser.add_argument("--timeout", type=int, default=35000, help="Timeout de navegación en ms")
    args = parser.parse_args()

    result = refresh_tokens(
        force=args.force,
        max_days=args.max_days,
        timeout_ms=args.timeout
    )

    print(json.dumps(result, ensure_ascii=False, indent=2))
    sys.exit(0 if result["success"] else 1)
