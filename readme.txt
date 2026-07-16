=== EventMesh ===
Contributors: louh
Tags: events, sync, holvi, gutenberg, event list
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronize external event sources into native WordPress content, with editable Gutenberg blocks for event lists, dates, venues, and tickets.

== Description ==

EventMesh pulls event listings from external sources into real WordPress posts (a dedicated Events post type), so your events live in your own database, work with any theme, and stay fully editable.

**Features**

* **Background sync** — events are imported and kept up to date on a schedule you choose (15 minutes to daily), with a visitor-triggered fallback when WP-Cron is unreliable and a sync-health panel under Diagnostics.
* **Holvi connector** — point the plugin at one or more Holvi shop URLs and their event listings are imported, including dates, venues, images, sold-out state, and ticket links.
* **Gutenberg blocks** — an event list block plus Query Loop-ready blocks for the event date, title, venue, ticket button, provider embeds (Spotify, YouTube, Mixcloud, Bandcamp, SoundCloud), and a "Past Events" divider. All blocks support the standard color, typography, border, and spacing controls.
* **Provider links & embeds** — provider links (Spotify, YouTube, Mixcloud, Bandcamp, SoundCloud) found on a source's event page are attached to the event and rendered as embeds.
* **Editable overrides** — every event field (title, description, date, venue, price, sold-out state, and provider links) and the featured image is editable per event and kept on the next sync. A per-field "Follow source again" discards your version and returns to the source whenever you want it.
* **Hide and disable events** — "Hide" keeps an event out of the front-end listings while its own page still works; "Disable" also makes its page return 404. Both are per-event and survive re-sync.
* **Keep events the source dropped** — pin an event to stay published even after it disappears from its source (instead of being archived on the next sync); its details freeze at the last sync.
* **Sold out and canceled handling** — sold-out events show a non-clickable Sold out state, events with CANCELED in the title are struck through, and past events sort below upcoming ones under a divider.
* **Reversible source management** — disable a source and its events are archived (moved to Draft) on the next sync; re-enable it and they are republished. Uninstalling keeps your data unless you opt in to deletion.
* **Connector-agnostic architecture** — new sources can be added as connectors without touching the core plugin.

**A note on data ownership:** synced events are ordinary posts. You can edit them, feature them, or build on them with any block — EventMesh only updates the fields it manages on the next sync.

== External services ==

EventMesh connects to external services to import and enrich event data. No data is sent anywhere unless you configure a source.

**Holvi (holvi.com)**
When you add one or more Holvi shop URLs under EventMesh → Sources, the plugin periodically requests those public shop pages (and the individual event pages linked from them) to read event titles, dates, venues, images, and ticket availability. Only the URLs you configured are requested; no visitor or site data is sent. See the [Holvi terms of service](https://www.holvi.com/terms/) and [privacy policy](https://www.holvi.com/privacy/).

**Media provider oEmbed endpoints (Spotify, YouTube, Mixcloud, Bandcamp, SoundCloud)**
When a synced event links to an artist page or track on one of these providers (found on the source's event page), the plugin requests that provider's public oEmbed endpoint to fetch embeddable player markup for the URL in question. Only that provider URL is sent; no visitor or site data is included. See each provider's terms and privacy policy: [Spotify](https://www.spotify.com/legal/), [YouTube](https://www.youtube.com/t/terms), [Mixcloud](https://www.mixcloud.com/terms/), [Bandcamp](https://bandcamp.com/terms_of_use), [SoundCloud](https://soundcloud.com/terms-of-use).

**Event images**
When a source lists an image for an event, the plugin downloads that image from the URL the source provided (which may be a CDN) into your media library, so events display images hosted on your own site.

== How EventMesh reads a Holvi page ==

For each shop URL, EventMesh fetches the listing page, then each event's own detail page, and merges the richer detail data over the listing (the detail page usually has the full description and the most reliable venue and date).

It decodes the page two ways, in order:

1. JSON-LD (the `<script type="application/ld+json">` data Holvi embeds) is preferred. Holvi tags each listing as a schema.org Product — with the date in its name and an offers block — so both Product and Event types are read.
2. HTML markup is the fallback, used only when no JSON-LD is found. It scans product / store-item / event elements and reads their itemprop attributes, Holvi's own class names, `<time>` tags, headings, and links.

Fields it populates for each event:

* Title — the listing name (kept with its date so events are identifiable in wp-admin; the date is hidden only for front-end display).
* Description — the item description (the detail page keeps its formatting).
* Ticket link — the listing/detail URL.
* Start and end date — from a structured date, or a Finnish/European dotted date found in the text (see below).
* Start and end time — from "klo 18:00-21:00" (Finnish "klo" = "at") or a bare "18:00-21:00" in the text.
* Venue — the schema.org location, or a "Venue:" / "Location:" line in the description.
* Price — the offer price and currency (shown with €/$/£), or Holvi's already-formatted price element.
* Sold out — the offer availability ("SoldOut"), or a "sold-out" class / "Sold out" stock label.
* Featured image — the listing image, including Holvi's JSON image-carousel and CSS background images.
* Provider links — Spotify, YouTube, Mixcloud, Bandcamp, SoundCloud, Instagram, and Facebook links found in the listing or description.

= Dates and times =

A structured date is used as-is. Otherwise EventMesh reads a dotted date from the text: "12.8.2026", "12.8." (no year), or a "27.6-28.6.2026" range. When no year is given it picks the next upcoming occurrence, never a past date. A date with no time of day stays at midnight. Listings with no date at all (such as gift cards or merch) are skipped unless the title itself looks like it names a date.

Times of day come from "klo 18:00-21:00" or a bare "18:00-21:00". For an event that starts and ends on the same day, the end time is the latest time found anywhere in the text - schedules list doors and warm-ups first and the real finish last, so EventMesh reaches the actual end instead of stopping at the first range.

= Multi-day events and exact start/end =

A bare time can't say which day it belongs to, so for events that span more than one day - or whenever you want to pin an exact start and end from the source - write an explicit schedule anywhere in the description:

* Same day, giving only the end time: 8.8.2026 19:00 - 21:30
* Spanning days: 8.8.2026 19:00 - 10.8.2026 21:30

Dates are day.month.year; times are 24-hour ("18:30" or "18.30"), optionally after "klo"; the separator is a hyphen or dash. When present, this wins over every other date/time signal. You can also set the start and end by hand on the event's own edit screen; those overrides survive the next sync.

= Canceled events =

Holvi has no structured "canceled" flag, so EventMesh treats the word CANCELED or CANCELLED (either spelling, any case) anywhere in the event title as the signal. The word stays visible, and the front end strikes through the title and date. This is separate from "sold out", which comes from the availability and stock signals above, not the title.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/eventmesh/`, or install through the WordPress plugins screen.
2. Activate the plugin through the Plugins screen.
3. Go to **EventMesh → Sources** and add one or more Holvi shop URLs.
4. Go to **EventMesh → Settings** to choose the sync interval and data-retention options.
5. Click **Sync now** on the EventMesh dashboard, or wait for the background sync.
6. Add the **EventMesh event list** block (or the Query Loop pattern) to any page.

== Frequently Asked Questions ==

= Do I need a Holvi account? =

No. The plugin reads public Holvi shop pages. You only need the URL of the shop whose events you want to display.

= My events are not syncing automatically. =

Check **EventMesh → Diagnostics**: the Background sync health panel shows the next scheduled run, the last attempt, and a concrete recommendation when your host blocks WordPress's own cron mechanism (typically: define `DISABLE_WP_CRON` and trigger `wp-cron.php` from a system cron).

= What happens when an event disappears from the source? =

It is archived (moved to Draft), not deleted. If it reappears at the source, it is republished automatically.

= Can I edit synced events? =

Yes — they are normal posts. Edit the title, description, or featured image right in the editor, or override the date, venue, price, sold-out state, and provider links in the EventMesh box; your changes are kept on every sync. Each field has a "Follow source again" control that hands it back to the source when you want it. Anything else you add to the post is always kept.

= How do I add another event source? =

Connectors are self-contained: a directory under `src/Connectors/` with a `register.php` that hooks `eventmesh/register_connectors`. The bundled Dummy connector (enable it in EventMesh → Sources) is a minimal reference implementation.

= Does the plugin phone home or collect analytics? =

No. The only outgoing requests are the ones described under External services, and only for sources and providers you configured.

== Screenshots ==

1. The EventMesh dashboard with sync status and controls.
2. Sources page with per-connector enablement and Holvi shop URLs.
3. The event list block in the editor.
4. Settings: sync interval and data-retention options.
5. Diagnostics with the background sync health panel.

== Changelog ==

= 1.0.0 =
* Initial release.
* Holvi connector with multi-URL source management.
* Events post type with background sync, locking, and cron fallback.
* Gutenberg blocks: event list, event field (date/title/venue), ticket button, provider embed, other-provider links, all-provider links, past-events marker.
* Optional editor-set prefix and suffix on the event-field and ticket-button blocks (e.g. "at Venue", "From €15 →").
* Provider links parsed from source pages, with oEmbed embeds for Spotify, YouTube, Mixcloud, Bandcamp, and SoundCloud.
* Per-event overrides for every field — title, description, date, venue, price, sold-out, provider links, and the featured image — that survive re-sync, each with a "Follow source again".
* Per-event "Hide" (drop from listings), "Disable" (also 404 its page), and "Keep published if removed from the source", plus an all-provider-links block listing every provider link.
* Sold-out and CANCELED handling, past/upcoming ordering with a divider.
* Per-source enablement in the Sources table, with reversible archiving.
* Status dashboard with an AJAX "Sync now" and last-error reporting.
* Diagnostics page with background-sync health.
* Built-in dummy/test source (enable in Sources) for demos.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
