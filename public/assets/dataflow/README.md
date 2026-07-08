# /reference/data-flow assets

Static assets for the interactive data-flow chart served (behind the auth
gate) at `/reference/data-flow` — the page document itself lives at
`templates/reference/dataflow.html` so it is never reachable unauthenticated;
only these supporting files are web-root static.

Everything is self-hosted — the page makes **no external requests**:

- `dc-runtime.js` — the chart's template/logic runtime (generated build,
  shipped with the design deliverable; do not hand-edit). It evaluates the
  chart definition with `new Function`, which is why the route sends a CSP
  with `script-src 'unsafe-eval'` (that page only — see
  `ReferenceController::dataflow()`).
- `react.production.min.js` / `react-dom.production.min.js` — React 18.3.1
  UMD builds, vendored from the npm registry tarballs and verified against
  the subresource-integrity hashes the runtime pins for its CDN fallback
  (`sha384-DGyLxAyjq0f9…` / `sha384-gTGxhz21lVGY…`). Loading them locally
  *before* `dc-runtime.js` means the runtime's unpkg.com fallback never runs.
- `fonts/*.woff2` — IBM Plex Sans / Plex Mono subsets used by the chart
  (named by the first segment of their original bundle ids).

To update the chart, export a new standalone bundle from the design tool,
unpack it the same way (assets here, rewritten page to
`templates/reference/dataflow.html` with local asset paths), and keep the
React files whenever the runtime still pins 18.3.1.
