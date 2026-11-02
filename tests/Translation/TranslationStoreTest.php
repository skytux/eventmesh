<?php

declare(strict_types=1);

namespace EventMesh\Tests\Translation;

use EventMesh\Tests\TestCase;
use EventMesh\Translation\TranslationStore;

final class TranslationStoreTest extends TestCase
{
    /**
     * @return array{hash: string, status: string, title: string, content: string}
     */
    private static function translation(string $hash, string $status, string $title = 'Title'): array
    {
        return ['hash' => $hash, 'status' => $status, 'title' => $title, 'content' => 'Text'];
    }

    public function testHashChangesWithTitleOrContent(): void
    {
        $hash = TranslationStore::hashOf('Title', 'Text');

        self::assertSame($hash, TranslationStore::hashOf('Title', 'Text'));
        self::assertNotSame($hash, TranslationStore::hashOf('Title!', 'Text'));
        self::assertNotSame($hash, TranslationStore::hashOf('Title', 'Text!'));
        self::assertNotSame(TranslationStore::hashOf('ab', 'c'), TranslationStore::hashOf('a', 'bc'));
    }

    public function testCurrentTranslationIsSkipped(): void
    {
        self::assertSame('current', TranslationStore::decide(self::translation('h1', 'auto'), null, 'h1'));
    }

    public function testChangedOriginalIsTranslatedAgain(): void
    {
        self::assertSame('translate', TranslationStore::decide(self::translation('h0', 'auto'), null, 'h1'));
        self::assertSame('translate', TranslationStore::decide(self::translation('', '', ''), null, 'h1'));
    }

    public function testEditedTranslationIsNeverOverwritten(): void
    {
        self::assertSame('edited', TranslationStore::decide(self::translation('h0', 'edited'), null, 'h1'));
        self::assertSame('edited', TranslationStore::decide(self::translation('h1', 'edited'), null, 'h1'));
    }

    public function testGivesUpAfterThreeFailuresOnTheSameOriginal(): void
    {
        $empty = self::translation('', '', '');

        self::assertSame('translate', TranslationStore::decide($empty, ['hash' => 'h1', 'count' => 2], 'h1'));
        self::assertSame('gave_up', TranslationStore::decide($empty, ['hash' => 'h1', 'count' => 3], 'h1'));
        // A new original starts the count again.
        self::assertSame('translate', TranslationStore::decide($empty, ['hash' => 'h0', 'count' => 3], 'h1'));
    }

    public function testAttemptsCountOnlyForTheSameOriginal(): void
    {
        self::assertSame(0, TranslationStore::attemptsFor(null, 'h1'));
        self::assertSame(2, TranslationStore::attemptsFor(['hash' => 'h1', 'count' => 2], 'h1'));
        self::assertSame(0, TranslationStore::attemptsFor(['hash' => 'h0', 'count' => 2], 'h1'));
    }

    public function testOriginalLanguageIsNeverATarget(): void
    {
        self::assertSame(['en', 'sv'], TranslationStore::targetsFor(['fi', 'en', 'sv'], 'fi'));
        self::assertSame([], TranslationStore::targetsFor(['fi'], 'fi'));
    }

    public function testMetaKeys(): void
    {
        self::assertSame('_eventmesh_tr_en_title', TranslationStore::metaKey('en', 'title'));
        self::assertSame('_eventmesh_tr_en_fail', TranslationStore::failureKey('en'));
    }
}
