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
            // A card with the labels above the fields. Field names, the hidden
            // template id and the submit name are what the form handler reads.
            $rand = mt_rand();
            echo "<div class='card mb-3 sprint-member-card'><div class='card-body'>";
            echo SprintUi::cardHeader('fas fa-user-plus', __('Add a default team member', 'sprint'));
            echo "<form method='post' action='" . static::getFormURL() . "' class='sprint-member-form'>";
            echo Html::hidden('plugin_sprint_sprinttemplates_id', ['value' => $ID]);
            echo "<div class='row g-2 align-items-end'>";

            echo "<div class='col-12 col-md-6 col-xl-4' role='group' aria-labelledby='sprint-tmpl-member-user-label-{$rand}'>";
            echo "<div class='form-label' id='sprint-tmpl-member-user-label-{$rand}'>" . __s('User') . "</div>";
            User::dropdown(['name' => 'users_id', 'right' => 'all', 'width' => '100%']);
            echo "</div>";

            echo "<div class='col-12 col-md-6 col-xl-3' role='group' aria-labelledby='sprint-tmpl-member-role-label-{$rand}'>";
            echo "<div class='form-label' id='sprint-tmpl-member-role-label-{$rand}'>" . __s('Role', 'sprint') . "</div>";
            Dropdown::showFromArray('role', $roles, [
                'value'      => SprintMember::ROLE_DEVELOPER,
                'aria_label' => __('Role', 'sprint'),
                'width'      => '100%',
            ]);
            echo "</div>";

            echo "<div class='col-6 col-md-3 col-xl-2'>";
            echo "<label class='form-label' for='sprint-tmpl-member-capacity'>" . __s('Set capacity (%)', 'sprint') . "</label>";
            echo SprintMember::capacityInput('capacity_percent', 100, [
                'id'       => 'sprint-tmpl-member-capacity',
                'required' => true,
            ]);
            echo "</div>";

            echo "<div class='col-12 col-md-auto'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</div>";

            echo "</div>";
            Html::closeForm();
            echo "</div></div>";
        }

        $member  = new self();
        $members = $member->find(['plugin_sprint_sprinttemplates_id' => $ID], ['role ASC']);

        echo "<div class='card mb-3 sprint-member-card'><div class='card-body'>";
        echo SprintUi::cardHeader('fas fa-users', __('Default team members', 'sprint'));
        echo "<div class='table-responsive'><table class='table table-vcenter mb-0 sprint-member-table'>";
        echo "<thead><tr>";
        echo "<th scope='col'>" . __s('User') . "</th>";
        echo "<th scope='col'>" . __s('Role', 'sprint') . "</th>";
        echo "<th scope='col' class='text-end'>" . __s('Capacity', 'sprint') . "</th>";
        if ($canedit) {
            echo "<th scope='col' class='text-end'>" . __s('Actions') . "</th>";
        }
        echo "</tr></thead><tbody>";

        if (count($members) === 0) {
            $cols = $canedit ? 4 : 3;
            echo "<tr><td colspan='{$cols}' class='text-center text-muted py-3'>" .
                __s('No default members', 'sprint') . "</td></tr>";
        }

        foreach ($members as $row) {
            $roleName   = $roles[$row['role']] ?? $row['role'];
            $memberName = SprintCache::userName($row['users_id']);
            echo "<tr>";
            echo "<td><i class='fas fa-user text-muted me-2' aria-hidden='true'></i>" . htmlescape($memberName) . "</td>";
            echo "<td>" . htmlescape((string)$roleName) . "</td>";
            echo "<td class='text-end'>" . SprintMember::formatCapacity($row['capacity_percent']) . "%</td>";
            if ($canedit) {
                echo "<td class='text-end sprint-row-actions'>";
                echo self::deleteButton(
                    static::getFormURL(),
                    (int)$row['id'],
                    $memberName,
                    sprintf(__('Remove %s from this template?', 'sprint'), $memberName)
                );
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</tbody></table></div></div></div>";

        self::showFixedLeave($template, $canedit);
    }

    /**
     * Delete as an icon button in its own purge form. js/sprint.js asks first
     * (data-sprint-confirm) and then submits this same form, so the server
     * path stays the plain purge POST.
     *
     * @param string $name     what is deleted, for the accessible label
     * @param string $question the confirmation question
     */
    private static function deleteButton(string $formUrl, int $id, string $name, string $question): string
    {
        return "<form method='post' action='" . htmlescape($formUrl) . "' class='d-inline-block'>"
            . Html::hidden('id', ['value' => $id])
            . "<button type='submit' name='purge' value='1' class='btn btn-sm btn-outline-danger' "
            . "title='" . __s('Delete') . "' "
            . "aria-label='" . __s('Delete') . ': ' . htmlescape($name) . "' "
            . "data-sprint-confirm='" . htmlescape($question) . "' data-sprint-danger='1' "
            . "data-sprint-confirm-ok='" . __s('Delete') . "'>"
            . SprintIcon::html(SprintIcon::DELETE) . "</button>"
            . Html::closeForm(false);
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
            $rand = mt_rand();
            echo "<div class='card mb-3 sprint-member-card'><div class='card-body'>";
            echo SprintUi::cardHeader('fas fa-calendar-plus', __('Add fixed leave (weekly)', 'sprint'));
            echo "<form method='post' action='" . $formUrl . "' class='sprint-member-form'>";
            echo Html::hidden('plugin_sprint_sprinttemplates_id', ['value' => $ID]);
            echo "<div class='row g-2 align-items-end'>";

            echo "<div class='col-12 col-md-6 col-xl-3' role='group' aria-labelledby='sprint-tmpl-leave-user-label-{$rand}'>";
            echo "<div class='form-label' id='sprint-tmpl-leave-user-label-{$rand}'>" . __s('User') . "</div>";
            User::dropdown(['name' => 'users_id', 'right' => 'all', 'width' => '100%']);
            echo "</div>";

            echo "<div class='col-6 col-md-3 col-xl-2' role='group' aria-labelledby='sprint-tmpl-leave-day-label-{$rand}'>";
            echo "<div class='form-label' id='sprint-tmpl-leave-day-label-{$rand}'>" . __s('Weekday', 'sprint') . "</div>";
            Dropdown::showFromArray('weekday', $weekdays, ['value' => 5, 'width' => '100%']);
            echo "</div>";

            echo "<div class='col-6 col-md-3 col-xl-2'>";
            echo "<label class='form-label' for='sprint-tmpl-leave-pct-{$rand}'>" . __s('Availability %', 'sprint') . "</label>";
            echo "<input type='number' min='0' max='100' step='.5' value='0' class='form-control' name='availability_percent' id='sprint-tmpl-leave-pct-{$rand}'>";
            echo "</div>";

            echo "<div class='col-12 col-md-9 col-xl'>";
            echo "<label class='form-label' for='sprint-tmpl-leave-reason-{$rand}'>" . __s('Reason', 'sprint') . "</label>";
            echo "<input class='form-control' name='comment' id='sprint-tmpl-leave-reason-{$rand}'>";
            echo "</div>";

            echo "<div class='col-12 col-xl-auto'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</div>";

            echo "</div>";
            Html::closeForm();
            echo "</div></div>";
        }

        $rows = (new SprintTemplateAvailability())->find(
            ['plugin_sprint_sprinttemplates_id' => $ID],
            ['users_id ASC', 'weekday ASC']
        );

        echo "<div class='card mb-3 sprint-member-card'><div class='card-body'>";
        echo SprintUi::cardHeader('fas fa-user-clock', __('Fixed leave', 'sprint'));
        echo "<div class='table-responsive'><table class='table table-vcenter mb-0 sprint-member-table'>";
        echo "<thead><tr>";
        echo "<th scope='col'>" . __s('User') . "</th>";
        echo "<th scope='col'>" . __s('Weekday', 'sprint') . "</th>";
        echo "<th scope='col' class='text-end'>" . __s('Availability %', 'sprint') . "</th>";
        echo "<th scope='col'>" . __s('Reason', 'sprint') . "</th>";
        if ($canedit) {
            echo "<th scope='col' class='text-end'>" . __s('Actions') . "</th>";
        }
        echo "</tr></thead><tbody>";

        if (count($rows) === 0) {
            $cols = $canedit ? 5 : 4;
            echo "<tr><td colspan='{$cols}' class='text-center text-muted py-3'>" .
                __s('No fixed leave', 'sprint') . "</td></tr>";
        }

        foreach ($rows as $row) {
            $memberName = SprintCache::userName($row['users_id']);
            $weekday    = (string)($weekdays[(int)$row['weekday']] ?? $row['weekday']);
            echo "<tr>";
            echo "<td><i class='fas fa-user-clock text-muted me-2' aria-hidden='true'></i>" . htmlescape($memberName) . "</td>";
            echo "<td>" . htmlescape($weekday) . "</td>";
            echo "<td class='text-end'>" . SprintMember::formatCapacity($row['availability_percent']) . "%</td>";
            echo "<td>" . htmlescape((string)$row['comment']) . "</td>";
            if ($canedit) {
                echo "<td class='text-end sprint-row-actions'>";
                echo self::deleteButton(
                    $formUrl,
                    (int)$row['id'],
                    $memberName . ' · ' . $weekday,
                    // 1: member name, 2: weekday
                    sprintf(__('Remove the fixed leave of %1$s on %2$s?', 'sprint'), $memberName, $weekday)
                );
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</tbody></table></div></div></div>";
    }
}
