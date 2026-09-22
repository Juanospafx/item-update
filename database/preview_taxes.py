import sqlite3
import os
import pandas as pd
import sys
import json
import glob
import re
from datetime import datetime

# Agregar carpeta scripts al path para importar utils centralizado
sys.path.append(os.path.join(os.path.dirname(__file__), '..', 'scripts'))
from utils import setup_console, normalize_category
from app_config import get_tax_settings
from price_history import get_historical_price_data

# Configurar consola (UTF-8)
setup_console()

def preview_prices_with_tax(category, tax_percent):
    """
    Muestra una vista previa ejecutiva de los precios para una categoría o todo el catálogo ('ALL'),
    comparando precio del mes anterior y el más reciente anterior a hoy frente al precio actual de Rexel,
    calculando variación (%) y aplicando impuestos.
    """
    try:
        script_dir = os.path.dirname(os.path.abspath(__file__))
        project_root = os.path.dirname(script_dir)
        db_path = os.path.join(script_dir, 'diccionario.db')

        if not os.path.exists(db_path):
            print("<div class='alert-box alert-error'>Error: Base de datos no encontrada.</div>")
            return

        # Verificación estricta de Tax Settings de la base de datos
        db_tax, db_bypass = get_tax_settings()
        if db_bypass:
            tax_percent = 0.0

        is_all = (category.upper() == 'ALL')

        with sqlite3.connect(db_path, timeout=30.0) as conexion:
            if is_all:
                query = """
                    SELECT
                        cw.categoria AS "categoria",
                        mp.nombre_procore AS "nombre_procore",
                        cw.nombre_web AS "nombre_web",
                        cw.precio_anterior AS "precio_anterior_db",
                        cw.precio_actual AS "precio_actual",
                        cw.ultima_actualizacion
                    FROM catalogo_web cw
                    JOIN mapeo_procore mp ON cw.id = mp.web_item_id
                    WHERE cw.precio_actual > 0
                    ORDER BY cw.categoria ASC, mp.nombre_procore ASC;
                """
                df = pd.read_sql_query(query, conexion)
            else:
                query = """
                    SELECT
                        cw.categoria AS "categoria",
                        mp.nombre_procore AS "nombre_procore",
                        cw.nombre_web AS "nombre_web",
                        cw.precio_anterior AS "precio_anterior_db",
                        cw.precio_actual AS "precio_actual",
                        cw.ultima_actualizacion
                    FROM catalogo_web cw
                    JOIN mapeo_procore mp ON cw.id = mp.web_item_id
                    WHERE cw.precio_actual > 0 AND cw.categoria = ?
                    ORDER BY mp.nombre_procore ASC;
                """
                df = pd.read_sql_query(query, conexion, params=(category,))

        if df.empty:
            cat_label = "el catálogo completo" if is_all else f"la categoría <strong>{category}</strong>"
            print(f"<div class='alert-box alert-warning'>No se encontraron precios actualizados para {cat_label}.</div>")
            return

        hoy = datetime.now().strftime('%Y-%m-%d')
        tax_multiplier = 1 + (tax_percent / 100.0)

        # Cargar historial no contaminado (estrictamente anterior a hoy y del mes anterior)
        hist_data = get_historical_price_data(project_root)
        prior_map = hist_data['prior_prices']
        month_map = hist_data['month_prices']
        dates_map = hist_data['dates']

        def compute_prior_price(row):
            cat = str(row['categoria']).strip()
            name = str(row['nombre_procore']).strip()
            if (cat, name) in prior_map:
                return float(prior_map[(cat, name)])
            # Fallback seguro si no hay Excel anterior
            db_prev = float(row['precio_anterior_db'] or 0)
            db_curr = float(row['precio_actual'] or 0)
            if db_prev > 0 and db_prev != db_curr:
                return db_prev
            return db_curr

        def compute_month_price(row, prior_val):
            cat = str(row['categoria']).strip()
            name = str(row['nombre_procore']).strip()
            if (cat, name) in month_map:
                return float(month_map[(cat, name)])
            return prior_val

        df['precio_anterior'] = df.apply(compute_prior_price, axis=1).round(2)
        df['precio_mes_anterior'] = df.apply(lambda r: compute_month_price(r, r['precio_anterior']), axis=1).round(2)
        df['precio_actual'] = df['precio_actual'].astype(float).round(2)
        
        # Diferencias contra el precio anterior más reciente (< hoy)
        df['diff_usd'] = (df['precio_actual'] - df['precio_anterior']).round(2)
        df['pct_change'] = df.apply(
            lambda r: round(((r['precio_actual'] - r['precio_anterior']) / r['precio_anterior'] * 100.0), 2)
            if r['precio_anterior'] > 0 else 0.0,
            axis=1
        )
        df['precio_final'] = (df['precio_actual'] * tax_multiplier).round(2)
        df['es_hoy'] = df['ultima_actualizacion'].astype(str).str.contains(hoy)

        def classify_behavior(pct):
            if pct > 0.05:
                return 'up'
            elif pct < -0.05:
                return 'down'
            else:
                return 'same'

        df['behavior'] = df['pct_change'].apply(classify_behavior)

        total_items = len(df)
        actualizados = int(df['es_hoy'].sum())
        no_actualizados = total_items - actualizados
        total_up = int((df['behavior'] == 'up').sum())
        total_down = int((df['behavior'] == 'down').sum())
        total_same = int((df['behavior'] == 'same').sum())
        avg_pct = round(float(df['pct_change'].mean()), 2)

        cat_title = "Todas las Categorías" if is_all else category
        cat_sub = "en Catálogo Completo" if is_all else f"en {category}"

        # Chips de categoría si es modo global
        cat_chips_html = ""
        if is_all and 'categoria' in df.columns:
            unique_cats = sorted(df['categoria'].dropna().unique())
            cat_chips_html = '<div class="filter-chips-container" style="margin-top: 0.6rem; width: 100%; border-top: 1px solid var(--border-subtle); padding-top: 0.6rem; display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;">'
            cat_chips_html += '<span class="text-small text-muted" style="font-weight: 600; margin-right: 0.25rem;">Categoría:</span>'
            cat_chips_html += f'<button type="button" class="filter-chip filter-cat-chip active" onclick="filterPreviewByCategory(\'all\', this)">Todas ({total_items})</button>'
            for c in unique_cats:
                c_cnt = int((df['categoria'] == c).sum())
                cat_chips_html += f'<button type="button" class="filter-chip filter-cat-chip" onclick="filterPreviewByCategory(\'{c}\', this)">{c} ({c_cnt})</button>'
            cat_chips_html += '</div>'

        tax_badge_label = f"Tax: {tax_percent}%" if not db_bypass else "Tax: 0% (Bypass)"

        # --- ENCABEZADO Y KPIS ---
        print(f"""
        <div class="report-header-banner">
            <div>
                <span class="report-subtitle-badge">Revisión de Precios & Taxes</span>
                <h2 class="report-main-title">Validación de Catálogo: <span class="text-accent">{cat_title}</span></h2>
                <p class="report-desc-text">
                    Verifica los precios extraídos de Rexel frente a los costos anteriores (mes anterior y última extracción previa a hoy) y confirma el impuesto del <strong>{tax_percent}%</strong> antes de generar el archivo final para Procore.
                </p>
            </div>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <span class="badge-updated" style="padding: 0.5rem 1.1rem; font-size: 0.88rem; font-weight: 700;">
                    {tax_badge_label}
                </span>
            </div>
        </div>

        <div class="report-kpi-grid">
            <div class="report-kpi-card">
                <span class="report-kpi-label">Total Ítems {cat_sub}</span>
                <span class="report-kpi-val text-primary">{total_items}</span>
                <span class="report-kpi-sub">Total registrados en catálogo</span>
            </div>

            <div class="report-kpi-card" style="border-top: 3px solid var(--emerald-500);">
                <span class="report-kpi-label">Actualizados Hoy</span>
                <span class="report-kpi-val text-success">{actualizados}</span>
                <span class="report-kpi-sub">{no_actualizados} sin cambios hoy ({hoy})</span>
            </div>

            <div class="report-kpi-card" style="border-top: 3px solid {'#f87171' if avg_pct > 0 else '#10b981'};">
                <span class="report-kpi-label">Variación Promedio</span>
                <span class="report-kpi-val {'text-danger' if avg_pct > 0 else 'text-success'}">
                    {'+' if avg_pct > 0 else ''}{avg_pct}%
                </span>
                <span class="report-kpi-sub">Tendencia {'al alza 📈' if avg_pct > 0 else 'a la baja 📉'} en este grupo</span>
            </div>

            <div class="report-kpi-card" style="border-top: 3px solid #f59e0b;">
                <span class="report-kpi-label">Comportamiento</span>
                <div class="flex-center" style="justify-content: flex-start; gap: 0.5rem;">
                    <span style="color: #f59e0b; font-weight: 700; font-size: 1.1rem;">▲ {total_up}</span>
                    <span style="color: var(--emerald-500); font-weight: 700; font-size: 1.1rem; margin-left: 0.5rem;">▼ {total_down}</span>
                    <span style="color: var(--text-secondary); font-weight: 700; font-size: 1.1rem; margin-left: 0.5rem;">▬ {total_same}</span>
                </div>
                <span class="report-kpi-sub">Subieron / Bajaron / Estables</span>
            </div>
        </div>

        <!-- BARRA DE BÚSQUEDA Y FILTROS -->
        <div class="report-toolbar-card">
            <div class="report-toolbar-row">
                <div style="flex: 1; min-width: 260px;">
                    <div class="search-input-wrap">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <input type="text" id="table-search" class="input-std" oninput="applyPreviewFilters()" placeholder="Buscar por código, nombre Procore, nombre Rexel o precio en {cat_title}...">
                    </div>
                </div>

                <div class="filter-chips-container">
                    <span class="text-small text-muted" style="font-weight: 600; margin-right: 0.25rem;">Comportamiento:</span>
                    <button type="button" class="filter-chip filter-beh-chip active" onclick="filterPreviewByBehavior('all', this)">Todos ({total_items})</button>
                    <button type="button" class="filter-chip filter-beh-chip" onclick="filterPreviewByBehavior('up', this)">▲ Subieron ({total_up})</button>
                    <button type="button" class="filter-chip filter-beh-chip" onclick="filterPreviewByBehavior('down', this)">▼ Bajaron ({total_down})</button>
                    <button type="button" class="filter-chip filter-beh-chip" onclick="filterPreviewByBehavior('same', this)">▬ Sin Cambios ({total_same})</button>
                </div>
            </div>
            {cat_chips_html}
        </div>
        """)

        active_cat_key = 'ALL' if is_all else category
        prev_date_header = dates_map.get(active_cat_key, {}).get('prior_date', 'Mixto')
        month_date_header = dates_map.get(active_cat_key, {}).get('month_date', '07/02')
        curr_date_header = dates_map.get(active_cat_key, {}).get('current_date', 'Mixto')
        dates_map_json = json.dumps(dates_map)

        if is_all:
            th_headers = f"""
                        <th class="col-num" style="width: 6%; min-width: 65px; text-align: center;">Cat.</th>
                        <th class="col-name-procore" style="width: 23%; min-width: 180px;">Nombre Procore</th>
                        <th class="col-name-web" style="width: 23%; min-width: 180px;">Nombre Web (Rexel)</th>
                        <th class="col-num" style="width: 9%; min-width: 95px; text-align: right;">Mes Ant.<br><span id="th-month-date-badge" style="font-size: 0.72rem; color: #60a5fa; font-weight: 700;">({month_date_header})</span></th>
                        <th class="col-num" style="width: 9%; min-width: 95px; text-align: right;">Ant. Reciente<br><span id="th-prev-date-badge" style="font-size: 0.72rem; color: var(--accent-primary); font-weight: 700;">({prev_date_header})</span></th>
                        <th class="col-num" style="width: 9%; min-width: 90px; text-align: right;">Precio Base<br>Actual<br><span id="th-current-date-badge" style="font-size: 0.72rem; color: var(--emerald-400); font-weight: 700;">({curr_date_header})</span></th>
                        <th class="col-num" style="width: 6%; min-width: 70px; text-align: right;">Diferencia<br>($)</th>
                        <th class="col-num" style="width: 6%; min-width: 70px; text-align: center;">Variación<br>(%)</th>
                        <th class="col-num" style="width: 9%; min-width: 95px; text-align: right;">Final<br><span style="font-size: 0.72rem; color: var(--emerald-400); font-weight: 600;">(+{tax_percent}% Tax)</span></th>
                        <th class="col-status" style="width: 10%; min-width: 110px; text-align: center;">Estado</th>
            """
        else:
            th_headers = f"""
                        <th class="col-name-procore" style="width: 26%; min-width: 190px;">Nombre Procore</th>
                        <th class="col-name-web" style="width: 26%; min-width: 190px;">Nombre Web (Rexel)</th>
                        <th class="col-num" style="width: 9%; min-width: 95px; text-align: right;">Mes Ant.<br><span id="th-month-date-badge" style="font-size: 0.72rem; color: #60a5fa; font-weight: 700;">({month_date_header})</span></th>
                        <th class="col-num" style="width: 9%; min-width: 95px; text-align: right;">Ant. Reciente<br><span id="th-prev-date-badge" style="font-size: 0.72rem; color: var(--accent-primary); font-weight: 700;">({prev_date_header})</span></th>
                        <th class="col-num" style="width: 9%; min-width: 90px; text-align: right;">Precio Base<br>Actual<br><span id="th-current-date-badge" style="font-size: 0.72rem; color: var(--emerald-400); font-weight: 700;">({curr_date_header})</span></th>
                        <th class="col-num" style="width: 6%; min-width: 70px; text-align: right;">Diferencia<br>($)</th>
                        <th class="col-num" style="width: 6%; min-width: 70px; text-align: center;">Variación<br>(%)</th>
                        <th class="col-num" style="width: 9%; min-width: 95px; text-align: right;">Final<br><span style="font-size: 0.72rem; color: var(--emerald-400); font-weight: 600;">(+{tax_percent}% Tax)</span></th>
                        <th class="col-status" style="width: 10%; min-width: 110px; text-align: center;">Estado</th>
            """


        print(f"""
        <!-- TABLA DE REVISIÓN -->
        <div class="table-container-scroll" style="max-height: 550px; border-radius: 0.85rem; border: 1px solid var(--border-subtle); margin-top: 1rem; width: 100%; box-sizing: border-box; overflow-x: auto;">
            <table class="dict-table report-table" id="preview-table">
                <thead>
                    <tr>
                        {th_headers}
                    </tr>
                </thead>
                <tbody>
        """)

        for _, row in df.iterrows():
            p_month = float(row['precio_mes_anterior'])
            p_prev = float(row['precio_anterior'])
            p_curr = float(row['precio_actual'])
            diff = float(row['diff_usd'])
            pct = float(row['pct_change'])
            p_final = float(row['precio_final'])
            behavior = row['behavior']
            item_cat = row.get('categoria', '')

            if behavior == 'up':
                diff_str = f"+${diff:,.2f}"
                pct_str = f"▲ +{pct:.1f}%"
                diff_class = "text-danger font-mono"
                badge_class = "badge-price-up"
            elif behavior == 'down':
                diff_str = f"-${abs(diff):,.2f}"
                pct_str = f"▼ {pct:.1f}%"
                diff_class = "text-success font-mono"
                badge_class = "badge-price-down"
            else:
                diff_str = "$0.00"
                pct_str = "▬ 0.0%"
                diff_class = "text-muted font-mono"
                badge_class = "badge-price-same"

            status_badge = (
                '<span class="status-pill status-updated" title="Precio actualizado en la extracción de hoy">'
                '<span class="status-dot"></span>Actualizado</span>'
                if row['es_hoy'] else
                '<span class="status-pill status-neutral" title="Sin extracción en el día de hoy (mantiene precio anterior)">'
                '<span class="status-dot"></span>Sin scrap hoy</span>'
            )

            cat_td = f'<td class="col-num" style="text-align: center;"><span class="cat-count-pill" style="font-weight: 700; font-size: 0.75rem;">{item_cat}</span></td>' if is_all else ''

            print(f"""
                <tr class="preview-row" data-behavior="{behavior}" data-category="{item_cat}">
                    {cat_td}
                    <td class="col-name-procore" style="font-weight: 600; color: var(--text-primary);">{row['nombre_procore']}</td>
                    <td class="col-name-web text-secondary text-small">{row['nombre_web']}</td>
                    <td class="col-num" style="text-align: right; font-family: monospace; color: #93c5fd;">${p_month:,.2f}</td>
                    <td class="col-num" style="text-align: right; font-family: monospace; color: var(--text-secondary);">${p_prev:,.2f}</td>
                    <td class="col-num" style="text-align: right; font-family: monospace; font-weight: 700; color: var(--text-primary);">${p_curr:,.2f}</td>
                    <td class="col-num" style="text-align: right; {diff_class}">{diff_str}</td>
                    <td class="col-num" style="text-align: center;"><span class="{badge_class}">{pct_str}</span></td>
                    <td class="col-num" style="text-align: right; font-weight: 800; color: var(--emerald-400); font-family: monospace;">${p_final:,.2f}</td>
                    <td class="col-status">{status_badge}</td>
                </tr>
            """)

        print(f"""
                </tbody>
            </table>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.75rem; padding: 0 0.5rem; font-size: 0.8rem; color: var(--text-secondary);">
            <span id="preview-filter-counter">Mostrando todos los registros</span>
            <span class="text-muted font-mono">Precios contrastados vs Mes Anterior y Última Extracción previa a hoy</span>
        </div>

        <script>
        window.previewBehavior = 'all';
        window.previewCategory = 'all';
        window.previewDatesMap = {dates_map_json};
        </script>
        """)

        print("""
        <script>
        window.filterPreviewByBehavior = function(beh, btnEl) {
            window.previewBehavior = beh;
            document.querySelectorAll('.filter-beh-chip').forEach(b => b.classList.remove('active'));
            if (btnEl) btnEl.classList.add('active');
            window.applyPreviewFilters();
        };

        window.filterPreviewByCategory = function(cat, btnEl) {
            window.previewCategory = cat;
            document.querySelectorAll('.filter-cat-chip').forEach(b => b.classList.remove('active'));
            if (btnEl) btnEl.classList.add('active');

            const badge = document.getElementById('th-prev-date-badge');
            const badgeMonth = document.getElementById('th-month-date-badge');
            const badgeCurrent = document.getElementById('th-current-date-badge');
            if (window.previewDatesMap) {
                const info = (cat === 'all') ? window.previewDatesMap['ALL'] : window.previewDatesMap[cat];
                if (info) {
                    if (badge) badge.textContent = '(' + (info.prior_date || 'Mixto') + ')';
                    if (badgeMonth) badgeMonth.textContent = '(' + (info.month_date || '07/02') + ')';
                    if (badgeCurrent) badgeCurrent.textContent = '(' + (info.current_date || 'Mixto') + ')';
                }
            }

            window.applyPreviewFilters();
        };

        window.applyPreviewFilters = function() {
            const searchInput = document.getElementById('table-search');
            const term = searchInput ? searchInput.value.toUpperCase().trim() : '';
            const rows = document.querySelectorAll('.preview-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const beh = row.getAttribute('data-behavior');
                const cat = row.getAttribute('data-category');
                const text = row.innerText.toUpperCase();

                const matchesBeh = (window.previewBehavior === 'all' || beh === window.previewBehavior);
                const matchesCat = (window.previewCategory === 'all' || cat === window.previewCategory);
                const matchesText = (!term || text.indexOf(term) > -1);

                if (matchesBeh && matchesCat && matchesText) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            const counter = document.getElementById('preview-filter-counter');
            if (counter) {
                counter.textContent = 'Mostrando ' + visibleCount + ' de ' + rows.length + ' ítems';
            }
        };
        </script>
        """)

    except Exception as e:
        print(f"<div class='alert-box alert-error'>Ocurrió un error al generar la vista previa: {e}</div>")

if __name__ == '__main__':
    if len(sys.argv) != 3:
        print("Uso: python preview_taxes.py <categoria> <tax_percent>")
        sys.exit(1)
    
    cat_arg = sys.argv[1]
    if cat_arg.upper() != 'ALL':
        cat_arg = normalize_category(cat_arg)
    else:
        cat_arg = 'ALL'

    try:
        tax_arg = float(sys.argv[2])
    except ValueError:
        print("<p style='color:red'>Error: El porcentaje de tax debe ser un número.</p>")
        sys.exit(1)
        
    preview_prices_with_tax(cat_arg, tax_arg)