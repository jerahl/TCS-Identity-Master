/*
 * Clickable table rows.
 *
 * Rows marked `<tr class="is-clickable" data-href="…">` navigate to their href
 * when clicked anywhere in the row. Replaces inline `onclick="window.location=…"`
 * handlers, which the strict CSP (script-src 'self', no 'unsafe-inline') blocks —
 * so the whole-row click silently did nothing and only the name link worked.
 *
 * Delegated from the document so it covers every current and future row with a
 * single listener. Real interactive elements (links, buttons, form controls) keep
 * their own behavior, an in-progress text selection is never hijacked, and
 * ctrl/cmd/middle-click opens the record in a new tab like a normal link.
 */
(function () {
  'use strict';

  function navigate(e) {
    var row = e.target.closest ? e.target.closest('tr.is-clickable[data-href]') : null;
    if (!row) {
      return;
    }
    // Let genuine interactive elements inside the row behave normally.
    if (e.target.closest('a, button, input, select, textarea, label')) {
      return;
    }
    // Don't navigate away mid-selection (user is highlighting text in the row).
    var sel = window.getSelection && window.getSelection();
    if (sel && String(sel).length > 0) {
      return;
    }
    var href = row.getAttribute('data-href');
    if (!href) {
      return;
    }
    if (e.metaKey || e.ctrlKey || e.button === 1) {
      window.open(href, '_blank', 'noopener');
    } else {
      window.location.assign(href);
    }
  }

  document.addEventListener('click', navigate);
  // Middle-click → open in a new tab.
  document.addEventListener('auxclick', function (e) {
    if (e.button === 1) {
      navigate(e);
    }
  });
})();
