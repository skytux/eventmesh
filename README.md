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

## How the Holvi parser works

For each configured shop URL, EventMesh fetches the **listing page**, parses it, then fetches each event's own **detail page** and merges the richer data over the listing (the detail page usually carries the full description and is often the only reliable source of the venue and date).

### Decoding, in priority order

1. **JSON-LD** (`<script type="application/ld+json">`) is preferred. EventMesh reads a `@graph` array, a top-level array, or a single object, and accepts items whose `@type` is `Event` or `Product` — Holvi tags each listing as a schema.org `Product` (with the date in its `name` and an `offers` block), not an `Event`.
2. **HTML markup** is the fallback, used only when no JSON-LD events are found. It scans elements whose class contains `event`, `product`, or `store-item`, or that carry `data-event-id` or `itemtype=".../schema.org/Product"`, and reads fields from `itemprop=` attributes, Holvi's own class names (`store-item-name`, `product-price`, …), `<time datetime>`, headings, and links.

Both paths produce the same event fields.

### Fields it populates

| Field | From JSON-LD | From HTML markup |
|---|---|---|
| **Title** | `name` | `itemprop="name"`, `.store-item-name`, or first `<h1>`–`<h4>` |
| **Description** | `description` | `itemprop="description"` / `.store-item-description` (detail page keeps inner HTML) |
| **URL** | `url` | first `<a href>` (resolved to absolute) |
| **Start / end date** | `startDate` / `endDate` | `<time datetime>`, else `<time>` text |
| **Start / end time** | from description text (see below) | same |
| **Venue** | `location` name | `itemprop="location"`, else a `Venue:` / `Location:` line in the description |
| **Price** | `offers.price` (or `lowPrice`) + `priceCurrency` → `€`/`$`/`£` symbol, trailing `.00` trimmed | `.product-price` / `.store-item-price` / `itemprop="price"`, taken verbatim |
| **Sold out** | `offers.availability` contains `SoldOut` | an element class contains `sold-out`, or the stock element reads "Sold out" |
| **Image** | `image` | an `<image-carousel images="[…JSON…]">` attribute (HTML-entity-decoded JSON), `itemprop="image"`, `<img src>`, or a CSS `background: url(…)` |
| **Provider links** | `sameAs` URLs | known-provider `<a href>` links plus bare URLs written into the description text |

Provider links are matched by host against the known set — Spotify, YouTube, Mixcloud, Bandcamp, SoundCloud, Instagram, Facebook.

### Dates and times

A structured `<time datetime="…">` or JSON-LD `startDate`/`endDate` is used directly (any ISO date/time). When there is no structured date, EventMesh reads a Finnish/European **dotted** date out of the title or description text: `12.8.2026`, `12.8.` (no year), or a `27.6-28.6.2026` range. With no year it resolves to the **next upcoming** occurrence — never a past date. Times come from `klo 18:00-21:00` (`klo` is Finnish for "at") or a bare `18:00-21:00`; a date with no time stays at midnight. The date is kept in the stored title (so events stay identifiable in wp-admin) and stripped only for front-end display.

**End time on a single day.** For an event that starts and ends on the same day, the end time is taken from the **latest** time mentioned anywhere in the text. Event schedules list doors and warm-ups first (`18:30–19:00 doors`) and the actual finish last (`21:30 closing`), so EventMesh reaches the real end instead of stopping at the first range it sees.

**Multi-day events and exact control.** A bare time can't say which day it belongs to, so for events spanning more than one day — or whenever you want to pin an exact start/end from the source — write an explicit schedule anywhere in the description:

```
8.8.2026 19:00 - 21:30              (same day; only the end time given)
8.8.2026 19:00 - 10.8.2026 21:30    (spanning days)
```

Dates are `day.month.year`; times are 24-hour (`18:30` or `18.30`, optionally after `klo`); the separator is a hyphen or dash. When present this **wins over every other date/time signal**. (You can also set the start and end by hand on the event's edit screen — those overrides survive re-sync.)

### Filtering out non-events

The markup selectors and the `Product` type also match gift cards, merch, and other non-dated listings. An item with **no date at all** is kept only if its title *looks like* it names a date (dotted, ISO `2026-08-12`, `12/8`, or `Aug 12` / `12 Aug` style); otherwise it is skipped. An item with a real structured date is always kept.

### Canceled events

Holvi provides no structured "canceled" flag, so EventMesh treats the word **`CANCELED`** or **`CANCELLED`** (either spelling, case-insensitive) anywhere in the **event title** as the cancellation signal. The word is left visible in the title, and the front end strikes through the title and date for those events. This is deliberately separate from *sold out*, which comes from the availability/stock signals above rather than the title.

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
