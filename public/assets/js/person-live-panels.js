/*
 * Person detail — live panel loaders (Active Directory, Google Workspace).
 *
 * Each of these panels runs a live remote lookup — Adaxes REST, or GAM / the
 * Google Directory API — that can be slow, so the server renders the detail page
 * immediately with a loading indicator and we fetch the rendered panel fragment
 * over AJAX here, swapping it in when it arrives. Read-only: this only GETs the
 * comparison, never mutates.
 *
 * Any element with data-live-url is loaded; data-live-label sets the wait text
 * and data-live-error the failure text. No inline script — loaded as an external
 * file to satisfy the strict CSP (script-src 'self'); handlers via addEventListener.
 */
(function () {
  'use strict';

  function loading(el, label) {
    var wrap = document.createElement('div');
    wrap.className = 'live-panel-loading identity-note';
    wrap.style.marginBottom = '0';
    var sp = document.createElement('span');
    sp.className = 'spinner';
    sp.setAttribute('aria-hidden', 'true');
    var txt = document.createElement('span');
    txt.textContent = label;
    wrap.appendChild(sp);
    wrap.appendChild(txt);
    el.innerHTML = '';
    el.appendChild(wrap);
  }

  function failure(el, message) {
    var box = document.createElement('div');
    box.className = 'identity-note';
    box.style.marginBottom = '0';
    box.style.color = '#B42318';
    box.appendChild(document.createTextNode(message + ' '));
    var retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'live-panel-retry';
    retry.textContent = 'Retry';
    retry.addEventListener('click', function () { load(el); });
    box.appendChild(retry);
    el.innerHTML = '';
    el.appendChild(box);
  }

  function load(el) {
    var url = el.getAttribute('data-live-url');
    if (!url) {
      return;
    }
    var label = el.getAttribute('data-live-label') || 'Loading…';
    var errText = el.getAttribute('data-live-error') || 'Could not load this panel.';

    el.classList.add('is-loading');
    loading(el, label);

    fetch(url, {
      headers: { 'X-Requested-With': 'fetch' },
      credentials: 'same-origin'
    })
      .then(function (res) {
        if (!res.ok) {
          throw new Error('HTTP ' + res.status);
        }
        return res.text();
      })
      .then(function (html) {
        el.classList.remove('is-loading');
        el.innerHTML = html;
      })
      .catch(function () {
        el.classList.remove('is-loading');
        failure(el, errText);
      });
  }

  function init() {
    var els = document.querySelectorAll('[data-live-url]');
    for (var i = 0; i < els.length; i++) {
      load(els[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
