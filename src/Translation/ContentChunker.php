<?php

declare(strict_types=1);

namespace EventMesh\Translation;

/**
 * Splits long event content into pieces that are translated one request at
 * a time, so a long description neither runs into the model's output limit
 * nor one slow request. Splits only between blocks (before a top-level
 * <!-- wp: --> opener) and between paragraphs (after a closing block-level
 * tag, or at a blank line), never inside a tag. Joining the pieces gives back
 * exactly the original text.
 */
final class ContentChunker
{
    public const MAX_CHUNK = 2500;

    /**
     * @return array<int, string>
     */
    public static function split(string $content, int $maxLength = self::MAX_CHUNK): array
    {
        if (strlen($content) <= $maxLength) {
            return [$content];
        }

        $pieces = self::pieces($content);
        $chunks = [];
        $current = '';

        foreach ($pieces as $piece) {
            if ('' !== $current && strlen($current) + strlen($piece) > $maxLength) {
                $chunks[] = $current;
                $current = '';
            }

            $current .= $piece;
        }

        if ('' !== $current) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * The smallest pieces the content may be cut into.
     *
     * @return array<int, string>
     */
    private static function pieces(string $content): array
    {
        $pattern = '#(?<=</p>|</ul>|</ol>|</h1>|</h2>|</h3>|</h4>|</h5>|</h6>|</blockquote>|</figure>|</div>|\n\n)'
            . '|(?=<!--\s*wp:)#i';

        $parts = preg_split($pattern, $content, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($parts) || [] === $parts) {
            return [$content];
        }

        // A piece may open a tag that a later piece closes. Each translated
        // piece is checked against its own original piece, so the markup
        // still has to come back unchanged; only the joined result is saved.
        return $parts;
    }
}
