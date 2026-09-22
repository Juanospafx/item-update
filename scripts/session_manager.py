"""
session_manager.py
------------------
Abre el navegador del usuario (detectando si usa Brave, Chrome o Edge)
controlado por Playwright para que realice login manualmente en Rexel.
Una vez detecta que el login fue exitoso, guarda storage_state.json.

Uso: python session_manager.py [--timeout 300]
     python session_manager.py --check
"""

from playwright.sync_api import sync_playwright
import os
import sys
import argparse
import json
import time
import tempfile
import subprocess

if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8")

SCRIPT_DIR         = os.path.dirname(os.path.abspath(__file__))
STORAGE_STATE_PATH = os.path.join(SCRIPT_DIR, "storage_state.json")
PID_FILE_PATH      = os.path.join(tempfile.gettempdir(), "rexel_login_pid.txt")

REXEL_LOGIN_URL    = "https://www.rexelusa.com/login"
LOGGED_IN_DOMAIN   = "www.rexelusa.com"
AUTH_DOMAIN        = "auth.rexelusa.com"


def check_session_validity(storage_state_path: str) -> dict:
    if not os.path.exists(storage_state_path):
        return {"valid": False, "expires_at": None, "message": "No existe archivo de sesion."}

    try:
        with open(storage_state_path, "r", encoding="utf-8") as f:
            state = json.load(f)

        refresh_expiry = None
        token_expiry   = None

        for cookie in state.get("cookies", []):
            if cookie.get("name") == "auth._refresh_token_expiration.oauth" and cookie.get("expires", -1) > 0:
                refresh_expiry = cookie["expires"]
            if cookie.get("name") == "auth._token_expiration.oauth" and cookie.get("expires", -1) > 0:
                token_expiry = cookie["expires"]

        for origin in state.get("origins", []):
            for item in origin.get("localStorage", []):
                if item.get("name") == "auth._refresh_token_expiration.oauth":
                    try:
                        refresh_expiry = int(item["value"]) / 1000
                    except (ValueError, TypeError):
                        pass
                if item.get("name") == "auth._token_expiration.oauth":
                    try:
                        token_expiry = int(item["value"]) / 1000
                    except (ValueError, TypeError):
                        pass

        now            = time.time()
        primary_expiry = refresh_expiry or token_expiry

        if primary_expiry is None:
            return {"valid": False, "expires_at": None, "message": "No se encontro expiracion en la sesion."}

        if now > primary_expiry:
            return {
                "valid": False,
                "expires_at": primary_expiry,
                "message": f"La sesion expiro hace {int((now - primary_expiry) / 3600)} horas.",
            }

        # Si el access token expiró pero el refresh token sigue activo (hasta 30 días),
        # la sesión es válida y auto-renovable en background
        needs_token_refresh = (token_expiry is not None and now >= token_expiry)
        days_left  = int((primary_expiry - now) / 86400)
        hours_left = int(((primary_expiry - now) % 86400) / 3600)
        return {
            "valid":               True,
            "expires_at":          primary_expiry,
            "days_left":           days_left,
            "hours_left":          hours_left,
            "needs_token_refresh": needs_token_refresh,
            "message":             f"Sesion valida. Expira en {days_left} dias y {hours_left} horas.",
        }

    except Exception as e:
        return {"valid": False, "expires_at": None, "message": f"Error al leer sesion: {e}"}


try:
    from utils import detect_running_browser_name, get_browser_executable, launch_best_browser
except ImportError:
    from scripts.utils import detect_running_browser_name, get_browser_executable, launch_best_browser


def check_login_state(context) -> bool:
    """
    Verifica si el login en Rexel se ha completado exitosamente.
    Revisa:
    1. Cookies de autenticacion OAuth de Rexel en el contexto
    2. URLs de todas las pestañas abiertas
    3. localStorage de las pestañas en www.rexelusa.com
    """
    # 1. Comprobar cookies del contexto
    try:
        cookies = context.cookies()
        for c in cookies:
            name = c.get("name", "")
            val = str(c.get("value", ""))
            if name in ("auth._token.oauth", "auth._refresh_token_expiration.oauth", "auth._token_expiration.oauth"):
                if val and val != "false" and len(val) > 5:
                    return True
            if "sf.web" in val or "Bearer" in val:
                return True
    except Exception:
        pass

    # 2. Comprobar URLs y localStorage de todas las paginas
    try:
        pages = context.pages
        for p in pages:
            try:
                if p.is_closed():
                    continue
                url = p.url or ""
                if not url or url == "about:blank":
                    continue
                if AUTH_DOMAIN in url:
                    continue
                if LOGGED_IN_DOMAIN in url:
                    # Comprobar si hay token en localStorage
                    try:
                        has_token = p.evaluate("""() => {
                            try {
                                const t = localStorage.getItem('auth._token.oauth');
                                const r = localStorage.getItem('auth._refresh_token_expiration.oauth');
                                return !!(t || r || (document.cookie && document.cookie.indexOf('auth._token') !== -1));
                            } catch(e) { return false; }
                        }""")
                        if has_token:
                            return True
                    except Exception:
                        pass

                    # Si estamos en www.rexelusa.com y no es /login (o es callback con code)
                    if "/login" not in url or "code=" in url or "/callback" in url:
                        return True
            except Exception:
                continue
    except Exception:
        pass

    return False


def perform_manual_login(timeout_seconds: int = 300, headless: bool = False):
    """Abre el mejor navegador disponible para login manual en Rexel."""
    # Guardar PID inmediatamente para que PHP sepa que estamos vivos
    try:
        with open(PID_FILE_PATH, "w", encoding="utf-8") as f:
            f.write(str(os.getpid()))
    except Exception:
        pass

    print(f"[SESSION_MANAGER] PID: {os.getpid()}")
    print(f"[SESSION_MANAGER] Timeout: {timeout_seconds} segundos")
    print(f"[SESSION_MANAGER] Modo: {'Headless (Oculto)' if headless else 'Visible'}")
    print("[SESSION_MANAGER] Detectando navegador...")
    sys.stdout.flush()

    try:
        with sync_playwright() as p:
            try:
                browser, browser_name = launch_best_browser(p, headless=headless)
            except Exception as e:
                print(f"[SESSION_MANAGER] [ERROR] No se pudo abrir ningun navegador: {e}")
                sys.stdout.flush()
                sys.exit(1)

            context = browser.new_context(
                viewport=None,
                user_agent=(
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                    "AppleWebKit/537.36 (KHTML, like Gecko) "
                    "Chrome/127.0.0.0 Safari/537.36"
                ),
            )
            page = context.new_page()

            print("[SESSION_MANAGER] Navegando a Rexel login...")
            sys.stdout.flush()

            try:
                page.goto(REXEL_LOGIN_URL, wait_until="domcontentloaded", timeout=30000)
            except Exception as e:
                print(f"[SESSION_MANAGER] [ADVERTENCIA] Navegacion inicial: {e}")
                sys.stdout.flush()

            print("[SESSION_MANAGER] Inicia sesion en la ventana que se abrio.")
            print("[SESSION_MANAGER] El script detectara el login automaticamente y guardara la sesion.")
            sys.stdout.flush()

            start_time      = time.time()
            poll_interval   = 2
            last_url        = ""
            last_status_log = 0

            while True:
                elapsed   = time.time() - start_time
                remaining = timeout_seconds - elapsed

                if remaining <= 0:
                    print(f"[SESSION_MANAGER] [ERROR] Timeout. Ultima URL: {last_url}")
                    sys.stdout.flush()
                    try:
                        browser.close()
                    except Exception:
                        pass
                    sys.exit(1)

                # Verificar si el usuario cerro la ventana
                try:
                    open_pages = [p for p in context.pages if not p.is_closed()]
                    if len(open_pages) == 0:
                        print("[SESSION_MANAGER] Ventana cerrada por el usuario. Verificando si se guardo sesion...")
                        sys.stdout.flush()
                        if check_login_state(context):
                            context.storage_state(path=STORAGE_STATE_PATH)
                            print(f"[SESSION_MANAGER] [EXITO] Sesion guardada en: {STORAGE_STATE_PATH}")
                            sys.exit(0)
                        else:
                            print("[SESSION_MANAGER] [ERROR] La ventana fue cerrada antes de completar el login.")
                            sys.exit(1)
                except Exception:
                    pass

                # Obtener URL actual de la pagina activa
                try:
                    current_url = page.url if not page.is_closed() else ""
                except Exception:
                    current_url = ""

                if current_url and current_url != last_url:
                    print(f"[SESSION_MANAGER] URL: {current_url}")
                    sys.stdout.flush()
                    last_url = current_url

                # Verificar si ya se completo el login
                if check_login_state(context):
                    print("[SESSION_MANAGER] Login detectado exitosamente!")
                    print("[SESSION_MANAGER] Esperando 2 segundos para estabilizacion de cookies...")
                    sys.stdout.flush()
                    time.sleep(2)

                    print("[SESSION_MANAGER] Guardando estado de sesion...")
                    sys.stdout.flush()
                    try:
                        context.storage_state(path=STORAGE_STATE_PATH)
                        print(f"[SESSION_MANAGER] [EXITO] Sesion guardada en: {STORAGE_STATE_PATH}")
                    except Exception as e:
                        print(f"[SESSION_MANAGER] [ERROR] No se pudo guardar la sesion: {e}")
                        sys.stdout.flush()
                        try:
                            browser.close()
                        except Exception:
                            pass
                        sys.exit(1)

                    sys.stdout.flush()
                    try:
                        browser.close()
                    except Exception:
                        pass
                    sys.exit(0)

                if elapsed - last_status_log >= 15:
                    print(f"[SESSION_MANAGER] Esperando login... {int(remaining)}s restantes.")
                    sys.stdout.flush()
                    last_status_log = elapsed

                time.sleep(poll_interval)

    finally:
        if os.path.exists(PID_FILE_PATH):
            try:
                os.remove(PID_FILE_PATH)
            except Exception:
                pass


def import_session_data(raw_data: str) -> dict:
    """Importa y valida datos de sesion proporcionados como JSON string o archivo."""
    try:
        if os.path.exists(raw_data):
            with open(raw_data, "r", encoding="utf-8") as f:
                state = json.load(f)
        else:
            state = json.loads(raw_data)

        # Validacion minima de estructura
        if not isinstance(state, dict):
            return {"success": False, "message": "El formato debe ser un objeto JSON valido."}

        has_cookies = bool(state.get("cookies"))
        has_origins = bool(state.get("origins"))

        if not has_cookies and not has_origins:
            return {"success": False, "message": "El archivo no contiene cookies ni almacenamiento de origen (origins)."}

        with open(STORAGE_STATE_PATH, "w", encoding="utf-8") as f:
            json.dump(state, f, indent=2)

        validity = check_session_validity(STORAGE_STATE_PATH)
        return {
            "success": validity.get("valid", False),
            "message": validity.get("message", "Sesion importada."),
            "validity": validity
        }
    except Exception as e:
        return {"success": False, "message": f"Error al importar sesion: {e}"}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Gestor de sesion de Rexel")
    parser.add_argument("--timeout", type=int, default=300,
                        help="Tiempo maximo de espera en segundos (default: 300)")
    parser.add_argument("--check", action="store_true",
                        help="Solo verificar el estado de la sesion actual")
    parser.add_argument("--headless", action="store_true",
                        help="Lanzar navegador en modo headless (para servidores)")
    parser.add_argument("--import-json", type=str, default="",
                        help="Importar sesion desde un archivo JSON o string JSON")
    args = parser.parse_args()

    if args.import_json:
        res = import_session_data(args.import_json)
        print(json.dumps(res, ensure_ascii=False))
        sys.exit(0 if res.get("success") else 1)
    elif args.check:
        result = check_session_validity(STORAGE_STATE_PATH)
        print(json.dumps(result, ensure_ascii=False))
        sys.exit(0 if result["valid"] else 1)
    else:
        perform_manual_login(timeout_seconds=args.timeout, headless=args.headless)
