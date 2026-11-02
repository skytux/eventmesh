<?php

declare(strict_types=1);

namespace EventMesh\Tests\Translation;

use Brain\Monkey\Functions;
use EventMesh\Tests\TestCase;
use EventMesh\Translation\LlmSettings;

final class LlmSettingsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, bool|null> */
    private array $autoload = [];

    private string $salt = 'first-salt';

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->autoload = [];
        $this->salt = 'first-salt';

        Functions\when('wp_salt')->alias(fn () => $this->salt);
        Functions\when('get_option')->alias(
            fn (string $name, $default = false) => array_key_exists($name, $this->options) ? $this->options[$name] : $default
        );
        Functions\when('update_option')->alias(
            function (string $name, $value, $autoload = null): bool {
                $this->options[$name] = $value;
                $this->autoload[$name] = $autoload;

                return true;
            }
        );
        Functions\when('delete_option')->alias(
            function (string $name): bool {
                unset($this->options[$name]);

                return true;
            }
        );
        Functions\when('esc_url_raw')->returnArg();
    }

    public function testEncryptThenDecryptGivesBackTheKey(): void
    {
        $stored = LlmSettings::encrypt('sk-test-123');

        self::assertNotSame('sk-test-123', $stored);
        self::assertStringNotContainsString('sk-test-123', (string) base64_decode($stored, true));
        self::assertSame('sk-test-123', LlmSettings::decrypt($stored));
    }

    public function testEachEncryptionUsesAFreshNonce(): void
    {
        self::assertNotSame(LlmSettings::encrypt('same'), LlmSettings::encrypt('same'));
    }

    public function testChangedSaltMakesTheKeyUnreadable(): void
    {
        $stored = LlmSettings::encrypt('sk-test-123');

        $this->salt = 'second-salt';

        self::assertNull(LlmSettings::decrypt($stored));
    }

    public function testTamperedOrGarbageValuesDecryptToNull(): void
    {
        $raw = (string) base64_decode(LlmSettings::encrypt('sk-test-123'), true);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);

        self::assertNull(LlmSettings::decrypt(base64_encode($raw)));
        self::assertNull(LlmSettings::decrypt('not base64 !!'));
        self::assertNull(LlmSettings::decrypt(base64_encode('short')));
        self::assertNull(LlmSettings::decrypt(''));
    }

    public function testSaveStoresEncryptedKeyNotAutoloaded(): void
    {
        LlmSettings::save('https://llm.example/v1/', 'some-model', 'sk-test-123');

        self::assertSame('https://llm.example/v1', $this->options[LlmSettings::OPTION_URL]);
        self::assertSame('some-model', $this->options[LlmSettings::OPTION_MODEL]);
        self::assertNotSame('sk-test-123', $this->options[LlmSettings::OPTION_KEY]);
        self::assertFalse($this->autoload[LlmSettings::OPTION_KEY]);
        self::assertSame('sk-test-123', LlmSettings::apiKey());
    }

    public function testEmptyKeyKeepsTheSavedKey(): void
    {
        LlmSettings::save('https://llm.example/v1', 'some-model', 'sk-test-123');
        LlmSettings::save('https://llm.example/v2', 'other-model', '');

        self::assertSame('sk-test-123', LlmSettings::apiKey());
        self::assertSame('https://llm.example/v2', LlmSettings::url());
    }

    public function testUnreadableKeyCountsAsMissing(): void
    {
        LlmSettings::save('https://llm.example/v1', 'some-model', 'sk-test-123');
        LlmSettings::setEnabled(true);
        self::assertTrue(LlmSettings::isReady());

        $this->salt = 'rotated';

        self::assertSame('', LlmSettings::apiKey());
        self::assertFalse(LlmSettings::hasKey());
        self::assertTrue(LlmSettings::keyUnreadable());
        self::assertFalse(LlmSettings::isReady());
    }

    public function testNotReadyUntilEnabledAndComplete(): void
    {
        self::assertFalse(LlmSettings::isReady());

        LlmSettings::save('https://llm.example/v1', 'some-model', 'sk-test-123');
        self::assertFalse(LlmSettings::isReady(), 'off by default');

        LlmSettings::setEnabled(true);
        self::assertTrue(LlmSettings::isReady());

        LlmSettings::removeKey();
        self::assertFalse(LlmSettings::isReady());
        self::assertFalse(LlmSettings::keyUnreadable());
    }

    public function testRejectsNonHttpUrls(): void
    {
        self::assertSame('', LlmSettings::sanitizeUrl('ftp://llm.example'));
        self::assertSame('', LlmSettings::sanitizeUrl('javascript:alert(1)'));
        self::assertSame('http://llm.example:8080/v1', LlmSettings::sanitizeUrl(' http://llm.example:8080/v1/ '));
    }

    public function testLanguageCodes(): void
    {
        self::assertSame('fi', LlmSettings::languageCode('fi_FI'));
        self::assertSame('en', LlmSettings::languageCode('EN-gb'));
        self::assertSame('sv', LlmSettings::languageCode('sv'));
        self::assertSame('', LlmSettings::languageCode('fin'));
        self::assertSame('', LlmSettings::languageCode(''));
        self::assertSame(['fi', 'en'], LlmSettings::normalizeLanguages(['fi', 'fi_FI', 'en_US', 'x', 'english']));
    }

    public function testTargetsDefaultToTheSiteLanguageWithoutPolylang(): void
    {
        if (function_exists('pll_languages_list')) {
            self::markTestSkipped('Polylang stub defined by another test.');
        }

        Functions\when('get_locale')->justReturn('fi_FI');

        self::assertSame(['fi'], LlmSettings::targetLanguages());

        LlmSettings::saveTargetLanguages(['en', 'sv_SE', 'bogus']);

        self::assertSame(['en', 'sv'], LlmSettings::targetLanguages());
    }
}
