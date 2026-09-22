'use strict';

const wait = ms => new Promise(resolve => setTimeout(resolve, ms));

async function hydrateLazyProducts() {
  const originalY = window.scrollY;
  const heights = [0.25, 0.5, 0.75, 1];
  for (const ratio of heights) {
    window.scrollTo({top: Math.max(0, document.documentElement.scrollHeight * ratio), behavior: 'auto'});
    await wait(650);
  }
  window.scrollTo({top: originalY, behavior: 'auto'});
  await wait(250);
}

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (message.type !== 'extract') return false;
  (async () => {
    try {
      const initialGate = RexelExtractor.detectGate(document, location);
      if (initialGate.blocked) {
        sendResponse({status: 'awaiting_login', gate: initialGate, source_url: location.href, items: []});
        return;
      }
      await hydrateLazyProducts();
      const result = RexelExtractor.extract(document, location, Math.max(1, Math.min(25, Number(message.maxItems) || 10)));
      sendResponse({...result, source_url: location.href, title: document.title});
    } catch (error) {
      sendResponse({status: 'error', error: error.message || String(error), source_url: location.href});
    }
  })();
  return true;
});

chrome.runtime.sendMessage({type: 'rexel-page-ready', url: location.href}).catch(() => {});

const BATCH_STATE_KEY = 'rexelExperimentalJob';
let progressHost = null;

function ensureProgressOverlay() {
  if (progressHost && document.documentElement.contains(progressHost)) return progressHost.shadowRoot;
  progressHost = document.createElement('div');
  progressHost.id = 'rexel-local-scraper-progress';
  progressHost.style.cssText = 'position:fixed;top:14px;right:14px;z-index:2147483647;';
  const root = progressHost.attachShadow({mode:'open'});
  root.innerHTML = `
    <style>
      .box{width:290px;padding:12px;border:1px solid #475569;border-radius:10px;background:#111827;color:#f8fafc;box-shadow:0 8px 28px #0008;font:13px/1.35 system-ui,sans-serif}
      .title{font-weight:800}.count{color:#93c5fd;margin-top:3px}.message{color:#fbbf24;margin-top:8px}.track{height:6px;margin-top:9px;border-radius:99px;overflow:hidden;background:#334155}.bar{height:100%;background:#22c55e;transition:width .25s}.actions{display:flex;gap:7px;margin-top:9px}button{flex:1;border:0;border-radius:6px;padding:7px;background:#d97706;color:white;font-weight:750;cursor:pointer}
    </style>
    <div class="box">
      <div class="title">Rexel Local Scraper</div>
      <div class="count"></div>
      <div class="track"><div class="bar"></div></div>
      <div class="message"></div>
      <div class="actions"><button type="button"></button></div>
    </div>`;
  (document.documentElement || document.body).appendChild(progressHost);
  root.querySelector('button').addEventListener('click', async event => {
    event.preventDefault();
    event.stopPropagation();
    const stored = await chrome.storage.local.get(BATCH_STATE_KEY);
    const state = stored[BATCH_STATE_KEY];
    const resume = state && ['paused','pause_pending'].includes(state.phase);
    await chrome.runtime.sendMessage({type:resume ? 'resume-batch' : 'pause-batch'});
  });
  return root;
}

function renderProgressOverlay(state) {
  if (!state || state.mode !== 'batch') {
    if (progressHost) progressHost.remove();
    progressHost = null;
    return;
  }
  const root = ensureProgressOverlay();
  const total = state.totalJobs || (state.jobs || []).length;
  const completed = Math.min(Number(state.currentIndex) || 0,total);
  const working = ['navigating','scraping','pause_pending'].includes(state.phase);
  const percent = total ? Math.min(100,((completed + (working ? 0.45 : 0)) / total) * 100) : 0;
  root.querySelector('.count').textContent = `${completed}/${total} URLs completadas · actual ${Math.min(completed + 1,total)}/${total}`;
  root.querySelector('.bar').style.width = `${percent}%`;
  root.querySelector('.message').textContent = state.message || state.phase || 'Preparando recorrido…';
  const button = root.querySelector('button');
  button.hidden = state.phase === 'completed';
  button.textContent = ['paused','pause_pending'].includes(state.phase) ? (state.phase === 'pause_pending' ? 'Cancelar pausa' : 'Reanudar') : 'Pausar recorrido';
}

chrome.storage.onChanged.addListener((changes, area) => {
  if (area === 'local' && changes[BATCH_STATE_KEY]) renderProgressOverlay(changes[BATCH_STATE_KEY].newValue || null);
});
chrome.storage.local.get(BATCH_STATE_KEY).then(stored => renderProgressOverlay(stored[BATCH_STATE_KEY])).catch(() => {});
