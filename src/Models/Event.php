<?php

declare(strict_types=1);

namespace EventMesh\Models;

use DateTimeImmutable;

final class Event
{
    /**
     * @param array<string, string> $providers
     */
    public function __construct(
        private readonly string $sourceId,
        private readonly string $externalId,
        private readonly string $title,
        private readonly ?DateTimeImmutable $startsAt = null,
        private readonly ?DateTimeImmutable $endsAt = null,
        private readonly string $url = '',
        private readonly string $description = '',
        private readonly string $imageUrl = '',
        private readonly string $venueName = '',
        private readonly bool $startsAtYearKnown = true,
        private readonly bool $soldOut = false,
        private readonly array $providers = [],
        private readonly string $price = ''
    ) {
    }

    public function sourceId(): string
    {
        return $this->sourceId;
    }

    public function externalId(): string
    {
        return $this->externalId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function startsAt(): ?DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): ?DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function imageUrl(): string
    {
        return $this->imageUrl;
    }

    public function venueName(): string
    {
        return $this->venueName;
    }

    /**
     * Whether startsAt() had an explicit year in the source (title/content),
     * as opposed to one this connector had to infer (e.g. "12.8." with no
     * year, resolved to the next upcoming occurrence). Display code can use
     * this to decide whether to show a year at all.
     */
    public function startsAtYearKnown(): bool
    {
        return $this->startsAtYearKnown;
    }

    public function soldOut(): bool
    {
        return $this->soldOut;
    }

    /**
     * Display-ready price string as shown at the source (e.g. "€15",
     * "15,00 €", "Free"), or '' when the source listed no price. Kept as
     * presentation text rather than a numeric amount + currency: connectors
     * see prices already localised/formatted, and the ticket button only
     * ever renders it verbatim.
     */
    public function price(): string
    {
        return $this->price;
    }

    /**
     * Provider links (Spotify, Mixcloud, ...) parsed directly off this
     * event's own Holvi page - keyed by provider name, as used in
     * _eventmesh_provider_{name} post meta.
     *
     * @return array<string, string>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    /**
     * @return array{
     *     source_id: string,
     *     external_id: string,
     *     title: string,
     *     starts_at: string,
     *     starts_at_year_known: string,
     *     ends_at: string,
     *     url: string,
     *     description: string,
     *     image_url: string,
     *     venue_name: string,
     *     sold_out: string,
     *     providers: array<string, string>,
     *     price: string
     * }
     */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'external_id' => $this->externalId,
            'title' => $this->title,
            'starts_at' => $this->startsAt?->format(DATE_ATOM) ?? '',
            'starts_at_year_known' => $this->startsAtYearKnown ? '1' : '',
            'ends_at' => $this->endsAt?->format(DATE_ATOM) ?? '',
            'url' => $this->url,
            'description' => $this->description,
            'image_url' => $this->imageUrl,
            'venue_name' => $this->venueName,
            'sold_out' => $this->soldOut ? '1' : '',
            'providers' => $this->providers,
            'price' => $this->price,
        ];
    }
}
