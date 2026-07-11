/*
 * Person-page identity actions (rename / unlink).
 *
 * Each action is a form marked `data-idm-prompt` that collects one value (the
 * rename's previous name, or the unlink's reason) through a pop-up prompt instead
 * of an always-visible text field. The prompt doubles as the confirm step —
 * Cancel aborts, OK submits — so the row stays a single button.
 *
 * Delegated from the document (one listener, covers current + future forms) and
 * lives in an external file because the strict CSP (script-src 'self', no
 * 'unsafe-inline') blocks inline on* handlers — the same reason row-click.js
 * exists.
 *
 * Form contract:
 *   data-idm-prompt            marks the form
 *   data-prompt="…"            the pop-up message (also the warning text)
 *   data-prompt-field="reason" name of the hidden input to fill with the answer
 *   data-prompt-required       (optional) "1" = a blank answer aborts
 */
(function () {
  'use strict';

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.matches || !form.matches('form[data-idm-prompt]')) {
      return;
    }
    // A programmatic resubmit (below) is already vetted — let it through.
    if (form.dataset.idmVetted === '1') {
      return;
    }

    e.preventDefault();

    var message = form.getAttribute('data-prompt') || 'Continue?';
    var fieldName = form.getAttribute('data-prompt-field') || '';
    var required = form.getAttribute('data-prompt-required') === '1';

    var answer = window.prompt(message, '');
    if (answer === null) {
      return; // Cancel → abort, nothing submitted.
    }
    answer = answer.trim();
    if (required && answer === '') {
      return; // Required but empty → abort.
    }

    if (fieldName) {
      var field = form.querySelector('input[name="' + fieldName + '"]');
      if (field) {
        field.value = answer;
      }
    }

    form.dataset.idmVetted = '1';
    form.submit();
  });
})();
