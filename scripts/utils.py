import sys
import re
import unicodedata

from datetime import datetime
from zoneinfo import ZoneInfo

# Zona horaria operativa fija: Orlando, Florida (Eastern Time: EST/EDT)
APP_TIMEZONE = "America/New_York"

def get_eastern_now():
    """Devuelve la fecha y hora actual en la zona horaria de Orlando, Florida (America/New_York)."""
    try:
        return datetime.now(ZoneInfo(APP_TIMEZONE))
    except Exception:
        return datetime.now()

def get_eastern_now_str(fmt='%Y-%m-%d %H:%M:%S'):
    """Devuelve el string formateado de fecha y hora actual en America/New_York."""
    return get_eastern_now().strftime(fmt)

# Centralización de la configuración de salida para Windows para evitar errores de caracteres
def setup_console():
    if sys.platform == "win32":
        sys.stdout.reconfigure(encoding='utf-8', line_buffering=True)
    else:
        sys.stdout.reconfigure(line_buffering=True)

# Lógica única de normalización de categorías para consistencia con la DB
def normalize_category(category):
    if category.lower() == 'wires':
        return 'Wires'
    return category.upper()

# Helpers de impresión HTML para el Monitor Web (CSS compatible)
def print_header(msg):
    print(f"\n<div class='log-header'>--- {msg} ---</div>", flush=True)

def print_sub_header(msg):
    print(f"\n<div class='log-sub-header'>{msg}</div>", flush=True)

def print_success(msg):
    print(f"<div class='log-success'>{msg}</div>", flush=True)

def print_warning(msg):
    print(f"<div class='log-warning'>{msg}</div>", flush=True)

def print_error(msg):
    print(f"<div class='log-error'>{msg}</div>", flush=True)

def print_info(msg):
    print(f"<div class='log-info'>{msg}</div>", flush=True)

def clean_price(price_str):
    """Limpia strings de precio usando regex para dejar solo números y puntos."""
    return re.sub(r'[^\d.]', '', price_str)

def normalize_text(text):
    """Normaliza caracteres unicode (acentos, símbolos) y colapsa espacios múltiples."""
    text = unicodedata.normalize("NFKD", text).strip()
    return re.sub(r'\s+', ' ', text)

# ==============================================================================
# MOTOR UNIFICADO DE NAVEGADORES (Brave / Chrome / Edge / Chromium)
# Optimizado para servidores, VPS y estaciones de trabajo locales
# ==============================================================================
import os
import subprocess
import shutil

def detect_running_browser_name() -> str | None:
    """Detecta qué navegador está corriendo en el sistema en este momento (Brave, Chrome, Edge)."""
    try:
        if sys.platform == "win32":
            out = subprocess.check_output(["tasklist"], text=True, errors="ignore").lower()
            if "brave.exe" in out:
                return "brave"
            if "chrome.exe" in out:
                return "chrome"
            if "msedge.exe" in out:
                return "msedge"
        elif sys.platform.startswith("linux"):
            for b in ["brave-browser", "brave", "google-chrome", "google-chrome-stable", "chromium", "chromium-browser", "microsoft-edge"]:
                if shutil.which(b):
                    return b
    except Exception:
        pass
    return None

def get_browser_executable(name: str) -> str | None:
    """Busca la ruta del ejecutable para un navegador específico en Windows o Linux."""
    if sys.platform == "win32":
        local_app_data = os.environ.get("LOCALAPPDATA", "")
        prog_files = os.environ.get("PROGRAMFILES", r"C:\Program Files")
        prog_files_x86 = os.environ.get("ProgramFiles(x86)", r"C:\Program Files (x86)")

        paths_map = {
            "brave": [
                os.path.join(prog_files, r"BraveSoftware\Brave-Browser\Application\brave.exe"),
                os.path.join(prog_files_x86, r"BraveSoftware\Brave-Browser\Application\brave.exe"),
                os.path.join(local_app_data, r"BraveSoftware\Brave-Browser\Application\brave.exe"),
            ],
            "chrome": [
                os.path.join(prog_files, r"Google\Chrome\Application\chrome.exe"),
                os.path.join(prog_files_x86, r"Google\Chrome\Application\chrome.exe"),
                os.path.join(local_app_data, r"Google\Chrome\Application\chrome.exe"),
            ],
            "msedge": [
                os.path.join(prog_files, r"Microsoft\Edge\Application\msedge.exe"),
                os.path.join(prog_files_x86, r"Microsoft\Edge\Application\msedge.exe"),
            ],
        }

        candidates = paths_map.get(name, [])
        for p in candidates:
            if p and os.path.exists(p):
                return p
    elif sys.platform.startswith("linux"):
        cmd_map = {
            "brave": ["brave-browser", "brave"],
            "chrome": ["google-chrome", "google-chrome-stable", "chromium", "chromium-browser"],
            "msedge": ["microsoft-edge", "microsoft-edge-stable"]
        }
        for cmd in cmd_map.get(name, [name]):
            path = shutil.which(cmd)
            if path:
                return path

    return None

def launch_best_browser(playwright, headless: bool = False, extra_args: list = None):
    """
    Motor centralizado y uniforme para lanzar navegadores en todo el sistema.
    Detecta automáticamente el navegador del usuario (Brave, Chrome, Edge) y 
    aplica flags de optimización para bajo consumo de memoria y CPU en servidores.
    """
    # En Linux sin entorno gráfico (X11 / DISPLAY), forzar automáticamente modo headless
    if sys.platform.startswith("linux") and not os.environ.get("DISPLAY"):
        headless = True
        print("[BROWSER_ENGINE] Entorno de servidor Linux sin DISPLAY detectado. Forzando modo Headless.")
        sys.stdout.flush()

    running = detect_running_browser_name()
    if running:
        print(f"[BROWSER_ENGINE] Navegador prioritario detectado: {running.upper()}")
        sys.stdout.flush()

    preferred_order = []
    if running:
        preferred_order.append(running)
    for b in ["brave", "chrome", "msedge"]:
        if b not in preferred_order:
            preferred_order.append(b)

    # Flags de alto rendimiento y bajo consumo (Vitales para servidores VPS y estabilidad)
    args = [
        "--disable-blink-features=AutomationControlled",
        "--no-default-browser-check",
        "--no-sandbox",
        "--disable-dev-shm-usage",        # Evita colapso de memoria compartida /dev/shm en Linux
        "--disable-gpu",                  # Reduce consumo de RAM y CPU en headless
        "--disable-software-rasterizer",
        "--disable-extensions",
        "--mute-audio",
        "--disable-background-networking"
    ]
    if not headless:
        args.append("--start-maximized")
    if extra_args:
        args.extend(extra_args)

    for b_name in preferred_order:
        exe = get_browser_executable(b_name)
        if exe:
            try:
                browser = playwright.chromium.launch(
                    headless=headless,
                    executable_path=exe,
                    args=args,
                )
                print(f"[BROWSER_ENGINE] Lanzando {b_name.upper()} ({'Headless' if headless else 'Visible'}) desde: {exe}")
                sys.stdout.flush()
                return browser, b_name
            except Exception as e:
                print(f"[BROWSER_ENGINE] No se pudo lanzar {b_name} ({e}), probando siguiente opción...")
                sys.stdout.flush()

        if b_name in ("msedge", "chrome"):
            try:
                browser = playwright.chromium.launch(
                    headless=headless,
                    channel=b_name,
                    args=args,
                )
                print(f"[BROWSER_ENGINE] Lanzando {b_name.upper()} vía canal de Playwright.")
                sys.stdout.flush()
                return browser, b_name
            except Exception:
                pass

    # Fallback seguro al Chromium bundled
    print(f"[BROWSER_ENGINE] Usando Chromium bundled de Playwright ({'Headless' if headless else 'Visible'}).")
    sys.stdout.flush()
    browser = playwright.chromium.launch(headless=headless, args=args)
    return browser, "chromium"