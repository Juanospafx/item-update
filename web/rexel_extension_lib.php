<?php

require_once __DIR__ . '/db.php';

const REXEL_EXTENSION_PAIR_TTL = 600;
const REXEL_EXTENSION_JOB_TTL = 7200;
const REXEL_EXTENSION_MAX_RESULTS = 50;

function rexel_extension_now(): string
{
    return date('Y-m-d H:i:s');
}

function rexel_extension_session_hash(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('No hay una sesion activa del panel.');
    }
    return hash('sha256', session_id());
}

function rexel_extension_hash_secret(string $secret): string
{
    return hash('sha256', $secret);
}

function rexel_extension_create_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS rexel_extension_batches (
    id TEXT PRIMARY KEY,
    session_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'created',
    created_at TEXT NOT NULL,
    applied_at TEXT,
    apply_summary TEXT
)
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS rexel_extension_jobs (
    id TEXT PRIMARY KEY,
    session_hash TEXT NOT NULL,
    url_id INTEGER NOT NULL,
    category TEXT NOT NULL,
    target_url TEXT NOT NULL,
    max_items INTEGER NOT NULL DEFAULT 10,
    pair_code_hash TEXT NOT NULL,
    pair_expires_at TEXT NOT NULL,
    paired_at TEXT,
    token_hash TEXT,
    token_expires_at TEXT,
    status TEXT NOT NULL DEFAULT 'created',
    progress_message TEXT,
    error_message TEXT,
    result_digest TEXT,
    created_at TEXT NOT NULL,
    submitted_at TEXT,
    applied_at TEXT,
    apply_summary TEXT,
    batch_id TEXT,
    FOREIGN KEY (url_id) REFERENCES scraping_urls(id)
)
SQL);
    $columns = $pdo->query('PRAGMA table_info(rexel_extension_jobs)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('batch_id', $columns, true)) {
        $pdo->exec('ALTER TABLE rexel_extension_jobs ADD COLUMN batch_id TEXT');
    }
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS rexel_extension_results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id TEXT NOT NULL,
    ordinal INTEGER NOT NULL,
    name TEXT NOT NULL,
    sku TEXT,
    reference TEXT,
    product_url TEXT,
    price REAL,
    currency TEXT,
    unit TEXT,
    price_label TEXT,
    mapping_status TEXT NOT NULL,
    catalogo_web_id INTEGER,
    validation_warning TEXT,
    FOREIGN KEY (job_id) REFERENCES rexel_extension_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (catalogo_web_id) REFERENCES catalogo_web(id),
    UNIQUE(job_id, ordinal)
)
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rexel_ext_jobs_session ON rexel_extension_jobs(session_hash, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rexel_ext_jobs_pair ON rexel_extension_jobs(pair_code_hash, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rexel_ext_jobs_batch ON rexel_extension_jobs(batch_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rexel_ext_results_job ON rexel_extension_results(job_id, ordinal)');
}

function rexel_extension_create_batch(PDO $pdo, string $category, int $maxItems): array
{
    rexel_extension_create_schema($pdo);
    $maxItems = max(1, min(25, $maxItems));
    $category = trim($category);
    if ($category === '' || strtoupper($category) === 'ALL') {
        $stmt = $pdo->query('SELECT id FROM scraping_urls WHERE is_active = 1 ORDER BY category, id');
    } else {
        $stmt = $pdo->prepare('SELECT id FROM scraping_urls WHERE is_active = 1 AND category = ? ORDER BY id');
        $stmt->execute([$category]);
    }
    $urlIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$urlIds) {
        throw new InvalidArgumentException('No hay URLs activas para el lote seleccionado.');
    }
    if (count($urlIds) > 60) {
        throw new InvalidArgumentException('El prototipo admite hasta 60 URLs por lote.');
    }
    $batchId = bin2hex(random_bytes(16));
    $jobs = [];
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $insert = $pdo->prepare("INSERT INTO rexel_extension_batches (id, session_hash, status, created_at) VALUES (?, ?, 'created', ?)");
        $insert->execute([$batchId, rexel_extension_session_hash(), rexel_extension_now()]);
        foreach ($urlIds as $urlId) {
            $job = rexel_extension_create_job($pdo, $urlId, $maxItems);
            $pdo->prepare('UPDATE rexel_extension_jobs SET batch_id = ? WHERE id = ?')->execute([$batchId, $job['job_id']]);
            $jobRow = rexel_extension_get_panel_job($pdo, $job['job_id']);
            $jobs[] = $job + [
                'category' => $jobRow['category'],
                'target_url' => $jobRow['target_url'],
                'max_items' => (int)$jobRow['max_items'],
            ];
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
        throw $e;
    }
    return ['batch_id' => $batchId, 'jobs' => $jobs, 'total_urls' => count($jobs)];
}

function rexel_extension_get_panel_batch(PDO $pdo, string $batchId): array
{
    $stmt = $pdo->prepare('SELECT * FROM rexel_extension_batches WHERE id = ? AND session_hash = ?');
    $stmt->execute([$batchId, rexel_extension_session_hash()]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$batch) {
        throw new RuntimeException('Lote no encontrado o no autorizado.');
    }
    return $batch;
}

function rexel_extension_batch_summary(PDO $pdo, array $batch): array
{
    $stmt = $pdo->prepare('SELECT * FROM rexel_extension_jobs WHERE batch_id = ? AND session_hash = ? ORDER BY created_at, id');
    $stmt->execute([$batch['id'], rexel_extension_session_hash()]);
    $jobs = [];
    $counts = ['urls' => 0, 'completed_urls' => 0, 'found' => 0, 'with_price' => 0, 'without_price' => 0, 'matched' => 0, 'unmatched' => 0, 'applicable' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $job) {
        $summary = rexel_extension_summary($pdo, $job);
        $jobs[] = $summary;
        $counts['urls']++;
        if (in_array($job['status'], ['submitted', 'applied'], true)) $counts['completed_urls']++;
        foreach (['found', 'with_price', 'without_price', 'matched', 'unmatched', 'applicable'] as $key) {
            $counts[$key] += (int)($summary['counts'][$key] ?? 0);
        }
    }
    return [
        'batch_id' => $batch['id'],
        'status' => $batch['status'],
        'created_at' => $batch['created_at'],
        'applied_at' => $batch['applied_at'],
        'counts' => $counts,
        'jobs' => $jobs,
        'apply_summary' => $batch['apply_summary'] ? json_decode($batch['apply_summary'], true) : null,
    ];
}

function rexel_extension_is_allowed_url(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    return $scheme === 'https'
        && ($host === 'rexelusa.com' || str_ends_with($host, '.rexelusa.com'));
}

function rexel_extension_normalize_url(string $url): string
{
    $parts = parse_url($url);
    if (!$parts) {
        return '';
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path = $parts['path'] ?? '/';
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    return $scheme . '://' . $host . $port . $path . $query;
}

function rexel_extension_same_target(string $received, string $configured): bool
{
    if (!rexel_extension_is_allowed_url($received) || !rexel_extension_is_allowed_url($configured)) {
        return false;
    }
    $a = parse_url(rexel_extension_normalize_url($received));
    $b = parse_url(rexel_extension_normalize_url($configured));
    parse_str((string)($a['query'] ?? ''), $receivedQuery);
    parse_str((string)($b['query'] ?? ''), $configuredQuery);
    foreach (array_keys($receivedQuery) as $key) {
        if (str_starts_with(strtolower((string)$key), 'utm_')) {
            unset($receivedQuery[$key]);
        }
    }
    if (!isset($configuredQuery['page']) && isset($receivedQuery['page']) && (string)$receivedQuery['page'] === '1') {
        unset($receivedQuery['page']);
    }
    ksort($receivedQuery);
    ksort($configuredQuery);
    return strtolower((string)$a['host']) === strtolower((string)$b['host'])
        && rtrim((string)($a['path'] ?? '/'), '/') === rtrim((string)($b['path'] ?? '/'), '/')
        && $receivedQuery === $configuredQuery;
}

function rexel_extension_clean_text($value, int $maxLength): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }
    return substr($value, 0, $maxLength);
}

function rexel_extension_validate_results(array $payload, array $job): array
{
    $sourceUrl = rexel_extension_clean_text($payload['source_url'] ?? '', 2048);
    if (!rexel_extension_same_target($sourceUrl, $job['target_url'])) {
        throw new InvalidArgumentException('La URL de origen no corresponde a la URL configurada para el trabajo.');
    }
    $items = $payload['items'] ?? null;
    if (!is_array($items)) {
        throw new InvalidArgumentException('items debe ser una lista.');
    }
    $limit = min((int)$job['max_items'], REXEL_EXTENSION_MAX_RESULTS);
    if (count($items) > $limit) {
        throw new InvalidArgumentException("El trabajo admite como maximo {$limit} productos.");
    }

    $validated = [];
    $seen = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException("El producto #{$index} no es un objeto valido.");
        }
        $name = rexel_extension_clean_text($item['name'] ?? '', 500);
        if ($name === '') {
            throw new InvalidArgumentException("El producto #{$index} no tiene nombre.");
        }
        $sku = rexel_extension_clean_text($item['sku'] ?? '', 160);
        $reference = rexel_extension_clean_text($item['reference'] ?? '', 160);
        $productUrl = rexel_extension_clean_text($item['product_url'] ?? '', 2048);
        if ($productUrl !== '' && !rexel_extension_is_allowed_url($productUrl)) {
            throw new InvalidArgumentException("La URL del producto #{$index} no pertenece a Rexel USA.");
        }
        $currency = strtoupper(rexel_extension_clean_text($item['currency'] ?? '', 3));
        if ($currency !== '' && $currency !== 'USD') {
            throw new InvalidArgumentException("La moneda del producto #{$index} no es USD.");
        }
        $price = $item['price'] ?? null;
        if ($price === '' || $price === null) {
            $price = null;
        } elseif (!is_int($price) && !is_float($price) && !(is_string($price) && is_numeric($price))) {
            throw new InvalidArgumentException("El precio del producto #{$index} no es numerico.");
        } else {
            $price = round((float)$price, 4);
            if (!is_finite($price) || $price <= 0 || $price > 10000000) {
                throw new InvalidArgumentException("El precio del producto #{$index} esta fuera de rango.");
            }
        }
        $key = strtolower($productUrl !== '' ? $productUrl : $name . '|' . $sku);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $validated[] = [
            'name' => $name,
            'sku' => $sku,
            'reference' => $reference,
            'product_url' => $productUrl,
            'price' => $price,
            'currency' => $currency ?: ($price !== null ? 'USD' : ''),
            'unit' => rexel_extension_clean_text($item['unit'] ?? '', 120),
            'price_label' => rexel_extension_clean_text($item['price_label'] ?? '', 120),
        ];
    }
    return ['source_url' => $sourceUrl, 'items' => $validated];
}

function rexel_extension_create_job(PDO $pdo, int $urlId, int $maxItems): array
{
    rexel_extension_create_schema($pdo);
    $maxItems = max(1, min(25, $maxItems));
    $stmt = $pdo->prepare('SELECT id, category, url FROM scraping_urls WHERE id = ? AND is_active = 1');
    $stmt->execute([$urlId]);
    $urlRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$urlRow || !rexel_extension_is_allowed_url($urlRow['url'])) {
        throw new InvalidArgumentException('La URL seleccionada no esta activa o no pertenece a Rexel USA.');
    }

    $jobId = bin2hex(random_bytes(16));
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $now = rexel_extension_now();
    $expires = date('Y-m-d H:i:s', time() + REXEL_EXTENSION_PAIR_TTL);
    $insert = $pdo->prepare(<<<'SQL'
INSERT INTO rexel_extension_jobs
(id, session_hash, url_id, category, target_url, max_items, pair_code_hash, pair_expires_at, status, created_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'created', ?)
SQL);
    $insert->execute([
        $jobId,
        rexel_extension_session_hash(),
        $urlRow['id'],
        $urlRow['category'],
        $urlRow['url'],
        $maxItems,
        rexel_extension_hash_secret($code),
        $expires,
        $now,
    ]);
    return ['job_id' => $jobId, 'pair_code' => $code, 'pair_expires_at' => $expires];
}

function rexel_extension_get_panel_job(PDO $pdo, string $jobId): array
{
    $stmt = $pdo->prepare('SELECT * FROM rexel_extension_jobs WHERE id = ? AND session_hash = ?');
    $stmt->execute([$jobId, rexel_extension_session_hash()]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        throw new RuntimeException('Trabajo no encontrado o no autorizado.');
    }
    return $job;
}

function rexel_extension_get_token_job(PDO $pdo, string $jobId, string $token): array
{
    if ($token === '') {
        throw new RuntimeException('Falta el token temporal del trabajo.');
    }
    $stmt = $pdo->prepare('SELECT * FROM rexel_extension_jobs WHERE id = ? AND token_hash = ?');
    $stmt->execute([$jobId, rexel_extension_hash_secret($token)]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job || empty($job['token_expires_at']) || strtotime($job['token_expires_at']) < time()) {
        throw new RuntimeException('Token de trabajo invalido o expirado.');
    }
    return $job;
}

function rexel_extension_summary(PDO $pdo, array $job, bool $withResults = true): array
{
    $response = [
        'job_id' => $job['id'],
        'category' => $job['category'],
        'target_url' => $job['target_url'],
        'max_items' => (int)$job['max_items'],
        'status' => $job['status'],
        'progress_message' => $job['progress_message'],
        'error_message' => $job['error_message'],
        'created_at' => $job['created_at'],
        'submitted_at' => $job['submitted_at'],
        'applied_at' => $job['applied_at'],
    ];
    if (!$withResults) {
        return $response;
    }
    $stmt = $pdo->prepare('SELECT * FROM rexel_extension_results WHERE job_id = ? ORDER BY ordinal');
    $stmt->execute([$job['id']]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $counts = ['found' => count($results), 'with_price' => 0, 'without_price' => 0, 'matched' => 0, 'unmatched' => 0, 'applicable' => 0];
    foreach ($results as &$row) {
        $row['price'] = $row['price'] !== null ? (float)$row['price'] : null;
        $row['catalogo_web_id'] = $row['catalogo_web_id'] !== null ? (int)$row['catalogo_web_id'] : null;
        $row['price'] === null ? $counts['without_price']++ : $counts['with_price']++;
        $row['mapping_status'] === 'matched' ? $counts['matched']++ : $counts['unmatched']++;
        if ($row['mapping_status'] === 'matched' && $row['price'] !== null) {
            $counts['applicable']++;
        }
    }
    unset($row);
    $response['counts'] = $counts;
    $response['results'] = $results;
    if ($job['apply_summary']) {
        $response['apply_summary'] = json_decode($job['apply_summary'], true);
    }
    return $response;
}

function rexel_extension_store_results(PDO $pdo, array $job, array $payload): array
{
    $validated = rexel_extension_validate_results($payload, $job);
    $digest = hash('sha256', json_encode($validated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if (in_array($job['status'], ['submitted', 'applied'], true)) {
        if (hash_equals((string)$job['result_digest'], $digest)) {
            return ['duplicate' => true, 'summary' => rexel_extension_summary($pdo, $job)];
        }
        throw new RuntimeException('El trabajo ya tiene un resultado diferente y no puede sobrescribirse.');
    }

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $check = $pdo->prepare('SELECT status, result_digest FROM rexel_extension_jobs WHERE id = ?');
        $check->execute([$job['id']]);
        $current = $check->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            throw new RuntimeException('El trabajo ya no existe.');
        }
        if (in_array($current['status'], ['submitted', 'applied'], true)) {
            if (hash_equals((string)$current['result_digest'], $digest)) {
                $pdo->exec('COMMIT');
                $latest = $pdo->prepare('SELECT * FROM rexel_extension_jobs WHERE id = ?');
                $latest->execute([$job['id']]);
                return ['duplicate' => true, 'summary' => rexel_extension_summary($pdo, $latest->fetch(PDO::FETCH_ASSOC))];
            }
            throw new RuntimeException('El trabajo ya fue recibido por otra solicitud con datos diferentes.');
        }
        $delete = $pdo->prepare('DELETE FROM rexel_extension_results WHERE job_id = ?');
        $delete->execute([$job['id']]);
        $find = $pdo->prepare('SELECT id FROM catalogo_web WHERE categoria = ? AND nombre_web = ? ORDER BY id LIMIT 1');
        $insert = $pdo->prepare(<<<'SQL'
INSERT INTO rexel_extension_results
(job_id, ordinal, name, sku, reference, product_url, price, currency, unit, price_label, mapping_status, catalogo_web_id, validation_warning)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
        $matchedCatalogIds = [];
        foreach ($validated['items'] as $ordinal => $item) {
            $find->execute([$job['category'], $item['name']]);
            $catalogId = $find->fetchColumn();
            $mapping = $catalogId ? 'matched' : 'unmatched';
            $warning = $item['price'] === null ? 'Sin precio: no se aplicara ningun cambio.' : null;
            if ($catalogId && isset($matchedCatalogIds[(string)$catalogId])) {
                $mapping = 'duplicate';
                $catalogId = null;
                $warning = 'Correspondencia duplicada dentro del trabajo: no se aplicara dos veces.';
            } elseif ($catalogId) {
                $matchedCatalogIds[(string)$catalogId] = true;
            }
            $insert->execute([
                $job['id'], $ordinal, $item['name'], $item['sku'], $item['reference'],
                $item['product_url'], $item['price'], $item['currency'], $item['unit'],
                $item['price_label'], $mapping, $catalogId ?: null, $warning,
            ]);
        }
        $update = $pdo->prepare("UPDATE rexel_extension_jobs SET status = 'submitted', result_digest = ?, submitted_at = ?, progress_message = ? WHERE id = ?");
        $update->execute([$digest, rexel_extension_now(), 'Resultados recibidos; esperando revision y aplicacion.', $job['id']]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
        throw $e;
    }
    $fresh = $pdo->prepare('SELECT * FROM rexel_extension_jobs WHERE id = ?');
    $fresh->execute([$job['id']]);
    return ['duplicate' => false, 'summary' => rexel_extension_summary($pdo, $fresh->fetch(PDO::FETCH_ASSOC))];
}

function rexel_extension_apply(PDO $pdo, array $job): array
{
    if ($job['status'] === 'applied') {
        return ['duplicate' => true, 'summary' => json_decode((string)$job['apply_summary'], true) ?: []];
    }
    if ($job['status'] !== 'submitted') {
        throw new RuntimeException('El trabajo aun no tiene una vista previa lista para aplicar.');
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $reserve = $pdo->prepare("UPDATE rexel_extension_jobs SET status = 'applying' WHERE id = ? AND session_hash = ? AND status = 'submitted'");
        $reserve->execute([$job['id'], rexel_extension_session_hash()]);
        if ($reserve->rowCount() !== 1) {
            $latestStmt = $pdo->prepare('SELECT status, apply_summary FROM rexel_extension_jobs WHERE id = ? AND session_hash = ?');
            $latestStmt->execute([$job['id'], rexel_extension_session_hash()]);
            $latest = $latestStmt->fetch(PDO::FETCH_ASSOC);
            if ($latest && $latest['status'] === 'applied') {
                $pdo->exec('COMMIT');
                return ['duplicate' => true, 'summary' => json_decode((string)$latest['apply_summary'], true) ?: []];
            }
            throw new RuntimeException('El trabajo ya esta siendo aplicado o no esta autorizado.');
        }
        $rows = $pdo->prepare("SELECT catalogo_web_id, price FROM rexel_extension_results WHERE job_id = ? AND mapping_status = 'matched' AND price IS NOT NULL AND price > 0");
        $rows->execute([$job['id']]);
        $update = $pdo->prepare(<<<'SQL'
UPDATE catalogo_web
SET precio_anterior = CASE
        WHEN precio_actual IS NOT NULL AND precio_actual > 0 AND precio_actual != ? THEN precio_actual
        ELSE COALESCE(precio_anterior, precio_actual, ?)
    END,
    precio_actual = ?, url_origen = ?, ultima_actualizacion = ?
WHERE id = ? AND categoria = ?
SQL);
        $updated = 0;
        $now = rexel_extension_now();
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $price = (float)$row['price'];
            $update->execute([$price, $price, $price, $job['target_url'], $now, $row['catalogo_web_id'], $job['category']]);
            $updated += $update->rowCount();
        }
        $countStmt = $pdo->prepare(<<<'SQL'
SELECT
    COUNT(*) AS found,
    SUM(CASE WHEN price IS NOT NULL THEN 1 ELSE 0 END) AS with_price,
    SUM(CASE WHEN price IS NULL THEN 1 ELSE 0 END) AS without_price,
    SUM(CASE WHEN mapping_status != 'matched' THEN 1 ELSE 0 END) AS unmatched
FROM rexel_extension_results WHERE job_id = ?
SQL);
        $countStmt->execute([$job['id']]);
        $counts = $countStmt->fetch(PDO::FETCH_ASSOC);
        $summary = [
            'updated' => $updated,
            'found' => (int)($counts['found'] ?? 0),
            'with_price' => (int)($counts['with_price'] ?? 0),
            'without_price' => (int)($counts['without_price'] ?? 0),
            'unmatched' => (int)($counts['unmatched'] ?? 0),
        ];
        $finish = $pdo->prepare("UPDATE rexel_extension_jobs SET status = 'applied', applied_at = ?, apply_summary = ?, progress_message = ? WHERE id = ? AND status = 'applying'");
        $finish->execute([$now, json_encode($summary), 'Precios aplicados explicitamente desde la vista previa.', $job['id']]);
        $pdo->exec('COMMIT');
        return ['duplicate' => false, 'summary' => $summary];
    } catch (Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
        throw $e;
    }
}

function rexel_extension_apply_batch(PDO $pdo, array $batch): array
{
    if ($batch['status'] === 'applied') {
        return ['duplicate' => true, 'summary' => json_decode((string)$batch['apply_summary'], true) ?: []];
    }
    $statusStmt = $pdo->prepare('SELECT status, COUNT(*) AS total FROM rexel_extension_jobs WHERE batch_id = ? GROUP BY status');
    $statusStmt->execute([$batch['id']]);
    $statuses = $statusStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $totalJobs = array_sum(array_map('intval', $statuses));
    $readyJobs = (int)($statuses['submitted'] ?? 0) + (int)($statuses['applied'] ?? 0);
    if ($totalJobs === 0 || $readyJobs !== $totalJobs) {
        throw new RuntimeException("El lote aun no esta completo ({$readyJobs}/{$totalJobs} URLs listas).");
    }

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $reserve = $pdo->prepare("UPDATE rexel_extension_batches SET status = 'applying' WHERE id = ? AND session_hash = ? AND status != 'applied'");
        $reserve->execute([$batch['id'], rexel_extension_session_hash()]);
        if ($reserve->rowCount() !== 1) {
            throw new RuntimeException('El lote ya esta siendo aplicado o no esta autorizado.');
        }
        $rows = $pdo->prepare(<<<'SQL'
SELECT r.catalogo_web_id, r.price, j.category, j.target_url
FROM rexel_extension_results r
JOIN rexel_extension_jobs j ON j.id = r.job_id
WHERE j.batch_id = ? AND j.session_hash = ?
  AND r.mapping_status = 'matched' AND r.price IS NOT NULL AND r.price > 0
ORDER BY j.created_at, r.ordinal
SQL);
        $rows->execute([$batch['id'], rexel_extension_session_hash()]);
        $grouped = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['catalogo_web_id'];
            $grouped[$id][] = $row;
        }
        $update = $pdo->prepare(<<<'SQL'
UPDATE catalogo_web
SET precio_anterior = CASE
        WHEN precio_actual IS NOT NULL AND precio_actual > 0 AND precio_actual != ? THEN precio_actual
        ELSE COALESCE(precio_anterior, precio_actual, ?)
    END,
    precio_actual = ?, url_origen = ?, ultima_actualizacion = ?
WHERE id = ? AND categoria = ?
SQL);
        $updated = 0;
        $duplicates = 0;
        $conflicts = 0;
        $now = rexel_extension_now();
        foreach ($grouped as $catalogId => $matches) {
            $prices = array_values(array_unique(array_map(fn($row) => number_format((float)$row['price'], 4, '.', ''), $matches)));
            if (count($prices) > 1) {
                $conflicts++;
                continue;
            }
            $duplicates += max(0, count($matches) - 1);
            $row = $matches[0];
            $price = (float)$row['price'];
            $update->execute([$price, $price, $price, $row['target_url'], $now, $catalogId, $row['category']]);
            $updated += $update->rowCount();
        }
        $summary = [
            'updated' => $updated,
            'duplicate_matches_skipped' => $duplicates,
            'conflicting_prices_skipped' => $conflicts,
            'urls' => $totalJobs,
        ];
        $json = json_encode($summary);
        $pdo->prepare("UPDATE rexel_extension_jobs SET status = 'applied', applied_at = ?, apply_summary = ? WHERE batch_id = ? AND status = 'submitted'")
            ->execute([$now, $json, $batch['id']]);
        $pdo->prepare("UPDATE rexel_extension_batches SET status = 'applied', applied_at = ?, apply_summary = ? WHERE id = ?")
            ->execute([$now, $json, $batch['id']]);
        $pdo->exec('COMMIT');
        return ['duplicate' => false, 'summary' => $summary];
    } catch (Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
        throw $e;
    }
}
