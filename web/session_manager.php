<?php
/**
 * session_manager.php
 * --------------------
 * Endpoint AJAX para gestionar la sesion de Rexel.
 * Permite al frontend verificar el estado, iniciar login manual y limpiar la sesion.
 *
 * Acciones disponibles (GET/POST):
 *   GET  ?action=status          -> Verifica si la sesion es valida
 *   POST action=start_login      -> Lanza session_manager.py en background
 *   GET  ?action=check_login&job=<pid> -> Verifica si el proceso de login termino
 *   POST action=clear_session    -> Elimina storage_state.json
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- SEGURIDAD: Solo AJAX con CSRF ---
header('Content-Type: application/json; charset=utf-8');

// Rutas clave
$script_dir = realpath(__DIR__ . '/../scripts');
$storage_state = $script_dir . DIRECTORY_SEPARATOR . 'storage_state.json';
$session_mgr_py = $script_dir . DIRECTORY_SEPARATOR . 'session_manager.py';
$refresh_py = $script_dir . DIRECTORY_SEPARATOR . 'refresh_rexel_session.py';
$pid_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rexel_login_pid.txt';
$log_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rexel_login_log.txt';

// Detectar Python del venv
$venv_python_win = realpath(__DIR__ . '/../.venv/Scripts/python.exe');
$venv_python_nix = realpath(__DIR__ . '/../.venv/bin/python');
if ($venv_python_win && file_exists($venv_python_win)) {
    $python_exe = $venv_python_win;
} elseif ($venv_python_nix && file_exists($venv_python_nix)) {
    $python_exe = $venv_python_nix;
} else {
    $python_exe = 'python';
}

// Timeout del login manual (segundos que el usuario tiene para hacer login)
$login_timeout = 300;

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // ======================================================
    // STATUS: Verifica si el storage_state.json es valido
    // ======================================================
    case 'status':
        if (!file_exists($storage_state)) {
            echo json_encode([
                'valid' => false,
                'status' => 'missing',
                'message' => 'No existe sesion guardada.',
            ]);
            exit;
        }

        // Leer el JSON directamente en PHP para evitar lanzar Python solo para verificar
        $state_raw = file_get_contents($storage_state);
        $state = json_decode($state_raw, true);

        if (!$state) {
            echo json_encode([
                'valid' => false,
                'status' => 'corrupt',
                'message' => 'El archivo de sesion esta corrupto.',
            ]);
            exit;
        }

        $token_expiry = null;

        // Buscar en cookies
        foreach (($state['cookies'] ?? []) as $cookie) {
            if ($cookie['name'] === 'auth._refresh_token_expiration.oauth' && ($cookie['expires'] ?? -1) > 0) {
                $refresh_expiry = $cookie['expires'];
            }
            if ($cookie['name'] === 'auth._token_expiration.oauth' && ($cookie['expires'] ?? -1) > 0) {
                $token_expiry = $cookie['expires'];
            }
        }

        // Buscar en localStorage (valor en milisegundos)
        foreach (($state['origins'] ?? []) as $origin) {
            foreach (($origin['localStorage'] ?? []) as $item) {
                if ($item['name'] === 'auth._refresh_token_expiration.oauth') {
                    $refresh_expiry = (float) $item['value'] / 1000;
                }
                if ($item['name'] === 'auth._token_expiration.oauth') {
                    $token_expiry = (float) $item['value'] / 1000;
                }
            }
        }

        if ($refresh_expiry === null) {
            echo json_encode([
                'valid' => false,
                'status' => 'no_expiry',
                'message' => 'No se encontro informacion de expiracion en la sesion.',
            ]);
            exit;
        }

        $now = time();
        $days_left = floor(($refresh_expiry - $now) / 86400);
        $hours_left = floor((($refresh_expiry - $now) % 86400) / 3600);

        if ($now > $refresh_expiry) {
            $expired_ago = floor(($now - $refresh_expiry) / 3600);
            echo json_encode([
                'valid' => false,
                'status' => 'expired',
                'expires_at' => $refresh_expiry,
                'message' => "La sesion expiro hace {$expired_ago} horas.",
            ]);
            exit;
        }

        $warning = ($days_left < 3);
        echo json_encode([
            'valid' => true,
            'status' => $warning ? 'expiring_soon' : 'ok',
            'expires_at' => $refresh_expiry,
            'days_left' => $days_left,
            'hours_left' => $hours_left,
            'auto_refresh_supported' => true,
            'message' => "Sesion activa. Expira en {$days_left} dias y {$hours_left} horas.",
        ]);
        exit;


    // ======================================================
    // REFRESH_TOKEN: Renueva la sesion en background (Headless)
    // ======================================================
    case 'refresh_token':
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['error' => 'Token CSRF invalido.']);
            exit;
        }

        if (!file_exists($storage_state)) {
            echo json_encode([
                'success' => false,
                'error' => 'no_session',
                'message' => 'No hay una sesion previa para renovar. Por favor inicia sesion primero.',
            ]);
            exit;
        }

        // Ejecutar worker ligero de Playwright headless
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'cmd /C ""' . $python_exe . '" -u "' . $refresh_py . '" --force"';
        } else {
            $cmd = escapeshellarg($python_exe) . ' -u ' . escapeshellarg($refresh_py) . ' --force';
        }
        $output_lines = [];
        $return_code = 0;
        exec($cmd, $output_lines, $return_code);

        $output_str = implode("\n", $output_lines);
        $result = json_decode($output_str, true);

        if ($return_code === 0 && is_array($result) && ($result['success'] ?? false)) {
            $_SESSION['rexel_email'] = 'rexel_authenticated';
            echo json_encode([
                'success' => true,
                'message' => $result['message'] ?? 'Token renovado exitosamente.',
                'days_left' => $result['days_left'] ?? 30,
                'hours_left' => $result['hours_left'] ?? 0,
                'expires_at' => $result['expires_at'] ?? null,
                'elapsed_seconds' => $result['elapsed_seconds'] ?? null,
            ]);
        } else {
            $err_msg = $result['message'] ?? (empty($output_str) ? 'Fallo al ejecutar el worker de renovacion.' : $output_str);
            echo json_encode([
                'success' => false,
                'error' => 'refresh_failed',
                'message' => $err_msg,
            ]);
        }
        exit;


    // ======================================================
    // IMPORT_SESSION: Importa sesion transferida desde navegador
    // ======================================================
    case 'import_session':
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['error' => 'Token CSRF invalido.']);
            exit;
        }

        $raw_json = $_POST['session_json'] ?? '';
        if (empty($raw_json)) {
            echo json_encode(['success' => false, 'message' => 'No se recibieron datos de sesion.']);
            exit;
        }

        $decoded = json_decode($raw_json, true);
        if (!$decoded || !is_array($decoded)) {
            echo json_encode(['success' => false, 'message' => 'El formato debe ser un JSON valido (Playwright storage_state).']);
            exit;
        }

        // Guardar en storage_state.json
        if (file_put_contents($storage_state, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            echo json_encode(['success' => false, 'message' => 'Error al escribir el archivo storage_state.json.']);
            exit;
        }

        // Verificar validez
        if (PHP_OS_FAMILY === 'Windows') {
            $check_cmd = 'cmd /C ""' . $python_exe . '" -u "' . $session_mgr_py . '" --check"';
        } else {
            $check_cmd = escapeshellarg($python_exe) . ' -u ' . escapeshellarg($session_mgr_py) . ' --check';
        }
        $check_out = [];
        exec($check_cmd, $check_out, $check_code);
        $check_res = json_decode(implode("\n", $check_out), true);

        if ($check_code === 0 && ($check_res['valid'] ?? false)) {
            $_SESSION['rexel_email'] = 'rexel_authenticated';
            echo json_encode([
                'success' => true,
                'message' => 'Sesion importada y verificada exitosamente.',
                'days_left' => $check_res['days_left'] ?? 30,
                'hours_left' => $check_res['hours_left'] ?? 0,
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Se guardo la sesion pero no parece ser valida: ' . ($check_res['message'] ?? 'Datos incompletos.'),
            ]);
        }
        exit;


    // ======================================================
    // START_LOGIN: Lanza el browser visible o headless en background
    // ======================================================
    case 'start_login':
        // Verificar CSRF
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['error' => 'Token CSRF invalido.']);
            exit;
        }

        $is_headless = (isset($_POST['headless']) && $_POST['headless'] === '1');

        // Si ya hay un proceso corriendo, verificar si sigue vivo
        if (file_exists($pid_file)) {
            $existing_pid = trim(file_get_contents($pid_file));
            if ($existing_pid && is_numeric($existing_pid)) {
                if (PHP_OS_FAMILY === 'Windows') {
                    exec("tasklist /FI \"PID eq {$existing_pid}\" 2>NUL", $output);
                    $still_running = count($output) > 1;
                } else {
                    $still_running = posix_kill((int) $existing_pid, 0);
                }
                if ($still_running) {
                    echo json_encode(['success' => true, 'pid' => $existing_pid, 'already_running' => true]);
                    exit;
                }
            }
            @unlink($pid_file);
        }

        // Inicializar log limpio con encabezado
        file_put_contents($log_file, "[SESSION_MANAGER] Iniciando proceso de login...\n");
        $_SESSION['rexel_login_started_at'] = time();

        $headless_flag = $is_headless ? ' --headless' : '';

        // Construir y lanzar el comando en background
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'cmd /C start "" /B '
                . '"' . $python_exe . '" -u '
                . '"' . $session_mgr_py . '" --timeout ' . $login_timeout . $headless_flag
                . ' > "' . $log_file . '" 2>&1';

            pclose(popen($cmd, 'r'));
        } else {
            $py_escaped     = escapeshellarg($python_exe);
            $script_escaped = escapeshellarg($session_mgr_py);
            $log_escaped    = escapeshellarg($log_file);
            $cmd = "{$py_escaped} -u {$script_escaped} --timeout {$login_timeout}{$headless_flag} > {$log_escaped} 2>&1 & echo $!";
            $pid = trim(shell_exec($cmd));
            if ($pid && is_numeric($pid)) {
                file_put_contents($pid_file, $pid);
            }
        }

        echo json_encode(['success' => true]);
        exit;


    // ======================================================
    // CHECK_LOGIN: Verifica el resultado del proceso
    // ======================================================
    case 'check_login':
        $pid = file_exists($pid_file) ? trim(file_get_contents($pid_file)) : null;

        // Leer log acumulado
        $log_content = file_exists($log_file) ? file_get_contents($log_file) : '';

        // Comprobar si termino con exito
        $success = strpos($log_content, '[EXITO]') !== false;
        $error   = strpos($log_content, '[ERROR]') !== false;

        if ($success) {
            @unlink($pid_file);
            $_SESSION['rexel_email'] = 'rexel_authenticated';
            echo json_encode(['done' => true, 'success' => true, 'log' => $log_content]);
            exit;
        }

        if ($error) {
            @unlink($pid_file);
            echo json_encode(['done' => true, 'success' => false, 'log' => $log_content]);
            exit;
        }

        // Determinar si sigue en ejecucion
        $started_at = $_SESSION['rexel_login_started_at'] ?? time();
        $elapsed    = time() - $started_at;
        $still_running = true;

        if ($pid && is_numeric($pid)) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
                // Si tasklist devuelve solo encabezado o "INFO: No tasks...", el proceso cerro
                $found = false;
                foreach ($output as $line) {
                    if (strpos($line, (string)$pid) !== false) {
                        $found = true;
                        break;
                    }
                }
                $still_running = $found;
            } else {
                $still_running = @posix_kill((int) $pid, 0);
            }
        } elseif ($elapsed > 25 && (empty($log_content) || strpos($log_content, 'Iniciando') !== false)) {
            // Pasaron mas de 25 segundos y el proceso nunca escribio su PID ni avanzo
            $still_running = false;
        }

        // Solo reportar done=true si el proceso efectivamente finalizo
        $done = !$still_running;

        echo json_encode([
            'done'    => $done,
            'success' => false,
            'running' => $still_running,
            'log'     => $log_content,
        ]);
        exit;


    // ======================================================
    // CLEAR_SESSION: Elimina el storage_state.json
    // ======================================================
    case 'clear_session':
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['error' => 'Token CSRF invalido.']);
            exit;
        }

        if (file_exists($storage_state)) {
            unlink($storage_state);
            echo json_encode(['success' => true, 'message' => 'Sesion eliminada correctamente.']);
        } else {
            echo json_encode(['success' => true, 'message' => 'No habia sesion que eliminar.']);
        }
        exit;


    default:
        http_response_code(400);
        echo json_encode(['error' => "Accion desconocida: {$action}"]);
        exit;
}
