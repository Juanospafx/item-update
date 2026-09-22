'use strict';

window.addEventListener('message', event => {
  if (event.source !== window || event.origin !== 'https://item-update.brightronix.net') return;
  const data = event.data;
  if (!data || data.source !== 'rexel-panel') return;
  if (data.type === 'PING_REXEL_EXTENSION') {
    window.postMessage({source:'rexel-extension', type:'REXEL_EXTENSION_READY', version:'0.3.1'}, event.origin);
    return;
  }
  if (data.type !== 'START_REXEL_BATCH') return;
  let api;
  try { api = new URL(data.apiUrl); } catch (_) { return; }
  if (api.origin !== 'https://item-update.brightronix.net' || !api.pathname.endsWith('/rexel_extension_api.php')) return;
  if (!Array.isArray(data.jobs) || !data.jobs.length || data.jobs.length > 60) return;
  chrome.runtime.sendMessage({type:'start-batch', apiUrl:data.apiUrl, batchId:data.batchId, jobs:data.jobs})
    .then(result => window.postMessage({source:'rexel-extension', type:'REXEL_BATCH_ACK', batchId:data.batchId, result}, event.origin))
    .catch(error => window.postMessage({source:'rexel-extension', type:'REXEL_BATCH_ACK', batchId:data.batchId, result:{ok:false,error:error.message}}, event.origin));
});

window.postMessage({source:'rexel-extension', type:'REXEL_EXTENSION_READY', version:'0.3.1'}, location.origin);
