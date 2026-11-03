<?php

declare(strict_types=1);

namespace EventMesh\Tests\Djs;

use Brain\Monkey\Functions;
use EventMesh\Djs\Dj;
use EventMesh\Djs\TeamPageParser;
use EventMesh\Tests\TestCase;

final class TeamPageParserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('remove_accents')->alias(
            static fn (string $text): string => strtr($text, ['ä' => 'a', 'Ä' => 'A', 'é' => 'e'])
        );
    }

    /**
     * The shape of the real team page, in block editor markup.
     */
    private static function page(): string
    {
        return <<<'HTML'
<!-- wp:heading -->
<h2 class="wp-block-heading">About our team</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>We are a small crew.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Main organizers</h2>
<!-- /wp:heading -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Milla Linna</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Milla plays <strong>deep</strong> organic house.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>She also&nbsp;runs the   sound &amp; light.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Regular DJs</h2>
<!-- /wp:heading -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Ihmeidentekijä</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Slow builds,<br>big skies.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Toby One</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Toby brings the drums.</p>
<!-- /wp:paragraph -->
HTML;
    }

    public function testEachMatchingHeadingGivesItsParagraphsUpToTheNextHeading(): void
    {
        $djs = [
            new Dj(1, 'Milla Linna'),
            new Dj(2, 'Ihmeidentekija'),
            new Dj(3, 'Toby One'),
        ];

        self::assertSame(
            [
                1 => "Milla plays deep organic house.\n\nShe also runs the sound & light.",
                2 => 'Slow builds, big skies.',
                3 => 'Toby brings the drums.',
            ],
            TeamPageParser::parse($djs, self::page())
        );
    }

    public function testOtherSpellingsMatchAndCaseDoesNotMatter(): void
    {
        $djs = [new Dj(5, 'T1', ['TOBY ONE'])];

        self::assertSame([5 => 'Toby brings the drums.'], TeamPageParser::parse($djs, self::page()));
    }

    public function testHeadingsThatNameNoDjAreIgnored(): void
    {
        $djs = [new Dj(1, 'Nobody'), new Dj(2, 'Our team')];

        self::assertSame([], TeamPageParser::parse($djs, self::page()));
    }

    public function testTheWholeHeadingMustBeTheName(): void
    {
        $content = '<h2>Milla Linna &amp; Ty Alexander</h2><p>Together.</p><h2>Milla</h2><p>Alone.</p>';

        self::assertSame(
            [],
            TeamPageParser::parse([new Dj(1, 'Milla Linna'), new Dj(2, 'Ty Alexander')], $content)
        );
        self::assertSame(
            [3 => 'Together.'],
            TeamPageParser::parse([new Dj(3, 'Milla Linna & Ty Alexander')], $content)
        );
    }

    public function testOnlyH2ToH4AreDjHeadingsButEveryHeadingEndsASection(): void
    {
        $content = '<h1>Toby One</h1><p>Title text.</p>'
            . '<h4>Toby One</h4><p>Right.</p><h5>Gear</h5><p>Not about Toby.</p>';

        self::assertSame([3 => 'Right.'], TeamPageParser::parse([new Dj(3, 'Toby One')], $content));
    }

    public function testAHeadingWithNoParagraphsGivesNothing(): void
    {
        $content = '<h3>Toby One</h3><h3>Milla Linna</h3><p>Milla.</p>';

        self::assertSame(
            [2 => 'Milla.'],
            TeamPageParser::parse([new Dj(1, 'Toby One'), new Dj(2, 'Milla Linna')], $content)
        );
    }

    public function testClassicTextWithoutParagraphTagsIsSplitOnBlankLines(): void
    {
        $content = "<h2>Toby One</h2>\nFirst line.\n\nSecond line.\n";

        self::assertSame(
            [1 => "First line.\n\nSecond line."],
            TeamPageParser::parse([new Dj(1, 'Toby One')], $content)
        );
    }
}
