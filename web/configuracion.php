<?php
session_start();

require_once __DIR__ . '/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$storage_state_file = realpath(__DIR__ . '/../scripts/storage_state.json');
if ($storage_state_file && file_exists($storage_state_file) && empty($_SESSION['rexel_email'])) {
    $_SESSION['rexel_email'] = 'rexel_authenticated';
}

// --- AJAX HANDLER: Obtener Detalle del Diccionario ---
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_cat_items') {
    // Retornamos JSON puro y terminamos la ejecución
    header('Content-Type: application/json');
    
    $cat = $_GET['category'] ?? '';
    try {
        $db = get_db_connection();
        $cutoff_recent = date('Y-m-d H:i:s', strtotime('-3 minutes'));
        $stmt = $db->prepare("
            SELECT cw.id, mp.nombre_procore, cw.nombre_web, cw.precio_actual, cw.ultima_actualizacion, cw.url_origen,
            (CASE WHEN cw.ultima_actualizacion >= ? THEN 1 ELSE 0 END) as is_recent
            FROM catalogo_web cw
            LEFT JOIN mapeo_procore mp ON cw.id = mp.web_item_id
            WHERE cw.categoria = ?
            ORDER BY cw.precio_actual DESC, mp.nombre_procore ASC
        ");
        $stmt->execute([$cutoff_recent, $cat]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

// --- AJAX HANDLER: Eliminar Ítem del Diccionario ---
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_item') {
    header('Content-Type: application/json');
    
    $posted_token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $posted_token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado: Token CSRF inválido']);
        exit;
    }

    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
    
    if (!$item_id) { echo json_encode(['error' => 'ID inválido']); exit; }

    try {
        $db = get_db_connection();
        // Borrar de catalogo_web (mapeo_procore se borra automáticamente por ON DELETE CASCADE)
        $db->prepare("DELETE FROM catalogo_web WHERE id = ?")->execute([$item_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

// --- HANDLER PARA GUARDAR TAX (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_tax') {
    $posted_token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $posted_token)) {
        header("Location: configuracion.php?error=Token+CSRF+inválido");
        exit;
    }
    try {
        $tax_rate = $_POST['tax_rate'] ?? '6.5';
        $tax_bypass = isset($_POST['tax_bypass']) ? '1' : '0';

        set_config_value('tax_rate', $tax_rate);
        set_config_value('tax_bypass', $tax_bypass);

        header("Location: configuracion.php?success=Configuración+de+Tax+actualizada");
        exit;
    } catch (Exception $e) {
        $error_message = "Error al guardar Tax: " . $e->getMessage();
    }
}

// --- DB CONNECTION & FETCH ---
$urls_by_category = [];
$config_procore = null;
$config_tax_rate = '6.5'; // Default
$config_tax_bypass = false; // Default
$dictionary_stats = []; // Estadísticas del diccionario

try {
    $pdo = get_db_connection();

    // Obtener URLs
    $stmt = $pdo->query("SELECT id, category, url, is_active FROM scraping_urls ORDER BY category, id");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $urls_by_category[$row['category']][] = $row;
    }

    // Obtener Credenciales Guardadas (si existen)
    // Primero verificamos si la tabla existe para evitar errores en instalaciones nuevas
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_config (key TEXT PRIMARY KEY, value TEXT)");
    
    $stmt = $pdo->query("SELECT key, value FROM app_config WHERE key IN ('procore_email', 'tax_rate', 'tax_bypass')");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['key'] === 'procore_email') $config_procore = $row['value'];
        if ($row['key'] === 'tax_rate') $config_tax_rate = $row['value'];
        if ($row['key'] === 'tax_bypass') $config_tax_bypass = ($row['value'] === '1');
    }

    // Obtener Estadísticas del Diccionario (si existe la tabla)
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='catalogo_web'");
    if ($stmt->fetch()) {
        $stmt = $pdo->query("
            SELECT 
                categoria, 
                COUNT(*) as total_items, 
                SUM(CASE WHEN precio_actual > 0 THEN 1 ELSE 0 END) as items_found,
                MAX(ultima_actualizacion) as last_update
            FROM catalogo_web 
            GROUP BY categoria
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $dictionary_stats[$row['categoria']] = $row;
        }
    }

    $cat_meta = [
        'EMT' => ['icon' => '⚡', 'color' => '#3b82f6', 'desc' => 'Tuberías y conducciones metálicas EMT'],
        'PVC' => ['icon' => '🛡️', 'color' => '#10b981', 'desc' => 'Tuberías y accesorios plásticos Schedule 40/80'],
        'Wires' => ['icon' => '🔌', 'color' => '#f59e0b', 'desc' => 'Conductores de cobre, aluminio y cableado'],
        'FUSES' => ['icon' => '💥', 'color' => '#ef4444', 'desc' => 'Fusibles industriales y protección eléctrica']
    ];

} catch (Exception $e) {
    $error_message = "Error al conectar o leer la base de datos: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Links</title>
    <link rel="stylesheet" href="../css/style.css?v=<?php echo filemtime(__DIR__ . '/../css/style.css'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="app-dialogs.js"></script>
</head>
<body>
    <!-- Navbar de Configuración -->
    <nav class="navbar">
        <div class="logo-container">
            <a href="index.php" style="text-decoration: none; color: inherit; display: flex; flex-direction: column; align-items: flex-start;">
                <img src="../css/logo-text.png" alt="Brightronix Logo" class="logo-full">
                <span class="app-subtitle">Catalog Updater</span>
            </a>
        </div>
        <div class="nav-actions">
            <a href="index.php" class="btn-back-nav" title="Volver al Panel Principal">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                <span>Volver al Panel</span>
            </a>
        </div>
    </nav>

    <div class="main-content">
        <div class="workspace" style="max-width: 1400px; width: 96%; margin: 1.5rem auto; padding: 0;">
            <div class="report-header-banner" style="margin-bottom: 2rem;">
                <div>
                    <span class="report-subtitle-badge">Configuración Global & Ajustes</span>
                    <h2 class="report-main-title">Gestión de Catálogos, Enlaces e Impuestos</h2>
                    <p class="report-desc-text">
                        Administra las categorías de productos, enlaces objetivo de Rexel USA, credenciales corporativas y reglas de cálculo de impuestos.
                    </p>
                </div>
                <div>
                    <button class="btn-primary" onclick="openModal('modal-add-category')" style="padding: 0.85rem 1.6rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.5rem; box-shadow: 0 4px 15px rgba(251, 90, 58, 0.35);">
                        <span>➕</span>
                        <span>Nueva Categoría</span>
                    </button>
                </div>
            </div>

            <?php
            // Clasificación de errores para alertas informativas y amigables
            $flash_error = null;
            if (!empty($_SESSION['flash_error'])) {
                $flash_error = $_SESSION['flash_error'];
                unset($_SESSION['flash_error']);
            } elseif (!empty($_GET['error'])) {
                $flash_error = classify_error($_GET['error'], $pdo ?? null);
            } elseif (!empty($error_message)) {
                $flash_error = classify_error($error_message, $pdo ?? null);
            }

            $flash_success = $_GET['success'] ?? null;
            ?>

            <?php if ($flash_error): ?>
                <div class="alert-box alert-error alert-card" id="main-error-alert" role="alert" style="margin-bottom: 2rem;">
                    <div class="alert-header">
                        <div class="alert-title-wrap">
                            <span class="alert-icon">⚠️</span>
                            <h4 class="alert-heading"><?php echo htmlspecialchars($flash_error['title']); ?></h4>
                        </div>
                        <button type="button" class="alert-close-btn" onclick="this.closest('.alert-box').remove()" title="Cerrar aviso">✕</button>
                    </div>
                    <div class="alert-body">
                        <p class="alert-text"><?php echo htmlspecialchars($flash_error['message']); ?></p>
                        <?php if (!empty($flash_error['tip'])): ?>
                            <div class="alert-tip-box">
                                <span class="tip-icon">💡</span>
                                <div>
                                    <strong style="color: #fdba74;">¿Cómo resolverlo?</strong>
                                    <span style="display: block; margin-top: 0.15rem;"><?php echo htmlspecialchars($flash_error['tip']); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($flash_success): ?>
                <div class="alert-box alert-success alert-card" id="main-success-alert" role="status" style="margin-bottom: 2rem;">
                    <div class="alert-header">
                        <div class="alert-title-wrap">
                            <span class="alert-icon">✅</span>
                            <h4 class="alert-heading">Operación Exitosa</h4>
                        </div>
                        <button type="button" class="alert-close-btn" onclick="this.closest('.alert-box').remove()" title="Cerrar aviso">✕</button>
                    </div>
                    <div class="alert-body">
                        <p class="alert-text"><?php echo htmlspecialchars($flash_success); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- SECCIÓN: ESTADO DEL DICCIONARIO -->
            <div class="dashboard-card card-box config-card" style="border-radius: 1.25rem; border: 1px solid var(--border-subtle); background: var(--bg-panel); margin-bottom: 2rem;">
                <div class="category-header" style="border-bottom: 1px solid var(--border-subtle); padding-bottom: 0.85rem;">
                    <div style="display: flex; align-items: center; gap: 0.6rem;">
                        <span style="font-size: 1.25rem;">📚</span>
                        <h2 style="color: var(--text-primary); margin: 0; font-size: 1.2rem; font-weight: 700;">Estado del Diccionario (Base de Datos)</h2>
                    </div>
                </div>
                <p class="text-secondary text-small" style="margin: 0.5rem 0 1.5rem 0; line-height: 1.5;">
                    Resumen de los ítems registrados en el sistema y su estado de precios. El scraper actualiza los precios tomando como referencia estos códigos y nombres Procore.
                </p>

                <?php if (empty($dictionary_stats)): ?>
                    <p style="font-style: italic; color: var(--text-muted);">No hay datos en el diccionario. Carga una plantilla para empezar.</p>
                <?php else: ?>
                    <div class="cat-table-wrap" style="border-radius: 0.9rem; overflow: hidden; border: 1px solid var(--border-subtle); margin-top: 0.5rem;">
                        <table class="cat-list-table">
                            <thead>
                                <tr>
                                    <th style="width: 32%;">Categoría</th>
                                    <th style="width: 18%;">Total Ítems</th>
                                    <th style="width: 18%;">Con Precio</th>
                                    <th style="width: 18%;">Último Scrap</th>
                                    <th style="width: 14%; text-align: right;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dictionary_stats as $cat => $stats): 
                                    $meta = $cat_meta[$cat] ?? ['icon' => '📦', 'color' => '#64748b', 'desc' => 'Categoría de catálogo'];
                                ?>
                                    <tr>
                                        <!-- Identidad de la Categoría -->
                                        <td>
                                            <div class="cat-ident-cell">
                                                <div class="cat-ident-icon" style="background: <?php echo $meta['color']; ?>18; border-color: <?php echo $meta['color']; ?>40;">
                                                    <?php echo $meta['icon']; ?>
                                                </div>
                                                <div>
                                                    <div class="cat-ident-name" style="font-weight: 700; color: var(--text-primary); font-size: 0.95rem;"><?php echo htmlspecialchars($cat); ?></div>
                                                    <div class="cat-ident-desc" style="font-size: 0.75rem; color: var(--text-secondary);"><?php echo htmlspecialchars($meta['desc']); ?></div>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Total Ítems -->
                                        <td>
                                            <div class="cat-num-pill">
                                                <span>📦</span>
                                                <span style="font-weight: 700;"><?php echo number_format($stats['total_items']); ?> ítems</span>
                                            </div>
                                        </td>

                                        <!-- Con Precio -->
                                        <td>
                                            <div class="cat-num-pill" style="<?php echo ($stats['items_found'] > 0) ? 'border-color: rgba(16, 185, 129, 0.3); background: rgba(16, 185, 129, 0.08);' : ''; ?>">
                                                <span style="color: <?php echo ($stats['items_found'] > 0) ? 'var(--emerald-500)' : 'var(--text-muted)'; ?>;">✓</span>
                                                <span style="font-weight: 700; color: <?php echo ($stats['items_found'] > 0) ? 'var(--emerald-500)' : 'var(--text-secondary)'; ?>;">
                                                    <?php echo number_format($stats['items_found']); ?> con precio
                                                </span>
                                            </div>
                                        </td>

                                        <!-- Último Scrap -->
                                        <td>
                                            <?php if ($stats['last_update']): ?>
                                                <span class="status-pill status-pill-success" style="font-size: 0.78rem;">
                                                    <span class="status-dot status-dot-active"></span>
                                                    <span><?php echo date('m/d/Y H:i:s', strtotime($stats['last_update'])); ?></span>
                                                </span>
                                            <?php else: ?>
                                                <span class="status-pill status-pill-inactive" style="font-size: 0.78rem;">
                                                    <span class="status-dot status-dot-inactive"></span>
                                                    <span>Sin registro</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Botón Ver Ítems -->
                                        <td style="text-align: right;">
                                            <button type="button" class="btn-secondary" style="padding: 0.55rem 0.9rem; font-size: 0.8rem; font-weight: 600; border-radius: 0.6rem; border: 1px solid var(--border-subtle); display: inline-flex; align-items: center; gap: 0.35rem; cursor: pointer;" onclick="openDictionaryModal('<?php echo htmlspecialchars($cat); ?>')">
                                                <span>👁</span> Ver Ítems
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- SECCIÓN DE TAX / IMPUESTOS -->
            <div class="dashboard-card card-box config-card" style="border-radius: 1.25rem; border: 1px solid var(--border-subtle); background: var(--bg-panel); margin-bottom: 2rem;">
                <div class="category-header" style="border-bottom: 1px solid var(--border-subtle); padding-bottom: 0.85rem;">
                    <div style="display: flex; align-items: center; gap: 0.6rem;">
                        <span style="font-size: 1.25rem;">⚙️</span>
                        <h2 style="color: var(--text-primary); margin: 0; font-size: 1.2rem; font-weight: 700;">Configuración Global de Precios & Impuestos</h2>
                    </div>
                </div>
                <p class="text-secondary text-small" style="margin: 0.5rem 0 1.5rem 0;">
                    Define el porcentaje de recargo o impuestos (Tax Rate) que se sumará al costo base de Rexel USA antes de exportar la plantilla a Procore.
                </p>
                <form action="configuracion.php" method="post" class="tax-config-container">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                    <input type="hidden" name="action" value="save_tax">
                    
                    <div style="flex: 1; min-width: 220px;">
                        <label for="tax_rate" class="input-label" style="font-weight: 600;">Factor de Recargo / Tax (%)</label>
                        <div style="position: relative; display: flex; align-items: center;">
                            <input type="number" step="0.01" name="tax_rate" id="tax_rate" class="input-std" value="<?php echo htmlspecialchars($config_tax_rate); ?>" required style="font-size: 1.1rem; font-weight: 700; padding-right: 2.5rem;">
                            <span style="position: absolute; right: 1rem; color: var(--text-muted); font-weight: 700;">%</span>
                        </div>
                    </div>

                    <div style="flex: 1.5; min-width: 260px; display: flex; align-items: center; padding-top: 1.7rem;">
                        <label style="display: flex; align-items: center; gap: 0.75rem; cursor: pointer; user-select: none;">
                            <input type="checkbox" name="tax_bypass" id="tax_bypass" value="1" <?php echo $config_tax_bypass ? 'checked' : ''; ?> style="width: 1.2rem; height: 1.2rem; accent-color: var(--accent-primary); cursor: pointer;">
                            <div>
                                <span style="font-weight: 700; color: var(--text-primary); display: block;">Modo Bypass (Exención de Tax)</span>
                                <span class="text-small text-muted">No sumar recargo; utilizar el costo neto extraído de Rexel USA.</span>
                            </div>
                        </label>
                    </div>

                    <button type="submit" class="btn-primary" style="height: fit-content; padding: 0.85rem 1.75rem; font-weight: 700;">
                        Guardar Configuración
                    </button>
                </form>
            </div>

            <!-- SECCIÓN DE CUENTAS VINCULADAS -->
            <div style="margin-bottom: 2rem;">
                <div class="category-header" style="border:none; margin-bottom: 0.75rem;">
                    <div style="display: flex; align-items: center; gap: 0.6rem;">
                        <span style="font-size: 1.25rem;">🔐</span>
                        <h2 style="color: var(--text-primary); margin: 0; font-size: 1.2rem; font-weight: 700;">Cuentas & Conexiones Vinculadas</h2>
                    </div>
                </div>
                <div class="account-grid">
                    <!-- Cuenta PROCORE -->
                    <div class="dashboard-card card-box" style="border-radius: 1.25rem; border: 1px solid var(--border-subtle); background: var(--bg-panel); padding: 1.5rem; display: flex; flex-direction: column;">
                        <div class="category-header" style="border-bottom: 1px solid var(--border-subtle); padding-bottom: 0.75rem;">
                            <div style="display: flex; align-items: center; gap: 0.6rem;">
                                <span style="font-size: 1.4rem;">🏗️</span>
                                <div>
                                    <h3 style="margin: 0; font-size: 1.15rem; font-weight: 800; color: var(--text-primary);">Procore</h3>
                                    <span style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em;">Construction OS</span>
                                </div>
                            </div>
                            <?php if($config_procore): ?>
                                <span class="session-status-badge status-ok" style="font-size: 0.72rem; padding: 0.25rem 0.65rem;">
                                    <span class="badge-dot"></span> Conectado
                                </span>
                            <?php else: ?>
                                <span class="session-status-badge status-error" style="font-size: 0.72rem; padding: 0.25rem 0.65rem;">
                                    <span class="badge-dot"></span> Sin Configurar
                                </span>
                            <?php endif; ?>
                        </div>

                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0.85rem 0; line-height: 1.5;">
                            Credenciales corporativas para la sincronización automática de catálogos y subida de presupuestos y plantillas a Procore.
                        </p>

                        <?php if($config_procore): ?>
                            <div style="background: var(--bg-input); border: 1px solid var(--border-subtle); padding: 0.75rem 0.9rem; border-radius: 0.75rem; margin-bottom: 1.25rem;">
                                <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-bottom: 0.25rem;">Cuenta Sincronizada</div>
                                <div style="font-family: 'Consolas', monospace; color: var(--text-primary); font-size: 0.88rem; display: flex; align-items: center; gap: 0.5rem; word-break: break-all;">
                                    <span>👤</span>
                                    <strong><?php echo htmlspecialchars($config_procore); ?></strong>
                                </div>
                            </div>
                            <div style="display: flex; gap: 0.75rem; margin-top: auto;">
                                <button type="button" class="btn-secondary" onclick="openModal('modal-procore-auth')" style="flex: 1; border: 1px solid var(--border-subtle); padding: 0.75rem; border-radius: 0.75rem; font-weight: 600;">
                                    ✏️ Modificar
                                </button>
                                <button type="button" class="btn-mini-danger" onclick="confirmDeleteProcore()" style="padding: 0.75rem 1rem; border-radius: 0.75rem; font-weight: 600; cursor: pointer;">
                                    🗑️ Desvincular
                                </button>
                            </div>
                        <?php else: ?>
                            <div style="background: rgba(245, 158, 11, 0.08); border: 1px dashed rgba(245, 158, 11, 0.3); border-radius: 0.75rem; padding: 0.85rem 1rem; margin-bottom: 1.25rem; font-size: 0.82rem; color: var(--text-secondary); line-height: 1.5;">
                                ⚠️ No se han vinculado credenciales de Procore. El sistema requiere una cuenta autorizada para exportar plantillas de catálogo.
                            </div>
                            <button type="button" class="btn-primary" onclick="openModal('modal-procore-auth')" style="margin-top: auto; width: 100%; padding: 0.75rem; border-radius: 0.75rem; font-weight: 700; background: var(--blue-500); border-color: var(--blue-500);">
                                🔑 Conectar Cuenta Procore
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- Info Card REXEL (Con botón directo a gestión de sesión) -->
                    <div class="dashboard-card card-box" style="border-radius: 1.25rem; border: 1px solid var(--border-subtle); background: var(--bg-panel); padding: 1.5rem; display: flex; flex-direction: column;">
                        <div class="category-header" style="border-bottom: 1px solid var(--border-subtle); padding-bottom: 0.75rem;">
                            <div style="display: flex; align-items: center; gap: 0.6rem;">
                                <span style="font-size: 1.4rem;">⚡</span>
                                <div>
                                    <h3 style="margin: 0; font-size: 1.15rem; font-weight: 800; color: var(--text-primary);">Rexel USA</h3>
                                    <span style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em;">Sesión de Navegador</span>
                                </div>
                            </div>
                            <span class="session-status-badge status-ok" id="rexel-session-badge" style="font-size: 0.72rem; padding: 0.25rem 0.65rem; cursor: pointer;" onclick="openRexelModal()">
                                <span class="badge-dot"></span> <span id="rexel-badge-text">Verificando...</span>
                            </span>
                        </div>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0.85rem 0; line-height: 1.5;">
                            Rexel autentica mediante cookies y tokens de sesión gestionados de forma segura con Playwright. No requiere guardar contraseñas en texto plano.
                        </p>
                        <div style="background: var(--bg-input); border: 1px solid var(--border-subtle); padding: 0.75rem 0.9rem; border-radius: 0.75rem; margin-bottom: 1.25rem; font-size: 0.82rem; color: var(--text-secondary); line-height: 1.5;">
                            🌐 Puedes verificar la vigencia, abrir el navegador para re-autenticar o renovar el token OAuth directamente sin salir de este panel.
                        </div>
                        <button type="button" class="btn-primary" onclick="openRexelModal()" style="margin-top: auto; width: 100%; padding: 0.85rem; border-radius: 0.75rem; font-weight: 700; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-color: #059669; display: flex; align-items: center; justify-content: center; gap: 0.5rem; cursor: pointer; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.25);">
                            <span>⚡</span> Iniciar / Gestionar Sesión Rexel
                        </button>
                    </div>
                </div>

                <!-- Formulario oculto para desvincular Procore -->
                <form id="form-delete-procore" action="manage_urls.php" method="POST" style="display:none;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                    <input type="hidden" name="action" value="delete_procore">
                </form>
            </div>

            <!-- SECCIÓN: ENLACES POR CATEGORÍA -->
            <div class="category-header" style="border:none; margin-bottom: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.6rem;">
                    <span style="font-size: 1.25rem;">🔗</span>
                    <h2 style="color: var(--text-primary); margin: 0; font-size: 1.2rem; font-weight: 700;">Enlaces de Búsqueda Configurados por Categoría</h2>
                </div>
            </div>

            <?php foreach ($urls_by_category as $category => $urls): ?>
                <div class="dashboard-card card-box config-card" style="border-radius: 1.25rem; border: 1px solid var(--border-subtle); background: var(--bg-panel); margin-bottom: 1.5rem;">
                    <div class="category-header" style="border-bottom: 1px solid var(--border-subtle); padding-bottom: 0.85rem;">
                        <div style="display: flex; align-items: center; gap: 0.6rem;">
                            <h3 style="margin: 0; font-size: 1.2rem; font-weight: 800; color: var(--accent-primary);"><?php echo htmlspecialchars($category); ?></h3>
                            <span class="badge-neutral" style="font-size: 0.75rem; font-weight: 700;"><?php echo count($urls); ?> enlaces</span>
                        </div>
                        <div class="header-actions" style="display: flex; gap: 0.5rem; align-items: center;">
                            <button type="button" class="btn-secondary" onclick="openUploadModal('<?php echo htmlspecialchars($category); ?>')" style="padding: 0.45rem 0.85rem; font-size: 0.8rem; font-weight: 600; border-radius: 0.6rem; border: 1px solid var(--border-subtle); display: inline-flex; align-items: center; gap: 0.35rem;">
                                <span>📂</span> Archivos Base
                            </button>
                            <button type="button" class="btn-secondary" onclick="openAddUrlModal('<?php echo htmlspecialchars($category); ?>')" style="padding: 0.45rem 0.85rem; font-size: 0.8rem; font-weight: 600; border-radius: 0.6rem; border: 1px solid var(--border-subtle); display: inline-flex; align-items: center; gap: 0.35rem;">
                                <span>🔗</span> + Añadir Link
                            </button>
                            <button type="button" class="btn-secondary" onclick="openDeleteCategoryModal('<?php echo htmlspecialchars($category); ?>')" style="padding: 0.45rem 0.85rem; font-size: 0.8rem; font-weight: 600; border-radius: 0.6rem; color: #f87171; border-color: rgba(239, 68, 68, 0.35); background: rgba(239, 68, 68, 0.06); display: inline-flex; align-items: center; gap: 0.35rem;">
                                <span>🗑️</span> Eliminar Categoría
                            </button>
                        </div>
                    </div>

                    <div class="cat-table-wrap" style="border-radius: 0.85rem; overflow: hidden; border: 1px solid var(--border-subtle); margin-top: 0.75rem;">
                        <table class="cat-list-table" style="min-width: 600px;">
                            <thead>
                                <tr>
                                    <th style="width: 14%;">Estado</th>
                                    <th style="width: 66%;">URL de Rastreo (Rexel)</th>
                                    <th style="width: 20%; text-align: right;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($urls as $url): ?>
                                    <tr style="<?php echo $url['is_active'] ? '' : 'opacity: 0.65;'; ?>">
                                        <!-- Estado -->
                                        <td>
                                            <?php if ($url['is_active']): ?>
                                                <span class="status-pill status-pill-success" style="font-size: 0.75rem;">
                                                    <span class="status-dot status-dot-active"></span>
                                                    <span>Activa</span>
                                                </span>
                                            <?php else: ?>
                                                <span class="status-pill status-pill-inactive" style="font-size: 0.75rem;">
                                                    <span class="status-dot status-dot-inactive"></span>
                                                    <span>Pausada</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- URL -->
                                        <td>
                                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; width: 100%;">
                                                <div style="display: flex; align-items: center; gap: 0.5rem; min-width: 0; flex: 1;">
                                                    <span style="font-size: 0.95rem; color: var(--text-muted); flex-shrink: 0;">🔗</span>
                                                    <span class="font-mono text-small" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-primary); max-width: 540px;" title="<?php echo htmlspecialchars($url['url']); ?>">
                                                        <?php echo htmlspecialchars($url['url']); ?>
                                                    </span>
                                                </div>
                                                <a href="<?php echo htmlspecialchars($url['url']); ?>" target="_blank" rel="noopener noreferrer" title="Abrir enlace en Rexel USA (pestaña nueva)" style="flex-shrink: 0; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; border-radius: 0.45rem; border: 1px solid var(--border-subtle); background: var(--bg-card); color: var(--accent-primary); text-decoration: none; font-size: 0.82rem; font-weight: 700; transition: all 0.2s ease;">
                                                    ↗
                                                </a>
                                            </div>
                                        </td>

                                        <!-- Acciones -->
                                        <td style="text-align: right;">
                                            <div style="display: inline-flex; gap: 0.45rem; align-items: center; justify-content: flex-end;">
                                                <form action="manage_urls.php" method="post" style="margin: 0;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                                    <input type="hidden" name="url_id" value="<?php echo $url['id']; ?>">
                                                    <button type="submit" name="action" value="toggle" class="btn-secondary" style="padding: 0.4rem 0.75rem; font-size: 0.78rem; font-weight: 600; border-radius: 0.55rem; border: 1px solid var(--border-subtle); cursor: pointer;">
                                                        <?php echo $url['is_active'] ? '⏸ Pausar' : '▶ Activar'; ?>
                                                    </button>
                                                </form>
                                                <form action="manage_urls.php" method="post" style="margin: 0;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                                    <input type="hidden" name="url_id" value="<?php echo $url['id']; ?>">
                                                    <button type="button" class="btn-mini btn-mini-danger" onclick="confirmDeleteUrl(this)" style="padding: 0.4rem 0.75rem; font-size: 0.78rem; font-weight: 600; border-radius: 0.55rem; cursor: pointer;">
                                                        🗑 Eliminar
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($urls_by_category)): ?>
                <div style="text-align: center; color: var(--text-muted); padding: 3rem;">
                    <p>No hay categorías configuradas. ¡Crea una nueva arriba!</p>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- MODAL: Añadir Nueva Categoría -->
    <div class="modal-overlay" id="modal-add-category">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Nueva Categoría de Scraping</h3>
                <button class="btn-icon-only" onclick="closeModal('modal-add-category')">✕</button>
            </div>
            <form action="manage_urls.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <h4 style="margin-top:0;">1. Detalles Básicos</h4>
                    <label for="new_cat_name" class="input-label">Nombre (Ej: Fittings)</label>
                    <input type="text" id="new_cat_name" name="category" class="input-std" placeholder="Ej: Fittings" required>
                    <br>
                    <label for="new_cat_url" class="input-label">URL Inicial de Búsqueda</label>
                    <input type="url" id="new_cat_url" name="url" class="input-std" placeholder="https://www.rexelusa.com/..." required>
                    
                    <h4>2. Archivos Requeridos</h4>
                    
                    <label for="cat_template" class="input-label">Plantilla Excel (Procore)</label>
                    <p style="font-size:0.75rem; color:var(--text-muted); margin:0 0 0.5rem 0;">El Excel base donde se escribirán los precios.</p>
                    <input type="file" id="cat_template" name="template_file" accept=".xlsx" class="input-std" required style="padding:0.5rem;">
                    <br>
                    
                    <label for="cat_dict" class="input-label">Diccionario/Mapping (Excel)</label>
                    <p style="font-size:0.75rem; color:var(--text-muted); margin:0 0 0.5rem 0;">
                        Excel con ítems para importar a la DB.<br>
                        <strong>Col A:</strong> Nombre Procore | <strong>Col C (o B):</strong> Nombre Rexel
                        <br>
                        <a href="manage_urls.php?action=download_template" class="btn-mini" style="display:inline-flex; margin-top:0.3rem; text-decoration:none; gap:0.3rem;">
                            <span>⬇</span> Descargar Plantilla Base
                        </a>
                    </p>
                    <input type="file" id="cat_dict" name="dictionary_file" accept=".xlsx" class="input-std" required style="padding:0.5rem;">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal('modal-add-category')">Cancelar</button>
                    <button type="submit" class="btn-primary">
                        Crear Categoría e Importar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Añadir URL a Categoría Existente -->
    <div class="modal-overlay" id="modal-add-url">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Añadir URL a <span id="modal-cat-name-display" style="color: var(--accent-primary);"></span></h3>
                <button class="btn-icon-only" onclick="closeModal('modal-add-url')">✕</button>
            </div>
            <form action="manage_urls.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" id="modal-cat-input" name="category" value="">
                <div class="modal-body">
                    <label for="add_url_input" class="input-label">Nueva URL (Rexel)</label>
                    <input type="url" id="add_url_input" name="url" class="input-std" placeholder="https://www.rexelusa.com/..." required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal('modal-add-url')">Cancelar</button>
                    <button type="submit" class="btn-primary">Añadir URL</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Subir Archivos a Categoría Existente -->
    <div class="modal-overlay" id="modal-upload-files">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Gestionar Archivos de <span id="modal-upload-cat-name" style="color: var(--blue-500);"></span></h3>
                <button class="btn-icon-only" onclick="closeModal('modal-upload-files')">✕</button>
            </div>
            <form action="manage_urls.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="upload_files">
                <input type="hidden" id="modal-upload-cat-input" name="category" value="">
                <div class="modal-body">
                    <p style="font-size:0.8rem; color:var(--text-muted);">Sube solo los archivos que desees actualizar.</p>

                    <label class="input-label">Plantilla Excel (Template)</label>
                    <input type="file" name="template_file" accept=".xlsx" class="input-std" style="padding:0.5rem; margin-bottom:1rem;">

                    <label class="input-label">Diccionario/Mapping (Importar Ítems)</label>
                    <input type="file" name="dictionary_file" accept=".xlsx" class="input-std" style="padding:0.5rem;">
                    <a href="manage_urls.php?action=download_template" class="btn-mini" style="display:inline-flex; margin-top:0.5rem; text-decoration:none; gap:0.3rem;">
                        <span>⬇</span> Descargar Plantilla Base
                    </a>
                    <p style="font-size:0.7rem; color:var(--text-muted); margin-top:0.2rem;">* Se añadirán nuevos ítems, no se borrarán los existentes.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal('modal-upload-files')">Cancelar</button>
                    <button type="submit" class="btn-primary">
                        Actualizar Archivos
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Confirmar Eliminación de Categoría -->
    <div class="modal-overlay" id="modal-delete-category">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="text-danger">Eliminar Categoría</h3>
                <button class="btn-icon-only" onclick="closeModal('modal-delete-category')">✕</button>
            </div>
            <form action="manage_urls.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" id="delete-cat-name-input" name="category" value="">
                <div class="modal-body">
                    <p>¿Estás seguro de que deseas eliminar la categoría <strong id="delete-cat-name-display" class="text-accent"></strong>?</p>
                    <p class="dict-stat-label" style="text-transform: none;">Esta acción eliminará todos los enlaces de scraping configurados para esta categoría.</p>
                    
                    <div class="modal-warning-area">
                        <label style="display: flex; align-items: flex-start; gap: 0.75rem; cursor: pointer;">
                            <input type="checkbox" name="delete_dictionary" value="1" style="margin-top: 0.25rem;">
                            <span>
                                <strong style="display: block;" class="text-primary">Eliminar también del Diccionario</strong>
                                <span class="dict-stat-label" style="text-transform: none;">Borra permanentemente todos los productos y precios guardados en la base de datos para esta categoría.</span>
                            </span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal('modal-delete-category')">Cancelar</button>
                    <button type="submit" class="btn-danger">Confirmar Eliminación</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Confirmación Genérica -->
    <div class="modal-overlay" id="modal-generic-confirm">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="generic-confirm-title">Confirmar Acción</h3>
                <button class="btn-icon-only" onclick="closeModal('modal-generic-confirm')">✕</button>
            </div>
            <div class="modal-body"><p id="generic-confirm-message"></p></div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('modal-generic-confirm')">Cancelar</button>
                <button type="button" class="btn-primary" id="generic-confirm-btn">Confirmar</button>
            </div>
        </div>
    </div>

    <!-- MODAL: Auth Procore -->
    <div class="modal-overlay" id="modal-procore-auth">
        <div class="modal-content" style="max-width: 480px;">
            <div class="modal-header">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-size: 1.3rem;">🏗️</span>
                    <h3 style="margin: 0; font-size: 1.15rem; font-weight: 800;">Conectar Cuenta Procore</h3>
                </div>
                <button class="btn-icon-only" onclick="closeModal('modal-procore-auth')">✕</button>
            </div>
            <form action="manage_urls.php" method="post" id="form-procore-auth" onsubmit="handleProcoreSubmit(this)">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="save_procore">
                <div class="modal-body">
                    <div style="background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.25); border-radius: 0.75rem; padding: 0.85rem 1rem; margin-bottom: 1.25rem;">
                        <p style="font-size: 0.84rem; color: var(--text-secondary); margin: 0; line-height: 1.5;">
                            El sistema verificará las credenciales iniciando una sesión de prueba en Procore antes de guardarlas en la base de datos.
                        </p>
                    </div>

                    <div style="margin-bottom: 1.25rem;">
                        <label class="input-label" style="display: block; margin-bottom: 0.4rem; font-weight: 600;">Correo Electrónico (Procore)</label>
                        <input type="email" name="email" class="input-std" required placeholder="tu-usuario@empresa.com" value="<?php echo htmlspecialchars($config_procore ?? ''); ?>" style="width: 100%; box-sizing: border-box;">
                    </div>

                    <div style="margin-bottom: 1rem;">
                        <label class="input-label" style="display: block; margin-bottom: 0.4rem; font-weight: 600;">Contraseña</label>
                        <div style="position: relative;">
                            <input type="password" name="password" id="procore-password-input" class="input-std" required placeholder="••••••••••••" style="width: 100%; padding-right: 3rem; box-sizing: border-box;">
                            <button type="button" class="btn-icon-only" onclick="togglePass(this)" style="position: absolute; right: 0.5rem; top: 50%; transform: translateY(-50%); height: 80%; width: 2.5rem; display: flex; align-items: center; justify-content: center; background: transparent; border: none; cursor: pointer; color: var(--text-muted);" title="Mostrar/Ocultar contraseña">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/></svg>
                            </button>
                        </div>
                    </div>

                    <div style="margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.6rem; padding: 0.5rem 0.25rem;">
                        <input type="checkbox" id="procore-headless-check" name="headless" value="1" style="width: 1.15rem; height: 1.15rem; cursor: pointer; accent-color: var(--blue-500);">
                        <label for="procore-headless-check" style="cursor: pointer; font-size: 0.86rem; color: var(--text-secondary); user-select: none;">
                            Ejecutar verificación en modo silencioso (Headless - sin ventana visible)
                        </label>
                    </div>

                    <div id="procore-loading-indicator" style="display:none; padding: 0.75rem; background: var(--bg-input); border-radius: 0.5rem; border: 1px solid var(--border-subtle); margin-top: 0.75rem; text-align: center; font-size: 0.82rem; color: var(--text-secondary);">
                        <span class="loading-pulse" style="width: 10px; height: 10px; display: inline-block; margin-right: 0.5rem;"></span>
                        Verificando credenciales en Procore, por favor espera...
                    </div>
                </div>
                <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" class="btn-secondary" onclick="closeModal('modal-procore-auth')">Cancelar</button>
                    <button type="submit" id="btn-submit-procore" class="btn-primary" style="background-color: var(--blue-500); border-color: var(--blue-500); font-weight: 700;">
                        Verificar y Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Ver Detalle del Diccionario -->
    <div class="modal-overlay" id="modal-dictionary-detail">
        <div class="modal-content modal-lg" style="max-width: 960px; width: 95%; border-radius: 1.25rem; border: 1px solid var(--border-subtle); background: var(--bg-panel);">
            <div class="modal-header" style="border-bottom: 1px solid var(--border-subtle); padding-bottom: 0.85rem;">
                <h3 style="margin: 0; font-size: 1.15rem; font-weight: 700;">Diccionario: <span id="dict-modal-title" style="color: var(--accent-primary);"></span></h3>
                <button class="btn-icon-only" onclick="closeModal('modal-dictionary-detail')">✕</button>
            </div>
            <div class="modal-body" style="padding: 1.25rem 0;">
                <div id="dict-loading" style="text-align: center; padding: 2rem; display: none;">
                    <div class="loading-pulse" style="width: 15px; height: 15px;"></div> Cargando datos...
                </div>
                <div id="dict-table-container" class="table-container-scroll" style="max-height: 520px; border-radius: 0.75rem; border: 1px solid var(--border-subtle); overflow-x: auto;">
                    <!-- Tabla inyectada vía JS -->
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid var(--border-subtle); padding-top: 0.85rem; display: flex; justify-content: flex-end;">
                <button type="button" class="btn-secondary" onclick="closeModal('modal-dictionary-detail')" style="padding: 0.55rem 1.25rem; font-size: 0.85rem; font-weight: 600; border-radius: 0.65rem;">Cerrar</button>
            </div>
        </div>
    </div>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.add('show');
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }
        
        function openAddUrlModal(categoryName) {
            document.getElementById('modal-cat-name-display').innerText = categoryName;
            document.getElementById('modal-cat-input').value = categoryName;
            openModal('modal-add-url');
        }

        function openUploadModal(categoryName) {
            document.getElementById('modal-upload-cat-name').innerText = categoryName;
            document.getElementById('modal-upload-cat-input').value = categoryName;
            openModal('modal-upload-files');
        }

        function openDeleteCategoryModal(categoryName) {
            document.getElementById('delete-cat-name-display').innerText = categoryName;
            document.getElementById('delete-cat-name-input').value = categoryName;
            openModal('modal-delete-category');
        }

        function customConfirm(title, message, callback) {
            document.getElementById('generic-confirm-title').innerText = title;
            document.getElementById('generic-confirm-message').innerText = message;
            const btn = document.getElementById('generic-confirm-btn');
            
            const newBtn = btn.cloneNode(true);
            btn.parentNode.replaceChild(newBtn, btn);
            
            newBtn.onclick = function() {
                closeModal('modal-generic-confirm');
                callback();
            };
            openModal('modal-generic-confirm');
        }

        function confirmDeleteUrl(btn) {
            const form = btn.closest('form');
            customConfirm(
                'Eliminar URL', 
                '¿Estás seguro de que quieres eliminar esta URL?', 
                () => {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'action';
                    hidden.value = 'delete';
                    form.appendChild(hidden);
                    form.submit();
                }
            );
        }

        function openDictionaryModal(categoryName, isImportReport = false) {
            const titleEl = document.getElementById('dict-modal-title');
            titleEl.innerText = categoryName;
            
            const container = document.getElementById('dict-table-container');
            const loading = document.getElementById('dict-loading');
            
            container.innerHTML = ''; // Limpiar anterior
            loading.style.display = 'block';
            openModal('modal-dictionary-detail');

            // Fetch Data
            fetch('configuracion.php?ajax_action=get_cat_items&category=' + encodeURIComponent(categoryName))
                .then(response => response.json())
                .then(data => {
                    loading.style.display = 'none';
                    if (data.error) {
                        container.innerHTML = '<p style="color:red; padding:1rem;">Error: ' + data.error + '</p>';
                        return;
                    }
                    if (data.length === 0) {
                        container.innerHTML = '<p style="padding:1rem;">No hay ítems en esta categoría.</p>';
                        return;
                    }

                    let col3Title = isImportReport ? 'Cambios' : 'Precio';
                    let html = `<table class="cat-list-table" style="min-width: 720px; width: 100%;">
                        <thead>
                            <tr>
                                <th style="width: 35%; min-width: 200px;">Nombre Procore (ID)</th>
                                <th style="width: 35%; min-width: 210px;">Nombre Web (Búsqueda)</th>
                                <th style="width: 9%; min-width: 80px; text-align: right;">${col3Title}</th>
                                <th style="width: 10%; min-width: 110px; text-align: center;">Estado</th>
                                <th style="width: 11%; min-width: 110px; text-align: center;">Acción</th>
                            </tr>
                        </thead>
                        <tbody>`;
                    
                    data.forEach(item => {
                        let statusBadge = '';
                        let col3 = '';
                        
                        if (isImportReport) {
                            // MODO REPORTE: Ver si se actualizó recientemente
                            if (item.is_recent == 1) {
                                statusBadge = '<span class="status-pill status-pill-success" title="Precio modificado recientemente en este catálogo"><span class="status-dot status-dot-active"></span>Actualizado</span>';
                                col3 = '<span style="color:var(--emerald-400); font-size:0.82rem; font-weight:700;">Modificado</span>';
                            } else {
                                statusBadge = '<span class="status-pill status-pill-inactive" title="Sin cambios recientes en catálogo"><span class="status-dot status-dot-inactive"></span>No actualizado</span>';
                                col3 = '<span style="color:var(--text-muted); font-size:0.82rem;">Sin Cambios</span>';
                            }
                        } else {
                            // MODO NORMAL: Ver precios
                            const price = parseFloat(item.precio_actual || 0);
                            statusBadge = price > 0 
                                ? '<span class="status-pill status-pill-success" title="Precio disponible en la base de datos"><span class="status-dot status-dot-active"></span>Encontrado</span>' 
                                : '<span class="status-pill status-pill-inactive" title="Precio pendiente de extracción"><span class="status-dot status-dot-inactive"></span>No encontrado</span>';
                            col3 = '$' + price.toFixed(2);
                        }
                        
                        html += `<tr id="row-${item.id}">
                            <td style="font-weight: 600; color: var(--text-primary); font-size: 0.85rem; word-break: break-word;">${item.nombre_procore || '-'}</td>
                            <td style="font-family: var(--font-mono); color: var(--accent-primary); word-break: break-word; font-size: 0.81rem;">${item.nombre_web}</td>
                            <td style="text-align: right; font-family: var(--font-mono); font-weight: 700; color: var(--text-primary); font-size: 0.86rem;">${col3}</td>
                            <td style="text-align: center;">${statusBadge}</td>
                            <td style="text-align: center;">
                                <button type="button" class="btn-mini btn-mini-danger" onclick="deleteItem(${item.id})" style="padding: 0.35rem 0.65rem; font-size: 0.76rem; font-weight: 600; border-radius: 0.5rem; display: inline-flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                                    <span>🗑️</span> Eliminar
                                </button>
                            </td>
                        </tr>`;
                    });
                    html += '</tbody></table>';
                    container.innerHTML = html;
                })
                .catch(err => {
                    loading.style.display = 'none';
                    container.innerHTML = '<p style="color:red; padding:1rem;">Error de conexión.</p>';
                });
        }

        function deleteItem(itemId) {
            customConfirm(
                'Eliminar Ítem',
                '¿Estás seguro de que quieres eliminar este ítem del diccionario? Esta acción no se puede deshacer.',
                () => {
                    const formData = new FormData();
                    formData.append('ajax_action', 'delete_item');
                    formData.append('item_id', itemId);
                    formData.append('csrf_token', '<?php echo $_SESSION["csrf_token"] ?? ""; ?>');

                    fetch('configuracion.php', { method: 'POST', body: formData })
                        .then(response => response.json())
                        .then(data => {
                            if(data.success) { document.getElementById('row-' + itemId).remove(); } 
                            else { alert('Error al eliminar: ' + (data.error || 'Desconocido')); }
                        })
                        .catch(err => alert('Error de conexión al intentar eliminar.'));
                }
            );
        }

        function togglePass(btn) {
            const input = btn.previousElementSibling;
            if (input.type === "password") {
                input.type = "text";
                btn.style.color = "var(--accent-primary)";
            } else {
                input.type = "password";
                btn.style.color = "";
            }
        }

        function confirmDeleteProcore() {
            customConfirm(
                'Desvincular Cuenta Procore',
                '¿Estás seguro de que deseas eliminar las credenciales guardadas de Procore? Tendrás que volver a ingresarlas para sincronizaciones con Procore.',
                function() {
                    document.getElementById('form-delete-procore').submit();
                }
            );
        }

        function handleProcoreSubmit(form) {
            const btn = document.getElementById('btn-submit-procore');
            const loading = document.getElementById('procore-loading-indicator');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Verificando...';
            }
            if (loading) {
                loading.style.display = 'block';
            }
        }

        // AUTO-OPEN MODAL SI VIENE EL PARAMETRO EN URL (Reporte de Importación)
        document.addEventListener("DOMContentLoaded", function() {
            const urlParams = new URLSearchParams(window.location.search);
            const openCat = urlParams.get('open_dict');
            if (openCat) {
                // Limpiar URL para que no se reabra al recargar
                window.history.replaceState({}, document.title, window.location.pathname);
                // Abrir en modo Reporte (true)
                openDictionaryModal(openCat, true);
            }
        });
    </script>

    <!-- Modal Compartido de Sesión Rexel -->
    <?php include __DIR__ . '/modal_rexel_session.php'; ?>
</body>
</html>