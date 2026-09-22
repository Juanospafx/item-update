'use strict';

const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

let stored = {};
let nextTabId = 1;
let activeTab = null;
let submitted = 0;

const jobsByCode = {
  CODE0001: {job_id:'job-1', token:'token-1', token_expires_at:'2099-01-01', target_url:'https://www.rexelusa.com/s/a', category:'A', max_items:2},
  CODE0002: {job_id:'job-2', token:'token-2', token_expires_at:'2099-01-01', target_url:'https://www.rexelusa.com/s/b', category:'B', max_items:2},
};

const context = {
  URL,
  console,
  setTimeout: callback => { callback(); return 1; },
  fetch: async (_url, options) => {
    const request = JSON.parse(options.body);
    if (request.action === 'pair') return response({ok:true, job:jobsByCode[request.code]});
    if (request.action === 'submit_results') { submitted += 1; return response({ok:true, duplicate:false}); }
    return response({ok:true});
  },
  chrome: {
    storage: {local: {
      get: async key => ({[key]: stored[key]}),
      set: async values => { stored = {...stored, ...values}; },
      remove: async key => { delete stored[key]; },
    }},
    tabs: {
      create: async ({url}) => (activeTab = {id:nextTabId++, url}),
      update: async (id, {url}) => (activeTab = {id, url}),
      get: async id => {
        if (!activeTab || activeTab.id !== id) throw new Error('missing tab');
        return activeTab;
      },
      query: async () => activeTab ? [activeTab] : [],
      sendMessage: async () => ({status:'ok', source_url:activeTab.url, items:[{name:'Fixture', price:1.25}]}),
    },
    runtime: {onMessage:{addListener: listener => { context.listener = listener; }}},
  },
};

function response(body) {
  return {ok:true, status:200, json:async () => body};
}

const source = fs.readFileSync('extension/rexel-local-scraper/service-worker.js', 'utf8');
vm.runInNewContext(`${source}\nglobalThis.batchTestApi={startBatch,pauseBatch,resumeBatch,processBatchPage,getState};`, context);

(async () => {
  const result = await context.batchTestApi.startBatch({
    apiUrl:'https://item-update.brightronix.net/web/rexel_extension_api.php',
    batchId:'batch-1',
    jobs:[{pair_code:'CODE0001'},{pair_code:'CODE0002'}],
  }, {url:'https://item-update.brightronix.net/web/rexel_extension.php'});
  assert.strictEqual(result.total, 2);
  let state = await context.batchTestApi.getState();
  assert.strictEqual(state.phase, 'navigating');
  assert.strictEqual(state.currentIndex, 0);

  await context.batchTestApi.pauseBatch();
  state = await context.batchTestApi.getState();
  assert.strictEqual(state.phase, 'paused');
  assert.strictEqual(state.pauseRequested, true);
  await context.batchTestApi.processBatchPage(activeTab.id);
  assert.strictEqual((await context.batchTestApi.getState()).currentIndex, 0);
  await context.batchTestApi.resumeBatch();
  assert.strictEqual((await context.batchTestApi.getState()).phase, 'navigating');

  await context.batchTestApi.processBatchPage(activeTab.id);
  state = await context.batchTestApi.getState();
  assert.strictEqual(state.currentIndex, 1);
  assert.strictEqual(state.phase, 'navigating');

  await context.batchTestApi.processBatchPage(activeTab.id);
  state = await context.batchTestApi.getState();
  assert.strictEqual(state.currentIndex, 2);
  assert.strictEqual(state.phase, 'completed');
  assert.strictEqual(submitted, 2);
  console.log('OK: vinculacion temporal, pausa, una pestaña y avance automatico de lote');
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
