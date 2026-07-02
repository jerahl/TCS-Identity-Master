/*
 * Person detail — live Active Directory panel loader.
 *
 * The AD comparison is a live REST call to Adaxes that can be slow, so the server
 * renders the detail page immediately with a loading indicator (#adaxes-live) and
 * we fetch the rendered panel fragment from GET /people/{id}/adaxes here, swapping
 * it in when it arrives. Read-only: this only GETs the comparison, never mutates.
 *
 * No inline script — loaded as an external file to satisfy the strict CSP
 * (script-src 'self'); all handlers are attached via addEventListener.
 */
(function () {
  'use strict';

  function load(el) {
    var url = el.getAttribute('data-adaxes-url');
    if (!url) {
      return;
    }

    el.classList.add('is-loading');
    el.innerHTML =
      '<div class="adaxes-loading identity-note" style="margin-bottom:0;">' +
      '<span class="spinner" aria-hidden="true"></span>' +
      '<span>Checking Active Directory…</span></div>';

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
        el.innerHTML =
          '<div class="identity-note" style="margin-bottom:0; color:#B42318;">' +
          'Could not load the Active Directory check. ' +
          '<button type="button" class="adaxes-retry">Retry</button></div>';
        var retry = el.querySelector('.adaxes-retry');
        if (retry) {
          retry.addEventListener('click', function () {
            load(el);
          });
        }
      });
  }

  function init() {
    var el = document.getElementById('adaxes-live');
    if (el) {
      load(el);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
