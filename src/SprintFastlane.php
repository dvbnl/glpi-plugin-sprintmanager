<?php

namespace GlpiPlugin\Sprint;

use CommonGLPI;
use Session;

/**
 * Sprint tab listing fastlane items. Like Backlog, not a CommonDBTM but a
 * virtual view over SprintItem rows with is_fastlane = 1. Each entry links
 * to the SprintItem form where members + capacity are assigned.
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

        Sprint::renderHeaderBar($sprint);

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

        echo "<table class='tab_cadre_fixe sprint-themed'>";
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

        $rel      = new SprintFastlaneMember();
        $itemIds  = array_map(fn($r) => (int)$r['id'], $items);
        $tagsById = SprintItem::getTagsForItems($itemIds);
        $depsById = SprintItemDependency::getOpenSummariesForItems($itemIds);
        foreach ($items as $row) {
            $itemId = (int)$row['id'];
            $rels   = $rel->find(['plugin_sprint_sprintitems_id' => $itemId]);

            $memberNames = [];
            $totalCap    = 0.0;
            foreach ($rels as $r) {
                $uid = (int)$r['users_id'];
                $cap = (float)$r['capacity'];
                $totalCap += $cap;
                $memberNames[] = htmlescape(getUserName($uid)) . " (" . SprintMember::formatCapacity($cap) . "%)";
            }

            $statusLabel = $statuses[$row['status']] ?? $row['status'];
            $statusClass = 'sprint-status-' . str_replace('_', '-', $row['status']);

            $linkedDisplay = '<span style="color:#ccc;">-</span>';
            if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
                $tmp = new SprintItem();
                $tmp->fields = $row;
                $linkedDisplay = $tmp->getLinkedItemDisplay();
            }

            $rowTags = $tagsById[$itemId] ?? [];
            $rowDeps = $depsById[$itemId] ?? [];

            echo "<tr class='tab_bg_1'>";
            echo "<td><a href='" . SprintItem::getFormURLWithID($itemId) . "'>" .
                htmlescape($row['name']) . "</a>" . SprintItem::renderTagPills($rowTags) . SprintItem::renderDependencyBadge($rowDeps) . "</td>";
            echo "<td>" . $linkedDisplay . "</td>";
            echo "<td><span class='sprint-badge {$statusClass}'>" . $statusLabel . "</span></td>";
            echo "<td>" . ($priorities[$row['priority']] ?? $row['priority']) . "</td>";
            echo "<td>" . (count($memberNames) > 0 ? implode('<br>', $memberNames) :
                "<span style='color:#999;'>" . __('None', 'sprint') . "</span>") . "</td>";
            echo "<td class='center'><strong>" . SprintMember::formatCapacity($totalCap) . "%</strong></td>";
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

        // Mount the modal + JS for the "quick edit linked item" buttons;
        // without it those buttons render but do nothing.
        SprintItem::renderLinkedQuickEditUI();
    }
}
