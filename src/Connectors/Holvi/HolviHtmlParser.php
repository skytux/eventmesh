<?php

declare(strict_types=1);

namespace EventMesh\Connectors\Holvi;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use EventMesh\Models\Event;
use EventMesh\Support\KnownProviders;

final class HolviHtmlParser
{
    /**
     * @return array<int, Event>
     */
    public function parse(string $html, string $sourceUrl): array
    {
        $document = $this->document($html);
        $xpath = new DOMXPath($document);

        $events = $this->parseJsonLdEvents($xpath, $sourceUrl);

        if ([] !== $events) {
            return $events;
        }

        return $this->parseMarkupEvents($xpath, $sourceUrl);
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /**
     * @return array<int, Event>
     */
    private function parseJsonLdEvents(DOMXPath $xpath, string $sourceUrl): array
    {
        $events = [];
        $scripts = $xpath->query('//script[@type="application/ld+json"]');

        if (false === $scripts) {
            return [];
        }

        foreach ($scripts as $script) {
            $decoded = json_decode(trim($script->textContent), true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach ($this->jsonLdItems($decoded) as $item) {
                if (! $this->isJsonLdEvent($item)) {
                    continue;
                }

                $event = $this->eventFromJsonLd($item, $sourceUrl);

                if (! $event instanceof Event) {
                    continue;
                }

                // An explicit schema.org Event is trusted as-is. A Product
                // (how Holvi tags its listings, but also its gift cards/merch)
                // is only kept when it looks like a real event - the same date
                // gate the markup fallback applies.
                if ($this->jsonLdTypeContains($item, 'Event') || $this->looksLikeARealEvent($event)) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return array<int, array<string, mixed>>
     */
    private function jsonLdItems(array $decoded): array
    {
        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            return array_filter($decoded['@graph'], 'is_array');
        }

        if (array_is_list($decoded)) {
            return array_filter($decoded, 'is_array');
        }

        return [$decoded];
    }

    /**
     * Holvi describes each listing as a schema.org Product (with the date in
     * its name and an offers block), not an Event, so both types are accepted;
     * parseJsonLdEvents() then applies the real-event date filter to Products.
     *
     * @param array<string, mixed> $item
     */
    private function isJsonLdEvent(array $item): bool
    {
        return $this->jsonLdTypeContains($item, 'Event') || $this->jsonLdTypeContains($item, 'Product');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function jsonLdTypeContains(array $item, string $wanted): bool
    {
        $type = $item['@type'] ?? '';

        if (is_array($type)) {
            return in_array($wanted, $type, true);
        }

        return $wanted === $type;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function eventFromJsonLd(array $item, string $sourceUrl): ?Event
    {
        $title = $this->stringValue($item['name'] ?? '');

        if ('' === $title) {
            return null;
        }

        $url = $this->absoluteUrl(
            $this->stringValue($item['url'] ?? ''),
            $sourceUrl
        );

        $description = $this->stringValue($item['description'] ?? '');
        $structuredEndDate = $this->dateValue($this->stringValue($item['endDate'] ?? ''));
        $resolved = $this->resolveDate($title, $this->dateValue($this->stringValue($item['startDate'] ?? '')));
        $schedule = $this->schedule($resolved, $structuredEndDate, $description);

        return new Event(
            sourceId: 'holvi',
            externalId: $this->externalId($url, $title),
            title: $title,
            startsAt: $schedule['start'],
            endsAt: $schedule['end'],
            url: $url,
            description: $description,
            imageUrl: $this->imageValue($item['image'] ?? '', $sourceUrl),
            venueName: $this->resolveVenue($this->placeValue($item['location'] ?? ''), $description),
            startsAtYearKnown: $schedule['yearKnown'],
            soldOut: $this->isSoldOutFromJsonLd($item),
            providers: $this->providersFromSameAs($item['sameAs'] ?? []),
            price: $this->priceFromJsonLd($item)
        );
    }

    /**
     * Builds a display price from schema.org offers (price + priceCurrency),
     * sitting right next to the offers.availability this parser already reads
     * for sold-out. Common currencies get their symbol; anything else keeps
     * its ISO code. A whole-number price drops its ".00"/",00" cents.
     *
     * @param array<string, mixed> $item
     */
    private function priceFromJsonLd(array $item): string
    {
        $offers = $item['offers'] ?? [];
        $offers = array_is_list($offers) ? ($offers[0] ?? []) : $offers;

        if (! is_array($offers)) {
            return '';
        }

        $amount = $this->stringValue($offers['price'] ?? $offers['lowPrice'] ?? '');

        if ('' === $amount) {
            return '';
        }

        $amount = trim((string) preg_replace('/([.,])00$/', '', $amount));
        $currency = strtoupper($this->stringValue($offers['priceCurrency'] ?? ''));

        $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];

        if (isset($symbols[$currency])) {
            return $symbols[$currency] . $amount;
        }

        return '' !== $currency ? $amount . ' ' . $currency : $amount;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isSoldOutFromJsonLd(array $item): bool
    {
        $offers = $item['offers'] ?? [];
        $offers = array_is_list($offers) ? ($offers[0] ?? []) : $offers;
        $availability = is_array($offers) ? $this->stringValue($offers['availability'] ?? '') : '';

        return false !== stripos($availability, 'soldout') || false !== stripos($availability, 'sold_out');
    }

    /**
     * schema.org's "sameAs" property commonly lists an entity's social/
     * streaming profile links - the JSON-LD equivalent of the plain <a href>
     * links extractProviders() scans for in markup.
     *
     * @return array<string, string>
     */
    private function providersFromSameAs(mixed $sameAs): array
    {
        $urls = is_array($sameAs) ? $sameAs : [$sameAs];
        $providers = [];

        foreach ($urls as $url) {
            $url = $this->stringValue($url);
            $provider = $this->providerForUrl($url);

            if (null !== $provider && ! isset($providers[$provider])) {
                $providers[$provider] = $url;
            }
        }

        return $providers;
    }

    /**
     * @return array<int, Event>
     */
    private function parseMarkupEvents(DOMXPath $xpath, string $sourceUrl): array
    {
        $events = [];
        $nodes = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " event ")' .
            ' or contains(concat(" ", normalize-space(@class), " "), " product ")' .
            ' or contains(concat(" ", normalize-space(@class), " "), " store-item ")' .
            ' or @data-event-id' .
            ' or @itemtype="http://schema.org/Product"' .
            ' or @itemtype="https://schema.org/Product"]'
        );

        if (false === $nodes) {
            return [];
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $event = $this->eventFromElement($xpath, $node, $sourceUrl);

            if ($event instanceof Event && $this->looksLikeARealEvent($event)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * The markup fallback's XPath (class="product"/"store-item", any
     * schema.org/Product) matches every product card on a Holvi shop page,
     * not just ticketed gigs - gift cards, merch, and other non-dated
     * listings get scraped too, with no real venue/date/ticket link to show.
     * If we already found a structured start date, trust it regardless of
     * title; otherwise only keep the item if its title itself looks like it
     * names a date, which is the only signal a real gig listing reliably has
     * in this fallback path.
     */
    private function looksLikeARealEvent(Event $event): bool
    {
        if (null !== $event->startsAt()) {
            return true;
        }

        return $this->titleContainsADate($event->title());
    }

    private function titleContainsADate(string $title): bool
    {
        $patterns = [
            '/\b\d{1,2}\.\d{1,2}\.(\d{2,4})?\b/',                                        // 12.8.2026 or 12.8. (fi/eu)
            '/\b\d{4}-\d{2}-\d{2}\b/',                                                    // 2026-08-12 (ISO)
            '/\b\d{1,2}\/\d{1,2}(\/\d{2,4})?\b/',                                         // 12/8/2026
            '/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s*\d{1,2}\b/i', // Aug 12
            '/\b\d{1,2}\.?\s*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\b/i', // 12 Aug / 12. Aug
        ];

        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $title)) {
                return true;
            }
        }

        return false;
    }

    private function eventFromElement(
        DOMXPath $xpath,
        DOMElement $element,
        string $sourceUrl
    ): ?Event {
        $title = $this->firstText(
            $xpath,
            $element,
            './/*[@itemprop="name"]' .
            '|.//*[contains(concat(" ", normalize-space(@class), " "), " store-item-name ")]' .
            '|.//*[self::h1 or self::h2 or self::h3 or self::h4]'
        );

        if ('' === $title) {
            return null;
        }

        $url = $this->absoluteUrl(
            $this->firstAttribute($xpath, $element, './/a[@href]', 'href'),
            $sourceUrl
        );
        $imageUrl = $this->imageUrlFromElement($xpath, $element, $sourceUrl);
        $dateText = $this->firstAttribute($xpath, $element, './/time[@datetime]', 'datetime');

        if ('' === $dateText) {
            $dateText = $this->firstText($xpath, $element, './/time');
        }

        $description = $this->firstText(
            $xpath,
            $element,
            './/*[@itemprop="description"]' .
            '|.//*[contains(concat(" ", normalize-space(@class), " "), " store-item-description ")]'
        );
        $resolved = $this->resolveDate($title, $this->dateValue($dateText));
        $structuredVenue = $this->firstText($xpath, $element, './/*[@itemprop="location"]');
        $schedule = $this->schedule($resolved, null, $description);

        return new Event(
            sourceId: 'holvi',
            externalId: $this->externalId($url, $title),
            title: $title,
            startsAt: $schedule['start'],
            endsAt: $schedule['end'],
            url: $url,
            description: $description,
            imageUrl: $imageUrl,
            venueName: $this->resolveVenue($structuredVenue, $description),
            startsAtYearKnown: $schedule['yearKnown'],
            soldOut: $this->isSoldOut($xpath, $element),
            providers: array_merge(
                $this->extractProvidersFromText($description),
                $this->extractProviders($xpath, $element, $sourceUrl)
            ),
            price: $this->priceFromMarkup($xpath, $element)
        );
    }

    /**
     * Holvi renders each listing's already-localised price inside a
     * "product-price" element (e.g. "15,00 €"); take it verbatim rather than
     * re-formatting, since the source has already applied the shop's currency
     * and locale conventions.
     */
    private function priceFromMarkup(DOMXPath $xpath, DOMNode $context): string
    {
        return $this->firstText(
            $xpath,
            $context,
            './/*[contains(concat(" ", normalize-space(@class), " "), " product-price ")' .
            ' or contains(concat(" ", normalize-space(@class), " "), " store-item-price ")' .
            ' or (@itemprop="price" and normalize-space(text()) != "")]'
        );
    }

    /**
     * Scans free text for bare provider URLs (Spotify/YouTube/... links a
     * listing writes into its description as plain text rather than as
     * clickable anchors), complementing the anchor-based extractProviders().
     *
     * @return array<string, string>
     */
    private function extractProvidersFromText(string $text): array
    {
        if ('' === trim($text)) {
            return [];
        }

        if (1 !== preg_match_all('#https?://[^\s"\'<>()\]]+#i', $text, $matches) && [] === ($matches[0] ?? [])) {
            return [];
        }

        $providers = [];

        foreach ($matches[0] as $url) {
            $url = rtrim($url, '.,;:!?');
            $provider = $this->providerForUrl($url);

            if (null !== $provider && ! isset($providers[$provider])) {
                $providers[$provider] = $url;
            }
        }

        return $providers;
    }

    /**
     * Scans every link within the given context for a known provider's
     * domain (Spotify, Mixcloud, etc.) - these are usually the artist's own
     * streaming/social links, embedded directly in Holvi's own listing or
     * description content, and take priority over the manually-configured
     * artist map since they're specific to this exact event.
     *
     * @return array<string, string>
     */
    private function extractProviders(DOMXPath $xpath, DOMNode $context, string $sourceUrl): array
    {
        $nodes = $xpath->query('.//a[@href]', $context);

        if (false === $nodes) {
            return [];
        }

        $providers = [];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $href = $this->absoluteUrl(trim($node->getAttribute('href')), $sourceUrl);
            $provider = $this->providerForUrl($href);

            if (null !== $provider && ! isset($providers[$provider])) {
                $providers[$provider] = $href;
            }
        }

        return $providers;
    }

    private function providerForUrl(string $url): ?string
    {
        $host = strtolower((string) (wp_parse_url($url)['host'] ?? ''));

        if ('' === $host) {
            return null;
        }

        foreach (KnownProviders::domains() as $domain => $provider) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Holvi marks a sold-out listing with a compound (hyphenated, not
     * space-separated) class like "product-price-container-sold-out" - a
     * plain substring check catches that, unlike the space-padded
     * concat(" ", @class, " ") technique used elsewhere for tokenized class
     * lists. Falls back to the "Sold out" text Holvi renders inside its
     * stock-status element, in case the class name ever changes.
     */
    private function isSoldOut(DOMXPath $xpath, DOMNode $context): bool
    {
        $nodes = $xpath->query('.//*[contains(@class, "sold-out")]', $context);

        if (false !== $nodes && $nodes->length > 0) {
            return true;
        }

        $stockText = $this->firstText(
            $xpath,
            $context,
            './/*[contains(concat(" ", normalize-space(@class), " "), " product-item-stock ")]'
        );

        return '' !== $stockText && false !== stripos($stockText, 'sold out');
    }

    /**
     * Holvi's shop/listing pages only expose an excerpt of each event's
     * description; the full description (and often the only place a real
     * venue/date ends up, buried in prose) lives on the event's own detail
     * page. Callers fetch that page separately and parse it with this.
     */
    public function parseDetailPage(string $html, string $pageUrl): ?Event
    {
        $document = $this->document($html);
        $xpath = new DOMXPath($document);

        $title = $this->firstText($xpath, $document, '//h1[@itemprop="name"]');

        if ('' === $title) {
            return null;
        }

        $descriptionNode = $this->firstElement($xpath, $document, '//*[@itemprop="description"]');
        $descriptionHtml = $descriptionNode instanceof DOMElement ? $this->innerHtml($descriptionNode) : '';
        $descriptionText = $descriptionNode instanceof DOMElement ? trim((string) $descriptionNode->textContent) : '';

        $structuredDate = $this->dateValue($this->firstAttribute($xpath, $document, '//time[@datetime]', 'datetime'));
        $resolved = $this->resolveDate($title, $structuredDate);
        $endDate = $resolved['endDate'];

        if (null === $resolved['date'] && '' !== $descriptionText) {
            $contentDate = $this->findDateInText($descriptionText);
            $resolved['date'] = $contentDate['date'];
            $resolved['yearKnown'] = $contentDate['yearKnown'];
            $endDate = $contentDate['endDate'];
        }

        $resolved['endDate'] = $endDate;
        $schedule = $this->schedule($resolved, null, $descriptionText);

        return new Event(
            sourceId: 'holvi',
            externalId: $this->externalId($pageUrl, $title),
            title: $title,
            startsAt: $schedule['start'],
            endsAt: $schedule['end'],
            url: $pageUrl,
            description: '' !== $descriptionHtml ? $descriptionHtml : $descriptionText,
            imageUrl: $this->imageUrlFromElement($xpath, $document, $pageUrl),
            venueName: $this->resolveVenue(
                $this->firstText($xpath, $document, '//*[@itemprop="location"]'),
                $descriptionText
            ),
            startsAtYearKnown: $schedule['yearKnown'],
            soldOut: $this->isSoldOut($xpath, $document),
            // Scoped to the description node, not the whole document - the
            // page's own header/footer routinely carries Holvi's own
            // site-wide "share on Facebook/Instagram" links, which would
            // otherwise get misattributed as this specific artist's profile.
            // Bare URLs written into the description text are picked up too;
            // anchor links win on any duplicate.
            providers: array_merge(
                $this->extractProvidersFromText($descriptionText),
                $descriptionNode instanceof DOMElement
                    ? $this->extractProviders($xpath, $descriptionNode, $pageUrl)
                    : []
            ),
            price: $this->priceFromMarkup($xpath, $document)
        );
    }

    private function innerHtml(DOMElement $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= (string) $node->ownerDocument?->saveHTML($child);
        }

        return trim($html);
    }

    /**
     * The title is kept verbatim (including any embedded date) since it
     * becomes the post_title - useful for identifying events in wp-admin.
     * Hiding the date from front-end display is a separate, presentation-only
     * concern; see stripDateForDisplay().
     *
     * @return array{date: ?DateTimeImmutable, endDate: ?DateTimeImmutable, yearKnown: bool}
     */
    private function resolveDate(string $title, ?DateTimeImmutable $structuredDate): array
    {
        if (null !== $structuredDate) {
            return ['date' => $structuredDate, 'endDate' => null, 'yearKnown' => true];
        }

        $found = $this->findDateInText($title);

        return [
            'date' => $found['date'],
            'endDate' => $found['endDate'],
            'yearKnown' => $found['yearKnown'],
        ];
    }

    /**
     * Resolves an event's start and end, preferring - when present in the text
     * - the explicit "start - end" datetime format over the date/time guessed
     * from the title and description. Otherwise falls back to the resolved date
     * plus applyTimeRange()'s single-day time handling.
     *
     * @param array{date: ?DateTimeImmutable, endDate: ?DateTimeImmutable, yearKnown: bool} $resolved
     *
     * @return array{start: ?DateTimeImmutable, end: ?DateTimeImmutable, yearKnown: bool}
     */
    private function schedule(array $resolved, ?DateTimeImmutable $structuredEndDate, string $text): array
    {
        $explicit = $this->explicitSchedule($text);

        if (null !== $explicit) {
            return ['start' => $explicit['start'], 'end' => $explicit['end'], 'yearKnown' => true];
        }

        [$start, $end] = $this->applyTimeRange(
            $resolved['date'],
            $structuredEndDate ?? $resolved['endDate'],
            $text
        );

        return ['start' => $start, 'end' => $end, 'yearKnown' => $resolved['yearKnown']];
    }

    /**
     * The explicit, unambiguous schedule a source can write into an event's
     * description to pin its exact start and end. Required for events that span
     * more than one day, where a bare time can't say which day it belongs to.
     * Two forms:
     *
     *   8.8.2026 19:00 - 21:30              (same day; only the end time given)
     *   8.8.2026 19:00 - 10.8.2026 21:30    (spanning days)
     *
     * Dates are day.month.year; times are 24-hour ("18:30" or "18.30"),
     * optionally after "klo"; the separator is a hyphen or dash. When present
     * this wins over every other date/time signal.
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null
     */
    private function explicitSchedule(string $text): ?array
    {
        $pattern = '/(?<sd>\d{1,2})\.(?<smo>\d{1,2})\.(?<sy>\d{4})\s+(?:klo\s+)?(?<sh>\d{1,2})[.:](?<smin>\d{2})'
            . '\s*[-\x{2013}\x{2014}]\s*'
            . '(?:(?<ed>\d{1,2})\.(?<emo>\d{1,2})\.(?<ey>\d{4})\s+(?:klo\s+)?)?'
            . '(?<eh>\d{1,2})[.:](?<emin>\d{2})/iu';

        if (1 !== preg_match($pattern, $text, $m)) {
            return null;
        }

        $start = $this->buildDate((int) $m['sy'], (int) $m['smo'], (int) $m['sd'], true);

        if (null === $start) {
            return null;
        }

        $start = $start->setTime((int) $m['sh'], (int) $m['smin']);

        $endDate = '' !== ($m['ed'] ?? '')
            ? $this->buildDate((int) $m['ey'], (int) $m['emo'], (int) $m['ed'], true)
            : $start;

        if (null === $endDate) {
            return null;
        }

        $end = $endDate->setTime((int) $m['eh'], (int) $m['emin']);

        // An end at or before the start is a mistake (e.g. a same-day "22:00 -
        // 02:00" that really crosses midnight) - fall back rather than store a
        // negative span; such events should give the end its own date.
        if ($end <= $start) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Strips a recognized embedded date (the same one startsAt would be
     * extracted from) out of a title, for front-end display only - the
     * stored post title/admin list keeps the date intact for identification.
     */
    public function stripDateForDisplay(string $title): string
    {
        $found = $this->findDateInText($title);

        if ('' === $found['matchedText']) {
            return $title;
        }

        $stripped = trim(str_replace($found['matchedText'], '', $title));
        $stripped = trim($stripped, " \t\n\r\0\x0B-–—,");
        $stripped = trim((string) preg_replace('/\s+/', ' ', $stripped));

        return '' !== $stripped ? $stripped : $title;
    }

    private function resolveVenue(string $structuredVenue, string $descriptionText): string
    {
        if ('' !== $structuredVenue) {
            return $structuredVenue;
        }

        if ('' === $descriptionText) {
            return '';
        }

        foreach (['venue', 'location'] as $label) {
            if (1 === preg_match('/^\s*' . preg_quote($label, '/') . '\s*:\s*(.+)$/im', $descriptionText, $matches)) {
                return trim($matches[1]);
            }
        }

        return '';
    }

    private const RANGE_DATE_PATTERN =
        '/\b(?<startDay>\d{1,2})\.(?<startMonth>\d{1,2})\s*-\s*'
        . '(?<endDay>\d{1,2})\.(?<endMonth>\d{1,2})\.(?<year>\d{4})?/';
    private const SINGLE_DATE_PATTERN = '/\b(?<day>\d{1,2})\.(?<month>\d{1,2})\.(?<year>\d{4})?/';

    /**
     * Looks for a Finnish/European "12.8.2026" or "12.8." style date, or a
     * "27.6-28.6.2026" range (in which case the second/later date's year, if
     * any, applies to both ends). When no year is present, resolves to the
     * next upcoming occurrence of that day/month rather than assuming the
     * current year has already passed.
     *
     * @return array{date: ?DateTimeImmutable, endDate: ?DateTimeImmutable, yearKnown: bool, matchedText: string}
     */
    private function findDateInText(string $text): array
    {
        $none = ['date' => null, 'endDate' => null, 'yearKnown' => true, 'matchedText' => ''];

        if (1 === preg_match(self::RANGE_DATE_PATTERN, $text, $matches)) {
            $yearKnown = '' !== ($matches['year'] ?? '');
            $year = $yearKnown ? (int) $matches['year'] : (int) (new DateTimeImmutable())->format('Y');

            $start = $this->buildDate($year, (int) $matches['startMonth'], (int) $matches['startDay'], $yearKnown);
            $end = $this->buildDate($year, (int) $matches['endMonth'], (int) $matches['endDay'], $yearKnown);

            if (null !== $start) {
                return ['date' => $start, 'endDate' => $end, 'yearKnown' => $yearKnown, 'matchedText' => $matches[0]];
            }
        }

        if (1 === preg_match(self::SINGLE_DATE_PATTERN, $text, $matches)) {
            $yearKnown = '' !== ($matches['year'] ?? '');
            $year = $yearKnown ? (int) $matches['year'] : (int) (new DateTimeImmutable())->format('Y');

            $date = $this->buildDate($year, (int) $matches['month'], (int) $matches['day'], $yearKnown);

            if (null !== $date) {
                return ['date' => $date, 'endDate' => null, 'yearKnown' => $yearKnown, 'matchedText' => $matches[0]];
            }
        }

        return $none;
    }

    /**
     * Looks for a time-of-day range in text - "klo 18:00-21:00" (Finnish,
     * "klo" = "at") or a bare "18:00-21:00" - and applies it to the given
     * dates' time-of-day. A date-only value (from title/date-pattern
     * extraction) always has a midnight time component, so this is the only
     * way start/end times surface at all for events without a structured
     * <time datetime> that already included one.
     *
     * For a single-day event (no end date) the end is then taken from the
     * LATEST time mentioned anywhere in the text, not just the first range.
     * Event schedules list doors/warm-up first ("18:30-19:00 doors") and the
     * real finish last ("21:30 closing"), so the first range would end the
     * event far too early. Multi-day events can't be resolved from bare times
     * (a time can't say which day it belongs to) - they use the explicit
     * "start - end" datetime format instead; see explicitSchedule().
     *
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable}
     */
    private function applyTimeRange(?DateTimeImmutable $start, ?DateTimeImmutable $end, string $text): array
    {
        if (null === $start) {
            return [$start, $end];
        }

        $time = $this->findTimeRangeInText($text);

        if (null === $time['startHour']) {
            return [$start, $end];
        }

        $newStart = $start->setTime($time['startHour'], $time['startMinute']);
        $newEnd = $end;

        if (null !== $time['endHour']) {
            $newEnd = ($end ?? $start)->setTime($time['endHour'], $time['endMinute']);
        }

        // Single-day (no end date): let the latest time in the text win as the
        // end, as long as it is after the start.
        if (null === $end) {
            $latest = $this->latestTimeInText($text);

            if (null !== $latest) {
                $candidate = $newStart->setTime($latest['hour'], $latest['minute']);

                if ($candidate > $newStart) {
                    $newEnd = $candidate;
                }
            }
        }

        return [$newStart, $newEnd];
    }

    /**
     * The latest time-of-day mentioned anywhere in the text. Matches colon
     * times ("21:30") and "klo 21.30"/"klo 21:30"; a bare dotted time is
     * deliberately not matched so a date like "18.03.2026" is never misread as
     * a time.
     *
     * @return array{hour: int, minute: int}|null
     */
    private function latestTimeInText(string $text): ?array
    {
        $pattern = '/\bklo\s+(\d{1,2})[.:](\d{2})\b|\b(\d{1,2}):(\d{2})\b/i';

        if (0 === preg_match_all($pattern, $text, $sets, PREG_SET_ORDER)) {
            return null;
        }

        $latest = null;

        foreach ($sets as $set) {
            $hour = '' !== ($set[1] ?? '') ? (int) $set[1] : (int) ($set[3] ?? -1);
            $minute = '' !== ($set[2] ?? '') ? (int) $set[2] : (int) ($set[4] ?? 0);

            if ($hour < 0 || $hour > 23 || $minute > 59) {
                continue;
            }

            $value = $hour * 60 + $minute;

            if (null === $latest || $value > $latest['value']) {
                $latest = ['hour' => $hour, 'minute' => $minute, 'value' => $value];
            }
        }

        if (null === $latest) {
            return null;
        }

        return ['hour' => $latest['hour'], 'minute' => $latest['minute']];
    }

    /**
     * @return array{startHour: ?int, startMinute: ?int, endHour: ?int, endMinute: ?int}
     */
    private function findTimeRangeInText(string $text): array
    {
        $none = ['startHour' => null, 'startMinute' => null, 'endHour' => null, 'endMinute' => null];

        if (
            1 === preg_match(
                '/\bklo\s+(?<sh>\d{1,2})[:.](?<sm>\d{2})(?:\s*-\s*(?<eh>\d{1,2})[:.](?<em>\d{2}))?/i',
                $text,
                $matches
            )
        ) {
            return [
                'startHour' => (int) $matches['sh'],
                'startMinute' => (int) $matches['sm'],
                'endHour' => isset($matches['eh']) && '' !== $matches['eh'] ? (int) $matches['eh'] : null,
                'endMinute' => isset($matches['em']) && '' !== $matches['em'] ? (int) $matches['em'] : null,
            ];
        }

        if (
            1 === preg_match(
                '/\b(?<sh>\d{1,2}):(?<sm>\d{2})\s*-\s*(?<eh>\d{1,2}):(?<em>\d{2})\b/',
                $text,
                $matches
            )
        ) {
            return [
                'startHour' => (int) $matches['sh'],
                'startMinute' => (int) $matches['sm'],
                'endHour' => (int) $matches['eh'],
                'endMinute' => (int) $matches['em'],
            ];
        }

        return $none;
    }

    private function buildDate(int $year, int $month, int $day, bool $yearKnown): ?DateTimeImmutable
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        try {
            $date = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        } catch (\Exception) {
            return null;
        }

        if (! $yearKnown && $date < new DateTimeImmutable('today')) {
            $date = $date->modify('+1 year');
        }

        return $date;
    }

    private function imageUrlFromElement(DOMXPath $xpath, DOMNode $context, string $sourceUrl): string
    {
        $carouselUrl = $this->imageFromCarouselAttribute($xpath, $context, $sourceUrl);

        if ('' !== $carouselUrl) {
            return $carouselUrl;
        }

        $imageNode = $this->firstElement($xpath, $context, './/*[@itemprop="image"]|.//img[@src]');

        if (! $imageNode instanceof DOMElement) {
            return '';
        }

        $imageUrl = trim($imageNode->getAttribute('content'));

        if ('' === $imageUrl) {
            $imageUrl = trim($imageNode->getAttribute('src'));
        }

        if ('' === $imageUrl) {
            $imageUrl = trim($imageNode->getAttribute('style'));

            if ('' !== $imageUrl) {
                preg_match('/url\(([^)]+)\)/i', $imageUrl, $matches);

                if (isset($matches[1])) {
                    $imageUrl = trim($matches[1], " \t\n\r\0\x0B'\"");
                }
            }
        }

        return $this->validatedImageUrl($this->absoluteUrl($imageUrl, $sourceUrl));
    }

    /**
     * Holvi's own detail-page image gallery isn't marked up with
     * itemprop="image" or a plain <img src> at all - it's an Angular
     * component (<image-carousel images="[{&quot;url&quot;: &quot;...&quot;}]">)
     * carrying the real image URLs as an HTML-entity-escaped JSON attribute.
     * DOMDocument decodes the entities for us, so the attribute value is
     * already valid JSON once read.
     */
    private function imageFromCarouselAttribute(DOMXPath $xpath, DOMNode $context, string $sourceUrl): string
    {
        $node = $this->firstElement($xpath, $context, './/*[@images]');

        if (! $node instanceof DOMElement) {
            return '';
        }

        $decoded = json_decode($node->getAttribute('images'), true);

        if (! is_array($decoded) || ! is_array($decoded[0] ?? null)) {
            return '';
        }

        $url = $this->stringValue($decoded[0]['url'] ?? $decoded[0]['thumb'] ?? '');

        return $this->validatedImageUrl($this->absoluteUrl($url, $sourceUrl));
    }

    /**
     * media_sideload_image() rejects malformed URLs with an unhelpful
     * "Invalid image URL" error and no context on which event caused it.
     * Filtering here means a bad extraction just results in no image
     * (silently skipped downstream) rather than a failed sync/log spam.
     */
    private function validatedImageUrl(string $url): string
    {
        if ('' === $url || 1 !== preg_match('#^https?://#i', $url)) {
            return '';
        }

        return false !== filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    private function firstElement(DOMXPath $xpath, DOMNode $context, string $query): ?DOMElement
    {
        $nodes = $xpath->query($query, $context);

        if (false === $nodes || 0 === $nodes->length) {
            return null;
        }

        $node = $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function firstText(
        DOMXPath $xpath,
        DOMNode $context,
        string $query
    ): string {
        $nodes = $xpath->query($query, $context);

        if (false === $nodes || 0 === $nodes->length) {
            return '';
        }

        return trim((string) $nodes->item(0)?->textContent);
    }

    private function firstAttribute(
        DOMXPath $xpath,
        DOMNode $context,
        string $query,
        string $attribute
    ): string {
        $nodes = $xpath->query($query, $context);

        if (false === $nodes || 0 === $nodes->length) {
            return '';
        }

        $node = $nodes->item(0);

        if (! $node instanceof DOMElement) {
            return '';
        }

        return trim($node->getAttribute($attribute));
    }

    private function dateValue(string $value): ?DateTimeImmutable
    {
        if ('' === trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function imageValue(mixed $value, string $sourceUrl): string
    {
        if (is_array($value)) {
            $value = $value[0] ?? '';
        }

        return $this->validatedImageUrl($this->absoluteUrl($this->stringValue($value), $sourceUrl));
    }

    private function placeValue(mixed $value): string
    {
        if (is_array($value)) {
            return $this->stringValue($value['name'] ?? '');
        }

        return $this->stringValue($value);
    }

    private function stringValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function externalId(string $url, string $title): string
    {
        $seed = '' !== $url ? $url : $title;

        return hash('sha256', 'holvi:' . $seed);
    }

    private function absoluteUrl(string $url, string $sourceUrl): string
    {
        if ('' === $url || str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $source = wp_parse_url($sourceUrl);

        if (! is_array($source) || empty($source['scheme']) || empty($source['host'])) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return $source['scheme'] . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            return $source['scheme'] . '://' . $source['host'] . $url;
        }

        $path = isset($source['path']) ? dirname($source['path']) : '';

        return $source['scheme'] . '://' . $source['host'] . '/' . trim($path . '/' . $url, '/');
    }
}
