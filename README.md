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
- **DJ table** — under **Events → DJs**, each DJ's name, other spellings, and Spotify, SoundCloud and Mixcloud links. Events that name a DJ get that DJ's links for services they have no link for yet; a link typed on the event is never replaced.
- **Reversible source management** — disabling a source archives its events (Draft) on the next sync; re-enabling republishes them. Uninstalling keeps your data unless you opt in to deletion.
- **Automatic translation (optional)** — translates each event's title and text with a language model on a server you choose, for visitors viewing the site in another language. See [Automatic translation](#automatic-translation).
- **Connector-agnostic** — new sources can be added as self-contained connectors without touching the core plugin.

## Automatic translation

Off by default, and the plugin ships with no server, model or key. To use it:

1. Go to **EventMesh → Settings → Automatic translation**.
2. Enter the **API URL** of any OpenAI-compatible chat API (the base URL; EventMesh adds `/chat/completions`), the **model** name and the **API key**. The key is stored encrypted (sodium, with a key derived from the site's auth salt) and is never shown again; leave the field empty to keep it, or use **Remove key**.
3. Tick the languages to translate into. The default is Polylang's languages, or the site language without Polylang. An event is never translated into its own language.
4. Tick **Translate events automatically**, save, and use **Test connection**. **Translate now** queues every published event and starts at once.

What is sent to the server: only each event's **title and text** (the description), once to detect the original language and then for each target language. No visitor data and no other site data are sent. The key and the event text are never written to the log; the log records only short error kinds such as `http_401`.

How it runs:

- Each synced (or edited) event is queued; a WP-Cron job works on the queue in runs of about 40 seconds and schedules itself again while work remains. Long text is split into pieces.
- An event is translated again only when its title or text changes.
- Each translation is checked before it is saved: the same numbers, links, email addresses, HTML tags and block markers as the original, and not empty. A translation that fails the check is not saved; after 3 failed attempts on the same text it is given up until the text changes (the count shows on the event).
- Visitors see a translation when the page language (Polylang's current language, or the site locale) differs from the event's original language and the translation was made from the event's current text. Automatic translations carry the line *Automatically translated* (CSS class `eventmesh-auto-translated`).
- The **Translations** box on the event edit screen shows each translation. Changing one there marks it as edited: background translation never overwrites it. **Back to automatic** hands it back.

The settings can also be set from a one-off PHP script after loading WordPress:

```php
require 'wp-load.php';
\EventMesh\Translation\LlmSettings::save('https://llm.example/v1', 'model-name', 'api-key');
```

Deleting the plugin removes these settings and the stored translations.

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
php tools/build-zip.php            # Linux: build dist/eventmesh-<version>.zip (same files)
php tools/make-pot.php             # extract strings into languages/eventmesh.pot
php tools/make-mo.php              # compile languages/*.po into .mo
```

CI (GitHub Actions) runs PHPUnit and PHPCS on every push and pull request.

## Changelog

### 1.10.4
- The event field and past-events heading blocks only accept p, h1-h6, div or span as their tag (Q250).

### 1.10.3
- "Sync now" cleans the synced title and text the same way the automatic sync does (Q249).

### 1.10.2
- Developer: the line-length rule of the code style check (phpcs) no longer applies to templates/ (Q231).

### 1.10.1
- Developer: the code style check (phpcs) now also covers templates/; no change in output.

### 1.10.0
- Holvi: the start time comes from the doors-open line ("18:30-19:00 Ovet auki", "Doors open 18:30"). A doors-closing time ("Ovet suljetaan klo 19:00") is never taken as the start.
- When automatic translation (the language model) is set up, it fills the event fields the parser could not find in the text: start, end, venue name, venue address and price. It never overwrites a value the parser found, and a value the parser finds later replaces the inferred one.

### 1.9.0
- The DJ line under each event title shows only the names, without the word "DJ" or "DJs" in front.

### 1.8.0
- Public pages show each event's clean name (normal case, no date) with the DJ names on their own line under it: the event title field (event lists and the single event page) and the event-list block's list and card templates. While an event has no clean name yet, its title is shown as before.

### 1.7.0
- Clean event names: the language model writes each event's name without the date and the DJ names, in normal case ("Ecstatic Dance" from "ECSTATIC DANCE 14.11.2026 / ..."), also from the Finnish title, and lists the DJ names in the title. Every answer is checked against the title and rejected when it adds words. Other plugins read them through the filters `eventmesh/event_name` and `eventmesh/event_dj_names`; the event edit screen shows them.

### 1.6.0
- DJ descriptions: every DJ in Events > DJs has a description, typed by hand or filled in automatically from the Team page (the paragraphs under the DJ's name heading) or from the DJ's SoundCloud or Mixcloud profile. A typed description is never overwritten. Set the Team page and press "Fetch descriptions now" on the DJs page. Other plugins get the DJs an event names, with their descriptions, through the filter `eventmesh/event_djs`.

### 1.5.1
- SoundCloud players no longer show the "Play on SoundCloud" / "Listen in browser" screen on phones: `show_teaser=false` is added to every SoundCloud player address, also for players already saved.

### 1.5.0
- New Events → DJs page and DJ table (name, other spellings, Spotify/SoundCloud/Mixcloud links), filled once from existing event titles and links. Events whose title or text names a DJ (whole words, case and accents ignored) get that DJ's links for services they have no link for yet, and their player is updated; a link typed on the event is never replaced. Future events are checked again after every DJ change.
- The play button starts the player through the official Spotify, SoundCloud and Mixcloud player APIs, loaded only after the click; if an API does not load, the player is shown as before.

### 1.4.0
- Optional automatic translation of event titles and text with a language model on a server you choose (OpenAI-compatible chat API): encrypted API key, background queue, safety check before saving, per-event editable translations, "Automatically translated" label. Off by default.

### 1.3.0
- Finnish translation; texts follow the site or page language (e.g. Polylang).

Earlier versions: see the changelog in [readme.txt](readme.txt).

## License

[GPL-2.0-only](LICENSE).
