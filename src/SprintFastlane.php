<?php

namespace GlpiPlugin\Sprint;

use CommonGLPI;
use Session;

/**
 * SprintFastlane - Sprint tab listing fastlane items.
 *
 * Mirrors the Backlog pattern: not a CommonDBTM, just a virtual collection
 * over SprintItem rows where is_fastlane = 1 AND
 * plugin_sprint_sprints_id = $sprintId.
 *
 * Registered as a tab on Sprint via setup.php. Each entry links back to
 * the SprintItem edit form, where the "Fastlane Members" tab lets users
 * assign sprint members + capacity.
 */
class SprintFastlane extends CommonGLPI
{
    public static $rightname = 'plugin_sprint_item';

    public static function getTypeName($nb = 0): string
    {
        return _n('Fastlane', 'Fastlane', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-bolt';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Sprint) {
            $count = countElementsInTable(
                SprintItem::getTable(),
                [
                    'plugin_sprint_sprints_id' => $item->getID(),
                    'is_fastlane'              => 1,
                ]
            );
            return self::createTabEntry(self::getTypeName(2), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof Sprint) {
            self::showForSprint($item);
            return true;
        }
        return false;
    }

    /**
     * Render the fastlane items list for a sprint.
     */
    public static function showForSprint(Sprint $sprint): void
    {
        $sprintId = $sprint->getID();
        $canedit  = SprintItem::canUpdate()
            || Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS);

        $si    = new SprintItem();
        $items = $si->find(
            [
                'plugin_sprint_sprints_id' => $sprintId,
                'is_fastlane'              => 1,
            ],
            ['priority DESC', 'date_creation DESC']
        );

        $statuses   = SprintItem::getAllStatuses();
        $priorities = [
            1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
            4 => __('High'), 5 => __('Very high'),
        ];

        echo "<div class='center'>";
        echo "<h3 style='margin:14px 0 6px;'><i class='" . self::getIcon() . "' style='color:#fd7e14;margin-right:6px;'></i>" .
            self::getTypeName(2) . "</h3>";
        echo "<p class='text-muted'>" .
            __('Items flagged as fastlane in the backlog. Open an entry to assign sprint members and capacity.', 'sprint') .
            "</p>";

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Name') . "</th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th>" . __('Status') . "</th>";
        echo "<th>" . __('Priority') . "</th>";
        echo "<th>" . __('Members', 'sprint') . "</th>";
        echo "<th>" . __('Total Fastlane capacity', 'sprint') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($items) === 0) {
            $cols = $canedit ? 7 : 6;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('No fastlane items in this sprint', 'sprint') . "</td></tr>";
        }

        $rel = new SprintFastlaneMember();
        foreach ($items as $row) {
            $itemId = (int)$row['id'];
            $rels   = $rel->find(['plugin_sprint_sprintitems_id' => $itemId]);

            $memberNames = [];
            $totalCap    = 0;
            foreach ($rels as $r) {
                $uid = (int)$r['users_id'];
                $cap = (int)$r['capacity'];
                $totalCap += $cap;
                $memberNames[] = htmlescape(getUserName($uid)) . " ({$cap}%)";
            }

            $statusLabel = $statuses[$row['status']] ?? $row['status'];
            $statusClass = 'sprint-status-' . str_replace('_', '-', $row['status']);

            $linkedDisplay = '<span style="color:#ccc;">-</span>';
            if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
                $tmp = new SprintItem();
                $tmp->fields = $row;
                $linkedDisplay = $tmp->getLinkedItemDisplay();
            }

            echo "<tr class='tab_bg_1'>";
            echo "<td><a href='" . SprintItem::getFormURLWithID($itemId) . "'>" .
                htmlescape($row['name']) . "</a></td>";
            echo "<td>" . $linkedDisplay . "</td>";
            echo "<td><span class='sprint-badge {$statusClass}'>" . $statusLabel . "</span></td>";
            echo "<td>" . ($priorities[$row['priority']] ?? $row['priority']) . "</td>";
            echo "<td>" . (count($memberNames) > 0 ? implode('<br>', $memberNames) :
                "<span style='color:#999;'>" . __('None', 'sprint') . "</span>") . "</td>";
            echo "<td class='center'><strong>{$totalCap}%</strong></td>";
            if ($canedit) {
                echo "<td class='center' style='white-space:nowrap;'>";
                echo "<a href='" . SprintItem::getFormURLWithID($itemId) .
                    "' class='btn btn-sm btn-outline-primary' title='" . __('Open') . "'>" .
                    "<i class='fas fa-edit'></i></a>";
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</table></div>";
    }
}
