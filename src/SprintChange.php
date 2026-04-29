<?php

namespace GlpiPlugin\Sprint;

use CommonDBRelation;
use CommonGLPI;
use Html;
use Session;
use Change;
use Dropdown;

/**
 * SprintChange - Link table between Sprints and Changes
 */
class SprintChange extends CommonDBRelation
{
    public static $itemtype_1 = 'GlpiPlugin\Sprint\Sprint';
    public static $items_id_1 = 'plugin_sprint_sprints_id';
    public static $itemtype_2 = 'Change';
    public static $items_id_2 = 'changes_id';

    public static $rightname  = 'plugin_sprint_sprint';

    public static function getTypeName($nb = 0): string
    {
        return _n('Linked Change', 'Linked Changes', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-exchange-alt';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Change) {
            $count = countElementsInTable(
                SprintItem::getTable(),
                [
                    'itemtype'                 => 'Change',
                    'items_id'                 => $item->getID(),
                    ['NOT' => ['plugin_sprint_sprints_id' => 0]],
                ]
            );
            return self::createTabEntry(__('Sprints', 'sprint'), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof Change) {
            self::showForChange($item);
            return true;
        }
        return false;
    }

    /**
     * Show linked changes for a sprint
     */
    public static function showForSprint(Sprint $sprint): void
    {
        $ID      = $sprint->getID();
        $canedit = Sprint::canUpdate();

        if ($canedit) {
            $memberOptions = SprintMember::getSprintMemberOptions($ID);

            echo "<div class='center'>";
            echo "<form method='post' action='" . static::getFormURL() . "'>";
            echo Html::hidden('plugin_sprint_sprints_id', ['value' => $ID]);

            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_2'><th colspan='4'>" .
                __('Link a change', 'sprint') . "</th></tr>";
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Change') . "</td>";
            echo "<td>";
            Change::dropdown([
                'name'        => 'changes_id',
                'displaywith' => ['id'],
            ]);
            echo "</td>";
            echo "<td>" . __('Assign to member', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('users_id', $memberOptions);
            echo "</td></tr>";
            echo "<tr class='tab_bg_1'><td colspan='4' class='center'>";
            echo Html::submit(__('Link'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        $link  = new self();
        $links = $link->find(['plugin_sprint_sprints_id' => $ID]);

        echo "<div class='center'><table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('ID') . "</th>";
        echo "<th>" . __('Title') . "</th>";
        echo "<th>" . __('Status') . "</th>";
        echo "<th>" . __('Sprint Member', 'sprint') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($links) === 0) {
            $cols = $canedit ? 5 : 4;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('No changes linked', 'sprint') . "</td></tr>";
        }

        foreach ($links as $row) {
            $change = new Change();
            if (!$change->getFromDB($row['changes_id'])) {
                continue;
            }

            $assignedMember = ((int)$row['users_id'] > 0)
                ? getUserName($row['users_id'])
                : '<span style="color:#999;">' . __('Unassigned', 'sprint') . '</span>';

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . $change->getID() . "</td>";
            echo "<td><a href='" . Change::getFormURLWithID($change->getID()) . "'>" .
                htmlescape($change->fields['name']) . "</a></td>";
            echo "<td>" . Change::getStatus($change->fields['status']) . "</td>";
            echo "<td>" . $assignedMember . "</td>";
            if ($canedit) {
                echo "<td class='center'>";
                echo "<form method='post' action='" . static::getFormURL() .
                    "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::submit(__('Unlink', 'sprint'), [
                    'name'    => 'purge',
                    'class'   => 'btn btn-sm btn-outline-danger',
                    'confirm' => __('Remove this link?', 'sprint'),
                ]);
                Html::closeForm();
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</table></div>";
    }

    /**
     * Show linked sprints on a change's tab
     */
    public static function showForChange(Change $change): void
    {
        $changeID = $change->getID();
        $canedit  = Sprint::canUpdate();

        if ($canedit) {
            echo "<div class='center'>";
            echo "<form method='post' action='" . static::getFormURL() . "'>";
            echo Html::hidden('changes_id', ['value' => $changeID]);

            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_2'><th colspan='3'>" .
                __('Link to a sprint', 'sprint') . "</th></tr>";
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Sprint', 'sprint') . "</td>";
            echo "<td>";
            Sprint::dropdown(['name' => 'plugin_sprint_sprints_id']);
            echo "</td>";
            echo "<td>";
            echo Html::submit(__('Link'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
            echo "</div>";

            Backlog::showAddToBacklogButton('Change', $changeID);
        }

        // Source of truth is SprintItem — see SprintTicket::showForTicket.
        $si    = new SprintItem();
        $links = $si->find([
            'itemtype' => 'Change',
            'items_id' => $changeID,
            ['NOT' => ['plugin_sprint_sprints_id' => 0]],
        ]);

        $backlogRows = $si->find([
            'itemtype'                 => 'Change',
            'items_id'                 => $changeID,
            'plugin_sprint_sprints_id' => 0,
        ]);
        $statuses = Sprint::getAllStatuses();

        echo "<div class='center'><table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Sprint', 'sprint') . "</th>";
        echo "<th>" . __('Status') . "</th>";
        echo "<th>" . __('Period', 'sprint') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($links) === 0) {
            $cols = $canedit ? 4 : 3;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('Not linked to any sprint', 'sprint') . "</td></tr>";
        }

        foreach ($links as $row) {
            $sprint = new Sprint();
            if (!$sprint->getFromDB($row['plugin_sprint_sprints_id'])) {
                continue;
            }

            echo "<tr class='tab_bg_1'>";
            echo "<td><a href='" . Sprint::getFormURLWithID($sprint->getID()) . "'>" .
                htmlescape($sprint->fields['name']) . "</a></td>";
            echo "<td>" . ($statuses[$sprint->fields['status']] ?? '') . "</td>";
            echo "<td>" . Html::convDateTime($sprint->fields['date_start']) .
                " - " . Html::convDateTime($sprint->fields['date_end']) . "</td>";
            if ($canedit) {
                echo "<td class='center'>";
                echo "<form method='post' action='" . SprintItem::getFormURL() .
                    "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::submit(__('Unlink', 'sprint'), [
                    'name'    => 'purge',
                    'class'   => 'btn btn-sm btn-outline-danger',
                    'confirm' => __('Remove this link?', 'sprint'),
                ]);
                Html::closeForm();
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</table></div>";

        if (count($backlogRows) > 0) {
            $backlogUrl = Backlog::getSearchURL();
            echo "<div class='center' style='margin-top:18px;'><table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_2'>";
            echo "<th colspan='" . ($canedit ? 4 : 3) . "' style='background:#e7f1ff;color:#084298;'>"
                . "<i class='fas fa-layer-group'></i> "
                . __('On backlog', 'sprint')
                . "</th>";
            echo "</tr>";
            echo "<tr class='tab_bg_2'>";
            echo "<th>" . __('Backlog item', 'sprint') . "</th>";
            echo "<th><i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;'></i>" . __('Is Fastlane', 'sprint') . "</th>";
            echo "<th><i class='fas fa-ban' style='color:#dc3545;margin-right:4px;'></i>" . __('Is Blocked', 'sprint') . "</th>";
            if ($canedit) {
                echo "<th>" . __('Actions') . "</th>";
            }
            echo "</tr>";

            foreach ($backlogRows as $row) {
                $isFastlane = (int)($row['is_fastlane'] ?? 0) === 1;
                $isBlocked  = (int)($row['is_blocked'] ?? 0) === 1;

                echo "<tr class='tab_bg_1'>";
                echo "<td><a href='" . SprintItem::getFormURLWithID($row['id']) . "'>"
                    . htmlescape($row['name']) . "</a></td>";
                echo "<td class='center'>"
                    . ($isFastlane
                        ? "<i class='fas fa-bolt' style='color:#fd7e14;'></i> " . __('Yes')
                        : "<span class='text-muted'>" . __('No') . "</span>")
                    . "</td>";
                echo "<td class='center'>"
                    . ($isBlocked
                        ? "<i class='fas fa-ban' style='color:#dc3545;'></i> " . __('Yes')
                        : "<span class='text-muted'>" . __('No') . "</span>")
                    . "</td>";
                if ($canedit) {
                    echo "<td class='center'><a href='" . $backlogUrl . "' class='btn btn-sm btn-outline-primary'>"
                        . "<i class='fas fa-layer-group'></i> " . __('Open backlog', 'sprint')
                        . "</a></td>";
                }
                echo "</tr>";
            }

            echo "</table></div>";
        }
    }

    public function prepareInputForAdd($input)
    {
        $sprintId = (int)($input['plugin_sprint_sprints_id'] ?? 0);
        $changeId = (int)($input['changes_id'] ?? 0);

        if ($sprintId <= 0 || $changeId <= 0) {
            Session::addMessageAfterRedirect(
                __('Please select a sprint and a change.', 'sprint'),
                false,
                ERROR
            );
            return false;
        }

        if (SprintItem::isLinkedItemInSprint($sprintId, 'Change', $changeId)) {
            Session::addMessageAfterRedirect(
                sprintf(__('This change (#%d) is already linked to this sprint.', 'sprint'), $changeId),
                false,
                ERROR
            );
            return false;
        }

        return parent::prepareInputForAdd($input);
    }

    public function post_addItem()
    {
        $change = new Change();
        if ($change->getFromDB($this->fields['changes_id'])) {
            $item = new SprintItem();
            $item->add([
                'plugin_sprint_sprints_id' => $this->fields['plugin_sprint_sprints_id'],
                'name'                     => $change->fields['name'],
                'itemtype'                 => 'Change',
                'items_id'                 => $this->fields['changes_id'],
                'status'                   => SprintItem::STATUS_TODO,
                'priority'                 => (int)($change->fields['priority'] ?? 3),
                'users_id'                 => (int)($this->fields['users_id'] ?? 0),
            ]);
        }
    }

    public function post_purgeItem()
    {
        $item = new SprintItem();
        $items = $item->find([
            'plugin_sprint_sprints_id' => $this->fields['plugin_sprint_sprints_id'],
            'itemtype'                 => 'Change',
            'items_id'                 => $this->fields['changes_id'],
        ]);
        foreach ($items as $row) {
            $item->delete(['id' => $row['id']], 1);
        }
    }

    public static function cleanForItem(\CommonDBTM $item): void
    {
        $temp = new self();
        $temp->deleteByCriteria(['changes_id' => $item->getID()]);
    }
}
