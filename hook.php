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
            `scope_baseline_items` INT UNSIGNED NOT NULL DEFAULT 0,
            `scope_baseline_points` INT UNSIGNED NOT NULL DEFAULT 0,
            `fastlane_capacity` DECIMAL(5,1) NOT NULL DEFAULT 0 COMMENT 'Fastlane capacity cap in %, 0 = no cap',
            `users_id`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Scrum Master',
            `projects_id`     INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_sprint_sprinttemplates_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `comment`         TEXT,
            `date_creation`   TIMESTAMP NULL DEFAULT NULL,
            `date_mod`        TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`),
            KEY `status` (`status`),
            KEY `users_id` (`users_id`),
            KEY `projects_id` (`projects_id`),
            KEY `plugin_sprint_sprinttemplates_id` (`plugin_sprint_sprinttemplates_id`),
            KEY `date_start` (`date_start`),
            KEY `date_end` (`date_end`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // Migration: per-sprint fastlane hard cap (display-only overflow guard)
    if ($DB->tableExists('glpi_plugin_sprint_sprints')) {
        $migration->addField('glpi_plugin_sprint_sprints', 'scope_baseline_items', 'integer', ['value' => 0, 'after' => 'duration_weeks']);
        $migration->addField('glpi_plugin_sprint_sprints', 'scope_baseline_points', 'integer', ['value' => 0, 'after' => 'scope_baseline_items']);
        $migration->addField('glpi_plugin_sprint_sprints', 'plugin_sprint_sprinttemplates_id', 'integer', ['value' => 0, 'after' => 'projects_id']);
        $migration->addKey('glpi_plugin_sprint_sprints', 'plugin_sprint_sprinttemplates_id');
        $migration->addField(
            'glpi_plugin_sprint_sprints',
            'fastlane_capacity',
            'integer',
            ['value' => 0, 'after' => 'duration_weeks']
        );
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_sprintitems (sprint backlog items)
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprintitems')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprintitems` (
            `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprints_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `proposed_sprints_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Sprint pre-selected on the backlog, awaiting Scrum Master assignment',
            `name`                     VARCHAR(255) NOT NULL DEFAULT '',
            `description`              TEXT,
            `itemtype`                 VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Linked GLPI item type (Ticket, Change, Problem, ProjectTask)',
            `items_id`                 INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Linked GLPI item ID',
            `status`                   VARCHAR(50) NOT NULL DEFAULT 'todo',
            `priority`                 INT NOT NULL DEFAULT 3,
            `story_points`             INT UNSIGNED NOT NULL DEFAULT 1,
            `users_id`                 INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Owner/Assignee',
            `sort_order`               INT NOT NULL DEFAULT 0,
            `capacity`                 DECIMAL(5,1) NOT NULL DEFAULT 0 COMMENT 'Capacity usage in %',
            `note`                     TEXT COMMENT 'Standup note',
            `date_creation`            TIMESTAMP NULL DEFAULT NULL,
            `date_mod`                 TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_sprint_sprints_id` (`plugin_sprint_sprints_id`),
            KEY `proposed_sprints_id` (`proposed_sprints_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `status` (`status`),
            KEY `users_id` (`users_id`),
            KEY `priority` (`priority`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // Migration: add itemtype/items_id to existing sprintitems table
    if ($DB->tableExists('glpi_plugin_sprint_sprintitems')) {
        // Backlog items can pre-select a target sprint that only the Scrum
        // Master of that sprint may actually assign.
        $migration->addField(
            'glpi_plugin_sprint_sprintitems',
            'proposed_sprints_id',
            'integer',
            ['value' => 0, 'after' => 'plugin_sprint_sprints_id']
        );
        $migration->addKey('glpi_plugin_sprint_sprintitems', 'proposed_sprints_id');
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
        $migration->addField(
            'glpi_plugin_sprint_sprintitems',
            'is_blocked',
            'bool',
            ['value' => 0, 'after' => 'is_fastlane']
        );
        $migration->addKey('glpi_plugin_sprint_sprintitems', 'is_blocked');
        // Adhoc flag: work that entered the sprint after kick-off. Only the
        // sprint's Scrum Master may toggle it (enforced in SprintItem);
        // flagged rows are highlighted on the dashboard, personal view and
        // meeting boards.
        $migration->addField(
            'glpi_plugin_sprint_sprintitems',
            'is_adhoc',
            'bool',
            ['value' => 0, 'after' => 'is_blocked']
        );
        $migration->addKey('glpi_plugin_sprint_sprintitems', 'is_adhoc');
        $migration->addField('glpi_plugin_sprint_sprintitems', 'ready_checks', 'text', ['after' => 'description']);
        $migration->addField('glpi_plugin_sprint_sprintitems', 'done_checks', 'text', ['after' => 'ready_checks']);
        $migration->addField('glpi_plugin_sprint_sprintitems', 'plugin_sprint_sprintepics_id', 'integer', ['value' => 0, 'after' => 'done_checks']);
        $migration->addKey('glpi_plugin_sprint_sprintitems', 'plugin_sprint_sprintepics_id');
        // Backlog category (Projects / R&D / Internal / ... — admin-defined
        // in plugin settings). Exactly one per item so capacity can be
        // aggregated per category on the backlog dashboard.
        $migration->addField('glpi_plugin_sprint_sprintitems', 'plugin_sprint_sprintcategories_id', 'integer', ['value' => 0, 'after' => 'plugin_sprint_sprintepics_id']);
        $migration->addKey('glpi_plugin_sprint_sprintitems', 'plugin_sprint_sprintcategories_id');
        // Non-active tier: parked backlog items are long-term / low-priority
        // work kept out of the active planning sections and capacity totals.
        $migration->addField('glpi_plugin_sprint_sprintitems', 'is_parked', 'bool', ['value' => 0, 'after' => 'is_adhoc']);
        $migration->addKey('glpi_plugin_sprint_sprintitems', 'is_parked');
        // Customer + credits replace the former planned/actual capacity pair:
        // an item is planned as a capacity %% and charged as credits.
        $migration->addField(
            'glpi_plugin_sprint_sprintitems',
            'plugin_sprint_sprintcustomers_id',
            'integer',
            ['value' => 0, 'after' => 'capacity']
        );
        $migration->addKey('glpi_plugin_sprint_sprintitems', 'plugin_sprint_sprintcustomers_id');
        $migration->addField(
            'glpi_plugin_sprint_sprintitems',
            'credits',
            "DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Credits charged to the customer for this item'",
            ['after' => 'plugin_sprint_sprintcustomers_id']
        );
        $migration->dropField('glpi_plugin_sprint_sprintitems', 'capacity_actual');
        // The catalogue entry the credits were taken from, 0 = entered by hand.
        $migration->addField(
            'glpi_plugin_sprint_sprintitems',
            'plugin_sprint_sprintcreditproducts_id',
            'integer',
            ['value' => 0, 'after' => 'credits']
        );
        $migration->addKey('glpi_plugin_sprint_sprintitems', 'plugin_sprint_sprintcreditproducts_id');
    }

    // =========================================================================
    // Backlog categories + per-sprint capacity caps per category
    // =========================================================================
    $backlogTables = [
        'glpi_plugin_sprint_sprintcategories' => "CREATE TABLE `glpi_plugin_sprint_sprintcategories` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`          VARCHAR(255) NOT NULL DEFAULT '',
            `color`         VARCHAR(16) NOT NULL DEFAULT '#0d6efd',
            `plugin_sprint_sprintcategories_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Parent category, 0 = top level (max one level of nesting)',
            `sort_order`    INT NOT NULL DEFAULT 0,
            `is_active`     TINYINT NOT NULL DEFAULT 1,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod`      TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `name` (`name`), KEY `is_active` (`is_active`),
            KEY `plugin_sprint_sprintcategories_id` (`plugin_sprint_sprintcategories_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC",
        'glpi_plugin_sprint_backlogflow' => "CREATE TABLE `glpi_plugin_sprint_backlogflow` (
            `id`                                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `date`                              DATE NOT NULL,
            `plugin_sprint_sprintcategories_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `direction`                         VARCHAR(3) NOT NULL DEFAULT 'in' COMMENT 'in = entered backlog, out = left (assigned/removed)',
            `capacity`                          DECIMAL(6,1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `date_dir` (`date`, `direction`),
            KEY `category` (`plugin_sprint_sprintcategories_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC",
        'glpi_plugin_sprint_sprintcategorycaps' => "CREATE TABLE `glpi_plugin_sprint_sprintcategorycaps` (
            `id`                                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprints_id`          INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_sprint_sprintcategories_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `max_percent`                       DECIMAL(6,1) NOT NULL DEFAULT 0 COMMENT 'Capacity cap in %, 0 = no cap',
            `min_percent`                       DECIMAL(6,1) NOT NULL DEFAULT 0 COMMENT 'Capacity floor in %, 0 = no minimum',
            `date_mod`                          TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_sprint_sprints_id`, `plugin_sprint_sprintcategories_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC",
    ];
    foreach ($backlogTables as $table => $query) {
        if (!$DB->tableExists($table)) {
            $DB->doQueryOrDie($query, $DB->error());
        }
    }

    // Subcategories: one level of nesting so the backlog and the planning
    // matrix can group work per category/subcategory.
    if ($DB->tableExists('glpi_plugin_sprint_sprintcategories')) {
        $migration->addField(
            'glpi_plugin_sprint_sprintcategories',
            'plugin_sprint_sprintcategories_id',
            "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Parent category, 0 = top level (max one level of nesting)'",
            ['after' => 'color']
        );
        $migration->addKey('glpi_plugin_sprint_sprintcategories', 'plugin_sprint_sprintcategories_id');
    }

    if ($DB->tableExists('glpi_plugin_sprint_sprintcategorycaps')) {
        $migration->addField(
            'glpi_plugin_sprint_sprintcategorycaps',
            'min_percent',
            "DECIMAL(6,1) NOT NULL DEFAULT 0 COMMENT 'Capacity floor in %, 0 = no minimum'",
            ['after' => 'max_percent']
        );
    }

    // =========================================================================
    // Customers + their credit ledger
    // =========================================================================
    $creditTables = [
        'glpi_plugin_sprint_sprintcustomers' => "CREATE TABLE `glpi_plugin_sprint_sprintcustomers` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`          VARCHAR(255) NOT NULL DEFAULT '',
            `code`          VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'Short reference code',
            `color`         VARCHAR(16) NOT NULL DEFAULT '#0d6efd',
            `entities_id`   INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive`  TINYINT NOT NULL DEFAULT 0,
            `contact`       VARCHAR(255) NOT NULL DEFAULT '',
            `email`         VARCHAR(255) NOT NULL DEFAULT '',
            `credit_alert`  DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Warn below this many credits left, 0 = no alert',
            `is_active`     TINYINT NOT NULL DEFAULT 1,
            `comment`       TEXT,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod`      TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC",
        'glpi_plugin_sprint_sprintcreditproducts' => "CREATE TABLE `glpi_plugin_sprint_sprintcreditproducts` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`          VARCHAR(255) NOT NULL DEFAULT '',
            `code`          VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'Short reference code',
            `credits`       DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Default credits filled in when the product is picked',
            `description`   TEXT,
            `sprintcreditproducts_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Parent folder, 0 = top level',
            `is_folder`     TINYINT NOT NULL DEFAULT 0 COMMENT 'A folder groups products and cannot be picked',
            `entities_id`   INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive`  TINYINT NOT NULL DEFAULT 1,
            `is_active`     TINYINT NOT NULL DEFAULT 1,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod`      TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `sprintcreditproducts_id` (`sprintcreditproducts_id`),
            KEY `entities_id` (`entities_id`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC",
        'glpi_plugin_sprint_sprintretainers' => "CREATE TABLE `glpi_plugin_sprint_sprintretainers` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprintcustomers_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_start`    DATE NULL DEFAULT NULL COMMENT 'First sprint start date the rule applies to, NULL = from the beginning',
            `credits_per_sprint` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Granted every sprint until the next rule starts, 0 = no retainer',
            `credits_cap_per_sprint` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Flag a sprint claiming more than this, 0 = no cap',
            `comment`       VARCHAR(255) NOT NULL DEFAULT '',
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod`      TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_sprint_sprintcustomers_id` (`plugin_sprint_sprintcustomers_id`),
            KEY `date_start` (`date_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC",
        'glpi_plugin_sprint_sprintcustomercredits' => "CREATE TABLE `glpi_plugin_sprint_sprintcustomercredits` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprints_id`         INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_sprint_sprintcustomers_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `credits`       DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'Grant for this sprint, NULL = the customer retainer',
            `max_credits`   DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'Cap for this sprint, NULL = the customer cap',
            `date_mod`      TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_sprint_sprints_id`, `plugin_sprint_sprintcustomers_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC",
        'glpi_plugin_sprint_sprintcredits' => "CREATE TABLE `glpi_plugin_sprint_sprintcredits` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprintcustomers_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `name`          VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Description of the purchase or correction',
            `reference`     VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'PO / invoice reference',
            `credits`       DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Positive = bought, negative = correction',
            `date`          DATE NULL DEFAULT NULL COMMENT 'Booking date',
            `date_expire`   DATE NULL DEFAULT NULL COMMENT 'Credits stop counting after this date, NULL = never',
            `users_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Who booked it',
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod`      TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_sprint_sprintcustomers_id` (`plugin_sprint_sprintcustomers_id`),
            KEY `date` (`date`),
            KEY `date_expire` (`date_expire`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC",
    ];
    foreach ($creditTables as $table => $query) {
        if (!$DB->tableExists($table)) {
            $DB->doQueryOrDie($query, $DB->error());
        }
    }

    // Catalogue folders: a pre-release table gets the parent + folder flag.
    if ($DB->tableExists('glpi_plugin_sprint_sprintcreditproducts')) {
        $migration->addField('glpi_plugin_sprint_sprintcreditproducts', 'sprintcreditproducts_id', 'integer', ['value' => 0, 'after' => 'description']);
        $migration->addKey('glpi_plugin_sprint_sprintcreditproducts', 'sprintcreditproducts_id');
        $migration->addField('glpi_plugin_sprint_sprintcreditproducts', 'is_folder', 'bool', ['value' => 0, 'after' => 'sprintcreditproducts_id']);
    }

    // The retainer moved from four columns on the customer (one figure with
    // a start/end window) to dated rules in glpi_plugin_sprint_sprintretainers.
    // A pre-release database carries the figure over as the first rule, an
    // end date as a closing 0-rule, and only then are the columns dropped —
    // once, so a second run finds nothing to move.
    if (
        $DB->tableExists('glpi_plugin_sprint_sprintcustomers')
        && $DB->fieldExists('glpi_plugin_sprint_sprintcustomers', 'credits_per_sprint')
    ) {
        foreach ($DB->request([
            'SELECT' => ['id', 'credits_per_sprint', 'credits_cap_per_sprint', 'retainer_start', 'retainer_end'],
            'FROM'   => 'glpi_plugin_sprint_sprintcustomers',
        ]) as $row) {
            $per = (float)$row['credits_per_sprint'];
            $cap = (float)$row['credits_cap_per_sprint'];
            if ($per <= 0 && $cap <= 0) {
                continue;
            }
            $start = ($row['retainer_start'] ?? null) === null || str_starts_with((string)$row['retainer_start'], '0000') ? null : substr((string)$row['retainer_start'], 0, 10);
            $end   = ($row['retainer_end'] ?? null) === null || str_starts_with((string)$row['retainer_end'], '0000') ? null : substr((string)$row['retainer_end'], 0, 10);
            $now   = date('Y-m-d H:i:s');
            $DB->insert('glpi_plugin_sprint_sprintretainers', [
                'plugin_sprint_sprintcustomers_id' => (int)$row['id'],
                'date_start'                       => $start,
                'credits_per_sprint'               => $per,
                'credits_cap_per_sprint'           => $cap,
                'comment'                          => '',
                'date_creation'                    => $now,
                'date_mod'                         => $now,
            ]);
            if ($end !== null) {
                $DB->insert('glpi_plugin_sprint_sprintretainers', [
                    'plugin_sprint_sprintcustomers_id' => (int)$row['id'],
                    'date_start'                       => date('Y-m-d', strtotime($end . ' +1 day')),
                    'credits_per_sprint'               => 0,
                    'credits_cap_per_sprint'           => 0,
                    'comment'                          => '',
                    'date_creation'                    => $now,
                    'date_mod'                         => $now,
                ]);
            }
        }
        foreach (['credits_per_sprint', 'credits_cap_per_sprint', 'retainer_start', 'retainer_end'] as $field) {
            $migration->dropField('glpi_plugin_sprint_sprintcustomers', $field);
        }
    }

    // Sprint flow policies.
    if ($DB->tableExists('glpi_plugin_sprint_sprints')) {
        $migration->addField('glpi_plugin_sprint_sprints', 'wip_limits', 'text', ['after' => 'goal']);
        $migration->addField('glpi_plugin_sprint_sprints', 'wip_hard', 'bool', ['value' => 0, 'after' => 'wip_limits']);
        $migration->addField('glpi_plugin_sprint_sprints', 'sync_linked_status', 'bool', ['value' => 0, 'after' => 'wip_hard']);
        $migration->addField('glpi_plugin_sprint_sprints', 'linked_status_rules', 'text', ['after' => 'sync_linked_status']);
    }

    // 1.2.2: DoR/DoD moved to global settings and WIP limits became
    // per-person — drop the dead columns.
    $migration->dropField('glpi_plugin_sprint_sprints', 'definition_ready');
    $migration->dropField('glpi_plugin_sprint_sprints', 'definition_done');
    $migration->dropField('glpi_plugin_sprint_sprints', 'wip_per_person');
    $migration->dropField('glpi_plugin_sprint_sprinttemplates', 'definition_ready');
    $migration->dropField('glpi_plugin_sprint_sprinttemplates', 'definition_done');
    $migration->dropField('glpi_plugin_sprint_sprinttemplates', 'wip_per_person');
    $migration->dropField('glpi_plugin_sprint_sprintitems', 'acceptance_criteria');

    $agilityTables = [
        'glpi_plugin_sprint_sprintepics' => "CREATE TABLE `glpi_plugin_sprint_sprintepics` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprints_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `color` VARCHAR(16) NOT NULL DEFAULT '#0d6efd',
            `target_date` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `sprint` (`plugin_sprint_sprints_id`), KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC",
        'glpi_plugin_sprint_sprintavailabilities' => "CREATE TABLE `glpi_plugin_sprint_sprintavailabilities` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprints_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_start` DATE NOT NULL,
            `date_end` DATE NOT NULL,
            `availability_percent` DECIMAL(5,1) NOT NULL DEFAULT 0,
            `comment` VARCHAR(255) NOT NULL DEFAULT '',
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `sprint_user` (`plugin_sprint_sprints_id`,`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC",
        'glpi_plugin_sprint_sprintimprovements' => "CREATE TABLE `glpi_plugin_sprint_sprintimprovements` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprints_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `category` VARCHAR(32) NOT NULL DEFAULT 'action',
            `description` TEXT NOT NULL,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `due_date` DATE NULL DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'open',
            `votes` INT UNSIGNED NOT NULL DEFAULT 0,
            `carry_to_next` TINYINT NOT NULL DEFAULT 1,
            `is_anonymous` TINYINT NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `sprint_status` (`plugin_sprint_sprints_id`,`status`), KEY `owner` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC",
        'glpi_plugin_sprint_sprintimprovementvotes' => "CREATE TABLE `glpi_plugin_sprint_sprintimprovementvotes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprintimprovements_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_sprint_sprintimprovements_id`, `users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC",
        'glpi_plugin_sprint_sprintsignals' => "CREATE TABLE `glpi_plugin_sprint_sprintsignals` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprints_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `signal_type` VARCHAR(40) NOT NULL DEFAULT 'info',
            `message` TEXT NOT NULL,
            `url` VARCHAR(1000) NOT NULL DEFAULT '',
            `is_read` TINYINT NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `user_read` (`users_id`,`is_read`), KEY `sprint` (`plugin_sprint_sprints_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC",
    ];
    foreach ($agilityTables as $table => $query) {
        if (!$DB->tableExists($table)) {
            $DB->doQueryOrDie($query, $DB->error());
        }
    }
    if ($DB->tableExists('glpi_plugin_sprint_sprintimprovements')) {
        $migration->addField('glpi_plugin_sprint_sprintimprovements', 'is_anonymous', 'bool', ['value' => 0, 'after' => 'carry_to_next']);
    }
    if ($DB->tableExists('glpi_plugin_sprint_sprintepics')) {
        $migration->addField('glpi_plugin_sprint_sprintepics', 'entities_id', 'integer', ['value' => 0, 'after' => 'plugin_sprint_sprints_id']);
        $migration->addKey('glpi_plugin_sprint_sprintepics', 'entities_id');
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_sprintitemtags (multi-tag per sprint item)
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprintitemtags')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprintitemtags` (
            `id`                           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprintitems_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `tag`                          VARCHAR(100) NOT NULL DEFAULT '',
            `date_creation`                TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_sprint_sprintitems_id`, `tag`),
            KEY `tag` (`tag`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
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
            `capacity`                      DECIMAL(5,1) NOT NULL DEFAULT 0 COMMENT 'Capacity allocated to this user for this fastlane item, in %',
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
    // Table: glpi_plugin_sprint_sprintitemdependencies
    // Soft-resolve via is_resolved keeps the row for audit while releasing
    // the helper's capacity.
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprintitemdependencies')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprintitemdependencies` (
            `id`                            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprintitems_id`  INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id`                      INT UNSIGNED NOT NULL DEFAULT 0,
            `capacity`                      DECIMAL(5,1) NOT NULL DEFAULT 0 COMMENT 'Capacity allocated to this dependency, in %',
            `is_resolved`                   TINYINT NOT NULL DEFAULT 0,
            `comment`                       TEXT,
            `date_creation`                 TIMESTAMP NULL DEFAULT NULL,
            `date_mod`                      TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_sprint_sprintitems_id`, `users_id`),
            KEY `plugin_sprint_sprintitems_id` (`plugin_sprint_sprintitems_id`),
            KEY `users_id` (`users_id`),
            KEY `is_resolved` (`is_resolved`)
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
            `users_id`                 INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Facilitator',
            `meeting_status`           VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'Guided session: open / in_progress / completed',
            `current_phase`            INT UNSIGNED NOT NULL DEFAULT 0,
            `started_at`               TIMESTAMP NULL DEFAULT NULL,
            `ended_at`                 TIMESTAMP NULL DEFAULT NULL,
            `phase_started_at`         TIMESTAMP NULL DEFAULT NULL,
            `phase_notes`              LONGTEXT COMMENT 'JSON object of per-phase notes keyed by phase key',
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

    // Migration: guided meeting session state (review/retrospective rail);
    // the never-used attendees/treated_items columns are dropped.
    if ($DB->tableExists('glpi_plugin_sprint_sprintmeetings')) {
        $meetingsTable = 'glpi_plugin_sprint_sprintmeetings';
        $migration->dropField($meetingsTable, 'attendees');
        $migration->dropField($meetingsTable, 'treated_items');
        $migration->addField($meetingsTable, 'meeting_status', "VARCHAR(20) NOT NULL DEFAULT 'open'", ['after' => 'users_id']);
        $migration->addField($meetingsTable, 'current_phase', 'INT UNSIGNED NOT NULL DEFAULT 0', ['after' => 'meeting_status']);
        $migration->addField($meetingsTable, 'started_at', 'TIMESTAMP NULL DEFAULT NULL', ['after' => 'current_phase']);
        $migration->addField($meetingsTable, 'ended_at', 'TIMESTAMP NULL DEFAULT NULL', ['after' => 'started_at']);
        $migration->addField($meetingsTable, 'phase_started_at', 'TIMESTAMP NULL DEFAULT NULL', ['after' => 'ended_at']);
        $migration->addField($meetingsTable, 'phase_notes', 'longtext', ['after' => 'phase_started_at']);
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
            `capacity_percent`         DECIMAL(5,1) NOT NULL DEFAULT 100 COMMENT 'Beschikbaarheid in %',
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
    // Table: glpi_plugin_sprint_sprintproblems (link sprints <-> problems)
    // With users_id to assign a sprint member to the linked problem
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprintproblems')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprintproblems` (
            `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprints_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `problems_id`              INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id`                 INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Assigned sprint member',
            `date_creation`            TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_sprint_sprints_id`, `problems_id`),
            KEY `plugin_sprint_sprints_id` (`plugin_sprint_sprints_id`),
            KEY `problems_id` (`problems_id`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
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
    if ($DB->tableExists('glpi_plugin_sprint_sprinttemplates')) {
        $migration->addField('glpi_plugin_sprint_sprinttemplates', 'wip_in_progress', 'integer', ['value' => 0, 'after' => 'goal']);
        $migration->addField('glpi_plugin_sprint_sprinttemplates', 'wip_review', 'integer', ['value' => 0, 'after' => 'wip_in_progress']);
        $migration->addField('glpi_plugin_sprint_sprinttemplates', 'wip_dependency', 'integer', ['value' => 0, 'after' => 'wip_review']);
        $migration->addField('glpi_plugin_sprint_sprinttemplates', 'wip_hard', 'bool', ['value' => 0, 'after' => 'wip_dependency']);
        $migration->addField('glpi_plugin_sprint_sprinttemplates', 'fastlane_capacity', 'integer', ['value' => 0, 'after' => 'wip_hard']);
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
            `capacity_percent`                 DECIMAL(5,1) NOT NULL DEFAULT 100,
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

    // =========================================================================
    // Table: glpi_plugin_sprint_sprinttemplateavailabilities (fixed weekly leave,
    // materialized into sprint availability exceptions on template apply)
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprinttemplateavailabilities')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprinttemplateavailabilities` (
            `id`                               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprinttemplates_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id`                         INT UNSIGNED NOT NULL DEFAULT 0,
            `weekday`                          TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'ISO weekday 1=Monday .. 5=Friday',
            `availability_percent`             DECIMAL(5,1) NOT NULL DEFAULT 0,
            `comment`                          VARCHAR(255) NOT NULL DEFAULT '',
            `date_creation`                    TIMESTAMP NULL DEFAULT NULL,
            `date_mod`                         TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_sprint_sprinttemplates_id` (`plugin_sprint_sprinttemplates_id`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_audit_sources
    // Attributes a glpi_logs row to the entity that triggered it (today:
    // meeting saves that fan out into SprintItem updates).
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_audit_sources')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_audit_sources` (
            `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `glpi_logs_id`    INT UNSIGNED NOT NULL,
            `source_itemtype` VARCHAR(255) NOT NULL DEFAULT '',
            `source_items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation`   TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `glpi_logs_id` (`glpi_logs_id`),
            KEY `source` (`source_itemtype`, `source_items_id`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_meetingblockedsnapshots
    // Records which SprintItems were blocked as of each meeting. The next
    // meeting compares against this set so already-blocked items aren't
    // re-flagged — independent of the meetings' scheduled dates.
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_meetingblockedsnapshots')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_meetingblockedsnapshots` (
            `id`                             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_sprint_sprintmeetings_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_sprint_sprintitems_id`    INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation`                  TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `meeting_item` (`plugin_sprint_sprintmeetings_id`, `plugin_sprint_sprintitems_id`),
            KEY `meeting` (`plugin_sprint_sprintmeetings_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // =========================================================================
    // Table: glpi_plugin_sprint_sprintrequests
    // Approval requests from non-Scrum-Masters: assigning a backlog item to a
    // sprint, or changing an item's capacity % or category inside a sprint.
    // The target sprint's Scrum Master accepts (performs it) or rejects.
    // Name must match SprintRequest::getTable() (class SprintRequest).
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_sprint_sprintrequests')) {
        $query = "CREATE TABLE `glpi_plugin_sprint_sprintrequests` (
            `id`                           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `request_type`                 VARCHAR(20) NOT NULL DEFAULT 'assign' COMMENT 'assign | capacity | category',
            `plugin_sprint_sprintitems_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_sprint_sprints_id`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Target sprint (assign) or the item sprint (capacity/category)',
            `users_id`                     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Requester',
            `requested_capacity`           DECIMAL(5,1) NOT NULL DEFAULT 0,
            `requested_category_id`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Requested backlog category for a category request',
            `reason`                       TEXT NULL COMMENT 'Requester motivation shown to the Scrum Master',
            `status`                       VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending | accepted | rejected',
            `users_id_validate`            INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Scrum Master who handled it',
            `date_creation`                TIMESTAMP NULL DEFAULT NULL,
            `date_mod`                     TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `item` (`plugin_sprint_sprintitems_id`),
            KEY `sprint_status` (`plugin_sprint_sprints_id`, `status`),
            KEY `requester` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // Migration: requester motivation for approval requests (also applied
    // lazily by SprintRequest::ensureTable() for running installations).
    if ($DB->tableExists('glpi_plugin_sprint_sprintrequests')
        && !$DB->fieldExists('glpi_plugin_sprint_sprintrequests', 'reason')) {
        $DB->doQueryOrDie(
            "ALTER TABLE `glpi_plugin_sprint_sprintrequests` ADD COLUMN `reason` TEXT NULL "
            . "COMMENT 'Requester motivation shown to the Scrum Master'",
            $DB->error()
        );
    }

    // Migration: target category for 'category' approval requests (also
    // applied lazily by SprintRequest::ensureTable() for running installations).
    if ($DB->tableExists('glpi_plugin_sprint_sprintrequests')
        && !$DB->fieldExists('glpi_plugin_sprint_sprintrequests', 'requested_category_id')) {
        $DB->doQueryOrDie(
            "ALTER TABLE `glpi_plugin_sprint_sprintrequests` ADD COLUMN `requested_category_id` INT UNSIGNED NOT NULL DEFAULT 0 "
            . "COMMENT 'Requested backlog category for a category request'",
            $DB->error()
        );
    }

    // Migration: capacity columns INT -> DECIMAL(5,1) for 0.5% granularity.
    $capacityColumns = [
        'glpi_plugin_sprint_sprints'               => ['fastlane_capacity' => '0'],
        'glpi_plugin_sprint_sprintitems'           => ['capacity' => '0'],
        'glpi_plugin_sprint_sprintfastlanemembers' => ['capacity' => '0'],
        'glpi_plugin_sprint_sprintitemdependencies' => ['capacity' => '0'],
        'glpi_plugin_sprint_sprintmembers'         => ['capacity_percent' => '100'],
        'glpi_plugin_sprint_sprinttemplatemembers' => ['capacity_percent' => '100'],
    ];
    foreach ($capacityColumns as $table => $columns) {
        if (!$DB->tableExists($table)) {
            continue;
        }
        $tableFields = $DB->listFields($table);
        foreach ($columns as $column => $default) {
            $fieldType = $tableFields[$column]['Type'] ?? '';
            if ($fieldType !== '' && stripos($fieldType, 'decimal') === false) {
                $migration->changeField(
                    $table,
                    $column,
                    $column,
                    "DECIMAL(5,1) NOT NULL DEFAULT '{$default}'"
                );
            }
        }
    }

    // Cleanup: remove duplicate SprintItem rows for the same linked GLPI
    // item. Pre-1.0.9 paths could create two SprintItems per (sprint, item)
    // pair, and a backlog row could coexist with the sprint version.
    // Runs on every install/upgrade (cheap when there are no dupes).
    if ($DB->tableExists('glpi_plugin_sprint_sprintitems')) {
        // Delete duplicates within the same sprint, keeping the lowest id.
        $dupeQuery = "DELETE si FROM `glpi_plugin_sprint_sprintitems` si
            INNER JOIN `glpi_plugin_sprint_sprintitems` sj
                ON si.plugin_sprint_sprints_id = sj.plugin_sprint_sprints_id
               AND si.itemtype = sj.itemtype
               AND si.items_id = sj.items_id
               AND si.id > sj.id
            WHERE si.itemtype <> ''
              AND si.items_id > 0
              AND si.plugin_sprint_sprints_id > 0";
        $DB->doQuery($dupeQuery);

        // Delete backlog rows (sprints_id = 0) for items that already live
        // in at least one sprint.
        $backlogQuery = "DELETE bl FROM `glpi_plugin_sprint_sprintitems` bl
            INNER JOIN `glpi_plugin_sprint_sprintitems` sp
                ON sp.itemtype = bl.itemtype
               AND sp.items_id = bl.items_id
            WHERE bl.plugin_sprint_sprints_id = 0
              AND sp.plugin_sprint_sprints_id > 0
              AND bl.itemtype <> ''
              AND bl.items_id > 0";
        $DB->doQuery($backlogQuery);

        // Collapse multiple backlog rows for the same linked item — keep
        // the lowest id.
        $backlogDupeQuery = "DELETE bi FROM `glpi_plugin_sprint_sprintitems` bi
            INNER JOIN `glpi_plugin_sprint_sprintitems` bj
                ON bi.itemtype = bj.itemtype
               AND bi.items_id = bj.items_id
               AND bi.plugin_sprint_sprints_id = 0
               AND bj.plugin_sprint_sprints_id = 0
               AND bi.id > bj.id
            WHERE bi.itemtype <> ''
              AND bi.items_id > 0";
        $DB->doQuery($backlogDupeQuery);
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

    // Register the nightly audit-log cleanup cron. Purges sprint-related
    // glpi_logs rows older than the earliest existing sprint's start
    // date so each sprint keeps its own history for its full window.
    CronTask::Register(
        'GlpiPlugin\Sprint\SprintAudit',
        'AuditCleanup',
        DAY_TIMESTAMP,
        [
            'comment' => 'Purge SprintManager audit log entries older than the retention window',
            'mode'    => CronTask::MODE_INTERNAL,
        ]
    );
    CronTask::Register(
        'GlpiPlugin\Sprint\SprintAgility',
        'SprintSignals',
        HOUR_TIMESTAMP,
        [
            'comment' => 'Create SprintManager meeting, approval and risk signals',
            'mode'    => CronTask::MODE_INTERNAL,
        ]
    );
    CronTask::Register(
        'GlpiPlugin\Sprint\SprintItem',
        'BacklogCleanup',
        DAY_TIMESTAMP,
        [
            'comment' => 'Remove backlog items whose linked item is solved or closed',
            'mode'    => CronTask::MODE_INTERNAL,
        ]
    );

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
        'glpi_plugin_sprint_sprintcreditproducts',
        'glpi_plugin_sprint_sprintretainers',
        'glpi_plugin_sprint_sprintcustomercredits',
        'glpi_plugin_sprint_sprintcredits',
        'glpi_plugin_sprint_sprintcustomers',
        'glpi_plugin_sprint_backlogflow',
        'glpi_plugin_sprint_sprintcategorycaps',
        'glpi_plugin_sprint_sprintcategories',
        'glpi_plugin_sprint_sprintsignals',
        'glpi_plugin_sprint_sprintimprovementvotes',
        'glpi_plugin_sprint_sprintimprovements',
        'glpi_plugin_sprint_sprintavailabilities',
        'glpi_plugin_sprint_sprintepics',
        'glpi_plugin_sprint_sprintrequests',
        'glpi_plugin_sprint_meetingblockedsnapshots',
        'glpi_plugin_sprint_audit_sources',
        'glpi_plugin_sprint_sprinttemplatemeetings',
        'glpi_plugin_sprint_sprinttemplateitems',
        'glpi_plugin_sprint_sprinttemplateavailabilities',
        'glpi_plugin_sprint_sprinttemplatemembers',
        'glpi_plugin_sprint_sprinttemplates',
        'glpi_plugin_sprint_profiles',
        'glpi_plugin_sprint_sprintprojecttasks',
        'glpi_plugin_sprint_sprintproblems',
        'glpi_plugin_sprint_sprintchanges',
        'glpi_plugin_sprint_sprinttickets',
        'glpi_plugin_sprint_sprintstandups',
        'glpi_plugin_sprint_sprintmeetings',
        'glpi_plugin_sprint_sprintitemdependencies',
        'glpi_plugin_sprint_sprintfastlanemembers',
        'glpi_plugin_sprint_sprintitemtags',
        'glpi_plugin_sprint_sprintmembers',
        'glpi_plugin_sprint_sprintitems',
        'glpi_plugin_sprint_sprints',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQueryOrDie("DROP TABLE `{$table}`", $DB->error());
        }
    }

    // Unregister cron task
    CronTask::unregister('sprint');

    // Remove display preferences
    $pref = new DisplayPreference();
    $pref->deleteByCriteria([
        'itemtype' => ['LIKE', 'GlpiPlugin\\\\Sprint\\\\%'],
    ]);

    // Remove the plugin's profile rights
    GlpiPlugin\Sprint\Profile::uninstallRights();

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

function plugin_sprint_display_central(): void
{
    if (Session::haveRight('plugin_sprint_sprint', READ)) {
        GlpiPlugin\Sprint\SprintAgility::renderHomeWidget();
    }
}

/**
 * Check configuration
 *
 * @param bool $verbose
 * @return boolean
 */
function plugin_sprint_check_config(bool $verbose = false): bool
{
    // The plugin works out of the box — no mandatory configuration step.
    return true;
}
