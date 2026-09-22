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
from utils import setup_console
from app_config import get_tax_settings
from price_history import get_historical_price_data

# Configurar consola (UTF-8)
setup_console()

def view_all_prices(tax_percent, mode="view", csrf_token=""):
    """
    Genera un Reporte Ejecutivo de Comportamiento y Fluctuación de Precios.
    Compara el precio del mes anterior y el más reciente anterior a hoy frente al precio actual
    actualizado por el scraper de Rexel, calculando variaciones (%), diferencias en dólares
    y métricas por categoría y mercado.
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

        with sqlite3.connect(db_path, timeout=30.0) as conexion:
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

        if df.empty:
            print("<div class='alert-box alert-warning'>No se encontraron precios cargados en la base de datos.</div>")
            return

        # --- CÁLCULOS DE COMPORTAMIENTO Y FLUCTUACIÓN HISTÓRICA ---
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
        
        # Diferencia y variación vs Anterior Reciente (< hoy)
        df['diff_usd'] = (df['precio_actual'] - df['precio_anterior']).round(2)
        df['pct_change'] = df.apply(
            lambda r: round(((r['precio_actual'] - r['precio_anterior']) / r['precio_anterior'] * 100.0), 2)
            if r['precio_anterior'] > 0 else 0.0,
            axis=1
        )

        # Diferencia y variación vs Mes Anterior (< mes actual)
        df['diff_month_usd'] = (df['precio_actual'] - df['precio_mes_anterior']).round(2)
        df['pct_month_change'] = df.apply(
            lambda r: round(((r['precio_actual'] - r['precio_mes_anterior']) / r['precio_mes_anterior'] * 100.0), 2)
            if r['precio_mes_anterior'] > 0 else 0.0,
            axis=1
        )
        
        # Precio final con impuesto aplicado
        df['precio_final'] = (df['precio_actual'] * tax_multiplier).round(2)

        # Clasificación de comportamiento vs Reciente
        def classify_behavior(pct):
            if pct > 0.05:
                return 'up'
            elif pct < -0.05:
                return 'down'
            else:
                return 'same'

        df['behavior'] = df['pct_change'].apply(classify_behavior)
        
        hoy = datetime.now().strftime('%Y-%m-%d')
        df['es_hoy'] = df['ultima_actualizacion'].astype(str).str.contains(hoy)

        # --- MODO EXPORTAR EXCEL ---
        if mode == "export":
            output_df = pd.DataFrame({
                'Categoría': df['categoria'],
                'Nombre Procore': df['nombre_procore'],
                'Nombre Web (Rexel)': df['nombre_web'],
                'Precio Mes Anterior ($)': df['precio_mes_anterior'],
                'Precio Anterior Reciente ($)': df['precio_anterior'],
                'Precio Actual Base ($)': df['precio_actual'],
                'Diferencia vs Reciente ($)': df['diff_usd'],
                'Variación vs Reciente (%)': df['pct_change'].apply(lambda v: f"{'+' if v > 0 else ''}{v}%"),
                'Diferencia vs Mes Anterior ($)': df['diff_month_usd'],
                'Variación vs Mes Anterior (%)': df['pct_month_change'].apply(lambda v: f"{'+' if v > 0 else ''}{v}%"),
                f'Precio Final (+{tax_percent}% Tax)': df['precio_final'],
                'Comportamiento': df['behavior'].map({'up': 'Subió', 'down': 'Bajó', 'same': 'Sin Cambio'}),
                'Última Actualización': df['ultima_actualizacion']
            })
            
            output_dir = os.path.join(os.path.dirname(script_dir), 'data', 'excel_output')
            os.makedirs(output_dir, exist_ok=True)
            timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
            filename = f"Reporte_Comportamiento_Precios_{timestamp}.xlsx"
            path = os.path.join(output_dir, filename)
            output_df.to_excel(path, index=False)
            print(f"[EXITO] Archivo actualizado guardado en: {path}")
            return

        # --- MÉTRICAS GLOBALES ---
        total_items = len(df)
        total_up = int((df['behavior'] == 'up').sum())
        total_down = int((df['behavior'] == 'down').sum())
        total_same = int((df['behavior'] == 'same').sum())
        avg_global_pct = round(float(df['pct_change'].mean()), 2)
        total_actualizados_hoy = int(df['es_hoy'].sum())

        pct_up_share = round((total_up / total_items) * 100, 1) if total_items > 0 else 0
        pct_down_share = round((total_down / total_items) * 100, 1) if total_items > 0 else 0

        # Métricas vs Mes Anterior
        avg_month_pct = round(float(df['pct_month_change'].mean()), 2)
        total_up_month = int((df['pct_month_change'] > 0.05).sum())
        total_down_month = int((df['pct_month_change'] < -0.05).sum())
        pct_up_month_share = round((total_up_month / total_items) * 100, 1) if total_items > 0 else 0
        pct_down_month_share = round((total_down_month / total_items) * 100, 1) if total_items > 0 else 0

        # --- RESUMEN POR CATEGORÍA (GRUPOS) ---
        cat_groups = df.groupby('categoria')
        cat_stats = []
        for cat_name, group in cat_groups:
            c_total = len(group)
            c_up = int((group['behavior'] == 'up').sum())
            c_down = int((group['behavior'] == 'down').sum())
            c_same = int((group['behavior'] == 'same').sum())
            c_avg_pct = round(float(group['pct_change'].mean()), 2)
            c_avg_diff = round(float(group['diff_usd'].mean()), 2)
            cat_stats.append({
                'name': cat_name,
                'total': c_total,
                'up': c_up,
                'down': c_down,
                'same': c_same,
                'avg_pct': c_avg_pct,
                'avg_diff': c_avg_diff
            })

        # --- RENDERIZADO HTML ---
        export_btn_html = ""
        if csrf_token:
            export_btn_html = f"""
            <form method="POST" action="ejecutar.php" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="{csrf_token}">
                <input type="hidden" name="script" value="export_prices">
                <button type="submit" class="btn-primary" style="background-color: var(--emerald-500); border-color: var(--emerald-500); padding: 0.75rem 1.4rem; font-size: 0.88rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 700; white-space: nowrap;">
                    <span>⬇</span> Exportar Reporte a Excel
                </button>
            </form>
            """

        tax_desc = f"Tax global configurado: <strong class='text-accent'>{tax_percent}%</strong>." if not db_bypass else "Tax global: <strong class='text-accent'>0% (Modo Bypass Activo)</strong>."

        print(f"""
        <div class="report-header-banner">
            <div>
                <span class="report-subtitle-badge">Comportamiento & Fluctuación de Mercado</span>
                <h2 class="report-main-title">Reporte e Información de Precios</h2>
                <p class="report-desc-text">
                    Comparativa detallada entre precios anteriores (mes anterior y última extracción previa a hoy) y precios actualizados de Rexel.
                    {tax_desc}
                </p>
            </div>
            <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                <button type="button" class="btn-secondary" onclick="openModal('modal-price-analysis-guide')" style="padding: 0.75rem 1.25rem; font-size: 0.88rem; display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 700; white-space: nowrap; border-radius: 0.75rem; cursor: pointer; border: 1px solid var(--border-subtle); background: var(--bg-card); color: var(--text-primary); transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.15);">
                    <span style="font-size: 1.15rem;">💡</span> Guía de Análisis
                </button>
                {export_btn_html}
            </div>
        </div>

        <!-- 1. KPI CARDS GLOBALES (Sincronizadas con Modo de Comparación) -->
        <div class="report-kpi-grid">
            <div class="report-kpi-card">
                <span class="report-kpi-label">Total Ítems en Catálogo</span>
                <span class="report-kpi-val text-primary">{total_items}</span>
                <span class="report-kpi-sub"><strong class="text-success">{total_actualizados_hoy}</strong> actualizados hoy</span>
            </div>

            <div class="report-kpi-card" id="kpi-card-variation" style="border-top: 3px solid {'#f87171' if avg_global_pct > 0 else '#10b981'};">
                <span class="report-kpi-label" id="kpi-label-variation">Variación Promedio Global</span>
                <span class="report-kpi-val {'text-danger' if avg_global_pct > 0 else 'text-success'}" id="kpi-val-variation">
                    {'+' if avg_global_pct > 0 else ''}{avg_global_pct}%
                </span>
                <span class="report-kpi-sub" id="kpi-sub-variation">vs Último Scrap | <span style="color: #60a5fa; font-weight: 600;">Mes: {'+' if avg_month_pct > 0 else ''}{avg_month_pct}%</span></span>
            </div>

            <div class="report-kpi-card" id="kpi-card-up" style="border-top: 3px solid #f59e0b;">
                <span class="report-kpi-label" id="kpi-label-up">Precios al Alza</span>
                <div class="flex-center" style="justify-content: flex-start; gap: 0.5rem;">
                    <span class="report-kpi-val" id="kpi-val-up" style="color: #f59e0b;">▲ {total_up}</span>
                    <span class="badge-price-up text-small" id="kpi-share-up">{pct_up_share}% del total</span>
                </div>
                <span class="report-kpi-sub" id="kpi-sub-up">Ítems con incremento de precio</span>
            </div>

            <div class="report-kpi-card" id="kpi-card-down" style="border-top: 3px solid var(--emerald-500);">
                <span class="report-kpi-label" id="kpi-label-down">Precios a la Baja / Ahorro</span>
                <div class="flex-center" style="justify-content: flex-start; gap: 0.5rem;">
                    <span class="report-kpi-val text-success" id="kpi-val-down">▼ {total_down}</span>
                    <span class="badge-price-down text-small" id="kpi-share-down">{pct_down_share}% del total</span>
                </div>
                <span class="report-kpi-sub" id="kpi-sub-down">Ítems con precio menor o en oferta</span>
            </div>
        </div>


        <!-- 2. RESUMEN POR CATEGORÍAS (GRUPOS) -->
        <div class="report-section-heading">
            <h3 style="margin: 0; color: var(--text-primary); font-size: 1.15rem; font-weight: 700;">Comportamiento por Grupo / Categoría</h3>
            <span class="text-small text-muted">Haz clic en cualquier categoría para filtrar la tabla automáticamente</span>
        </div>

        <div class="cat-summary-grid">
        """)

        for cat in cat_stats:
            trend_icon = "▲" if cat['avg_pct'] > 0 else ("▼" if cat['avg_pct'] < 0 else "▬")
            trend_color = "var(--amber-500)" if cat['avg_pct'] > 0 else ("var(--emerald-500)" if cat['avg_pct'] < 0 else "var(--text-secondary)")
            
            print(f"""
            <div class="cat-summary-card" onclick="filterByCategory('{cat['name']}')" id="cat-card-{cat['name']}">
                <div class="cat-summary-header">
                    <span class="cat-summary-title">{cat['name']}</span>
                    <span class="cat-summary-pct" style="color: {trend_color};">
                        {trend_icon} {'+' if cat['avg_pct'] > 0 else ''}{cat['avg_pct']}%
                    </span>
                </div>
                <div class="cat-summary-metrics">
                    <div>
                        <span class="text-small text-muted">Ítems</span>
                        <div style="font-weight: 700; color: var(--text-primary);">{cat['total']}</div>
                    </div>
                    <div>
                        <span class="text-small text-muted">Subieron</span>
                        <div style="font-weight: 700; color: #f59e0b;">▲ {cat['up']}</div>
                    </div>
                    <div>
                        <span class="text-small text-muted">Bajaron</span>
                        <div style="font-weight: 700; color: var(--emerald-500);">▼ {cat['down']}</div>
                    </div>
                    <div>
                        <span class="text-small text-muted">Igual</span>
                        <div style="font-weight: 700; color: var(--text-secondary);">▬ {cat['same']}</div>
                    </div>
                </div>
            </div>
            """)

        print(f"""
        </div>

        <!-- 3. BARRA DE HERRAMIENTAS Y FILTROS INTERACTIVOS -->
        <div class="report-toolbar-card">
            <div class="report-toolbar-row">
                <div style="flex: 1; min-width: 260px;">
                    <div class="search-input-wrap">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <input type="text" id="table-search" class="input-std" oninput="applyReportFilters()" placeholder="Buscar por código, nombre Procore, nombre Rexel o precio...">
                    </div>
                </div>

                <div class="filter-chips-container">
                    <span class="text-small text-muted" style="font-weight: 600; margin-right: 0.25rem;">Estado:</span>
                    <button type="button" class="filter-chip filter-beh-chip active" onclick="filterByBehavior('all', this)">Todos ({total_items})</button>
                    <button type="button" class="filter-chip filter-beh-chip" onclick="filterByBehavior('up', this)">▲ Subieron ({total_up})</button>
                    <button type="button" class="filter-chip filter-beh-chip" onclick="filterByBehavior('down', this)">▼ Bajaron ({total_down})</button>
                    <button type="button" class="filter-chip filter-beh-chip" onclick="filterByBehavior('same', this)">▬ Sin Cambios ({total_same})</button>
                </div>
            </div>

            <!-- Pestaña de Vista Comparativa: Mes Anterior vs Anterior Reciente -->
            <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--border-subtle); display: flex; gap: 0.45rem; flex-wrap: wrap; align-items: center;">
                <span class="text-small text-muted" style="font-weight: 600; margin-right: 0.25rem;">Comparación:</span>
                <button type="button" class="filter-chip filter-view-mode-chip active" id="btn-mode-all" onclick="setViewMode('all', this)">📊 Vista Completa (Ambos Precios)</button>
                <button type="button" class="filter-chip filter-view-mode-chip" id="btn-mode-recent" onclick="setViewMode('recent', this)">⏱️ vs Último Anterior (<span id="btn-recent-date-label">Mixto</span>)</button>
                <button type="button" class="filter-chip filter-view-mode-chip" id="btn-mode-month" onclick="setViewMode('month', this)">📅 vs Mes Anterior (<span id="btn-month-date-label">07/02</span>)</button>
            </div>

            <div class="category-tabs-bar" style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--border-subtle); display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                <span class="text-small text-muted" style="font-weight: 600; margin-right: 0.25rem;">Categoría:</span>
                <button type="button" class="cat-tab-btn active" id="tab-btn-all" onclick="filterByCategory('all', this)">Todas</button>
        """)

        for cat in cat_stats:
            print(f"""<button type="button" class="cat-tab-btn" id="tab-btn-{cat['name']}" onclick="filterByCategory('{cat['name']}', this)">{cat['name']} ({cat['total']})</button>""")

        print("""
            </div>
        </div>
        """)

        prev_date_header = dates_map.get('ALL', {}).get('prior_date', 'Mixto')
        month_date_header = dates_map.get('ALL', {}).get('month_date', '07/02')
        curr_date_header = dates_map.get('ALL', {}).get('current_date', 'Mixto')
        prev_dates_json = json.dumps(dates_map)

        # 4. TABLA DE COMPORTAMIENTO DETALLADO
        print(f"""
        <div class="table-container-scroll" style="max-height: 580px; border-radius: 0.85rem; border: 1px solid var(--border-subtle); width: 100%; box-sizing: border-box; overflow-x: auto;">
            <table class="dict-table report-table" id="report-table">
                <thead>
                    <tr>
                        <th class="col-num" style="width: 5%; min-width: 60px; text-align: center;">Cat.</th>
                        <th class="col-name-procore" style="width: 23%; min-width: 180px;">Nombre<br>Procore</th>
                        <th class="col-name-web" style="width: 23%; min-width: 180px;">Nombre Web<br>(Rexel)</th>
                        <th class="col-num col-header-month" style="width: 9%; min-width: 95px; text-align: right;">Mes Ant.<br><span id="th-month-date-badge" style="font-size: 0.72rem; color: #60a5fa; font-weight: 700;">({month_date_header})</span></th>
                        <th class="col-num col-header-prior" style="width: 9%; min-width: 95px; text-align: right;">Ant. Reciente<br><span id="th-prev-date-badge" style="font-size: 0.72rem; color: var(--accent-primary); font-weight: 700;">({prev_date_header})</span></th>
                        <th class="col-num" style="width: 9%; min-width: 90px; text-align: right;">Precio<br>Actual<br><span id="th-current-date-badge" style="font-size: 0.72rem; color: var(--emerald-400); font-weight: 700;">({curr_date_header})</span></th>
                        <th class="col-num" style="width: 6%; min-width: 70px; text-align: right;">Diferencia<br>($)</th>
                        <th class="col-num" style="width: 6%; min-width: 70px; text-align: center;">Variación<br>(%)</th>
                        <th class="col-num" style="width: 9%; min-width: 95px; text-align: right;">Final<br>(+{tax_percent}% Tax)</th>
                        <th class="col-status" style="width: 11%; min-width: 110px; text-align: center;">Estado</th>
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
            diff_month = float(row['diff_month_usd'])
            pct_month = float(row['pct_month_change'])
            p_final = float(row['precio_final'])
            behavior = row['behavior']
            cat = row['categoria']

            # Formato de variación vs reciente (por defecto)
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

            print(f"""
                <tr class="report-row" data-category="{cat}" data-behavior="{behavior}"
                    data-diff-recent="{diff}" data-pct-recent="{pct}"
                    data-diff-month="{diff_month}" data-pct-month="{pct_month}">
                    <td class="col-num" style="text-align: center;"><span class="badge-neutral" style="font-weight: 700; font-size: 0.72rem;">{cat}</span></td>
                    <td class="col-name-procore" style="font-weight: 600; color: var(--text-primary);">{row['nombre_procore']}</td>
                    <td class="col-name-web text-secondary text-small">{row['nombre_web']}</td>
                    <td class="col-num col-data-month" style="text-align: right; font-family: monospace; color: #93c5fa;">${p_month:,.2f}</td>
                    <td class="col-num col-data-prior" style="text-align: right; font-family: monospace; color: var(--text-secondary);">${p_prev:,.2f}</td>
                    <td class="col-num" style="text-align: right; font-family: monospace; font-weight: 700; color: var(--text-primary);">${p_curr:,.2f}</td>
                    <td class="col-num col-diff-cell {diff_class}" style="text-align: right;">{diff_str}</td>
                    <td class="col-num col-pct-cell" style="text-align: center;"><span class="{badge_class}">{pct_str}</span></td>
                    <td class="col-num" style="text-align: right; font-weight: 800; color: var(--emerald-400); font-family: monospace;">${p_final:,.2f}</td>
                    <td class="col-status">{status_badge}</td>
                </tr>
            """)

        print(f"""
                </tbody>
            </table>
        </div>
        
        <!-- Contador de resultados mostrados -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.75rem; padding: 0 0.5rem; font-size: 0.8rem; color: var(--text-secondary);">
            <span id="report-filter-counter">Mostrando todos los registros</span>
            <span class="text-muted font-mono">Precios contrastados vs Mes Anterior y Última Extracción previa a hoy</span>
        </div>

        <script>
        window.selectedCategory = 'all';
        window.selectedBehavior = 'all';
        window.selectedViewMode = 'all'; // 'all', 'recent', 'month'
        window.prevCategoryDates = {prev_dates_json};
        window.kpiData = {{
            recent: {{
                avgPct: {avg_global_pct},
                up: {total_up},
                down: {total_down},
                shareUp: {pct_up_share},
                shareDown: {pct_down_share},
                subVar: "vs Último Scrap ({prev_date_header})",
                subUp: "Ítems con subida vs último scrap",
                subDown: "Ítems con bajada vs último scrap"
            }},
            month: {{
                avgPct: {avg_month_pct},
                up: {total_up_month},
                down: {total_down_month},
                shareUp: {round((total_up_month / total_items * 100), 1) if total_items > 0 else 0},
                shareDown: {round((total_down_month / total_items * 100), 1) if total_items > 0 else 0},
                subVar: "vs Mes Anterior ({month_date_header})",
                subUp: "Ítems con subida vs mes anterior",
                subDown: "Ítems con bajada vs mes anterior"
            }},
            all: {{
                avgPct: {avg_global_pct},
                up: {total_up},
                down: {total_down},
                shareUp: {pct_up_share},
                shareDown: {pct_down_share},
                subVar: "vs Último Scrap | <span style='color: #60a5fa; font-weight: 600;'>Mes: {'+' if avg_month_pct > 0 else ''}{avg_month_pct}%</span>",
                subUp: "Ítems con incremento de precio",
                subDown: "Ítems con precio menor o en oferta"
            }}
        }};
        </script>
        """)

        print("""
        <script>
        window.setViewMode = function(mode, btnEl) {
            window.selectedViewMode = mode;
            document.querySelectorAll('.filter-view-mode-chip').forEach(b => b.classList.remove('active'));
            if (btnEl) btnEl.classList.add('active');

            // Actualizar tarjetas KPI globales dinámicamente
            const kpi = window.kpiData[mode] || window.kpiData.all;
            const valVarEl = document.getElementById('kpi-val-variation');
            const subVarEl = document.getElementById('kpi-sub-variation');
            const cardVarEl = document.getElementById('kpi-card-variation');
            const valUpEl = document.getElementById('kpi-val-up');
            const shareUpEl = document.getElementById('kpi-share-up');
            const subUpEl = document.getElementById('kpi-sub-up');
            const valDownEl = document.getElementById('kpi-val-down');
            const shareDownEl = document.getElementById('kpi-share-down');
            const subDownEl = document.getElementById('kpi-sub-down');

            if (valVarEl && subVarEl) {
                const prefix = kpi.avgPct > 0 ? '+' : '';
                valVarEl.textContent = prefix + kpi.avgPct + '%';
                valVarEl.className = 'report-kpi-val ' + (kpi.avgPct > 0 ? 'text-danger' : 'text-success');
                if (cardVarEl) cardVarEl.style.borderTop = '3px solid ' + (kpi.avgPct > 0 ? '#f87171' : '#10b981');
                subVarEl.innerHTML = kpi.subVar;
            }
            if (valUpEl && shareUpEl) {
                valUpEl.textContent = '▲ ' + kpi.up;
                shareUpEl.textContent = kpi.shareUp + '% del total';
                if (subUpEl) subUpEl.textContent = kpi.subUp;
            }
            if (valDownEl && shareDownEl) {
                valDownEl.textContent = '▼ ' + kpi.down;
                shareDownEl.textContent = kpi.shareDown + '% del total';
                if (subDownEl) subDownEl.textContent = kpi.subDown;
            }

            const monthCols = document.querySelectorAll('.col-header-month, .col-data-month');
            const priorCols = document.querySelectorAll('.col-header-prior, .col-data-prior');

            if (mode === 'month') {
                monthCols.forEach(el => {
                    el.style.backgroundColor = 'rgba(96, 165, 250, 0.12)';
                    el.style.fontWeight = '700';
                });
                priorCols.forEach(el => {
                    el.style.backgroundColor = '';
                    el.style.fontWeight = 'normal';
                });
            } else if (mode === 'recent') {
                priorCols.forEach(el => {
                    el.style.backgroundColor = 'rgba(249, 115, 22, 0.12)';
                    el.style.fontWeight = '700';
                });
                monthCols.forEach(el => {
                    el.style.backgroundColor = '';
                    el.style.fontWeight = 'normal';
                });
            } else {
                monthCols.forEach(el => {
                    el.style.backgroundColor = '';
                    el.style.fontWeight = 'normal';
                });
                priorCols.forEach(el => {
                    el.style.backgroundColor = '';
                    el.style.fontWeight = 'normal';
                });
            }

            // Actualizar celdas de diferencia y porcentaje según el modo seleccionado
            document.querySelectorAll('.report-row').forEach(row => {
                const diffCell = row.querySelector('.col-diff-cell');
                const pctCell = row.querySelector('.col-pct-cell');
                if (!diffCell || !pctCell) return;

                const isMonth = (mode === 'month');
                const diffVal = parseFloat(row.getAttribute(isMonth ? 'data-diff-month' : 'data-diff-recent') || 0);
                const pctVal = parseFloat(row.getAttribute(isMonth ? 'data-pct-month' : 'data-pct-recent') || 0);

                let diffClass = "text-muted font-mono";
                let badgeClass = "badge-price-same";
                let diffStr = "$0.00";
                let pctStr = "▬ 0.0%";

                if (pctVal > 0.05) {
                    diffClass = "text-danger font-mono";
                    badgeClass = "badge-price-up";
                    diffStr = "+$" + diffVal.toFixed(2);
                    pctStr = "▲ +" + pctVal.toFixed(1) + "%";
                } else if (pctVal < -0.05) {
                    diffClass = "text-success font-mono";
                    badgeClass = "badge-price-down";
                    diffStr = "-$" + Math.abs(diffVal).toFixed(2);
                    pctStr = "▼ " + pctVal.toFixed(1) + "%";
                }

                diffCell.className = "col-num col-diff-cell " + diffClass;
                diffCell.textContent = diffStr;
                pctCell.innerHTML = '<span class="' + badgeClass + '">' + pctStr + '</span>';
            });
        };

        window.filterByCategory = function(cat, btnEl) {
            window.selectedCategory = cat;
            document.querySelectorAll('.cat-tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.cat-summary-card').forEach(c => c.classList.remove('active'));
            
            if (btnEl) {
                btnEl.classList.add('active');
            } else {
                const target = document.getElementById(cat === 'all' ? 'tab-btn-all' : 'tab-btn-' + cat);
                if (target) target.classList.add('active');
            }
            
            if (cat !== 'all') {
                const cardTarget = document.getElementById('cat-card-' + cat);
                if (cardTarget) cardTarget.classList.add('active');
            }

            const dateBadge = document.getElementById('th-prev-date-badge');
            const monthBadge = document.getElementById('th-month-date-badge');
            const currentBadge = document.getElementById('th-current-date-badge');
            const btnRecentLabel = document.getElementById('btn-recent-date-label');
            const btnMonthLabel = document.getElementById('btn-month-date-label');

            if (window.prevCategoryDates) {
                const info = (cat === 'all') ? (window.prevCategoryDates['ALL'] || {}) : (window.prevCategoryDates[cat] || {});
                const priorVal = info.prior_date || 'Mixto';
                const monthVal = info.month_date || '07/02';
                const currentVal = info.current_date || 'Mixto';
                if (dateBadge) dateBadge.textContent = '(' + priorVal + ')';
                if (monthBadge) monthBadge.textContent = '(' + monthVal + ')';
                if (currentBadge) currentBadge.textContent = '(' + currentVal + ')';
                if (btnRecentLabel) btnRecentLabel.textContent = priorVal;
                if (btnMonthLabel) btnMonthLabel.textContent = monthVal;
            }

            window.applyReportFilters();
        };

        window.filterByBehavior = function(beh, btnEl) {
            window.selectedBehavior = beh;
            document.querySelectorAll('.filter-beh-chip').forEach(b => b.classList.remove('active'));
            if (btnEl) btnEl.classList.add('active');
            window.applyReportFilters();
        };

        window.applyReportFilters = function() {
            const searchInput = document.getElementById('table-search');
            const term = searchInput ? searchInput.value.toUpperCase().trim() : '';
            const rows = document.querySelectorAll('.report-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const cat = row.getAttribute('data-category');
                const beh = row.getAttribute('data-behavior');
                const text = row.innerText.toUpperCase();

                const matchesCat = (window.selectedCategory === 'all' || cat === window.selectedCategory);
                const matchesBeh = (window.selectedBehavior === 'all' || beh === window.selectedBehavior);
                const matchesText = (!term || text.indexOf(term) > -1);

                if (matchesCat && matchesBeh && matchesText) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            const counter = document.getElementById('report-filter-counter');
            if (counter) {
                counter.textContent = 'Mostrando ' + visibleCount + ' de ' + rows.length + ' ítems filtrados';
            }
        };

        window.openModal = function(id) {
            const el = document.getElementById(id);
            if (el) {
                el.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        };

        window.closeModal = function(id) {
            const el = document.getElementById(id);
            if (el) {
                el.classList.remove('show');
                document.body.style.overflow = '';
            }
        };
        </script>

        <!-- MODAL: GUÍA DE ANÁLISIS E INTERPRETACIÓN DE PRECIOS -->
        <div class="modal-overlay" id="modal-price-analysis-guide" onclick="if(event.target===this) closeModal('modal-price-analysis-guide')">
            <div class="modal-content modal-lg" style="max-width: 840px; max-height: 88vh;">
                <div class="modal-header">
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <span style="font-size: 1.4rem;">💡</span>
                        <div>
                            <h3 style="margin: 0; font-size: 1.15rem; font-weight: 800; color: var(--text-primary);">Guía de Análisis e Interpretación de Precios</h3>
                            <span style="font-size: 0.74rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em;">Criterios de Métricas y Comparativas</span>
                        </div>
                    </div>
                    <button type="button" class="btn-icon-only" onclick="closeModal('modal-price-analysis-guide')">✕</button>
                </div>
                <div class="modal-body" style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1.35rem;">
                    
                    <!-- 1. Objetivo General -->
                    <div style="background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.25); border-radius: 0.9rem; padding: 1.1rem 1.3rem;">
                        <h4 style="margin: 0 0 0.4rem 0; color: var(--blue-400); font-size: 0.98rem; display: flex; align-items: center; gap: 0.45rem;">
                            <span>🎯</span> Objetivo del Reporte
                        </h4>
                        <p style="margin: 0; font-size: 0.86rem; color: var(--text-secondary); line-height: 1.55;">
                            Este reporte analiza y audita los precios unitarios extraídos en vivo desde la cuenta corporativa de <strong>Rexel USA</strong>, contrastándolos contra dos horizontes temporales independientes (<strong>Mes Anterior</strong> y <strong>Última Extracción previa a hoy</strong>). Su propósito es suministrar visibilidad comercial para prevenir pérdidas y ajustar presupuestos antes de sincronizar plantillas con <strong>Procore</strong>.
                        </p>
                    </div>

                    <!-- 2. Tarjetas KPI Superiores -->
                    <div>
                        <h4 style="margin: 0 0 0.75rem 0; color: var(--text-primary); font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 0.45rem;">
                            <span>📊</span> Métricas Clave (Tarjetas Superiores)
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.9rem;">
                            <div style="background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 0.8rem; padding: 1rem;">
                                <strong style="color: var(--text-primary); font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">📦 Total Ítems en Catálogo</strong>
                                <span style="font-size: 0.81rem; color: var(--text-secondary); line-height: 1.45; display: block;">
                                    Universo total de ítems registrados en el diccionario y cuántos han sido actualizados con precios frescos en la jornada de hoy.
                                </span>
                            </div>
                            <div style="background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 0.8rem; padding: 1rem;">
                                <strong style="color: #60a5fa; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">📈 Variación Promedio Global</strong>
                                <span style="font-size: 0.81rem; color: var(--text-secondary); line-height: 1.45; display: block;">
                                    Promedio aritmético de la fluctuación porcentual de todos los productos. En rojo (+) indica encarecimiento general y en verde (-) abaratamiento o ahorro.
                                </span>
                            </div>
                            <div style="background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 0.8rem; padding: 1rem;">
                                <strong style="color: #f59e0b; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">▲ Precios al Alza</strong>
                                <span style="font-size: 0.81rem; color: var(--text-secondary); line-height: 1.45; display: block;">
                                    Cantidad y proporción de productos que sufrieron un incremento de costo. Alerta inmediata para actualizar presupuestos de obra.
                                </span>
                            </div>
                            <div style="background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 0.8rem; padding: 1rem;">
                                <strong style="color: var(--emerald-400); font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">▼ Precios a la Baja / Ahorro</strong>
                                <span style="font-size: 0.81rem; color: var(--text-secondary); line-height: 1.45; display: block;">
                                    Productos cuyo costo unitario disminuyó o tienen promociones vigentes en Rexel. Oportunidades directas de ahorro en compras.
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Modos de Comparación -->
                    <div>
                        <h4 style="margin: 0 0 0.75rem 0; color: var(--text-primary); font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 0.45rem;">
                            <span>🎛️</span> Selector de Modos de Comparación
                        </h4>
                        <div style="background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 0.85rem; padding: 1.1rem; display: flex; flex-direction: column; gap: 0.75rem;">
                            <div style="display: flex; gap: 0.75rem; align-items: flex-start;">
                                <span class="filter-chip active" style="font-size: 0.75rem; padding: 0.3rem 0.65rem; white-space: nowrap;">📊 Vista Completa</span>
                                <span style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.45;">
                                    Presenta simultáneamente ambas columnas base (<strong>Mes Ant.</strong> y <strong>Ant. Reciente</strong>) permitiendo contrastar de un vistazo la trayectoria completa del costo.
                                </span>
                            </div>
                            <div style="display: flex; gap: 0.75rem; align-items: flex-start;">
                                <span class="filter-chip" style="font-size: 0.75rem; padding: 0.3rem 0.65rem; white-space: nowrap; color: var(--accent-primary); border-color: rgba(249, 115, 22, 0.4);">⏱️ vs Último Anterior</span>
                                <span style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.45;">
                                    Compara contra la extracción más reciente previa a hoy. Recalcula las columnas de <em>Diferencia ($)</em>, <em>Variación (%)</em> y las tarjetas KPI superiores para reflejar micro-fluctuaciones a corto plazo.
                                </span>
                            </div>
                            <div style="display: flex; gap: 0.75rem; align-items: flex-start;">
                                <span class="filter-chip" style="font-size: 0.75rem; padding: 0.3rem 0.65rem; white-space: nowrap; color: #60a5fa; border-color: rgba(96, 165, 250, 0.4);">📅 vs Mes Anterior</span>
                                <span style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.45;">
                                    Compara contra el cierre consolidado del mes anterior (ej. 30+ días atrás). Recalcula las métricas para medir la <strong>inflación real de materiales</strong> acumulada.
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Columnas de la Tabla -->
                    <div>
                        <h4 style="margin: 0 0 0.75rem 0; color: var(--text-primary); font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 0.45rem;">
                            <span>📋</span> Estructura de la Tabla Detallada
                        </h4>
                        <div style="overflow-x: auto; border-radius: 0.75rem; border: 1px solid var(--border-subtle);">
                            <table style="width: 100%; border-collapse: collapse; font-size: 0.82rem; color: var(--text-secondary);">
                                <thead>
                                    <tr style="border-bottom: 1px solid var(--border-subtle); background: var(--bg-card); color: var(--text-primary); text-align: left;">
                                        <th style="padding: 0.6rem 0.85rem;">Columna</th>
                                        <th style="padding: 0.6rem 0.85rem;">Significado / Cálculo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                        <td style="padding: 0.6rem 0.85rem; font-weight: 700; color: var(--text-primary);">Nombre Procore & Rexel</td>
                                        <td style="padding: 0.6rem 0.85rem;">Mapeo bidireccional entre el código/descripción corporativa de Procore y el título extraído de Rexel USA.</td>
                                    </tr>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                        <td style="padding: 0.6rem 0.85rem; font-weight: 700; color: #60a5fa;">Mes Ant. (MM/DD)</td>
                                        <td style="padding: 0.6rem 0.85rem;">Costo unitario registrado al corte del mes anterior (formato americano Mes/Día).</td>
                                    </tr>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                        <td style="padding: 0.6rem 0.85rem; font-weight: 700; color: var(--accent-primary);">Ant. Reciente (MM/DD)</td>
                                        <td style="padding: 0.6rem 0.85rem;">Último costo unitario registrado estrictamente antes de la fecha de hoy.</td>
                                    </tr>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                        <td style="padding: 0.6rem 0.85rem; font-weight: 700; color: var(--text-primary);">Precio Actual</td>
                                        <td style="padding: 0.6rem 0.85rem;">Costo unitario base obtenido directamente del catálogo en vivo con la sesión autenticada de Rexel.</td>
                                    </tr>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                        <td style="padding: 0.6rem 0.85rem; font-weight: 700; color: var(--text-primary);">Diferencia ($) & Var (%)</td>
                                        <td style="padding: 0.6rem 0.85rem;"><code>Precio Actual - Precio Anterior</code> y <code>(Diferencia / Precio Anterior) * 100</code>. Se actualiza dinámicamente según el modo seleccionado.</td>
                                    </tr>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                        <td style="padding: 0.6rem 0.85rem; font-weight: 700; color: var(--emerald-400);">Final (+Tax)</td>
                                        <td style="padding: 0.6rem 0.85rem;"><code>Precio Actual * (1 + Tax / 100)</code>. Costo final con impuestos aplicados (o 0% en Modo Bypass), listo para exportarse a Procore.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
                <div style="padding: 1rem 1.5rem; border-top: 1px solid var(--border-subtle); display: flex; justify-content: flex-end; background: rgba(0,0,0,0.15);">
                    <button type="button" class="btn-primary" onclick="closeModal('modal-price-analysis-guide')" style="padding: 0.6rem 1.4rem; font-size: 0.85rem; font-weight: 700; border-radius: 0.65rem;">
                        Entendido, Volver al Reporte
                    </button>
                </div>
            </div>
        </div>
        """)

    except Exception as e:
        print(f"<div class='alert-box alert-error'>Ocurrió un error al generar el reporte de fluctuación de precios: {e}</div>")

if __name__ == '__main__':
    tax = float(sys.argv[1]) if len(sys.argv) > 1 else 6.5
    mode = sys.argv[2] if len(sys.argv) > 2 else "view"
    token = sys.argv[3] if len(sys.argv) > 3 else ""
    view_all_prices(tax, mode, token)