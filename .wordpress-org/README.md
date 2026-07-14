# WordPress.org page assets

Images for the plugin's public listing (WordPress.org plugin directory and the
GitHub README). **These are deliberately kept out of the distributed plugin** —
`scripts/build-zip.ps1` only packages the runtime folders, so nothing here bloats
`eventmesh.zip`. When the plugin is submitted to WordPress.org, these files map to
the SVN `/assets/` directory (which wp.org serves on the plugin page but never
ships to sites).

## Screenshots

Drop PNG or JPG files here named `screenshot-1` … `screenshot-N`, in the same
order as the `== Screenshots ==` captions in `readme.txt`:

1. `screenshot-1.png` — The EventMesh dashboard with sync status and controls.
2. `screenshot-2.png` — Sources page with per-connector enablement and Holvi shop URLs.
3. `screenshot-3.png` — The event list block in the editor.
4. `screenshot-4.png` — Settings: sync interval and data-retention options.
5. `screenshot-5.png` — Diagnostics with the background sync health panel.

Guidance: PNG for UI screenshots, roughly 1280px wide, under ~1 MB each. The
number and order must match the `readme.txt` captions — wp.org pairs them by index.

## Icon and banner (optional but recommended)

- `icon-256x256.png` (and optionally `icon-128x128.png`) — the square icon shown
  next to the plugin name.
- `banner-772x250.png` (and optionally `banner-1544x500.png` for hi-DPI) — the
  header banner on the plugin page.
