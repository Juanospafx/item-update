'use strict';

const $ = id => document.getElementById(id);
const send = message => chrome.runtime.sendMessage(message);
const STATE_KEY = 'rexelExperimentalJob';
let renderedState = null;

function showError(message = '') {
  $('error').hidden = !message;
  $('error').textContent = message;
}

function originPattern(apiUrl) {
  const url = new URL(apiUrl);
  return `${url.protocol}//${url.host}/*`;
}

function render(state) {
  renderedState = state;
  $('pair-section').hidden = Boolean(state);
  $('job-section').hidden = !state;
  if (!state) return;
  if (state.mode === 'batch') {
    const total = state.totalJobs || (state.jobs || []).length;
    const completed = Math.min(Number(state.currentIndex) || 0, total);
    $('job-info').textContent = `Recorrido automático · ${completed}/${total} URLs completadas`;
    const inProgress = ['navigating','scraping','pause_pending'].includes(state.phase);
    const progress = total ? Math.min(100, state.phase === 'pairing' ? (((state.jobs || []).length / total) * 100) : (((completed + (inProgress ? 0.45 : 0)) / total) * 100)) : 0;
    $('progress-bar').style.width = `${progress}%`;
    $('open').textContent = 'Mostrar pestaña de Rexel';
    $('open').disabled = state.phase === 'pairing';
    $('pause').hidden = state.phase === 'completed';
    $('pause').textContent = ['paused','pause_pending'].includes(state.phase) ? (state.phase === 'pause_pending' ? 'Cancelar pausa solicitada' : 'Reanudar recorrido') : 'Pausar recorrido';
    $('extract').hidden = !['awaiting_login','error'].includes(state.phase);
    $('extract').textContent = state.phase === 'awaiting_login' ? 'Continuar después del login' : 'Reintentar URL actual';
    $('extract').disabled = state.phase === 'completed';
    const urlBox = $('current-url');
    if (state.currentUrl) {
      try {
        const current = new URL(state.currentUrl);
        urlBox.textContent = `URL ${Math.min(completed + 1,total)}/${total}: ${current.hostname}${current.pathname}${current.search}`;
      } catch (_) {
        urlBox.textContent = state.currentUrl;
      }
      urlBox.hidden = false;
    } else {
      urlBox.hidden = true;
    }
  } else {
    $('job-info').textContent = `${state.category} · máximo ${state.maxItems} productos`;
    $('open').textContent = 'Abrir Rexel';
    $('open').disabled = false;
    $('extract').textContent = 'Continuar / extraer';
    $('extract').disabled = false;
    $('extract').hidden = false;
    $('pause').hidden = true;
    $('current-url').hidden = true;
    $('progress-bar').style.width = '0%';
  }
  $('status').textContent = state.message || state.phase || 'Vinculado';
}

async function restore() {
  const result = await send({type:'get-state'});
  render(result.state);
}

$('pair').addEventListener('click', async () => {
  showError();
  try {
    const apiUrl = $('api-url').value.trim();
    const code = $('pair-code').value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    const parsedApi = new URL(apiUrl);
    if (/^(?:www\.)?rexelusa\.com$/i.test(parsedApi.hostname) || !parsedApi.pathname.endsWith('/rexel_extension_api.php')) {
      throw new Error('La primera casilla debe contener la URL del API terminada en rexel_extension_api.php, no una URL de Rexel.');
    }
    if (code.length !== 8) throw new Error('El código temporal debe tener 8 caracteres.');
    const granted = await chrome.permissions.request({origins:[originPattern(apiUrl)]});
    if (!granted) throw new Error('Debes autorizar el origen del panel para vincular el trabajo.');
    const response = await fetch(apiUrl, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'pair', code})});
    const data = await response.json().catch(() => ({ok:false,error:'El servidor no devolvió JSON. Verifica la URL del API.'}));
    if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
    const saved = await send({type:'save-pair', apiUrl, job:data.job});
    render(saved.state);
  } catch (error) {
    showError(error.message || String(error));
  }
});

$('open').addEventListener('click', async () => {
  showError();
  $('open').disabled = true;
  $('open').textContent = 'Mostrando Rexel…';
  const result = await send({type:'open-rexel'});
  if (!result.ok) {
    showError(result.error);
    $('open').disabled = false;
  } else {
    window.close();
  }
  await restore();
});

$('extract').addEventListener('click', async () => {
  showError();
  $('status').textContent = 'Reanudando…';
  const result = await send({type:'extract'});
  if (!result.ok) showError(result.error);
  await restore();
});

$('pause').addEventListener('click', async () => {
  showError();
  const shouldResume = renderedState && ['paused','pause_pending'].includes(renderedState.phase);
  $('status').textContent = shouldResume ? 'Reanudando recorrido…' : 'Solicitando pausa…';
  const result = await send({type:shouldResume ? 'resume-batch' : 'pause-batch'});
  if (!result.ok) showError(result.error);
  await restore();
});

$('clear').addEventListener('click', async () => {
  await send({type:'clear-job'});
  render(null);
});

chrome.storage.onChanged.addListener((changes, area) => {
  if (area === 'local' && changes[STATE_KEY]) render(changes[STATE_KEY].newValue || null);
});

restore().catch(error => showError(error.message));
setInterval(() => restore().catch(() => {}), 750);
