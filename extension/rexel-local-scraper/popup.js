'use strict';

const $ = id => document.getElementById(id);
const send = message => chrome.runtime.sendMessage(message);
function showError(message='') { $('error').hidden=!message; $('error').textContent=message; }
function originPattern(apiUrl) { const url=new URL(apiUrl); return `${url.protocol}//${url.host}/*`; }
function render(state) {
  $('pair-section').hidden=Boolean(state);
  $('job-section').hidden=!state;
  if (!state) return;
  $('job-info').textContent=`${state.category} · máximo ${state.maxItems} productos`;
  $('status').textContent=state.message || state.phase || 'Vinculado';
}
async function restore() { const result=await send({type:'get-state'}); render(result.state); }
$('pair').addEventListener('click', async () => {
  showError();
  try {
    const apiUrl=$('api-url').value.trim();
    const code=$('pair-code').value.toUpperCase().replace(/[^A-Z0-9]/g,'');
    const parsedApi=new URL(apiUrl);
    if (/^(?:www\.)?rexelusa\.com$/i.test(parsedApi.hostname) || !parsedApi.pathname.endsWith('/rexel_extension_api.php')) {
      throw new Error('La primera casilla debe contener la URL local del API terminada en rexel_extension_api.php, no una URL de Rexel.');
    }
    if (code.length!==8) throw new Error('El código temporal debe tener 8 caracteres.');
    const pattern=originPattern(apiUrl);
    const granted=await chrome.permissions.request({origins:[pattern]});
    if (!granted) throw new Error('Debes autorizar el origen del panel para vincular el trabajo.');
    const response=await fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'pair',code})});
    const data=await response.json().catch(()=>({ok:false,error:'El servidor no devolvió JSON. Verifica que pegaste la URL de rexel_extension_api.php.'}));
    if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
    const saved=await send({type:'save-pair',apiUrl,job:data.job});
    render(saved.state);
  } catch(error) { showError(error.message || String(error)); }
});
$('open').addEventListener('click',async()=>{showError();const r=await send({type:'open-rexel'});if(!r.ok)showError(r.error);else restore();});
$('extract').addEventListener('click',async()=>{showError();$('status').textContent='Extrayendo…';const r=await send({type:'extract'});if(!r.ok)showError(r.error);await restore();});
$('clear').addEventListener('click',async()=>{await send({type:'clear-job'});render(null);});
restore().catch(error=>showError(error.message));
