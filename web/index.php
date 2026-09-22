<?php
session_start(); // Iniciar sesión para comprobar si las credenciales ya existen.

// --- SEGURIDAD: GENERAR CSRF TOKEN ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- CENTRALIZED DB HELPER ---
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rexel_extension_config.php';

// --- CARGAR CONFIGURACIÓN GLOBAL (Tax) ---
try {
    $stored_tax = get_config_value('tax_rate');
    $stored_bypass = get_config_value('tax_bypass');
    if ($stored_tax !== false) $_SESSION['tax_rate'] = $stored_tax;
    if ($stored_bypass !== false) $_SESSION['tax_bypass'] = $stored_bypass;
} catch (Exception $e) { /* Silenciar error en carga inicial */ }

$storage_state_file = realpath(__DIR__ . '/../scripts/storage_state.json');
if ($storage_state_file && file_exists($storage_state_file) && empty($_SESSION['rexel_email'])) {
    $_SESSION['rexel_email'] = 'rexel_authenticated';
}

// --- OBTENER CATEGORÍAS Y MÉTRICAS DE DASHBOARD ---
$categories = [];
$category_stats = [];
$total_items = 0;
$total_mapeados = 0;
$updated_today = 0;
$total_urls = 0;

$today_str = date('Y-m-d');

try {
    $pdo = get_db_connection();

    // Categorías únicas
    $stmt = $pdo->query("SELECT DISTINCT category FROM scraping_urls WHERE category IS NOT NULL AND category != '' ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Resumen global consolidado para KPIs (1 sola consulta agregada optimizada)
    $stmt_global = $pdo->prepare("
        SELECT 
            COUNT(*) as total_items,
            MAX(ultima_actualizacion) as last_update_raw,
            SUM(CASE WHEN ultima_actualizacion LIKE ? THEN 1 ELSE 0 END) as updated_today
        FROM catalogo_web
    ");
    $stmt_global->execute([$today_str . '%']);
    $global_row = $stmt_global->fetch(PDO::FETCH_ASSOC) ?: [];

    $total_items = (int)($global_row['total_items'] ?? 0);
    $updated_today = (int)($global_row['updated_today'] ?? 0);
    $last_update_raw = $global_row['last_update_raw'] ?? null;
    $total_mapeados = (int)$pdo->query("SELECT COUNT(*) FROM mapeo_procore")->fetchColumn();

    // Fecha y hora de la última actualización global de precios
    $last_update_text = 'Sin registros';
    if ($last_update_raw) {
        $last_update_ts = strtotime($last_update_raw);
        if (date('Y-m-d', $last_update_ts) === date('Y-m-d')) {
            $last_update_text = 'Hoy a las ' . date('H:i', $last_update_ts);
        } else {
            $last_update_text = date('m/d/Y H:i', $last_update_ts);
        }
    }

    // Datos detallados del último mapeo Procore
    $last_map_date = get_config_value('last_mapping_date');
    $last_map_count = get_config_value('last_mapping_count');
    $last_map_cat = get_config_value('last_mapping_category');
    if (!$last_map_date) {
        $fallback_row = $pdo->query("
            SELECT c.categoria, COUNT(*) as cnt, MAX(c.ultima_actualizacion) as max_date
            FROM catalogo_web c
            JOIN mapeo_procore m ON c.id = m.web_item_id
            GROUP BY c.categoria
            ORDER BY max_date DESC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        if ($fallback_row && !empty($fallback_row['max_date'])) {
            $last_map_date = $fallback_row['max_date'];
            $last_map_count = (int)$fallback_row['cnt'];
            $last_map_cat = $fallback_row['categoria'];
        } else {
            $last_map_date = $last_update_raw ? $last_update_raw : date('Y-m-d H:i:s');
            $last_map_count = $total_mapeados;
            $last_map_cat = 'General';
        }
    }
    $last_map_date_fmt = $last_map_date ? date('m/d/Y H:i', strtotime($last_map_date)) : 'N/A';

    $total_urls = (int)$pdo->query("SELECT COUNT(*) FROM scraping_urls WHERE is_active = 1")->fetchColumn();

        // Estadísticas por categoría
        $stmt_cats = $pdo->prepare("
            SELECT 
                c.categoria,
                COUNT(c.id) as item_count,
                MAX(c.ultima_actualizacion) as last_update,
                SUM(CASE WHEN c.ultima_actualizacion LIKE ? THEN 1 ELSE 0 END) as updated_today
            FROM catalogo_web c
            GROUP BY c.categoria
        ");
        $stmt_cats->execute([$today_str . '%']);
        $cat_rows = $stmt_cats->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cat_rows as $row) {
            $category_stats[$row['categoria']] = $row;
        }

        // Conteo de URLs por categoría
        $stmt_urls = $pdo->query("SELECT category, COUNT(*) as url_count FROM scraping_urls WHERE is_active = 1 GROUP BY category");
        $url_rows = $stmt_urls->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($url_rows as $cat => $cnt) {
            if (!isset($category_stats[$cat])) {
                $category_stats[$cat] = [
                    'categoria' => $cat,
                    'item_count' => 0,
                    'last_update' => null,
                    'updated_today' => 0
                ];
            }
            $category_stats[$cat]['url_count'] = (int)$cnt;
        }
} catch (Exception $e) {
    // Si falla la DB, mantenemos fallbacks seguros
}

if (empty($categories)) {
    $categories = ['EMT', 'PVC', 'Wires'];
}

// Configuración estética y visual por categoría
$cat_meta = [
    'EMT' => ['icon' => '⚡', 'color' => '#3b82f6', 'desc' => 'Tuberías y condulets metálicos de alta durabilidad'],
    'PVC' => ['icon' => '🛡️', 'color' => '#10b981', 'desc' => 'Canalizaciones, codos y accesorios de PVC rígido'],
    'Wires' => ['icon' => '🔌', 'color' => '#f59e0b', 'desc' => 'Conductores eléctricos de cobre y aleaciones'],
    'FUSES' => ['icon' => '💥', 'color' => '#ec4899', 'desc' => 'Fusibles industriales y sistemas de protección']
];

// Archivos Excel recientes generados
$recent_excels = [];
$excel_dir = realpath(__DIR__ . '/../data/excel_output');
if ($excel_dir && is_dir($excel_dir)) {
    $files = glob($excel_dir . '/*.xlsx');
    if ($files) {
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        foreach (array_slice($files, 0, 3) as $f) {
            $recent_excels[] = [
                'name' => basename($f),
                'size' => round(filesize($f) / 1024, 1) . ' KB',
                'time' => date('m/d/Y H:i', filemtime($f))
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard · Catalogo Update Workflow</title>
    <link rel="stylesheet" href="../css/style.css?v=<?php echo filemtime(__DIR__ . '/../css/style.css'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="app-dialogs.js"></script>
</head>
<body>
    <!-- Navbar Superior -->
    <nav class="navbar">
        <div class="logo-container">
            <img src="../css/logo-text.png" alt="Brightronix Logo" class="logo-full">
            <span class="app-subtitle">Operations Hub</span>
        </div>

        <div class="nav-actions">
            <!-- Badge de estado de sesion Rexel -->
            <button id="rexel-session-badge"
                    class="session-status-badge status-loading"
                    onclick="openRexelModal()"
                    title="Estado de la sesion de Rexel">
                <span class="badge-dot"></span>
                <span id="rexel-badge-text">Verificando Rexel...</span>
            </button>
        </div>
    </nav>

    <div class="main-content">
        <div class="workspace" style="max-width: 1400px; width: 100%; min-width: 0; margin: 0 auto; padding: 1.5rem 1rem 3rem 1rem; box-sizing: border-box;">
            
            <!-- 1. HERO HEADER DE OPERACIONES -->
            <div class="dashboard-hero">
                <div class="dashboard-hero-title-wrap">
                    <h1 class="dashboard-hero-title">Centro de Control de Catálogos</h1>
                </div>
                <div class="dashboard-hero-quick-actions">
                    <a href="configuracion.php" class="btn-hero-action" title="Abrir configuración del sistema">
                        <span class="hero-action-icon">⚙️</span>
                        <div class="hero-action-text">
                            <span class="hero-action-title">Configuración</span>
                            <span class="hero-action-sub">Ajustes del Sistema</span>
                        </div>
                    </a>
                </div>
            </div>

            <?php if (isset($_GET['error'])): ?>
                <div class="alert-box alert-error" style="margin-bottom: 1.5rem;"><?php echo htmlspecialchars($_GET['error']); ?></div>
            <?php endif; ?>

            <?php if (isset($_GET['success'])): ?>
                <div class="alert-box alert-success" style="margin-bottom: 1.5rem;"><?php echo htmlspecialchars($_GET['success']); ?></div>
            <?php endif; ?>

            <!-- 2. RESUMEN DE INDICADORES CLAVE (KPIS) -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-label">Materiales en Base</span>
                        <div class="kpi-icon-wrap kpi-blue">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                        </div>
                    </div>
                    <div class="kpi-value"><?php echo number_format($total_items); ?></div>
                    <div class="kpi-footer">
                        <span class="kpi-sub-text">Inventario local · <?php echo count($categories); ?> categorías</span>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-label">Actualizados Hoy</span>
                        <div class="kpi-icon-wrap kpi-emerald">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        </div>
                    </div>
                    <div class="kpi-value"><?php echo number_format($updated_today); ?></div>
                    <div class="kpi-footer">
                        <span class="kpi-sub-text" style="display: flex; flex-direction: column; gap: 0.2rem;">
                            <span style="color: var(--text-primary); font-weight: 600; font-size: 0.76rem;">
                                🕒 <?php echo htmlspecialchars($last_update_text); ?>
                            </span>
                            <span>
                                <?php echo $total_items > 0 ? round(($updated_today / $total_items) * 100, 1) : 0; ?>% del total · 
                                <strong style="color: <?php echo $updated_today > 0 ? 'var(--emerald-500)' : 'var(--text-muted)'; ?>;">
                                    <?php echo $updated_today > 0 ? 'Al día' : 'Pendiente'; ?>
                                </strong>
                            </span>
                        </span>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-label">URLs de Scraping</span>
                        <div class="kpi-icon-wrap kpi-amber">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                        </div>
                    </div>
                    <div class="kpi-value"><?php echo number_format($total_urls); ?></div>
                    <div class="kpi-footer">
                        <span class="kpi-sub-text">Enlaces activos en Rexel USA</span>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-label">Mapeo Procore</span>
                        <div class="kpi-icon-wrap kpi-orange">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v1"></path><path d="M18 8h4a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-4"></path><circle cx="8" cy="12" r="2"></circle></svg>
                        </div>
                    </div>
                    <div class="kpi-value"><?php echo number_format($total_mapeados); ?></div>
                    <div class="kpi-footer">
                        <span class="kpi-sub-text" style="display: flex; flex-direction: column; gap: 0.2rem;">
                            <span style="color: var(--text-primary); font-weight: 600; font-size: 0.76rem;">
                                📅 Último: <?php echo htmlspecialchars($last_map_date_fmt); ?>
                            </span>
                            <span style="color: var(--emerald-400); font-weight: 600; font-size: 0.76rem;">
                                ✓ <?php echo number_format($last_map_count); ?> ítems incluidos (<?php echo htmlspecialchars($last_map_cat); ?>)
                            </span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- SELECTOR DE MODO DE NAVEGADOR (VISIBLE VS SILENCIOSO) -->
            <div class="execution-mode-card" style="margin-bottom: 1.5rem; background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 1rem; padding: 1rem 1.4rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; box-shadow: 0 4px 15px rgba(0,0,0,0.15);">
                <div style="display: flex; align-items: center; gap: 0.85rem;">
                    <div style="width: 42px; height: 42px; border-radius: 50%; background: rgba(59, 130, 246, 0.15); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; border: 1px solid rgba(59, 130, 246, 0.3);">
                        🖥️
                    </div>
                    <div>
                        <div style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                            <span>Modo de Ejecución del Navegador</span>
                            <span id="mode-current-badge" class="badge-new" style="font-size: 0.72rem; padding: 0.15rem 0.55rem;">Visible</span>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.15rem;">
                            Elige si deseas ver la ventana del navegador en vivo en tu pantalla para monitorear o ejecutarlo en segundo plano.
                        </div>
                    </div>
                </div>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <button type="button" id="btn-browser-visible" onclick="setScraperBrowserMode(false)" style="padding: 0.6rem 1.1rem; border-radius: 0.65rem; font-size: 0.85rem; font-weight: 700; cursor: pointer; border: 1px solid #3b82f6; background: rgba(59, 130, 246, 0.2); color: #60a5fa; display: flex; align-items: center; gap: 0.45rem; transition: all 0.2s;">
                        <span>👁️</span> Modo Visible (Ver Navegador)
                    </button>
                    <button type="button" id="btn-browser-headless" onclick="setScraperBrowserMode(true)" style="padding: 0.6rem 1.1rem; border-radius: 0.65rem; font-size: 0.85rem; font-weight: 600; cursor: pointer; border: 1px solid var(--border-subtle); background: var(--bg-panel); color: var(--text-secondary); display: flex; align-items: center; gap: 0.45rem; transition: all 0.2s;">
                        <span>⚡</span> Modo Silencioso (Headless)
                    </button>
                </div>
            </div>

            <!-- 3. BANNER DE AUTOMATIZACIÓN COMPLETA (EN MEDIO) -->
            <div class="automation-banner">
                <div class="automation-banner-content">
                    <div class="automation-banner-icon">⚡</div>
                    <div class="automation-banner-text">
                        <h3>Actualización Completa del Catálogo</h3>
                        <p>Ejecuta la extracción secuencial para todas las categorías activas (<?php echo implode(', ', $categories); ?>), actualiza los precios más recientes y consolida la base de datos.</p>
                    </div>
                </div>
                <div class="automation-banner-actions">
                    <form action="ejecutar.php" method="post" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="script" value="update_all">
                        <input type="hidden" name="headless" class="input-headless-val" value="0">
                        <button type="submit" class="btn-automation-run">
                            <span>⚡</span> Iniciar Actualización Total
                        </button>
                    </form>
                </div>
            </div>

            <!-- 4. LISTA DE CATEGORÍAS DEL CATÁLOGO (ESCALABLE EN FORMATO LISTA) -->
            <div class="categories-list-section">
                <div class="cat-section-header">
                    <div class="cat-section-title-group">
                        <span style="font-size: 1.3rem;">📂</span>
                        <h2 class="cat-section-title">Categorías del Catálogo</h2>
                        <span class="cat-count-pill"><?php echo count($categories); ?> Activas</span>
                    </div>
                    <a href="configuracion.php" style="font-size: 0.82rem; color: var(--accent-primary); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem;">
                        <span>⚙️ Administrar Enlaces & Categorías &rarr;</span>
                    </a>
                </div>

                <div class="cat-table-wrap">
                    <table class="cat-list-table">
                        <thead>
                            <tr>
                                <th style="width: 40%;">Categoría</th>
                                <th style="width: 18%;">Materiales</th>
                                <th style="width: 14%;">URLs Rastreadas</th>
                                <th style="width: 16%;">Estado de Hoy</th>
                                <th style="width: 12%; text-align: right;">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): 
                                $meta = $cat_meta[$cat] ?? ['icon' => '📦', 'color' => '#64748b', 'desc' => 'Categoría de materiales eléctricos'];
                                $stats = $category_stats[$cat] ?? ['item_count' => 0, 'last_update' => 'Sin registro', 'updated_today' => 0, 'url_count' => 0];
                                $is_updated = !empty($stats['updated_today']) && $stats['updated_today'] > 0;
                                $cat_slug = strtolower($cat);
                            ?>
                                <tr>
                                    <!-- Identidad de la Categoría -->
                                    <td>
                                        <div class="cat-ident-cell">
                                            <div class="cat-ident-icon" style="background: <?php echo $meta['color']; ?>18; border-color: <?php echo $meta['color']; ?>40;">
                                                <?php echo $meta['icon']; ?>
                                            </div>
                                            <div>
                                                <div class="cat-ident-name"><?php echo htmlspecialchars($cat); ?></div>
                                                <div class="cat-ident-desc"><?php echo htmlspecialchars($meta['desc']); ?></div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Materiales -->
                                    <td>
                                        <div class="cat-num-pill">
                                            <span>📦</span>
                                            <span><?php echo number_format($stats['item_count']); ?> ítems</span>
                                        </div>
                                    </td>

                                    <!-- URLs -->
                                    <td>
                                        <div class="cat-num-pill">
                                            <span>🔗</span>
                                            <span><?php echo number_format($stats['url_count'] ?? 0); ?> URLs</span>
                                        </div>
                                    </td>

                                    <!-- Estado de Hoy -->
                                    <td>
                                        <?php if ($is_updated): ?>
                                            <span class="status-pill status-pill-success" title="Actualizado durante el día de hoy">
                                                <span class="status-dot status-dot-active"></span>
                                                <span><?php echo $stats['updated_today']; ?> ítems hoy</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="status-pill status-pill-inactive" title="Pendiente de extracción hoy">
                                                <span class="status-dot status-dot-inactive"></span>
                                                <span>Pendiente hoy</span>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Acción de Ejecución Directa -->
                                    <td style="text-align: right;">
                                        <form action="ejecutar.php" method="post" style="margin: 0; display: inline-block;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="script" value="update_<?php echo htmlspecialchars($cat_slug); ?>">
                                            <input type="hidden" name="headless" class="input-headless-val" value="0">
                                            <button type="submit" class="btn-cat-run">
                                                <span>Actualizar</span>
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 5. INTEGRACIONES Y REPORTES (SECCIÓN INFERIOR) -->
            <div class="bottom-tools-grid">
                <?php if (REXEL_EXTENSION_EXPERIMENT_ENABLED): ?>
                <!-- Prototipo aislado: navegador local mediante extension MV3 -->
                <div class="tool-hub-card" style="border-color: rgba(245, 158, 11, 0.5);">
                    <div>
                        <div class="tool-hub-header">
                            <div class="tool-hub-icon" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3);">&#129514;</div>
                            <div>
                                <h3 class="tool-hub-title">Rexel en navegador local</h3>
                                <span class="tool-hub-sub" style="color:#f59e0b;font-weight:700;">EXPERIMENTAL &middot; Extension MV3</span>
                            </div>
                        </div>
                        <p class="tool-hub-desc">Crea un trabajo para una URL y extrae precios desde una pesta&ntilde;a normal de Chrome, Edge o Brave, sin ejecutar Chromium en el servidor.</p>
                    </div>
                    <a href="rexel_extension.php" class="btn-tool-action" style="border-left:3px solid #f59e0b;text-decoration:none;box-sizing:border-box;">
                        <span>Abrir prototipo</span><span style="opacity:.6">&rarr;</span>
                    </a>
                </div>
                <?php endif; ?>
                
                <!-- Card: Procore Dashboard -->
                <div class="tool-hub-card">
                    <div>
                        <div class="tool-hub-header">
                            <div class="tool-hub-icon" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3);">
                                🚀
                            </div>
                            <div>
                                <h3 class="tool-hub-title">Procore Dashboard</h3>
                                <span class="tool-hub-sub">Cost Catalog & Sincronización</span>
                            </div>
                        </div>
                        <p class="tool-hub-desc">
                            Gestión y subida directa de plantillas generadas hacia Procore Cost Catalog sin necesidad de repetir el scraping.
                        </p>
                    </div>
                    <form action="ejecutar.php" method="post" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <button type="submit" name="script" value="procore_dashboard" class="btn-tool-action" style="border-left: 3px solid #f59e0b;">
                            <span>Abrir Procore Hub</span>
                            <span style="opacity: 0.6;">&rarr;</span>
                        </button>
                    </form>
                </div>

                <!-- Card: Reporte de Precios -->
                <div class="tool-hub-card">
                    <div>
                        <div class="tool-hub-header">
                            <div class="tool-hub-icon" style="background: rgba(251, 90, 58, 0.15); color: var(--accent-primary); border: 1px solid rgba(251, 90, 58, 0.3);">
                                📊
                            </div>
                            <div>
                                <h3 class="tool-hub-title">Reporte de Precios</h3>
                                <span class="tool-hub-sub">Fluctuaciones & Historial</span>
                            </div>
                        </div>
                        <p class="tool-hub-desc">
                            Análisis de variaciones de precios entre Rexel y catálogo local, filtros interactivos y cálculo de tax con recargo.
                        </p>
                    </div>
                    <form action="ejecutar.php" method="post" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <button type="submit" name="script" value="view_prices" class="btn-tool-action" style="border-left: 3px solid var(--accent-primary);">
                            <span>Ver Reporte e Información</span>
                            <span style="opacity: 0.6;">&rarr;</span>
                        </button>
                    </form>
                </div>

                <!-- Card: Excels Recientes -->
                <div class="tool-hub-card">
                    <div>
                        <div class="tool-hub-header">
                            <div class="tool-hub-icon" style="background: rgba(16, 185, 129, 0.15); color: var(--emerald-500); border: 1px solid rgba(16, 185, 129, 0.3);">
                                📑
                            </div>
                            <div>
                                <h3 class="tool-hub-title">Excels Recientes</h3>
                                <span class="tool-hub-sub">Plantillas en data/excel_output/</span>
                            </div>
                        </div>
                        
                        <?php if (!empty($recent_excels)): ?>
                            <div class="recent-files-compact-list" style="margin-bottom: 0.5rem;">
                                <?php foreach ($recent_excels as $rf): ?>
                                    <div class="recent-file-row">
                                        <div style="display: flex; align-items: center; gap: 0.5rem; overflow: hidden;">
                                            <span style="font-size: 1rem;">📊</span>
                                            <span class="recent-file-name" title="<?php echo htmlspecialchars($rf['name']); ?>">
                                                <?php echo htmlspecialchars($rf['name']); ?>
                                            </span>
                                        </div>
                                        <span class="recent-file-meta"><?php echo $rf['time']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="tool-hub-desc">No hay plantillas Excel generadas recientemente en el sistema.</p>
                        <?php endif; ?>
                    </div>
                    <form action="ejecutar.php" method="post" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <button type="submit" name="script" value="export_prices" class="btn-tool-action" style="border-left: 3px solid var(--emerald-500);">
                            <span>Exportar Todo a Excel</span>
                            <span style="opacity: 0.6;">&rarr;</span>
                        </button>
                    </form>
                </div>

            </div>

        </div>
    </div>
</body>

<!-- ============================================================
     MODAL: REXEL SESSION MANAGER
     ============================================================ -->
<div id="modal-rexel-session" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="rexel-modal-title">
    <div class="modal-content" style="max-width: 650px; width: 94%;">

        <!-- Header -->
        <div class="modal-header">
            <h3 id="rexel-modal-title">🔐 Conectar Rexel</h3>
            <button class="btn-icon-only" onclick="closeRexelModal()" title="Cerrar">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <!-- Stepper -->
        <div class="step-indicator" id="rexel-step-indicator">
            <div class="step-item" id="step-1">
                <div class="step-circle">1</div>
                <div class="step-label">Preparar</div>
            </div>
            <div class="step-line" id="step-line-1"></div>
            <div class="step-item" id="step-2">
                <div class="step-circle">2</div>
                <div class="step-label">Login</div>
            </div>
            <div class="step-line" id="step-line-2"></div>
            <div class="step-item" id="step-3">
                <div class="step-circle">3</div>
                <div class="step-label">Listo</div>
            </div>
        </div>

        <!-- Body: Panel dinamico segun el estado -->
        <div class="modal-body">

            <!-- PANEL 0: Conectado (Sesión Activa) -->
            <div id="rexel-panel-connected" style="display:none;">
                <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 1rem; padding: 1.25rem; margin-bottom: 1.25rem; text-align: center;">
                    <div style="width: 50px; height: 50px; border-radius: 50%; background: rgba(16, 185, 129, 0.15); display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 0.6rem; border: 1px solid rgba(16, 185, 129, 0.3);">
                        ⚡
                    </div>
                    <h4 style="margin: 0 0 0.35rem 0; color: #10b981; font-size: 1.15rem; font-weight: 800;">
                        Sesión Activa de Rexel USA
                    </h4>
                    <p style="margin: 0; font-size: 0.85rem; color: var(--text-secondary); line-height: 1.5;" id="rexel-connected-msg">
                        Autenticación corporativa lista para extracción de precios y catálogos.
                    </p>
                </div>

                <div style="background: var(--bg-input); border: 1px solid var(--border-subtle); border-radius: 0.85rem; padding: 1rem; margin-bottom: 1rem; display: flex; flex-direction: column; gap: 0.75rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
                        <span style="color: var(--text-muted); font-weight: 600;">Estado de conexión:</span>
                        <span class="session-status-badge status-ok" style="padding: 0.2rem 0.65rem; font-size: 0.75rem;">
                            <span class="badge-dot"></span> <span id="rexel-connected-status-pill">Conectado</span>
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
                        <span style="color: var(--text-muted); font-weight: 600;">Tiempo restante:</span>
                        <span style="color: var(--text-primary); font-weight: 700; font-family: 'Consolas', monospace;" id="rexel-connected-time">
                            Calculando...
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
                        <span style="color: var(--text-muted); font-weight: 600;">Auto-renovación:</span>
                        <span style="color: #10b981; font-weight: 700; font-size: 0.82rem; display: flex; align-items: center; gap: 0.35rem;">
                            <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#10b981;"></span>
                            Segundo Plano Continuo
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
                        <span style="color: var(--text-muted); font-weight: 600;">Almacenamiento:</span>
                        <span style="color: var(--text-secondary); font-size: 0.78rem; font-family: 'Consolas', monospace;">
                            scripts/storage_state.json
                        </span>
                    </div>
                </div>

                <div style="background: rgba(16, 185, 129, 0.05); border: 1px dashed rgba(16, 185, 129, 0.3); border-radius: 0.75rem; padding: 0.75rem 1rem; font-size: 0.8rem; color: var(--text-secondary); line-height: 1.5;">
                    ⚡ <strong>Auto-renovación activa:</strong> El sistema renueva tus credenciales periódicamente en segundo plano con Playwright Headless para que no tengas que volver a iniciar sesión manualmente.
                </div>
            </div>

            <!-- PANEL 1: Listo para iniciar -->
            <div id="rexel-panel-ready">
                <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border-subtle); padding-bottom: 0.5rem;">
                    <button type="button" id="tab-btn-browser" onclick="switchRexelMethod('browser')" style="padding: 0.45rem 0.9rem; font-size: 0.83rem; font-weight: 700; border-radius: 0.5rem; border: none; cursor: pointer; background: var(--bg-card-hover); color: var(--text-primary);">
                        🌐 Navegador Automático
                    </button>
                    <button type="button" id="tab-btn-import" onclick="switchRexelMethod('import')" style="padding: 0.45rem 0.9rem; font-size: 0.83rem; font-weight: 600; border-radius: 0.5rem; border: none; cursor: pointer; background: transparent; color: var(--text-muted);">
                        📥 Importar / Sincronizar (VPS / Remoto)
                    </button>
                </div>

                <!-- Subseccion A: Navegador -->
                <div id="rexel-sub-browser">
                    <p style="color: var(--text-secondary); margin: 0 0 1.25rem 0; line-height: 1.7;">
                        Se abrirá una ventana de tu navegador (detectando automáticamente <strong>Brave</strong>, Chrome o Edge)
                        donde podrás iniciar sesión en Rexel con tus credenciales de forma segura.
                    </p>
                    <div class="login-status-panel" style="text-align: left; padding: 1rem 1.25rem;">
                        <div style="display: flex; align-items: flex-start; gap: 0.75rem; margin-bottom: 0.6rem;">
                            <span style="color: var(--accent-primary); font-size: 1.1rem; margin-top: 0.05rem;">1.</span>
                            <span style="color: var(--text-secondary); font-size: 0.9rem;">Haz clic en <strong style="color:var(--text-primary)">Abrir Navegador</strong> abajo.</span>
                        </div>
                        <div style="display: flex; align-items: flex-start; gap: 0.75rem; margin-bottom: 0.6rem;">
                            <span style="color: var(--accent-primary); font-size: 1.1rem; margin-top: 0.05rem;">2.</span>
                            <span style="color: var(--text-secondary); font-size: 0.9rem;">Inicia sesión en la ventana del navegador.</span>
                        </div>
                        <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                            <span style="color: var(--accent-primary); font-size: 1.1rem; margin-top: 0.05rem;">3.</span>
                            <span style="color: var(--text-secondary); font-size: 0.9rem;">El sistema detectará el login <strong style="color:var(--text-primary)">automáticamente</strong> y se mantendrá renovada indefinidamente en segundo plano.</span>
                        </div>
                    </div>
                </div>

                <!-- Subseccion B: Importar / Sincronizar (para Servidores Headless/VPS) -->
                <div id="rexel-sub-import" style="display:none;">
                    <p style="color: var(--text-secondary); margin: 0 0 0.75rem 0; font-size: 0.88rem; line-height: 1.6;">
                        Ideal si ejecutas el sistema en un <strong>servidor VPS remoto</strong> sin pantalla:
                    </p>
                    <div style="background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 0.65rem; padding: 0.75rem 1rem; margin-bottom: 0.75rem; font-size: 0.8rem; line-height: 1.6;">
                        <strong>¿Cómo obtener tu sesión?</strong>
                        <ul style="margin: 0.4rem 0 0 1.2rem; padding: 0; color: var(--text-secondary);">
                            <li><strong>Opción A:</strong> Si ya tienes el archivo local, abre <code>scripts/storage_state.json</code>, copia todo su texto y pégalo abajo.</li>
                            <li><strong>Opción B (Directo de la web):</strong> Inicia sesión en <a href="https://www.rexelusa.com" target="_blank" style="color:var(--accent-primary); text-decoration:underline;">rexelusa.com</a> en tu navegador, presiona <kbd>F12</kbd> &rarr; Consola, pega el comando extractor y copia el JSON resultante:</li>
                        </ul>
                        <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem; align-items: center;">
                            <button type="button" class="btn-mini btn-mini-primary" onclick="copyRexelExtractorSnippet()" style="padding: 0.35rem 0.75rem; font-size: 0.75rem;">
                                📋 Copiar Comando Extractor para Consola F12
                            </button>
                            <span id="copy-snippet-msg" style="font-size: 0.75rem; color: #10b981; display: none;">¡Copiado al portapapeles!</span>
                        </div>
                    </div>
                    <textarea id="rexel-import-json" placeholder='Pega aquí el JSON de sesión (storage_state)...' style="width: 100%; height: 95px; background: var(--bg-input); border: 1px solid var(--border-subtle); border-radius: 0.6rem; color: var(--text-primary); font-family: 'Consolas', monospace; font-size: 0.75rem; padding: 0.6rem; resize: vertical; margin-bottom: 0.75rem;"></textarea>
                    <div style="display: flex; justify-content: flex-end;">
                        <button type="button" id="btn-do-import" onclick="importRexelSession()" class="btn-primary" style="font-size: 0.85rem; padding: 0.5rem 1rem;">
                            📥 Sincronizar Sesión
                        </button>
                    </div>
                </div>

                <div id="rexel-start-error" style="display:none;" class="alert-box alert-error" style="margin-top:0.75rem;"></div>
            </div>

            <!-- PANEL 2: Esperando (browser abierto) -->
            <div id="rexel-panel-waiting" style="display:none; text-align: center;">
                <div class="login-status-panel">
                    <span class="waiting-icon">🌐</span>
                    <p style="color: var(--text-primary); font-weight: 700; margin: 0 0 0.4rem 0;">
                        Navegador abierto
                    </p>
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin: 0;">
                        Inicia sesión en la ventana que se abrió<span class="waiting-dots"></span>
                    </p>
                    <div class="login-progress-bar-wrap">
                        <div class="login-progress-bar" id="login-progress-bar"></div>
                    </div>
                </div>
                <!-- Log en tiempo real -->
                <div style="margin-top: 0.75rem; text-align: left;">
                    <div style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted);
                                text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.3rem;">
                        Monitor en tiempo real
                    </div>
                    <div id="rexel-live-log"
                         style="background: var(--bg-input); border: 1px solid var(--border-subtle);
                                border-radius: 0.5rem; padding: 0.75rem; font-family: 'Consolas', monospace;
                                font-size: 0.72rem; color: var(--text-secondary); height: 110px;
                                overflow-y: auto; text-align: left; line-height: 1.5;">
                        Iniciando...
                    </div>
                </div>
                <div class="countdown-timer">
                    Tiempo restante: <span id="countdown-value">5:00</span>
                </div>
            </div>

            <!-- PANEL 3: Exito -->
            <div id="rexel-panel-success" style="display:none; text-align: center;">
                <span class="success-checkmark">✅</span>
                <h3 style="color: #10b981; margin: 0 0 0.4rem 0; font-weight: 800;">¡Sesión guardada!</h3>
                <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
                    Rexel conectado correctamente. Los scrapers ya pueden funcionar.
                </p>
            </div>

            <!-- PANEL 4: Error -->
            <div id="rexel-panel-error" style="display:none; text-align: center;">
                <span style="font-size: 3rem; display: block; margin-bottom: 0.75rem;">❌</span>
                <h3 style="color: #ef4444; margin: 0 0 0.4rem 0; font-weight: 800;">No se pudo conectar</h3>
                <p id="rexel-error-detail" style="color: var(--text-secondary); margin: 0 0 1rem 0; font-size: 0.9rem;">
                    El proceso terminó sin detectar login exitoso.
                </p>
            </div>

        </div><!-- /modal-body -->

        <!-- Footer: Botones dinamicos -->
        <div class="modal-footer" id="rexel-modal-footer" style="padding: 1.1rem 1.5rem; background: var(--bg-app); border-top: 1px solid var(--border-subtle);">
            <!-- Estado connected -->
            <div id="rexel-footer-connected" style="display:none; justify-content:space-between; width:100%; align-items:center; gap:0.75rem;">
                <button type="button" class="btn-secondary" id="btn-disconnect-rexel" onclick="disconnectRexelSession()" style="padding: 0.6rem 0.95rem; font-size: 0.82rem; font-weight: 600; cursor: pointer; border-radius: 0.65rem; display: inline-flex; align-items: center; gap: 0.35rem; color: #f87171; border-color: rgba(239, 68, 68, 0.35); background: rgba(239, 68, 68, 0.06);">
                    <span>🗑️</span> Cerrar Sesión
                </button>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <button type="button" class="btn-secondary" onclick="switchToRexelReauth()" title="Reabrir navegador para inicio manual" style="padding: 0.6rem 0.95rem; font-size: 0.82rem; font-weight: 600; border-radius: 0.65rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                        <span>🔄</span> Re-abrir
                    </button>
                    <button type="button" class="btn-primary" id="btn-refresh-token" onclick="refreshRexelToken()" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-color: #059669; font-weight: 700; padding: 0.6rem 1.15rem; font-size: 0.82rem; border-radius: 0.65rem; display: inline-flex; align-items: center; gap: 0.4rem; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3);">
                        <span>⚡</span> Renovar Token Ahora
                    </button>
                </div>
            </div>
            <!-- Estado ready -->
            <div id="rexel-footer-ready" style="display:flex; gap:0.75rem; width:100%; justify-content:flex-end; align-items:center;">
                <button type="button" class="btn-secondary" onclick="closeRexelModal()" style="padding: 0.6rem 1.1rem; border-radius: 0.65rem; font-size: 0.85rem;">Cancelar</button>
                <button type="button" class="btn-primary" id="btn-open-chrome" onclick="startRexelLogin()" style="padding: 0.6rem 1.3rem; border-radius: 0.65rem; font-size: 0.85rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.45rem;">
                    <span>🌐</span> Abrir Navegador &rarr;
                </button>
            </div>
            <!-- Estado waiting -->
            <div id="rexel-footer-waiting" style="display:none; width:100%; justify-content:flex-end; align-items:center;">
                <button type="button" class="btn-danger" onclick="cancelRexelLogin()" style="padding: 0.6rem 1.1rem; border-radius: 0.65rem; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.4rem;">
                    <span>✕</span> Cancelar proceso
                </button>
            </div>
            <!-- Estado success -->
            <div id="rexel-footer-success" style="display:none; width:100%; justify-content:flex-end; align-items:center;">
                <button type="button" class="btn-primary" onclick="closeRexelModal(); refreshSessionBadge();" style="padding: 0.6rem 1.3rem; border-radius: 0.65rem; font-size: 0.85rem; font-weight: 700;">
                    Listo / Cerrar
                </button>
            </div>
            <!-- Estado error -->
            <div id="rexel-footer-error" style="display:none; gap:0.75rem; width:100%; justify-content:flex-end; align-items:center;">
                <button type="button" class="btn-secondary" onclick="closeRexelModal()" style="padding: 0.6rem 1.1rem; border-radius: 0.65rem; font-size: 0.85rem;">Cerrar</button>
                <button type="button" class="btn-primary" onclick="resetRexelModal()" style="padding: 0.6rem 1.3rem; border-radius: 0.65rem; font-size: 0.85rem; font-weight: 700;">Reintentar</button>
            </div>
        </div>

    </div><!-- /modal-content -->
</div><!-- /modal-rexel-session -->

<!-- ============================================================
     MODAL: ADVERTENCIA DE SESIÓN NO INICIADA
     ============================================================ -->
<div id="modal-rexel-warning" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="rexel-warning-title">
    <div class="modal-content" style="max-width: 520px; box-shadow: 0 20px 40px rgba(0,0,0,0.5); border: 1px solid rgba(245, 158, 11, 0.35);">
        <!-- Header -->
        <div class="modal-header" style="border-bottom: 1px solid var(--border-subtle); padding-bottom: 0.85rem;">
            <h3 id="rexel-warning-title" style="display: flex; align-items: center; gap: 0.6rem; color: #f59e0b; margin: 0; font-size: 1.15rem; font-weight: 800;">
                <span style="font-size: 1.3rem;">⚠️</span> Sesión de Rexel No Detectada
            </h3>
            <button type="button" class="btn-icon-only" onclick="closeRexelWarningModal()" title="Cerrar">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <!-- Body -->
        <div class="modal-body" style="padding: 1.25rem 0;">
            <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 0.85rem; padding: 1.1rem; margin-bottom: 1.25rem;">
                <div style="font-weight: 700; color: var(--text-primary); font-size: 0.95rem; margin-bottom: 0.35rem; display: flex; align-items: center; gap: 0.45rem;">
                    <span>🔐</span> Autenticación requerida para mejores resultados
                </div>
                <p style="margin: 0; font-size: 0.86rem; color: var(--text-secondary); line-height: 1.6;">
                    Estás a punto de iniciar la extracción para <strong id="warning-target-catalog" style="color: var(--accent-primary);">este catálogo</strong> sin una sesión activa detectada en Rexel USA.
                </p>
            </div>

            <div style="font-size: 0.85rem; color: var(--text-secondary); line-height: 1.6; margin-bottom: 1rem;">
                <div style="font-weight: 700; color: var(--text-primary); margin-bottom: 0.45rem;">¿Qué sucede si continúas sin iniciar sesión?</div>
                <ul style="margin: 0 0 0 1.25rem; padding: 0;">
                    <li style="margin-bottom: 0.4rem;">No se obtendrán los <strong>precios con descuento de contratista</strong> asociados a la cuenta.</li>
                    <li style="margin-bottom: 0.4rem;">Rexel podría solicitar inicio de sesión en el navegador e interrumpir la extracción.</li>
                </ul>
            </div>

            <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0; text-align: center;">
                ¿Deseas iniciar sesión en Rexel primero o continuar de todos modos?
            </p>
        </div>

        <!-- Footer -->
        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; border-top: 1px solid var(--border-subtle); padding-top: 1rem;">
            <button type="button" class="btn-secondary" onclick="closeRexelWarningModal()" style="font-size: 0.85rem; padding: 0.6rem 1rem;">
                Cancelar
            </button>
            <div style="display: flex; gap: 0.6rem; align-items: center;">
                <button type="button" class="btn-secondary" onclick="proceedPendingScraperForm()" style="font-size: 0.85rem; padding: 0.6rem 1rem; border-color: rgba(245, 158, 11, 0.4); color: #f59e0b;">
                    Continuar de todos modos
                </button>
                <button type="button" class="btn-primary" onclick="connectRexelFromWarning()" style="font-size: 0.85rem; font-weight: 700; padding: 0.6rem 1.2rem; display: flex; align-items: center; gap: 0.45rem;">
                    <span>🔐</span> Conectar Rexel Primero
                </button>
            </div>
        </div>
    </div>
</div>



<script>
// ================================================================
// REXEL SESSION MANAGER — JavaScript
// ================================================================

const CSRF_TOKEN    = '<?php echo $_SESSION["csrf_token"]; ?>';
const SESSION_MGR   = 'session_manager.php';
const LOGIN_TIMEOUT = 300; // segundos

let pollInterval    = null;
let countdownTimer  = null;
let countdownSecs   = LOGIN_TIMEOUT;
let loginPid        = null;
let progressInterval = null;
let latestRexelSession = null;

// ---- Control de Modo de Navegador (Visible vs Silencioso) para Scrapers ----
let currentBrowserHeadless = (localStorage.getItem('rexel_browser_headless') === '1'); // Default: Modo Visible (false)

function setScraperBrowserMode(isHeadless) {
    currentBrowserHeadless = isHeadless;
    localStorage.setItem('rexel_browser_headless', isHeadless ? '1' : '0');
    updateBrowserModeUI();
}

function updateBrowserModeUI() {
    const btnVisible = document.getElementById('btn-browser-visible');
    const btnHeadless = document.getElementById('btn-browser-headless');
    const badge = document.getElementById('mode-current-badge');

    if (btnVisible && btnHeadless) {
        if (!currentBrowserHeadless) {
            // Modo Visible activo
            btnVisible.style.background = 'rgba(59, 130, 246, 0.25)';
            btnVisible.style.borderColor = '#3b82f6';
            btnVisible.style.color = '#60a5fa';
            btnVisible.style.fontWeight = '700';

            btnHeadless.style.background = 'var(--bg-panel)';
            btnHeadless.style.borderColor = 'var(--border-subtle)';
            btnHeadless.style.color = 'var(--text-secondary)';
            btnHeadless.style.fontWeight = '500';

            if (badge) {
                badge.textContent = 'Visible';
                badge.style.background = 'rgba(59, 130, 246, 0.15)';
                badge.style.color = '#60a5fa';
                badge.style.borderColor = 'rgba(59, 130, 246, 0.3)';
            }
        } else {
            // Modo Silencioso activo
            btnHeadless.style.background = 'rgba(251, 90, 58, 0.25)';
            btnHeadless.style.borderColor = 'var(--accent-primary)';
            btnHeadless.style.color = 'var(--accent-primary)';
            btnHeadless.style.fontWeight = '700';

            btnVisible.style.background = 'var(--bg-panel)';
            btnVisible.style.borderColor = 'var(--border-subtle)';
            btnVisible.style.color = 'var(--text-secondary)';
            btnVisible.style.fontWeight = '500';

            if (badge) {
                badge.textContent = 'Silencioso';
                badge.style.background = 'rgba(251, 90, 58, 0.15)';
                badge.style.color = 'var(--accent-primary)';
                badge.style.borderColor = 'rgba(251, 90, 58, 0.3)';
            }
        }
    }

    // Actualizar todos los inputs hidden de los formularios en la página
    document.querySelectorAll('.input-headless-val').forEach(input => {
        input.value = currentBrowserHeadless ? '1' : '0';
    });
}

// ---- Badge de sesion ----
async function refreshSessionBadge() {
    const badge = document.getElementById('rexel-session-badge');
    const text  = document.getElementById('rexel-badge-text');
    const heroDot = document.getElementById('hero-rexel-dot');
    const heroStatus = document.getElementById('hero-rexel-status');
    if (!badge || !text) return;

    badge.className = 'session-status-badge status-loading';
    text.textContent = 'Verificando...';
    badge.onclick = openRexelModal;
    badge.style.cursor = 'pointer';

    try {
        const res  = await fetch(SESSION_MGR + '?action=status', { cache: 'no-store' });
        const data = await res.json();
        latestRexelSession = data;

        badge.style.pointerEvents = '';
        badge.onclick = openRexelModal;
        badge.style.cursor = 'pointer';

        if (data.valid) {
            if (data.status === 'expiring_soon') {
                badge.className = 'session-status-badge status-warning';
                text.textContent = `Rexel · Expira en ${data.days_left}d`;
                badge.title = data.message + ' (Clic para gestionar)';
                if (heroDot) heroDot.className = 'status-dot status-dot-warning';
                if (heroStatus) heroStatus.textContent = `Expira en ${data.days_left}d`;
            } else {
                badge.className = 'session-status-badge status-ok';
                text.textContent = `Rexel Conectado · ${data.days_left}d`;
                badge.title = data.message + ' (Clic para gestionar)';
                if (heroDot) heroDot.className = 'status-dot status-dot-active';
                if (heroStatus) heroStatus.textContent = `Conectado (${data.days_left}d)`;
            }
        } else {
            badge.className = 'session-status-badge status-error';
            if (data.status === 'missing') {
                text.textContent = 'Rexel · Sin sesión';
                badge.title = 'Clic para conectar Rexel';
                if (heroDot) heroDot.className = 'status-dot status-dot-inactive';
                if (heroStatus) heroStatus.textContent = 'Sin sesión (Clic)';
            } else {
                text.textContent = 'Rexel · Sesión expirada';
                badge.title = data.message + ' (Clic para renovar)';
                if (heroDot) heroDot.className = 'status-dot status-dot-error';
                if (heroStatus) heroStatus.textContent = 'Expirada (Renovar)';
            }
        }
    } catch (e) {
        badge.className = 'session-status-badge status-error';
        text.textContent = 'Rexel · Error';
        badge.style.pointerEvents = '';
        badge.onclick = openRexelModal;
        badge.style.cursor = 'pointer';
        if (heroDot) heroDot.className = 'status-dot status-dot-error';
        if (heroStatus) heroStatus.textContent = 'Error';
    }
}

// ---- Abrir / cerrar modal ----
function openRexelModal() {
    resetRexelModal();
    const title = document.getElementById('rexel-modal-title');
    if (latestRexelSession && latestRexelSession.valid) {
        showPanel('connected');
        if (title) title.textContent = '🔐 Sesión Activa de Rexel';
        const timeEl = document.getElementById('rexel-connected-time');
        const msgEl = document.getElementById('rexel-connected-msg');
        const pillEl = document.getElementById('rexel-connected-status-pill');
        if (timeEl) timeEl.textContent = `${latestRexelSession.days_left} días y ${latestRexelSession.hours_left || 0} horas`;
        if (msgEl) msgEl.textContent = latestRexelSession.message || 'Autenticación corporativa activa para extracción de precios y catálogos.';
        if (pillEl) pillEl.textContent = latestRexelSession.status === 'expiring_soon' ? 'Expira pronto' : 'Conectado';
    } else {
        showPanel('ready');
        setStep(1);
        if (title) title.textContent = '🔐 Conectar Rexel';
    }
    document.getElementById('modal-rexel-session').classList.add('show');
}

function closeRexelModal() {
    document.getElementById('modal-rexel-session').classList.remove('show');
    stopPolling();
}

function switchToRexelReauth() {
    const title = document.getElementById('rexel-modal-title');
    if (title) title.textContent = '🔄 Renovar Sesión · Rexel USA';
    showPanel('ready');
    setStep(1);
}

// ---- Alternar metodo de login (Navegador vs Importar) ----
function switchRexelMethod(method) {
    const subBrowser = document.getElementById('rexel-sub-browser');
    const subImport = document.getElementById('rexel-sub-import');
    const tabBrowser = document.getElementById('tab-btn-browser');
    const tabImport = document.getElementById('tab-btn-import');
    const readyFooter = document.getElementById('rexel-footer-ready');
    const btnOpenChrome = document.getElementById('btn-open-chrome');

    if (method === 'import') {
        if (subBrowser) subBrowser.style.display = 'none';
        if (subImport) subImport.style.display = 'block';
        if (tabBrowser) {
            tabBrowser.style.background = 'transparent';
            tabBrowser.style.color = 'var(--text-muted)';
            tabBrowser.style.fontWeight = '600';
        }
        if (tabImport) {
            tabImport.style.background = 'var(--bg-card-hover)';
            tabImport.style.color = 'var(--text-primary)';
            tabImport.style.fontWeight = '700';
        }
        if (btnOpenChrome) btnOpenChrome.style.display = 'none';
    } else {
        if (subBrowser) subBrowser.style.display = 'block';
        if (subImport) subImport.style.display = 'none';
        if (tabBrowser) {
            tabBrowser.style.background = 'var(--bg-card-hover)';
            tabBrowser.style.color = 'var(--text-primary)';
            tabBrowser.style.fontWeight = '700';
        }
        if (tabImport) {
            tabImport.style.background = 'transparent';
            tabImport.style.color = 'var(--text-muted)';
            tabImport.style.fontWeight = '600';
        }
        if (btnOpenChrome) btnOpenChrome.style.display = '';
    }
}

// ---- Renovar Token OAuth en Segundo Plano (Headless) ----
async function refreshRexelToken() {
    const btn = document.getElementById('btn-refresh-token');
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '⏳ Renovando en background...';
    }
    try {
        const form = new FormData();
        form.append('action', 'refresh_token');
        form.append('csrf_token', CSRF_TOKEN);

        const res = await fetch(SESSION_MGR, { method: 'POST', body: form, cache: 'no-store' });
        const data = await res.json();

        if (data.success) {
            await refreshSessionBadge();
            const timeEl = document.getElementById('rexel-connected-time');
            const msgEl = document.getElementById('rexel-connected-msg');
            if (timeEl) timeEl.textContent = `${data.days_left} días y ${data.hours_left || 0} horas`;
            if (msgEl) msgEl.textContent = `¡Token renovado con éxito! (Tardó ${data.elapsed_seconds || 4}s)`;
            alert('✅ Token renovado exitosamente en segundo plano.\nNueva vigencia: ' + data.days_left + ' días.');
        } else {
            alert('⚠️ No se pudo renovar automáticamente:\n' + (data.message || data.error));
        }
    } catch (e) {
        alert('Error al renovar token: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    }
}

// ---- Copiar snippet extractor para navegador web ----
function copyRexelExtractorSnippet() {
    const snippet = `(() => {
  const ls = [];
  for (let i = 0; i < localStorage.length; i++) {
    const k = localStorage.key(i);
    ls.push({ name: k, value: localStorage.getItem(k) });
  }
  const cookies = document.cookie.split('; ').filter(Boolean).map(c => {
    const [name, ...v] = c.split('=');
    return { name: name.trim(), value: v.join('='), domain: '.rexelusa.com', path: '/' };
  });
  const state = { cookies: cookies, origins: [{ origin: 'https://www.rexelusa.com', localStorage: ls }] };
  copy(JSON.stringify(state));
  alert('¡Sesión copiada al portapapeles! Ahora pégala en el cuadro de texto del panel VPS.');
})();`;

    navigator.clipboard.writeText(snippet).then(() => {
        const msg = document.getElementById('copy-snippet-msg');
        if (msg) {
            msg.style.display = 'inline';
            setTimeout(() => { msg.style.display = 'none'; }, 3500);
        }
    }).catch(() => {
        prompt('Copia manualmente este código y ejecútalo en la consola F12 de rexelusa.com:', snippet);
    });
}

// ---- Importar / Sincronizar Sesion Manualmente ----
async function importRexelSession() {
    const jsonInput = document.getElementById('rexel-import-json');
    const raw = jsonInput ? jsonInput.value.trim() : '';
    if (!raw) {
        alert('Por favor pega el JSON de sesión (storage_state) primero.');
        return;
    }
    const btn = document.getElementById('btn-do-import');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Importando...';
    }
    try {
        const form = new FormData();
        form.append('action', 'import_session');
        form.append('session_json', raw);
        form.append('csrf_token', CSRF_TOKEN);

        const res = await fetch(SESSION_MGR, { method: 'POST', body: form, cache: 'no-store' });
        const data = await res.json();

        if (data.success) {
            await refreshSessionBadge();
            openRexelModal();
            alert('✅ ' + data.message);
        } else {
            alert('❌ ' + (data.message || 'Error al importar sesión.'));
        }
    } catch (e) {
        alert('Error de red al importar: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = '📥 Sincronizar Sesión';
        }
    }
}

// ---- Reset al estado inicial ----
function resetRexelModal() {
    switchRexelMethod('browser');
    showPanel('ready');
    setStep(1);
    const errBox = document.getElementById('rexel-start-error');
    if (errBox) {
        errBox.style.display = 'none';
        errBox.textContent = '';
        errBox.className = 'alert-box alert-error';
    }
    stopPolling();
}

// ---- Desconectar / Cerrar sesion ----
async function disconnectRexelSession() {
    const ok = await showAppConfirm('¿Estás seguro de que deseas cerrar la sesión y borrar las cookies almacenadas de Rexel USA?', {
        title: 'Cerrar Sesión de Rexel',
        confirmText: '🗑️ Sí, Cerrar Sesión',
        cancelText: 'Cancelar',
        isDanger: true
    });
    if (!ok) {
        return;
    }
    const btn = document.getElementById('btn-disconnect-rexel');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Borrando cookies...';
    }
    try {
        const form = new FormData();
        form.append('action', 'clear_session');
        form.append('csrf_token', CSRF_TOKEN);

        const res = await fetch(SESSION_MGR, { method: 'POST', body: form, cache: 'no-store' });
        const data = await res.json();

        if (data.success) {
            latestRexelSession = null;
            await refreshSessionBadge();
            showPanel('ready');
            setStep(1);
            const title = document.getElementById('rexel-modal-title');
            if (title) title.textContent = '🔐 Conectar Rexel';
            const errBox = document.getElementById('rexel-start-error');
            if (errBox) {
                errBox.className = 'alert-box alert-success';
                errBox.textContent = '✅ Sesión cerrada y cookies eliminadas correctamente.';
                errBox.style.display = 'block';
            }
        } else {
            alert('Error al cerrar sesión: ' + (data.error || 'Desconocido'));
        }
    } catch (e) {
        alert('Error de red al cerrar sesión: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '🗑️ Cerrar Sesión (Borrar Cookies)';
        }
    }
}

// ---- Iniciar login ----
async function startRexelLogin() {
    const btn = document.getElementById('btn-open-chrome');
    btn.disabled = true;
    btn.textContent = 'Iniciando...';

    try {
        const form = new FormData();
        form.append('action', 'start_login');
        form.append('csrf_token', CSRF_TOKEN);

        const res  = await fetch(SESSION_MGR, { method: 'POST', body: form, cache: 'no-store' });
        const data = await res.json();

        if (data.success) {
            loginPid = data.pid;
            showPanel('waiting');
            setStep(2);
            startCountdown();
            startProgressBar();
            startPolling();
        } else {
            showStartError('No se pudo lanzar el proceso. ' + (data.error || ''));
        }
    } catch (e) {
        showStartError('Error de red: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '🌐 Abrir Navegador &rarr;';
    }
}

// ---- Cancelar proceso ----
function cancelRexelLogin() {
    stopPolling();
    showPanel('error');
    document.getElementById('rexel-error-detail').textContent = 'Proceso cancelado por el usuario.';
    setStep(1);
}

// ---- Polling para verificar resultado ----
function startPolling() {
    pollInterval = setInterval(async () => {
        try {
            const url = SESSION_MGR + '?action=check_login' + (loginPid ? '&pid=' + loginPid : '');
            const res  = await fetch(url, { cache: 'no-store' });
            const data = await res.json();

            // Actualizar el log en tiempo real
            const logEl = document.getElementById('rexel-live-log');
            if (logEl && data.log) {
                logEl.textContent = data.log;
                logEl.scrollTop = logEl.scrollHeight; // auto-scroll al final
            }

            if (data.done) {
                stopPolling();
                if (data.success) {
                    showPanel('success');
                    setStep(3, true);
                    refreshSessionBadge();
                } else {
                    // En caso de error, mostrar el log en el panel de error
                    const errDetail = document.getElementById('rexel-error-detail');
                    if (errDetail && data.log) {
                        errDetail.innerHTML = 'El proceso terminó sin detectar login exitoso.<br>'
                            + '<small style="font-family:monospace; font-size:0.75rem; opacity:0.7;">'
                            + data.log.split('\n').pop()
                            + '</small>';
                    }
                    showPanel('error');
                    setStep(1);
                }
            }
        } catch (e) { /* ignorar errores de red transitorios */ }
    }, 2500);
}

function stopPolling() {
    clearInterval(pollInterval);
    clearInterval(countdownTimer);
    clearInterval(progressInterval);
    pollInterval = null;
    countdownTimer = null;
    progressInterval = null;
}

// ---- Countdown ----
function startCountdown() {
    countdownSecs = LOGIN_TIMEOUT;
    updateCountdownDisplay();
    countdownTimer = setInterval(() => {
        countdownSecs--;
        updateCountdownDisplay();
        if (countdownSecs <= 0) {
            stopPolling();
            showPanel('error');
            document.getElementById('rexel-error-detail').textContent = 'Tiempo agotado. El login no se completó a tiempo.';
        }
    }, 1000);
}

function updateCountdownDisplay() {
    const el = document.getElementById('countdown-value');
    if (!el) return;
    const m = Math.floor(countdownSecs / 60).toString().padStart(1, '0');
    const s = (countdownSecs % 60).toString().padStart(2, '0');
    el.textContent = m + ':' + s;
}

// ---- Barra de progreso ----
function startProgressBar() {
    const bar = document.getElementById('login-progress-bar');
    if (!bar) return;
    bar.style.width = '0%';
    let elapsed = 0;
    progressInterval = setInterval(() => {
        elapsed++;
        const pct = Math.min((elapsed / LOGIN_TIMEOUT) * 100, 98);
        bar.style.width = pct + '%';
    }, 1000);
}

// ---- Helpers de UI ----
function showPanel(name) {
    ['connected', 'ready', 'waiting', 'success', 'error'].forEach(p => {
        const panel = document.getElementById('rexel-panel-' + p);
        if (panel) panel.style.display = (p === name) ? '' : 'none';
    });
    ['connected', 'ready', 'waiting', 'success', 'error'].forEach(p => {
        const el = document.getElementById('rexel-footer-' + p);
        if (el) el.style.display = (p === name) ? 'flex' : 'none';
    });
    const stepper = document.getElementById('rexel-step-indicator');
    if (stepper) {
        stepper.style.display = (name === 'connected') ? 'none' : 'flex';
    }
}

function setStep(active, allDone = false) {
    for (let i = 1; i <= 3; i++) {
        const item = document.getElementById('step-' + i);
        if (!item) continue;
        item.classList.remove('active', 'done');
        if (allDone || i < active) item.classList.add('done');
        else if (i === active)     item.classList.add('active');
    }
    for (let i = 1; i <= 2; i++) {
        const line = document.getElementById('step-line-' + i);
        if (!line) continue;
        line.classList.remove('done', 'active');
        if (allDone || i < active) line.classList.add('done');
        else if (i === active)     line.classList.add('active');
    }
}

function showStartError(msg) {
    const el = document.getElementById('rexel-start-error');
    el.textContent = msg;
    el.style.display = 'block';
}

// ---- Control de Modal de Advertencia de Sesión de Rexel ----
let pendingScraperForm = null;

function openRexelWarningModal(targetDesc) {
    const label = document.getElementById('warning-target-catalog');
    if (label) label.textContent = targetDesc || 'el catálogo';
    const m = document.getElementById('modal-rexel-warning');
    if (m) m.classList.add('show');
}

function closeRexelWarningModal() {
    const m = document.getElementById('modal-rexel-warning');
    if (m) m.classList.remove('show');
    if (pendingScraperForm) {
        pendingScraperForm.removeAttribute('data-confirmed');
        pendingScraperForm = null;
    }
}

function proceedPendingScraperForm() {
    if (pendingScraperForm) {
        const formToSubmit = pendingScraperForm;
        pendingScraperForm = null;
        closeRexelWarningModal();
        formToSubmit.setAttribute('data-confirmed', 'true');
        formToSubmit.submit();
    }
}

function connectRexelFromWarning() {
    closeRexelWarningModal();
    openRexelModal();
}

// ---- Cerrar modales al hacer clic fuera ----
document.getElementById('modal-rexel-session').addEventListener('click', function(e) {
    if (e.target === this) closeRexelModal();
});

const modalRexelWarning = document.getElementById('modal-rexel-warning');
if (modalRexelWarning) {
    modalRexelWarning.addEventListener('click', function(e) {
        if (e.target === this) closeRexelWarningModal();
    });
}

// ---- Interceptar envíos de scrapers si no hay sesión iniciada ----
document.addEventListener('submit', function(e) {
    const form = e.target;
    if (!form || !form.action || !form.action.includes('ejecutar.php')) return;
    const scriptInput = form.querySelector('input[name="script"]');
    if (!scriptInput || !scriptInput.value.startsWith('update_')) return;

    // Si ya fue confirmado explícitamente en el modal de advertencia, dejar pasar
    if (form.getAttribute('data-confirmed') === 'true') {
        return;
    }

    const scriptVal = scriptInput.value;
    const catName = (scriptVal === 'update_all') ? 'todos los catálogos' : scriptVal.replace('update_', '').toUpperCase();

    // Si la sesión no es válida, no existe o ha expirado, mostrar advertencia
    if (!latestRexelSession || !latestRexelSession.valid) {
        e.preventDefault();
        pendingScraperForm = form;

        // Si el estado aún se está cargando (null), hacer consulta instantánea antes de abrir modal
        if (latestRexelSession === null) {
            fetch(SESSION_MGR + '?action=status', { cache: 'no-store' })
                .then(r => r.json())
                .then(data => {
                    latestRexelSession = data;
                    if (!data.valid) {
                        openRexelWarningModal(catName);
                    } else {
                        form.setAttribute('data-confirmed', 'true');
                        form.submit();
                    }
                }).catch(() => {
                    openRexelWarningModal(catName);
                });
        } else {
            openRexelWarningModal(catName);
        }
    }
});

// ---- Inicializar al cargar la pagina ----
document.addEventListener('DOMContentLoaded', function() {
    updateBrowserModeUI();
    refreshSessionBadge();
});
</script>
</html>
