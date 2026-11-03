<?php

declare(strict_types=1);

namespace EventMesh\Djs;

/**
 * One row of the DJ table: a name, other spellings of that name, and up to
 * one link per player service.
 */
final class Dj
{
    /**
     * The services a DJ row has a link column for. These are the three the
     * event player can show (see ProviderEmbedEnricher).
     */
    public const LINK_KINDS = ['spotify', 'soundcloud', 'mixcloud'];

    /**
     * @param array<int, string>    $aliases Other spellings of the name.
     * @param array<string, string> $links   Service key (one of LINK_KINDS) => URL.
     *                                       Services with no link are left out.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly array $aliases = [],
        public readonly array $links = []
    ) {
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
