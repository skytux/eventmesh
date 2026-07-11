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

    public function testRenderMetaBoxPrintsAnInputForEveryKnownProviderPrefilledFromMeta(): void
    {
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key) => '_eventmesh_provider_spotify' === $key
                ? 'https://open.spotify.com/artist/abc'
                : ''
        );

        ob_start();
        (new EventPostType())->renderMetaBox($this->post());
        $html = (string) ob_get_clean();

        self::assertStringContainsString('name="eventmesh_provider_spotify"', $html);
        self::assertStringContainsString('name="eventmesh_provider_mixcloud"', $html);
        self::assertStringContainsString('value="https://open.spotify.com/artist/abc"', $html);
    }

    public function testSaveMetaBoxPersistsSubmittedProviderUrls(): void
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
            'eventmesh_provider_spotify' => 'https://open.spotify.com/artist/abc',
            'eventmesh_provider_mixcloud' => '',
        ];

        (new EventPostType())->saveMetaBox(42, $this->post());

        self::assertSame(
            'https://open.spotify.com/artist/abc',
            $metaWrites['_eventmesh_provider_spotify'] ?? null
        );
        self::assertSame('', $metaWrites['_eventmesh_provider_mixcloud'] ?? null);
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
            'eventmesh_provider_spotify' => 'https://open.spotify.com/track/abc',
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
