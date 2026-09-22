<?php
session_start(); // Iniciar sesión para acceder a las credenciales guardadas.

// --- SEGURIDAD: CSRF TOKEN ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- CENTRALIZED DB HELPER ---
require_once __DIR__ . '/db.php';

// --- CONFIGURACIÓN ---
$venv_python_win = realpath(__DIR__ . '/../.venv/Scripts/python.exe');
$venv_python_nix = realpath(__DIR__ . '/../.venv/bin/python');
if ($venv_python_win && file_exists($venv_python_win)) {
    $python_executable = '"' . $venv_python_win . '" -u';
} elseif ($venv_python_nix && file_exists($venv_python_nix)) {
    $python_executable = '"' . $venv_python_nix . '" -u';
} else {
    $python_executable = 'python -u';
}
set_time_limit(0); // Eliminar el límite de tiempo de ejecución para scripts largos como los scrapers.

// --- ESTADO DE SESIÓN REXEL ---
$storage_state_file = realpath(__DIR__ . '/../scripts/storage_state.json');
$has_storage_state = ($storage_state_file && file_exists($storage_state_file));
if ($has_storage_state && empty($_SESSION['rexel_email'])) {
    $_SESSION['rexel_email'] = 'rexel_authenticated';
}


/**
 * Función para ejecutar un comando y mostrar su salida en tiempo real.
 * @param string $command El comando a ejecutar.
 * @param string $title El título a mostrar para esta sección. 
 * @param bool $capture_output Si es true, la salida se captura y se devuelve. Si es false, se imprime en tiempo real.
 * @param bool $raw_output_html Si es true y $capture_output es true, imprime la salida como HTML sin escapar (para la tabla).
 * @return string La salida completa del comando.
 */
function run_command($command, $title, $capture_output = false, $raw_output_html = false)
{
    if (!empty($title)) {
        echo "<h3>$title</h3>";
        flush();
    }

    $output = '';
    $exit_code = 0;

    if ($capture_output) {
        ob_start();
        passthru($command . " 2>&1", $exit_code);
        $output = ob_get_clean();
    } else {
        if (!$raw_output_html)
            echo "<pre>";
        passthru($command . " 2>&1", $exit_code);
        if (!$raw_output_html)
            echo "</pre>";
    }

    if ($exit_code !== 0) {
        echo "<p style='color: red; font-weight: bold;'>El script terminó con un código de error: $exit_code.</p>";
    }
    echo '<hr style="border:none; border-top: 1px solid var(--border-subtle); margin: 1.5rem 0;">';
    return $output;
}

/**
 * Ejecuta un comando en streaming en tiempo real línea a línea sin buffering.
 * @param string $command Comando a ejecutar.
 * @param string &$full_output Referencia para guardar la salida completa.
 * @return int Código de salida del proceso.
 */
function stream_process_output($command, &$full_output = '')
{
    $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    $process = proc_open($command, $descriptors, $pipes);
    $full_output = '';

    if (is_resource($process)) {
        fclose($pipes[0]);
        while (!feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if ($line !== false) {
                $full_output .= $line;
                echo $line;
                @ob_flush();
                flush();
            }
        }
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (!empty($stderr)) {
            $full_output .= $stderr;
            echo $stderr;
            @ob_flush();
            flush();
        }

        return proc_close($process);
    }
    return -1;
}

/**
 * Función para encontrar la ruta del archivo Excel en la salida de un script.
 * @param string $output La salida del script procesar_excel.py.
 * @return string|null La ruta del archivo o null si no se encuentra.
 */
function find_excel_path($output)
{
    // Busca una línea que contenga "[EXITO] Archivo actualizado guardado en: "
    if (preg_match('/\[EXITO\] Archivo actualizado guardado en: (.*\.xlsx)/', $output, $matches)) {
        // La ruta completa está en el grupo de captura 1
        return trim($matches[1]);
    }
    return null;
}

/**
 * Verifica si la salida de un script indica que la sesion de Rexel expiro.
 * Muestra un aviso especial al usuario si es el caso.
 * @param string $output La salida del script.
 * @return bool True si la sesion expiro.
 */
function check_and_show_session_error($output)
{
    $session_expired = strpos($output, '[SESSION_EXPIRED]') !== false;
    $session_missing = strpos($output, '[SESSION_MISSING]') !== false;

    if ($session_expired || $session_missing) {
        $reason = $session_expired
            ? 'La sesión de Rexel ha <strong>expirado</strong>.'
            : 'No se encontró una sesión de Rexel guardada.';
        echo '
        <div style="background: rgba(239,68,68,0.08); border: 2px solid rgba(239,68,68,0.4);
                    border-radius: 1rem; padding: 2rem; margin-top: 1.5rem; text-align: center;">
            <div style="font-size: 2.5rem; margin-bottom: 1rem;">🔐</div>
            <h2 style="color: #ef4444; margin: 0 0 0.5rem 0;">Sesión de Rexel Requerida</h2>
            <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">' . $reason . '
               Para ejecutar los scrapers necesitas conectarte a Rexel.</p>
            <a href="index.php" class="btn-primary"
               style="text-decoration: none; background: #ef4444; display: inline-flex;
                      align-items: center; gap: 0.5rem; padding: 0.75rem 2rem;">
               ← Ir al Dashboard y Conectar Rexel
            </a>
        </div>';
        return true;
    }
    return false;
}

/**
 * Muestra mensaje amigable si el script de extracción falló fatalmente.
 * @param string $output Salida del proceso.
 * @return bool True si hubo error fatal.
 */
function check_and_show_fatal_error($output)
{
    if (strpos($output, '[ERROR FATAL]') !== false) {
        echo '
        <div style="background: rgba(239,68,68,0.08); border: 2px solid rgba(239,68,68,0.4);
                    border-radius: 1rem; padding: 2rem; margin-top: 1.5rem; text-align: center;">
            <div style="font-size: 2.5rem; margin-bottom: 1rem;">⚠️</div>
            <h2 style="color: #ef4444; margin: 0 0 0.5rem 0;">Error en el Proceso de Extracción</h2>
            <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
                Ocurrió un error técnico durante la extracción de datos. Revisa el registro superior para más detalles.
            </p>
            <a href="index.php" class="btn-secondary"
               style="text-decoration: none; display: inline-flex;
                      align-items: center; gap: 0.5rem; padding: 0.75rem 2rem; border-radius: 0.6rem; font-weight: 600;">
               ← Volver al Panel de Control
            </a>
        </div>';
        return true;
    }
    return false;
}

/**
 * Busca el archivo Excel más reciente generado para una categoría específica.
 * @param string $category Nombre de la categoría.
 * @return array|null Datos del archivo (path, name, date) o null si no existe.
 */
function get_latest_excel_for_category($category)
{
    $output_dir = realpath(__DIR__ . '/../data/excel_output');
    if (!$output_dir)
        return null;

    $pattern = $output_dir . DIRECTORY_SEPARATOR . "Plantilla_{$category}_Actualizada_*.xlsx";
    $files = glob($pattern);
    if (empty($files))
        return null;

    usort($files, function ($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    $latest = $files[0];
    return ['path' => $latest, 'name' => basename($latest), 'date' => date("Y-m-d H:i:s", filemtime($latest))];
}

/**
 * Renderiza el interruptor visual para Modo Silencioso (Headless) / Modo Supervisado.
 * @param string $id Sufijo identificador único para el toggle.
 * @param bool $compact True para formato compacto en tarjetas, False para formato expandido.
 * @return string Código HTML del interruptor interactivo.
 */
function render_headless_toggle($id, $compact = false)
{
    $safe_id = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);
    $card_class = $compact ? 'headless-toggle-compact active' : 'headless-toggle-card active';
    $title_icon = '⚡';
    $title_text = 'Modo Silencioso (Headless)';
    $sub_text = $compact ? 'Segundo plano • Ahorro de memoria y CPU' : 'Ejecuta en segundo plano sin ventana externa (Ahorra memoria y CPU)';
    $badge_text = 'Activo';

    $compact_js_val = $compact ? 'true' : 'false';

    $html = '
    <div class="' . $card_class . '" id="headless-card-' . $safe_id . '" onclick="toggleHeadlessClick(\'' . $safe_id . '\', event, ' . $compact_js_val . ')">
        <div class="toggle-meta">
            <div style="display: flex; align-items: center; gap: 0.45rem;">
                <span class="toggle-title" id="headless-title-' . $safe_id . '">
                    <span>' . $title_icon . '</span> ' . $title_text . '
                </span>
                <span class="toggle-mode-badge" id="headless-badge-' . $safe_id . '">' . $badge_text . '</span>
            </div>
            <span class="toggle-subtitle" id="headless-desc-' . $safe_id . '">' . $sub_text . '</span>
        </div>
        <div class="custom-switch-ui" onclick="event.stopPropagation();">
            <input type="checkbox" name="headless" value="1" checked id="headless-input-' . $safe_id . '" onchange="toggleHeadlessSwitch(\'' . $safe_id . '\', this, ' . $compact_js_val . ')">
            <label class="custom-switch-slider" for="headless-input-' . $safe_id . '"></label>
        </div>
    </div>';
    return $html;
}

// ==========================================
// MODO 1: PROCESADOR DE STREAM (Backend)
// ==========================================
// Si existe el parámetro 'stream', ejecutamos la lógica y enviamos datos crudos.
if (isset($_GET['stream']) && $_GET['stream'] === '1') {
    // Desactivar buffering para streaming real
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', 1);
    }
    @ini_set('zlib.output_compression', 0);
    @ini_set('implicit_flush', 1);
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    // Cabeceras para evitar caché y buffering en servidores intermedios o navegadores
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Accel-Buffering: no'); // Específico para Nginx/Proxies

    // Enviamos 4kb de datos iniciales con salto de línea para forzar al navegador a renderizar el primer paquete
    echo str_pad("<!-- stream start -->\n", 4096, " ") . "\n";
    flush();

    // VERIFICACIÓN CSRF (Backend Stream)
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo "<h2>Error de Seguridad</h2><p>Token CSRF inválido o sesión expirada. Por favor recarga la página.</p>";
        exit();
    }

    $action = $_POST['script'];
    $csrf_field = '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';

    // Liberar el bloqueo exclusivo del archivo de sesión PHP para que otras solicitudes fluyan sin demora
    session_write_close();

    switch ($action) {
        case 'procore_dashboard':
            // --- PANEL DE CONTROL DE SUBIDAS DIRECTAS A PROCORE ---
            $categories = get_all_categories_from_db();
            $stored_tax = get_config_value('tax_rate');
            $stored_bypass = get_config_value('tax_bypass');
            $effective_tax = ($stored_bypass === '1') ? 0 : (($stored_tax !== false) ? floatval($stored_tax) : 6.5);
            $procore_email = get_config_value('procore_email');
            $has_procore_auth = !empty($procore_email);

            // Métricas de estado de archivos (Evaluación diaria)
            $total_cats = count($categories);
            $ready_files_total = 0;
            $ready_today_files = 0;
            $uploaded_today_count = 0;
            $cat_data = [];
            $today_ymd = date('Y-m-d');

            $global_last_upload = get_config_value('procore_last_upload_global');

            foreach ($categories as $cat) {
                $latest_file = get_latest_excel_for_category($cat);
                $has_file = ($latest_file !== null);
                $is_file_today = false;
                $file_date_fmt = '';

                if ($has_file) {
                    $ready_files_total++;
                    $file_mtime = filemtime($latest_file['path']);
                    $is_file_today = (date('Y-m-d', $file_mtime) === $today_ymd);
                    if ($is_file_today) {
                        $ready_today_files++;
                        $file_date_fmt = 'Hoy ' . date('H:i', $file_mtime);
                    } else {
                        $file_date_fmt = date('m/d/Y H:i', $file_mtime);
                    }
                }

                $cat_upload = get_config_value('procore_last_upload_' . $cat);
                if (!$cat_upload && $global_last_upload) {
                    // Fallback a global si no hay específico
                    $cat_upload = $global_last_upload;
                }
                $is_uploaded_today = ($cat_upload && substr($cat_upload, 0, 10) === $today_ymd);
                if ($is_uploaded_today) {
                    $uploaded_today_count++;
                }

                $cat_data[$cat] = [
                    'has_file' => $has_file,
                    'is_today' => $is_file_today,
                    'file_date_fmt' => $file_date_fmt,
                    'file' => $latest_file,
                    'last_upload' => $cat_upload,
                    'is_uploaded_today' => $is_uploaded_today
                ];
            }

            // Archivos pendientes por generar en el día de hoy
            $pending_today_files = $total_cats - $ready_today_files;

            // 1. BANNER EJECUTIVO
            echo '<div class="report-header-banner" style="margin-bottom: 2rem;">';
            echo '  <div>';
            echo '      <span class="report-subtitle-badge">Automatización & Subida Directa</span>';
            echo '      <h2 class="report-main-title">Centro de Control y Sincronización Procore</h2>';
            echo '      <p class="report-desc-text">';
            echo '          Gestiona, inspecciona y sube a Procore las plantillas generadas para cada categoría. Monitorea el estado diario de actualización y sincronización.';
            echo '      </p>';
            echo '  </div>';
            echo '  <div style="display: flex; gap: 0.75rem; align-items: center;">';
            if ($has_procore_auth) {
                echo '      <span class="account-status status-ok" style="font-size: 0.8rem; padding: 0.4rem 0.9rem;">🟢 Procore: ' . htmlspecialchars($procore_email) . '</span>';
            } else {
                echo '      <a href="configuracion.php" class="account-status status-missing" style="font-size: 0.8rem; padding: 0.4rem 0.9rem; text-decoration: none;">🟠 Conectar Procore</a>';
            }
            echo '  </div>';
            echo '</div>';

            // 2. GRID DE KPIS GLOBALES (Con última subida y evaluación diaria)
            echo '<div class="report-kpi-grid" style="margin-bottom: 2rem;">';
            echo '  <div class="report-kpi-card">';
            echo '      <span class="report-kpi-label">Categorías Configuradas</span>';
            echo '      <span class="report-kpi-val text-primary">' . $total_cats . '</span>';
            echo '      <span class="report-kpi-sub">Catálogos activos en el sistema</span>';
            echo '  </div>';

            echo '  <div class="report-kpi-card" style="border-top: 3px solid ' . ($ready_today_files === $total_cats ? 'var(--emerald-500)' : '#3b82f6') . ';">';
            echo '      <span class="report-kpi-label">Generados Hoy</span>';
            echo '      <span class="report-kpi-val" style="color: ' . ($ready_today_files === $total_cats ? 'var(--emerald-500)' : '#3b82f6') . ';">' . $ready_today_files . ' <span style="font-size: 1rem; font-weight: normal; color: var(--text-secondary);">/ ' . $total_cats . '</span></span>';
            echo '      <span class="report-kpi-sub">' . ($ready_today_files === $total_cats ? '✓ Todos los Excel al día hoy' : $ready_files_total . ' archivos totales en disco') . '</span>';
            echo '  </div>';

            echo '  <div class="report-kpi-card" style="border-top: 3px solid ' . ($pending_today_files > 0 ? '#f59e0b' : 'var(--emerald-500)') . ';">';
            echo '      <span class="report-kpi-label">Pendientes de Hoy</span>';
            echo '      <span class="report-kpi-val" style="color:' . ($pending_today_files > 0 ? '#f59e0b' : 'var(--emerald-500)') . ';">' . $pending_today_files . '</span>';
            echo '      <span class="report-kpi-sub">' . ($pending_today_files > 0 ? 'Requieren generar plantilla hoy' : '✓ Al día para la fecha de hoy') . '</span>';
            echo '  </div>';

            // Tarjeta de Última Subida a Procore
            $upload_kpi_label = 'Última Subida a Procore';
            $upload_kpi_val = 'Sin registro';
            $upload_kpi_sub = 'Ninguna subida registrada';
            $upload_border_color = 'var(--border-subtle)';

            if ($global_last_upload) {
                $is_glob_today = (substr($global_last_upload, 0, 10) === $today_ymd);
                if ($is_glob_today) {
                    $upload_kpi_val = 'Hoy ' . date('H:i', strtotime($global_last_upload));
                    $upload_kpi_sub = "✓ $uploaded_today_count catálogo(s) subido(s) hoy";
                    $upload_border_color = 'var(--emerald-500)';
                } else {
                    $upload_kpi_val = date('m/d/Y H:i', strtotime($global_last_upload));
                    $upload_kpi_sub = 'Pendiente sincronizar hoy';
                    $upload_border_color = '#f59e0b';
                }
            }

            echo '  <div class="report-kpi-card" style="border-top: 3px solid ' . $upload_border_color . ';">';
            echo '      <span class="report-kpi-label">' . $upload_kpi_label . '</span>';
            echo '      <span class="report-kpi-val" style="font-size: 1.45rem; color: ' . ($upload_border_color !== 'var(--border-subtle)' ? $upload_border_color : 'var(--text-primary)') . ';">' . $upload_kpi_val . '</span>';
            echo '      <span class="report-kpi-sub">' . $upload_kpi_sub . '</span>';
            echo '  </div>';

            echo '  <div class="report-kpi-card" style="border-top: 3px solid var(--accent-primary);">';
            echo '      <span class="report-kpi-label">Tax Configurado</span>';
            echo '      <span class="report-kpi-val text-accent">' . ($stored_bypass === '1' ? '0% (Bypass)' : $effective_tax . '%') . '</span>';
            echo '      <span class="report-kpi-sub">Aplicado al costo base</span>';
            echo '  </div>';
            echo '</div>';

            // 3. TARJETAS DE ACCIÓN POR CATEGORÍA
            echo '<div class="report-section-heading">';
            echo '  <h3 style="margin: 0; color: var(--text-primary); font-size: 1.2rem; font-weight: 700;">Catálogos Disponibles para Sincronización</h3>';
            echo '  <span class="text-small text-muted">Sube o descarga directamente las plantillas procesadas con estado diario</span>';
            echo '</div>';

            echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 1.5rem; margin-top: 1rem;">';

            foreach ($categories as $cat) {
                $has_file = $cat_data[$cat]['has_file'];
                $is_file_today = $cat_data[$cat]['is_today'];
                $file_date_fmt = $cat_data[$cat]['file_date_fmt'];
                $latest_file = $cat_data[$cat]['file'];
                $last_upload = $cat_data[$cat]['last_upload'];
                $is_uploaded_today = $cat_data[$cat]['is_uploaded_today'];

                $card_top_border = $is_file_today ? 'var(--emerald-500)' : ($has_file ? '#f59e0b' : 'rgba(255,255,255,0.1)');

                echo '<div class="dashboard-card card-box" style="border-radius: 1.25rem; border: 1px solid var(--border-subtle); background: var(--bg-panel); padding: 1.6rem; display: flex; flex-direction: column; justify-content: space-between; border-top: 4px solid ' . $card_top_border . ';">';

                // Header del card con badge diario
                echo '  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">';
                echo "      <h3 style='margin: 0; font-size: 1.3rem; font-weight: 800; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;'><span>📁</span> $cat</h3>";
                if ($is_file_today) {
                    echo '      <span class="badge-price-down" style="font-size: 0.75rem; padding: 0.3rem 0.75rem;">✓ Generado Hoy</span>';
                } elseif ($has_file) {
                    echo '      <span style="font-size: 0.72rem; padding: 0.28rem 0.7rem; border-radius: 9999px; background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); font-weight: 600;">⚠️ Excel Anterior (Pendiente hoy)</span>';
                } else {
                    echo '      <span class="badge-price-up" style="font-size: 0.75rem; padding: 0.3rem 0.75rem;">Sin Plantilla</span>';
                }
                echo '  </div>';

                // Contenido central
                if ($has_file) {
                    $web_path = str_replace(dirname(__DIR__), '', $latest_file['path']);
                    $web_path = str_replace('\\', '/', $web_path);
                    $web_path = '../' . ltrim($web_path, '/');
                    $file_size_kb = round(filesize($latest_file['path']) / 1024, 1);

                    echo '  <div style="background: var(--bg-input); border: 1px solid var(--border-subtle); border-radius: 0.9rem; padding: 1rem 1.2rem; margin-bottom: 1rem;">';
                    echo "      <div style='display: flex; justify-content: space-between; align-items: center;'>";
                    echo "          <span class='text-small text-muted' style='text-transform: uppercase; font-weight: 600; letter-spacing: 0.04em;'>Última Plantilla Generada</span>";
                    if ($is_file_today) {
                        echo "      <span style='font-size: 0.7rem; font-weight: 700; color: var(--emerald-500); background: rgba(16, 185, 129, 0.12); padding: 0.15rem 0.45rem; border-radius: 4px;'>Al Día</span>";
                    } else {
                        echo "      <span style='font-size: 0.7rem; font-weight: 700; color: #f59e0b; background: rgba(245, 158, 11, 0.12); padding: 0.15rem 0.45rem; border-radius: 4px;'>Anterior</span>";
                    }
                    echo "      </div>";
                    echo "      <div class='text-primary font-mono' style='font-weight: 700; font-size: 0.88rem; margin: 0.3rem 0; word-break: break-all;'>{$latest_file['name']}</div>";
                    echo "      <div style='display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.4rem;'>";
                    echo "          <span>📅 {$file_date_fmt}</span>";
                    echo "          <span>💾 {$file_size_kb} KB</span>";
                    echo "      </div>";
                    echo '  </div>';

                    // Bloque de Última Subida a Procore para la categoría
                    echo '  <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-subtle); border-radius: 0.75rem; padding: 0.7rem 1rem; margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center;">';
                    echo '      <span style="font-size: 0.78rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.35rem;">';
                    echo '          <span>🚀</span> Subida a Procore:';
                    echo '      </span>';
                    if ($last_upload) {
                        $up_today = (substr($last_upload, 0, 10) === $today_ymd);
                        $up_fmt = $up_today ? 'Hoy ' . date('H:i', strtotime($last_upload)) : date('m/d/Y H:i', strtotime($last_upload));
                        $up_color = $up_today ? 'var(--emerald-500)' : '#f59e0b';
                        $up_icon = $up_today ? '✓ ' : '⚠️ ';
                        echo '      <span style="font-size: 0.78rem; font-weight: 700; color: ' . $up_color . ';">' . $up_icon . $up_fmt . '</span>';
                    } else {
                        echo '      <span style="font-size: 0.78rem; color: var(--text-muted);">Sin subida registrada</span>';
                    }
                    echo '  </div>';

                    // Botones de acción principal (Subir y Descargar)
                    echo '  <div style="display: flex; flex-direction: column; gap: 0.75rem;">';

                    // Botón Subir a Procore
                    echo '      <form method="POST" action="ejecutar.php" style="margin: 0;">';
                    echo $csrf_field;
                    echo '          <input type="hidden" name="script" value="upload_to_procore">';
                    echo '          <input type="hidden" name="excel_path" value="' . htmlspecialchars($latest_file['path']) . '">';
                    echo '          <input type="hidden" name="use_saved_creds" value="1">';
                    echo '          <input type="hidden" name="category" value="' . htmlspecialchars($cat) . '">';
                    echo render_headless_toggle('dash_' . $cat, true);
                    echo '          <button type="submit" class="btn-primary" style="width: 100%; background: var(--accent-primary); border-color: var(--accent-primary); padding: 0.9rem; font-size: 0.95rem; font-weight: 700; box-shadow: 0 4px 15px rgba(251, 90, 58, 0.3); display: flex; justify-content: center; align-items: center; gap: 0.5rem;">';
                    echo '              <span>🚀</span> Subir a Procore Ahora';
                    echo '          </button>';
                    echo '      </form>';

                    // Fila secundaria: Descargar y Regenerar
                    echo '      <div style="display: flex; gap: 0.5rem;">';
                    echo "          <a href='$web_path' download class='btn-secondary' style='flex: 1; border: 1px solid var(--border-subtle); text-decoration: none; padding: 0.65rem 0.5rem; font-size: 0.8rem; font-weight: 600; text-align: center; border-radius: 0.6rem; display: flex; align-items: center; justify-content: center; gap: 0.35rem;'>";
                    echo "              <span>⬇</span> Descargar";
                    echo "          </a>";

                    echo '          <form method="POST" action="ejecutar.php" style="flex: 1; margin: 0;">';
                    echo $csrf_field;
                    echo '              <input type="hidden" name="script" value="generate_excel">';
                    echo "              <input type=\"hidden\" name=\"category\" value=\"$cat\">";
                    echo "              <input type=\"hidden\" name=\"tax_rate\" value=\"$effective_tax\">";
                    echo '              <button type="submit" class="btn-secondary" style="width: 100%; border: 1px solid var(--border-subtle); padding: 0.65rem 0.5rem; font-size: 0.8rem; font-weight: 600; border-radius: 0.6rem; display: flex; align-items: center; justify-content: center; gap: 0.35rem;">';
                    echo '                  <span>🔄</span> ' . ($is_file_today ? 'Regenerar' : 'Generar Hoy');
                    echo '              </button>';
                    echo '          </form>';
                    echo '      </div>';
                    echo '  </div>';

                } else {
                    echo '  <div style="background: rgba(245, 158, 11, 0.06); border: 1px dashed rgba(245, 158, 11, 0.3); border-radius: 0.9rem; padding: 1.75rem 1.2rem; text-align: center; margin-bottom: 1.5rem;">';
                    echo '      <span style="font-size: 1.8rem; display: block; margin-bottom: 0.5rem;">📄</span>';
                    echo '      <div style="font-weight: 600; color: var(--text-primary); font-size: 0.9rem;">Sin plantilla generada aún</div>';
                    echo '      <div class="text-small text-muted" style="margin-top: 0.25rem;">Genera la primera plantilla con los precios actuales del catálogo.</div>';
                    echo '  </div>';

                    // Botón para generar Excel Inicial
                    echo '  <form method="POST" action="ejecutar.php" style="margin: 0;">';
                    echo $csrf_field;
                    echo '      <input type="hidden" name="script" value="generate_excel">';
                    echo "      <input type=\"hidden\" name=\"category\" value=\"$cat\">";
                    echo "      <input type=\"hidden\" name=\"tax_rate\" value=\"$effective_tax\">";
                    echo '      <button type="submit" class="btn-primary" style="width: 100%; background-color: var(--emerald-500); border-color: var(--emerald-500); padding: 0.9rem; font-size: 0.95rem; font-weight: 700; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3); display: flex; justify-content: center; align-items: center; gap: 0.5rem;">';
                    echo '          <span>📄</span> Generar Plantilla Inicial';
                    echo '      </button>';
                    echo '  </form>';
                }

                echo '</div>'; // End card
            }
            echo '</div>'; // End grid
            break;

        case 'preview_tax':
            // --- FASE DE VISTA PREVIA ---
            $category = isset($_POST['category']) ? $_POST['category'] : '';
            $stored_bypass = get_config_value('tax_bypass');
            $stored_tax = get_config_value('tax_rate');
            if ($stored_bypass === '1') {
                $tax_rate = 0.0;
            } else {
                $tax_rate = isset($_POST['tax_rate']) ? floatval($_POST['tax_rate']) : ($stored_tax !== false ? floatval($stored_tax) : 6.5);
            }

            if (!$category) {
                echo "<p style='color:red'>Error: Categoría no especificada.</p>";
                break;
            }

            // Normalización para ALL si viene como 'Todos los Catálogos'
            if (strtoupper($category) === 'ALL' || $category === 'Todos los Catálogos') {
                $category = 'ALL';
            }

            // Ejecutar script de python para generar la tabla HTML de vista previa
            $script_preview = realpath(__DIR__ . '/../database/preview_taxes.py');
            $command = "$python_executable " . escapeshellarg($script_preview) . " " . escapeshellarg($category) . " " . escapeshellarg($tax_rate);
            run_command($command, "", false, true); // true al final para renderizar HTML crudo

            // --- BOTÓN FINAL PARA GENERAR EXCEL ---
            echo '<div class="dashboard-card card-box" style="background: var(--bg-panel); padding: 2.5rem; border-radius: 1.5rem; margin-top: 2rem; border: 2px solid var(--accent-primary); text-align: center;">';
            echo '<h3 class="text-accent" style="margin-top: 0; font-weight: 700; font-size: 1.4rem;">¿Todo correcto?</h3>';
            echo "<p class='text-secondary' style='margin-bottom: 2rem; font-size: 1.05rem;'>Si los precios mostrados arriba son correctos, procede a generar el archivo final para Procore.</p>";

            echo '<form method="POST" action="ejecutar.php" style="margin: 0;">';
            echo $csrf_field;
            if ($category === 'ALL') {
                echo '  <input type="hidden" name="script" value="export_prices">';
                echo '  <button type="submit" class="btn-primary" style="background-color: var(--emerald-500); border-color: var(--emerald-500); width: 100%; padding: 1.2rem; font-size: 1.1rem; cursor: pointer; display: flex; justify-content: center; align-items: center; gap: 0.75rem; font-weight: 700;">📊 Exportar Todo el Catálogo a Excel</button>';
            } else {
                echo '  <input type="hidden" name="script" value="generate_excel">';
                echo "  <input type=\"hidden\" name=\"category\" value=\"$category\">";
                echo "  <input type=\"hidden\" name=\"tax_rate\" value=\"$tax_rate\">";
                echo '  <button type="submit" class="btn-primary" style="background-color: var(--emerald-500); border-color: var(--emerald-500); width: 100%; padding: 1.2rem; font-size: 1.1rem; cursor: pointer; display: flex; justify-content: center; align-items: center; gap: 0.75rem; font-weight: 700;">✅ Generar Archivo Excel Final</button>';
            }
            echo '</form>';
            echo '</div>';
            break;

        case 'generate_excel':
            // --- FASE FINAL: GENERACIÓN DE EXCEL ---
            $category = isset($_POST['category']) ? $_POST['category'] : '';
            $tax_rate = isset($_POST['tax_rate']) ? floatval($_POST['tax_rate']) : 6.5;

            // Ejecutar procesar_excel.py pasando el tax rate
            $script_excel = realpath(__DIR__ . '/../scripts/procesar_excel.py');
            $command = "$python_executable " . escapeshellarg($script_excel) . " " . escapeshellarg($category) . " " . escapeshellarg($tax_rate);

            // Capturamos silenciosamente para procesar los datos y evitar el log técnico
            $excel_output = run_command($command, "", true);

            // Buscar y ofrecer descarga
            $file_path = find_excel_path($excel_output);

            // Extraer cantidad de filas actualizadas para el dashboard
            preg_match('/✓ Se actualizaron (\d+) filas/', $excel_output, $rows_match);
            $rows_updated = $rows_match[1] ?? '0';

            if ($file_path) {
                $web_path = str_replace(dirname(__DIR__), '', $file_path);
                $web_path = str_replace('\\', '/', $web_path);
                $web_path = '../' . ltrim($web_path, '/');

                // --- DISEÑO ESTILO VISTA PREVIA (RESULTADO FINAL) ---
                echo '<div class="dashboard-card card-box" style="border: 2px solid var(--accent-primary); margin-top: 1rem;">';
                echo '  <div class="flex-center" style="gap: 1.5rem; margin-bottom: 1.5rem; justify-content: flex-start;">';
                echo '      <div class="alert-box alert-success flex-center" style="font-size: 2.5rem; padding: 1rem; border-radius: 1rem; margin:0; border:none; background:rgba(16, 185, 129, 0.1);">📄</div>';
                echo '      <div style="flex-grow: 1;">';
                echo '          <h2 class="text-primary" style="margin: 0;">¡Excel Generado con Éxito!</h2>';
                echo '          <p class="text-secondary" style="margin: 0.2rem 0 0 0;">Categoría: <strong>' . $category . '</strong> | Tax Aplicado: <strong>' . $tax_rate . '%</strong></p>';
                echo '      </div>';
                echo '      <div class="dict-stat-card" style="border-left: 4px solid var(--accent-primary); min-width: 140px;">';
                echo '          <span class="dict-stat-number text-accent">' . $rows_updated . '</span>';
                echo '          <span class="dict-stat-label">Filas Actualizadas</span>';
                echo '      </div>';
                echo '  </div>';

                echo '  <div class="flex-center" style="gap: 1rem; border-top: 1px solid var(--border-subtle); padding-top: 1.5rem;">';
                echo "      <a href='$web_path' download class='btn-primary' style='background-color: var(--emerald-500); border-color: var(--emerald-500); text-decoration: none; padding: 0.8rem 2rem; font-weight: 700;'><span>⬇</span> Descargar " . basename($file_path) . "</a>";
                echo '  </div>';
                echo '</div>';

                // --- NUEVO FORMULARIO PARA SUBIR A PROCORE ---
                $procore_email_saved = get_config_value('procore_email');
                $has_procore_creds = ($procore_email_saved && get_config_value('procore_password'));

                echo '<div class="dashboard-card card-box" style="margin-top: 2rem; border: 2px solid var(--accent-primary);">';
                echo '<h3 class="text-accent" style="margin-top: 0; display: flex; align-items: center; gap: 0.5rem;"><span>🚀</span> Paso Final: Subir a Procore</h3>';
                echo "<p style='color: var(--text-secondary);'>Confirma el inicio de la sincronización automática con Procore.</p>";

                echo '<form method="POST" action="ejecutar.php">';
                echo $csrf_field;
                echo '  <input type="hidden" name="script" value="upload_to_procore">';
                echo '  <input type="hidden" name="excel_path" value="' . htmlspecialchars($file_path) . '">';
                echo '  <input type="hidden" name="use_saved_creds" value="1">';
                echo '  <input type="hidden" name="category" value="' . htmlspecialchars($category) . '">';
                echo render_headless_toggle('gen_excel', false);

                echo '  <button type="submit" class="btn-primary" style="width: 100%; padding: 1rem; font-size: 1.1rem; cursor: pointer; font-weight: 700; box-shadow: 0 4px 15px rgba(251, 90, 58, 0.3);">Iniciar Automatización Procore</button>';
                echo '</form>';
                echo '</div>';
            } else {
                echo "<h2>Error en la Generación</h2>";
                echo "<p>No se pudo generar el archivo. Revisa el log arriba.</p>";
                echo "<div style='background: #1a1a1a; color: #ff5555; padding: 1rem; border-radius: 5px; margin-top: 1rem; font-family: monospace;'>" . nl2br(htmlspecialchars($excel_output)) . "</div>";
            }
            break;

        case 'upload_to_procore':
            // --- FASE DE SUBIDA A PROCORE CON STREAMING EN VIVO ---
            $excel_path = isset($_POST['excel_path']) ? $_POST['excel_path'] : '';

            // --- SEGURIDAD: VALIDACIÓN DE PATH TRAVERSAL ---
            // Aseguramos que el archivo esté estrictamente dentro de la carpeta de outputs permitida.
            $base_dir = realpath(__DIR__ . '/../data/excel_output');
            $target_file = realpath($excel_path); // realpath devuelve false si no existe

            if ($target_file === false || strpos($target_file, $base_dir) !== 0 || !file_exists($target_file)) {
                echo "<p style='color:red; font-weight:bold;'>Error de Seguridad: El archivo especificado no es válido o intenta acceder a una ruta no permitida.</p>";
                break;
            }

            $is_using_db_creds = (isset($_POST['use_saved_creds']) && $_POST['use_saved_creds'] == '1');
            if ($is_using_db_creds) {
                $procore_email = get_config_value('procore_email');
                $procore_password = get_config_value('procore_password');
            } else {
                $procore_email = isset($_POST['procore_email']) ? $_POST['procore_email'] : '';
                $procore_password = isset($_POST['procore_password']) ? $_POST['procore_password'] : '';
            }

            if (!$procore_email || !$procore_password || !$excel_path) {
                echo "<p style='color:red'>Error: No se encontraron credenciales de Procore configuradas.</p>";
                break;
            }

            $excel_path_escaped = escapeshellarg($excel_path);
            $is_headless = isset($_POST['headless']) && $_POST['headless'] == '1';
            $headless_arg = $is_headless ? '--headless' : '';
            $script_path = realpath(__DIR__ . '/../scripts/procore_uploader.py');

            // SEGURIDAD: Si se usan credenciales guardadas, delegamos la lectura a Python para no exponer contraseñas en CLI
            if ($is_using_db_creds) {
                $command = "$python_executable " . escapeshellarg($script_path) . " --use-db-creds $excel_path_escaped $headless_arg";
            } else {
                $procore_email_escaped = escapeshellarg($procore_email);
                $procore_password_escaped = escapeshellarg($procore_password);
                $command = "$python_executable " . escapeshellarg($script_path) . " $procore_email_escaped $procore_password_escaped $excel_path_escaped $headless_arg";
            }

            $procore_output = '';
            $upload_exit_code = stream_process_output($command, $procore_output);
            if ($upload_exit_code === 0 || strpos($procore_output, '[EXITO]') !== false || strpos($procore_output, 'completada exitosamente') !== false) {
                $now_ts = date('Y-m-d H:i:s');
                set_config_value('procore_last_upload_global', $now_ts);
                if (!empty($_POST['category'])) {
                    set_config_value('procore_last_upload_' . trim($_POST['category']), $now_ts);
                }
            }
            break;

        case 'update_all':
            // --- OBTENER CONFIGURACIÓN DE TAX DE LA DB ---
            $stored_tax = get_config_value('tax_rate');
            $stored_bypass = get_config_value('tax_bypass');
            $tax_to_use = ($stored_bypass === '1') ? 0 : (($stored_tax !== false) ? floatval($stored_tax) : 6.5);
            $categories = get_all_categories_from_db();

            echo '<div class="report-header-banner" style="margin-bottom: 1.5rem;">';
            echo '  <div>';
            echo '      <span class="report-subtitle-badge">Automatización Total en Vivo</span>';
            echo '      <h2 class="report-main-title">Actualización Completa de Catálogos</h2>';
            echo '      <p class="report-desc-text">';
            echo '          Ejecutando la extracción continua de todos los enlaces configurados en una sola sesión de navegador. Impuesto aplicado: <strong>' . ($stored_bypass === '1' ? 'BYPASS (0%)' : $tax_to_use . '%') . '</strong>.';
            echo '      </p>';
            echo '  </div>';
            echo '  <div>';
            echo '      <span class="badge-updated" style="padding: 0.5rem 1.1rem; font-size: 0.88rem; font-weight: 700;">Tax: ' . $tax_to_use . '%</span>';
            echo '  </div>';
            echo '</div>';

            // 1. EXTRACCIÓN DE UN SOLO TIRO CON SCRAPER_GENERIC.PY 'ALL'
            $scraper_script = realpath(__DIR__ . '/../scripts/scraper_generic.py');
            $is_headless = !(isset($_POST['headless']) && ($_POST['headless'] === '0' || $_POST['headless'] === 'false' || $_POST['headless'] === false));
            $headless_flag = $is_headless ? '--headless' : '--visible';
            $command = "$python_executable " . escapeshellarg($scraper_script) . " ALL $headless_flag";

            $scraper_output = '';
            stream_process_output($command, $scraper_output);

            // Si la sesión expiró o hubo error fatal, detener ejecución
            if (check_and_show_session_error($scraper_output) || check_and_show_fatal_error($scraper_output)) {
                break;
            }

            // 2. PASO 2: CONFIRMACIÓN Y VISTA PREVIA DEL CATÁLOGO COMPLETO
            echo '<div class="dashboard-card card-box" style="margin-top: 2rem; background: var(--bg-panel); border: 2px solid var(--accent-primary); overflow: hidden; padding: 1.5rem;">';
            echo '  <h3 class="text-accent" style="margin-top: 0; display: flex; align-items: center; gap: 0.5rem; font-weight: 700;"><span>⚙️</span> Paso 2: Validación de Datos del Catálogo Completo</h3>';
            echo "  <p class='text-secondary' style='margin-bottom: 1.2rem;'>La extracción para <strong>todos los catálogos</strong> ha finalizado correctamente. Confirma los impuestos para continuar a la vista previa.</p>";

            if ($stored_bypass === '1') {
                echo '  <div class="alert-box alert-error" style="padding: 1.2rem; margin-bottom: 1.5rem; border-radius: 0.75rem; font-weight: 600;">⚠ MODO BYPASS ACTIVO: No se aplicarán impuestos (0%).</div>';
            } else {
                echo "  <div class='alert-box alert-success' style='padding: 1.2rem; margin-bottom: 1.5rem; border-radius: 0.75rem; font-weight: 600;'>Se aplicará el impuesto configurado globalmente: <strong>{$tax_to_use}%</strong></div>";
            }

            echo '<div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">';
            echo '  <form method="POST" action="ejecutar.php" style="margin: 0; flex: 1; min-width: 200px;">';
            echo $csrf_field;
            echo '      <input type="hidden" name="script" value="update_all">';
            echo '      <input type="hidden" name="headless" value="1">';
            echo '      <button type="submit" class="btn-secondary" style="width: 100%; padding: 1.15rem; font-size: 1.05rem; display: flex; justify-content: center; align-items: center; gap: 0.5rem; font-weight: 600; border-radius: 0.75rem;"><span>🔄</span> Repetir Scrap</button>';
            echo '  </form>';

            echo '  <form method="POST" action="ejecutar.php" style="margin: 0; flex: 2; min-width: 250px;">';
            echo $csrf_field;
            echo '      <input type="hidden" name="script" value="preview_tax">';
            echo '      <input type="hidden" name="category" value="ALL">';
            echo "      <input type=\"hidden\" name=\"tax_rate\" value=\"$tax_to_use\">";
            echo '      <button type="submit" class="btn-primary" style="width: 100%; padding: 1.15rem; font-size: 1.05rem; display: flex; justify-content: center; align-items: center; gap: 0.5rem; font-weight: 700; border-radius: 0.75rem;">Ver Vista Previa Completa &rarr;</button>';
            echo '  </form>';
            echo '</div>';
            echo '</div>';
            break;

        case 'view_prices':
            // --- OBTENER CONFIGURACIÓN DE TAX DE LA DB ---
            $stored_tax = get_config_value('tax_rate');
            $stored_bypass = get_config_value('tax_bypass');
            $tax_to_use = ($stored_bypass === '1') ? 0 : (($stored_tax !== false) ? floatval($stored_tax) : 6.5);
            $csrf_token_arg = $_SESSION['csrf_token'];

            // Ejecutamos con $raw_output = true para que se renderice la tabla HTML. Pasamos el Tax y el Token como argumentos.
            // CORRECCIÓN: Se agrega 'echo' para que el resultado capturado se imprima en el stream.
            echo run_command("$python_executable ../database/view_prices.py " . escapeshellarg($tax_to_use) . " view " . escapeshellarg($csrf_token_arg), "Tabla de Precios (Tax Aplicado: $tax_to_use%)", true, true);
            break;

        // --- NUEVOS CASOS AUXILIARES ---
        case 'generate_excel_silent':
            // Genera el Excel y fuerza la descarga sin mostrar toda la UI del monitor
            $category = $_POST['category'];
            $tax_rate = $_POST['tax_rate'];

            $command = "$python_executable ../scripts/procesar_excel.py " . escapeshellarg($category) . " " . escapeshellarg($tax_rate);

            // Ejecutamos y capturamos salida
            ob_start();
            passthru($command . " 2>&1");
            $output = ob_get_clean();

            $file_path = find_excel_path($output);

            if ($file_path && file_exists($file_path)) {
                // Forzar descarga del archivo
                $filename = basename($file_path);
                header('Content-Description: File Transfer');
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($file_path));
                readfile($file_path);
                exit;
            } else {
                echo "<h2>Error Generando Excel</h2><pre>$output</pre>";
            }
            break;

        case 'upload_ui':
            // Muestra una UI limpia solo para subir el archivo de una categoría específica
            $category = $_POST['category'];
            echo "<h2>Subir $category a Procore</h2>";
            echo "<p>Selecciona el archivo Excel generado para <strong>$category</strong> y confirma la subida.</p>";

            echo '<form method="POST" action="ejecutar.php">';
            echo $csrf_field;
            echo '  <input type="hidden" name="script" value="upload_to_procore">';
            echo '  <input type="hidden" name="category" value="' . htmlspecialchars($category) . '">';

            // Al ser un entorno local, podemos listar los archivos recientes de la carpeta output
            $output_dir = __DIR__ . '/../data/excel_output';
            $files = glob($output_dir . "/Plantilla_{$category}_Actualizada_*.xlsx");
            usort($files, function ($a, $b) {
                return filemtime($b) - filemtime($a);
            }); // Más recientes primero

            echo '<label style="display:block; margin-bottom:0.5rem;">Archivo a subir (Detectados en carpeta de salida):</label>';
            echo '<select name="excel_path" class="input-std" style="margin-bottom:1rem;">';
            foreach ($files as $f) {
                echo '<option value="' . htmlspecialchars(realpath($f)) . '">' . basename($f) . ' (' . date("H:i:s", filemtime($f)) . ')</option>';
            }
            echo '</select>';

            // Credenciales Procore
            $procore_email = get_config_value('procore_email');
            if ($procore_email) {
                echo "<p>Se usará la cuenta: <strong>$procore_email</strong></p>";
                echo '<input type="hidden" name="use_saved_creds" value="1">';
            } else {
                echo '<input type="email" name="procore_email" placeholder="Email Procore" required class="input-std" style="margin-bottom:0.5rem;">';
                echo '<input type="password" name="procore_password" placeholder="Password Procore" required class="input-std" style="margin-bottom:0.5rem;">';
            }

            echo render_headless_toggle('upload_manual', false);

            echo '<button type="submit" class="btn-primary">Iniciar Subida</button>';
            echo '</form>';
            break;

        case 'upload_to_procore_manual_path':
            // Redirige al caso principal si fuese invocado directamente
            header("Location: index.php");
            exit;

        case 'export_prices':
            // --- GENERAR Y DESCARGAR REPORTE GLOBAL ---
            $stored_tax = get_config_value('tax_rate');
            $stored_bypass = get_config_value('tax_bypass');
            $tax_to_use = ($stored_bypass === '1') ? 0 : (($stored_tax !== false) ? floatval($stored_tax) : 6.5);

            $command = "$python_executable ../database/view_prices.py " . escapeshellarg($tax_to_use) . " export " . escapeshellarg($_SESSION['csrf_token']);
            $excel_output = run_command($command, "Exportando Tabla Completa a Excel...", true);

            $file_path = find_excel_path($excel_output);
            if ($file_path) {
                $web_path = str_replace(dirname(__DIR__), '', $file_path);
                $web_path = str_replace('\\', '/', $web_path);
                $web_path = '../' . ltrim($web_path, '/');

                echo "<div class='dashboard-card card-box' style='border: 2px solid var(--accent-primary); margin-top: 1rem; text-align: center; padding: 2.5rem;'>";
                echo "  <h3 class='text-primary' style='margin-top: 0; font-weight: 700;'>¡Catálogo Exportado!</h3>";
                echo "  <p class='text-secondary' style='margin-bottom: 2rem;'>El reporte completo con tax del {$tax_to_use}% ha sido generado.</p>";
                echo "  <a href='$web_path' download class='btn-primary' style='background-color: var(--emerald-500); border-color: var(--emerald-500); text-decoration: none; padding: 1.2rem 3rem; font-size: 1.1rem; font-weight: 700;'><span>⬇</span> Descargar Excel Completo</a>";
                echo "</div>";
            } else {
                echo "<p style='color: red;'>Error al generar el archivo Excel.</p>";
            }
            break;

        default:
            // --- MANEJO DINÁMICO DE CATEGORÍAS (update_*) ---
            // Si la acción empieza por 'update_' y no es 'update_all' (ya manejado arriba)
            if (strpos($action, 'update_') === 0) {
                $raw_cat = str_replace('update_', '', $action);

                // NORMALIZACIÓN UNIFICADA: Debe ser idéntica a scripts/utils.py
                if (strtolower($raw_cat) === 'wires') {
                    $category = 'Wires';
                } else {
                    $category = strtoupper($raw_cat);
                }

                // Obtener valores por defecto de la DB para el formulario manual
                $def_tax_val = get_config_value('tax_rate');
                if ($def_tax_val === false)
                    $def_tax_val = 6.5;
                $def_bypass = get_config_value('tax_bypass');

                $scraper_script = realpath(__DIR__ . '/../scripts/scraper_generic.py');
                $is_headless = !(isset($_POST['headless']) && ($_POST['headless'] === '0' || $_POST['headless'] === 'false' || $_POST['headless'] === false));
                $headless_flag = $is_headless ? '--headless' : '--visible';
                // El scraper usa storage_state.json con soporte para modo visible o silencioso
                $command = "$python_executable " . escapeshellarg($scraper_script) . " " . escapeshellarg($category) . " $headless_flag";

                // Ejecutar con streaming en tiempo real
                $scraper_output = '';
                stream_process_output($command, $scraper_output);

                // Si la sesion expiro o hubo error fatal, detener antes del Paso 2
                if (check_and_show_session_error($scraper_output) || check_and_show_fatal_error($scraper_output)) {
                    break;
                }

                // --- NUEVO PASO INTERMEDIO: CONFIRMACIÓN DE TAX GLOBAL ---
                $effective_tax = ($def_bypass === '1') ? 0 : floatval($def_tax_val);

                // --- DISEÑO UNIFICADO DE PASO 2 (CORREGIDO) ---
                echo '<div class="dashboard-card card-box" style="margin-top: 2rem; background: var(--bg-panel); border: 2px solid var(--accent-primary); overflow: hidden; padding: 1.5rem;">';
                echo '  <h3 class="text-accent" style="margin-top: 0; display: flex; align-items: center; gap: 0.5rem; font-weight: 700;"><span>⚙️</span> Paso 2: Validación de Datos</h3>';
                echo "  <p class='text-secondary' style='margin-bottom: 1.2rem;'>La extracción para <strong>$category</strong> ha finalizado correctamente. Confirma los impuestos para continuar.</p>";

                if ($def_bypass === '1') {
                    echo '  <div class="alert-box alert-error" style="padding: 1.2rem; margin-bottom: 1.5rem; border-radius: 0.75rem; font-weight: 600;">⚠ MODO BYPASS ACTIVO: No se aplicarán impuestos (0%).</div>';
                } else {
                    echo "  <div class='alert-box alert-success' style='padding: 1.2rem; margin-bottom: 1.5rem; border-radius: 0.75rem; font-weight: 600;'>Se aplicará el impuesto configurado globalmente: <strong>{$effective_tax}%</strong></div>";
                }

                echo '<div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">';
                echo '  <form method="POST" action="ejecutar.php" style="margin: 0; flex: 1; min-width: 200px;">';
                echo $csrf_field;
                echo "      <input type=\"hidden\" name=\"script\" value=\"$action\">";
                echo '      <input type="hidden" name="headless" value="1">';
                echo '      <button type="submit" class="btn-secondary" style="width: 100%; padding: 1.15rem; font-size: 1.05rem; display: flex; justify-content: center; align-items: center; gap: 0.5rem; font-weight: 600; border-radius: 0.75rem;"><span>🔄</span> Repetir Scrap</button>';
                echo '  </form>';

                echo '  <form method="POST" action="ejecutar.php" style="margin: 0; flex: 2; min-width: 250px;">';
                echo $csrf_field;
                echo '      <input type="hidden" name="script" value="preview_tax">';
                echo "      <input type=\"hidden\" name=\"category\" value=\"$category\">";
                echo "      <input type=\"hidden\" name=\"tax_rate\" value=\"$effective_tax\">";
                echo '      <button type="submit" class="btn-primary" style="width: 100%; padding: 1.15rem; font-size: 1.05rem; display: flex; justify-content: center; align-items: center; gap: 0.5rem; font-weight: 700; border-radius: 0.75rem;">Ver Vista Previa y Continuar &rarr;</button>';
                echo '  </form>';
                echo '</div>';
                echo '</div>';

                // Importante: break del switch (aunque estamos dentro del default, es buena práctica)
                break;
            }

            // Si no coincide con nada conocido
            echo "<h1>Error</h1><p>Acción no reconocida: " . htmlspecialchars($action) . "</p>";
            break;
    }
    // Terminamos la ejecución del stream aquí.
    exit();
}

// ==========================================
// MODO 2: MONITOR UI (Frontend)
// ==========================================
// Si no es stream, mostramos la interfaz HTML que iniciará el stream vía JS.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['script'])) {
    header("Location: index.php");
    exit();
}

// VERIFICACIÓN CSRF (Frontend Monitor)
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("Error de Seguridad: Solicitud no autorizada (CSRF Token mismatch).");
}

$action = $_POST['script'];
$is_scraper = (strpos($action, 'update_') === 0);
$is_procore_upload = ($action === 'upload_to_procore' || $action === 'upload_to_procore_manual_path');

// Normalizar nombre de categoría para visualización
$display_category = 'Catálogo';
if ($action === 'update_all') {
    $display_category = 'Todos los Catálogos';
} elseif ($is_scraper) {
    $raw_c = str_replace('update_', '', $action);
    $display_category = (strtolower($raw_c) === 'wires') ? 'Wires' : strtoupper($raw_c);
} elseif ($is_procore_upload) {
    $raw_cat = isset($_POST['category']) ? $_POST['category'] : '';
    if (!empty($raw_cat)) {
        $display_category = (strtolower($raw_cat) === 'wires') ? 'Wires' : strtoupper($raw_cat);
    } elseif (isset($_POST['excel_path'])) {
        $fname = basename($_POST['excel_path']);
        if (preg_match('/Plantilla_([^_]+)_/', $fname, $m)) {
            $display_category = (strtolower($m[1]) === 'wires') ? 'Wires' : strtoupper($m[1]);
        } else {
            $display_category = 'Cost Catalog';
        }
    } else {
        $display_category = 'Cost Catalog';
    }
}

// Obtener configuración de impuestos
$stored_tax = get_config_value('tax_rate');
$stored_bypass = get_config_value('tax_bypass');
$effective_tax = ($stored_bypass === '1') ? 0 : (($stored_tax !== false) ? floatval($stored_tax) : 6.5);

$post_data_json = json_encode($_POST);
?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php
    if ($is_procore_upload) {
        echo "Subida Procore: " . htmlspecialchars($display_category) . " | Brightronix";
    } elseif ($is_scraper) {
        echo "Monitor: " . htmlspecialchars($display_category) . " | Brightronix";
    } else {
        echo "Brightronix Catalog Updater";
    }
    ?></title>
    <link rel="stylesheet" href="../css/style.css?v=<?php echo filemtime(__DIR__ . '/../css/style.css'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800;900&display=swap"
        rel="stylesheet">
    <script src="app-dialogs.js"></script>
</head>

<body class="monitor-mode">

    <!-- Defs SVG para degradados de círculos -->
    <svg width="0" height="0" style="position: absolute; pointer-events: none;">
        <defs>
            <linearGradient id="loaderGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="#fb5a3a" />
                <stop offset="100%" stop-color="#f59e0b" />
            </linearGradient>
            <linearGradient id="loaderGradientSuccess" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="#10b981" />
                <stop offset="100%" stop-color="#059669" />
            </linearGradient>
        </defs>
    </svg>

    <!-- Navbar Superior con Botón Volver Accesible -->
    <nav class="navbar" style="position: sticky; top: 0; z-index: 1000;">
        <div class="logo-container">
            <a href="index.php"
                style="text-decoration: none; color: inherit; display: flex; flex-direction: column; align-items: flex-start;">
                <img src="../css/logo-text.png" alt="Brightronix Logo" class="logo-full">
                <span class="app-subtitle">Catalog Updater</span>
            </a>
        </div>
        <div class="nav-actions">
            <a href="index.php" class="btn-back-nav" id="btn-top-back" title="Volver al Panel Principal">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                <span>Volver al Panel</span>
            </a>
        </div>
    </nav>

    <?php if ($is_scraper): ?>
        <!-- ==========================================
         VISTA DE MONITOREO EN VIVO DEL SCRAPER
         ========================================== -->
        <div class="scraper-monitor-container">
            <div class="scraper-hero-card">

                <div
                    style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; border-bottom: 1px solid var(--border-subtle); padding-bottom: 1rem;">
                    <div style="text-align: left;">
                        <span class="text-small text-muted"
                            style="text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Scraper en
                            Vivo</span>
                        <h2 style="margin: 0.2rem 0 0 0; color: var(--text-primary); font-size: 1.5rem;">
                            Extracción: <span class="text-accent"><?php echo htmlspecialchars($display_category); ?></span>
                        </h2>
                    </div>
                    <span id="badge-live-status" class="badge-updated"
                        style="padding: 0.45rem 1rem; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 700;">
                        <span class="terminal-live-dot" id="live-indicator-dot"></span>
                        <span id="badge-live-text">En Ejecución</span>
                    </span>
                </div>

                <!-- Círculo de Carga Animado -->
                <div class="circular-loader-section">
                    <div class="circular-progress-wrap">
                        <svg class="circular-progress-svg" viewBox="0 0 140 140">
                            <circle class="circle-bg" cx="70" cy="70" r="60"></circle>
                            <circle id="circle-progress-bar" class="circle-bar spinning" cx="70" cy="70" r="60"></circle>
                        </svg>
                        <div class="circular-center-content">
                            <div id="circle-icon-pulse" class="circular-icon-pulse">⚡</div>
                            <div id="circle-icon-check" class="circular-success-check">✓</div>
                            <div id="circle-pct-label"
                                style="font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); margin-top: 0.2rem;">
                                0%</div>
                        </div>
                    </div>

                    <!-- Subtítulo Dinámico de Estado -->
                    <div id="live-status-subtitle" class="live-status-subtitle">Iniciando scraper y verificando sesión...
                    </div>
                    <div id="live-status-detail" class="live-status-detail">Conectando a Rexel USA...</div>
                </div>

                <!-- Grid de Métricas en Vivo -->
                <div class="live-metrics-bar">
                    <div class="metric-pill">
                        <span id="counter-urls" class="metric-pill-val">0 / 0</span>
                        <span class="metric-pill-label">Enlaces</span>
                    </div>
                    <div class="metric-pill">
                        <span id="counter-detected" class="metric-pill-val">0</span>
                        <span class="metric-pill-label">Detectados</span>
                    </div>
                    <div class="metric-pill">
                        <span id="counter-updated" class="metric-pill-val" style="color: var(--emerald-500);">0</span>
                        <span class="metric-pill-label">Actualizados</span>
                    </div>
                    <div class="metric-pill">
                        <span id="counter-unmapped" class="metric-pill-val" style="color: var(--amber-500);">0</span>
                        <span class="metric-pill-label">No Mapeados</span>
                    </div>
                </div>

                <!-- Botón Sticky para Continuar a Vista Previa cuando finalice -->
                <div id="post-finish-banner"
                    style="display: none; background: rgba(16, 185, 129, 0.1); border: 1px solid var(--emerald-500); border-radius: 1rem; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div style="text-align: left;">
                        <strong style="color: var(--emerald-500); font-size: 1rem; display: block;">¡Extracción Finalizada con Éxito!</strong>
                        <span style="font-size: 0.85rem; color: var(--text-secondary);">Los precios han sido actualizados en la base de datos.</span>
                    </div>
                    <div style="display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" onclick="document.getElementById('form-repeat-scraper').submit();" style="font-weight: 600; padding: 0.8rem 1.25rem; border-radius: 0.65rem; display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.9rem;">
                            <span>🔄</span> Repetir Scrap
                        </button>
                        <form method="POST" action="ejecutar.php" style="margin: 0;">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="script" value="preview_tax">
                            <input type="hidden" name="category" id="banner-cat-input"
                                value="<?php echo ($action === 'update_all') ? 'ALL' : htmlspecialchars($display_category); ?>">
                            <input type="hidden" name="tax_rate" id="banner-tax-input"
                                value="<?php echo htmlspecialchars($effective_tax); ?>">
                            <button type="submit" class="btn-primary"
                                style="background: var(--emerald-500); border-color: var(--emerald-500); padding: 0.8rem 1.5rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.5rem; border-radius: 0.65rem;">
                                <span>Ir a Vista Previa y Taxes</span>
                                <span>&rarr;</span>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Consola Terminal en Vivo -->
                <div class="live-terminal-container">
                    <div class="live-terminal-header">
                        <div class="terminal-title-wrap">
                            <span class="terminal-live-dot" id="terminal-pulse-dot"></span>
                            <span>Consola de Ejecución en Tiempo Real</span>
                        </div>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <button type="button" class="btn-mini" onclick="toggleAutoScroll()" id="btn-autoscroll"
                                title="Alternar scroll automático">Auto-scroll: ON</button>
                            <button type="button" class="btn-mini" onclick="clearLiveTerminal()"
                                title="Limpiar salida de consola">Limpiar</button>
                        </div>
                    </div>
                    <div id="live-terminal-body" class="live-terminal-body">
                        <div class="term-line term-header">> Iniciando proceso de extracción...</div>
                    </div>
                </div>

                <!-- Formulario Oculto para Repetir Scrap (invocado desde modal y banner de finalización) -->
                <form method="POST" action="ejecutar.php" id="form-repeat-scraper" style="display: none;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="script" value="<?php echo htmlspecialchars($action); ?>">
                    <input type="hidden" name="headless" value="1">
                </form>

            </div>
        </div>

        <!-- ==========================================
         MODAL DE RESUMEN DE RESULTADOS DEL SCRAPER
         ========================================== -->
        <div id="summary-modal-overlay" class="summary-modal-overlay">
            <div class="summary-modal-card">
                <div class="summary-modal-header">
                    <div>
                        <span class="text-small text-muted"
                            style="text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Resultado del
                            Scraper</span>
                        <h3 class="summary-modal-title">
                            <span>🎉</span> ¡Extracción Completada!
                        </h3>
                    </div>
                    <span id="modal-cat-badge" class="badge-updated"
                        style="font-size: 0.85rem; padding: 0.35rem 0.8rem; font-weight: 700;">
                        <?php echo htmlspecialchars($display_category); ?>
                    </span>
                </div>

                <div class="summary-modal-body">
                    <div class="summary-stats-grid">
                        <div class="summary-stat-box">
                            <span id="modal-stat-urls" class="summary-stat-val text-primary">0</span>
                            <span class="summary-stat-lbl">Enlaces Procesados</span>
                        </div>
                        <div class="summary-stat-box">
                            <span id="modal-stat-detected" class="summary-stat-val text-primary">0</span>
                            <span class="summary-stat-lbl">Productos Detectados</span>
                        </div>
                        <div class="summary-stat-box" style="border-color: rgba(16, 185, 129, 0.4);">
                            <span id="modal-stat-updated" class="summary-stat-val"
                                style="color: var(--emerald-500);">0</span>
                            <span class="summary-stat-lbl">Actualizados en BD</span>
                        </div>
                        <div class="summary-stat-box">
                            <span id="modal-stat-time" class="summary-stat-val text-primary">0s</span>
                            <span class="summary-stat-lbl">Tiempo de Ejecución</span>
                        </div>
                    </div>

                    <div class="summary-tax-callout">
                        <div style="font-size: 1.5rem;">⚙️</div>
                        <div style="text-align: left;">
                            <div style="font-weight: 700; color: var(--text-primary);">Configuración de Impuestos</div>
                            <div id="modal-tax-text"
                                style="font-size: 0.82rem; color: var(--text-secondary); margin-top: 2px;">
                                <?php if ($stored_bypass === '1'): ?>
                                    <strong>Modo Bypass Activo:</strong> Se aplicará 0% de tax.
                                <?php else: ?>
                                    Se aplicará el impuesto configurado globalmente:
                                    <strong><?php echo $effective_tax; ?>%</strong>.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 1.25rem 0 0 0; text-align: center;">
                        Pasa a la vista previa para comprobar qué productos se actualizaron y verificar los impuestos
                        aplicados.
                    </p>
                </div>

                <div class="summary-modal-footer" style="display: flex; gap: 0.6rem; align-items: center; justify-content: flex-end; flex-wrap: wrap;">
                    <button type="button" class="btn-secondary" onclick="closeSummaryModal()">Ver Log de Consola</button>

                    <form method="POST" action="ejecutar.php" id="modal-preview-form" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="script" value="preview_tax">
                        <input type="hidden" name="category" id="modal-preview-cat"
                            value="<?php echo ($action === 'update_all') ? 'ALL' : htmlspecialchars($display_category); ?>">
                        <input type="hidden" name="tax_rate" id="modal-preview-tax"
                            value="<?php echo htmlspecialchars($effective_tax); ?>">

                        <button type="submit" class="btn-primary"
                            style="background: var(--emerald-500); border-color: var(--emerald-500); padding: 0.85rem 1.75rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.95rem; border-radius: 0.65rem;">
                            <span>Continuar a Vista Previa y Taxes</span>
                            <span>&rarr;</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

    <?php elseif ($is_procore_upload): ?>
        <!-- ==========================================
         VISTA DE MONITOREO EN VIVO: SUBIDA PROCORE
         ========================================== -->
        <div class="scraper-monitor-container">
            <div class="scraper-hero-card">

                <div
                    style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-subtle); padding-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem;">
                    <div style="text-align: left;">
                        <span class="text-small text-muted"
                            style="text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Automatización
                            Procore</span>
                        <h2
                            style="margin: 0.2rem 0 0 0; color: var(--text-primary); font-size: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                            <span>🚀</span> Sincronización: <span
                                class="text-accent"><?php echo htmlspecialchars($display_category); ?></span>
                        </h2>
                    </div>
                    <div style="display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap;">
                        <span id="badge-procore-mode" class="badge-updated"
                            style="padding: 0.45rem 0.85rem; font-size: 0.78rem; font-weight: 700; background: var(--bg-surface);">
                            <?php echo (isset($_POST['headless']) && $_POST['headless'] == '1') ? '⚡ Modo Silencioso (Headless)' : '🖥️ Modo Supervisado (Ventana)'; ?>
                        </span>
                        <span id="badge-procore-status" class="badge-updated"
                            style="padding: 0.45rem 1rem; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 700;">
                            <span class="terminal-live-dot" id="procore-indicator-dot"></span>
                            <span id="badge-procore-text">Iniciando...</span>
                        </span>
                    </div>
                </div>

                <!-- Stepper Interactivo de 4 Etapas -->
                <div class="procore-stepper">
                    <div class="procore-stepper-progress" id="procore-stepper-bar"></div>
                    <div class="procore-step-item active" id="procore-step-1">
                        <div class="procore-step-circle">1</div>
                        <div class="procore-step-label">Autenticación</div>
                    </div>
                    <div class="procore-step-item" id="procore-step-2">
                        <div class="procore-step-circle">2</div>
                        <div class="procore-step-label">Cost Catalog</div>
                    </div>
                    <div class="procore-step-item" id="procore-step-3">
                        <div class="procore-step-circle">3</div>
                        <div class="procore-step-label">Menú Importar</div>
                    </div>
                    <div class="procore-step-item" id="procore-step-4">
                        <div class="procore-step-circle">4</div>
                        <div class="procore-step-label">Sincronización</div>
                    </div>
                </div>

                <!-- Círculo de Carga Animado -->
                <div class="circular-loader-section">
                    <div class="circular-progress-wrap">
                        <svg class="circular-progress-svg" viewBox="0 0 140 140">
                            <circle class="circle-bg" cx="70" cy="70" r="60"></circle>
                            <circle id="procore-circle-bar" class="circle-bar spinning" cx="70" cy="70" r="60"></circle>
                        </svg>
                        <div class="circular-center-content">
                            <div id="procore-circle-pulse" class="circular-icon-pulse">🚀</div>
                            <div id="procore-circle-check" class="circular-success-check">✓</div>
                            <div id="procore-circle-pct"
                                style="font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); margin-top: 0.2rem;">
                                0%</div>
                        </div>
                    </div>

                    <!-- Subtítulo Dinámico de Estado -->
                    <div id="procore-status-title" class="live-status-subtitle">Iniciando entorno de subida...</div>
                    <div id="procore-status-detail" class="live-status-detail font-mono">
                        <?php echo htmlspecialchars(basename(isset($_POST['excel_path']) ? $_POST['excel_path'] : '')); ?>
                    </div>
                </div>

                <!-- Botón Sticky para Continuar o Volver cuando finalice -->
                <div id="procore-post-finish-banner"
                    style="display: none; background: rgba(16, 185, 129, 0.1); border: 1px solid var(--emerald-500); border-radius: 1rem; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div style="text-align: left;">
                        <strong style="color: var(--emerald-500); font-size: 1rem; display: block;">🎉 ¡Catálogo
                            Sincronizado Exitosamente con Procore!</strong>
                        <span style="font-size: 0.85rem; color: var(--text-secondary);">El archivo fue procesado e importado
                            en la herramienta Cost Catalog.</span>
                    </div>
                    <form method="POST" action="ejecutar.php" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="script" value="procore_dashboard">
                        <button type="submit" class="btn-primary"
                            style="background: var(--emerald-500); border-color: var(--emerald-500); padding: 0.8rem 1.5rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.5rem;">
                            <span>Ir al Centro de Control Procore</span>
                            <span>&rarr;</span>
                        </button>
                    </form>
                </div>

                <!-- Consola Terminal en Vivo de Procore -->
                <div class="live-terminal-container">
                    <div class="live-terminal-header">
                        <div class="terminal-title-wrap">
                            <span class="terminal-live-dot" id="procore-term-dot"></span>
                            <span>Consola de Ejecución en Tiempo Real (Procore)</span>
                        </div>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <button type="button" class="btn-mini" onclick="toggleAutoScroll()" id="btn-autoscroll"
                                title="Alternar scroll automático">Auto-scroll: ON</button>
                            <button type="button" class="btn-mini" onclick="clearLiveTerminal()"
                                title="Limpiar salida de consola">Limpiar</button>
                        </div>
                    </div>
                    <div id="live-terminal-body" class="live-terminal-body">
                        <div class="term-line term-header">> Conectando con el script de automatización Procore...</div>
                    </div>
                </div>

                <!-- Contenedor para Captura Diagnóstica en caso de Timeout o Error -->
                <div id="procore-screenshot-box" class="procore-screenshot-card" style="display: none;">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                        <div>
                            <h4 style="margin: 0; color: #ef4444; display: flex; align-items: center; gap: 0.5rem;">
                                <span>📸</span> Captura Diagnóstica de Pantalla (Procore)
                            </h4>
                            <span style="font-size: 0.8rem; color: var(--text-secondary);">Esta imagen refleja exactamente
                                lo que vio el navegador antes de detenerse.</span>
                        </div>
                        <a id="procore-screenshot-link" href="../data/excel_output/procore_error_screenshot.png"
                            target="_blank" class="btn-mini" style="text-decoration: none; padding: 0.4rem 0.8rem;">
                            Ver en tamaño completo ↗
                        </a>
                    </div>
                    <img id="procore-screenshot-img" src="../data/excel_output/procore_error_screenshot.png"
                        alt="Captura Diagnóstica" class="procore-screenshot-preview">
                </div>

            </div>
        </div>

        <!-- ==========================================
         MODAL DE RESUMEN DE SUBIDA A PROCORE
         ========================================== -->
        <div id="procore-modal-overlay" class="summary-modal-overlay">
            <div class="summary-modal-card">
                <div class="summary-modal-header">
                    <div>
                        <span class="text-small text-muted"
                            style="text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Sincronización
                            Exitosa</span>
                        <h3 class="summary-modal-title">
                            <span>🎉</span> ¡Subida a Procore Completada!
                        </h3>
                    </div>
                    <span class="badge-updated" style="font-size: 0.85rem; padding: 0.35rem 0.8rem; font-weight: 700;">
                        <?php echo htmlspecialchars($display_category); ?>
                    </span>
                </div>

                <div class="summary-modal-body">
                    <div
                        style="background: var(--bg-input); border: 1px solid var(--border-subtle); border-radius: 0.8rem; padding: 1.25rem; margin-bottom: 1.5rem; text-align: left;">
                        <div class="text-small text-muted" style="text-transform: uppercase; font-weight: 600;">Archivo
                            Importado en Cost Catalog</div>
                        <div class="text-primary font-mono"
                            style="font-weight: 700; font-size: 0.95rem; margin: 0.35rem 0; word-break: break-all;">
                            <?php echo htmlspecialchars(basename(isset($_POST['excel_path']) ? $_POST['excel_path'] : '')); ?>
                        </div>
                        <div
                            style="font-size: 0.8rem; color: var(--emerald-500); margin-top: 0.5rem; display: flex; align-items: center; gap: 0.4rem;">
                            <span>✓</span> El archivo fue entregado, validado y cerrado en Procore.
                        </div>
                    </div>

                    <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0; text-align: center;">
                        Puedes regresar al Centro de Control de Procore para gestionar otras categorías o volver al panel
                        principal.
                    </p>
                </div>

                <div class="summary-modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeProcoreModal()">Ver Log</button>

                    <form method="POST" action="ejecutar.php" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="script" value="procore_dashboard">
                        <button type="submit" class="btn-primary"
                            style="background: var(--emerald-500); border-color: var(--emerald-500); padding: 0.85rem 1.75rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.95rem;">
                            <span>Volver a Procore Dashboard</span>
                            <span>&rarr;</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- ==========================================
         VISTA ESTÁNDAR (PREVIEW TAX, GENERATE EXCEL, PROCORE)
         ========================================== -->
        <div
            class="output-container <?php echo in_array($action, ['view_prices', 'preview_tax', 'procore_dashboard']) ? 'report-mode' : ''; ?>">
            <div class="monitor-header">
                <h1 style="margin: 0; font-size: 1.35rem; display: flex; align-items: center; gap: 0.75rem;">
                    <?php if ($action === 'view_prices'): ?>
                        <span>📊 Reporte e Información de Precios</span>
                    <?php elseif ($action === 'preview_tax'): ?>
                        <span>🔍 Revisión de Precios e Impuestos (Tax Preview)</span>
                    <?php elseif ($action === 'procore_dashboard'): ?>
                        <span>🚀 Centro de Control de Subida Procore</span>
                    <?php else: ?>
                        <span>Monitor: <?php echo htmlspecialchars($action); ?></span>
                    <?php endif; ?>
                    <span class="loading-pulse" id="status-indicator"></span>
                </h1>
                <div class="monitor-actions">
                    <?php if ($action === 'view_prices'): ?>
                        <form method="POST" action="ejecutar.php" style="margin:0;">
                            <input type="hidden" name="script" value="export_prices">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="monitor-content">
                <div id="terminal-output"></div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ==========================================
     LÓGICA JAVASCRIPT DE STREAMING Y EVENTOS
     ========================================== -->
    <script>
        let autoScrollEnabled = true;
        const isScraperMode = <?php echo $is_scraper ? 'true' : 'false'; ?>;
        const isProcoreMode = <?php echo $is_procore_upload ? 'true' : 'false'; ?>;
        const defaultTax = <?php echo json_encode($effective_tax); ?>;
        let activeCategory = <?php echo json_encode($display_category); ?>;

        // Métricas acumuladas del frontend
        let metricUrlsCurrent = 0;
        let metricUrlsTotal = 0;
        let metricDetectedTotal = 0;
        let metricUpdatedTotal = 0;
        let metricUnmappedTotal = 0;

        function toggleAutoScroll() {
            autoScrollEnabled = !autoScrollEnabled;
            const btn = document.getElementById("btn-autoscroll");
            if (btn) btn.textContent = "Auto-scroll: " + (autoScrollEnabled ? "ON" : "OFF");
        }

        function clearLiveTerminal() {
            const term = document.getElementById("live-terminal-body");
            if (term) term.innerHTML = '<div class="term-line term-header">> Consola limpiada por el usuario.</div>';
        }

        function openSummaryModal(summaryData) {
            const overlay = document.getElementById("summary-modal-overlay");
            if (!overlay) return;

            if (summaryData) {
                const targetCat = (summaryData.category === "ALL" || activeCategory === "Todos los Catálogos") ? "ALL" : summaryData.category;
                if (summaryData.category) {
                    document.getElementById("modal-cat-badge").textContent = (summaryData.category === "ALL") ? "Todos los Catálogos" : summaryData.category;
                    document.getElementById("modal-preview-cat").value = targetCat;
                    const bannerCat = document.getElementById("banner-cat-input");
                    if (bannerCat) bannerCat.value = targetCat;
                }
                if (summaryData.total_urls !== undefined) {
                    document.getElementById("modal-stat-urls").textContent = summaryData.total_urls;
                }
                if (summaryData.detected !== undefined) {
                    document.getElementById("modal-stat-detected").textContent = summaryData.detected;
                }
                if (summaryData.updated !== undefined) {
                    document.getElementById("modal-stat-updated").textContent = summaryData.updated;
                }
                if (summaryData.duration !== undefined) {
                    document.getElementById("modal-stat-time").textContent = summaryData.duration + "s";
                }
            }

            overlay.classList.add("active");

            // Mostrar también el banner secundario debajo del card
            const banner = document.getElementById("post-finish-banner");
            if (banner) banner.style.display = "flex";
        }

        function closeSummaryModal() {
            const overlay = document.getElementById("summary-modal-overlay");
            if (overlay) overlay.classList.remove("active");
        }

        function openProcoreModal() {
            const overlay = document.getElementById("procore-modal-overlay");
            if (overlay) overlay.classList.add("active");
            const banner = document.getElementById("procore-post-finish-banner");
            if (banner) banner.style.display = "flex";
        }

        function closeProcoreModal() {
            const overlay = document.getElementById("procore-modal-overlay");
            if (overlay) overlay.classList.remove("active");
        }

        function setProcoreStep(stepNum, percent, title, detail) {
            const procoreBar = document.getElementById("procore-circle-bar");
            const procorePct = document.getElementById("procore-circle-pct");
            const procoreTitle = document.getElementById("procore-status-title");
            const procoreDetail = document.getElementById("procore-status-detail");
            const stepperBar = document.getElementById("procore-stepper-bar");
            const CIRCUMFERENCE = 377;

            if (procoreBar) {
                const clamped = Math.max(0, Math.min(100, percent));
                const offset = CIRCUMFERENCE - (clamped / 100) * CIRCUMFERENCE;
                procoreBar.style.strokeDashoffset = offset;
                if (procorePct) procorePct.textContent = Math.round(clamped) + "%";
            }

            if (title && procoreTitle) procoreTitle.textContent = title;
            if (detail && procoreDetail) procoreDetail.textContent = detail;

            if (stepperBar) {
                const widths = { 1: "0%", 2: "33%", 3: "66%", 4: "100%" };
                stepperBar.style.width = widths[stepNum] || "0%";
            }

            for (let i = 1; i <= 4; i++) {
                const el = document.getElementById("procore-step-" + i);
                if (!el) continue;
                const circle = el.querySelector(".procore-step-circle");
                el.classList.remove("active", "completed");
                if (i < stepNum) {
                    el.classList.add("completed");
                    if (circle) circle.textContent = "✓";
                } else if (i === stepNum) {
                    el.classList.add("active");
                    if (circle) circle.textContent = i;
                } else {
                    if (circle) circle.textContent = i;
                }
            }
        }

        // Control interactivo del Switch Headless
        window.toggleHeadlessClick = function (id, event, isCompact) {
            const input = document.getElementById('headless-input-' + id);
            if (!input) return;
            input.checked = !input.checked;
            window.toggleHeadlessSwitch(id, input, isCompact);
        };

        window.toggleHeadlessSwitch = function (id, el, isCompact) {
            const card = document.getElementById('headless-card-' + id);
            const title = document.getElementById('headless-title-' + id);
            const badge = document.getElementById('headless-badge-' + id);
            const desc = document.getElementById('headless-desc-' + id);

            if (el.checked) {
                if (card) card.classList.add('active');
                if (title) title.innerHTML = '<span>⚡</span> Modo Silencioso (Headless)';
                if (badge) badge.textContent = 'Activo';
                if (desc) desc.textContent = isCompact
                    ? 'Segundo plano • Ahorro de memoria y CPU'
                    : 'Ejecuta en segundo plano sin ventana externa (Ahorra memoria y CPU)';
            } else {
                if (card) card.classList.remove('active');
                if (title) title.innerHTML = '<span>🖥️</span> Modo Supervisado';
                if (badge) badge.textContent = 'Ventana Visible';
                if (desc) desc.textContent = isCompact
                    ? 'Ventana externa visible'
                    : 'Se abrirá la ventana gráfica del navegador para supervisión';
            }
        };

        // ==========================================================
        // FUNCIONES GLOBALES DE FILTRADO PARA REPORTES Y VISTA PREVIA
        // ==========================================================
        window.selectedCategory = 'all';
        window.selectedBehavior = 'all';

        window.filterByCategory = function (cat, btnEl) {
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

            window.applyReportFilters();
        };

        window.filterByBehavior = function (beh, btnEl) {
            window.selectedBehavior = beh;
            document.querySelectorAll('.filter-chip').forEach(b => b.classList.remove('active'));
            if (btnEl) btnEl.classList.add('active');
            window.applyReportFilters();
        };

        window.applyReportFilters = function () {
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

        window.previewBehavior = 'all';

        window.filterPreviewByBehavior = function (beh, btnEl) {
            window.previewBehavior = beh;
            document.querySelectorAll('.filter-chip').forEach(b => b.classList.remove('active'));
            if (btnEl) btnEl.classList.add('active');
            window.applyPreviewFilters();
        };

        window.applyPreviewFilters = function () {
            const searchInput = document.getElementById('table-search');
            const term = searchInput ? searchInput.value.toUpperCase().trim() : '';
            const rows = document.querySelectorAll('.preview-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const beh = row.getAttribute('data-behavior');
                const text = row.innerText.toUpperCase();

                const matchesBeh = (window.previewBehavior === 'all' || beh === window.previewBehavior);
                const matchesText = (!term || text.indexOf(term) > -1);

                if (matchesBeh && matchesText) {
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

        document.addEventListener("DOMContentLoaded", function () {
            const postData = <?php echo $post_data_json; ?>;
            const formData = new FormData();
            for (const key in postData) {
                formData.append(key, postData[key]);
            }

            // Elementos del DOM para el monitor del scraper
            const liveSubtitle = document.getElementById("live-status-subtitle");
            const liveDetail = document.getElementById("live-status-detail");
            const circleBar = document.getElementById("circle-progress-bar");
            const circlePctLabel = document.getElementById("circle-pct-label");
            const circlePulseIcon = document.getElementById("circle-icon-pulse");
            const circleSuccessIcon = document.getElementById("circle-icon-check");
            const counterUrls = document.getElementById("counter-urls");
            const counterDetected = document.getElementById("counter-detected");
            const counterUpdated = document.getElementById("counter-updated");
            const counterUnmapped = document.getElementById("counter-unmapped");
            const terminalBody = document.getElementById("live-terminal-body");
            const badgeLiveText = document.getElementById("badge-live-text");
            const badgeLiveStatus = document.getElementById("badge-live-status");
            const liveIndicatorDot = document.getElementById("live-indicator-dot");

            // Elementos del DOM para vista estándar
            const standardTerminal = document.getElementById("terminal-output");
            const standardIndicator = document.getElementById("status-indicator");

            // Constante circunferencia del círculo (r=60 -> 2*PI*60 ≈ 376.99)
            const CIRCUMFERENCE = 377;

            function setCircleProgress(percent) {
                if (!circleBar) return;
                const clamped = Math.max(0, Math.min(100, percent));
                const offset = CIRCUMFERENCE - (clamped / 100) * CIRCUMFERENCE;
                circleBar.style.strokeDashoffset = offset;
                if (circlePctLabel) circlePctLabel.textContent = Math.round(clamped) + "%";
            }

            function appendTerminalLine(text, typeClass) {
                if (!terminalBody) return;
                const lineEl = document.createElement("div");
                lineEl.className = "term-line " + (typeClass || "");
                lineEl.textContent = text;
                terminalBody.appendChild(lineEl);

                if (autoScrollEnabled) {
                    terminalBody.scrollTop = terminalBody.scrollHeight;
                }
            }

            let lineBuffer = "";
            let reportBuffer = "";
            let summaryFound = false;
            let streamInScript = false;
            let streamInStyle = false;
            const isReportView = (postData.script === "view_prices" || postData.script === "preview_tax" || postData.script === "procore_dashboard");

            if (isReportView && standardTerminal) {
                standardTerminal.innerHTML = `
                    <div class="report-loading-container" style="text-align: center; padding: 4rem 1rem;">
                        <div class="loading-spinner" style="width: 44px; height: 44px; border: 3px solid rgba(251,90,58,0.2); border-top-color: var(--accent-primary); border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 1.25rem auto;"></div>
                        <div style="font-weight: 700; font-size: 1.15rem; color: var(--text-primary);">Cargando y estructurando tabla...</div>
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.4rem;">Preparando visualización completa con estilos aplicados</div>
                    </div>`;
            }

            // Iniciar conexión de streaming
            fetch("ejecutar.php?stream=1", {
                method: "POST",
                body: formData
            }).then(response => {
                const reader = response.body.getReader();
                const decoder = new TextDecoder();

                function read() {
                    reader.read().then(({ done, value }) => {
                        if (done) {
                            if (badgeLiveText) badgeLiveText.textContent = "Completado";
                            if (liveIndicatorDot) liveIndicatorDot.style.animation = "none";
                            if (standardIndicator) standardIndicator.style.display = "none";

                            // En modo reporte, inyectar el HTML completo de una sola vez para que el CSS
                            // calcule la tabla al 100% sin desbordamientos ni saltos visuales
                            if (isReportView && standardTerminal) {
                                standardTerminal.innerHTML = reportBuffer;
                            }

                            // Re-ejecutar scripts inyectados en standardTerminal para que los filtros funcionen de inmediato
                            if (standardTerminal) {
                                const scripts = standardTerminal.querySelectorAll("script");
                                scripts.forEach(oldScript => {
                                    const newScript = document.createElement("script");
                                    Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                                    newScript.textContent = oldScript.textContent;
                                    document.body.appendChild(newScript);
                                    oldScript.remove();
                                });
                            }

                            // Si terminó el stream y era scraper sin haber mostrado el modal aún
                            if (isScraperMode && !summaryFound) {
                                if (circleBar) {
                                    circleBar.classList.remove("spinning");
                                    circleBar.style.stroke = "url(#loaderGradientSuccess)";
                                    setCircleProgress(100);
                                }
                                if (circlePulseIcon) circlePulseIcon.style.display = "none";
                                if (circleSuccessIcon) circleSuccessIcon.style.display = "block";
                                if (liveSubtitle) liveSubtitle.textContent = "¡Extracción completada!";
                                openSummaryModal({
                                    category: activeCategory,
                                    total_urls: metricUrlsTotal || metricUrlsCurrent || 1,
                                    detected: metricDetectedTotal,
                                    updated: metricUpdatedTotal,
                                    duration: 0
                                });
                            }

                            if (isProcoreMode && !summaryFound) {
                                const procoreBar = document.getElementById("procore-circle-bar");
                                const procoreBadgeText = document.getElementById("badge-procore-text");
                                const procoreTermDot = document.getElementById("procore-term-dot");
                                const procoreIndicatorDot = document.getElementById("procore-indicator-dot");
                                if (procoreBadgeText) procoreBadgeText.textContent = "Finalizado";
                                if (procoreTermDot) procoreTermDot.style.animation = "none";
                                if (procoreIndicatorDot) procoreIndicatorDot.style.animation = "none";
                                if (procoreBar) procoreBar.classList.remove("spinning");
                            }
                            return;
                        }

                        const chunk = decoder.decode(value, { stream: true });

                        if (isReportView) {
                            // Acumular el buffer del reporte en memoria para renderizado atómico completo
                            reportBuffer += chunk;
                            read();
                            return;
                        }

                        if (!isScraperMode && !isProcoreMode) {
                            // Modo estándar (logs / texto simple): inyectar directamente en terminal-output
                            if (standardTerminal) {
                                standardTerminal.insertAdjacentHTML("beforeend", chunk);
                                window.scrollTo(0, document.body.scrollHeight);
                            }
                            read();
                            return;
                        }

                        if (isProcoreMode) {
                            // Modo Procore: Procesar línea a línea
                            lineBuffer += chunk;
                            const lines = lineBuffer.split("\n");
                            lineBuffer = lines.pop();

                            for (let rawLine of lines) {
                                const line = rawLine.trim();
                                if (!line) continue;

                                if (line.indexOf("[PROCORE_EVENT:INIT]") !== -1) {
                                    setProcoreStep(1, 10, "Iniciando entorno de automatización...", "Configurando navegador y credenciales");
                                    appendTerminalLine(">>> Iniciando navegador y entorno de automatización...", "term-url");
                                    continue;
                                }

                                if (line.indexOf("[PROCORE_EVENT:LOGIN_START]") !== -1) {
                                    setProcoreStep(1, 25, "Autenticando en Procore...", "Enviando correo y contraseña en portal oficial");
                                    appendTerminalLine("  -> Conectando a login.procore.com e ingresando credenciales...", "term-warning");
                                    continue;
                                }

                                if (line.indexOf("[PROCORE_EVENT:LOGIN_VERIFY]") !== -1) {
                                    setProcoreStep(1, 40, "Verificando inicio de sesión...", "Esperando redirección del panel corporativo");
                                    appendTerminalLine("  -> Verificando autenticación y tokens de sesión...", "term-warning");
                                    continue;
                                }

                                if (line.indexOf("[PROCORE_EVENT:LOGIN_SUCCESS]") !== -1) {
                                    setProcoreStep(2, 55, "¡Autenticación exitosa!", "Accediendo a Cost Catalog...");
                                    appendTerminalLine("  [✓] Autenticación confirmada en Procore.", "term-success");
                                    continue;
                                }

                                if (line.indexOf("[PROCORE_EVENT:NAV_CATALOG]") !== -1) {
                                    setProcoreStep(2, 65, "Ingresando a Cost Catalog...", "Navegando a la herramienta de catálogo de costos");
                                    appendTerminalLine(">>> Accediendo a herramienta Cost Catalog...", "term-url");
                                    continue;
                                }

                                if (line.indexOf("[PROCORE_EVENT:IMPORT_MENU]") !== -1) {
                                    setProcoreStep(3, 75, "Abriendo menú de importación...", "Localizando asistente 'Import Catalog Items'");
                                    appendTerminalLine("  -> Abriendo menú de acciones y asistente de importación...", "term-warning");
                                    continue;
                                }

                                if (line.indexOf("[PROCORE_EVENT:UPLOADING]") !== -1) {
                                    const fname = line.replace("[PROCORE_EVENT:UPLOADING]", "").trim();
                                    setProcoreStep(4, 85, "Cargando archivo Excel...", fname);
                                    appendTerminalLine("  -> Subiendo archivo: " + fname, "term-warning");
                                    continue;
                                }

                                if (line.indexOf("[PROCORE_EVENT:ATTACHING]") !== -1) {
                                    setProcoreStep(4, 92, "Adjuntando y validando en Procore...", "Procore está leyendo las filas y actualizando precios...");
                                    appendTerminalLine("  -> Adjuntando archivo en Procore y esperando validación...", "term-warning");
                                    continue;
                                }

                                if (line.indexOf("[PROCORE_EVENT:SUCCESS]") !== -1) {
                                    summaryFound = true;
                                    setProcoreStep(4, 100, "¡Sincronización completada con éxito!", "La plantilla fue importada correctamente en Procore.");

                                    const procoreBar = document.getElementById("procore-circle-bar");
                                    const procorePulse = document.getElementById("procore-circle-pulse");
                                    const procoreCheck = document.getElementById("procore-circle-check");
                                    const procoreBadgeText = document.getElementById("badge-procore-text");
                                    const procoreBadgeStatus = document.getElementById("badge-procore-status");
                                    const procoreTermDot = document.getElementById("procore-term-dot");
                                    const procoreIndicatorDot = document.getElementById("procore-indicator-dot");

                                    if (procoreBar) {
                                        procoreBar.classList.remove("spinning");
                                        procoreBar.style.stroke = "url(#loaderGradientSuccess)";
                                    }
                                    if (procorePulse) procorePulse.style.display = "none";
                                    if (procoreCheck) procoreCheck.style.display = "block";
                                    if (procoreBadgeText) procoreBadgeText.textContent = "Completado";
                                    if (procoreBadgeStatus) procoreBadgeStatus.className = "badge-updated badge-found";
                                    if (procoreTermDot) procoreTermDot.style.animation = "none";
                                    if (procoreIndicatorDot) procoreIndicatorDot.style.animation = "none";

                                    appendTerminalLine("========================================", "term-header");
                                    appendTerminalLine("🎉 ¡ÉXITO! PLANTILLA IMPORTADA EN PROCORE", "term-success");
                                    appendTerminalLine("========================================", "term-header");

                                    setTimeout(() => {
                                        openProcoreModal();
                                    }, 500);
                                    continue;
                                }

                                if (line.indexOf("[PROCORE_EVENT:ERROR]") !== -1) {
                                    const errMsg = line.replace("[PROCORE_EVENT:ERROR]", "").trim();
                                    const procoreTitle = document.getElementById("procore-status-title");
                                    const procoreDetail = document.getElementById("procore-status-detail");
                                    const procoreBar = document.getElementById("procore-circle-bar");
                                    const procoreBadgeText = document.getElementById("badge-procore-text");

                                    if (procoreTitle) procoreTitle.textContent = "Error en la automatización";
                                    if (procoreDetail) procoreDetail.textContent = errMsg;
                                    if (procoreBar) {
                                        procoreBar.classList.remove("spinning");
                                        procoreBar.style.stroke = "#ef4444";
                                    }
                                    if (procoreBadgeText) procoreBadgeText.textContent = "Detenido";

                                    appendTerminalLine("  [X] Error: " + errMsg, "term-error");
                                    continue;
                                }

                                // Detección de captura de pantalla para diagnóstico
                                if (line.indexOf("procore_error_screenshot.png") !== -1) {
                                    const scBox = document.getElementById("procore-screenshot-box");
                                    const scImg = document.getElementById("procore-screenshot-img");
                                    const scLink = document.getElementById("procore-screenshot-link");
                                    if (scBox && scImg) {
                                        const newSrc = "../data/excel_output/procore_error_screenshot.png?t=" + new Date().getTime();
                                        scImg.src = newSrc;
                                        if (scLink) scLink.href = newSrc;
                                        scBox.style.display = "block";
                                    }
                                }

                                // Formateo visual de líneas regulares de consola
                                let styleClass = "";
                                if (line.indexOf("[✓]") !== -1 || line.indexOf("exitosamente") !== -1 || line.indexOf("ÉXITO") !== -1 || line.indexOf("🎉") !== -1) {
                                    styleClass = "term-success";
                                } else if (line.indexOf("[ERROR") !== -1 || line.indexOf("Falló") !== -1 || line.indexOf("Exception") !== -1) {
                                    styleClass = "term-error";
                                } else if (line.indexOf("http") !== -1 || line.indexOf("URL") !== -1) {
                                    styleClass = "term-url";
                                } else if (line.indexOf("INICIANDO") !== -1 || line.indexOf("===") !== -1) {
                                    styleClass = "term-header";
                                } else if (line.indexOf("[INFO]") !== -1 || line.indexOf("Aviso") !== -1 || line.indexOf("AVISO") !== -1 || line.indexOf("pausada") !== -1 || line.indexOf("⏸️") !== -1) {
                                    styleClass = "term-warning";
                                }

                                appendTerminalLine(line, styleClass);
                            }
                            read();
                            return;
                        }

                        // Modo Scraper: Procesar línea a línea y actualizar indicadores
                        lineBuffer += chunk;
                        const lines = lineBuffer.split("\n");
                        lineBuffer = lines.pop(); // Mantener fragmento incompleto

                        for (let rawLine of lines) {
                            const line = rawLine.trim();
                            if (!line) continue;

                            // 0. EVENTO: Estado preliminar [EVENT:STAGE] <mensaje>
                            if (line.indexOf("[EVENT:STAGE]") !== -1) {
                                const stageMsg = line.replace("[EVENT:STAGE]", "").trim();
                                const isHeadlessMode = !(postData.headless === '0' || postData.headless === 'false' || postData.headless === false);
                                if (liveDetail) liveDetail.textContent = isHeadlessMode ? "Rexel USA (Silencioso)" : "Rexel USA (Ventana Visible)";
                                appendTerminalLine(">>> " + stageMsg, "term-url");
                                continue;
                            }

                            // 1. EVENTO: Inicialización [EVENT:INIT] <cat>|<total>
                            if (line.indexOf("[EVENT:INIT]") !== -1) {
                                const parts = line.replace("[EVENT:INIT]", "").trim().split("|");
                                activeCategory = parts[0] || activeCategory;
                                metricUrlsTotal = parseInt(parts[1]) || 1;
                                if (counterUrls) counterUrls.textContent = "0 / " + metricUrlsTotal;
                                if (liveSubtitle) liveSubtitle.textContent = "Iniciando extracción para " + activeCategory + "...";
                                continue;
                            }

                            // 2. EVENTO: Progreso de URL [EVENT:URL_PROGRESS] <current>|<total>|<url>
                            if (line.indexOf("[EVENT:URL_PROGRESS]") !== -1) {
                                const parts = line.replace("[EVENT:URL_PROGRESS]", "").trim().split("|");
                                metricUrlsCurrent = parseInt(parts[0]) || 1;
                                metricUrlsTotal = parseInt(parts[1]) || metricUrlsTotal;
                                const currentUrl = parts[2] || "";

                                if (counterUrls) counterUrls.textContent = metricUrlsCurrent + " / " + metricUrlsTotal;
                                if (liveSubtitle) liveSubtitle.textContent = "Ingresando al enlace #" + metricUrlsCurrent + " de " + metricUrlsTotal + "...";
                                if (liveDetail) liveDetail.textContent = currentUrl;

                                const pct = Math.round(((metricUrlsCurrent - 0.5) / metricUrlsTotal) * 90);
                                setCircleProgress(pct);

                                appendTerminalLine(">>> Enlace #" + metricUrlsCurrent + " / " + metricUrlsTotal + ": " + currentUrl, "term-url");
                                continue;
                            }

                            // 3. EVENTO: Productos detectados [EVENT:ITEMS_DETECTED] <count>
                            if (line.indexOf("[EVENT:ITEMS_DETECTED]") !== -1) {
                                const count = parseInt(line.replace("[EVENT:ITEMS_DETECTED]", "").trim()) || 0;
                                metricDetectedTotal += count;
                                if (counterDetected) counterDetected.textContent = metricDetectedTotal;
                                if (liveSubtitle) liveSubtitle.textContent = "Extrayendo elementos... (" + count + " detectados en este enlace)";
                                appendTerminalLine("  -> " + count + " productos detectados en la página.", "term-warning");
                                continue;
                            }

                            // 4. EVENTO: Producto actualizado [EVENT:ITEM_UPDATED] <name>|<price>
                            if (line.indexOf("[EVENT:ITEM_UPDATED]") !== -1) {
                                const itemData = line.replace("[EVENT:ITEM_UPDATED]", "").trim().split("|");
                                metricUpdatedTotal++;
                                if (counterUpdated) counterUpdated.textContent = metricUpdatedTotal;
                                if (liveSubtitle) liveSubtitle.textContent = "Actualizando precios: " + (itemData[0] || "");
                                appendTerminalLine("  [✓] Actualizado: " + itemData[0] + " -> $" + (itemData[1] || ""), "term-success");
                                continue;
                            }

                            // 5. EVENTO: Producto no mapeado [EVENT:ITEM_UNMAPPED] <name>|<price>
                            if (line.indexOf("[EVENT:ITEM_UNMAPPED]") !== -1) {
                                const itemData = line.replace("[EVENT:ITEM_UNMAPPED]", "").trim().split("|");
                                metricUnmappedTotal++;
                                if (counterUnmapped) counterUnmapped.textContent = metricUnmappedTotal;
                                appendTerminalLine("  [!] No mapeado: " + itemData[0] + " ($" + (itemData[1] || "") + ")", "term-warning");
                                continue;
                            }

                            // 6. EVENTO: Sincronización de estadísticas [EVENT:STATS] <upd>|<unmap>|<det>
                            if (line.indexOf("[EVENT:STATS]") !== -1) {
                                const s = line.replace("[EVENT:STATS]", "").trim().split("|");
                                if (s[0]) metricUpdatedTotal = parseInt(s[0]);
                                if (s[1]) metricUnmappedTotal = parseInt(s[1]);
                                if (counterUpdated) counterUpdated.textContent = metricUpdatedTotal;
                                if (counterUnmapped) counterUnmapped.textContent = metricUnmappedTotal;
                                continue;
                            }

                            // 7. EVENTO FINAL: Resumen en JSON [SUMMARY_DATA] {...}
                            if (line.indexOf("[SUMMARY_DATA]") !== -1) {
                                try {
                                    summaryFound = true;
                                    const jsonStr = line.substring(line.indexOf("[SUMMARY_DATA]") + 14).trim();
                                    const summaryData = JSON.parse(jsonStr);

                                    // Cambiar visualización del círculo a estado completado (verde)
                                    if (circleBar) {
                                        circleBar.classList.remove("spinning");
                                        circleBar.style.stroke = "url(#loaderGradientSuccess)";
                                        setCircleProgress(100);
                                    }
                                    if (circlePulseIcon) circlePulseIcon.style.display = "none";
                                    if (circleSuccessIcon) circleSuccessIcon.style.display = "block";
                                    if (liveSubtitle) liveSubtitle.textContent = "¡Extracción finalizada con éxito!";
                                    if (badgeLiveText) badgeLiveText.textContent = "Completado";
                                    if (badgeLiveStatus) {
                                        badgeLiveStatus.className = "badge-found";
                                    }

                                    appendTerminalLine("========================================", "term-header");
                                    appendTerminalLine("🎉 EXTRACCIÓN COMPLETADA EXITOSAMENTE", "term-success");
                                    appendTerminalLine("========================================", "term-header");

                                    // Abrir automáticamente el modal con los resultados
                                    setTimeout(() => {
                                        openSummaryModal(summaryData);
                                    }, 500);

                                    /* [DESHABILITADO: Resumen de extracción stream-fallback-container]
                                    if (fallbackWrapper && fallbackBody && fallbackBody.innerHTML.trim() !== "") {
                                        fallbackWrapper.style.display = "block";
                                    }
                                    */

                                } catch (e) {
                                    console.error("Error parseando SUMMARY_DATA:", e);
                                }
                                continue;
                            }

                            const trimmedLine = line.trim();

                            // 1. BLINDAJE DE BLOQUES SCRIPT Y STYLE
                            if (trimmedLine.indexOf("<script") !== -1) {
                                streamInScript = true;
                            }
                            if (streamInScript) {
                                if (trimmedLine.indexOf("<\x2fscript>") !== -1 || trimmedLine.indexOf("<\x2fscript") !== -1) {
                                    streamInScript = false;
                                }
                                continue;
                            }
                            if (trimmedLine.indexOf("<style") !== -1) {
                                streamInStyle = true;
                            }
                            if (streamInStyle) {
                                if (trimmedLine.indexOf("<\x2fstyle>") !== -1 || trimmedLine.indexOf("<\x2fstyle") !== -1) {
                                    streamInStyle = false;
                                }
                                continue;
                            }

                            // 2. BLINDAJE DE SINTAXIS JAVASCRIPT Y CÓDIGO LEAK
                            const isJsOrCodeLeak =
                                trimmedLine.startsWith("window.") ||
                                trimmedLine.startsWith("const ") ||
                                trimmedLine.startsWith("let ") ||
                                trimmedLine.startsWith("var ") ||
                                trimmedLine.startsWith("function") ||
                                trimmedLine.startsWith("document.") ||
                                trimmedLine.startsWith("console.") ||
                                trimmedLine.startsWith("return ") ||
                                trimmedLine.startsWith("if (") ||
                                trimmedLine.startsWith("else ") ||
                                trimmedLine.startsWith("for (") ||
                                trimmedLine.startsWith("while (") ||
                                trimmedLine === "};" ||
                                trimmedLine === "});" ||
                                trimmedLine === "}" ||
                                trimmedLine === "{" ||
                                trimmedLine.indexOf("previewBehavior") !== -1 ||
                                trimmedLine.indexOf("filterPreview") !== -1 ||
                                trimmedLine.indexOf("applyPreviewFilters") !== -1 ||
                                trimmedLine.indexOf("previewCategory") !== -1 ||
                                trimmedLine.indexOf("visibleCount") !== -1 ||
                                trimmedLine.indexOf("table-search") !== -1 ||
                                trimmedLine.indexOf("preview-row") !== -1;

                            if (isJsOrCodeLeak) {
                                continue;
                            }

                            // 3. BLINDAJE DE FRAGMENTOS TEXTUALES DE REPORTES O KPIS LEAK
                            const isReportTextLeak =
                                /^Tax:\s*\d+(\.\d+)?%$/i.test(trimmedLine) ||
                                /^[+-]?\d+(\.\d+)?%$/.test(trimmedLine) ||
                                trimmedLine.indexOf("Mostrando todos los registros") !== -1 ||
                                (trimmedLine.indexOf("Mostrando ") === 0 && trimmedLine.indexOf("ítems") !== -1) ||
                                trimmedLine.indexOf("Tendencia al alza") !== -1 ||
                                trimmedLine.indexOf("Tendencia a la baja") !== -1 ||
                                trimmedLine.indexOf("Subieron / Bajaron / Estables") !== -1 ||
                                trimmedLine.indexOf("Precios listos para generación") !== -1 ||
                                trimmedLine.indexOf("Total registrados en catálogo") !== -1;

                            if (isReportTextLeak) {
                                continue;
                            }

                            // 4. BLINDAJE Y DETECCIÓN DE SALIDA HTML DIRECTA
                            const isHtmlLine = trimmedLine.charAt(0) === "<" ||
                                trimmedLine.indexOf("<div") !== -1 ||
                                trimmedLine.indexOf("<span") !== -1 ||
                                trimmedLine.indexOf("<table") !== -1 ||
                                trimmedLine.indexOf("<thead") !== -1 ||
                                trimmedLine.indexOf("<tbody") !== -1 ||
                                trimmedLine.indexOf("<tr") !== -1 ||
                                trimmedLine.indexOf("<th") !== -1 ||
                                trimmedLine.indexOf("<td") !== -1 ||
                                trimmedLine.indexOf("<h") !== -1 ||
                                trimmedLine.indexOf("<form") !== -1 ||
                                trimmedLine.indexOf("<p") !== -1 ||
                                trimmedLine.indexOf("<input") !== -1 ||
                                trimmedLine.indexOf("<button") !== -1 ||
                                trimmedLine.indexOf("<svg") !== -1 ||
                                trimmedLine.indexOf("<path") !== -1 ||
                                trimmedLine.indexOf("<circle") !== -1 ||
                                trimmedLine.indexOf("<select") !== -1 ||
                                trimmedLine.indexOf("<option") !== -1 ||
                                trimmedLine.indexOf("</") !== -1 ||
                                trimmedLine.indexOf("/>") !== -1;

                            if (isHtmlLine) {
                                // Si contiene alerta crítica de sesión de Rexel expirada o requerida, mostrar de inmediato
                                if (line.indexOf("Sesión de Rexel Requerida") !== -1 ||
                                    line.indexOf("SESSION_EXPIRED") !== -1 ||
                                    line.indexOf("SESSION_MISSING") !== -1) {
                                    if (circleBar) {
                                        circleBar.classList.remove("spinning");
                                        circleBar.style.stroke = "#ef4444";
                                    }
                                    if (liveSubtitle) liveSubtitle.textContent = "Sesión de Rexel expirada o requerida";
                                }

                                // Extraer el texto limpio de los divs de log de Python para que SIEMPRE aparezcan en la consola
                                if (line.indexOf("log-") !== -1) {
                                    const tempDiv = document.createElement("div");
                                    tempDiv.innerHTML = line;
                                    const text = tempDiv.textContent.trim();
                                    if (text) {
                                        let styleClass = "";
                                        if (line.indexOf("log-error") !== -1 || line.indexOf("SESSION_EXPIRED") !== -1) {
                                            styleClass = "term-error";
                                        } else if (line.indexOf("log-warning") !== -1) {
                                            styleClass = "term-warning";
                                        } else if (line.indexOf("log-success") !== -1) {
                                            styleClass = "term-success";
                                        } else if (line.indexOf("log-header") !== -1) {
                                            styleClass = "term-header";
                                        } else if (line.indexOf("log-info") !== -1) {
                                            styleClass = "term-url";
                                        }
                                        appendTerminalLine(text, styleClass);
                                    }
                                }
                                continue;
                            }

                            // Línea regular de terminal con formato según contenido
                            let styleClass = "";
                            if (line.indexOf("[✓]") !== -1 || line.indexOf("correctamente") !== -1 || line.indexOf("EXITO") !== -1) {
                                styleClass = "term-success";
                            } else if (line.indexOf("[!]") !== -1 || line.indexOf("ADVERTENCIA") !== -1) {
                                styleClass = "term-warning";
                            } else if (line.indexOf("[ERROR") !== -1 || line.indexOf("Falló") !== -1 || line.indexOf("Exception") !== -1 || line.indexOf("SESSION_EXPIRED") !== -1 || line.indexOf("SESSION_MISSING") !== -1) {
                                styleClass = "term-error";
                                if (line.indexOf("SESSION_EXPIRED") !== -1 || line.indexOf("SESSION_MISSING") !== -1) {
                                    if (circleBar) {
                                        circleBar.classList.remove("spinning");
                                        circleBar.style.stroke = "#ef4444";
                                    }
                                    if (liveSubtitle) liveSubtitle.textContent = "Sesión de Rexel requerida o expirada";
                                    if (badgeLiveText) badgeLiveText.textContent = "Sesión Requerida";
                                    if (badgeLiveStatus) badgeLiveStatus.className = "badge-pending";
                                }
                            } else if (line.indexOf("http") !== -1 || line.indexOf("Enlace:") !== -1) {
                                styleClass = "term-url";
                            } else if (line.indexOf("INICIANDO") !== -1 || line.indexOf("FIN EXTRACCIÓN") !== -1 || line.indexOf("===") !== -1) {
                                styleClass = "term-header";
                            }

                            appendTerminalLine(line, styleClass);
                        }

                        read();
                    });
                }
                read();
            }).catch(err => {
                if (isScraperMode) {
                    if (liveSubtitle) liveSubtitle.textContent = "Error de conexión con el servidor";
                    appendTerminalLine("Error de stream: " + err, "term-error");
                } else if (standardTerminal) {
                    standardTerminal.innerHTML += "<p style='color:red'>Error de conexión con el stream: " + err + "</p>";
                }
                if (standardIndicator) standardIndicator.style.display = "none";
            });
        });

        // Función Global de Filtrado para tablas
        window.filterTable = function () {
            var input = document.getElementById("table-search");
            if (!input) return;

            var filter = input.value.toUpperCase();
            var table = document.querySelector(".styled-table");
            if (!table) return;

            var tr = table.getElementsByTagName("tr");
            for (var i = 0; i < tr.length; i++) {
                if (tr[i].parentNode.tagName === "THEAD" || tr[i].getElementsByTagName("th").length > 0) continue;
                var txtValue = tr[i].textContent || tr[i].innerText;
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                }
            }
        };
    </script>

</body>

</html>