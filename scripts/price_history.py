import os
import glob
import re
import sqlite3
import pandas as pd
from datetime import datetime

try:
    from utils import get_eastern_now
except ImportError:
    from scripts.utils import get_eastern_now

def get_historical_price_data(project_root):
    """
    Lee los archivos Excel generados en data/excel_output para obtener:
    1. 'prior': El precio más reciente estrictamente anterior a hoy (fecha < hoy).
    2. 'month': El precio de corte del mes anterior (mes < mes_actual).
    
    Devuelve:
    {
        'prior_prices': { (cat, name): float_price },
        'month_prices': { (cat, name): float_price },
        'dates': {
            cat: { 'prior_date': 'dd/mm', 'month_date': 'dd/mm', 'prior_ymd': 'YYYYMMDD', 'month_ymd': 'YYYYMMDD' }
        },
        'global_dates': {
            'prior_date': 'dd/mm' o 'Mixto',
            'month_date': 'dd/mm' o 'Mixto'
        }
    }
    """
    output_dir = os.path.join(project_root, 'data', 'excel_output')
    now = get_eastern_now()
    today_str = now.strftime('%Y%m%d')
    current_month = now.strftime('%Y%m')

    categories = ['EMT', 'PVC', 'Wires', 'FUSES']
    prior_prices = {}
    month_prices = {}
    dates_info = {}

    for cat in categories:
        pattern = os.path.join(output_dir, f"Plantilla_{cat}_Actualizada_*.xlsx")
        files = glob.glob(pattern)
        dated_files = []
        for f in files:
            m = re.search(r'Actualizada_(\d{4})(\d{2})(\d{2})_', os.path.basename(f))
            if m:
                ymd = f"{m.group(1)}{m.group(2)}{m.group(3)}"
                fmt_date = f"{m.group(2)}/{m.group(3)}"  # Formato americano: MM/DD
                dated_files.append((ymd, f, fmt_date))
        dated_files.sort(key=lambda x: x[0], reverse=True)

        # 0. Archivo más reciente (Scrap Actual)
        if dated_files:
            c_date = dated_files[0][2]
            c_ymd = dated_files[0][0]
        else:
            # Fallback: consultar base de datos si no hay archivo Excel generado aún
            c_date = 'N/D'
            c_ymd = ''
            db_path = os.path.join(project_root, 'database', 'diccionario.db')
            if os.path.exists(db_path):
                try:
                    with sqlite3.connect(db_path, timeout=5.0) as conn:
                        cur = conn.cursor()
                        cur.execute("SELECT MAX(ultima_actualizacion) FROM catalogo_web WHERE categoria = ?", (cat,))
                        row = cur.fetchone()
                        if row and row[0]:
                            ts = str(row[0]).strip()
                            # Formatos típicos: 'YYYY-MM-DD HH:MM:SS'
                            dt_m = re.search(r'(\d{4})-(\d{2})-(\d{2})', ts)
                            if dt_m:
                                c_date = f"{dt_m.group(2)}/{dt_m.group(3)}"
                                c_ymd = f"{dt_m.group(1)}{dt_m.group(2)}{dt_m.group(3)}"
                except Exception:
                    pass

        # 1. Archivo previo < hoy
        prior_files = [x for x in dated_files if x[0] < today_str]
        if prior_files:
            try:
                df_p = pd.read_excel(prior_files[0][1], usecols=['Name', 'Unit Cost'])
                for _, row in df_p.iterrows():
                    name = str(row['Name']).strip()
                    try:
                        cost = float(row['Unit Cost'])
                        if cost > 0:
                            prior_prices[(cat, name)] = cost
                    except (ValueError, TypeError):
                        pass
                p_date = prior_files[0][2]
                p_ymd = prior_files[0][0]
            except Exception:
                p_date = 'N/D'
                p_ymd = ''
        else:
            p_date = 'N/D'
            p_ymd = ''

        # 2. Archivo mes anterior < mes actual
        month_files = [x for x in dated_files if x[0][:6] < current_month]
        if month_files:
            try:
                df_m = pd.read_excel(month_files[0][1], usecols=['Name', 'Unit Cost'])
                for _, row in df_m.iterrows():
                    name = str(row['Name']).strip()
                    try:
                        cost = float(row['Unit Cost'])
                        if cost > 0:
                            month_prices[(cat, name)] = cost
                    except (ValueError, TypeError):
                        pass
                m_date = month_files[0][2]
                m_ymd = month_files[0][0]
            except Exception:
                m_date = 'N/D'
                m_ymd = ''
        else:
            # Si no hay archivo del mes anterior, usar el archivo previo
            m_date = p_date
            m_ymd = p_ymd
            for k, v in prior_prices.items():
                if k[0] == cat and k not in month_prices:
                    month_prices[k] = v

        dates_info[cat] = {
            'prior_date': p_date,
            'month_date': m_date,
            'current_date': c_date,
            'prior_ymd': p_ymd,
            'month_ymd': m_ymd,
            'current_ymd': c_ymd
        }

    # Fechas globales combinadas
    unique_prior_dates = {v['prior_date'] for v in dates_info.values() if v['prior_date'] != 'N/D'}
    unique_month_dates = {v['month_date'] for v in dates_info.values() if v['month_date'] != 'N/D'}
    unique_current_dates = {v['current_date'] for v in dates_info.values() if v['current_date'] != 'N/D'}

    global_prior = list(unique_prior_dates)[0] if len(unique_prior_dates) == 1 else 'Mixto'
    global_month = list(unique_month_dates)[0] if len(unique_month_dates) == 1 else 'Mixto'
    global_current = list(unique_current_dates)[0] if len(unique_current_dates) == 1 else 'Mixto'

    dates_info['ALL'] = {
        'prior_date': global_prior,
        'month_date': global_month,
        'current_date': global_current,
        'prior_ymd': '',
        'month_ymd': '',
        'current_ymd': ''
    }

    return {
        'prior_prices': prior_prices,
        'month_prices': month_prices,
        'dates': dates_info,
        'global_dates': {
            'prior_date': global_prior,
            'month_date': global_month,
            'current_date': global_current
        }
    }
