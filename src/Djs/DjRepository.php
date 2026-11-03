<?php

declare(strict_types=1);

namespace EventMesh\Djs;

/**
 * Reads and writes rows of the DJ table (see DjTable).
 *
 * The whole list is read once per request and kept, because a sync checks
 * every event against every DJ.
 */
final class DjRepository
{
    /**
     * @var array<int, Dj>|null
     */
    private ?array $cache = null;

    /**
     * Every DJ, sorted by name. Empty when the table does not exist yet.
     *
     * @return array<int, Dj>
     */
    public function all(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        if (! DjTable::isInstalled()) {
            return [];
        }

        global $wpdb;

        $table = DjTable::name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table, no user input in the query.
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY name ASC, id ASC", ARRAY_A);

        $this->cache = array_map([$this, 'fromRow'], is_array($rows) ? $rows : []);

        return $this->cache;
    }

    public function find(int $id): ?Dj
    {
        foreach ($this->all() as $dj) {
            if ($dj->id === $id) {
                return $dj;
            }
        }

        return null;
    }

    /**
     * Inserts the DJ when its id is 0, otherwise updates that row.
     *
     * @return int The row id, or 0 when the write failed.
     */
    public function save(Dj $dj): int
    {
        global $wpdb;

        $data = [
            'name' => $dj->name,
            'aliases' => implode("\n", $dj->aliases),
            'spotify_url' => $dj->link('spotify'),
            'soundcloud_url' => $dj->link('soundcloud'),
            'mixcloud_url' => $dj->link('mixcloud'),
            'description' => $dj->description,
            'description_source' => $dj->descriptionSource,
        ];

        $this->cache = null;

        if ($dj->id > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
            $result = $wpdb->update(DjTable::name(), $data, ['id' => $dj->id]);

            return false === $result ? 0 : $dj->id;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
        $result = $wpdb->insert(DjTable::name(), $data);

        return false === $result ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Writes only the description and its source of one DJ, so an automatic
     * refresh cannot change a name or link typed in the meantime.
     */
    public function saveDescription(int $id, string $description, string $source): bool
    {
        global $wpdb;

        $this->cache = null;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
        $result = $wpdb->update(
            DjTable::name(),
            ['description' => $description, 'description_source' => $source],
            ['id' => $id]
        );

        return false !== $result;
    }

    public function delete(int $id): bool
    {
        global $wpdb;

        $this->cache = null;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
        return false !== $wpdb->delete(DjTable::name(), ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): Dj
    {
        $links = [];

        foreach (Dj::LINK_KINDS as $kind) {
            $url = trim((string) ($row[$kind . '_url'] ?? ''));

            if ('' !== $url) {
                $links[$kind] = $url;
            }
        }

        return new Dj(
            (int) ($row['id'] ?? 0),
            (string) ($row['name'] ?? ''),
            Dj::parseAliases((string) ($row['aliases'] ?? '')),
            $links,
            (string) ($row['description'] ?? ''),
            (string) ($row['description_source'] ?? '')
        );
    }
}
