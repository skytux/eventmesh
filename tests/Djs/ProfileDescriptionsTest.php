<?php

declare(strict_types=1);

namespace EventMesh\Tests\Djs;

use Brain\Monkey\Functions;
use EventMesh\Djs\Dj;
use EventMesh\Djs\ProfileDescriptions;
use EventMesh\Support\Logger;
use EventMesh\Tests\TestCase;

final class ProfileDescriptionsTest extends TestCase
{
    /**
     * The answer shape SoundCloud's oEmbed endpoint gives for a profile.
     */
    private const SOUNDCLOUD_ANSWER = '{"version":1.0,"type":"rich","provider_name":"SoundCloud",'
        . '"title":"Ana","description":"Ana plays\r\nwarm house.\r\n\r\n\r\nBased in Helsinki.",'
        . '"html":"<iframe></iframe>","author_name":"Ana","author_url":"https://soundcloud.com/ana"}';

    /**
     * The answer shape api.mixcloud.com/<user>/ gives.
     */
    private const MIXCLOUD_ANSWER = '{"key":"/ana/","url":"https://www.mixcloud.com/ana/","name":"Ana",'
        . '"username":"ana","biog":"Mixes for slow mornings.","city":"Helsinki"}';

    public function testParsesTheSoundcloudOembedDescription(): void
    {
        self::assertSame(
            "Ana plays\nwarm house.\n\nBased in Helsinki.",
            ProfileDescriptions::parseSoundcloudOembed(self::SOUNDCLOUD_ANSWER)
        );
    }

    public function testParsesTheMixcloudBiog(): void
    {
        self::assertSame('Mixes for slow mornings.', ProfileDescriptions::parseMixcloudUser(self::MIXCLOUD_ANSWER));
    }

    public function testAnswersWithoutTheFieldGiveNoText(): void
    {
        self::assertSame('', ProfileDescriptions::parseSoundcloudOembed('{"title":"Ana","description":null}'));
        self::assertSame('', ProfileDescriptions::parseSoundcloudOembed('not json'));
        self::assertSame('', ProfileDescriptions::parseMixcloudUser('{"error":{"type":"ResourceNotFoundException"}}'));
    }

    public function testProfileAddresses(): void
    {
        self::assertSame('https://soundcloud.com/ana', ProfileDescriptions::soundcloudProfileUrl('https://soundcloud.com/ana'));
        self::assertSame('https://soundcloud.com/ana', ProfileDescriptions::soundcloudProfileUrl('https://m.soundcloud.com/ana/some-track?si=1'));
        self::assertSame('https://soundcloud.com/ana', ProfileDescriptions::soundcloudProfileUrl('https://soundcloud.com/ana/sets/x'));
        self::assertSame('', ProfileDescriptions::soundcloudProfileUrl('https://on.soundcloud.com/AbCd'));
        self::assertSame('', ProfileDescriptions::soundcloudProfileUrl('https://soundcloud.com/discover/sets/x'));
        self::assertSame('', ProfileDescriptions::soundcloudProfileUrl('https://example.com/ana'));
        self::assertSame('', ProfileDescriptions::soundcloudProfileUrl(''));

        self::assertSame('ana', ProfileDescriptions::mixcloudUsername('https://www.mixcloud.com/ana/'));
        self::assertSame('ana', ProfileDescriptions::mixcloudUsername('https://www.mixcloud.com/ana/my-mix/'));
        self::assertSame('', ProfileDescriptions::mixcloudUsername('https://www.mixcloud.com/live/ana/'));
        self::assertSame('', ProfileDescriptions::mixcloudUsername('https://soundcloud.com/ana'));
    }

    public function testSoundcloudIsAskedBeforeMixcloud(): void
    {
        $both = new Dj(1, 'Ana', [], ['soundcloud' => 'https://soundcloud.com/ana', 'mixcloud' => 'https://www.mixcloud.com/ana/']);
        $mixcloud = new Dj(2, 'Ana', [], ['mixcloud' => 'https://www.mixcloud.com/ana/']);
        $spotify = new Dj(3, 'Ana', [], ['spotify' => 'https://open.spotify.com/artist/x']);

        self::assertSame(['soundcloud', 'https://soundcloud.com/ana'], ProfileDescriptions::target($both));
        self::assertSame(['mixcloud', 'ana'], ProfileDescriptions::target($mixcloud));
        self::assertNull(ProfileDescriptions::target($spotify));
    }

    public function testFetchAsksTheRightEndpoints(): void
    {
        $asked = [];
        $this->stubHttp(
            static function (string $url) use (&$asked): array {
                $asked[] = $url;

                return [
                    'code' => 200,
                    'body' => str_contains($url, 'mixcloud') ? self::MIXCLOUD_ANSWER : self::SOUNDCLOUD_ANSWER,
                ];
            }
        );

        $profiles = new ProfileDescriptions(new Logger());

        self::assertSame(
            "Ana plays\nwarm house.\n\nBased in Helsinki.",
            $profiles->fetch(['soundcloud', 'https://soundcloud.com/ana'])
        );
        self::assertSame('Mixes for slow mornings.', $profiles->fetch(['mixcloud', 'ana']));
        self::assertSame(
            [
                'https://soundcloud.com/oembed?format=json&url=https%3A%2F%2Fsoundcloud.com%2Fana',
                'https://api.mixcloud.com/ana/',
            ],
            $asked
        );
    }

    public function testFailedRequestsAreLoggedAndGiveNoText(): void
    {
        $this->stubHttp(static fn (): array => ['code' => 404, 'body' => '{"error":{}}']);

        $logged = [];
        Functions\when('update_option')->alias(
            static function (string $name, array $logs) use (&$logged): bool {
                $logged = $logs;

                return true;
            }
        );

        self::assertSame('', (new ProfileDescriptions(new Logger()))->fetch(['mixcloud', 'nobody']));
        self::assertStringContainsString('HTTP 404', (string) end($logged)['message']);
    }

    /**
     * @param callable(string): array{code: int, body: string} $answer
     */
    private function stubHttp(callable $answer): void
    {
        Functions\when('wp_remote_get')->alias(static fn (string $url): array => $answer($url));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn (array $response): int => $response['code']);
        Functions\when('wp_remote_retrieve_body')->alias(static fn (array $response): string => $response['body']);
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
    }
}
