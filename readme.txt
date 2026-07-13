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
* **Provider links & embeds** — provider links (Spotify, YouTube, Mixcloud, Bandcamp, SoundCloud) found on a source's event page are attached to the event and rendered as embeds. Every field and link is editable per event from the editor, and your overrides survive the next sync.
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

Yes — they are normal posts. Fields EventMesh manages (title, dates, ticket link) are overwritten on the next sync; everything else you add is kept. The venue is preserved once set, even if a later sync cannot find one.

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
* Gutenberg blocks: event list, event field (date/title/venue), ticket button, provider embed, other-provider links, past-events marker.
* Provider links parsed from source pages, with oEmbed embeds for Spotify, YouTube, Mixcloud, Bandcamp, and SoundCloud.
* Editable per-event field and link overrides that survive re-sync.
* Sold-out and CANCELED handling, past/upcoming ordering with a divider.
* Per-source enablement in the Sources table, with reversible archiving.
* Status dashboard with an AJAX "Sync now" and last-error reporting.
* Diagnostics page with background-sync health.
* Built-in dummy/test source (enable in Sources) for demos.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
