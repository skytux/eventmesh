<?php

declare(strict_types=1);

namespace EventMesh\Djs;

/**
 * One row of the DJ table: a name, other spellings of that name, up to one
 * link per player service, and a short English description of the DJ with
 * where that description came from (see DjDescriptions).
 */
final class Dj
{
    /**
     * The services a DJ row has a link column for. These are the three the
     * event player can show (see ProviderEmbedEnricher).
     */
    public const LINK_KINDS = ['spotify', 'soundcloud', 'mixcloud'];

    /**
     * Where a description came from. SOURCE_NONE: there is no description,
     * or it was emptied by hand and the automatic sources may fill it again.
     */
    public const SOURCE_NONE = '';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_TEAM = 'team';
    public const SOURCE_SOUNDCLOUD = 'soundcloud';
    public const SOURCE_MIXCLOUD = 'mixcloud';

    /**
     * @param array<int, string>    $aliases           Other spellings of the name.
     * @param array<string, string> $links             Service key (one of LINK_KINDS) => URL.
     *                                                 Services with no link are left out.
     * @param string                $description       Plain text, paragraphs separated by "\n\n".
     * @param string                $descriptionSource One of the SOURCE_* constants.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly array $aliases = [],
        public readonly array $links = [],
        public readonly string $description = '',
        public readonly string $descriptionSource = self::SOURCE_NONE
    ) {
    }

    /**
     * True when an organizer typed the description; automatic refreshes
     * leave it alone.
     */
    public function hasManualDescription(): bool
    {
        return self::SOURCE_MANUAL === $this->descriptionSource;
    }

    /**
     * The name followed by every alias, empty ones dropped.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        $names = array_map('trim', array_merge([$this->name], $this->aliases));

        return array_values(array_filter($names, static fn (string $name): bool => '' !== $name));
    }

    public function link(string $kind): string
    {
        return trim((string) ($this->links[$kind] ?? ''));
    }

    /**
     * Splits the aliases text from the admin form (one per line) into a list.
     *
     * @return array<int, string>
     */
    public static function parseAliases(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $aliases = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ('' !== $line && ! in_array($line, $aliases, true)) {
                $aliases[] = $line;
            }
        }

        return $aliases;
    }
}
