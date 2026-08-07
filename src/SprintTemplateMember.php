<?php

namespace GlpiPlugin\Sprint;

use CommonDBRelation;
use CommonGLPI;
use Html;
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

    /** Capacity is stored as DECIMAL(5,1); snap to 0.5% steps like everywhere else. */
    public function prepareInputForAdd($input)
    {
        if (isset($input['capacity_percent'])) {
            $input['capacity_percent'] = SprintMember::normalizeCapacity($input['capacity_percent']);
        }
        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['capacity_percent'])) {
            $input['capacity_percent'] = SprintMember::normalizeCapacity($input['capacity_percent']);
        }
        return parent::prepareInputForUpdate($input);
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

            echo "<table class='tab_cadre_fixe sprint-themed'>";
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
            echo "<td>" . __('Maximum capacity (%)', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('capacity_percent', SprintMember::getCapacityChoices(), [
                'value' => 100,
            ]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'><td colspan='6' class='center'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        $member  = new self();
        $members = $member->find(['plugin_sprint_sprinttemplates_id' => $ID], ['role ASC']);

        echo "<div class='center'><table class='tab_cadre_fixe sprint-themed'>";
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
            echo "<td><i class='fas fa-user'></i> " . htmlescape(SprintCache::userName($row['users_id'])) . "</td>";
            echo "<td>" . $roleName . "</td>";
            echo "<td class='center'>" . SprintMember::formatCapacity($row['capacity_percent']) . "%</td>";
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

        self::showFixedLeave($template, $canedit);
    }

    /**
     * Fixed weekly leave (e.g. every Friday off). Applied as dated
     * availability exceptions when a sprint is created from this template.
     */
    private static function showFixedLeave(SprintTemplate $template, bool $canedit): void
    {
        $ID       = (int)$template->getID();
        $weekdays = SprintTemplateAvailability::getWeekdays();
        $formUrl  = SprintTemplateAvailability::getFormURL();

        if ($canedit) {
            echo "<div class='center'>";
            echo "<form method='post' action='" . $formUrl . "'>";
            echo Html::hidden('plugin_sprint_sprinttemplates_id', ['value' => $ID]);
            echo "<table class='tab_cadre_fixe sprint-themed'>";
            echo "<tr class='tab_bg_2'><th colspan='8'>" . __('Add fixed leave (weekly)', 'sprint') . "</th></tr>";
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('User') . "</td><td>";
            User::dropdown(['name' => 'users_id', 'right' => 'all']);
            echo "</td><td>" . __('Weekday', 'sprint') . "</td><td>";
            Dropdown::showFromArray('weekday', $weekdays, ['value' => 5]);
            echo "</td><td>" . __('Availability %', 'sprint') . "</td><td>";
            echo "<input type='number' min='0' max='100' step='.5' value='0' class='form-control' name='availability_percent'>";
            echo "</td><td>" . __('Reason', 'sprint') . "</td><td>";
            echo "<input class='form-control' name='comment' placeholder='" . htmlescape(__('Reason', 'sprint')) . "'>";
            echo "</td></tr>";
            echo "<tr class='tab_bg_1'><td colspan='8' class='center'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        $rows = (new SprintTemplateAvailability())->find(
            ['plugin_sprint_sprinttemplates_id' => $ID],
            ['users_id ASC', 'weekday ASC']
        );

        echo "<div class='center'><table class='tab_cadre_fixe sprint-themed'>";
        echo "<tr class='tab_bg_2'><th colspan='5'>" . __('Fixed leave', 'sprint') . "</th></tr>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('User') . "</th>";
        echo "<th>" . __('Weekday', 'sprint') . "</th>";
        echo "<th>" . __('Availability %', 'sprint') . "</th>";
        echo "<th>" . __('Reason', 'sprint') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($rows) === 0) {
            $cols = $canedit ? 5 : 4;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('No fixed leave', 'sprint') . "</td></tr>";
        }

        foreach ($rows as $row) {
            echo "<tr class='tab_bg_1'>";
            echo "<td><i class='fas fa-user-clock'></i> " . htmlescape(SprintCache::userName($row['users_id'])) . "</td>";
            echo "<td>" . htmlescape($weekdays[(int)$row['weekday']] ?? (string)$row['weekday']) . "</td>";
            echo "<td class='center'>" . SprintMember::formatCapacity($row['availability_percent']) . "%</td>";
            echo "<td>" . htmlescape((string)$row['comment']) . "</td>";
            if ($canedit) {
                echo "<td class='center'>";
                echo "<form method='post' action='" . $formUrl . "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::submit(__('Delete'), [
                    'name'  => 'purge',
                    'class' => 'btn btn-sm btn-outline-danger',
                ]);
                Html::closeForm();
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</table></div>";
    }
}
