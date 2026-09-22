'use strict';
const assert = require('node:assert/strict');
const extractor = require('../extension/rexel-local-scraper/extractor.js');
const location = {href:'https://auth.rexelusa.com/login', hostname:'auth.rexelusa.com', pathname:'/login'};
const doc = (text, hasLoginForm=false, title='Rexel') => ({
  title,
  body:{innerText:text},
  querySelector:selector => hasLoginForm && selector.includes('password') ? {} : null
});

assert.equal(extractor.detectGate(doc('Welcome $10.00'), location).blocked, false, 'URL o dolar solos no prueban estado de sesion');
assert.equal(extractor.detectGate(doc('Welcome', {}), location).reason, 'login', 'pagina auth mas formulario de login');
assert.equal(extractor.detectGate(doc('Sign In to view pricing'), {href:'https://www.rexelusa.com/s/x',hostname:'www.rexelusa.com',pathname:'/s/x'}).reason, 'login');
assert.equal(extractor.detectGate(doc('Verify you are human'), location).reason, 'verification');
console.log('OK: deteccion conservadora de login y verificacion');
