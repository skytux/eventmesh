<?php

declare(strict_types=1);

namespace EventMesh\Tests\Translation;

use Brain\Monkey\Functions;
use EventMesh\Tests\TestCase;
use EventMesh\Translation\ContentChunker;
use EventMesh\Translation\LlmClient;
use EventMesh\Translation\OutOfTime;
use EventMesh\Translation\TimeBudget;
use EventMesh\Translation\TranslationRejected;
use EventMesh\Translation\Translator;

/**
 * The HTTP layer is stubbed; replies come from $this->replies in order.
 */
final class TranslatorTest extends TestCase
{
    /** @var array<int, string> */
    private array $replies = [];

    /** @var array<int, string> */
    private array $sent = [];

    /** @var array<string, string> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->replies = [];
        $this->sent = [];
        $this->transients = [];

        Functions\when('wp_json_encode')->alias(static fn ($data) => json_encode($data));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) {
                $body = json_decode($args['body'], true);
                $this->sent[] = (string) end($body['messages'])['content'];

                return ['code' => 200, 'content' => (string) array_shift($this->replies)];
            }
        );
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn ($response) => $response['code']);
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn ($response) => json_encode(['choices' => [['message' => ['content' => $response['content']]]]])
        );
        Functions\when('get_transient')->alias(fn (string $key) => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(
            function (string $key, $value): bool {
                $this->transients[$key] = $value;

                return true;
            }
        );
        Functions\when('wp_strip_all_tags')->alias(static fn (string $text) => strip_tags($text));
    }

    private static function translator(): Translator
    {
        return new Translator(new LlmClient('https://llm.example/v1', 'test-model', 'sk-test'));
    }

    private static function budget(float $seconds = 40.0): TimeBudget
    {
        return new TimeBudget(1000.0 + $seconds, static fn (): float => 1000.0);
    }

    public function testParsesTheLanguageAnswer(): void
    {
        self::assertSame('fi', Translator::parseLanguage('{"lang":"fi"}'));
        self::assertSame('en', Translator::parseLanguage("```json\n{\"lang\": \"EN\"}\n```"));
        self::assertNull(Translator::parseLanguage('{"lang":"fin"}'));
        self::assertNull(Translator::parseLanguage('Finnish'));
        self::assertNull(Translator::parseLanguage('{"language":"fi"}'));
    }

    public function testDetectsTheLanguage(): void
    {
        $this->replies = ['{"lang":"fi"}'];

        self::assertSame('fi', self::translator()->detectLanguage('Tanssi-ilta', '<p>Tervetuloa</p>', self::budget()));
    }

    public function testRejectsAnUnusableLanguageAnswer(): void
    {
        $this->replies = ['I think it is Finnish.'];

        $this->expectException(TranslationRejected::class);
        self::translator()->detectLanguage('Tanssi-ilta', '', self::budget());
    }

    public function testTranslatesAndKeepsSurroundingWhitespace(): void
    {
        $this->replies = ['Dance night'];

        self::assertSame(" Dance night\n", self::translator()->translate(" Tanssi-ilta\n", 'fi', 'en', self::budget()));
        self::assertSame(['Tanssi-ilta'], $this->sent);
    }

    public function testRemovesACodeFence(): void
    {
        $this->replies = ["```html\n<p>Hello</p>\n```"];

        self::assertSame('<p>Hello</p>', self::translator()->translate('<p>Hei</p>', 'fi', 'en', self::budget()));
    }

    public function testRejectsATranslationThatFailsTheCheck(): void
    {
        $this->replies = ['Tickets 20 €'];

        try {
            self::translator()->translate('Liput 15 €', 'fi', 'en', self::budget());
            self::fail('Expected TranslationRejected.');
        } catch (TranslationRejected $exception) {
            self::assertSame('numbers', $exception->kind());
        }

        self::assertSame([], $this->transients, 'a rejected piece is not cached');
    }

    public function testCachedPiecesAreNotSentAgain(): void
    {
        $this->replies = ['Dance night'];
        self::translator()->translate('Tanssi-ilta', 'fi', 'en', self::budget());

        $this->replies = [];
        self::assertSame('Dance night', self::translator()->translate('Tanssi-ilta', 'fi', 'en', self::budget()));
        self::assertCount(1, $this->sent);
    }

    public function testDoesNotStartARequestWithoutTime(): void
    {
        $this->expectException(OutOfTime::class);
        self::translator()->translate('Tanssi-ilta', 'fi', 'en', self::budget(5.0));
    }

    public function testPromptKeepsWhatMustNotChange(): void
    {
        $prompt = Translator::prompt('fi', 'en');

        self::assertStringContainsString('Finnish', $prompt);
        self::assertStringContainsString('English', $prompt);
        self::assertStringContainsString('CANCELED', $prompt);
    }

    public function testChunksJoinBackToTheOriginal(): void
    {
        $block = "<!-- wp:paragraph -->\n<p>" . str_repeat('Sana ', 200) . "</p>\n<!-- /wp:paragraph -->\n\n";
        $content = str_repeat($block, 6);

        $chunks = ContentChunker::split($content, 2500);

        self::assertGreaterThan(1, count($chunks));
        self::assertSame($content, implode('', $chunks));

        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(2500, strlen($chunk));
        }
    }
}
