# Vendored third-party front-end assets

This project has **no build step, no npm and no bundler** (see the root
`README.md`): libraries are vendored as plain files, the same way PHP
libraries are vendored under `vendor/`. Files here are loaded by the browser
directly from `/views/assets/vendor/`.

## `quill.js` — Quill Editor 2.0.3

- Source: <https://github.com/slab/quill>, `dist/quill.js` (UMD, minified).
- Licence: BSD-3-Clause — see `quill.js.LICENSE.txt`.
- Loaded **lazily** by `ui.js` (`ensureQuill()`) the first time a rich-text
  field is initialised, so pages with no editor never download it.
- Used by `UI.editor()` for the competition description / rules / announcement
  fields, which non-technical staff edit as rich content with drag-and-drop
  images.
- The editor's CSS is **not** vendored: Quill's bundled `quill.snow.css` is
  opinionated and not RTL-tuned for this panel, so a small RTL theme lives in
  `../css/app.css` under `.ql-` selectors instead.

To upgrade: replace `quill.js` with a newer UMD build, re-run
`node --check public/views/assets/vendor/quill.js`, and re-read Quill's
release notes for toolbar/handler API changes.