<?php

declare(strict_types=1);

namespace EventMesh\Tests\Djs;

use Brain\Monkey\Functions;
use EventMesh\Admin\DjsPage;
use EventMesh\Djs\Dj;
use EventMesh\Djs\DjDescriptions;
use EventMesh\Djs\DjRepository;
use EventMesh\Djs\ProfileDescriptions;
use EventMesh\Support\Logger;
use EventMesh\Tests\TestCase;

final class DjDescriptionsTest extends TestCase
{
    private const TEAM_PAGE = 50;

    private mixed $savedWpdb = null;

    /**
     * @var array<string, mixed>
     */
    private array $options = [];

    /**
     * @var array<int, string> Every URL asked for.
     */
    private array $fetched = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedWpdb = $GLOBALS['wpdb'];
        $this->options = [
            'eventmesh_dj_db_version' => '2',
            DjDescriptions::TEAM_PAGE_OPTION => self::TEAM_PAGE,
        ];
        $this->fetched = [];

        Functions\when('remove_accents')->alias(static fn (string $text): string => $text);
        Functions\when('absint')->alias(static fn (mixed $value): int => abs((int) $value));
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $name, mixed $value): bool {
                $this->options[$name] = $value;

                return true;
            }
        );
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(true);

        Functions\when('wp_remote_get')->alias(
            function (string $url): array {
                $this->fetched[] = $url;

                return ['code' => 200, 'body' => '{"description":"Profile text.","biog":"Mixcloud text."}'];
            }
        );
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn (array $response): int => $response['code']);
        Functions\when('wp_remote_retrieve_body')->alias(static fn (array $response): string => $response['body']);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->savedWpdb;
        parent::tearDown();
    }

    public function testAManualDescriptionIsNeverReplaced(): void
    {
        $dj = new Dj(1, 'Ana', [], [], 'Typed.', Dj::SOURCE_MANUAL);

        self::assertNull(DjDescriptions::decide($dj, 'Team text.', 'Profile text.', Dj::SOURCE_SOUNDCLOUD));
    }

    public function testTeamTextComesBeforeProfileText(): void
    {
        $dj = new Dj(1, 'Ana', [], [], 'Old.', Dj::SOURCE_SOUNDCLOUD);

        self::assertSame(['Team text.', 'team'], DjDescriptions::decide($dj, 'Team text.', 'Profile text.', Dj::SOURCE_SOUNDCLOUD));
        self::assertSame(['Profile text.', 'mixcloud'], DjDescriptions::decide($dj, '', 'Profile text.', Dj::SOURCE_MIXCLOUD));
    }

    public function testNothingFoundKeepsTheOldText(): void
    {
        $dj = new Dj(1, 'Ana', [], [], 'Old.', Dj::SOURCE_TEAM);

        self::assertNull(DjDescriptions::decide($dj, '', '', Dj::SOURCE_SOUNDCLOUD));
        self::assertNull(DjDescriptions::decide($dj, 'Old.', '', ''));
    }

    public function testShortenCutsAtASentenceOrWord(): void
    {
        $sentence = str_repeat('Word word word word. ', 100);
        $short = DjDescriptions::shorten($sentence);

        self::assertLessThanOrEqual(DjDescriptions::MAX_LENGTH, mb_strlen($short));
        self::assertGreaterThan(DjDescriptions::MAX_LENGTH / 2, mb_strlen($short));
        self::assertStringEndsWith('word.', $short);

        $words = str_repeat('wörd ', 400);
        $cut = DjDescriptions::shorten($words);

        self::assertLessThanOrEqual(DjDescriptions::MAX_LENGTH + 1, mb_strlen($cut));
        self::assertStringEndsWith('wörd…', $cut);

        self::assertSame('Short.', DjDescriptions::shorten('  Short. '));
    }

    public function testRefreshFillsFromTeamPageThenProfileAndLeavesManualAlone(): void
    {
        $this->useDjRows(
            [
                self::row(1, 'Milla Linna', 'https://soundcloud.com/milla', 'Typed by hand.', 'manual'),
                self::row(2, 'Toby One', 'https://soundcloud.com/toby', 'Old profile.', 'soundcloud'),
                self::row(3, 'Ana', 'https://soundcloud.com/ana', '', ''),
                self::row(4, 'Nobody', '', 'Kept.', 'team'),
            ]
        );
        $this->useTeamPage('<h3>Milla Linna</h3><p>Milla team.</p><h3>Toby One</h3><p>Toby team.</p>');

        $result = $this->service()->refresh(null, microtime(true) + 60);

        $rows = $GLOBALS['wpdb']->rows;
        self::assertSame(['Typed by hand.', 'manual'], [$rows[0]['description'], $rows[0]['description_source']]);
        self::assertSame(['Toby team.', 'team'], [$rows[1]['description'], $rows[1]['description_source']]);
        self::assertSame(['Profile text.', 'soundcloud'], [$rows[2]['description'], $rows[2]['description_source']]);
        self::assertSame(['Kept.', 'team'], [$rows[3]['description'], $rows[3]['description_source']]);
        self::assertSame(['updated' => 2, 'left' => 0], $result);

        // One profile fetch, only for the DJ the team page does not name.
        self::assertSame(['https://soundcloud.com/oembed?format=json&url=https%3A%2F%2Fsoundcloud.com%2Fana'], $this->fetched);
    }

    public function testRefreshWithoutDeadlineLeavesProfilesToTheQueue(): void
    {
        $this->useDjRows([self::row(3, 'Ana', 'https://soundcloud.com/ana', '', '')]);
        $this->useTeamPage('');

        $service = $this->service();
        $result = $service->refresh();

        self::assertSame(['updated' => 0, 'left' => 1], $result);
        self::assertSame([], $this->fetched);
        self::assertSame([3], $service->queue());

        $run = $service->runQueue(microtime(true) + 60);

        self::assertSame(['updated' => 1, 'left' => 0], $run);
        self::assertSame([], $service->queue());
        self::assertSame('Profile text.', $GLOBALS['wpdb']->rows[0]['description']);
    }

    public function testSavingTheTeamPageRefreshes(): void
    {
        $this->useDjRows([self::row(2, 'Toby One', '', '', '')]);
        $this->useTeamPage('<h2>Toby One</h2><p>Toby team.</p>');

        $service = $this->service();
        $service->onSavePost(self::TEAM_PAGE + 1);

        self::assertSame('', $GLOBALS['wpdb']->rows[0]['description']);

        $service->onSavePost(self::TEAM_PAGE);

        self::assertSame('Toby team.', $GLOBALS['wpdb']->rows[0]['description']);
    }

    public function testTheFormKeepsAnUnchangedAutomaticTextAndMarksTypedTextManual(): void
    {
        $fromTeam = new Dj(1, 'Ana', [], [], "One.\n\nTwo.", Dj::SOURCE_TEAM);

        self::assertSame(["One.\n\nTwo.", 'team'], DjsPage::descriptionToStore("One.\r\n\r\nTwo.\r\n", $fromTeam));
        self::assertSame(['One. Changed.', 'manual'], DjsPage::descriptionToStore('One. Changed.', $fromTeam));
        self::assertSame(['', ''], DjsPage::descriptionToStore('  ', $fromTeam));
        self::assertSame(['New.', 'manual'], DjsPage::descriptionToStore('New.', null));
    }

    private function service(): DjDescriptions
    {
        $logger = new Logger();

        return new DjDescriptions(new DjRepository(), new ProfileDescriptions($logger), $logger);
    }

    private function useTeamPage(string $content): void
    {
        Functions\when('get_post')->alias(
            static fn (int $id): ?\WP_Post => self::TEAM_PAGE === $id ? new \WP_Post($id, 'page', 'Team', $content) : null
        );
    }

    /**
     * @return array<string, string>
     */
    private static function row(int $id, string $name, string $soundcloud, string $description, string $source): array
    {
        return [
            'id' => (string) $id,
            'name' => $name,
            'aliases' => '',
            'spotify_url' => '',
            'soundcloud_url' => $soundcloud,
            'mixcloud_url' => '',
            'description' => $description,
            'description_source' => $source,
        ];
    }

    /**
     * A $wpdb that serves these rows and applies updates to them.
     *
     * @param array<int, array<string, string>> $rows
     */
    private function useDjRows(array $rows): void
    {
        if (! defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }

        $GLOBALS['wpdb'] = new class ($rows) {
            public string $prefix = 'wp_';

            /**
             * @param array<int, array<string, string>> $rows
             */
            public function __construct(public array $rows)
            {
            }

            /**
             * @return array<int, array<string, string>>
             */
            public function get_results(string $query, string $output): array
            {
                return $this->rows;
            }

            /**
             * @param array<string, string> $data
             * @param array<string, int>    $where
             */
            public function update(string $table, array $data, array $where): int
            {
                foreach ($this->rows as $index => $row) {
                    if ((int) $row['id'] === $where['id']) {
                        $this->rows[$index] = array_merge($row, $data);

                        return 1;
                    }
                }

                return 0;
            }
        };
    }
}
