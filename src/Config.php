<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use CommonGLPI;
use Config as GlpiConfig;
use Html;
use Session;

/**
 * Plugin-wide settings, stored in GLPI's glpi_configs under
 * context='plugin:sprint' and exposed as a tab on Setup > General config.
 */
class Config extends CommonDBTM
{
    public static $rightname = 'config';

    const CONTEXT = 'plugin:sprint';

    /** Only the sprint's Scrum Master may edit capacity on regular sprint items. */
    const CFG_SCRUM_MASTER_CAPACITY = 'scrum_master_only_capacity';

    /** Optional URL or relative path to a logo to embed in the sprint export
     *  report header. Overrides any auto-detected logo. */
    const CFG_REPORT_LOGO_URL = 'report_logo_url';

    /** JSON-encoded pool of tag labels admins make available for sprint items;
     *  members pick from it, only admins add new entries. */
    const CFG_SPRINT_ITEM_TAGS = 'sprint_item_tags';

    /** When a backlog item with an estimated capacity %% joins a sprint,
     *  seed its story points from that capacity (1%% = 1 SP). Default off. */
    const CFG_CAPACITY_TO_POINTS = 'backlog_capacity_to_story_points';

    /** Purge backlog rows automatically once their linked Ticket / Change /
     *  Problem / ProjectTask is solved or closed. Default on. */
    const CFG_BACKLOG_AUTO_CLEANUP = 'backlog_auto_cleanup';

    /** Days before an unplanned active backlog item gets an aging badge
     *  (red at twice this value). 0 disables. */
    const CFG_BACKLOG_AGING_DAYS = 'backlog_aging_days';

    /** Default min/max capacity %% per category, JSON {"<catId>":{"min":x,"max":y}}.
     *  Applied to sprints that have no explicit limits for that category. */
    const CFG_CATEGORY_DEFAULT_LIMITS = 'category_default_limits';

    /** Global Definition of Ready / Definition of Done, one check per line. */
    const CFG_DEFINITION_READY = 'definition_ready';
    const CFG_DEFINITION_DONE  = 'definition_done';

    public static function getTypeName($nb = 0): string
    {
        return __('SprintManager', 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-running';
    }

    /**
     * Return current configuration values with defaults applied.
     *
     * @return array<string,mixed>
     */
    public static function getConfig(): array
    {
        $defaults = [
            self::CFG_SCRUM_MASTER_CAPACITY => 0,
            self::CFG_REPORT_LOGO_URL       => '',
            self::CFG_SPRINT_ITEM_TAGS      => '[]',
            self::CFG_CAPACITY_TO_POINTS    => 0,
            self::CFG_BACKLOG_AUTO_CLEANUP  => 1,
            self::CFG_BACKLOG_AGING_DAYS    => 21,
            self::CFG_CATEGORY_DEFAULT_LIMITS => '{}',
            self::CFG_DEFINITION_READY      => '',
            self::CFG_DEFINITION_DONE       => '',
        ];
        $stored = GlpiConfig::getConfigurationValues(self::CONTEXT);
        return array_merge($defaults, $stored);
    }

    /**
     * Admin-defined tag pool: de-duplicated, trimmed labels in original casing.
     *
     * @return string[]
     */
    public static function getDefinedTags(): array
    {
        $cfg  = self::getConfig();
        $raw  = (string)($cfg[self::CFG_SPRINT_ITEM_TAGS] ?? '[]');
        $list = json_decode($raw, true);
        if (!is_array($list)) {
            return [];
        }
        $out  = [];
        $seen = [];
        foreach ($list as $tag) {
            $tag = trim((string)$tag);
            if ($tag === '') {
                continue;
            }
            $key = mb_strtolower($tag);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $tag;
        }
        return $out;
    }

    /** @return string[] Global Definition of Ready checks. */
    public static function getDefinitionReady(): array
    {
        return SprintAgility::checklist((string)(self::getConfig()[self::CFG_DEFINITION_READY] ?? ''));
    }

    /** @return string[] Global Definition of Done checks. */
    public static function getDefinitionDone(): array
    {
        return SprintAgility::checklist((string)(self::getConfig()[self::CFG_DEFINITION_DONE] ?? ''));
    }

    public static function getReportLogoUrl(): string
    {
        $cfg = self::getConfig();
        return trim((string)($cfg[self::CFG_REPORT_LOGO_URL] ?? ''));
    }

    /**
     * Whether the "only Scrum Master can edit capacity" guard is enabled.
     */
    public static function isScrumMasterOnlyCapacity(): bool
    {
        $cfg = self::getConfig();
        return (int)($cfg[self::CFG_SCRUM_MASTER_CAPACITY] ?? 0) === 1;
    }

    /**
     * Whether backlog estimated capacity should seed story points (1% = 1 SP)
     * when an item is assigned to a sprint.
     */
    public static function isBacklogCapacityToStoryPoints(): bool
    {
        $cfg = self::getConfig();
        return (int)($cfg[self::CFG_CAPACITY_TO_POINTS] ?? 0) === 1;
    }

    /**
     * Whether solved/closed linked items are automatically purged from the
     * backlog (hook on item updates + daily cron sweeper).
     */
    public static function isBacklogAutoCleanup(): bool
    {
        $cfg = self::getConfig();
        return (int)($cfg[self::CFG_BACKLOG_AUTO_CLEANUP] ?? 1) === 1;
    }

    /** Aging badge threshold in days, 0 = disabled. */
    public static function getBacklogAgingDays(): int
    {
        $cfg = self::getConfig();
        return max(0, (int)($cfg[self::CFG_BACKLOG_AGING_DAYS] ?? 21));
    }

    /**
     * Default category limits, all-zero entries omitted.
     *
     * @return array<int,array{min:float,max:float}> category id => limits
     */
    public static function getCategoryDefaultLimits(): array
    {
        $cfg = self::getConfig();
        $raw = json_decode((string)($cfg[self::CFG_CATEGORY_DEFAULT_LIMITS] ?? '{}'), true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $categoryId => $limit) {
            $categoryId = (int)$categoryId;
            $min = max(0.0, min(9999.0, (float)($limit['min'] ?? 0)));
            $max = max(0.0, min(9999.0, (float)($limit['max'] ?? 0)));
            if ($categoryId > 0 && ($min > 0 || $max > 0)) {
                $out[$categoryId] = ['min' => $min, 'max' => $max];
            }
        }
        return $out;
    }

    /** Persist the default category limits map (from the category manager form). */
    public static function saveCategoryDefaultLimits(array $limits): void
    {
        $clean = [];
        foreach ($limits as $categoryId => $limit) {
            $categoryId = (int)$categoryId;
            $min = max(0.0, min(9999.0, (float)($limit['min'] ?? 0)));
            $max = max(0.0, min(9999.0, (float)($limit['max'] ?? 0)));
            if ($min > 0 && $max > 0 && $min > $max) {
                [$min, $max] = [$max, $min];
            }
            if ($categoryId > 0 && ($min > 0 || $max > 0)) {
                $clean[$categoryId] = ['min' => $min, 'max' => $max];
            }
        }
        GlpiConfig::setConfigurationValues(self::CONTEXT, [
            self::CFG_CATEGORY_DEFAULT_LIMITS => json_encode($clean),
        ]);
    }

    /**
     * Persist a full set of plugin settings (called from front/config.form.php).
     */
    public static function saveConfig(array $input): void
    {
        $values = [
            self::CFG_SCRUM_MASTER_CAPACITY => (int)(bool)($input[self::CFG_SCRUM_MASTER_CAPACITY] ?? 0),
            self::CFG_REPORT_LOGO_URL       => trim((string)($input[self::CFG_REPORT_LOGO_URL] ?? '')),
            self::CFG_SPRINT_ITEM_TAGS      => self::normalizeTagInput((string)($input[self::CFG_SPRINT_ITEM_TAGS] ?? '')),
            self::CFG_CAPACITY_TO_POINTS    => (int)(bool)($input[self::CFG_CAPACITY_TO_POINTS] ?? 0),
            self::CFG_BACKLOG_AUTO_CLEANUP  => (int)(bool)($input[self::CFG_BACKLOG_AUTO_CLEANUP] ?? 0),
            self::CFG_BACKLOG_AGING_DAYS    => max(0, min(365, (int)($input[self::CFG_BACKLOG_AGING_DAYS] ?? 21))),
            self::CFG_DEFINITION_READY      => implode("\n", SprintAgility::checklist((string)($input[self::CFG_DEFINITION_READY] ?? ''))),
            self::CFG_DEFINITION_DONE       => implode("\n", SprintAgility::checklist((string)($input[self::CFG_DEFINITION_DONE] ?? ''))),
        ];
        GlpiConfig::setConfigurationValues(self::CONTEXT, $values);
    }

    /**
     * Parse the textarea input (one tag per line, comma-separated also OK)
     * into a JSON-encoded de-duplicated list.
     */
    private static function normalizeTagInput(string $raw): string
    {
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $out   = [];
        $seen  = [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p === '') {
                continue;
            }
            $key = mb_strtolower($p);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $p;
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Whether the current user is the sprint's Scrum Master. New sprints
     * (no id yet) return true so the creation flow isn't gated.
     */
    public static function isCurrentUserScrumMaster(int $sprintId): bool
    {
        if ($sprintId <= 0) {
            return true;
        }
        $sprint = new Sprint();
        if (!$sprint->getFromDB($sprintId)) {
            return true;
        }
        return (int)$sprint->fields['users_id'] === (int)Session::getLoginUserID();
    }

    // === GLPI Config tab integration ===

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item->getType() === GlpiConfig::class) {
            return self::createTabEntry(self::getTypeName(), 0, $item::getType(), self::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item->getType() === GlpiConfig::class) {
            self::showConfigForm();
            return true;
        }
        return false;
    }

    /**
     * Render the settings form on the Config tab.
     */
    public static function showConfigForm(): void
    {
        $canedit = Session::haveRight('config', UPDATE);
        $cfg     = self::getConfig();

        echo "<div class='center'>";
        if ($canedit) {
            echo "<form method='post' action='" . \Plugin::getWebDir('sprint') . "/front/config.form.php'>";
        }

        echo "<table class='tab_cadre_fixe sprint-themed'>";
        echo "<tr class='tab_bg_2'><th colspan='2'>" .
            "<i class='" . self::getIcon() . "' style='margin-right:6px;'></i>" .
            __('SprintManager settings', 'sprint') . "</th></tr>";

        // Scrum Master only edits capacity
        $checked = (int)$cfg[self::CFG_SCRUM_MASTER_CAPACITY] === 1 ? 'checked' : '';
        echo "<tr class='tab_bg_1'>";
        echo "<td style='width:50%;'>" . __('Only Scrum Master can edit capacity on sprint items', 'sprint') . "<br>";
        echo "<span class='text-muted' style='font-size:0.85em;'>" .
            __('When enabled, only the sprint\'s Scrum Master may change the capacity % on regular sprint items. Fastlane item capacity remains editable by every sprint member.', 'sprint') .
            "</span></td>";
        echo "<td>";
        echo "<input type='hidden' name='" . self::CFG_SCRUM_MASTER_CAPACITY . "' value='0'>";
        echo "<label class='form-check form-switch'>";
        echo "<input class='form-check-input' type='checkbox' role='switch' "
            . "name='" . self::CFG_SCRUM_MASTER_CAPACITY . "' value='1' {$checked}"
            . ($canedit ? '' : ' disabled') . ">";
        echo "<span class='form-check-label ms-2'>" . __('Enable') . "</span>";
        echo "</label>";
        echo "</td></tr>";

        // Backlog capacity → story points on sprint assignment
        $checkedCap = (int)$cfg[self::CFG_CAPACITY_TO_POINTS] === 1 ? 'checked' : '';
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Convert backlog capacity to story points on sprint assignment', 'sprint') . "<br>";
        echo "<span class='text-muted' style='font-size:0.85em;'>" .
            __('When enabled, assigning a backlog item with an estimated capacity % to a sprint automatically sets its story points to that capacity (1% = 1 story point). An explicitly estimated item keeps its own story points.', 'sprint') .
            "</span></td>";
        echo "<td>";
        echo "<input type='hidden' name='" . self::CFG_CAPACITY_TO_POINTS . "' value='0'>";
        echo "<label class='form-check form-switch'>";
        echo "<input class='form-check-input' type='checkbox' role='switch' "
            . "name='" . self::CFG_CAPACITY_TO_POINTS . "' value='1' {$checkedCap}"
            . ($canedit ? '' : ' disabled') . ">";
        echo "<span class='form-check-label ms-2'>" . __('Enable') . "</span>";
        echo "</label>";
        echo "</td></tr>";

        // Auto-purge solved linked items from the backlog
        $checkedCleanup = (int)$cfg[self::CFG_BACKLOG_AUTO_CLEANUP] === 1 ? 'checked' : '';
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Automatically remove solved items from the backlog', 'sprint') . "<br>";
        echo "<span class='text-muted' style='font-size:0.85em;'>" .
            __('When enabled, a backlog item is removed as soon as its linked ticket, change, problem or project task is solved or closed. A daily cron task also sweeps the backlog for items solved outside the normal update flow.', 'sprint') .
            "</span></td>";
        echo "<td>";
        echo "<input type='hidden' name='" . self::CFG_BACKLOG_AUTO_CLEANUP . "' value='0'>";
        echo "<label class='form-check form-switch'>";
        echo "<input class='form-check-input' type='checkbox' role='switch' "
            . "name='" . self::CFG_BACKLOG_AUTO_CLEANUP . "' value='1' {$checkedCleanup}"
            . ($canedit ? '' : ' disabled') . ">";
        echo "<span class='form-check-label ms-2'>" . __('Enable') . "</span>";
        echo "</label>";
        echo "</td></tr>";

        // Aging badge threshold
        $agingDays = (int)$cfg[self::CFG_BACKLOG_AGING_DAYS];
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Backlog aging threshold (days)', 'sprint') . "<br>";
        echo "<span class='text-muted' style='font-size:0.85em;'>" .
            __('Unplanned active backlog items older than this get an aging badge (red at twice this value). 0 disables.', 'sprint') .
            "</span></td>";
        echo "<td>";
        echo "<input type='number' min='0' max='365' class='form-control' style='max-width:120px;' "
            . "name='" . self::CFG_BACKLOG_AGING_DAYS . "' value='{$agingDays}'"
            . ($canedit ? '' : ' readonly') . ">";
        echo "</td></tr>";

        // Report logo URL — explicit override for the export header, for when
        // custom-CSS branding (no `central_logo`) defeats logo auto-detection.
        $logoUrl = htmlescape((string)$cfg[self::CFG_REPORT_LOGO_URL]);
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Report logo URL', 'sprint') . "<br>";
        echo "<span class='text-muted' style='font-size:0.85em;'>" .
            __('Optional. Absolute URL or path relative to the GLPI install (e.g. "front/pics/logo.png", "https://example.com/logo.svg") used in the sprint export report header. Leave empty to auto-detect from GLPI\'s configured central logo or your custom CSS.', 'sprint') .
            "</span></td>";
        echo "<td>";
        echo "<input type='text' class='form-control' name='" . self::CFG_REPORT_LOGO_URL . "' "
            . "value='{$logoUrl}' placeholder='https://...' "
            . ($canedit ? '' : 'readonly') . ">";
        echo "</td></tr>";

        $dorRaw = implode("\n", self::getDefinitionReady());
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Definition of Ready — one check per line', 'sprint') . "<br>";
        echo "<span class='text-muted' style='font-size:0.85em;'>" .
            __('The Scrum Master confirms these checks when assigning backlog items to a sprint.', 'sprint') .
            "</span></td>";
        echo "<td>";
        echo "<textarea name='" . self::CFG_DEFINITION_READY . "' class='form-control' rows='4'"
            . ($canedit ? '' : ' readonly') . ">" . htmlescape($dorRaw) . "</textarea>";
        echo "</td></tr>";

        $dodRaw = implode("\n", self::getDefinitionDone());
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Definition of Done — one check per line', 'sprint') . "<br>";
        echo "<span class='text-muted' style='font-size:0.85em;'>" .
            __('Sprint members confirm these checks when moving an item to In Review or Done.', 'sprint') .
            "</span></td>";
        echo "<td>";
        echo "<textarea name='" . self::CFG_DEFINITION_DONE . "' class='form-control' rows='4'"
            . ($canedit ? '' : ' readonly') . ">" . htmlescape($dodRaw) . "</textarea>";
        echo "</td></tr>";

        $tagsRaw = implode("\n", self::getDefinedTags());
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Sprint item tags', 'sprint') . "<br>";
        echo "<span class='text-muted' style='font-size:0.85em;'>" .
            __('One tag per line (commas also accepted). Members can assign these tags to sprint items and filter on them, but only admins extend the list here.', 'sprint') .
            "</span></td>";
        echo "<td>";
        echo "<textarea name='" . self::CFG_SPRINT_ITEM_TAGS . "' class='form-control' rows='5' "
            . "placeholder='Security&#10;Projects&#10;Incident Response'"
            . ($canedit ? '' : ' readonly') . ">"
            . htmlescape($tagsRaw)
            . "</textarea>";
        echo "</td></tr>";

        if ($canedit) {
            echo "<tr class='tab_bg_1'><td colspan='2' class='center'>";
            echo Html::submit(__('Save'), [
                'name'  => 'update_sprint_config',
                'class' => 'btn btn-primary',
            ]);
            echo "</td></tr>";
        }

        echo "</table>";
        if ($canedit) {
            Html::closeForm();
        }
        echo "</div>";

        // Backlog category manager — its own form, below the settings table.
        SprintCategory::showManager();
    }
}
