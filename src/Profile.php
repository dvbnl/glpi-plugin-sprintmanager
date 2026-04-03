<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use CommonGLPI;
use Plugin;
use Profile as GlpiProfile;
use ProfileRight;
use Html;
use Session;

/**
 * Profile - Rights management for the Sprint plugin
 */
class Profile extends CommonDBTM
{
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0): string
    {
        return __('Sprint plugin rights', 'sprint');
    }

    /**
     * Custom right: manage own sprint items only
     */
    const RIGHT_OWN_ITEMS = 256;

    /**
     * Get rights definition for the plugin
     *
     * @return array
     */
    public static function getAllRights(): array
    {
        return [
            [
                'itemtype'  => 'GlpiPlugin\Sprint\Sprint',
                'label'     => __('Sprint management', 'sprint'),
                'field'     => 'plugin_sprint_sprint',
                'rights'    => [
                    READ    => __('Read'),
                    CREATE  => __('Create'),
                    UPDATE  => __('Update'),
                    DELETE  => __('Delete'),
                    PURGE   => __('Delete permanently'),
                ],
            ],
            [
                'itemtype'  => 'GlpiPlugin\Sprint\SprintItem',
                'label'     => __('Sprint items', 'sprint'),
                'field'     => 'plugin_sprint_item',
                'rights'    => [
                    READ              => __('Read'),
                    CREATE            => __('Create'),
                    UPDATE            => __('Update'),
                    DELETE            => __('Delete'),
                    PURGE             => __('Delete permanently'),
                    self::RIGHT_OWN_ITEMS => __('Manage own items only', 'sprint'),
                ],
            ],
        ];
    }

    /**
     * Install profile rights at plugin install
     */
    public static function installRights(): void
    {
        global $DB;

        $rights = self::getAllRights();
        foreach ($rights as $right) {
            $field = $right['field'];

            $profiles = $DB->request(['FROM' => 'glpi_profiles']);
            foreach ($profiles as $profile) {
                $existing = $DB->request([
                    'FROM'  => 'glpi_profilerights',
                    'WHERE' => [
                        'profiles_id' => $profile['id'],
                        'name'        => $field,
                    ],
                ]);
                if (count($existing) === 0) {
                    $value = ($profile['id'] == 4) ? ALLSTANDARDRIGHT : READ;
                    $DB->insert('glpi_profilerights', [
                        'profiles_id' => $profile['id'],
                        'name'        => $field,
                        'rights'      => $value,
                    ]);
                }
            }
        }
    }

    /**
     * Uninstall profile rights
     */
    public static function uninstallRights(): void
    {
        global $DB;

        $rights = self::getAllRights();
        foreach ($rights as $right) {
            $DB->delete('glpi_profilerights', [
                'name' => $right['field'],
            ]);
        }
    }

    /**
     * Show the profile form tab with sprint icon
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof GlpiProfile) {
            return self::createTabEntry(
                __('SprintManager', 'sprint'),
                0,
                $item::getType(),
                'fas fa-running'
            );
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof GlpiProfile) {
            self::showForProfile($item);
            return true;
        }
        return false;
    }

    /**
     * Show rights configuration form for a profile
     */
    public static function showForProfile(GlpiProfile $profile): void
    {
        global $DB;

        $canedit   = Session::haveRight('profile', UPDATE);
        $rights    = self::getAllRights();
        $profileId = $profile->getID();

        // Get current rights for this profile
        $currentRights = [];
        foreach ($rights as $right) {
            $result = $DB->request([
                'FROM'  => 'glpi_profilerights',
                'WHERE' => [
                    'profiles_id' => $profileId,
                    'name'        => $right['field'],
                ],
            ]);
            $row = $result->current();
            $currentRights[$right['field']] = (int)($row['rights'] ?? 0);
        }

        echo "<div class='center'>";
        if ($canedit) {
            $formUrl = Plugin::getWebDir('sprint') . '/front/profile.form.php';
            echo "<form method='post' action='" . $formUrl . "'>";
            echo Html::hidden('profiles_id', ['value' => $profileId]);
        }

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'><th colspan='2'>" .
            '<i class="fas fa-running" style="margin-right:8px;"></i>' .
            __('Sprint plugin rights', 'sprint') . "</th></tr>";

        $isFirst = true;
        foreach ($rights as $right) {
            $current = $currentRights[$right['field']] ?? 0;

            // Separator between right groups
            if (!$isFirst) {
                echo "<tr><td colspan='2' style='padding:0;'><hr style='margin:0;border:0;border-top:2px solid #dee2e6;'></td></tr>";
            }
            $isFirst = false;

            // Group header
            echo "<tr class='tab_bg_2'>";
            echo "<td colspan='2' style='font-weight:700; font-size:0.95em; padding:10px 12px;'>" .
                htmlspecialchars($right['label']) . "</td>";
            echo "</tr>";

            // Standard CRUD rights
            echo "<tr class='tab_bg_1'>";
            echo "<td style='width:20%; padding-left:24px; color:#6c757d;'>" . __('Rights') . "</td>";
            echo "<td>";
            echo "<div style='display:flex; gap:18px; flex-wrap:wrap; align-items:center;'>";
            foreach ($right['rights'] as $bit => $label) {
                if ($bit > PURGE) continue;
                $checked = ($current & $bit) ? 'checked' : '';
                $inputId = $right['field'] . '_' . $bit;
                echo "<label style='display:inline-flex; align-items:center; gap:5px; white-space:nowrap; cursor:pointer;'>";
                echo "<input type='checkbox' name='" . $right['field'] . "[]' " .
                    "value='{$bit}' id='{$inputId}' {$checked}";
                if (!$canedit) echo " disabled";
                echo "> " . htmlspecialchars($label);
                echo "</label>";
            }
            echo "</div>";
            echo "</td></tr>";

            // Special rights (e.g., "Manage own items only")
            $specialRights = [];
            foreach ($right['rights'] as $bit => $label) {
                if ($bit > PURGE) $specialRights[$bit] = $label;
            }

            if (!empty($specialRights)) {
                echo "<tr class='tab_bg_1'>";
                echo "<td style='padding-left:24px; color:#6c757d;'>" . __('Special', 'sprint') . "</td>";
                echo "<td>";
                echo "<div style='display:flex; gap:18px; flex-wrap:wrap; align-items:center;'>";
                foreach ($specialRights as $bit => $label) {
                    $checked = ($current & $bit) ? 'checked' : '';
                    $inputId = $right['field'] . '_' . $bit;
                    echo "<label style='display:inline-flex; align-items:center; gap:5px; white-space:nowrap; cursor:pointer;'>";
                    echo "<input type='checkbox' name='" . $right['field'] . "[]' " .
                        "value='{$bit}' id='{$inputId}' {$checked}";
                    if (!$canedit) echo " disabled";
                    echo "> " . htmlspecialchars($label);
                    echo "</label>";
                }
                echo "</div>";
                echo "</td></tr>";
            }
        }

        if ($canedit) {
            echo "<tr class='tab_bg_1'>";
            echo "<td colspan='2' class='center'>";
            echo Html::submit(__('Save'), [
                'name'  => 'update_sprint_rights',
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
