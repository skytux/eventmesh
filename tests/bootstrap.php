<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

if (! defined('EVENTMESH_PLUGIN_DIR')) {
    define('EVENTMESH_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (! defined('EVENTMESH_VERSION')) {
    define('EVENTMESH_VERSION', '0.0.0-test');
}

if (! defined('EVENTMESH_PLUGIN_URL')) {
    define('EVENTMESH_PLUGIN_URL', 'https://example.test/wp-content/plugins/eventmesh/');
}

if (! defined('DATE_ATOM')) {
    define('DATE_ATOM', 'Y-m-d\TH:i:sP');
}

if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

/**
 * Minimal stand-in for WP_Query, used to unit test code without a WordPress
 * database. Records the args a query was built with and returns a queued
 * result set so tests can assert on both sides without booting WordPress.
 */
if (! class_exists('WP_Query')) {
    class WP_Query
    {
        /**
         * @var array<int, array<int, mixed>>
         */
        public static array $nextResults = [];

        /**
         * Args the most recently constructed instance was built with, for
         * tests that need to assert on the query shape rather than results.
         *
         * @var array<string, mixed>
         */
        public static array $lastArgs = [];

        /**
         * @var array<string, mixed>
         */
        public array $query_vars = [];

        /**
         * @var array<int, mixed>
         */
        public array $posts = [];

        /**
         * @param array<string, mixed> $args
         */
        public function __construct(array $args = [])
        {
            $this->query_vars = $args;
            self::$lastArgs = $args;
            $this->posts = array_shift(self::$nextResults) ?? [];
        }

        public function get(string $key, mixed $default = false): mixed
        {
            return $this->query_vars[$key] ?? $default;
        }
    }
}

/**
 * Minimal stand-in for $wpdb, only covering the bits EventQuery's
 * posts_clauses filter touches (table name properties and prepare()'s %s
 * quoting) - not a general-purpose query builder.
 */
if (! isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public string $posts = 'wp_posts';
        public string $postmeta = 'wp_postmeta';

        public function prepare(string $query, mixed ...$args): string
        {
            foreach ($args as $arg) {
                $query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1);
            }

            return $query;
        }
    };
}

if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public function __construct(
            public int $ID = 0,
            public string $post_type = '',
            public string $post_title = '',
            public string $post_content = '',
            public string $post_excerpt = ''
        ) {
        }
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            private readonly string $code = '',
            private readonly string $message = ''
        ) {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }
    }
}
