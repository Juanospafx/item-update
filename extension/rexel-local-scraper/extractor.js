(function (root) {
  'use strict';

  const compact = value => String(value || '').replace(/\s+/g, ' ').trim();
  const uniqueProductLinks = node => new Set(
    Array.from(node.querySelectorAll ? node.querySelectorAll("a[href*='/p/']") : [])
      .map(link => link.getAttribute('href')).filter(Boolean)
  ).size;

  function detectGate(doc, locationLike) {
    const text = compact(doc.body && doc.body.innerText).slice(0, 200000);
    const title = compact(doc.title);
    const host = String(locationLike && locationLike.hostname || '').toLowerCase();
    const path = String(locationLike && locationLike.pathname || '').toLowerCase();
    const priceLogin = /sign in(?: or register)? to view pric(?:e|ing)|log in to (?:see|view) pric(?:e|ing)/i.test(text);
    const productCards = doc.querySelectorAll ? Array.from(doc.querySelectorAll('.search-product, .rex-product-tile')) : [];
    const productPricingLogin = productCards.some(card => /\bSign In or Register\b/i.test(compact(card.innerText || card.textContent)));
    const loginForm = Boolean(doc.querySelector && doc.querySelector(
      "form[action*='login' i], input[type='password'], input[name*='password' i], button[type='submit'][data-testid*='login' i]"
    ));
    const onAuthPage = host === 'auth.rexelusa.com' || /\/(?:login|signin)(?:\/|$)/.test(path);
    const challengeText = /verify you are human|checking your browser|attention required|just a moment|security verification/i.test(title + ' ' + text.slice(0, 5000));
    const challengeWidget = Boolean(doc.querySelector && doc.querySelector("iframe[src*='captcha' i], iframe[src*='challenge' i], [class*='captcha' i]"));

    if (challengeText || challengeWidget) {
      return {blocked: true, reason: 'verification', message: 'Resuelve manualmente la verificacion o CAPTCHA en la pestaña de Rexel.'};
    }
    if (priceLogin || productPricingLogin || (onAuthPage && loginForm)) {
      return {blocked: true, reason: 'login', message: 'Rexel todavía muestra “Sign In or Register” en los productos. Confirma la sesión y que la cuenta/ubicación tenga precios, recarga la página y vuelve a extraer.'};
    }
    return {blocked: false};
  }

  function findCard(link) {
    const explicit = link.closest && link.closest(".search-product, div.rex-product-tile, [data-cy='product-tile'], [data-testid*='product-card' i], article");
    if (explicit && uniqueProductLinks(explicit) <= 1) return explicit;
    let node = link;
    let best = link;
    for (let depth = 0; depth < 7 && node && node.parentElement; depth += 1) {
      node = node.parentElement;
      if (/^(MAIN|BODY)$/.test(node.tagName || '')) break;
      const count = uniqueProductLinks(node);
      if (count > 1) break;
      if (count === 1) best = node;
      const nodeText = compact(node.innerText);
      if (count === 1 && (/your\s*price|net\s*price|\$\s*[\d,.]+/i.test(nodeText) || node.querySelector("[data-cy='product-price']"))) {
        return node;
      }
    }
    return best;
  }

  function normalizedPriceText(value) {
    return compact(value)
      .replace(/\$\s+/g, '$')
      .replace(/(\d)\s*\.\s*(\d)/g, '$1.$2')
      .replace(/(\d)\s*,\s*(\d)/g, '$1,$2');
  }

  function moneyFromText(value) {
    const text = normalizedPriceText(value);
    const match = text.match(/(?:USD\s*)?\$\s*([\d,]+(?:\.\d{1,4})?)/i);
    if (!match) return null;
    const number = Number(match[1].replace(/,/g, ''));
    return Number.isFinite(number) && number > 0 ? number : null;
  }

  function priceFromAttributes(element) {
    if (!element || !element.getAttribute) return null;
    for (const attribute of ['content', 'data-price', 'data-unit-price', 'value', 'aria-label', 'title']) {
      const raw = element.getAttribute(attribute);
      if (!raw) continue;
      const direct = Number(String(raw).replace(/[$,\s]/g, ''));
      if (Number.isFinite(direct) && direct > 0) return direct;
      const parsed = moneyFromText(raw);
      if (parsed !== null) return parsed;
    }
    return null;
  }

  function extractPrice(card) {
    const text = normalizedPriceText(card.innerText || card.textContent);
    const preferredLabel = text.match(/Your\s*Price|Net\s*Price|Tu\s*Precio/i);
    if (preferredLabel) {
      const afterLabel = text.slice(preferredLabel.index, preferredLabel.index + 180);
      const preferredPrice = moneyFromText(afterLabel);
      if (preferredPrice !== null) return {price: preferredPrice, price_label: preferredLabel[0]};
    }

    const selectors = [
      "[itemprop='price']", "meta[itemprop='price']", "[data-price]", "[data-unit-price]",
      "[data-cy*='price' i]", "[data-qa*='price' i]", "[data-testid*='price' i]",
      "[class*='product-price' i]", "[class~='price']", "[class*='price-' i]", "[class*='-price' i]"
    ];
    const priceElements = card.querySelectorAll ? Array.from(card.querySelectorAll(selectors.join(','))) : [];
    const preferredElements = priceElements.filter(element => /Your\s*Price|Net\s*Price|Tu\s*Precio/i.test(compact((element.parentElement && element.parentElement.innerText) || element.innerText || element.textContent)));
    for (const element of [...preferredElements, ...priceElements]) {
      const attributePrice = priceFromAttributes(element);
      if (attributePrice !== null) return {price: attributePrice, price_label: preferredElements.includes(element) ? 'Your Price' : 'Displayed price'};
      const elementPrice = moneyFromText(element.innerText || element.textContent);
      if (elementPrice !== null) return {price: elementPrice, price_label: preferredElements.includes(element) ? 'Your Price' : 'Displayed price'};
      const parentPrice = moneyFromText(element.parentElement && (element.parentElement.innerText || element.parentElement.textContent));
      if (parentPrice !== null) return {price: parentPrice, price_label: preferredElements.includes(element) ? 'Your Price' : 'Displayed price'};
    }

    const cardPrice = moneyFromText(text);
    if (cardPrice !== null) return {price: cardPrice, price_label: preferredLabel ? preferredLabel[0] : 'Displayed price'};
    return {price: null, price_label: ''};
  }

  function extractUnit(card, price) {
    const text = compact(card.innerText || card.textContent);
    if (price !== null) {
      const escaped = String(price).replace('.', '\\.');
      const near = text.match(new RegExp('\\$\\s*[\\d,.]+\\s*(?:/|per)\\s*(\\d*\\s*(?:EA|EACH|FT|FOOT|FEET|M|PC|PIECE|PK|PACK|BOX|ROLL|C|100\\s*FT))\\b', 'i'));
      if (near) return compact(near[1]);
      void escaped;
    }
    const labelled = text.match(/(?:UOM|Unit(?: of sale)?|Sold by)\s*:?\s*([A-Za-z0-9 ]{1,24})/i);
    return labelled ? compact(labelled[1]) : '';
  }

  function selectorText(card, selectors) {
    for (const selector of selectors) {
      const element = card.querySelector && card.querySelector(selector);
      const value = compact(element && (element.innerText || element.textContent));
      if (value) return value.replace(/^(?:SKU|Mfr\.? Part|Catalog(?: No\.?| Number)?|Item)\s*:?\s*/i, '');
    }
    return '';
  }

  function extractIdentifiers(card) {
    const text = compact(card.innerText || card.textContent);
    const sku = selectorText(card, ["[data-cy*='sku' i]", "[data-testid*='sku' i]", "[class*='sku' i]"])
      || compact((text.match(/(?:SKU|Item)\s*#?\s*:?\s*([A-Z0-9._/-]{2,40})/i) || [])[1]);
    const reference = selectorText(card, ["[data-cy*='mfr' i]", "[data-testid*='mfr' i]", "[class*='mfr' i]"])
      || compact((text.match(/(?:Mfr\.?\s*(?:Part|No\.?|#)|Catalog\s*(?:No\.?|#))\s*:?\s*([A-Z0-9._/-]{2,60})/i) || [])[1]);
    return {sku, reference};
  }

  function extract(doc, locationLike, maxItems) {
    const gate = detectGate(doc, locationLike);
    if (gate.blocked) return {status: 'awaiting_login', gate, items: []};
    const links = Array.from(doc.querySelectorAll("a[href*='/p/']"));
    const seen = new Set();
    const items = [];
    for (const link of links) {
      if (items.length >= maxItems) break;
      const rawHref = link.getAttribute('href');
      if (!rawHref) continue;
      let productUrl;
      try { productUrl = new URL(rawHref, locationLike.href).href; } catch (_) { continue; }
      if (seen.has(productUrl)) continue;
      const card = findCard(link);
      const nameNode = link.querySelector("h1, h2, h3, [data-cy='product-name'], [data-qa*='product-name' i], [class*='product-name' i], .text-h6, .text-subtitle-1") || link;
      const name = compact(nameNode.innerText || nameNode.textContent);
      if (!name) continue;
      seen.add(productUrl);
      const priceData = extractPrice(card);
      const ids = extractIdentifiers(card);
      items.push({
        name,
        sku: ids.sku,
        reference: ids.reference,
        product_url: productUrl,
        price: Number.isFinite(priceData.price) && priceData.price > 0 ? priceData.price : null,
        currency: priceData.price ? 'USD' : '',
        unit: extractUnit(card, priceData.price),
        price_label: priceData.price_label
      });
    }
    return {
      status: 'ok',
      items,
      warning: items.length && items.every(item => item.price === null)
        ? 'Se encontraron productos, pero ningun precio dentro de sus contenedores. No se asumio que la sesion estuviera cerrada.'
        : ''
    };
  }

  root.RexelExtractor = {compact, detectGate, extract};
  if (typeof module !== 'undefined' && module.exports) module.exports = root.RexelExtractor;
})(typeof globalThis !== 'undefined' ? globalThis : this);
