'use strict';

const STATE_KEY = 'rexelExperimentalJob';

async function getState() {
  return (await chrome.storage.local.get(STATE_KEY))[STATE_KEY] || null;
}
async function setState(patch) {
  const current = await getState() || {};
  const next = {...current, ...patch, updatedAt: Date.now()};
  await chrome.storage.local.set({[STATE_KEY]: next});
  return next;
}
async function api(state, action, extra = {}) {
  const response = await fetch(state.apiUrl, {
    method: 'POST',
    headers: {'Content-Type': 'application/json', ...(state.token ? {'Authorization': `Bearer ${state.token}`} : {})},
    body: JSON.stringify({action, ...(state.jobId ? {job_id: state.jobId} : {}), ...extra})
  });
  const data = await response.json().catch(() => ({ok:false,error:`HTTP ${response.status}`}));
  if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
  return data;
}
async function report(state, status, message) {
  await setState({phase: status, message, error: status === 'error' ? message : ''});
  try { await api(state, 'extension_status', {status, message}); } catch (_) {}
}
async function locateTab(state) {
  if (state.tabId) {
    try { return await chrome.tabs.get(state.tabId); } catch (_) {}
  }
  const tabs = await chrome.tabs.query({url: ['https://www.rexelusa.com/*', 'https://auth.rexelusa.com/*']});
  return tabs[0] || null;
}
async function openRexel() {
  const state = await getState();
  if (!state || !state.targetUrl) throw new Error('Primero vincula un trabajo.');
  const tab = await chrome.tabs.create({url: state.targetUrl, active: true});
  await setState({tabId: tab.id, phase: 'opening', message: 'Pestaña de Rexel abierta.'});
  await report({...state, tabId: tab.id}, 'opening', 'Pestaña local abierta; esperando renderizado o inicio de sesion.');
  return {ok: true};
}
async function extractAndSubmit() {
  let state = await getState();
  if (!state || !state.token) throw new Error('El trabajo no esta vinculado.');
  const tab = await locateTab(state);
  if (!tab || !tab.id) throw new Error('No se encontro una pestaña de Rexel. Usa Abrir Rexel.');
  state = await setState({tabId: tab.id});
  await report(state, 'scraping', 'Leyendo productos renderizados en la pestaña local.');
  let result;
  try {
    result = await chrome.tabs.sendMessage(tab.id, {type: 'extract', maxItems: state.maxItems});
  } catch (error) {
    throw new Error('La extension no pudo acceder a la pestaña. Recarga Rexel y vuelve a intentar.');
  }
  if (result.status === 'awaiting_login') {
    const message = result.gate && result.gate.message || 'Completa el inicio de sesion o verificacion en Rexel.';
    await report(state, 'awaiting_login', message);
    return {ok: true, awaitingLogin: true, message};
  }
  if (result.status !== 'ok') throw new Error(result.error || 'No se pudo extraer el DOM de Rexel.');
  if (!result.items.length) throw new Error('No se encontraron productos renderizados en esta pagina.');
  const submitted = await api(state, 'submit_results', {source_url: result.source_url, items: result.items});
  await setState({phase: 'submitted', message: result.warning || 'Resultados enviados. Revisa la vista previa en el panel.', lastCount: result.items.length});
  return {ok: true, submitted: true, count: result.items.length, duplicate: submitted.duplicate, warning: result.warning || ''};
}

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  (async () => {
    if (message.type === 'get-state') return {ok:true, state:await getState()};
    if (message.type === 'save-pair') {
      const job = message.job;
      const state = {apiUrl:message.apiUrl, jobId:job.job_id, token:job.token, tokenExpiresAt:job.token_expires_at, targetUrl:job.target_url, category:job.category, maxItems:job.max_items, phase:'paired', message:'Trabajo vinculado.', error:''};
      await chrome.storage.local.set({[STATE_KEY]:state});
      return {ok:true,state};
    }
    if (message.type === 'open-rexel') return await openRexel();
    if (message.type === 'extract') return await extractAndSubmit();
    if (message.type === 'clear-job') { await chrome.storage.local.remove(STATE_KEY); return {ok:true}; }
    if (message.type === 'rexel-page-ready') {
      const state = await getState();
      if (state && sender.tab) await setState({tabId:sender.tab.id, message:'Rexel cargado. Pulsa Continuar / extraer.'});
      return {ok:true};
    }
    return {ok:false,error:'Mensaje desconocido'};
  })().then(sendResponse).catch(async error => {
    const state = await getState();
    if (state && state.token) await report(state, 'error', error.message || String(error));
    sendResponse({ok:false,error:error.message || String(error)});
  });
  return true;
});

