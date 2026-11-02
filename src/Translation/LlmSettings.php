<?php

declare(strict_types=1);

namespace EventMesh\Translation;

/**
 * Settings for automatic translation: the on/off switch, the LLM server
 * (an OpenAI-compatible chat API: base URL, model, API key) and the target
 * languages. All of it lives in the database; the plugin ships no default
 * server, model or key.
 *
 * The API key is stored encrypted (sodium secretbox, the random nonce
 * prepended, base64) under a key derived from the site's auth salt, in an
 * option that is not autoloaded. If the salts change, the stored key can no
 * longer be decrypted: it then counts as missing and wp-admin asks for it
 * again.
 *
 * Static so a one-off script can set the server up after loading WordPress:
 *
 *     require 'wp-load.php';
 *     \EventMesh\Translation\LlmSettings::save('https://llm.example/v1', 'model-name', 'secret');
 */
final class LlmSettings
{
    public const OPTION_ENABLED = 'eventmesh_translation_enabled';
    public const OPTION_URL = 'eventmesh_llm_url';
    public const OPTION_MODEL = 'eventmesh_llm_model';
    public const OPTION_KEY = 'eventmesh_llm_key';
    public const OPTION_LANGUAGES = 'eventmesh_translation_languages';

    /**
     * Every option this feature writes, for the factory reset and uninstall.
     */
    public const OPTIONS = [
        self::OPTION_ENABLED,
        self::OPTION_URL,
        self::OPTION_MODEL,
        self::OPTION_KEY,
        self::OPTION_LANGUAGES,
        TranslationQueue::OPTION_QUEUE,
        TranslationQueue::OPTION_STATUS,
    ];

    /**
     * Mixed into the hash that derives the encryption key, so the derived key
     * is only ever used for this one purpose.
     */
    private const KEY_CONTEXT = 'eventmesh|llm-api-key|v1|';

    /**
     * Saves the server URL, the model and the API key in one call. An empty
     * $key keeps the key that is already saved.
     */
    public static function save(string $url, string $model, string $key): void
    {
        update_option(self::OPTION_URL, self::sanitizeUrl($url), false);
        update_option(self::OPTION_MODEL, trim($model), false);

        if ('' !== trim($key)) {
            self::saveKey($key);
        }
    }

    public static function saveKey(string $key): void
    {
        update_option(self::OPTION_KEY, self::encrypt(trim($key)), false);
    }

    public static function removeKey(): void
    {
        delete_option(self::OPTION_KEY);
    }

    public static function url(): string
    {
        return (string) get_option(self::OPTION_URL, '');
    }

    public static function model(): string
    {
        return (string) get_option(self::OPTION_MODEL, '');
    }

    /**
     * The decrypted API key, or '' when none is saved or it cannot be read.
     */
    public static function apiKey(): string
    {
        $stored = (string) get_option(self::OPTION_KEY, '');

        if ('' === $stored) {
            return '';
        }

        return self::decrypt($stored) ?? '';
    }

    public static function hasKey(): bool
    {
        return '' !== self::apiKey();
    }

    /**
     * True when a key is saved but cannot be decrypted (the site's salts
     * changed since it was saved).
     */
    public static function keyUnreadable(): bool
    {
        $stored = (string) get_option(self::OPTION_KEY, '');

        return '' !== $stored && null === self::decrypt($stored);
    }

    public static function isEnabled(): bool
    {
        return '1' === (string) get_option(self::OPTION_ENABLED, '0');
    }

    public static function setEnabled(bool $enabled): void
    {
        update_option(self::OPTION_ENABLED, $enabled ? '1' : '0');
    }

    /**
     * Translation runs only when it is switched on and the URL, model and key
     * are all set.
     */
    public static function isReady(): bool
    {
        return self::isEnabled() && '' !== self::url() && '' !== self::model() && self::hasKey();
    }

    /**
     * The languages events are translated into: the saved choice, or by
     * default every language the site offers.
     *
     * @return array<int, string>
     */
    public static function targetLanguages(): array
    {
        $saved = get_option(self::OPTION_LANGUAGES, null);

        if (! is_array($saved)) {
            return self::siteLanguages();
        }

        return self::normalizeLanguages($saved);
    }

    /**
     * @param array<int, mixed> $languages
     */
    public static function saveTargetLanguages(array $languages): void
    {
        update_option(self::OPTION_LANGUAGES, self::normalizeLanguages($languages), false);
    }

    /**
     * Polylang's languages when Polylang is active, else the site language.
     *
     * @return array<int, string>
     */
    public static function siteLanguages(): array
    {
        if (function_exists('pll_languages_list')) {
            $slugs = pll_languages_list(['fields' => 'slug']);

            if (is_array($slugs) && [] !== $slugs) {
                $languages = self::normalizeLanguages($slugs);

                if ([] !== $languages) {
                    return $languages;
                }
            }
        }

        return self::normalizeLanguages([get_locale()]);
    }

    /**
     * Two-letter lowercase codes, duplicates and junk removed.
     *
     * @param array<int, mixed> $languages
     *
     * @return array<int, string>
     */
    public static function normalizeLanguages(array $languages): array
    {
        $result = [];

        foreach ($languages as $language) {
            $code = self::languageCode((string) $language);

            if ('' !== $code && ! in_array($code, $result, true)) {
                $result[] = $code;
            }
        }

        return $result;
    }

    /**
     * 'fi', 'fi_FI', 'FI' -> 'fi'; anything that does not start with two
     * letters -> ''.
     */
    public static function languageCode(string $value): string
    {
        $value = strtolower(trim($value));

        if (1 !== preg_match('/^([a-z]{2})(?:$|[_-])/', $value, $match)) {
            return '';
        }

        return $match[1];
    }

    public static function sanitizeUrl(string $url): string
    {
        $url = trim($url);

        if (1 !== preg_match('#^https?://[^\s/]+#i', $url)) {
            return '';
        }

        return rtrim(esc_url_raw($url, ['http', 'https']), '/');
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::secretKey()));
    }

    /**
     * Null when the value is not something encrypt() made with the current
     * salts.
     */
    public static function decrypt(string $stored): ?string
    {
        $raw = base64_decode($stored, true);

        if (false === $raw || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        try {
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::secretKey());
        } catch (\SodiumException) {
            return null;
        }

        return false === $plain ? null : $plain;
    }

    private static function secretKey(): string
    {
        return sodium_crypto_generichash(
            self::KEY_CONTEXT . wp_salt('auth'),
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }
}
