<?php

/**
 * Sprint Plugin - Install/Uninstall hooks
 */


/**
 * Plugin install process
 *
 * @return boolean
 */
function plugin_sprint_install(): bool
{
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $migration         = new Migration(PLUGIN_SPRINT_VERSION);

    // =========================================================================
    // Table: glpi_plugin_sprint_sprints (main sprint entity)
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprints')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprints` (
            `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`            VARCHAR(255) NOT NULL DEFAULT '',
            `entities_id`     INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive`    TINYINT NOT NULL DEFAULT 0,
            `sprint_number`   INT UNSIGNED NOT NULL DEFAULT 0,
            `goal`            TEXT,
            `status`          VARCHAR(50) NOT NULL DEFAULT 'planned',
            `date_start`      TIMESTAMP NULL DEFAULT NULL,
            `date_end`        TIMESTAMP NULL DEFAULT NULL,
            `duration_weeks`  INT UNSIGNED NOT NULL DEFAULT 2,
            `users_id`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Scrum Master',
            `projects_id`     INT UNSIGNED NOT NULL DEFAULT 0,
            `comment`         TEXT,
            `date_creation`   TIMESTAMP NULL DEFAULT NULL,
            `date_mod`        TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`),
            KEY `status` (`status`),
            KEY `users_id` (`users_id`),
            KEY `projects_id` (`projects_id`),
            KEY `date_start` (`date_start`),
            KEY `date_end` (`date_end`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_sprintitems (sprint backlog items)
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprintitems')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprintitems` (
            `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprints_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `name`                     VARCHAR(255) NOT NULL DEFAULT '',
            `description`              TEXT,
            `itemtype`                 VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Linked GLPI item type (Ticket, Change, ProjectTask)',
            `items_id`                 INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Linked GLPI item ID',
            `status`                   VARCHAR(50) NOT NULL DEFAULT 'todo',
            `priority`                 INT NOT NULL DEFAULT 3,
            `story_points`             INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id`                 INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Owner/Assignee',
            `sort_order`               INT NOT NULL DEFAULT 0,
            `capacity`                 INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Capacity usage in %',
            `note`                     TEXT COMMENT 'Standup note',
            `date_creation`            TIMESTAMP NULL DEFAULT NULL,
            `date_mod`                 TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_sprint_sprints_id` (`plugin_sprint_sprints_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `status` (`status`),
            KEY `users_id` (`users_id`),
            KEY `priority` (`priority`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // Migration: add itemtype/items_id to existing sprintitems table
    if ($DB->tableExists('glpi_plugin_sprint_sprintitems')) {
        $migration->addField(
            'glpi_plugin_sprint_sprintitems',
            'itemtype',
            'string',
            ['value' => '', 'after' => 'description']
        );
        $migration->addField(
            'glpi_plugin_sprint_sprintitems',
            'items_id',
            'integer',
            ['value' => 0, 'after' => 'itemtype']
        );
        $migration->addKey('glpi_plugin_sprint_sprintitems', ['itemtype', 'items_id'], 'item');
        $migration->addField(
            'glpi_plugin_sprint_sprintitems',
            'capacity',
            'integer',
            ['value' => 0, 'after' => 'sort_order']
        );
        $migration->addField(
            'glpi_plugin_sprint_sprintitems',
            'note',
            'text',
            ['after' => 'capacity']
        );
        // Fastlane flag: an item flagged here lives in the Sprint Fastlane
        // tab instead of the regular Sprint Items tab, and its capacity is
        // distributed across multiple sprint members via the
        // glpi_plugin_sprint_sprintfastlanemembers junction table.
        $migration->addField(
            'glpi_plugin_sprint_sprintitems',
            'is_fastlane',
            'bool',
            ['value' => 0, 'after' => 'note']
        );
        $migration->addKey('glpi_plugin_sprint_sprintitems', 'is_fastlane');
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_sprintfastlanemembers
    // Junction linking a Fastlane SprintItem to multiple sprint members,
    // each with their own assigned capacity %. Allows the dashboard to
    // aggregate the "Fastlane" capacity category across the team.
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprintfastlanemembers')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprintfastlanemembers` (
            `id`                            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprintitems_id`  INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id`                      INT UNSIGNED NOT NULL DEFAULT 0,
            `capacity`                      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Capacity allocated to this user for this fastlane item, in %',
            `comment`                       TEXT,
            `date_creation`                 TIMESTAMP NULL DEFAULT NULL,
            `date_mod`                      TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_sprint_sprintitems_id`, `users_id`),
            KEY `plugin_sprint_sprintitems_id` (`plugin_sprint_sprintitems_id`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_sprintmeetings (kickoff, standups, retro)
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprintmeetings')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprintmeetings` (
            `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprints_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `name`                     VARCHAR(255) NOT NULL DEFAULT '',
            `meeting_type`             VARCHAR(50) NOT NULL DEFAULT 'standup',
            `date_meeting`             TIMESTAMP NULL DEFAULT NULL,
            `duration_minutes`         INT UNSIGNED NOT NULL DEFAULT 15,
            `notes`                    LONGTEXT,
            `attendees`                TEXT COMMENT 'JSON array of user IDs',
            `users_id`                 INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Facilitator',
            `treated_items`            TEXT COMMENT 'JSON array of treated sprint item IDs',
            `date_creation`            TIMESTAMP NULL DEFAULT NULL,
            `date_mod`                 TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_sprint_sprints_id` (`plugin_sprint_sprints_id`),
            KEY `meeting_type` (`meeting_type`),
            KEY `date_meeting` (`date_meeting`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // Migration: add treated_items to existing meetings table
    if ($DB->tableExists('glpi_plugin_sprint_sprintmeetings')) {
        $migration->addField(
            'glpi_plugin_sprint_sprintmeetings',
            'treated_items',
            'text',
            ['after' => 'users_id']
        );
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_sprintstandups (standup entries per item)
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprintstandups')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprintstandups` (
            `id`                              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprintmeetings_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_sprint_sprintitems_id`    INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id`                        INT UNSIGNED NOT NULL DEFAULT 0,
            `status_update`                   VARCHAR(50) NOT NULL DEFAULT 'on_track',
            `done_yesterday`                  TEXT,
            `plan_today`                      TEXT,
            `blockers`                        TEXT,
            `date_creation`                   TIMESTAMP NULL DEFAULT NULL,
            `date_mod`                        TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_sprint_sprintmeetings_id` (`plugin_sprint_sprintmeetings_id`),
            KEY `plugin_sprint_sprintitems_id` (`plugin_sprint_sprintitems_id`),
            KEY `users_id` (`users_id`),
            KEY `status_update` (`status_update`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_sprintmembers (team members per sprint)
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprintmembers')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprintmembers` (
            `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprints_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id`                 INT UNSIGNED NOT NULL DEFAULT 0,
            `role`                     VARCHAR(50) NOT NULL DEFAULT 'developer',
            `capacity_percent`         INT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'Beschikbaarheid in %',
            `comment`                  TEXT,
            `date_creation`            TIMESTAMP NULL DEFAULT NULL,
            `date_mod`                 TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_sprint_sprints_id`, `users_id`),
            KEY `plugin_sprint_sprints_id` (`plugin_sprint_sprints_id`),
            KEY `users_id` (`users_id`),
            KEY `role` (`role`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_sprinttickets (link sprints <-> tickets)
    // With users_id to assign a sprint member to the linked ticket
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprinttickets')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprinttickets` (
            `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprints_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `tickets_id`               INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id`                 INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Assigned sprint member',
            `date_creation`            TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_sprint_sprints_id`, `tickets_id`),
            KEY `plugin_sprint_sprints_id` (`plugin_sprint_sprints_id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // Migration: add users_id to existing sprinttickets table
    if ($DB->tableExists('glpi_plugin_sprint_sprinttickets')) {
        $migration->addField(
            'glpi_plugin_sprint_sprinttickets',
            'users_id',
            'integer',
            ['value' => 0, 'after' => 'tickets_id']
        );
        $migration->addKey('glpi_plugin_sprint_sprinttickets', 'users_id');
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_sprintchanges (link sprints <-> changes)
    // With users_id to assign a sprint member to the linked change
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprintchanges')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprintchanges` (
            `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprints_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `changes_id`               INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id`                 INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Assigned sprint member',
            `date_creation`            TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_sprint_sprints_id`, `changes_id`),
            KEY `plugin_sprint_sprints_id` (`plugin_sprint_sprints_id`),
            KEY `changes_id` (`changes_id`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // Migration: add users_id to existing sprintchanges table
    if ($DB->tableExists('glpi_plugin_sprint_sprintchanges')) {
        $migration->addField(
            'glpi_plugin_sprint_sprintchanges',
            'users_id',
            'integer',
            ['value' => 0, 'after' => 'changes_id']
        );
        $migration->addKey('glpi_plugin_sprint_sprintchanges', 'users_id');
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_sprintprojecttasks (link sprints <-> project tasks)
    // With users_id to assign a sprint member to the linked task
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprintprojecttasks')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprintprojecttasks` (
            `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprints_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `projecttasks_id`          INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id`                 INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Assigned sprint member',
            `date_creation`            TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_sprint_sprints_id`, `projecttasks_id`),
            KEY `plugin_sprint_sprints_id` (`plugin_sprint_sprints_id`),
            KEY `projecttasks_id` (`projecttasks_id`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // Migration: add users_id to existing sprintprojecttasks table
    if ($DB->tableExists('glpi_plugin_sprint_sprintprojecttasks')) {
        $migration->addField(
            'glpi_plugin_sprint_sprintprojecttasks',
            'users_id',
            'integer',
            ['value' => 0, 'after' => 'projecttasks_id']
        );
        $migration->addKey('glpi_plugin_sprint_sprintprojecttasks', 'users_id');
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_profiles (rights management)
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_profiles')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_profiles` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `profiles_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `rights`      VARCHAR(50) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `profiles_id` (`profiles_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_sprinttemplates
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprinttemplates')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprinttemplates` (
            `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`            VARCHAR(255) NOT NULL DEFAULT '',
            `entities_id`     INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive`    TINYINT NOT NULL DEFAULT 0,
            `name_pattern`    VARCHAR(255) NOT NULL DEFAULT '',
            `duration_weeks`  INT UNSIGNED NOT NULL DEFAULT 2,
            `goal`            TEXT,
            `comment`         TEXT,
            `is_active`       TINYINT NOT NULL DEFAULT 1,
            `date_creation`   TIMESTAMP NULL DEFAULT NULL,
            `date_mod`        TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`),
            KEY `is_active` (`is_active`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_sprinttemplatemembers
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprinttemplatemembers')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprinttemplatemembers` (
            `id`                               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprinttemplates_id`  INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id`                         INT UNSIGNED NOT NULL DEFAULT 0,
            `role`                             VARCHAR(50) NOT NULL DEFAULT 'developer',
            `capacity_percent`                 INT UNSIGNED NOT NULL DEFAULT 100,
            `comment`                          TEXT,
            `date_creation`                    TIMESTAMP NULL DEFAULT NULL,
            `date_mod`                         TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_sprint_sprinttemplates_id`, `users_id`),
            KEY `plugin_sprint_sprinttemplates_id` (`plugin_sprint_sprinttemplates_id`),
            KEY `users_id` (`users_id`),
            KEY `role` (`role`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_sprinttemplateitems
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprinttemplateitems')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprinttemplateitems` (
            `id`                               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprinttemplates_id`  INT UNSIGNED NOT NULL DEFAULT 0,
            `name`                             VARCHAR(255) NOT NULL DEFAULT '',
            `description`                      TEXT,
            `priority`                         INT NOT NULL DEFAULT 3,
            `story_points`                     INT UNSIGNED NOT NULL DEFAULT 0,
            `sort_order`                       INT NOT NULL DEFAULT 0,
            `date_creation`                    TIMESTAMP NULL DEFAULT NULL,
            `date_mod`                         TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_sprint_sprinttemplates_id` (`plugin_sprint_sprinttemplates_id`),
            KEY `priority` (`priority`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_sprinttemplatemeetings
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprinttemplatemeetings')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprinttemplatemeetings` (
            `id`                               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprinttemplates_id`  INT UNSIGNED NOT NULL DEFAULT 0,
            `name`                             VARCHAR(255) NOT NULL DEFAULT '',
            `meeting_type`                     VARCHAR(50) NOT NULL DEFAULT 'standup',
            `schedule_type`                    VARCHAR(50) NOT NULL DEFAULT 'first_day' COMMENT 'first_day, last_day, day_before_end, interval',
            `interval_days`                    INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'For interval schedule: every N days',
            `duration_minutes`                 INT UNSIGNED NOT NULL DEFAULT 15,
            `is_optional`                      TINYINT NOT NULL DEFAULT 0,
            `skip_weekends`                    TINYINT NOT NULL DEFAULT 0 COMMENT 'Move weekend meetings to next Monday',
            `sort_order`                       INT NOT NULL DEFAULT 0,
            `date_creation`                    TIMESTAMP NULL DEFAULT NULL,
            `date_mod`                         TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_sprint_sprinttemplates_id` (`plugin_sprint_sprinttemplates_id`),
            KEY `meeting_type` (`meeting_type`),
            KEY `schedule_type` (`schedule_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // Migration: add skip_weekends to existing template meetings table
    if ($DB->tableExists('glpi_plugin_sprint_sprinttemplatemeetings')) {
        $migration->addField(
            'glpi_plugin_sprint_sprinttemplatemeetings',
            'skip_weekends',
            'bool',
            ['value' => 0, 'after' => 'is_optional']
        );
    }

    // Add display preferences
    $pref = new DisplayPreference();
    $found = $pref->find([
        'itemtype' => 'GlpiPlugin\\Sprint\\Sprint',
        'users_id' => 0,
    ]);
    if (count($found) === 0) {
        $fields = [1, 3, 4, 5, 6, 7]; // name (itemlink), status, date_start, date_end, sprint_number, scrum_master
        foreach ($fields as $rank => $num) {
            $pref->add([
                'itemtype' => 'GlpiPlugin\\Sprint\\Sprint',
                'num'      => $num,
                'rank'     => $rank + 1,
                'users_id' => 0,
            ]);
        }
    }

    // Install profile rights (grant Super-Admin full access)
    GlpiPlugin\Sprint\Profile::installRights();

    $migration->executeMigration();

    return true;
}

/**
 * Plugin uninstall process
 *
 * @return boolean
 */
function plugin_sprint_uninstall(): bool
{
    global $DB;

    $tables = [
        'glpi_plugin_sprint_sprinttemplatemeetings',
        'glpi_plugin_sprint_sprinttemplateitems',
        'glpi_plugin_sprint_sprinttemplatemembers',
        'glpi_plugin_sprint_sprinttemplates',
        'glpi_plugin_sprint_profiles',
        'glpi_plugin_sprint_sprintprojecttasks',
        'glpi_plugin_sprint_sprintchanges',
        'glpi_plugin_sprint_sprinttickets',
        'glpi_plugin_sprint_sprintstandups',
        'glpi_plugin_sprint_sprintmeetings',
        'glpi_plugin_sprint_sprintfastlanemembers',
        'glpi_plugin_sprint_sprintmembers',
        'glpi_plugin_sprint_sprintitems',
        'glpi_plugin_sprint_sprints',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQueryOrDie("DROP TABLE `{$table}`", $DB->error());
        }
    }

    // Remove display preferences
    $pref = new DisplayPreference();
    $pref->deleteByCriteria([
        'itemtype' => ['LIKE', 'GlpiPlugin\\\\Sprint\\\\%'],
    ]);

    return true;
}

/**
 * Check prerequisites before install
 *
 * @return boolean
 */
function plugin_sprint_check_prerequisites(): bool
{
    return true;
}

/**
 * Check configuration
 *
 * @param bool $verbose
 * @return boolean
 */
function plugin_sprint_check_config(bool $verbose = false): bool
{
    if (true) {
        return true;
    }

    if ($verbose) {
        _e('Installed / not configured', 'sprint');
    }

    return false;
}
