<?php

namespace GlpiPlugin\Sprint;

use CommonDBRelation;
use CommonGLPI;
use Html;
use Session;
use User;
use Dropdown;

/**
 * SprintTemplateMember - Default team members for a sprint template
 */
class SprintTemplateMember extends CommonDBRelation
{
    public static $itemtype_1 = 'GlpiPlugin\Sprint\SprintTemplate';
    public static $items_id_1 = 'plugin_sprint_sprinttemplates_id';
    public static $itemtype_2 = 'User';
    public static $items_id_2 = 'users_id';

    public static $rightname  = 'plugin_sprint_sprint';

    public static function getTypeName($nb = 0): string
    {
        return _n('Template Member', 'Template Members', $nb, 'sprint');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof SprintTemplate) {
            $count = countElementsInTable(
                self::getTable(),
                ['plugin_sprint_sprinttemplates_id' => $item->getID()]
            );
            return self::createTabEntry(__('Members', 'sprint'), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof SprintTemplate) {
            self::showForTemplate($item);
            return true;
        }
        return false;
    }

    public static function showForTemplate(SprintTemplate $template): void
    {
        $ID      = $template->getID();
        $canedit = SprintTemplate::canUpdate();
        $roles   = SprintMember::getAllRoles();

        if ($canedit) {
            echo "<div class='center'>";
            echo "<form method='post' action='" . static::getFormURL() . "'>";
            echo Html::hidden('plugin_sprint_sprinttemplates_id', ['value' => $ID]);

            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_2'><th colspan='6'>" .
                __('Add a default team member', 'sprint') . "</th></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('User') . "</td>";
            echo "<td>";
            User::dropdown(['name' => 'users_id', 'right' => 'all']);
            echo "</td>";
            echo "<td>" . __('Role', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('role', $roles, ['value' => SprintMember::ROLE_DEVELOPER]);
            echo "</td>";
            echo "<td>" . __('Capacity (%)', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showNumber('capacity_percent', [
                'value' => 100, 'min' => 0, 'max' => 100, 'step' => 10,
            ]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'><td colspan='6' class='center'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        // List members
        $member  = new self();
        $members = $member->find(['plugin_sprint_sprinttemplates_id' => $ID], ['role ASC']);

        echo "<div class='center'><table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('User') . "</th>";
        echo "<th>" . __('Role', 'sprint') . "</th>";
        echo "<th>" . __('Capacity', 'sprint') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($members) === 0) {
            $cols = $canedit ? 4 : 3;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('No default members', 'sprint') . "</td></tr>";
        }

        foreach ($members as $row) {
            $roleName = $roles[$row['role']] ?? $row['role'];
            echo "<tr class='tab_bg_1'>";
            echo "<td><i class='fas fa-user'></i> " . getUserName($row['users_id']) . "</td>";
            echo "<td>" . $roleName . "</td>";
            echo "<td class='center'>" . (int)$row['capacity_percent'] . "%</td>";
            if ($canedit) {
                echo "<td class='center'>";
                echo "<form method='post' action='" . static::getFormURL() . "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::submit(__('Delete'), [
                    'name'    => 'purge',
                    'class'   => 'btn btn-sm btn-outline-danger',
                    'confirm' => __('Remove this member?', 'sprint'),
                ]);
                Html::closeForm();
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</table></div>";
    }
}
