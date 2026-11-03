<?php

declare(strict_types=1);

namespace EventMesh\Djs;

/**
 * The database table that holds the DJ list: {prefix}eventmesh_djs.
 *
 * Created with dbDelta() on plugin activation, and again on the first admin
 * page load after an update whenever the stored table version is older than
 * DB_VERSION (activation does not run when a plugin is updated by upload).
 * To change the table, edit schema() and raise DB_VERSION; dbDelta() then
 * adds the new columns on the next admin page load.
 */
final class DjTable
{
    public const DB_VERSION = '2';
    public const VERSION_OPTION = 'eventmesh_dj_db_version';

    public static function name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'eventmesh_djs';
    }

    public static function isInstalled(): bool
    {
        return '' !== (string) get_option(self::VERSION_OPTION, '');
    }

    public function maybeUpgrade(): void
    {
        if (self::DB_VERSION === (string) get_option(self::VERSION_OPTION, '')) {
            return;
        }

        $this->install();
    }

    public function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta($this->schema());

        update_option(self::VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * Removes the table and its version option. Used by the factory reset.
     */
    public static function drop(): void
    {
        global $wpdb;

        $table = self::name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own; it cannot be a placeholder.
        $wpdb->query("DROP TABLE IF EXISTS {$table}");

        delete_option(self::VERSION_OPTION);
    }

    /**
     * dbDelta() needs this exact layout: one column per line, two spaces
     * after PRIMARY KEY, no back ticks.
     *
     * Columns: id; name (the DJ name); aliases (other spellings, one per
     * line); spotify_url, soundcloud_url, mixcloud_url (empty when the DJ has
     * no link on that service); description (plain text about the DJ) and
     * description_source (where it came from: 'manual', 'team',
     * 'soundcloud', 'mixcloud', or '' for none). Version 2 added the last two.
     */
    private function schema(): string
    {
        global $wpdb;

        $table = self::name();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL DEFAULT '',
  aliases text NOT NULL,
  spotify_url text NOT NULL,
  soundcloud_url text NOT NULL,
  mixcloud_url text NOT NULL,
  description text NOT NULL,
  description_source varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY name (name)
) {$charset};";
    }
}
