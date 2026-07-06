/*
 * Print trigger for the orientation-checklist document.
 *
 * The "Print / Save as PDF" button opens the browser's print dialog. An inline
 * onclick would be blocked by the strict CSP (script-src 'self', no
 * 'unsafe-inline'), so the handler is wired here from a self-hosted file instead.
 */
(function () {
  'use strict';
  var btn = document.getElementById('print-btn');
  if (btn) {
    btn.addEventListener('click', function () {
      window.print();
    });
  }
})();
