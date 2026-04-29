<?php

namespace GlpiPlugin\Sprint;

use CommonGLPI;
use Html;
use Session;
use Ticket;
use Change;
use ProjectTask;

/**
 * SprintDashboard - Consolidated overview tab for a Sprint
 */
class SprintDashboard extends CommonGLPI
{
    public static $rightname = 'plugin_sprint_sprint';

    public static function getTypeName($nb = 0): string
    {
        return __('Dashboard', 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-tachometer-alt';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Sprint) {
            $count = countElementsInTable('glpi_plugin_sprint_sprintitems', ['plugin_sprint_sprints_id' => $item->getID()]);
            return self::createTabEntry(__('Dashboard', 'sprint'), $count);
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

    public static function showForSprint(Sprint $sprint): void
    {
        $ID        = $sprint->getID();
        $currentUserId = (int)Session::getLoginUserID();

        // Collect all items once
        $allItems      = self::getAllLinkedItems($ID);
        $personalItems = array_filter($allItems, function ($row) use ($currentUserId) {
            return (int)$row['users_id'] === $currentUserId;
        });

        $globalStats   = self::computeStats($allItems);
        $personalStats = self::computeStats($personalItems);

        // === View toggle buttons ===
        echo "<div style='display:flex;justify-content:center;gap:8px;padding:12px 0 4px;'>";
        echo "<button id='sprint_btn_global' class='btn btn-primary' onclick='sprintToggleView(\"global\")'>" .
            "<i class='fas fa-globe' style='margin-right:5px;'></i>" . __('Global View', 'sprint') . "</button>";
        echo "<button id='sprint_btn_personal' class='btn btn-outline-secondary' onclick='sprintToggleView(\"personal\")'>" .
            "<i class='fas fa-user' style='margin-right:5px;'></i>" . __('Personal View', 'sprint') . "</button>";
        echo "</div>";

        // === Global view ===
        echo "<div id='sprint_view_global'>";
        self::renderStatsAndProgress($globalStats);
        self::showMemberActivityChart($ID);
        self::showFastlaneItems($ID, null, 'global');
        self::renderItemsTable($allItems, __('No items in this sprint yet', 'sprint'), 'sprint-dashboard-items-global', $ID, 'global');
        self::showMemberCapacity($ID);
        echo "</div>";

        // === Personal view (hidden by default) ===
        echo "<div id='sprint_view_personal' style='display:none;'>";
        self::renderStatsAndProgress($personalStats);
        self::showFastlaneItems($ID, $currentUserId, 'personal');
        self::renderItemsTable($personalItems, __('No items assigned to you', 'sprint'), 'sprint-dashboard-items-personal', $ID, 'personal');
        self::showPersonalCapacity($ID, $currentUserId);
        echo "</div>";

        // === Toggle script ===
        echo "<script>
        function sprintToggleView(view) {
            var gDiv = document.getElementById('sprint_view_global');
            var pDiv = document.getElementById('sprint_view_personal');
            var gBtn = document.getElementById('sprint_btn_global');
            var pBtn = document.getElementById('sprint_btn_personal');
            if (view === 'personal') {
                gDiv.style.display = 'none';
                pDiv.style.display = '';
                gBtn.className = 'btn btn-outline-secondary';
                pBtn.className = 'btn btn-primary';
            } else {
                gDiv.style.display = '';
                pDiv.style.display = 'none';
                gBtn.className = 'btn btn-primary';
                pBtn.className = 'btn btn-outline-secondary';
            }
        }
        </script>";

        SprintItem::renderQuickEditUI($ID);
        SprintItem::renderLinkedQuickEditUI();
    }

    /**
     * Compute stats from an array of item rows
     */
    private static function computeStats(array $items): array
    {
        $stats = [
            'total_items'   => count($items),
            'todo_items'    => 0,
            'in_progress'   => 0,
            'review_items'  => 0,
            'done_items'    => 0,
            'blocked_items' => 0,
            'total_points'  => 0,
            'done_points'   => 0,
        ];

        foreach ($items as $row) {
            $stats['total_points'] += (int)$row['story_points'];
            switch ($row['raw_status']) {
                case SprintItem::STATUS_TODO:
                    $stats['todo_items']++;
                    break;
                case SprintItem::STATUS_IN_PROGRESS:
                    $stats['in_progress']++;
                    break;
                case SprintItem::STATUS_REVIEW:
                    $stats['review_items']++;
                    break;
                case SprintItem::STATUS_DONE:
                    $stats['done_items']++;
                    $stats['done_points'] += (int)$row['story_points'];
                    break;
                case SprintItem::STATUS_BLOCKED:
                    $stats['blocked_items']++;
                    break;
            }
        }

        return $stats;
    }

    /**
     * Render stats cards and progress bar
     */
    private static function renderStatsAndProgress(array $stats): void
    {
        // === Stats cards ===
        echo "<div style='display:flex;flex-wrap:wrap;gap:14px;padding:16px 0 20px;justify-content:center;'>";

        $cards = [
            ['label' => __('Total Items', 'sprint'),  'value' => $stats['total_items'],  'icon' => 'fas fa-list-ul',       'color' => '#6c757d', 'bg' => '#f8f9fa', 'accent' => '#6c757d'],
            ['label' => __('Done', 'sprint'),          'value' => $stats['done_items'],   'icon' => 'fas fa-check-circle',   'color' => '#198754', 'bg' => '#d1e7dd', 'accent' => '#198754'],
            ['label' => __('In Progress', 'sprint'),   'value' => $stats['in_progress'],  'icon' => 'fas fa-circle-notch',   'color' => '#0d6efd', 'bg' => '#cfe2ff', 'accent' => '#0d6efd'],
            ['label' => __('In Review', 'sprint'),     'value' => $stats['review_items'], 'icon' => 'fas fa-search',         'color' => '#6f42c1', 'bg' => '#e9d7f7', 'accent' => '#6f42c1'],
            ['label' => __('Blocked', 'sprint'),       'value' => $stats['blocked_items'],'icon' => 'fas fa-hand-paper',     'color' => '#dc3545', 'bg' => '#f8d7da', 'accent' => '#dc3545'],
            ['label' => __('Story Points', 'sprint'),  'value' => $stats['done_points'] . ' / ' . $stats['total_points'], 'icon' => 'fas fa-star', 'color' => '#d68a00', 'bg' => '#fff3cd', 'accent' => '#d68a00'],
        ];

        foreach ($cards as $c) {
            echo "<div style='background:{$c['bg']};border:1px solid {$c['accent']}22;border-radius:10px;padding:16px 22px;min-width:130px;text-align:center;position:relative;overflow:hidden;transition:box-shadow 0.2s,transform 0.2s;border-top:3px solid {$c['accent']};'>";
            echo "<div style='font-size:1.3em;color:{$c['color']};margin-bottom:6px;opacity:0.85;'><i class='{$c['icon']}'></i></div>";
            echo "<div style='font-size:1.6em;font-weight:700;color:{$c['color']};line-height:1.2;'>{$c['value']}</div>";
            echo "<div style='font-size:0.78em;color:#6c757d;margin-top:3px;text-transform:uppercase;letter-spacing:0.03em;'>{$c['label']}</div>";
            echo "</div>";
        }
        echo "</div>";

        // === Progress bar ===
        $total = max($stats['total_items'], 1);
        $donePct     = round(($stats['done_items'] / $total) * 100, 1);
        $progressPct = round(($stats['in_progress'] / $total) * 100, 1);
        $reviewPct   = round(($stats['review_items'] / $total) * 100, 1);
        $blockedPct  = round(($stats['blocked_items'] / $total) * 100, 1);
        $todoPct     = round(($stats['todo_items'] / $total) * 100, 1);

        echo "<div style='margin:0 0 24px;'>";
        echo "<div style='display:flex;gap:18px;justify-content:center;margin-bottom:8px;font-size:0.82em;color:#6c757d;flex-wrap:wrap;'>";
        echo "<span><span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:#198754;margin-right:4px;vertical-align:middle;'></span>" . __('Done', 'sprint') . " {$donePct}%</span>";
        echo "<span><span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:#0d6efd;margin-right:4px;vertical-align:middle;'></span>" . __('In Progress', 'sprint') . " {$progressPct}%</span>";
        echo "<span><span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:#6f42c1;margin-right:4px;vertical-align:middle;'></span>" . __('In Review', 'sprint') . " {$reviewPct}%</span>";
        echo "<span><span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:#dc3545;margin-right:4px;vertical-align:middle;'></span>" . __('Blocked', 'sprint') . " {$blockedPct}%</span>";
        echo "<span><span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:#d5d8dc;margin-right:4px;vertical-align:middle;'></span>" . __('To Do', 'sprint') . " {$todoPct}%</span>";
        echo "</div>";
        echo "<div style='width:100%;height:20px;background:#e9ecef;border-radius:10px;overflow:hidden;display:flex;'>";
        if ($donePct > 0)     echo "<div style='width:{$donePct}%;height:100%;background:#198754;transition:width 0.4s;' title='" . __('Done', 'sprint') . "'></div>";
        if ($progressPct > 0) echo "<div style='width:{$progressPct}%;height:100%;background:#0d6efd;transition:width 0.4s;' title='" . __('In Progress', 'sprint') . "'></div>";
        if ($reviewPct > 0)   echo "<div style='width:{$reviewPct}%;height:100%;background:#6f42c1;transition:width 0.4s;' title='" . __('In Review', 'sprint') . "'></div>";
        if ($blockedPct > 0)  echo "<div style='width:{$blockedPct}%;height:100%;background:#dc3545;transition:width 0.4s;' title='" . __('Blocked', 'sprint') . "'></div>";
        if ($todoPct > 0)     echo "<div style='width:{$todoPct}%;height:100%;background:#d5d8dc;transition:width 0.4s;' title='" . __('To Do', 'sprint') . "'></div>";
        echo "</div>";
        echo "</div>";
    }

    /**
     * Render items table
     *
     * @param array  $items
     * @param string $emptyMessage
     * @param string $tableSelector  CSS class used to scope filter/sort JS to
     *                               this specific table (so global & personal
     *                               views operate independently).
     */
    private static function renderItemsTable(array $items, string $emptyMessage, string $tableSelector = 'sprint-dashboard-items', int $sprintId = 0, string $viewKey = 'global'): void
    {
        $statuses   = SprintItem::getAllStatuses();
        $priorities = [
            1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
            4 => __('High'), 5 => __('Very high'),
        ];

        // Collapsible wrapper — remembered per sprint + view so global and
        // personal views keep independent expand/collapse state.
        $collapseKey = 'dash-items-' . (int)$sprintId . '-' . $viewKey;
        echo "<div class='sprint-collapsible' data-sprint-collapse-key='" . htmlescape($collapseKey) . "'>";
        echo "<div class='sprint-collapsible-header'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron'></i>";
        echo "<i class='fas fa-list-ul' style='margin-left:2px;'></i>";
        echo "<span>" . __('Sprint items', 'sprint') . " <span class='text-muted' style='font-weight:400;'>(" . count($items) . ")</span></span>";
        echo "</div>";
        echo "<div class='sprint-collapsible-body'>";

        // Shared filter bar (window.SprintFilter wires the JS).
        SprintItem::renderFilterBar($tableSelector, [
            'statuses' => $statuses,
            'owners'   => $sprintId > 0 ? SprintMember::getSprintMemberOptions($sprintId) : [],
        ]);

        echo "<table class='tab_cadre_fixe {$tableSelector} sprint-dashboard-table'>";
        echo "<tr class='tab_bg_2'>";
        $sc = SprintItem::sortClickAttr($tableSelector);
        echo "<th class='sprint-sortable' data-sort-type='type' style='cursor:pointer;' {$sc}>" . __('Type') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='name' style='cursor:pointer;' {$sc}>" . __('Name') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th class='sprint-sortable' data-sort-type='status' style='cursor:pointer;' {$sc}>" . __('Status') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='priority' style='cursor:pointer;' {$sc}>" . __('Priority') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='owner' style='cursor:pointer;' {$sc}>" . __('Owner', 'sprint') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th style='width:60px;'></th>";
        echo "</tr>";

        if (count($items) === 0) {
            echo "<tr class='tab_bg_1'><td colspan='7' style='padding:32px;color:#adb5bd;text-align:center;'>" .
                "<i class='fas fa-inbox' style='font-size:2.2em;display:block;margin-bottom:10px;opacity:0.5;'></i>" .
                $emptyMessage . "</td></tr>";
        }

        foreach ($items as $row) {
            $linkedDisplay = !empty($row['linked_display'])
                ? $row['linked_display']
                : "<span style='color:#ccc;'>-</span>";

            $itemtypeCode = $row['itemtype_code'] ?? '';
            $typeFilter   = $itemtypeCode === '' ? 'Manual' : $itemtypeCode;
            $ownerNameRaw = $row['member_name'] ?? '';

            // Lay down every data-* attr the quick-edit modal and client-side
            // filter/sort logic need on the row itself, so JS can read them
            // without traversing into cells.
            $dataAttrs = 'class="tab_bg_1 sprint-row sprint-dashboard-row sprint-filterable-row"'
                . ' data-item-id="' . (int)$row['item_id'] . '"'
                . ' data-item-name="' . htmlescape((string)$row['name']) . '"'
                . ' data-item-status="' . htmlescape((string)$row['raw_status']) . '"'
                . ' data-item-status-label="' . htmlescape((string)($statuses[$row['raw_status']] ?? $row['raw_status'])) . '"'
                . ' data-item-priority="' . (int)($row['raw_priority'] ?? 3) . '"'
                . ' data-item-priority-label="' . htmlescape((string)($priorities[$row['raw_priority']] ?? '')) . '"'
                . ' data-item-type="' . htmlescape($typeFilter) . '"'
                . ' data-item-type-label="' . htmlescape((string)$row['type_label']) . '"'
                . ' data-users-id="' . (int)$row['users_id'] . '"'
                . ' data-owner-name="' . htmlescape($ownerNameRaw) . '"'
                . ' data-story-points="' . (int)$row['story_points'] . '"'
                . ' data-capacity="' . (int)($row['capacity'] ?? 0) . '"'
                . ' data-is-fastlane="0"'
                . ' data-note="' . htmlescape((string)($row['note'] ?? '')) . '"';

            echo "<tr {$dataAttrs}>";
            echo "<td style='white-space:nowrap;'><i class='{$row['icon']}' style='color:{$row['color']};margin-right:5px;opacity:0.85;'></i>{$row['type_label']}</td>";
            echo "<td class='sprint-cell-name'><a href='{$row['url']}'>" . htmlescape($row['name']) . "</a></td>";
            echo "<td>{$linkedDisplay}</td>";
            echo "<td class='sprint-cell-status'>{$row['status']}</td>";
            echo "<td class='sprint-cell-priority'>{$row['priority']}</td>";
            echo "<td class='sprint-cell-owner'>{$row['member']}</td>";
            echo "<td class='text-center'>";
            echo "<button type='button' class='btn btn-sm btn-outline-secondary sprint-quick-edit-btn' "
                . "title='" . __('Quick edit', 'sprint') . "' data-item-id='" . (int)$row['item_id'] . "'>"
                . "<i class='fas fa-pen'></i></button>";
            echo "</td>";
            echo "</tr>";
        }

        echo "</table>";
        echo "</div>"; // .sprint-collapsible-body
        echo "</div>"; // .sprint-collapsible
    }

    /**
     * Show member capacity overview, broken down by Regular vs Fastlane
     * categories so the team can see how much sprint capacity is going
     * to fastlane work.
     */
    private static function showMemberCapacity(int $sprintId): void
    {
        $member  = new SprintMember();
        $members = $member->find(['plugin_sprint_sprints_id' => $sprintId], ['role ASC']);

        if (count($members) === 0) {
            return;
        }

        // Regular (non-fastlane) per-user usage
        $si = new SprintItem();
        $regularUsed = [];
        foreach ($si->find([
            'plugin_sprint_sprints_id' => $sprintId,
            'is_fastlane'              => 0,
        ]) as $row) {
            $uid = (int)$row['users_id'];
            if ($uid > 0) {
                $regularUsed[$uid] = ($regularUsed[$uid] ?? 0) + (int)($row['capacity'] ?? 0);
            }
        }

        // Fastlane per-user usage from the junction table
        $fastlaneUsed = [];
        foreach ($members as $row) {
            $uid = (int)$row['users_id'];
            $fastlaneUsed[$uid] = SprintFastlaneMember::getUsedFastlaneCapacityForUser($sprintId, $uid);
        }

        $roles = SprintMember::getAllRoles();
        $sprintFastlaneTotal = SprintFastlaneMember::getTotalFastlaneCapacityForSprint($sprintId);

        echo "<div style='margin-top:20px;'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'><th colspan='8'>" .
            "<i class='fas fa-users' style='margin-right:6px;'></i>" .
            __('Team Capacity', 'sprint') .
            " &mdash; <i class='fas fa-bolt' style='color:#fd7e14;'></i> " .
            sprintf(__('Fastlane total: %d%%', 'sprint'), $sprintFastlaneTotal) .
            "</th></tr>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Member', 'sprint') . "</th>";
        echo "<th>" . __('Role', 'sprint') . "</th>";
        echo "<th>" . __('Total', 'sprint') . "</th>";
        echo "<th>" . __('Regular', 'sprint') . "</th>";
        echo "<th><i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;'></i>" . __('Fastlane', 'sprint') . "</th>";
        echo "<th>" . __('Used', 'sprint') . "</th>";
        echo "<th>" . __('Available', 'sprint') . "</th>";
        echo "<th>" . __('Capacity', 'sprint') . "</th>";
        echo "</tr>";

        foreach ($members as $row) {
            $uid       = (int)$row['users_id'];
            $total     = (int)$row['capacity_percent'];
            $regular   = $regularUsed[$uid] ?? 0;
            $fastlane  = $fastlaneUsed[$uid] ?? 0;
            $used      = $regular + $fastlane;
            $remaining = max($total - $used, 0);
            $pctUsed   = ($total > 0) ? round(($used / $total) * 100) : 0;
            $roleName  = $roles[$row['role']] ?? $row['role'];

            $remainColor = '#198754';
            if ($remaining <= 0) {
                $remainColor = '#dc3545';
            } elseif ($pctUsed >= 80) {
                $remainColor = '#e67e22';
            }

            $overload = ($total - $used < 0)
                ? ' <span style="color:#dc3545;font-weight:700;">(' . __('overloaded', 'sprint') . ')</span>'
                : '';

            $regularWidth  = ($total > 0) ? min(round(($regular / $total) * 100), 100) : 0;
            $fastlaneWidth = ($total > 0) ? min(round(($fastlane / $total) * 100), 100 - $regularWidth) : 0;

            echo "<tr class='tab_bg_1'>";
            echo "<td><i class='fas fa-user' style='margin-right:6px;opacity:0.6;'></i>" . getUserName($uid) . "</td>";
            echo "<td>" . $roleName . "</td>";
            echo "<td class='center'>{$total}%</td>";
            echo "<td class='center'>{$regular}%</td>";
            echo "<td class='center'>" . ($fastlane > 0
                ? "<strong style='color:#fd7e14;'>{$fastlane}%</strong>"
                : "{$fastlane}%") . "</td>";
            echo "<td class='center'>{$used}%</td>";
            echo "<td class='center' style='font-weight:700;color:{$remainColor};'>{$remaining}%{$overload}</td>";
            echo "<td style='min-width:180px;'>";
            // Stacked bar: regular = red, fastlane = orange, free = green
            echo "<div style='display:flex;height:16px;background:#198754;border-radius:8px;overflow:hidden;'>";
            if ($regularWidth > 0) {
                echo "<div style='width:{$regularWidth}%;height:100%;background:#dc3545;' title='" . __('Regular', 'sprint') . " {$regular}%'></div>";
            }
            if ($fastlaneWidth > 0) {
                echo "<div style='width:{$fastlaneWidth}%;height:100%;background:#fd7e14;' title='" . __('Fastlane', 'sprint') . " {$fastlane}%'></div>";
            }
            echo "</div>";
            echo "</td>";
            echo "</tr>";
        }

        echo "</table></div>";
    }

    /**
     * Render fastlane items table on the dashboard, between regular sprint
     * items and the team capacity overview. Optionally restricted to items
     * the given user is a fastlane member of.
     */
    private static function showFastlaneItems(int $sprintId, ?int $forUserId = null, string $viewKey = 'global'): void
    {
        $si    = new SprintItem();
        $items = $si->find(
            [
                'plugin_sprint_sprints_id' => $sprintId,
                'is_fastlane'              => 1,
            ],
            ['priority DESC', 'date_creation DESC']
        );

        if (count($items) === 0) {
            return;
        }

        $statuses = SprintItem::getAllStatuses();
        $statusBgColors = [
            SprintItem::STATUS_TODO        => '#6c757d',
            SprintItem::STATUS_IN_PROGRESS => '#0d6efd',
            SprintItem::STATUS_REVIEW      => '#6f42c1',
            SprintItem::STATUS_DONE        => '#198754',
            SprintItem::STATUS_BLOCKED     => '#dc3545',
        ];

        // Pre-load all fastlane member rows for this sprint in one query
        $rel       = new SprintFastlaneMember();
        $itemIds   = array_map(fn($r) => (int)$r['id'], $items);
        $relRowsByItem = [];
        if (count($itemIds) > 0) {
            $allRels = $rel->find(['plugin_sprint_sprintitems_id' => $itemIds]);
            foreach ($allRels as $r) {
                $relRowsByItem[(int)$r['plugin_sprint_sprintitems_id']][] = $r;
            }
        }

        $sprintFastlaneTotal = SprintFastlaneMember::getTotalFastlaneCapacityForSprint($sprintId);

        // Count how many items will be shown (personal view filters by user)
        $visibleCount = 0;
        foreach ($items as $row) {
            if ($forUserId !== null) {
                $relRows = $relRowsByItem[(int)$row['id']] ?? [];
                $hasUser = false;
                foreach ($relRows as $r) {
                    if ((int)$r['users_id'] === $forUserId) { $hasUser = true; break; }
                }
                if (!$hasUser) { continue; }
            }
            $visibleCount++;
        }

        // Collapsible wrapper — persisted per sprint + view.
        $collapseKey = 'dash-fastlane-' . (int)$sprintId . '-' . $viewKey;
        echo "<div class='sprint-collapsible' data-sprint-collapse-key='" . htmlescape($collapseKey) . "'>";
        echo "<div class='sprint-collapsible-header'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron'></i>";
        echo "<i class='fas fa-bolt' style='color:#fd7e14;margin-left:2px;'></i>";
        echo "<span>" . __('Fastlane', 'sprint') .
            " <span class='text-muted' style='font-weight:400;'>(" . $visibleCount . ")</span>" .
            " &mdash; " . sprintf(__('Total capacity: %d%%', 'sprint'), $sprintFastlaneTotal) .
            "</span>";
        echo "</div>";
        echo "<div class='sprint-collapsible-body'>";

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Name') . "</th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th>" . __('Status') . "</th>";
        echo "<th>" . __('Members', 'sprint') . "</th>";
        echo "<th>" . __('Total', 'sprint') . "</th>";
        echo "</tr>";

        $rendered = 0;
        foreach ($items as $row) {
            $itemId  = (int)$row['id'];
            $relRows = $relRowsByItem[$itemId] ?? [];

            // Personal view: only show items the user is allocated on
            if ($forUserId !== null) {
                $hasUser = false;
                foreach ($relRows as $r) {
                    if ((int)$r['users_id'] === $forUserId) {
                        $hasUser = true;
                        break;
                    }
                }
                if (!$hasUser) {
                    continue;
                }
            }

            $totalCap = 0;
            $memberLines = [];
            foreach ($relRows as $r) {
                $cap = (int)$r['capacity'];
                $totalCap += $cap;
                $memberLines[] = htmlescape(getUserName((int)$r['users_id'])) . " ({$cap}%)";
            }

            $linkedDisplay = '<span style="color:#ccc;">-</span>';
            if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
                $tmp = new SprintItem();
                $tmp->fields = $row;
                $linkedDisplay = $tmp->getLinkedItemDisplay();
            }

            $statusBg    = $statusBgColors[$row['status']] ?? '#6c757d';
            $statusLabel = $statuses[$row['status']] ?? $row['status'];

            echo "<tr class='tab_bg_1'>";
            echo "<td><a href='" . SprintItem::getFormURLWithID($itemId) . "'>" .
                "<i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;'></i>" .
                htmlescape($row['name']) . "</a></td>";
            echo "<td>{$linkedDisplay}</td>";
            echo "<td><span class='sprint-badge' style='display:inline-block;padding:4px 12px;border-radius:20px;font-size:0.8em;font-weight:600;color:#fff;background-color:{$statusBg};'>" .
                $statusLabel . "</span></td>";
            echo "<td>" . (count($memberLines) > 0 ? implode('<br>', $memberLines) :
                "<span style='color:#999;'>" . __('None', 'sprint') . "</span>") . "</td>";
            echo "<td class='center'><strong>{$totalCap}%</strong></td>";
            echo "</tr>";
            $rendered++;
        }

        if ($rendered === 0) {
            echo "<tr class='tab_bg_1'><td colspan='5' class='center'>" .
                __('No fastlane items', 'sprint') . "</td></tr>";
        }

        echo "</table>";
        echo "</div>"; // .sprint-collapsible-body
        echo "</div>"; // .sprint-collapsible
    }

    /**
     * Get all sprint items for the dashboard
     */
    private static function getAllLinkedItems(int $sprintId): array
    {
        $items = [];
        $statuses   = SprintItem::getAllStatuses();
        $priorities = [
            1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
            4 => __('High'), 5 => __('Very high'),
        ];
        $typeIcons = [
            ''            => ['fas fa-clipboard-list', '#6c757d', __('Manual', 'sprint')],
            'Ticket'      => ['fas fa-ticket-alt', '#0d6efd', __('Ticket')],
            'Change'      => ['fas fa-exchange-alt', '#6f42c1', __('Change')],
            'ProjectTask' => ['fas fa-tasks', '#fd7e14', __('Project task')],
        ];

        $si = new SprintItem();
        // Fastlane items are surfaced in their own dashboard section.
        foreach ($si->find(
            [
                'plugin_sprint_sprints_id' => $sprintId,
                'is_fastlane'              => 0,
            ],
            ['sort_order ASC', 'priority DESC']
        ) as $row) {
            $itemtype = $row['itemtype'] ?? '';
            $itemsId  = (int)($row['items_id'] ?? 0);
            $typeInfo = $typeIcons[$itemtype] ?? $typeIcons[''];

            $linkedName = '';
            $linkedUrl  = '';
            // Delegate the linked-item rendering (name, icon, parent project
            // suffix for ProjectTask, and the inline quick-edit ✎ button) to
            // SprintItem::getLinkedItemDisplay() so the dashboard table stays
            // consistent with the sprint items tab and meeting review.
            $tmp = new SprintItem();
            $tmp->fields = $row;
            $linkedDisplayHtml = $tmp->getLinkedItemDisplay();

            $allowedTypes = ['Ticket', 'Change', 'ProjectTask'];
            if (!empty($itemtype) && $itemsId > 0 && in_array($itemtype, $allowedTypes, true) && class_exists($itemtype)) {
                $linked = new $itemtype();
                if ($linked->getFromDB($itemsId)) {
                    $linkedName = $linked->fields['name'];
                    $linkedUrl  = $itemtype::getFormURLWithID($itemsId);
                }
            }

            $statusClass = 'sprint-status-' . str_replace('_', '-', $row['status']);

            // Inline background color so the badge stays readable even if
            // the plugin's CSS variables (var(--sprint-todo) etc.) are not
            // resolved in the current rendering context.
            $statusBgColors = [
                SprintItem::STATUS_TODO        => '#6c757d',
                SprintItem::STATUS_IN_PROGRESS => '#0d6efd',
                SprintItem::STATUS_REVIEW      => '#6f42c1',
                SprintItem::STATUS_DONE        => '#198754',
                SprintItem::STATUS_BLOCKED     => '#dc3545',
            ];
            $statusBg = $statusBgColors[$row['status']] ?? '#6c757d';

            $items[] = [
                'item_id'        => (int)$row['id'],
                'itemtype_code'  => $itemtype,
                'type_label'     => $typeInfo[2],
                'icon'           => $typeInfo[0],
                'color'          => $typeInfo[1],
                'name'           => $row['name'],
                'url'            => SprintItem::getFormURLWithID($row['id']),
                'linked_name'    => $linkedName,
                'linked_url'     => $linkedUrl,
                'linked_display' => $linkedDisplayHtml,
                'raw_status'    => $row['status'],
                'raw_priority'  => (int)($row['priority'] ?? 3),
                'story_points'  => (int)$row['story_points'],
                'capacity'      => (int)($row['capacity'] ?? 0),
                'users_id'      => (int)$row['users_id'],
                'note'          => (string)($row['note'] ?? ''),
                'member_name'   => ((int)$row['users_id'] > 0) ? getUserName((int)$row['users_id']) : '',
                'status'        => '<span class="sprint-badge ' . $statusClass . '" style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:0.8em;font-weight:600;color:#fff;background-color:' . $statusBg . ';">' .
                                   ($statuses[$row['status']] ?? $row['status']) . '</span>',
                'priority'      => $priorities[$row['priority']] ?? '',
                'member'        => self::getMemberName((int)$row['users_id']),
            ];
        }

        return $items;
    }

    /**
     * Show capacity overview for the current user only
     */
    private static function showPersonalCapacity(int $sprintId, int $userId): void
    {
        $member  = new SprintMember();
        $members = $member->find([
            'plugin_sprint_sprints_id' => $sprintId,
            'users_id'                 => $userId,
        ]);

        if (count($members) === 0) {
            return;
        }

        $si = new SprintItem();
        $regularUsed = 0;
        foreach ($si->find([
            'plugin_sprint_sprints_id' => $sprintId,
            'users_id'                 => $userId,
            'is_fastlane'              => 0,
        ]) as $row) {
            $regularUsed += (int)($row['capacity'] ?? 0);
        }
        $fastlaneUsed = SprintFastlaneMember::getUsedFastlaneCapacityForUser($sprintId, $userId);
        $usedCapacity = $regularUsed + $fastlaneUsed;

        $roles = SprintMember::getAllRoles();
        $memberData = reset($members);
        $total      = (int)$memberData['capacity_percent'];
        $remaining  = max($total - $usedCapacity, 0);
        $pctUsed    = ($total > 0) ? round(($usedCapacity / $total) * 100) : 0;
        $roleName   = $roles[$memberData['role']] ?? $memberData['role'];

        $remainColor = '#198754';
        if ($remaining <= 0) {
            $remainColor = '#dc3545';
        } elseif ($pctUsed >= 80) {
            $remainColor = '#e67e22';
        }

        $overload = ($total - $usedCapacity < 0)
            ? ' <span style="color:#dc3545;font-weight:700;">(' . __('overloaded', 'sprint') . ')</span>'
            : '';

        $regularWidth  = ($total > 0) ? min(round(($regularUsed / $total) * 100), 100) : 0;
        $fastlaneWidth = ($total > 0) ? min(round(($fastlaneUsed / $total) * 100), 100 - $regularWidth) : 0;

        echo "<div style='margin-top:20px;'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'><th colspan='8'>" .
            "<i class='fas fa-user' style='margin-right:6px;'></i>" .
            __('Your Capacity', 'sprint') . "</th></tr>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Member', 'sprint') . "</th>";
        echo "<th>" . __('Role', 'sprint') . "</th>";
        echo "<th>" . __('Total', 'sprint') . "</th>";
        echo "<th>" . __('Regular', 'sprint') . "</th>";
        echo "<th><i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;'></i>" . __('Fastlane', 'sprint') . "</th>";
        echo "<th>" . __('Used', 'sprint') . "</th>";
        echo "<th>" . __('Available', 'sprint') . "</th>";
        echo "<th>" . __('Capacity', 'sprint') . "</th>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><i class='fas fa-user' style='margin-right:6px;opacity:0.6;'></i>" . getUserName($userId) . "</td>";
        echo "<td>" . $roleName . "</td>";
        echo "<td class='center'>{$total}%</td>";
        echo "<td class='center'>{$regularUsed}%</td>";
        echo "<td class='center'>" . ($fastlaneUsed > 0
            ? "<strong style='color:#fd7e14;'>{$fastlaneUsed}%</strong>"
            : "{$fastlaneUsed}%") . "</td>";
        echo "<td class='center'>{$usedCapacity}%</td>";
        echo "<td class='center' style='font-weight:700;color:{$remainColor};'>{$remaining}%{$overload}</td>";
        echo "<td style='min-width:180px;'>";
        echo "<div style='display:flex;height:16px;background:#198754;border-radius:8px;overflow:hidden;'>";
        if ($regularWidth > 0) {
            echo "<div style='width:{$regularWidth}%;height:100%;background:#dc3545;' title='" . __('Regular', 'sprint') . " {$regularUsed}%'></div>";
        }
        if ($fastlaneWidth > 0) {
            echo "<div style='width:{$fastlaneWidth}%;height:100%;background:#fd7e14;' title='" . __('Fastlane', 'sprint') . " {$fastlaneUsed}%'></div>";
        }
        echo "</div>";
        echo "</td>";
        echo "</tr>";

        echo "</table></div>";
    }

    private static function getMemberName(int $usersId): string
    {
        if ($usersId > 0) {
            return getUserName($usersId);
        }
        return '<span style="color:#adb5bd;font-style:italic;">' . __('Unassigned', 'sprint') . '</span>';
    }

    /**
     * Render a per-member activity line chart driven by audit-log data.
     * Gives scrum masters a quick visual for spotting spikes (one-day
     * blitz followed by silence) without opening the full audit tab.
     *
     * Pure inline SVG — no Chart.js dependency, no external assets.
     */
    private static function showMemberActivityChart(int $sprintId): void
    {
        $data    = SprintAudit::getMemberActivity($sprintId);
        $dates   = $data['dates'];
        $members = $data['members'];

        $collapseKey = 'dash-activity-' . (int)$sprintId;
        echo "<div class='sprint-collapsible' data-sprint-collapse-key='" . htmlescape($collapseKey) . "'>";
        echo "<div class='sprint-collapsible-header'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron'></i>";
        echo "<i class='fas fa-chart-line' style='margin-left:2px;'></i>";
        echo "<span>" . __('Team activity', 'sprint') . "</span>";
        echo "</div>";
        echo "<div class='sprint-collapsible-body'>";

        echo "<div style='font-size:0.85em;color:#6c757d;margin-bottom:6px;'>" .
            __('Audit-log events per member per day — spot uneven workloads.', 'sprint') .
            "</div>";

        if (count($dates) < 2 || count($members) === 0) {
            // Diagnose why the chart is empty so users (especially on
            // existing/older sprints, where activity may be outside the
            // retention window) understand the feature is present and why
            // there's nothing to plot yet.
            $reason = self::diagnoseActivityEmptyReason($sprintId, $dates, $members);
            echo "<div style='padding:18px;text-align:center;color:#6c757d;background:#f8f9fa;border:1px dashed #dee2e6;border-radius:6px;'>"
                . "<i class='fas fa-info-circle' style='margin-right:6px;'></i>"
                . htmlescape($reason)
                . "</div>";
            echo "</div>"; // .sprint-collapsible-body
            echo "</div>"; // .sprint-collapsible
            return;
        }

        // Chart dimensions
        $width  = 820;
        $height = 220;
        $padL   = 40;   // left padding for y-axis labels
        $padR   = 20;
        $padT   = 16;
        $padB   = 36;   // bottom padding for x-axis labels
        $plotW  = $width  - $padL - $padR;
        $plotH  = $height - $padT - $padB;

        // Y scale: max value across all members, with a small headroom
        $max = 1;
        foreach ($members as $m) {
            foreach ($m['counts'] as $c) {
                if ($c > $max) { $max = $c; }
            }
        }
        $yMax = max(1, $max);
        // Round up to a "nice" integer tick so labels aren't fractional.
        $yTickStep = (int)max(1, ceil($yMax / 4));
        $yMax      = $yTickStep * 4;

        $nDates = count($dates);
        $xStep = ($nDates > 1) ? $plotW / ($nDates - 1) : 0;

        $xAt = fn(int $i) => $padL + ($xStep * $i);
        $yAt = fn(int $v) => $padT + $plotH - ($plotH * ($v / $yMax));

        echo "<div class='sprint-member-activity'>";
        echo "<div style='overflow-x:auto;'>";
        echo "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$width} {$height}' "
            . "style='width:100%;height:auto;max-width:{$width}px;font-family:sans-serif;font-size:11px;'>";

        // Horizontal grid + y-axis tick labels
        for ($t = 0; $t <= 4; $t++) {
            $yv = (int)round($yMax * $t / 4);
            $y  = $yAt($yv);
            $yStr = number_format($y, 2, '.', '');
            echo "<line x1='{$padL}' y1='{$yStr}' x2='" . ($padL + $plotW) . "' y2='{$yStr}' "
                . "stroke='#e9ecef' stroke-width='1' />";
            echo "<text x='" . ($padL - 6) . "' y='" . number_format($y + 3, 2, '.', '') . "' "
                . "text-anchor='end' fill='#6c757d'>{$yv}</text>";
        }

        // X-axis labels — thin them out so they don't overlap on long ranges
        $labelEvery = max(1, (int)ceil($nDates / 10));
        for ($i = 0; $i < $nDates; $i++) {
            if ($i % $labelEvery !== 0 && $i !== $nDates - 1) {
                continue;
            }
            $x = $xAt($i);
            $xStr = number_format($x, 2, '.', '');
            // Shorten to "d/m" to fit.
            $ts = strtotime($dates[$i]);
            $label = $ts ? date('d/m', $ts) : $dates[$i];
            echo "<text x='{$xStr}' y='" . ($padT + $plotH + 16) . "' "
                . "text-anchor='middle' fill='#6c757d'>" . htmlescape($label) . "</text>";
        }

        // Axes
        echo "<line x1='{$padL}' y1='{$padT}' x2='{$padL}' y2='" . ($padT + $plotH) . "' "
            . "stroke='#adb5bd' stroke-width='1' />";
        echo "<line x1='{$padL}' y1='" . ($padT + $plotH) . "' x2='" . ($padL + $plotW) . "' y2='" . ($padT + $plotH) . "' "
            . "stroke='#adb5bd' stroke-width='1' />";

        // One polyline per member
        foreach ($members as $idx => $m) {
            $points = [];
            foreach ($m['counts'] as $i => $c) {
                $points[] = number_format($xAt($i), 2, '.', '') . ',' . number_format($yAt((int)$c), 2, '.', '');
            }
            $pts = implode(' ', $points);
            $color = htmlescape($m['color']);
            echo "<polyline class='sprint-activity-line' data-member-idx='" . (int)$idx . "' "
                . "points='{$pts}' fill='none' stroke='{$color}' stroke-width='2' "
                . "stroke-linejoin='round' stroke-linecap='round' />";
            // Data-point dots (subtle)
            foreach ($m['counts'] as $i => $c) {
                if ($c <= 0) { continue; }
                $cx = number_format($xAt($i), 2, '.', '');
                $cy = number_format($yAt((int)$c), 2, '.', '');
                $title = htmlescape($m['name'] . ' — ' . $dates[$i] . ': ' . $c);
                echo "<circle class='sprint-activity-dot' data-member-idx='" . (int)$idx . "' "
                    . "cx='{$cx}' cy='{$cy}' r='2.5' fill='{$color}'>"
                    . "<title>{$title}</title></circle>";
            }
        }

        echo "</svg>";
        echo "</div>"; // overflow-x scroll wrapper

        // Legend — click to isolate a member, hover to preview. Wired by sprint.js.
        echo "<div class='sprint-activity-legend-wrap' style='display:flex;flex-wrap:wrap;gap:12px;margin-top:8px;font-size:0.9em;'>";
        foreach ($members as $idx => $m) {
            echo "<div class='sprint-activity-legend' data-member-idx='" . (int)$idx . "' "
                . "title='" . htmlescape(__('Click to isolate — hover to preview', 'sprint')) . "' "
                . "style='display:flex;align-items:center;gap:6px;cursor:pointer;'>"
                . "<span style='display:inline-block;width:14px;height:3px;background:" . htmlescape($m['color']) . ";border-radius:2px;'></span>"
                . "<span>" . htmlescape($m['name']) . " <span class='text-muted'>(" . (int)$m['total'] . ")</span></span>"
                . "</div>";
        }
        echo "</div>";

        echo "</div>"; // .sprint-member-activity
        echo "</div>"; // .sprint-collapsible-body
        echo "</div>"; // .sprint-collapsible
    }

    /**
     * Build a human-friendly explanation for why the team activity chart
     * has nothing to plot. Helps users distinguish "feature missing on this
     * sprint" (which it isn't — the chart is now always rendered) from
     * legitimate empty states (no members, sprint already past the
     * retention window, no logged actions yet).
     */
    private static function diagnoseActivityEmptyReason(int $sprintId, array $dates, array $members): string
    {
        $sprint = new Sprint();
        if (!$sprint->getFromDB($sprintId)) {
            return __('No activity to display yet.', 'sprint');
        }

        $hasMembers = countElementsInTable(
            SprintMember::getTable(),
            ['plugin_sprint_sprints_id' => $sprintId]
        ) > 0;
        if (!$hasMembers) {
            return __('Add team members to start tracking team activity.', 'sprint');
        }

        if (count($dates) === 0) {
            // Either the sprint hasn't started, or it ended outside the
            // audit retention window so there's no data left to chart.
            $endTs = !empty($sprint->fields['date_end'])
                ? strtotime((string)$sprint->fields['date_end'])
                : 0;
            $cutoff = time() - (SprintAudit::RETENTION_DAYS * 86400);
            if ($endTs > 0 && $endTs < $cutoff) {
                return sprintf(
                    __('This sprint ended more than %d days ago — activity data is outside the retention window.', 'sprint'),
                    SprintAudit::RETENTION_DAYS
                );
            }
            $startTs = !empty($sprint->fields['date_start'])
                ? strtotime((string)$sprint->fields['date_start'])
                : 0;
            if ($startTs > time()) {
                return __('Sprint has not started yet — activity will appear here once team members make changes.', 'sprint');
            }
            return __('No tracked activity yet for this sprint.', 'sprint');
        }

        // Dates exist, but no member crossed the threshold of having any
        // logged action in range.
        return __('No tracked activity yet for this sprint — make a change in any sprint item, member or meeting and it will show up here.', 'sprint');
    }
}
