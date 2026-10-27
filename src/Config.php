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

    /** Every save that carries the tag field (item form, quick edit, backlog
     *  edit) must keep at least one tag on the item. Default off. */
    const CFG_TAG_REQUIRED = 'sprint_item_tag_required';

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

    /** Named credit managers, JSON {"<userId>":"view"|"manage"}: credit access
     *  for individual users, on top of the profile right. */
    const CFG_CREDIT_MANAGERS = 'credit_managers';

    /** Global Definition of Ready / Definition of Done, one check per line. */
    const CFG_DEFINITION_READY = 'definition_ready';
    const CFG_DEFINITION_DONE  = 'definition_done';

    /** Maximum capacity %% one regular item may claim. Above it the planner
     *  gives a reason and the Scrum Master reviews the item on assignment.
     *  Default off, 10 %% when enabled. */
    const CFG_ITEM_CAP_LIMIT   = 'item_capacity_limit_enabled';
    const CFG_ITEM_CAP_PERCENT = 'item_capacity_limit_percent';

    /** Warn the Scrum Master when assigning an item pushes its customer past
     *  the credits agreed for that sprint. Default on. */
    const CFG_CREDIT_OVERRUN_WARN = 'credit_overrun_warning';

    /** Dot-votes every member gets in the retrospective. Default 3. */
    const CFG_RETRO_VOTES = 'retro_votes_per_member';

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
            self::CFG_TAG_REQUIRED          => 0,
            self::CFG_CAPACITY_TO_POINTS    => 0,
            self::CFG_BACKLOG_AUTO_CLEANUP  => 1,
            self::CFG_BACKLOG_AGING_DAYS    => 21,
            self::CFG_CATEGORY_DEFAULT_LIMITS => '{}',
            self::CFG_CREDIT_MANAGERS       => '{}',
            self::CFG_DEFINITION_READY      => '',
            self::CFG_DEFINITION_DONE       => '',
            self::CFG_ITEM_CAP_LIMIT        => 0,
            self::CFG_ITEM_CAP_PERCENT      => 10,
            self::CFG_CREDIT_OVERRUN_WARN   => 1,
            self::CFG_RETRO_VOTES           => 3,
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

    /**
     * Whether an item must carry at least one tag. Only meaningful while the
     * admin pool has tags to pick from, so an empty pool never locks saves.
     */
    public static function isTagRequired(): bool
    {
        $cfg = self::getConfig();
        return (int)($cfg[self::CFG_TAG_REQUIRED] ?? 0) === 1 && count(self::getDefinedTags()) > 0;
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

    /**
     * Maximum capacity %% per regular item, 0 when the limit is off.
     */
    public static function getItemCapacityLimit(): float
    {
        $cfg = self::getConfig();
        if ((int)($cfg[self::CFG_ITEM_CAP_LIMIT] ?? 0) !== 1) {
            return 0.0;
        }
        return (float)max(1, min(100, (int)($cfg[self::CFG_ITEM_CAP_PERCENT] ?? 10)));
    }

    /** Whether assigning an item warns when its customer runs past the sprint's credits. */
    public static function isCreditOverrunWarning(): bool
    {
        $cfg = self::getConfig();
        return (int)($cfg[self::CFG_CREDIT_OVERRUN_WARN] ?? 1) === 1;
    }

    /** Dot-votes per member in a retrospective. */
    public static function getRetroVotesPerMember(): int
    {
        $cfg = self::getConfig();
        return max(1, min(20, (int)($cfg[self::CFG_RETRO_VOTES] ?? 3)));
    }

    /**
     * Credit access granted per user, on top of the profile right.
     *
     * @return array<int,string> user id => 'view' | 'manage'
     */
    public static function getCreditManagers(): array
    {
        $raw  = json_decode((string)(self::getConfig()[self::CFG_CREDIT_MANAGERS] ?? '{}'), true);
        $out  = [];
        foreach (is_array($raw) ? $raw : [] as $uid => $level) {
            $uid = (int)$uid;
            if ($uid > 0) {
                $out[$uid] = $level === 'manage' ? 'manage' : 'view';
            }
        }
        return $out;
    }

    /** '' when not listed. Cached: the right checks run once per rendered row. */
    public static function currentUserCreditLevel(): string
    {
        static $level = null;
        if ($level === null) {
            $uid   = (int)Session::getLoginUserID();
            $level = $uid > 0 ? (self::getCreditManagers()[$uid] ?? '') : '';
        }
        return $level;
    }

    /** Persist the credit-manager rows from the settings form. */
    public static function saveCreditManagers(array $input): void
    {
        if (!Session::haveRight('config', UPDATE)) {
            return;
        }
        $levels = (array)($input['credit_level'] ?? []);
        $delete = array_map('intval', (array)($input['credit_delete'] ?? []));

        $out = [];
        foreach ($levels as $uid => $level) {
            $uid = (int)$uid;
            if ($uid > 0 && !in_array($uid, $delete, true)) {
                $out[$uid] = $level === 'manage' ? 'manage' : 'view';
            }
        }
        $newId = (int)($input['credit_new_user'] ?? 0);
        if ($newId > 0) {
            $out[$newId] = ($input['credit_new_level'] ?? '') === 'manage' ? 'manage' : 'view';
        }

        GlpiConfig::setConfigurationValues(self::CONTEXT, [
            self::CFG_CREDIT_MANAGERS => json_encode($out),
        ]);
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
     * Persist plugin settings (called from front/config.form.php). Every
     * settings group posts its own form, so only the keys present in the
     * input are written: saving one group never resets another. A switch
     * posts a hidden 0 before its checkbox, so "off" is always present.
     */
    public static function saveConfig(array $input): void
    {
        $normalize = [
            self::CFG_SCRUM_MASTER_CAPACITY => static fn($v) => (int)(bool)$v,
            self::CFG_REPORT_LOGO_URL       => static fn($v) => trim((string)$v),
            self::CFG_SPRINT_ITEM_TAGS      => static fn($v) => self::normalizeTagInput((string)$v),
            self::CFG_TAG_REQUIRED          => static fn($v) => (int)(bool)$v,
            self::CFG_CAPACITY_TO_POINTS    => static fn($v) => (int)(bool)$v,
            self::CFG_BACKLOG_AUTO_CLEANUP  => static fn($v) => (int)(bool)$v,
            self::CFG_BACKLOG_AGING_DAYS    => static fn($v) => max(0, min(365, (int)$v)),
            self::CFG_DEFINITION_READY      => static fn($v) => implode("\n", SprintAgility::checklist((string)$v)),
            self::CFG_DEFINITION_DONE       => static fn($v) => implode("\n", SprintAgility::checklist((string)$v)),
            self::CFG_ITEM_CAP_LIMIT        => static fn($v) => (int)(bool)$v,
            self::CFG_ITEM_CAP_PERCENT      => static fn($v) => max(1, min(100, (int)$v)),
            self::CFG_CREDIT_OVERRUN_WARN   => static fn($v) => (int)(bool)$v,
            self::CFG_RETRO_VOTES           => static fn($v) => max(1, min(20, (int)$v)),
        ];
        $values = [];
        foreach ($normalize as $key => $fn) {
            if (array_key_exists($key, $input)) {
                $values[$key] = $fn($input[$key]);
            }
        }
        if ($values) {
            GlpiConfig::setConfigurationValues(self::CONTEXT, $values);
        }
    }

    /**
     * The settings groups, in page order: anchor key => [title, icon].
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function getSettingsSections(): array
    {
        return [
            'planning' => [__('Planning & rights', 'sprint'), 'fas fa-user-shield'],
            'quality'  => [__('Quality', 'sprint'), 'fas fa-clipboard-check'],
            'retro'    => [__('Retrospective', 'sprint'), 'fas fa-lightbulb'],
            'backlog'  => [__('Backlog', 'sprint'), 'fas fa-layer-group'],
            'tags'     => [__('Tags & categories', 'sprint'), 'fas fa-tags'],
            'credits'  => [__('Credits', 'sprint'), 'fas fa-coins'],
            'report'   => [__('Sprint report', 'sprint'), 'fas fa-file-lines'],
        ];
    }

    /** Remember which form was saved, so its card can confirm it after the redirect. */
    public static function markSaved(string $form): void
    {
        $_SESSION['plugin_sprint_config_saved'] = $form;
    }

    /** The inline "saved" line for $form, once; '' when another form was saved. */
    public static function savedNotice(string $form): string
    {
        if (($_SESSION['plugin_sprint_config_saved'] ?? '') !== $form) {
            return '';
        }
        unset($_SESSION['plugin_sprint_config_saved']);
        return "<div class='alert alert-success py-2 mb-3 sprint-small' role='status'>"
            . "<i class='fas fa-check me-1' aria-hidden='true'></i>"
            . htmlescape(__('Saved. These settings apply from the next page load.', 'sprint')) . "</div>";
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
     * Whether the current user is the sprint's Scrum Master. An unknown or
     * missing sprint id returns false; no caller runs during sprint creation.
     */
    public static function isCurrentUserScrumMaster(int $sprintId): bool
    {
        // Fail closed: without a (valid) sprint there is no Scrum Master, so
        // nobody passes a Scrum-Master-only gate on that basis.
        if ($sprintId <= 0) {
            return false;
        }
        $sprint = new Sprint();
        if (!$sprint->getFromDB($sprintId)) {
            return false;
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
     * Render the settings page on the Config tab: one card per group, each
     * its own form with its own save button and result line, so an admin
     * always knows which part was saved.
     */
    public static function showConfigForm(): void
    {
        $canedit  = Session::haveRight('config', UPDATE);
        $cfg      = self::getConfig();
        $sections = self::getSettingsSections();

        echo "<div class='sprint-settings' style='max-width:1100px;margin:0 auto;'>";
        echo "<h2 class='mb-2'><i class='" . self::getIcon() . " me-2' aria-hidden='true'></i>"
            . htmlescape(__('SprintManager settings', 'sprint')) . "</h2>";
        if (!$canedit) {
            echo "<div class='alert alert-info py-2 sprint-small'>"
                . htmlescape(__('You can view these settings. Changing them needs the right to update the GLPI setup.', 'sprint')) . "</div>";
        }
        echo "<nav class='sprint-settings-nav mb-3' aria-label='" . __s('Settings groups', 'sprint') . "'>";
        foreach ($sections as $key => [$title, $icon]) {
            echo "<a class='btn btn-sm btn-outline-secondary' href='#sprint-cfg-{$key}'>"
                . "<i class='{$icon} me-1' aria-hidden='true'></i>" . htmlescape($title) . "</a>";
        }
        echo "</nav>";

        // Planning & rights
        self::openSection('planning', __('Who may change what while a sprint is planned and running.', 'sprint'));
        self::openForm($canedit, 'planning');
        self::switchRow($cfg, self::CFG_SCRUM_MASTER_CAPACITY, $canedit,
            __('Only Scrum Master can edit capacity on sprint items', 'sprint'),
            __('When enabled, only the sprint\'s Scrum Master may change the capacity % on regular sprint items. Fastlane item capacity remains editable by every sprint member.', 'sprint'));
        self::switchRow($cfg, self::CFG_CAPACITY_TO_POINTS, $canedit,
            __('Convert backlog capacity to story points on sprint assignment', 'sprint'),
            __('When enabled, assigning a backlog item with an estimated capacity % to a sprint automatically sets its story points to that capacity (1% = 1 story point). An explicitly estimated item keeps its own story points.', 'sprint'));
        self::switchRow($cfg, self::CFG_ITEM_CAP_LIMIT, $canedit,
            __('Maximum capacity per item', 'sprint'),
            __('When enabled, an item above the percentage below needs a reason from the planner, and the Scrum Master approves or lowers it when the item is assigned to the sprint. Spreading work over several items is recommended for a better distribution through the sprint. Fastlane items are exempt.', 'sprint'));
        $id = 'sprint-cfg-' . self::CFG_ITEM_CAP_PERCENT;
        self::row($id, __('Maximum capacity per item (%)', 'sprint'),
            __('The percentage one regular item may claim without a reason, for example 10, 15 or 18.', 'sprint'),
            "<input type='number' min='1' max='100' step='1' class='form-control' style='max-width:120px;' id='{$id}' aria-describedby='{$id}-help' "
            . "name='" . self::CFG_ITEM_CAP_PERCENT . "' value='" . (int)$cfg[self::CFG_ITEM_CAP_PERCENT] . "'"
            . ($canedit ? '' : ' readonly') . ">");
        self::switchRow($cfg, self::CFG_CREDIT_OVERRUN_WARN, $canedit,
            __('Warn when a customer runs past its credits for the sprint', 'sprint'),
            __('When enabled, assigning an item whose credits push the customer past the credits agreed for that sprint asks the Scrum Master to confirm first.', 'sprint'));
        self::closeForm($canedit, 'planning');
        self::closeSection();

        // Retrospective
        self::openSection('retro', __('How the team votes on its retrospective input.', 'sprint'));
        self::openForm($canedit, 'retro');
        $id = 'sprint-cfg-' . self::CFG_RETRO_VOTES;
        self::row($id, __('Votes per member', 'sprint'),
            __('Dot-votes every member may spend in the Cluster & vote phase. Nobody can vote on their own input. The top three themes become actions, the rest returns in the next retrospective.', 'sprint'),
            "<input type='number' min='1' max='20' step='1' class='form-control' style='max-width:120px;' id='{$id}' aria-describedby='{$id}-help' "
            . "name='" . self::CFG_RETRO_VOTES . "' value='" . (int)$cfg[self::CFG_RETRO_VOTES] . "'"
            . ($canedit ? '' : ' readonly') . ">");
        self::closeForm($canedit, 'retro');
        self::closeSection();

        // Quality
        self::openSection('quality', __('The checklists that guard an item on its way into and out of a sprint.', 'sprint'));
        self::openForm($canedit, 'quality');
        self::textareaRow(self::CFG_DEFINITION_READY, implode("\n", self::getDefinitionReady()), $canedit, 4,
            __('Definition of Ready — one check per line', 'sprint'),
            __('The Scrum Master confirms these checks when assigning backlog items to a sprint.', 'sprint'));
        self::textareaRow(self::CFG_DEFINITION_DONE, implode("\n", self::getDefinitionDone()), $canedit, 4,
            __('Definition of Done — one check per line', 'sprint'),
            __('Sprint members confirm these checks when moving an item to In Review or Done.', 'sprint'));
        self::closeForm($canedit, 'quality');
        self::closeSection();

        // Backlog
        self::openSection('backlog', __('How the backlog keeps itself tidy.', 'sprint'));
        self::openForm($canedit, 'backlog');
        self::switchRow($cfg, self::CFG_BACKLOG_AUTO_CLEANUP, $canedit,
            __('Automatically remove solved items from the backlog', 'sprint'),
            __('When enabled, a backlog item is removed as soon as its linked ticket, change, problem or project task is solved or closed. A daily cron task also sweeps the backlog for items solved outside the normal update flow.', 'sprint'));
        $id = 'sprint-cfg-' . self::CFG_BACKLOG_AGING_DAYS;
        self::row($id, __('Backlog aging threshold (days)', 'sprint'),
            __('Unplanned active backlog items older than this get an aging badge (red at twice this value). 0 disables.', 'sprint'),
            "<input type='number' min='0' max='365' class='form-control' style='max-width:120px;' id='{$id}' aria-describedby='{$id}-help' "
            . "name='" . self::CFG_BACKLOG_AGING_DAYS . "' value='" . (int)$cfg[self::CFG_BACKLOG_AGING_DAYS] . "'"
            . ($canedit ? '' : ' readonly') . ">");
        self::closeForm($canedit, 'backlog');
        self::closeSection();

        // Tags & categories: two forms, each saved on its own.
        self::openSection('tags', __('The labels members pick from, and the categories the backlog and the capacity dashboard group by.', 'sprint'));
        self::openForm($canedit, 'tags');
        self::textareaRow(self::CFG_SPRINT_ITEM_TAGS, implode("\n", self::getDefinedTags()), $canedit, 5,
            __('Sprint item tags', 'sprint'),
            __('One tag per line (commas also accepted). Members can assign these tags to sprint items and filter on them, but only admins extend the list here.', 'sprint'),
            'Security&#10;Projects&#10;Incident Response');
        self::switchRow($cfg, self::CFG_TAG_REQUIRED, $canedit,
            __('Require a tag on every item', 'sprint'),
            __('When enabled, the item form, the quick-edit dialog and the backlog edit dialog refuse to save an item without at least one tag. Only applies while the tag list above has entries.', 'sprint'));
        self::closeForm($canedit, 'tags', __('Save tags', 'sprint'));
        echo "<hr class='my-3'>";
        echo self::savedNotice('categories');
        SprintCategory::showManager();
        self::closeSection();

        // Credits
        self::openSection('credits', __('Credit access for named users, on top of the profile right.', 'sprint'));
        echo self::savedNotice('credits');
        self::showCreditManagers();
        self::closeSection();

        // Sprint report
        self::openSection('report', __('How the printable sprint report looks.', 'sprint'));
        self::openForm($canedit, 'report');
        $id = 'sprint-cfg-' . self::CFG_REPORT_LOGO_URL;
        // Explicit override for the export header, for when custom-CSS
        // branding (no `central_logo`) defeats logo auto-detection.
        self::row($id, __('Report logo URL', 'sprint'),
            __('Optional. Absolute URL or path relative to the GLPI install (e.g. "front/pics/logo.png", "https://example.com/logo.svg") used in the sprint export report header. Leave empty to auto-detect from GLPI\'s configured central logo or your custom CSS.', 'sprint'),
            "<input type='text' class='form-control' id='{$id}' aria-describedby='{$id}-help' name='" . self::CFG_REPORT_LOGO_URL . "' "
            . "value='" . htmlescape((string)$cfg[self::CFG_REPORT_LOGO_URL]) . "' placeholder='https://...'"
            . ($canedit ? '' : ' readonly') . ">");
        self::closeForm($canedit, 'report');
        self::closeSection();

        echo "</div>";
    }

    private static function openSection(string $key, string $intro): void
    {
        [$title, $icon] = self::getSettingsSections()[$key];
        echo "<section class='card mb-3 sprint-settings-section' id='sprint-cfg-{$key}' aria-labelledby='sprint-cfg-{$key}-title'>";
        echo "<div class='card-header'><h3 class='card-title mb-0' id='sprint-cfg-{$key}-title'>"
            . "<i class='{$icon} me-2' aria-hidden='true'></i>" . htmlescape($title) . "</h3></div>";
        echo "<div class='card-body'>";
        echo "<p class='text-muted sprint-small mb-3'>" . htmlescape($intro) . "</p>";
    }

    private static function closeSection(): void
    {
        echo "</div></section>";
    }

    private static function openForm(bool $canedit, string $section): void
    {
        echo self::savedNotice($section);
        if ($canedit) {
            echo "<form method='post' action='" . \Plugin::getWebDir('sprint') . "/front/config.form.php'>";
            echo Html::hidden('sprint_config_section', ['value' => $section]);
        }
    }

    /** The group's own save button, named after the group. */
    private static function closeForm(bool $canedit, string $section, string $label = ''): void
    {
        if (!$canedit) {
            return;
        }
        $title = self::getSettingsSections()[$section][0];
        echo "<div class='d-flex justify-content-end'>";
        echo Html::submit($label !== '' ? $label : sprintf(__('Save %s settings', 'sprint'), $title), [
            'name'  => 'update_sprint_config',
            'class' => 'btn btn-primary',
        ]);
        echo "</div>";
        Html::closeForm();
    }

    /** One labelled setting: label + help on the left, the control on the right. */
    private static function row(string $id, string $label, string $help, string $control): void
    {
        echo "<div class='row g-2 mb-3 sprint-settings-row'>";
        echo "<div class='col-md-6'><label class='form-label mb-1 fw-bold' for='{$id}'>" . htmlescape($label) . "</label>"
            . "<div class='text-muted sprint-small' id='{$id}-help'>" . htmlescape($help) . "</div></div>";
        echo "<div class='col-md-6'>{$control}</div>";
        echo "</div>";
    }

    private static function switchRow(array $cfg, string $key, bool $canedit, string $label, string $help): void
    {
        $id = 'sprint-cfg-' . $key;
        self::row($id, $label, $help,
            "<input type='hidden' name='{$key}' value='0'>"
            . "<div class='form-check form-switch'>"
            . "<input class='form-check-input' type='checkbox' role='switch' id='{$id}' aria-describedby='{$id}-help' "
            . "name='{$key}' value='1'" . ((int)$cfg[$key] === 1 ? ' checked' : '') . ($canedit ? '' : ' disabled') . ">"
            . "<span class='form-check-label ms-2'>" . htmlescape(__('Enable')) . "</span></div>");
    }

    private static function textareaRow(string $key, string $value, bool $canedit, int $rows, string $label, string $help, string $placeholder = ''): void
    {
        $id = 'sprint-cfg-' . $key;
        self::row($id, $label, $help,
            "<textarea name='{$key}' id='{$id}' aria-describedby='{$id}-help' class='form-control' rows='{$rows}'"
            . ($placeholder !== '' ? " placeholder='{$placeholder}'" : '')
            . ($canedit ? '' : ' readonly') . ">" . htmlescape($value) . "</textarea>");
    }

    /** Named users who get credit access without a profile carrying the right. */
    private static function showCreditManagers(): void
    {
        $canedit  = Session::haveRight('config', UPDATE);
        $managers = self::getCreditManagers();
        $levels   = [
            'view'   => __('View credits', 'sprint'),
            'manage' => __('Manage credits', 'sprint'),
        ];

        echo "<div>";
        if ($canedit) {
            echo "<form method='post' action='" . \Plugin::getWebDir('sprint') . "/front/config.form.php'>";
        }
        echo "<table class='tab_cadre_fixe sprint-themed' style='width:100%;'>";
        echo "<tr class='tab_bg_2'><th colspan='3'><i class='fas fa-coins' style='margin-right:6px;'></i>"
            . __('Credit managers', 'sprint') . "</th></tr>";
        echo "<tr class='tab_bg_1'><td colspan='3' class='text-muted' style='font-size:0.85em;'>"
            . __('Grant credit access to individual users on top of the profile right. View opens the Credits page, the customers and their balances. Manage additionally allows creating customers, booking credits and setting the per-sprint agreements. Picking a customer and a credit amount on a sprint item needs neither — that follows the item rights.', 'sprint')
            . "</td></tr>";

        echo "<tr class='tab_bg_2'>"
            . "<th>" . \User::getTypeName(1) . "</th>"
            . "<th style='width:200px;'>" . __('Access', 'sprint') . "</th>"
            . "<th style='width:90px;'>" . __('Delete') . "</th></tr>";

        foreach ($managers as $uid => $level) {
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . htmlescape(\getUserName($uid)) . "</td>";
            echo "<td class='center'>";
            \Dropdown::showFromArray("credit_level[{$uid}]", $levels, [
                'value'   => $level,
                'display' => true,
            ]);
            echo "</td>";
            echo "<td class='center'><input type='checkbox' name='credit_delete[]' value='{$uid}' aria-label='"
                . htmlescape(sprintf(__('Remove %s from the credit managers', 'sprint'), \getUserName($uid))) . "'"
                . ($canedit ? '' : ' disabled') . "></td>";
            echo "</tr>";
        }

        if (count($managers) === 0) {
            echo "<tr class='tab_bg_1'><td colspan='3' class='center text-muted'>"
                . __('No named credit managers — access follows the profile right only.', 'sprint') . "</td></tr>";
        }

        if ($canedit) {
            echo "<tr class='tab_bg_1'>";
            echo "<td>";
            \User::dropdown([
                'name'   => 'credit_new_user',
                'value'  => 0,
                'right'  => 'all',
                'entity' => $_SESSION['glpiactiveentities'] ?? -1,
                'width'  => '100%',
            ]);
            echo "</td>";
            echo "<td class='center'>";
            \Dropdown::showFromArray('credit_new_level', $levels, ['value' => 'view']);
            echo "</td><td></td></tr>";
            echo "<tr class='tab_bg_1'><td colspan='3' class='center'>";
            echo Html::submit(__('Save credit managers', 'sprint'), [
                'name'  => 'save_sprint_credit_managers',
                'class' => 'btn btn-primary',
            ]);
            echo "</td></tr>";
        }

        echo "</table>";
        if ($canedit) {
            Html::closeForm();
        }
        echo "</div>";
    }
}
