<?php

namespace GlpiPlugin\Sprint;

use CommonDBRelation;
use CommonGLPI;
use Html;
use Session;
use Problem;

class SprintProblem extends CommonDBRelation
{
    public static $itemtype_1 = 'GlpiPlugin\Sprint\Sprint';
    public static $items_id_1 = 'plugin_sprint_sprints_id';
    public static $itemtype_2 = 'Problem';
    public static $items_id_2 = 'problems_id';

    public static $rightname  = 'plugin_sprint_sprint';

    public static function getTypeName($nb = 0): string
    {
        return _n('Linked Problem', 'Linked Problems', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-exclamation-circle';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Problem) {
            $count = countElementsInTable(
                SprintItem::getTable(),
                [
                    'itemtype'                 => 'Problem',
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
        if ($item instanceof Problem) {
            self::showForProblem($item);
            return true;
        }
        return false;
    }

    public static function showForProblem(Problem $problem): void
    {
        $problemID = $problem->getID();
        $canedit   = Sprint::canUpdate();

        // Add-to-backlog only: items reach a sprint via the backlog (and the
        // Scrum Master), not by direct linking. Linked sprints shown read-only below.
        Backlog::showAddToBacklogButton('Problem', $problemID);

        $si    = new SprintItem();
        $links = $si->find([
            'itemtype' => 'Problem',
            'items_id' => $problemID,
            ['NOT' => ['plugin_sprint_sprints_id' => 0]],
        ]);

        $backlogRows = $si->find([
            'itemtype'                 => 'Problem',
            'items_id'                 => $problemID,
            'plugin_sprint_sprints_id' => 0,
        ]);
        $statuses = Sprint::getAllStatuses();

        echo "<div class='center'><table class='tab_cadre_fixe sprint-themed'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Sprint', 'sprint') . "</th>";
        echo "<th>" . __('Status') . "</th>";
        echo "<th>" . __('Period', 'sprint') . "</th>";
        echo "</tr>";

        if (count($links) === 0) {
            echo "<tr class='tab_bg_1'><td colspan='3' class='center'>" .
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
            echo "</tr>";
        }

        echo "</table></div>";

        if (count($backlogRows) > 0) {
            $backlogUrl = Backlog::getSearchURL();
            echo "<div class='center' style='margin-top:18px;'><table class='tab_cadre_fixe sprint-themed'>";
            echo "<tr class='tab_bg_2'>";
            echo "<th colspan='" . ($canedit ? 4 : 3) . "' style='background:color-mix(in srgb,#0d6efd 12%,var(--tblr-bg-surface,#fff));color:var(--tblr-primary,#0d6efd);'>"
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
        $sprintId  = (int)($input['plugin_sprint_sprints_id'] ?? 0);
        $problemId = (int)($input['problems_id'] ?? 0);

        if ($sprintId <= 0 || $problemId <= 0) {
            Session::addMessageAfterRedirect(
                __('Please select a sprint and a problem.', 'sprint'),
                false,
                ERROR
            );
            return false;
        }

        if (!SprintItem::currentUserIsScrumMasterOf($sprintId)) {
            Session::addMessageAfterRedirect(
                __('Only the Scrum Master of this sprint can link items to it.', 'sprint'),
                false,
                ERROR
            );
            return false;
        }

        if (SprintItem::isLinkedItemInSprint($sprintId, 'Problem', $problemId)) {
            Session::addMessageAfterRedirect(
                sprintf(__('This problem (#%d) is already linked to this sprint.', 'sprint'), $problemId),
                false,
                ERROR
            );
            return false;
        }

        return parent::prepareInputForAdd($input);
    }

    public function post_addItem()
    {
        $problem = new Problem();
        if ($problem->getFromDB($this->fields['problems_id'])) {
            $item = new SprintItem();
            $item->add([
                'plugin_sprint_sprints_id' => $this->fields['plugin_sprint_sprints_id'],
                'name'                     => $problem->fields['name'],
                'itemtype'                 => 'Problem',
                'items_id'                 => $this->fields['problems_id'],
                'status'                   => SprintItem::STATUS_TODO,
                'priority'                 => (int)($problem->fields['priority'] ?? 3),
                'users_id'                 => (int)($this->fields['users_id'] ?? 0),
            ]);
        }
    }

    public function post_purgeItem()
    {
        $item = new SprintItem();
        $items = $item->find([
            'plugin_sprint_sprints_id' => $this->fields['plugin_sprint_sprints_id'],
            'itemtype'                 => 'Problem',
            'items_id'                 => $this->fields['problems_id'],
        ]);
        foreach ($items as $row) {
            $item->delete(['id' => $row['id']], 1);
        }
    }

    public static function cleanForItem(\CommonDBTM $item): void
    {
        $temp = new self();
        $temp->deleteByCriteria(['problems_id' => $item->getID()]);
    }
}
