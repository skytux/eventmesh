<?php

declare(strict_types=1);

namespace EventMesh\Tests\Content;

use Brain\Monkey\Functions;
use EventMesh\Content\EventPostType;
use EventMesh\Services\ProviderEmbedEnricher;
use EventMesh\Support\Logger;
use EventMesh\Tests\TestCase;

final class EventPostTypeMetaBoxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_html')->alias(static fn ($value) => $value);
        Functions\when('esc_attr')->alias(static fn ($value) => $value);
        Functions\when('esc_url')->alias(static fn ($value) => $value);
        Functions\when('wp_nonce_field')->justReturn('');
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_kses')->returnArg(1);
        Functions\when('checked')->alias(
            static fn ($a, $b = true, $echo = true) => (string) $a === (string) $b ? ' checked="checked"' : ''
        );
    }

    protected function tearDown(): void
    {
        unset($_POST);
        parent::tearDown();
    }

    private function post(): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = 42;
        $post->post_type = EventPostType::NAME;

        return $post;
    }

    public function testRenderMetaBoxShowsFoundValuesReadOnlyWithEditableManualOverrides(): void
    {
        Functions\when('selected')->alias(
            static fn ($a, $b, $echo = true) => (string) $a === (string) $b ? ' selected="selected"' : ''
        );
        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key = '', bool $single = false) {
                return match ($key) {
                    '_eventmesh_provider_spotify' => 'https://open.spotify.com/artist/abc',
                    '_eventmesh_price' => '€39',
                    '_eventmesh_manual_price' => '€25',
                    default => '',
                };
            }
        );

        ob_start();
        (new EventPostType())->renderMetaBox($this->post());
        $html = (string) ob_get_clean();

        // Editable manual inputs, not the scraped meta key.
        self::assertStringContainsString('name="eventmesh_manual_provider_spotify"', $html);
        self::assertStringContainsString('name="eventmesh_manual_price"', $html);
        self::assertStringContainsString('name="eventmesh_manual_venue_name"', $html);
        self::assertStringContainsString('name="eventmesh_manual_sold_out"', $html);
        // The manual override prefills the input; the found value shows read-only.
        self::assertStringContainsString('value="€25"', $html);
        self::assertStringContainsString('Found: €39', $html);
        // The scraped provider value is shown as the found value, not in the input.
        self::assertStringContainsString('Found: https://open.spotify.com/artist/abc', $html);
    }

    public function testSaveMetaBoxPersistsManualOverridesNotTheScrapedMeta(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);
        Functions\when('wp_unslash')->alias(static fn ($value) => $value);
        Functions\when('esc_url_raw')->alias(static fn ($value) => $value);

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$metaWrites) {
                $metaWrites[$key] = $value;

                return true;
            }
        );

        $_POST = [
            'eventmesh_providers_nonce' => 'a-valid-nonce',
            'eventmesh_manual_provider_spotify' => 'https://open.spotify.com/artist/abc',
            'eventmesh_manual_price' => '€25',
            'eventmesh_manual_sold_out' => '1',
        ];

        (new EventPostType())->saveMetaBox(42, $this->post());

        self::assertSame('https://open.spotify.com/artist/abc', $metaWrites['_eventmesh_manual_provider_spotify'] ?? null);
        self::assertSame('€25', $metaWrites['_eventmesh_manual_price'] ?? null);
        self::assertSame('1', $metaWrites['_eventmesh_manual_sold_out'] ?? null);
        self::assertArrayNotHasKey(
            '_eventmesh_provider_spotify',
            $metaWrites,
            'The edit screen writes only manual overrides; the scraped meta is the sync\'s to own.'
        );
    }

    public function testRenderMetaBoxOffersFollowSourceAgainForTitleAndDescription(): void
    {
        Functions\when('selected')->alias(static fn ($a, $b, $echo = true) => '');
        Functions\when('has_post_thumbnail')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');

        ob_start();
        (new EventPostType())->renderMetaBox($this->post());
        $html = (string) ob_get_clean();

        self::assertStringContainsString('name="eventmesh_reset_title"', $html);
        self::assertStringContainsString('name="eventmesh_reset_content"', $html);
    }

    public function testRenderMetaBoxOffersHideAndDisableCheckboxes(): void
    {
        Functions\when('selected')->alias(static fn ($a, $b, $echo = true) => '');
        Functions\when('has_post_thumbnail')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');

        ob_start();
        (new EventPostType())->renderMetaBox($this->post());
        $html = (string) ob_get_clean();

        self::assertStringContainsString('name="eventmesh_manual_hidden"', $html);
        self::assertStringContainsString('name="eventmesh_manual_disabled"', $html);
    }

    public function testSaveMetaBoxStoresHiddenAsOneAndUntickedDisabledAsEmpty(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);
        Functions\when('wp_unslash')->alias(static fn ($value) => $value);
        Functions\when('esc_url_raw')->alias(static fn ($value) => $value);

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$metaWrites) {
                $metaWrites[$key] = $value;

                return true;
            }
        );

        // "Hide" ticked, "Disable" left unticked (absent from the POST).
        $_POST = [
            'eventmesh_providers_nonce' => 'ok',
            'eventmesh_manual_hidden' => '1',
        ];

        (new EventPostType())->saveMetaBox(42, $this->post());

        self::assertSame('1', $metaWrites['_eventmesh_manual_hidden'] ?? null);
        self::assertSame(
            '',
            $metaWrites['_eventmesh_manual_disabled'] ?? null,
            'An unticked checkbox must be stored as empty, not left at a stale "1".'
        );
    }

    public function testBlockDisabledEventForcesA404OnADisabledEventsPage(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(42);
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key = '', bool $single = false) => '_eventmesh_manual_disabled' === $key
                ? '1'
                : ''
        );
        Functions\when('status_header')->justReturn(null);
        Functions\when('nocache_headers')->justReturn(null);

        $GLOBALS['wp_query'] = new \WP_Query([]);

        (new EventPostType())->blockDisabledEvent();

        self::assertTrue($GLOBALS['wp_query']->is_404, 'A disabled event page must be turned into a 404.');
    }

    public function testBlockDisabledEventLeavesAnUndisabledEventAlone(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(42);
        Functions\when('get_post_meta')->justReturn('');

        $GLOBALS['wp_query'] = new \WP_Query([]);

        (new EventPostType())->blockDisabledEvent();

        self::assertFalse($GLOBALS['wp_query']->is_404, 'A normal (non-disabled) event page must render normally.');
    }

    public function testSaveMetaBoxRevertsTitleToTheSourceWhenFollowSourceAgainIsTicked(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);
        Functions\when('wp_unslash')->alias(static fn ($value) => $value);
        Functions\when('esc_url_raw')->alias(static fn ($value) => $value);
        Functions\when('wp_trim_words')->alias(static fn ($text) => $text);
        Functions\when('remove_action')->justReturn(true);
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_post')->justReturn(null);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key = '', bool $single = false) => '_eventmesh_source_title' === $key
                ? 'The Source Title'
                : ''
        );

        $updated = [];
        Functions\when('wp_update_post')->alias(
            static function (array $postarr) use (&$updated) {
                $updated = $postarr;

                return (int) $postarr['ID'];
            }
        );

        $_POST = [
            'eventmesh_providers_nonce' => 'ok',
            'eventmesh_reset_title' => '1',
        ];

        (new EventPostType())->saveMetaBox(42, $this->post());

        self::assertSame('The Source Title', $updated['post_title'] ?? null);
        self::assertArrayNotHasKey(
            'post_content',
            $updated,
            'Only the ticked field (title) should be reverted; the description was not ticked.'
        );
    }

    public function testSaveMetaBoxRejectsAnInvalidSoldOutValueAsFollowTheSource(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);
        Functions\when('wp_unslash')->alias(static fn ($value) => $value);
        Functions\when('esc_url_raw')->alias(static fn ($value) => $value);

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$metaWrites) {
                $metaWrites[$key] = $value;

                return true;
            }
        );

        $_POST = ['eventmesh_providers_nonce' => 'ok', 'eventmesh_manual_sold_out' => 'garbage'];

        (new EventPostType())->saveMetaBox(42, $this->post());

        self::assertSame('', $metaWrites['_eventmesh_manual_sold_out'] ?? null);
    }

    public function testSaveMetaBoxNormalizesAManualDateToDateAtom(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);
        Functions\when('wp_unslash')->alias(static fn ($value) => $value);
        Functions\when('esc_url_raw')->alias(static fn ($value) => $value);

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$metaWrites) {
                $metaWrites[$key] = $value;

                return true;
            }
        );

        $_POST = ['eventmesh_providers_nonce' => 'ok', 'eventmesh_manual_starts_at' => '2026-06-13T18:00'];

        (new EventPostType())->saveMetaBox(42, $this->post());

        self::assertStringStartsWith('2026-06-13T18:00:00', (string) ($metaWrites['_eventmesh_manual_starts_at'] ?? ''));
    }

    public function testSaveMetaBoxDoesNothingWithoutAValidNonce(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(false);
        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);
        Functions\when('wp_unslash')->alias(static fn ($value) => $value);

        $wrote = false;
        Functions\when('update_post_meta')->alias(
            static function () use (&$wrote) {
                $wrote = true;

                return true;
            }
        );

        $_POST = [
            'eventmesh_providers_nonce' => 'not-valid',
            'eventmesh_provider_spotify' => 'https://open.spotify.com/artist/abc',
        ];

        (new EventPostType())->saveMetaBox(42, $this->post());

        self::assertFalse($wrote);
    }

    public function testSaveMetaBoxTriggersProviderEmbedEnrichmentAfterSaving(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);
        Functions\when('wp_unslash')->alias(static fn ($value) => $value);
        Functions\when('esc_url_raw')->alias(static fn ($value) => $value);
        Functions\when('is_wp_error')->alias(static fn ($thing) => $thing instanceof \WP_Error);
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);

        $store = [];
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$store) {
                $store[$key] = $value;

                return true;
            }
        );
        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key = '', bool $single = false) use (&$store) {
                return $store[$key] ?? '';
            }
        );

        $requested = [];
        Functions\when('wp_remote_get')->alias(
            static function (string $url) use (&$requested) {
                $requested[] = $url;

                return [
                    '__body' => json_encode(
                        ['html' => '<iframe src="https://open.spotify.com/embed/track/abc"></iframe>']
                    ),
                ];
            }
        );
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(static fn ($r) => $r['__body'] ?? '');

        $_POST = [
            'eventmesh_providers_nonce' => 'a-valid-nonce',
            'eventmesh_manual_provider_spotify' => 'https://open.spotify.com/track/abc',
        ];

        $postType = new EventPostType(new ProviderEmbedEnricher(new Logger()));
        $postType->saveMetaBox(42, $this->post());

        self::assertCount(
            1,
            $requested,
            'Saving a manually-entered provider link should immediately resolve its embed, not wait for the next sync.'
        );
        self::assertStringContainsString('open.spotify.com/oembed', $requested[0]);
    }
}
