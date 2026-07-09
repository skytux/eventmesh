<?php

declare(strict_types=1);

namespace EventMesh\Connectors\Holvi;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use EventMesh\Models\Event;

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

                if ($event instanceof Event) {
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
     * @param array<string, mixed> $item
     */
    private function isJsonLdEvent(array $item): bool
    {
        $type = $item['@type'] ?? '';

        if (is_array($type)) {
            return in_array('Event', $type, true);
        }

        return 'Event' === $type;
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

        return new Event(
            'holvi',
            $this->externalId($url, $title),
            $title,
            $this->dateValue($this->stringValue($item['startDate'] ?? '')),
            $this->dateValue($this->stringValue($item['endDate'] ?? '')),
            $url,
            $this->stringValue($item['description'] ?? ''),
            $this->imageValue($item['image'] ?? '', $sourceUrl),
            $this->placeValue($item['location'] ?? '')
        );
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

            if ($event instanceof Event) {
                $events[] = $event;
            }
        }

        return $events;
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

        return new Event(
            'holvi',
            $this->externalId($url, $title),
            $title,
            $this->dateValue($dateText),
            null,
            $url,
            $this->firstText(
                $xpath,
                $element,
                './/*[@itemprop="description"]' .
                '|.//*[contains(concat(" ", normalize-space(@class), " "), " store-item-description ")]'
            ),
            $imageUrl,
            $this->firstText($xpath, $element, './/*[@itemprop="location"]')
        );
    }

    private function imageUrlFromElement(DOMXPath $xpath, DOMNode $context, string $sourceUrl): string
    {
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

        return $this->absoluteUrl($imageUrl, $sourceUrl);
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

        return $this->absoluteUrl($this->stringValue($value), $sourceUrl);
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
