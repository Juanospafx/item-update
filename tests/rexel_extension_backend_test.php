<?php
declare(strict_types=1);

require_once __DIR__ . '/../web/rexel_extension_lib.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}
function expect_exception(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        return;
    }
    throw new RuntimeException('FAIL: ' . $message);
}

session_id('rexel-owner-test');
session_start();
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE scraping_urls (id INTEGER PRIMARY KEY, category TEXT NOT NULL, url TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)');
$pdo->exec('CREATE TABLE catalogo_web (id INTEGER PRIMARY KEY, categoria TEXT NOT NULL, nombre_web TEXT NOT NULL, url_origen TEXT, precio_actual REAL, ultima_actualizacion TEXT, precio_anterior REAL)');
$pdo->exec("INSERT INTO scraping_urls VALUES (1, 'EMT', 'https://www.rexelusa.com/s/example?show=10', 1)");
$pdo->exec("INSERT INTO catalogo_web VALUES (7, 'EMT', 'Mapped Product', NULL, 8.5, '2026-01-01 00:00:00', 8.0)");
rexel_extension_create_schema($pdo);

$created = rexel_extension_create_job($pdo, 1, 4);
$job = rexel_extension_get_panel_job($pdo, $created['job_id']);
check($job['category'] === 'EMT', 'el trabajo conserva categoria y URL configuradas');
check(strlen($created['pair_code']) === 8, 'el codigo temporal tiene ocho caracteres');

session_write_close();
session_id('rexel-attacker-test');
session_start();
expect_exception(fn() => rexel_extension_get_panel_job($pdo, $created['job_id']), 'otra sesion no debe leer el trabajo');
session_write_close();
session_id('rexel-owner-test');
session_start();

$pdo->prepare("UPDATE rexel_extension_jobs SET status='paired', token_hash=?, token_expires_at=? WHERE id=?")
    ->execute([rexel_extension_hash_secret('temporary-token'), date('Y-m-d H:i:s', time() + 3600), $created['job_id']]);
$job = rexel_extension_get_token_job($pdo, $created['job_id'], 'temporary-token');
expect_exception(fn() => rexel_extension_get_token_job($pdo, $created['job_id'], 'wrong-token'), 'un token incorrecto no debe autorizar');

$payload = [
    'source_url' => 'https://www.rexelusa.com/s/example?show=10&page=1',
    'items' => [
        ['name' => 'Mapped Product', 'sku' => 'SKU-1', 'reference' => 'REF-1', 'product_url' => 'https://www.rexelusa.com/p/mapped', 'price' => 10.25, 'currency' => 'USD', 'unit' => 'EA', 'price_label' => 'Your Price'],
        ['name' => 'No Price Product', 'product_url' => 'https://www.rexelusa.com/p/no-price', 'price' => null],
        ['name' => 'Unmapped Product', 'product_url' => 'https://www.rexelusa.com/p/unmapped', 'price' => 2.5, 'currency' => 'USD'],
        ['name' => 'Mapped Product', 'product_url' => 'https://www.rexelusa.com/p/mapped-duplicate', 'price' => 11.0, 'currency' => 'USD'],
    ],
];
$stored = rexel_extension_store_results($pdo, $job, $payload);
check($stored['duplicate'] === false, 'la primera carga se almacena');
check($stored['summary']['counts']['matched'] === 1, 'el mapeo exacto encuentra solo el producto conocido');
check($stored['summary']['counts']['unmatched'] === 3, 'una segunda coincidencia al mismo registro se marca no aplicable');
check($stored['summary']['counts']['applicable'] === 1, 'solo una fila combina precio valido y correspondencia');
check($stored['summary']['counts']['without_price'] === 1, 'un precio ausente permanece NULL');

$fresh = rexel_extension_get_panel_job($pdo, $created['job_id']);
$duplicate = rexel_extension_store_results($pdo, $fresh, $payload);
check($duplicate['duplicate'] === true, 'reenviar el mismo resultado es idempotente');
check((int)$pdo->query('SELECT COUNT(*) FROM rexel_extension_results')->fetchColumn() === 4, 'el reenvio no duplica filas');

expect_exception(function () use ($job) {
    rexel_extension_validate_results(['source_url' => 'https://evil.example/s/example', 'items' => []], $job);
}, 'se rechaza una URL ajena a Rexel');
expect_exception(function () use ($job) {
    rexel_extension_validate_results(['source_url' => $job['target_url'], 'items' => [['name' => 'Zero', 'price' => 0]]], $job);
}, 'se rechaza precio cero');

$fresh = rexel_extension_get_panel_job($pdo, $created['job_id']);
$applied = rexel_extension_apply($pdo, $fresh);
check($applied['summary']['updated'] === 1, 'solo se aplica el producto con precio y correspondencia');
$catalog = $pdo->query('SELECT precio_actual, precio_anterior FROM catalogo_web WHERE id=7')->fetch(PDO::FETCH_ASSOC);
check((float)$catalog['precio_actual'] === 10.25 && (float)$catalog['precio_anterior'] === 8.5, 'se conserva la transicion de historial existente');
$fresh = rexel_extension_get_panel_job($pdo, $created['job_id']);
$appliedAgain = rexel_extension_apply($pdo, $fresh);
check($appliedAgain['duplicate'] === true, 'aplicar dos veces no crea otra actualizacion/historial');

$pdo->exec("INSERT INTO scraping_urls VALUES (2, 'BATCH', 'https://www.rexelusa.com/s/batch-a', 1)");
$pdo->exec("INSERT INTO scraping_urls VALUES (3, 'BATCH', 'https://www.rexelusa.com/s/batch-b', 1)");
$pdo->exec("INSERT INTO catalogo_web VALUES (8, 'BATCH', 'Batch Product', NULL, 4.0, '2026-01-01 00:00:00', 3.5)");
$batchCreated = rexel_extension_create_batch($pdo, 'BATCH', 3);
check($batchCreated['total_urls'] === 2, 'el lote incluye todas las URLs activas de la categoria');
$batch = rexel_extension_get_panel_batch($pdo, $batchCreated['batch_id']);

session_write_close();
session_id('rexel-batch-attacker-test');
session_start();
expect_exception(fn() => rexel_extension_get_panel_batch($pdo, $batchCreated['batch_id']), 'otra sesion no debe leer el lote');
session_write_close();
session_id('rexel-owner-test');
session_start();

foreach ($batchCreated['jobs'] as $index => $batchJobCreated) {
    $token = 'batch-token-' . $index;
    $pdo->prepare("UPDATE rexel_extension_jobs SET status='paired', token_hash=?, token_expires_at=? WHERE id=?")
        ->execute([rexel_extension_hash_secret($token), date('Y-m-d H:i:s', time() + 3600), $batchJobCreated['job_id']]);
    $batchJob = rexel_extension_get_token_job($pdo, $batchJobCreated['job_id'], $token);
    rexel_extension_store_results($pdo, $batchJob, [
        'source_url' => $batchJob['target_url'],
        'items' => [[
            'name' => 'Batch Product',
            'sku' => 'BATCH-1',
            'product_url' => 'https://www.rexelusa.com/p/batch-product',
            'price' => 6.25,
            'currency' => 'USD',
            'unit' => 'EA',
            'price_label' => 'Your Price',
        ]],
    ]);
}
$batch = rexel_extension_get_panel_batch($pdo, $batchCreated['batch_id']);
$batchSummary = rexel_extension_batch_summary($pdo, $batch);
check($batchSummary['counts']['completed_urls'] === 2, 'el lote contabiliza todas las URLs enviadas');
$batchApplied = rexel_extension_apply_batch($pdo, $batch);
check($batchApplied['summary']['updated'] === 1, 'el lote actualiza una sola vez el producto repetido');
check($batchApplied['summary']['duplicate_matches_skipped'] === 1, 'el lote omite la coincidencia duplicada sin duplicar historial');
$batchAgain = rexel_extension_apply_batch($pdo, rexel_extension_get_panel_batch($pdo, $batchCreated['batch_id']));
check($batchAgain['duplicate'] === true, 'reaplicar el lote completo es idempotente');

echo "OK: validacion, autorizacion, lote, mapeo, NULL sin precio e idempotencia\n";
