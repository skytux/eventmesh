<?php

declare(strict_types=1);

namespace EventMesh\Translation;

/**
 * Talks to an OpenAI-compatible chat API: POST {url}/chat/completions with a
 * Bearer key. Thinking is always switched off and the temperature kept low,
 * since the replies are translations and short JSON answers.
 *
 * Nothing here logs. Errors are thrown as an LlmException whose message is a
 * short kind only, never the key and never any event text.
 */
final class LlmClient
{
    private const TEMPERATURE = 0.1;

    /**
     * Longest single request. Sized for a host with a 60 second PHP limit:
     * the queue runner never starts a request that could end after its own
     * time budget, and passes a shorter timeout when less time is left.
     */
    public const MAX_TIMEOUT = 25;

    public function __construct(
        private readonly string $url,
        private readonly string $model,
        private readonly string $apiKey
    ) {
    }

    public static function fromSettings(): self
    {
        return new self(LlmSettings::url(), LlmSettings::model(), LlmSettings::apiKey());
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Sends one chat request and returns the reply text.
     *
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @throws LlmException
     */
    public function chat(array $messages, int $timeout = self::MAX_TIMEOUT, ?int $maxTokens = null): string
    {
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => self::TEMPERATURE,
            'stream' => false,
            'chat_template_kwargs' => ['enable_thinking' => false],
        ];

        if (null !== $maxTokens) {
            $body['max_tokens'] = $maxTokens;
        }

        $response = wp_remote_post(
            rtrim($this->url, '/') . '/chat/completions',
            [
                'timeout' => max(1, min(self::MAX_TIMEOUT, $timeout)),
                'redirection' => 0,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'body' => (string) wp_json_encode($body),
                'data_format' => 'body',
            ]
        );

        if (is_wp_error($response)) {
            throw new LlmException('network');
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status < 200 || $status >= 300) {
            throw new LlmException('http_' . $status);
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $content = is_array($decoded) ? ($decoded['choices'][0]['message']['content'] ?? null) : null;

        if (! is_string($content)) {
            throw new LlmException('bad_response');
        }

        // Thinking is switched off in the request, but a server that ignores
        // that may still put a reasoning block in front of the answer.
        $content = (string) preg_replace('#^\s*<think>.*?</think>#s', '', $content);

        if ('' === trim($content)) {
            throw new LlmException('empty');
        }

        return $content;
    }
}
