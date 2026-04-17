<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use CommonGLPI;
use Config as GlpiConfig;
use Html;
use Session;

/**
 * Config - Plugin-wide settings stored in GLPI's native glpi_configs table
 * under context='plugin:sprint'.
 *
 * Exposed as a tab on GLPI's Setup > General configuration page.
 */
class Config extends CommonDBTM
{
    public static $rightname = 'config';

    const CONTEXT = 'plugin:sprint';

    /** Only the sprint's Scrum Master may edit capacity on regular sprint items. */
    const CFG_SCRUM_MASTER_CAPACITY = 'scrum_master_only_capacity';

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
        ];
        $stored = GlpiConfig::getConfigurationValues(self::CONTEXT);
        return array_merge($defaults, $stored);
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
     * Persist a full set of plugin settings (called from front/config.form.php).
     */
    public static function saveConfig(array $input): void
    {
        $values = [
            self::CFG_SCRUM_MASTER_CAPACITY => (int)(bool)($input[self::CFG_SCRUM_MASTER_CAPACITY] ?? 0),
        ];
        GlpiConfig::setConfigurationValues(self::CONTEXT, $values);
    }

    /**
     * Check whether the current user is the Scrum Master of the given sprint.
     * Returns true for brand-new sprints (no id yet) so the creation flow
     * isn't gated — we only enforce restrictions on existing sprints.
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

        echo "<table class='tab_cadre_fixe'>";
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
    }
}
