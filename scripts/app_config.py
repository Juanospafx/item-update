import sqlite3
import os

# Definir la ruta a la base de datos (subiendo un nivel desde scripts/ y entrando a database/)
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB_PATH = os.path.join(BASE_DIR, 'database', 'diccionario.db')

def get_config(key, default_value=None):
    """Obtiene un valor de configuración de la tabla app_config"""
    try:
        if not os.path.exists(DB_PATH):
            return default_value
            
        conn = sqlite3.connect(DB_PATH, timeout=30.0)
        conn.execute("PRAGMA busy_timeout=30000;")
        conn.row_factory = sqlite3.Row
        cursor = conn.cursor()
        
        # Asegurar que la tabla existe (por si acaso)
        cursor.execute("CREATE TABLE IF NOT EXISTS app_config (key TEXT PRIMARY KEY, value TEXT)")
        
        cursor.execute("SELECT value FROM app_config WHERE key = ?", (key,))
        row = cursor.fetchone()
        conn.close()
        
        return row['value'] if row else default_value
    except Exception:
        return default_value

def get_tax_settings():
    """
    Devuelve una tupla (tax_rate, bypass_enabled).
    """
    rate_str = get_config("tax_rate", "6.5")
    bypass_str = get_config("tax_bypass", "0")
    
    try:
        tax_rate = float(rate_str)
    except:
        tax_rate = 6.5
        
    bypass_enabled = (bypass_str == "1" or str(bypass_str).lower() == "true")
    
    return tax_rate, bypass_enabled