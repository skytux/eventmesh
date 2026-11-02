<?php

declare(strict_types=1);

namespace EventMesh\Tests\Translation;

use Brain\Monkey\Functions;
use EventMesh\Tests\TestCase;
use EventMesh\Translation\LlmClient;
use EventMesh\Translation\LlmException;

/**
 * wp_remote_post is always stubbed: these tests never reach a server.
 */
final class LlmClientTest extends TestCase
{
    /** @var array<int, array{url: string, args: array<string, mixed>}> */
    private array $requests = [];

    /** @var mixed */
    private $response;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requests = [];
        $this->response = self::reply('Hello');

        Functions\when('wp_json_encode')->alias(static fn ($data) => json_encode($data));
        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) {
                $this->requests[] = ['url' => $url, 'args' => $args];

                return $this->response;
            }
        );
        Functions\when('is_wp_error')->alias(static fn ($value) => $value instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn ($response) => $response['response']['code'] ?? ''
        );
        Functions\when('wp_remote_retrieve_body')->alias(static fn ($response) => $response['body'] ?? '');
    }

    /**
     * @return array{response: array{code: int}, body: string}
     */
    private static function reply(string $content, int $code = 200): array
    {
        return [
            'response' => ['code' => $code],
            'body' => (string) json_encode(['choices' => [['message' => ['content' => $content]]]]),
        ];
    }

    private static function client(): LlmClient
    {
        return new LlmClient('https://llm.example/v1/', 'test-model', 'sk-test');
    }

    private function expectKind(string $kind): void
    {
        try {
            self::client()->chat([['role' => 'user', 'content' => 'Hei']]);
            self::fail('Expected an LlmException.');
        } catch (LlmException $exception) {
            self::assertSame($kind, $exception->kind());
        }
    }

    public function testSendsTheRequestTheServerExpects(): void
    {
        $reply = self::client()->chat([['role' => 'user', 'content' => 'Hei']], 99, 5);

        self::assertSame('Hello', $reply);
        self::assertCount(1, $this->requests);

        $request = $this->requests[0];
        self::assertSame('https://llm.example/v1/chat/completions', $request['url']);
        self::assertSame('Bearer sk-test', $request['args']['headers']['Authorization']);
        self::assertSame(LlmClient::MAX_TIMEOUT, $request['args']['timeout'], 'timeout is capped');
        self::assertSame(0, $request['args']['redirection']);

        $body = json_decode($request['args']['body'], true);
        self::assertSame('test-model', $body['model']);
        self::assertSame(['enable_thinking' => false], $body['chat_template_kwargs']);
        self::assertLessThanOrEqual(0.2, $body['temperature']);
        self::assertFalse($body['stream']);
        self::assertSame(5, $body['max_tokens']);
        self::assertSame([['role' => 'user', 'content' => 'Hei']], $body['messages']);
    }

    public function testLeavesMaxTokensOutUnlessGiven(): void
    {
        self::client()->chat([['role' => 'user', 'content' => 'Hei']], 10);

        $body = json_decode($this->requests[0]['args']['body'], true);
        self::assertArrayNotHasKey('max_tokens', $body);
        self::assertSame(10, $this->requests[0]['args']['timeout']);
    }

    public function testNetworkError(): void
    {
        $this->response = new \WP_Error('http_request_failed', 'timed out');

        $this->expectKind('network');
    }

    public function testHttpErrorStatus(): void
    {
        $this->response = ['response' => ['code' => 401], 'body' => '{"error":"bad key"}'];

        $this->expectKind('http_401');
    }

    public function testUnexpectedBody(): void
    {
        $this->response = ['response' => ['code' => 200], 'body' => 'not json'];

        $this->expectKind('bad_response');
    }

    public function testEmptyAnswer(): void
    {
        $this->response = self::reply("  \n");

        $this->expectKind('empty');
    }

    public function testStripsALeadingThinkBlock(): void
    {
        $this->response = self::reply("<think>\nreasoning\n</think>\n\nHello");

        self::assertSame("\n\nHello", self::client()->chat([['role' => 'user', 'content' => 'Hei']]));
    }

    public function testErrorKindsCarryNoKeyOrText(): void
    {
        $this->response = ['response' => ['code' => 500], 'body' => 'sk-test Hei'];

        try {
            self::client()->chat([['role' => 'user', 'content' => 'Hei']]);
            self::fail('Expected an LlmException.');
        } catch (LlmException $exception) {
            self::assertSame('http_500', $exception->getMessage());
            self::assertTrue($exception->isTransport());
        }
    }
}
