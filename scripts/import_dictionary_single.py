import pandas as pd
import sqlite3
import os
import sys

# Forzar la codificación de salida a UTF-8 para evitar errores en Windows
if sys.platform == "win32":
    sys.stdout.reconfigure(encoding='utf-8')

def import_single_category(excel_path, category):
    """
    Importa un diccionario (Mapeo Procore <-> Rexel) desde un Excel para una categoría específica.
    """
    script_dir = os.path.dirname(os.path.abspath(__file__))
    project_root = os.path.dirname(script_dir)
    db_path = os.path.join(project_root, 'database', 'diccionario.db')

    if not os.path.exists(db_path):
        print(f"[ERROR] No se encontró la base de datos en {db_path}")
        sys.exit(1)

    conn = None
    try:
        conn = sqlite3.connect(db_path, timeout=30.0)
        conn.execute("PRAGMA journal_mode=WAL;")
        conn.execute("PRAGMA busy_timeout=30000;")
        conn.execute("PRAGMA foreign_keys=ON;")
        cursor = conn.cursor()

        # Leemos el Excel sin encabezados para detectar columnas por índice
        # Se asume la estructura usada anteriormente: Col A (0) = Procore, Col C (2) = Rexel
        # Si el archivo tiene solo 2 columnas, usaremos 0 y 1.
        try:
             df = pd.read_excel(excel_path, header=None)
        except Exception as e:
             print(f"[ERROR] No se pudo leer el Excel: {e}")
             sys.exit(1)

        if df.shape[1] < 2:
             print("[ERROR] El archivo Excel debe tener al menos 2 columnas (Nombre Procore, Nombre Rexel).")
             sys.exit(1)

        # --- DETECCION DINAMICA DE COLUMNAS ---
        col_procore = 0 # Default A
        col_rexel = 1   # Default B (Antes se forzaba C/2 si shape >=3, causando errores con columnas vacías)

        if len(df) > 0:
            # Intentamos detectar encabezados en la primera fila para mayor precisión
            first_row = df.iloc[0].astype(str).str.lower().tolist()
            found_p = -1
            found_r = -1
            for i, val in enumerate(first_row):
                if 'procore' in val: found_p = i
                if 'rexel' in val or 'web' in val: found_r = i
            
            if found_p != -1: col_procore = found_p
            if found_r != -1: col_rexel = found_r

        count = 0
        for index, row in df.iterrows():
            p_name = str(row[col_procore]).strip()
            r_name = str(row[col_rexel]).strip()

            # Ignorar encabezados o filas vacías/inválidas
            if not p_name or p_name.lower() in ['procore_list', 'procore name', 'nan', 'none', 'nombre procore']:
                continue
            if not r_name or r_name.lower() in ['rexel_list', 'rexel name', 'nan', 'none']:
                continue

            # 1. Insertar o recuperar el ID del ítem en catalogo_web
            # Primero verificamos si ya existe para no duplicar por gusto
            cursor.execute("SELECT id FROM catalogo_web WHERE nombre_web = ? AND categoria = ?", (r_name, category))
            res = cursor.fetchone()
            
            web_id = None
            if res:
                web_id = res[0]
            else:
                # Si no existe, lo creamos con precio 0.0
                cursor.execute('''INSERT INTO catalogo_web (categoria, nombre_web, url_origen, precio_actual) 
                                  VALUES (?, ?, '', 0.0)''', (category, r_name))
                web_id = cursor.lastrowid
            
            # 2. Actualizar o Insertar el mapeo en mapeo_procore
            # IMPORTANTE: Usamos UPDATE primero para re-mapear si el ítem de Procore ya existía
            # pero ahora apunta a un nombre de Rexel diferente.
            cursor.execute("UPDATE mapeo_procore SET web_item_id = ? WHERE nombre_procore = ?", (web_id, p_name))
            
            if cursor.rowcount == 0:
                # Si no existía, insertamos
                cursor.execute("INSERT INTO mapeo_procore (nombre_procore, web_item_id) VALUES (?, ?)", (p_name, web_id))
            
            count += 1

        import datetime
        now_str = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        cursor.execute("CREATE TABLE IF NOT EXISTS app_config (key TEXT PRIMARY KEY, value TEXT)")
        cursor.execute("INSERT OR REPLACE INTO app_config (key, value) VALUES ('last_mapping_date', ?)", (now_str,))
        cursor.execute("INSERT OR REPLACE INTO app_config (key, value) VALUES ('last_mapping_count', ?)", (str(count),))
        cursor.execute("INSERT OR REPLACE INTO app_config (key, value) VALUES ('last_mapping_category', ?)", (category,))
        conn.commit()
        print(f"[EXITO] Se procesaron {count} ítems para la categoría '{category}'.")

    except Exception as e:
        print(f"[ERROR CRITICO] {e}")
        sys.exit(1)
    finally:
        if conn:
            conn.close()

if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("Uso: python import_dictionary_single.py <excel_path> <category>")
        sys.exit(1)
    import_single_category(sys.argv[1], sys.argv[2])