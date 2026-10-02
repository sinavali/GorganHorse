# Vendored webfonts

| File | Family | Subset | Licence |
|---|---|---|---|
| `vazirmatn-arabic-var.woff2` | Vazirmatn (variable, weight 100–900) | Arabic | SIL OFL 1.1 — `LICENSE.txt` |
| `vazirmatn-latin-var.woff2` | Vazirmatn (variable, weight 100–900) | Latin | SIL OFL 1.1 — `LICENSE.txt` |

Vazirmatn — © 2015 The Vazirmatn Project Authors
(<https://github.com/rastikerdar/vazirmatn>), SIL Open Font License 1.1.

Served from the panel itself rather than a CDN: the project has no build step
and must work on hosts with no outbound network access, and a public page that
silently fell back to Tahoma on an offline machine would look broken.

The two subsets are ~80 KB together and are declared with `font-display:swap`,
so text renders immediately in the fallback stack and swaps in when the file
arrives. The Arabic subset is preloaded by `public/views/index.html`; the Latin
subset is fetched on demand by the `unicode-range` on its `@font-face`.

To update: download a newer variable build of the same subsets and replace the
two `.woff2` files. No other file references the version.