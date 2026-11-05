<?php

declare(strict_types=1);

namespace EventMesh\Tests\Support;

use Brain\Monkey\Functions;
use EventMesh\Support\ScheduledJobs;
use EventMesh\Tests\TestCase;

final class ScheduledJobsTest extends TestCase
{
    public function testClearAllRemovesEveryEventMeshHook(): void
    {
        $removed = [];
        Functions\when('wp_unschedule_hook')->alias(
            static function (string $hook) use (&$removed): int {
                $removed[] = $hook;

                return 1;
            }
        );

        ScheduledJobs::clearAll();

        self::assertSame(
            [
                'eventmesh/background_sync',
                'eventmesh/translate_queue',
                'eventmesh/name_queue',
                'eventmesh/infer_queue',
                'eventmesh/dj_descriptions',
            ],
            $removed
        );
    }

    /**
     * Tripwire: every cron hook name declared in src/ (a HOOK / CRON_HOOK
     * constant, or a literal passed to wp_schedule_*) must be in the list
     * deactivation clears, so a new scheduled job cannot be forgotten there.
     */
    public function testEveryHookDeclaredInSrcIsCleared(): void
    {
        $declared = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(EVENTMESH_PLUGIN_DIR . 'src'));

        foreach ($files as $file) {
            if (! $file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            preg_match_all("/const\s+(?:CRON_)?HOOK\s*=\s*'([^']+)'/", $source, $constants);
            preg_match_all("/wp_schedule_(?:single_)?event\([^;]*'(eventmesh\/[^']+)'/s", $source, $literals);
            $declared = array_merge($declared, $constants[1], $literals[1]);
        }

        $declared = array_values(array_unique($declared));

        self::assertNotEmpty($declared, 'The scan found no hooks at all, so it would prove nothing.');
        self::assertSame([], array_values(array_diff($declared, ScheduledJobs::hooks())));
    }
}
