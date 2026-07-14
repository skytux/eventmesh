# EventMesh

[![CI](https://github.com/skytux/eventmesh/actions/workflows/ci.yml/badge.svg)](https://github.com/skytux/eventmesh/actions/workflows/ci.yml)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](LICENSE)
![WordPress 6.8+](https://img.shields.io/badge/WordPress-6.8%2B-21759b.svg)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg)

Synchronize external event sources into native WordPress content, with editable Gutenberg blocks for event lists, dates, venues, and tickets.

EventMesh pulls event listings from external sources into real WordPress posts (a dedicated **Events** post type), so your events live in your own database, work with any theme, and stay fully editable.

## Install

1. Download **`eventmesh.zip`** from the [latest release](https://github.com/skytux/eventmesh/releases/latest).
2. In wp-admin, go to **Plugins → Add New → Upload Plugin**, choose the zip, and activate.
3. Go to **EventMesh → Sources** and add one or more Holvi shop URLs.
4. Click **Sync now** on the EventMesh dashboard, or wait for the background sync.
5. Add the **EventMesh event list** block (or the Query Loop pattern) to any page.

Requires WordPress 6.8+ and PHP 8.2+.

## Features

- **Holvi connector** — point EventMesh at one or more Holvi shop URLs; titles, dates, venues, images, sold-out state, prices, and ticket links are imported.
- **Background sync** — scheduled imports (15 minutes to daily) with a visitor-triggered, loopback-free fallback when WP-Cron is unreliable, a sync-health panel under Diagnostics, and a status dashboard with an AJAX "Sync now".
- **Editable overrides** — every field and provider link, plus the title, description, and featured image, is editable per event and kept on the next sync. A per-field **Follow source again** returns to the source whenever you want it.
- **Per-event visibility** — **Hide** drops an event from listings, **Disable** also 404s its page, and **Keep published if removed from the source** pins an event so it survives after the source delists it.
- **Provider links & embeds** — provider links (Spotify, YouTube, Mixcloud, Bandcamp, SoundCloud, Instagram, Facebook) found on an event page are attached and rendered as embeds.
- **Reversible source management** — disabling a source archives its events (Draft) on the next sync; re-enabling republishes them. Uninstalling keeps your data unless you opt in to deletion.
- **Connector-agnostic** — new sources can be added as self-contained connectors without touching the core plugin.

## Blocks

An event list block, plus Query Loop-ready blocks for the event date, title, venue, ticket button (with price and optional prefix/suffix), provider embeds, **all** and **other** provider links, and a "Past Events" divider. All blocks support the standard color, typography, border, and spacing controls.

## Screenshots

| | |
|---|---|
| ![Dashboard](.wordpress-org/screenshot-1.png) | ![Sources](.wordpress-org/screenshot-2.png) |
| The dashboard with sync status and controls | Sources page with per-connector enablement |
| ![Event list block](.wordpress-org/screenshot-3.png) | ![Diagnostics](.wordpress-org/screenshot-5.png) |
| The event list block in the editor | Diagnostics with the background-sync health panel |

## Development

```sh
composer install
vendor/bin/phpunit                 # test suite
vendor/bin/phpcs src               # WordPress Coding Standards
pwsh scripts/build-zip.ps1         # build a distributable eventmesh.zip
```

CI (GitHub Actions) runs PHPUnit and PHPCS on every push and pull request.

## License

[GPL-2.0-only](LICENSE).
