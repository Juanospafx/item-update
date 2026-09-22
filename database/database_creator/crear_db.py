import sqlite3
import os

def inicializar_base_de_datos():
    """
    Inicializa la base de datos 'diccionario.db' con el esquema completo,
    claves foráneas con ON DELETE CASCADE, modo WAL e índices de alto rendimiento.
    """
    script_dir = os.path.dirname(os.path.abspath(__file__))
    project_root = os.path.dirname(os.path.dirname(script_dir))
    db_path = os.path.join(os.path.dirname(script_dir), 'diccionario.db')

    conexion = sqlite3.connect(db_path, timeout=30.0)
    cursor = conexion.cursor()

    # Pragmas de rendimiento e integridad
    cursor.execute("PRAGMA journal_mode = WAL;")
    cursor.execute("PRAGMA busy_timeout = 30000;")
    cursor.execute("PRAGMA foreign_keys = ON;")

    # 1. Tabla de Catálogo Web (con histórico de precios)
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS catalogo_web (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            categoria TEXT NOT NULL, 
            nombre_web TEXT NOT NULL,
            url_origen TEXT,
            precio_actual REAL,
            ultima_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP,
            precio_anterior REAL
        );
    ''')

    # 2. Tabla de Mapeo Procore (con borrado en cascada)
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS mapeo_procore (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre_procore TEXT UNIQUE NOT NULL,
            web_item_id INTEGER,
            FOREIGN KEY (web_item_id) REFERENCES catalogo_web (id) ON DELETE CASCADE
        );
    ''')

    # 3. Tabla de URLs de Scraping
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS scraping_urls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category TEXT NOT NULL,
            url TEXT NOT NULL UNIQUE,
            description TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ''')

    # 4. Tabla de Configuración de la Aplicación
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS app_config (
            key TEXT PRIMARY KEY,
            value TEXT
        );
    ''')

    # 5. Índices de Alto Rendimiento
    indexes = [
        "CREATE INDEX IF NOT EXISTS idx_cat_web_cat_name ON catalogo_web(categoria, nombre_web);",
        "CREATE INDEX IF NOT EXISTS idx_cat_web_cat ON catalogo_web(categoria);",
        "CREATE INDEX IF NOT EXISTS idx_cat_web_precio ON catalogo_web(precio_actual);",
        "CREATE INDEX IF NOT EXISTS idx_map_proc_web_item ON mapeo_procore(web_item_id);",
        "CREATE INDEX IF NOT EXISTS idx_scrap_urls_cat_act ON scraping_urls(category, is_active);",
    ]
    for idx_sql in indexes:
        cursor.execute(idx_sql)

    conexion.commit()
    conexion.close()
    print(f"Base de datos 'diccionario.db' inicializada correctamente con esquema completo e índices en: {db_path}")

if __name__ == '__main__':
    inicializar_base_de_datos()