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

