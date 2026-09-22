<?php

require_once __DIR__ . '/rexel_extension_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && preg_match('#^(chrome-extension|moz-extension)://[a-z0-9-]+$#i', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Max-Age: 600');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (!REXEL_EXTENSION_EXPERIMENT_ENABLED) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'El prototipo de extension esta desactivado.']);
    exit;
}

require_once __DIR__ . '/rexel_extension_lib.php';

function rexel_extension_json_input(): array
{
    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('El cuerpo JSON no es valido.');
        }
        return $data;
    }
    return $_POST;
}

function rexel_extension_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $match)) {
        return trim($match[1]);
    }
    return '';
}

function rexel_extension_require_csrf(array $input): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $expected = $_SESSION['csrf_token'] ?? '';
    $received = (string)($input['csrf_token'] ?? '');
    if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
        throw new RuntimeException('Token CSRF invalido o expirado.');
    }
}

function rexel_extension_reload_job(PDO $pdo, string $jobId): array
{
    $stmt = $pdo->prepare('SELECT * FROM rexel_extension_jobs WHERE id = ?');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        throw new RuntimeException('Trabajo no encontrado.');
    }
    return $job;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new InvalidArgumentException('Solo se acepta POST.');
    }
    $input = rexel_extension_json_input();
    $action = rexel_extension_clean_text($input['action'] ?? '', 40);
    $pdo = get_db_connection();
    rexel_extension_create_schema($pdo);

    if ($action === 'create_batch') {
        rexel_extension_require_csrf($input);
        $batch = rexel_extension_create_batch(
            $pdo,
            rexel_extension_clean_text($input['category'] ?? 'ALL', 120),
            (int)($input['max_items'] ?? 10)
        );
        echo json_encode(['ok' => true] + $batch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'panel_batch_status') {
        rexel_extension_require_csrf($input);
        $batch = rexel_extension_get_panel_batch($pdo, rexel_extension_clean_text($input['batch_id'] ?? '', 64));
        echo json_encode(['ok' => true, 'batch' => rexel_extension_batch_summary($pdo, $batch)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'apply_batch') {
        rexel_extension_require_csrf($input);
        $batch = rexel_extension_get_panel_batch($pdo, rexel_extension_clean_text($input['batch_id'] ?? '', 64));
        $result = rexel_extension_apply_batch($pdo, $batch);
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'create_job') {
        rexel_extension_require_csrf($input);
        $job = rexel_extension_create_job($pdo, (int)($input['url_id'] ?? 0), (int)($input['max_items'] ?? 10));
        echo json_encode(['ok' => true] + $job, JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'panel_status') {
        rexel_extension_require_csrf($input);
        $job = rexel_extension_get_panel_job($pdo, rexel_extension_clean_text($input['job_id'] ?? '', 64));
        echo json_encode(['ok' => true, 'job' => rexel_extension_summary($pdo, $job)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'apply') {
        rexel_extension_require_csrf($input);
        $job = rexel_extension_get_panel_job($pdo, rexel_extension_clean_text($input['job_id'] ?? '', 64));
        $result = rexel_extension_apply($pdo, $job);
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'pair') {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($input['code'] ?? '')));
        if (strlen($code) !== 8) {
            throw new InvalidArgumentException('El codigo de vinculacion debe tener 8 caracteres.');
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $pdo->prepare("SELECT * FROM rexel_extension_jobs WHERE pair_code_hash = ? AND status = 'created' AND pair_expires_at >= ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([rexel_extension_hash_secret($code), rexel_extension_now()]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                throw new RuntimeException('Codigo invalido, ya utilizado o expirado.');
            }
            $token = bin2hex(random_bytes(32));
            $tokenExpires = date('Y-m-d H:i:s', time() + REXEL_EXTENSION_JOB_TTL);
            $update = $pdo->prepare("UPDATE rexel_extension_jobs SET status = 'paired', paired_at = ?, token_hash = ?, token_expires_at = ?, pair_code_hash = ?, progress_message = ? WHERE id = ? AND status = 'created'");
            $update->execute([
                rexel_extension_now(), rexel_extension_hash_secret($token), $tokenExpires,
                rexel_extension_hash_secret(bin2hex(random_bytes(16))),
                'Extension vinculada; lista para abrir Rexel.', $job['id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('El codigo ya fue consumido por otra solicitud.');
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
            throw $e;
        }
        echo json_encode([
            'ok' => true,
            'job' => [
                'job_id' => $job['id'],
                'token' => $token,
                'token_expires_at' => $tokenExpires,
                'category' => $job['category'],
                'target_url' => $job['target_url'],
                'max_items' => (int)$job['max_items'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $jobId = rexel_extension_clean_text($input['job_id'] ?? '', 64);
    $job = rexel_extension_get_token_job($pdo, $jobId, rexel_extension_bearer_token());

    if ($action === 'extension_status') {
        $allowed = ['paired', 'opening', 'awaiting_login', 'scraping', 'error'];
        $status = rexel_extension_clean_text($input['status'] ?? '', 40);
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Estado de extension no permitido.');
        }
        if (in_array($job['status'], ['submitted', 'applied'], true)) {
            echo json_encode(['ok' => true, 'job' => rexel_extension_summary($pdo, $job, false)]);
            exit;
        }
        $message = rexel_extension_clean_text($input['message'] ?? '', 500);
        $error = $status === 'error' ? $message : null;
        $stmt = $pdo->prepare('UPDATE rexel_extension_jobs SET status = ?, progress_message = ?, error_message = ? WHERE id = ?');
        $stmt->execute([$status, $message, $error, $job['id']]);
        $job = rexel_extension_reload_job($pdo, $job['id']);
        echo json_encode(['ok' => true, 'job' => rexel_extension_summary($pdo, $job, false)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'submit_results') {
        $result = rexel_extension_store_results($pdo, $job, $input);
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'job_status') {
        echo json_encode(['ok' => true, 'job' => rexel_extension_summary($pdo, $job, false)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new InvalidArgumentException('Accion desconocida.');
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $isAuth = str_contains(strtolower($message), 'autoriz') || str_contains(strtolower($message), 'token');
    http_response_code($isAuth ? 403 : 409);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Rexel extension API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno al procesar el trabajo.'], JSON_UNESCAPED_UNICODE);
}
