<?php

declare(strict_types=1);

namespace EventMesh\Models;

use DateTimeImmutable;

final class Event
{
    public function __construct(
        private readonly string $sourceId,
        private readonly string $externalId,
        private readonly string $title,
        private readonly ?DateTimeImmutable $startsAt = null,
        private readonly ?DateTimeImmutable $endsAt = null,
        private readonly string $url = '',
        private readonly string $description = '',
        private readonly string $imageUrl = '',
        private readonly string $venueName = ''
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
     * @return array{
     *     source_id: string,
     *     external_id: string,
     *     title: string,
     *     starts_at: string,
     *     ends_at: string,
     *     url: string,
     *     description: string,
     *     image_url: string,
     *     venue_name: string
     * }
     */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'external_id' => $this->externalId,
            'title' => $this->title,
            'starts_at' => $this->startsAt?->format(DATE_ATOM) ?? '',
            'ends_at' => $this->endsAt?->format(DATE_ATOM) ?? '',
            'url' => $this->url,
            'description' => $this->description,
            'image_url' => $this->imageUrl,
            'venue_name' => $this->venueName,
        ];
    }
}
