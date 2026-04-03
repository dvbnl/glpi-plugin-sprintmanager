<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use CommonGLPI;
use Html;
use Session;
use User;
use Dropdown;

/**
 * SprintStandup - Standup log entries linked to meetings and sprint items
 *
 * Each entry records: what was done, what's planned, blockers, and item status.
 */
class SprintStandup extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_item';
    public $dohistory        = true;

    const STATUS_ON_TRACK = 'on_track';
    const STATUS_AT_RISK  = 'at_risk';
    const STATUS_BLOCKED  = 'blocked';
    const STATUS_DONE     = 'done';

    public static function getTypeName($nb = 0): string
    {
        return _n('Standup Entry', 'Standup Entries', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-comments';
    }

    public static function getAllStatuses(): array
    {
        return [
            self::STATUS_ON_TRACK => __('On Track', 'sprint'),
            self::STATUS_AT_RISK  => __('At Risk', 'sprint'),
            self::STATUS_BLOCKED  => __('Blocked', 'sprint'),
            self::STATUS_DONE     => __('Done', 'sprint'),
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof SprintMeeting) {
            $count = countElementsInTable(
                self::getTable(),
                ['plugin_sprint_sprintmeetings_id' => $item->getID()]
            );
            return self::createTabEntry(self::getTypeName(2), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof SprintMeeting) {
            self::showForMeeting($item);
            return true;
        }
        return false;
    }

    /**
     * Show standup entries for a specific meeting + add form
     */
    public static function showForMeeting(SprintMeeting $meeting): void
    {
        $meetingID = $meeting->getID();
        $sprintID  = $meeting->fields['plugin_sprint_sprints_id'] ?? 0;
        $canedit   = self::canUpdate()
            || Session::haveRight(self::$rightname, Profile::RIGHT_OWN_ITEMS);

        // Get sprint items for dropdown
        $sprintItem  = new SprintItem();
        $sprintItems = $sprintItem->find(['plugin_sprint_sprints_id' => $sprintID]);
        $itemOptions = [0 => Dropdown::EMPTY_VALUE];
        foreach ($sprintItems as $si) {
            $itemOptions[$si['id']] = $si['name'];
        }

        // === Add form ===
        if ($canedit) {
            echo "<div class='center'>";
            echo "<form method='post' action='" . static::getFormURL() . "'>";
            echo Html::hidden('plugin_sprint_sprintmeetings_id', ['value' => $meetingID]);

            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_2'><th colspan='4'>" .
                __('Add standup entry', 'sprint') . "</th></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Sprint Item', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('plugin_sprint_sprintitems_id', $itemOptions);
            echo "</td>";
            echo "<td>" . __('Reporter', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('users_id', SprintMember::getSprintMemberOptions($sprintID), [
                'value' => Session::getLoginUserID(),
            ]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Status', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('status_update', self::getAllStatuses(), [
                'value' => self::STATUS_ON_TRACK,
            ]);
            echo "</td>";
            echo "<td colspan='2'></td>";
            echo "</tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Done yesterday', 'sprint') . "</td>";
            echo "<td colspan='3'>";
            echo "<textarea name='done_yesterday' rows='2' cols='80'></textarea>";
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Plan today', 'sprint') . "</td>";
            echo "<td colspan='3'>";
            echo "<textarea name='plan_today' rows='2' cols='80'></textarea>";
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Blockers', 'sprint') . "</td>";
            echo "<td colspan='3'>";
            echo "<textarea name='blockers' rows='2' cols='80'></textarea>";
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td colspan='4' class='center'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";

            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        // === List entries ===
        self::renderStandupTable(
            ['plugin_sprint_sprintmeetings_id' => $meetingID],
            $canedit
        );
    }

    /**
     * Show full standup log across all meetings for a sprint
     */
    public static function showLogForSprint(Sprint $sprint): void
    {
        $sprintID = $sprint->getID();

        // Get all meeting IDs for this sprint
        $meetingObj = new SprintMeeting();
        $meetings   = $meetingObj->find(['plugin_sprint_sprints_id' => $sprintID]);
        $meetingIDs = array_column($meetings, 'id');

        if (empty($meetingIDs)) {
            echo "<div class='center'><table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_1'><td class='center'>" .
                __('No standup entries yet', 'sprint') . "</td></tr>";
            echo "</table></div>";
            return;
        }

        self::renderStandupTable(
            ['plugin_sprint_sprintmeetings_id' => $meetingIDs],
            false
        );
    }

    /**
     * Render a table of standup entries matching given criteria
     */
    private static function renderStandupTable(array $criteria, bool $showActions): void
    {
        $standup = new self();
        $entries = $standup->find($criteria, ['date_creation DESC']);

        $statuses = self::getAllStatuses();
        $statusIcons = [
            self::STATUS_ON_TRACK => 'fas fa-check-circle text-success',
            self::STATUS_AT_RISK  => 'fas fa-exclamation-triangle text-warning',
            self::STATUS_BLOCKED  => 'fas fa-ban text-danger',
            self::STATUS_DONE     => 'fas fa-flag-checkered text-info',
        ];

        echo "<div class='center'><table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Sprint Item', 'sprint') . "</th>";
        echo "<th>" . __('Reporter', 'sprint') . "</th>";
        echo "<th>" . __('Status', 'sprint') . "</th>";
        echo "<th>" . __('Done yesterday', 'sprint') . "</th>";
        echo "<th>" . __('Plan today', 'sprint') . "</th>";
        echo "<th>" . __('Blockers', 'sprint') . "</th>";
        echo "<th>" . __('Date') . "</th>";
        if ($showActions) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($entries) === 0) {
            $cols = $showActions ? 8 : 7;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('No standup entries yet', 'sprint') . "</td></tr>";
        }

        foreach ($entries as $row) {
            $icon = $statusIcons[$row['status_update']] ?? 'fas fa-question';

            // Get sprint item name
            $itemName = '-';
            if ($row['plugin_sprint_sprintitems_id'] > 0) {
                $si = new SprintItem();
                if ($si->getFromDB($row['plugin_sprint_sprintitems_id'])) {
                    $itemName = $si->fields['name'];
                }
            }

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . htmlescape($itemName) . "</td>";
            echo "<td>" . getUserName($row['users_id']) . "</td>";
            echo "<td><i class='{$icon}'></i> " .
                ($statuses[$row['status_update']] ?? $row['status_update']) . "</td>";
            echo "<td>" . nl2br(htmlescape($row['done_yesterday'] ?? '')) . "</td>";
            echo "<td>" . nl2br(htmlescape($row['plan_today'] ?? '')) . "</td>";
            echo "<td>" . nl2br(htmlescape($row['blockers'] ?? '')) . "</td>";
            echo "<td>" . Html::convDateTime($row['date_creation']) . "</td>";
            if ($showActions) {
                echo "<td class='center'>";
                echo "<form method='post' action='" . static::getFormURL() .
                    "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::submit(__('Delete'), [
                    'name'    => 'purge',
                    'class'   => 'btn btn-sm btn-outline-danger',
                    'confirm' => __('Confirm deletion?'),
                ]);
                Html::closeForm();
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</table></div>";
    }

    /**
     * Show the standup entry edit form
     */
    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        // Build sprint items dropdown data
        $meetingID = $this->fields['plugin_sprint_sprintmeetings_id'] ?? 0;
        $sprintID  = 0;
        if ($meetingID > 0) {
            $m = new SprintMeeting();
            if ($m->getFromDB($meetingID)) {
                $sprintID = $m->fields['plugin_sprint_sprints_id'];
            }
        }
        $sprintItem  = new SprintItem();
        $sprintItems = $sprintItem->find(['plugin_sprint_sprints_id' => $sprintID]);
        $itemOptions = [0 => Dropdown::EMPTY_VALUE];
        foreach ($sprintItems as $si) {
            $itemOptions[$si['id']] = $si['name'];
        }

        if (class_exists('Glpi\Application\View\TemplateRenderer')) {
            \Glpi\Application\View\TemplateRenderer::getInstance()->display(
                '@sprint/sprintstandup.form.html.twig',
                [
                    'item'             => $this,
                    'params'           => $options,
                    'sprint_items'     => $itemOptions,
                    'standup_statuses' => self::getAllStatuses(),
                ]
            );
        } else {
            $this->showFormHeader($options);

            echo "<tr class='tab_bg_1'><td>" . __('Sprint Item', 'sprint') . "</td><td>";
            Dropdown::showFromArray('plugin_sprint_sprintitems_id', $itemOptions, [
                'value' => $this->fields['plugin_sprint_sprintitems_id'] ?? 0,
            ]);
            echo "</td><td>" . __('Status', 'sprint') . "</td><td>";
            Dropdown::showFromArray('status_update', self::getAllStatuses(), [
                'value' => $this->fields['status_update'] ?? self::STATUS_ON_TRACK,
            ]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'><td>" . __('Reporter', 'sprint') . "</td><td>";
            // Get sprint ID via meeting
            $meetingId = (int)($this->fields['plugin_sprint_sprintmeetings_id'] ?? 0);
            $sprintIdForDropdown = 0;
            if ($meetingId > 0) {
                $mtg = new SprintMeeting();
                if ($mtg->getFromDB($meetingId)) {
                    $sprintIdForDropdown = (int)($mtg->fields['plugin_sprint_sprints_id'] ?? 0);
                }
            }
            Dropdown::showFromArray('users_id', SprintMember::getSprintMemberOptions($sprintIdForDropdown), [
                'value' => $this->fields['users_id'] ?? Session::getLoginUserID(),
            ]);
            echo "</td><td colspan='2'></td></tr>";

            echo "<tr class='tab_bg_1'><td>" . __('Done yesterday', 'sprint') . "</td>";
            echo "<td colspan='3'><textarea name='done_yesterday' rows='3' cols='80'>" .
                htmlescape($this->fields['done_yesterday'] ?? '') . "</textarea></td></tr>";

            echo "<tr class='tab_bg_1'><td>" . __('Plan today', 'sprint') . "</td>";
            echo "<td colspan='3'><textarea name='plan_today' rows='3' cols='80'>" .
                htmlescape($this->fields['plan_today'] ?? '') . "</textarea></td></tr>";

            echo "<tr class='tab_bg_1'><td>" . __('Blockers', 'sprint') . "</td>";
            echo "<td colspan='3'><textarea name='blockers' rows='3' cols='80'>" .
                htmlescape($this->fields['blockers'] ?? '') . "</textarea></td></tr>";

            $this->showFormButtons($options);
        }
        return true;
    }
}
