<?php
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/rexel_extension_config.php';
require_once __DIR__ . '/db.php';

$urls = [];
$batchCategories = [];
if (REXEL_EXTENSION_EXPERIMENT_ENABLED) {
    try {
        $stmt = get_db_connection()->query('SELECT id, category, url, description FROM scraping_urls WHERE is_active = 1 ORDER BY category, id');
        $urls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $batchCategories = array_values(array_unique(array_column($urls, 'category')));
    } catch (Throwable $e) {
        $loadError = 'No fue posible cargar las URLs configuradas.';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rexel en navegador local - Experimental</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .rx-wrap{max-width:1180px;margin:0 auto;padding:24px 16px 60px}.rx-head{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:20px}.rx-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,.8fr);gap:18px}.rx-card{background:var(--bg-panel,#202633);border:1px solid var(--border-subtle,#374151);border-radius:16px;padding:20px;color:var(--text-primary,#f3f4f6)}.rx-badge{display:inline-flex;padding:4px 9px;border-radius:999px;background:#7c2d12;color:#fed7aa;font-size:12px;font-weight:800;letter-spacing:.04em}.rx-muted{color:var(--text-secondary,#aeb6c5);line-height:1.55}.rx-field{width:100%;box-sizing:border-box;background:var(--bg-input,#151a23);color:var(--text-primary,#fff);border:1px solid var(--border-subtle,#374151);border-radius:9px;padding:10px}.rx-btn{border:0;border-radius:9px;padding:10px 14px;font-weight:750;cursor:pointer;background:var(--accent-primary,#fb5a3a);color:white}.rx-btn:disabled{opacity:.5;cursor:not-allowed}.rx-btn-secondary{background:#334155}.rx-code{font:800 30px/1.2 Consolas,monospace;letter-spacing:.16em;color:#fbbf24;margin:10px 0}.rx-status{padding:12px;border-radius:9px;background:#111827;border:1px solid #334155;margin-top:14px}.rx-kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:9px;margin:16px 0}.rx-kpi{background:#111827;border:1px solid #334155;border-radius:9px;padding:10px;text-align:center;color:#cbd5e1;font-weight:650;line-height:1.4}.rx-kpi strong{display:block;font-size:21px;color:#f8fafc;font-variant-numeric:tabular-nums}.rx-table-wrap{overflow:auto}.rx-table{width:100%;border-collapse:collapse;font-size:13px}.rx-table th,.rx-table td{padding:9px;border-bottom:1px solid #334155;text-align:left;vertical-align:top}.rx-ok{color:#047857}.rx-status.rx-ok{color:#6ee7b7}.rx-warn{color:#b45309}.rx-status.rx-warn{color:#fbbf24}.rx-error{color:#be123c}.rx-status.rx-error{color:#fda4af}.rx-hidden{display:none!important}.rx-steps{padding-left:20px}.rx-steps li{margin:9px 0;color:var(--text-secondary,#aeb6c5)}code{word-break:break-all}@media(max-width:850px){.rx-grid{grid-template-columns:1fr}.rx-kpis{grid-template-columns:repeat(2,1fr)}}
    </style>
</head>
<body>
<main class="rx-wrap">
    <div class="rx-head">
        <div>
            <span class="rx-badge">EXPERIMENTAL</span>
            <h1>Scraping de Rexel en tu navegador</h1>
            <p class="rx-muted">Este flujo no ejecuta Playwright ni Chromium en el servidor. Solo recibe los productos que la extensión extrae de la pestaña local.</p>
        </div>
        <a href="index.php" class="rx-btn rx-btn-secondary" style="text-decoration:none">Volver al panel</a>
    </div>

    <?php if (!REXEL_EXTENSION_EXPERIMENT_ENABLED): ?>
        <section class="rx-card"><h2>Prototipo desactivado</h2><p class="rx-muted">El flujo anterior sigue disponible sin cambios.</p></section>
    <?php else: ?>
    <div class="rx-grid">
        <section class="rx-card">
            <h2>1. Recorrido automático</h2>
            <p class="rx-muted">Una sola acción vincula la extensión y recorre todas las URLs activas de la categoría usando una pestaña de Rexel.</p>
            <?php if (!empty($loadError)): ?><p class="rx-error"><?php echo htmlspecialchars($loadError); ?></p><?php endif; ?>
            <label for="batch-category">Categoría</label>
            <select id="batch-category" class="rx-field">
                <option value="ALL">Todas las categorías y URLs activas</option>
                <?php foreach ($batchCategories as $category): ?>
                    <option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option>
                <?php endforeach; ?>
            </select>
            <label for="batch-max-items" style="display:block;margin-top:12px">Máximo de productos por URL (1-25)</label>
            <input id="batch-max-items" class="rx-field" type="number" min="1" max="25" value="10">
            <button id="start-batch" class="rx-btn" style="margin-top:14px" <?php echo empty($urls) ? 'disabled' : ''; ?>>Iniciar recorrido automático</button>
            <div id="extension-bridge-status" class="rx-status" style="color:#cbd5e1">Comprobando la extensión instalada…</div>

            <details style="margin-top:18px;border-top:1px solid #334155;padding-top:14px">
            <summary style="cursor:pointer;font-weight:700">Prueba manual de una sola URL</summary>
            <div style="margin-top:12px">
            <label for="url-id">URL configurada</label>
            <select id="url-id" class="rx-field">
                <?php foreach ($urls as $url): ?>
                    <option value="<?php echo (int)$url['id']; ?>"><?php echo htmlspecialchars($url['category'] . ' - ' . ($url['description'] ?: $url['url'])); ?></option>
                <?php endforeach; ?>
            </select>
            <label for="max-items" style="display:block;margin-top:12px">Máximo de productos (1-25)</label>
            <input id="max-items" class="rx-field" type="number" min="1" max="25" value="10">
            <button id="create-job" class="rx-btn" style="margin-top:14px" <?php echo empty($urls) ? 'disabled' : ''; ?>>Crear trabajo y código</button>

            <div id="pair-box" class="rx-status rx-hidden" style="color:#cbd5e1">
                <div style="color:#cbd5e1">Introduce este código una sola vez en la extensión:</div>
                <div id="pair-code" class="rx-code"></div>
                <div style="color:#cbd5e1;margin-bottom:6px">Caduca en 10 minutos. API del panel:</div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <code id="api-url" style="display:block;flex:1;min-width:240px;color:#60a5fa;background:#0b1220;border:1px solid #334155;border-radius:7px;padding:9px;word-break:break-all"></code>
                    <button id="copy-api-url" type="button" class="rx-btn rx-btn-secondary">Copiar URL</button>
                </div>
            </div>
            </div>
            </details>

            <div id="job-status" class="rx-status rx-hidden" aria-live="polite"></div>
        </section>

        <aside class="rx-card">
            <h2>Instalación de la extensión</h2>
            <ol class="rx-steps">
                <li>Abre <code>chrome://extensions</code> (Chrome/Brave) o <code>edge://extensions</code> (Edge).</li>
                <li>Activa <strong>Modo de desarrollador</strong>.</li>
                <li>Pulsa <strong>Cargar descomprimida</strong> y elige la carpeta <code>extension/rexel-local-scraper</code> de este proyecto.</li>
                <li>Fija “Rexel Local Scraper” en la barra y verifica que muestre la versión <strong>0.4.1</strong>.</li>
                <li>Pulsa <strong>Iniciar recorrido automático</strong>. El panel vinculará temporalmente todos los trabajos sin copiar URLs ni códigos.</li>
                <li>La extensión reutilizará una pestaña de Rexel. Si pide acceso, login, CAPTCHA o verificación, resuélvelo allí y pulsa <strong>Reanudar recorrido</strong>.</li>
            </ol>
            <p class="rx-muted"><strong>No se envían</strong> contraseña, cookies, tokens ni localStorage de Rexel. CAPTCHA o verificaciones se resuelven manualmente en la pestaña normal.</p>
        </aside>
    </div>

    <section id="preview" class="rx-card rx-hidden" style="margin-top:18px">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
            <div><h2 style="margin-bottom:4px">2. Vista previa (sin escritura)</h2><div class="rx-muted">Solo “Aplicar precios” modifica <code>catalogo_web</code>.</div></div>
            <button id="apply-job" class="rx-btn" disabled>Aplicar precios válidos</button>
        </div>
        <div class="rx-kpis">
            <div class="rx-kpi"><strong id="count-found">0</strong>Encontrados</div>
            <div class="rx-kpi"><strong id="count-price">0</strong>Con precio</div>
            <div class="rx-kpi"><strong id="count-no-price">0</strong>Sin precio</div>
            <div class="rx-kpi"><strong id="count-matched">0</strong>Correspondencia</div>
            <div class="rx-kpi"><strong id="count-unmatched">0</strong>Sin correspondencia</div>
        </div>
        <div class="rx-table-wrap"><table class="rx-table"><thead><tr><th>Producto</th><th>SKU / referencia</th><th>Precio</th><th>Moneda / unidad</th><th>Mapeo</th></tr></thead><tbody id="preview-body"></tbody></table></div>
        <p class="rx-muted">Limitación del prototipo: extrae únicamente los productos renderizados en la página actual (incluida carga diferida por scroll). No recorre paginación ni garantiza detectar listas virtualizadas que retiren nodos del DOM.</p>
    </section>
    <?php endif; ?>
</main>
<?php if (REXEL_EXTENSION_EXPERIMENT_ENABLED): ?>
<script>
const API_URL = new URL('rexel_extension_api.php', window.location.href).href;
const CSRF = <?php echo json_encode($_SESSION['csrf_token']); ?>;
let currentJob = null;
let currentBatch = null;
let pollTimer = null;
let extensionReady = false;
document.getElementById('api-url').textContent = API_URL;
document.getElementById('copy-api-url').addEventListener('click', async () => {
    const button = document.getElementById('copy-api-url');
    try {
        await navigator.clipboard.writeText(API_URL);
        button.textContent = 'Copiada';
        setTimeout(() => { button.textContent = 'Copiar URL'; }, 1500);
    } catch (error) {
        window.prompt('Copia esta URL del API:', API_URL);
    }
});

async function api(action, extra = {}) {
    const response = await fetch(API_URL, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action, csrf_token:CSRF, ...extra})});
    const data = await response.json().catch(() => ({ok:false,error:'Respuesta no válida del servidor'}));
    if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
    return data;
}

window.addEventListener('message', event => {
    if (event.source !== window || event.origin !== window.location.origin) return;
    const data = event.data;
    if (!data || data.source !== 'rexel-extension') return;
    if (data.type === 'REXEL_EXTENSION_READY') {
        extensionReady = data.version === '0.4.1';
        const el = document.getElementById('extension-bridge-status');
        el.textContent = extensionReady
            ? 'Extensión 0.4.1 lista. Progreso en vivo y pausa disponibles.'
            : 'Actualiza y recarga la extensión: se requiere la versión 0.4.1.';
        el.className = `rx-status ${extensionReady ? 'rx-ok' : 'rx-warn'}`;
    }
    if (data.type === 'REXEL_BATCH_ACK' && data.batchId === currentBatch) {
        if (data.result && data.result.ok) {
            setStatus(`Extensión vinculada. Recorriendo ${data.result.total} URLs automáticamente.`, 'rx-ok');
        } else {
            setStatus(`No se pudo iniciar la extensión: ${data.result?.error || 'error desconocido'}`, 'rx-error');
            document.getElementById('start-batch').disabled = false;
        }
    }
});

window.postMessage({source:'rexel-panel', type:'PING_REXEL_EXTENSION'}, window.location.origin);
setTimeout(() => {
    if (!extensionReady) {
        const el = document.getElementById('extension-bridge-status');
        el.textContent = 'No se detectó la versión 0.4.1. Recarga la extensión en Edge y luego recarga esta página.';
        el.className = 'rx-status rx-warn';
    }
}, 1200);

document.getElementById('start-batch').addEventListener('click', async () => {
    if (!extensionReady) {
        setStatus('Primero recarga la extensión 0.4.1 desde edge://extensions y vuelve a cargar esta página.', 'rx-error');
        return;
    }
    const button = document.getElementById('start-batch');
    button.disabled = true;
    try {
        const data = await api('create_batch', {
            category: document.getElementById('batch-category').value,
            max_items: Number(document.getElementById('batch-max-items').value)
        });
        currentBatch = data.batch_id;
        currentJob = null;
        document.getElementById('preview').classList.add('rx-hidden');
        setStatus(`Lote creado con ${data.total_urls} URLs. Entregándolo a la extensión…`, 'rx-warn');
        window.postMessage({source:'rexel-panel', type:'START_REXEL_BATCH', apiUrl:API_URL, batchId:data.batch_id, jobs:data.jobs}, window.location.origin);
        clearInterval(pollTimer);
        pollTimer = setInterval(refreshBatch, 2000);
        refreshBatch();
    } catch (error) {
        setStatus(error.message, 'rx-error');
        button.disabled = false;
    }
});
function setStatus(text, kind='') {
    const el = document.getElementById('job-status'); el.classList.remove('rx-hidden','rx-error','rx-ok','rx-warn');
    if (kind) el.classList.add(kind); el.textContent = text;
}
document.getElementById('create-job').addEventListener('click', async () => {
    try {
        const data = await api('create_job', {url_id:Number(document.getElementById('url-id').value), max_items:Number(document.getElementById('max-items').value)});
        currentBatch = null;
        currentJob = data.job_id;
        document.getElementById('pair-code').textContent = data.pair_code;
        document.getElementById('pair-box').classList.remove('rx-hidden');
        document.getElementById('preview').classList.add('rx-hidden');
        setStatus('Trabajo creado. Esperando que la extensión consuma el código.', 'rx-warn');
        clearInterval(pollTimer); pollTimer = setInterval(refreshJob, 2000); refreshJob();
    } catch (e) { setStatus(e.message, 'rx-error'); }
});
async function refreshJob() {
    if (!currentJob) return;
    try {
        const data = await api('panel_status', {job_id:currentJob});
        const job = data.job;
        const labels = {created:'Esperando vinculación',paired:'Extensión vinculada',opening:'Abriendo Rexel',awaiting_login:'Inicia sesión o resuelve la verificación en Rexel y continúa desde la extensión',scraping:'Extrayendo el DOM renderizado',submitted:'Vista previa lista',applying:'Aplicando',applied:'Aplicado',error:'Error'};
        setStatus(`${labels[job.status] || job.status}${job.progress_message ? ': ' + job.progress_message : ''}`, job.status === 'error' ? 'rx-error' : (job.status === 'submitted' || job.status === 'applied' ? 'rx-ok' : 'rx-warn'));
        if (job.status === 'submitted' || job.status === 'applied') renderPreview(job);
        if (job.status === 'applied') clearInterval(pollTimer);
    } catch (e) { setStatus(e.message, 'rx-error'); }
}

async function refreshBatch() {
    if (!currentBatch) return;
    try {
        const data = await api('panel_batch_status', {batch_id:currentBatch});
        const batch = data.batch;
        const counts = batch.counts || {};
        const problem = (batch.jobs || []).find(job => job.status === 'error' || job.status === 'awaiting_login');
        const active = (batch.jobs || []).find(job => ['opening','scraping'].includes(job.status));
        const paused = (batch.jobs || []).find(job => job.status === 'paused');
        const complete = Number(counts.completed_urls || 0) === Number(counts.urls || 0) && Number(counts.urls || 0) > 0;
        if (complete || batch.status === 'applied') document.getElementById('start-batch').disabled = false;
        if (batch.status === 'applied') {
            setStatus(`Lote aplicado: ${batch.apply_summary?.updated || 0} precios actualizados.`, 'rx-ok');
            clearInterval(pollTimer);
        } else if (problem) {
            const prefix = problem.status === 'awaiting_login' ? 'Pausa para iniciar sesión/verificación' : 'Error';
            setStatus(`${prefix} en ${problem.category}: ${problem.progress_message || problem.status}`, problem.status === 'error' ? 'rx-error' : 'rx-warn');
        } else if (active) {
            setStatus(`Recorrido ${counts.completed_urls || 0}/${counts.urls || 0}: ${active.progress_message || `procesando ${active.category}`}`, 'rx-warn');
        } else if (paused) {
            setStatus(`Recorrido pausado ${counts.completed_urls || 0}/${counts.urls || 0}: ${paused.progress_message || paused.category}`, 'rx-warn');
        } else {
            setStatus(`Recorrido automático: ${counts.completed_urls || 0}/${counts.urls || 0} URLs terminadas${complete ? '. Vista previa lista.' : '.'}`, complete ? 'rx-ok' : 'rx-warn');
        }
        renderBatchPreview(batch);
    } catch (error) {
        setStatus(error.message, 'rx-error');
    }
}

function renderBatchPreview(batch) {
    document.getElementById('preview').classList.remove('rx-hidden');
    const counts = batch.counts || {};
    for (const [id,key] of [['count-found','found'],['count-price','with_price'],['count-no-price','without_price'],['count-matched','matched'],['count-unmatched','unmatched']]) {
        document.getElementById(id).textContent = counts[key] || 0;
    }
    const body = document.getElementById('preview-body');
    body.replaceChildren();
    for (const job of (batch.jobs || [])) {
        for (const item of (job.results || [])) {
            const tr = document.createElement('tr');
            const values = [
                `${item.name || 'Producto'} (${job.category})`,
                [item.sku,item.reference].filter(Boolean).join(' / ') || '—',
                item.price === null ? 'Sin precio' : '$' + Number(item.price).toFixed(4).replace(/0+$/,'').replace(/\.$/,''),
                [item.currency,item.unit,item.price_label].filter(Boolean).join(' · ') || '—',
                item.mapping_status === 'matched' ? 'Correspondencia exacta' : (item.mapping_status === 'duplicate' ? 'Duplicado no aplicable' : 'Sin correspondencia')
            ];
            values.forEach((value,index) => {
                const td = document.createElement('td');
                td.textContent = value;
                if (index === 2 && item.price === null) td.className = 'rx-warn';
                if (index === 4) td.className = item.mapping_status === 'matched' ? 'rx-ok' : 'rx-warn';
                tr.appendChild(td);
            });
            body.appendChild(tr);
        }
    }
    const complete = Number(counts.completed_urls || 0) === Number(counts.urls || 0) && Number(counts.urls || 0) > 0;
    const apply = document.getElementById('apply-job');
    apply.disabled = batch.status === 'applied' || !complete || !(counts.applicable > 0);
    apply.textContent = batch.status === 'applied' ? `Aplicado (${batch.apply_summary?.updated || 0})` : 'Aplicar precios válidos del lote';
}

function renderPreview(job) {
    document.getElementById('preview').classList.remove('rx-hidden');
    const c = job.counts || {};
    for (const [id,key] of [['count-found','found'],['count-price','with_price'],['count-no-price','without_price'],['count-matched','matched'],['count-unmatched','unmatched']]) document.getElementById(id).textContent = c[key] || 0;
    const body = document.getElementById('preview-body'); body.replaceChildren();
    for (const item of (job.results || [])) {
        const tr = document.createElement('tr');
        const values = [item.name, [item.sku,item.reference].filter(Boolean).join(' / ') || '—', item.price === null ? 'Sin precio' : '$' + Number(item.price).toFixed(4).replace(/0+$/,'').replace(/\.$/,''), [item.currency,item.unit,item.price_label].filter(Boolean).join(' · ') || '—', item.mapping_status === 'matched' ? 'Correspondencia exacta' : 'Sin correspondencia'];
        values[4] = item.mapping_status === 'matched' ? 'Correspondencia exacta' : (item.mapping_status === 'duplicate' ? 'Duplicado no aplicable' : 'Sin correspondencia');
        values.forEach((value,index) => { const td=document.createElement('td'); td.textContent=value; if(index===2 && item.price===null) td.className='rx-warn'; if(index===4) td.className=item.mapping_status==='matched'?'rx-ok':'rx-warn'; tr.appendChild(td); });
        body.appendChild(tr);
    }
    const apply = document.getElementById('apply-job'); apply.disabled = job.status !== 'submitted' || !(c.applicable > 0); apply.textContent = job.status === 'applied' ? `Aplicado (${job.apply_summary?.updated || 0})` : 'Aplicar precios válidos';
}
document.getElementById('apply-job').addEventListener('click', async () => {
    if (!currentJob || !confirm('¿Aplicar únicamente los precios positivos con correspondencia exacta? Esta acción actualizará el historial de precio.')) return;
    try { document.getElementById('apply-job').disabled=true; const data=await api('apply',{job_id:currentJob}); setStatus(`Aplicación terminada: ${data.summary.updated || 0} precios actualizados.`, 'rx-ok'); refreshJob(); }
    catch(e) { setStatus(e.message,'rx-error'); refreshJob(); }
});
document.getElementById('apply-job').addEventListener('click', async () => {
    if (!currentBatch) return;
    if (!confirm('¿Aplicar los precios positivos con correspondencia exacta de todo el lote? Los conflictos se omitirán y el historial se actualizará una sola vez por producto.')) return;
    try {
        document.getElementById('apply-job').disabled = true;
        const data = await api('apply_batch', {batch_id:currentBatch});
        setStatus(`Lote aplicado: ${data.summary.updated || 0} precios actualizados; ${data.summary.conflicting_prices_skipped || 0} conflictos omitidos.`, 'rx-ok');
        refreshBatch();
    } catch (error) {
        setStatus(error.message, 'rx-error');
        refreshBatch();
    }
});
</script>
<?php endif; ?>
</body>
</html>
