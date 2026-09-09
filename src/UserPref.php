<?php

namespace GlpiPlugin\Sprint;

use Session;

/**
 * Per-user preferences of the plugin (personal views, remembered choices),
 * stored server-side so they follow the user across browsers and devices.
 * Plain key/value per user; not a CommonDBTM on purpose — nothing here is
 * searchable, logged or rights-managed beyond "your own row".
 */
class UserPref
{
    public const TABLE = 'glpi_plugin_sprint_userprefs';

    /** Personal backlog order: 'team' (shared ranking) or 'project' (project, then name). */
    public const BACKLOG_SORT = 'backlog_sort';

    /**
     * Create the table on the fly for installations that predate it, so a
     * running instance does not need a forced reinstall (same pattern as
     * SprintRequest::ensureTable()). hook.php creates it on install too.
     */
    public static function ensureTable(): bool
    {
        global $DB;
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }
        if ($DB->tableExists(self::TABLE)) {
            return $ready = true;
        }
        $charset   = \DBConnection::getDefaultCharset();
        $collation = \DBConnection::getDefaultCollation();
        $ready = (bool)$DB->doQuery(
            "CREATE TABLE `" . self::TABLE . "` (
                `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `name`     VARCHAR(64) NOT NULL DEFAULT '',
                `value`    VARCHAR(255) NOT NULL DEFAULT '',
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `user_name` (`users_id`, `name`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC"
        );
        return $ready;
    }

    /** @var array<string,string|null> per-request cache: name => stored value (null = unset) */
    private static array $cache = [];

    /** Value of a preference for the current user, or $default when unset. */
    public static function get(string $name, string $default = ''): string
    {
        global $DB;
        $userId = (int)Session::getLoginUserID();
        if ($userId <= 0 || !self::ensureTable()) {
            return $default;
        }
        if (!array_key_exists($name, self::$cache)) {
            self::$cache[$name] = null;
            foreach ($DB->request([
                'SELECT' => ['value'],
                'FROM'   => self::TABLE,
                'WHERE'  => ['users_id' => $userId, 'name' => $name],
                'LIMIT'  => 1,
            ]) as $row) {
                self::$cache[$name] = (string)$row['value'];
            }
        }
        return self::$cache[$name] ?? $default;
    }

    /** Store a preference for the current user (insert or overwrite). */
    public static function set(string $name, string $value): bool
    {
        global $DB;
        $userId = (int)Session::getLoginUserID();
        if ($userId <= 0 || $name === '' || !self::ensureTable()) {
            return false;
        }
        self::$cache[$name] = $value;
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $exists = false;
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['users_id' => $userId, 'name' => $name],
            'LIMIT'  => 1,
        ]) as $row) {
            $exists = true;
        }
        if ($exists) {
            return (bool)$DB->update(
                self::TABLE,
                ['value' => $value, 'date_mod' => $now],
                ['users_id' => $userId, 'name' => $name]
            );
        }
        return (bool)$DB->insert(self::TABLE, [
            'users_id' => $userId,
            'name'     => $name,
            'value'    => $value,
            'date_mod' => $now,
        ]);
    }

    /** Personal backlog order for the current user: 'team' or 'project'. */
    public static function backlogSort(): string
    {
        return self::get(self::BACKLOG_SORT, 'team') === 'project' ? 'project' : 'team';
    }
}
