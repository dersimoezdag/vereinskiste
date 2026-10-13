# Material Symbols Rounded

Official Google Material Symbols, locally bundled as a small SVG sprite.

Source: https://github.com/google/material-design-icons
Pinned revision: `49d4db35df873165d6bd6ba09b063c7dafbac2f4`.
Variant: Rounded, 24 px, default weight and unfilled style.
License: Apache-2.0; the original license is included in `LICENSE`.

`symbols.svg` contains only the icons used by the application. Symbol IDs are the
application's semantic names, mapped to upstream names in
`scripts/sync-material-symbols.ps1`. The sprite wraps the original path geometry;
no paths are drawn or manually changed. It is served from the application's own
origin, with no Google Fonts/CDN request or icon font required.

To reproduce/update assets during development, run the PowerShell synchronization
script. The hosting environment only needs the resulting SVG file and license.
