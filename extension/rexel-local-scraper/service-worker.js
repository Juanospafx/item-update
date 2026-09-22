'use strict';

const STATE_KEY = 'rexelExperimentalJob';
let batchProcessing = false;

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
async function pair(apiUrl, code) {
  const response = await fetch(apiUrl, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'pair', code})});
  const data = await response.json().catch(() => ({ok:false,error:`HTTP ${response.status}`}));
  if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
  return data.job;
}
async function report(state, status, message) {
  if (state.mode !== 'batch') await setState({phase: status, message, error: status === 'error' ? message : ''});
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
  if (!state) throw new Error('Primero vincula un trabajo.');
  if (state.mode === 'batch') return navigateBatchCurrent(true);
  if (!state.targetUrl) throw new Error('El trabajo no tiene URL objetivo.');
  const tab = await chrome.tabs.create({url: state.targetUrl, active: true});
  await setState({tabId: tab.id, phase: 'opening', message: 'Pestaña de Rexel abierta.'});
  await report({...state, tabId: tab.id}, 'opening', 'Pestaña local abierta; esperando renderizado o inicio de sesion.');
  return {ok: true};
}
async function extractAndSubmit() {
  let state = await getState();
  if (state && state.mode === 'batch') return navigateBatchCurrent(false);
  if (!state || !state.token) throw new Error('El trabajo no esta vinculado.');
  const tab = await locateTab(state);
  if (!tab || !tab.id) throw new Error('No se encontro una pestaña de Rexel. Usa Abrir Rexel.');
  state = await setState({tabId: tab.id});
  await report(state, 'scraping', 'Leyendo productos renderizados en la pestaña local.');
  let result;
  try { result = await chrome.tabs.sendMessage(tab.id, {type: 'extract', maxItems: state.maxItems}); }
  catch (_) { throw new Error('La extension no pudo acceder a la pestaña. Activa el acceso a Rexel, recarga la pagina y vuelve a intentar.'); }
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

async function startBatch(message, sender) {
  let senderUrl;
  let apiUrl;
  try {
    senderUrl = new URL(sender.url);
    apiUrl = new URL(message.apiUrl);
  } catch (_) {
    throw new Error('Origen del panel no autorizado.');
  }
  if (senderUrl.origin !== 'https://item-update.brightronix.net' || apiUrl.origin !== senderUrl.origin || !apiUrl.pathname.endsWith('/rexel_extension_api.php')) {
    throw new Error('Origen del panel no autorizado.');
  }
  if (!Array.isArray(message.jobs) || !message.jobs.length || message.jobs.length > 60) throw new Error('Lote invalido.');
  const pairedJobs = [];
  for (let index = 0; index < message.jobs.length; index += 1) {
    const paired = await pair(message.apiUrl, message.jobs[index].pair_code);
    pairedJobs.push({apiUrl:message.apiUrl,jobId:paired.job_id,token:paired.token,tokenExpiresAt:paired.token_expires_at,targetUrl:paired.target_url,category:paired.category,maxItems:paired.max_items});
    await setState({mode:'batch',apiUrl:message.apiUrl,batchId:message.batchId,jobs:pairedJobs,totalJobs:message.jobs.length,currentIndex:0,phase:'pairing',message:`Vinculando URLs ${index + 1}/${message.jobs.length}`});
  }
  await setState({mode:'batch',apiUrl:message.apiUrl,batchId:message.batchId,jobs:pairedJobs,totalJobs:pairedJobs.length,currentIndex:0,phase:'ready',message:`${pairedJobs.length} URLs vinculadas. Iniciando recorrido automático.`});
  await navigateBatchCurrent(true);
  return {ok:true,total:pairedJobs.length};
}

async function navigateBatchCurrent(activate) {
  const state = await getState();
  if (!state || state.mode !== 'batch') throw new Error('No hay lote automático activo.');
  const index = Number(state.currentIndex) || 0;
  if (index >= state.jobs.length) {
    await setState({phase:'completed',message:`Lote completado: ${state.jobs.length}/${state.jobs.length} URLs.`});
    return {ok:true,completed:true};
  }
  const job = state.jobs[index];
  let tab = await locateTab(state);
  if (!tab) tab = await chrome.tabs.create({url:job.targetUrl,active:activate});
  else await chrome.tabs.update(tab.id,{url:job.targetUrl,active:activate});
  await setState({tabId:tab.id,phase:'navigating',message:`Abriendo URL ${index + 1}/${state.jobs.length} (${job.category})`});
  await report({...job,mode:'batch'},'opening',`Procesando URL ${index + 1}/${state.jobs.length}.`);
  return {ok:true,index,total:state.jobs.length};
}

async function processBatchPage(tabId) {
  if (batchProcessing) return;
  batchProcessing = true;
  try {
    const state = await getState();
    if (!state || state.mode !== 'batch' || state.phase !== 'navigating') return;
    const index = Number(state.currentIndex) || 0;
    const job = state.jobs[index];
    if (!job || (state.tabId && state.tabId !== tabId)) return;
    await setState({phase:'scraping',message:`Extrayendo URL ${index + 1}/${state.jobs.length} (${job.category})`});
    await report({...job,mode:'batch'},'scraping',`Extrayendo URL ${index + 1}/${state.jobs.length}.`);
    await new Promise(resolve => setTimeout(resolve,1200));
    let result;
    try { result = await chrome.tabs.sendMessage(tabId,{type:'extract',maxItems:job.maxItems}); }
    catch (_) { throw new Error('No se pudo ejecutar el extractor en la pestaña de Rexel.'); }
    if (result.status === 'awaiting_login') {
      const message = result.gate && result.gate.message || 'Completa el login o verificacion en Rexel.';
      await report({...job,mode:'batch'},'awaiting_login',message);
      await setState({phase:'awaiting_login',message:`Pausa en URL ${index + 1}/${state.jobs.length}: ${message}`});
      return;
    }
    if (result.status !== 'ok') throw new Error(result.error || 'Fallo de extraccion.');
    await api(job,'submit_results',{source_url:result.source_url,items:result.items});
    const nextIndex = index + 1;
    await setState({currentIndex:nextIndex,phase:nextIndex >= state.jobs.length ? 'completed' : 'ready',message:nextIndex >= state.jobs.length ? `Lote completado: ${nextIndex}/${state.jobs.length} URLs.` : `URL ${nextIndex}/${state.jobs.length} completada.`});
    if (nextIndex < state.jobs.length) await navigateBatchCurrent(false);
  } catch (error) {
    const state = await getState();
    const index = Number(state && state.currentIndex) || 0;
    const job = state && state.jobs && state.jobs[index];
    if (job) await report({...job,mode:'batch'},'error',error.message || String(error));
    await setState({phase:'error',message:`Error en URL ${index + 1}: ${error.message || error}`,error:error.message || String(error)});
  } finally { batchProcessing = false; }
}

chrome.runtime.onMessage.addListener((message,sender,sendResponse) => {
  (async () => {
    if (message.type === 'get-state') return {ok:true,state:await getState()};
    if (message.type === 'save-pair') {
      const job=message.job;
      const state={mode:'single',apiUrl:message.apiUrl,jobId:job.job_id,token:job.token,tokenExpiresAt:job.token_expires_at,targetUrl:job.target_url,category:job.category,maxItems:job.max_items,phase:'paired',message:'Trabajo vinculado.',error:''};
      await chrome.storage.local.set({[STATE_KEY]:state});
      return {ok:true,state};
    }
    if (message.type === 'start-batch') return await startBatch(message,sender);
    if (message.type === 'open-rexel') return await openRexel();
    if (message.type === 'extract') return await extractAndSubmit();
    if (message.type === 'clear-job') { await chrome.storage.local.remove(STATE_KEY); return {ok:true}; }
    if (message.type === 'rexel-page-ready') {
      const state=await getState();
      if (state && sender.tab) {
        await setState({tabId:sender.tab.id});
        if (state.mode === 'batch' && state.phase === 'navigating') processBatchPage(sender.tab.id);
        else if (state.mode !== 'batch') await setState({message:'Rexel cargado. Pulsa Continuar / extraer.'});
      }
      return {ok:true};
    }
    return {ok:false,error:'Mensaje desconocido'};
  })().then(sendResponse).catch(async error => {
    const state=await getState();
    if (state && state.mode !== 'batch' && state.token) await report(state,'error',error.message || String(error));
    sendResponse({ok:false,error:error.message || String(error)});
  });
  return true;
});
