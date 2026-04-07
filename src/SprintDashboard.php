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
        self::renderDashboardContent($globalStats, $allItems, __('No items in this sprint yet', 'sprint'));
        self::showMemberCapacity($ID);
        echo "</div>";

        // === Personal view (hidden by default) ===
        echo "<div id='sprint_view_personal' style='display:none;'>";
        self::renderDashboardContent($personalStats, $personalItems, __('No items assigned to you', 'sprint'));
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
     * Render stats cards, progress bar, and items table
     */
    private static function renderDashboardContent(array $stats, array $items, string $emptyMessage): void
    {
        // === Stats cards ===
        echo "<div style='display:flex;flex-wrap:wrap;gap:14px;padding:16px 0 20px;justify-content:center;'>";

        $cards = [
            ['label' => __('Total Items', 'sprint'),  'value' => $stats['total_items'],  'icon' => 'fas fa-list-ul',       'color' => '#6c757d', 'bg' => '#f8f9fa', 'accent' => '#6c757d'],
            ['label' => __('Done', 'sprint'),          'value' => $stats['done_items'],   'icon' => 'fas fa-check-circle',   'color' => '#198754', 'bg' => '#d1e7dd', 'accent' => '#198754'],
            ['label' => __('In Progress', 'sprint'),   'value' => $stats['in_progress'],  'icon' => 'fas fa-circle-notch',   'color' => '#0d6efd', 'bg' => '#cfe2ff', 'accent' => '#0d6efd'],
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
        $blockedPct  = round(($stats['blocked_items'] / $total) * 100, 1);
        $todoPct     = round(($stats['todo_items'] / $total) * 100, 1);
        $reviewPct   = max(100 - $donePct - $progressPct - $blockedPct - $todoPct, 0);

        echo "<div style='margin:0 0 24px;'>";
        echo "<div style='display:flex;gap:18px;justify-content:center;margin-bottom:8px;font-size:0.82em;color:#6c757d;flex-wrap:wrap;'>";
        echo "<span><span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:#198754;margin-right:4px;vertical-align:middle;'></span>" . __('Done', 'sprint') . " {$donePct}%</span>";
        echo "<span><span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:#0d6efd;margin-right:4px;vertical-align:middle;'></span>" . __('In Progress', 'sprint') . " {$progressPct}%</span>";
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

        // === Items table ===
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Type') . "</th>";
        echo "<th>" . __('Name') . "</th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th>" . __('Status') . "</th>";
        echo "<th>" . __('Priority') . "</th>";
        echo "<th>" . __('Owner', 'sprint') . "</th>";
        echo "</tr>";

        if (count($items) === 0) {
            echo "<tr class='tab_bg_1'><td colspan='6' style='padding:32px;color:#adb5bd;text-align:center;'>" .
                "<i class='fas fa-inbox' style='font-size:2.2em;display:block;margin-bottom:10px;opacity:0.5;'></i>" .
                $emptyMessage . "</td></tr>";
        }

        foreach ($items as $row) {
            $linkedDisplay = !empty($row['linked_url'])
                ? "<a href='{$row['linked_url']}'><i class='{$row['icon']}' style='color:{$row['color']};margin-right:5px;'></i>" .
                  htmlescape($row['linked_name']) . "</a>"
                : "<span style='color:#ccc;'>-</span>";

            echo "<tr class='tab_bg_1'>";
            echo "<td style='white-space:nowrap;'><i class='{$row['icon']}' style='color:{$row['color']};margin-right:5px;opacity:0.85;'></i>{$row['type_label']}</td>";
            echo "<td><a href='{$row['url']}'>" . htmlescape($row['name']) . "</a></td>";
            echo "<td>{$linkedDisplay}</td>";
            echo "<td>{$row['status']}</td>";
            echo "<td>{$row['priority']}</td>";
            echo "<td>{$row['member']}</td>";
            echo "</tr>";
        }

        echo "</table>";
    }

    /**
     * Show member capacity overview
     */
    private static function showMemberCapacity(int $sprintId): void
    {
        $member  = new SprintMember();
        $members = $member->find(['plugin_sprint_sprints_id' => $sprintId], ['role ASC']);

        if (count($members) === 0) {
            return;
        }

        $si = new SprintItem();
        $allItems = $si->find(['plugin_sprint_sprints_id' => $sprintId]);
        $usedCapacity = [];
        foreach ($allItems as $row) {
            $uid = (int)$row['users_id'];
            if ($uid > 0) {
                $usedCapacity[$uid] = ($usedCapacity[$uid] ?? 0) + (int)($row['capacity'] ?? 0);
            }
        }

        $roles = SprintMember::getAllRoles();

        echo "<div style='margin-top:20px;'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'><th colspan='6'>" .
            "<i class='fas fa-users' style='margin-right:6px;'></i>" .
            __('Team Capacity', 'sprint') . "</th></tr>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Member', 'sprint') . "</th>";
        echo "<th>" . __('Role', 'sprint') . "</th>";
        echo "<th>" . __('Total', 'sprint') . "</th>";
        echo "<th>" . __('Used', 'sprint') . "</th>";
        echo "<th>" . __('Available', 'sprint') . "</th>";
        echo "<th>" . __('Capacity', 'sprint') . "</th>";
        echo "</tr>";

        foreach ($members as $row) {
            $uid       = (int)$row['users_id'];
            $total     = (int)$row['capacity_percent'];
            $used      = $usedCapacity[$uid] ?? 0;
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

            $usedWidth = ($total > 0) ? min(round(($used / $total) * 100), 100) : 0;

            echo "<tr class='tab_bg_1'>";
            echo "<td><i class='fas fa-user' style='margin-right:6px;opacity:0.6;'></i>" . getUserName($uid) . "</td>";
            echo "<td>" . $roleName . "</td>";
            echo "<td class='center'>{$total}%</td>";
            echo "<td class='center'>{$used}%</td>";
            echo "<td class='center' style='font-weight:700;color:{$remainColor};'>{$remaining}%{$overload}</td>";
            echo "<td style='min-width:180px;'>";
            echo "<div style='height:16px;background:#198754;border-radius:8px;overflow:hidden;display:flex;justify-content:flex-end;'>";
            if ($usedWidth > 0) {
                echo "<div style='width:{$usedWidth}%;height:100%;background:#dc3545;transition:width 0.3s;' title='" . __('Used', 'sprint') . " {$used}%'></div>";
            }
            echo "</div>";
            echo "</td>";
            echo "</tr>";
        }

        echo "</table></div>";
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
        foreach ($si->find(['plugin_sprint_sprints_id' => $sprintId], ['sort_order ASC', 'priority DESC']) as $row) {
            $itemtype = $row['itemtype'] ?? '';
            $itemsId  = (int)($row['items_id'] ?? 0);
            $typeInfo = $typeIcons[$itemtype] ?? $typeIcons[''];

            $linkedName = '';
            $linkedUrl  = '';
            $allowedTypes = ['Ticket', 'Change', 'ProjectTask'];
            if (!empty($itemtype) && $itemsId > 0 && in_array($itemtype, $allowedTypes, true) && class_exists($itemtype)) {
                $linked = new $itemtype();
                if ($linked->getFromDB($itemsId)) {
                    $linkedName = $linked->fields['name'];
                    $linkedUrl  = $itemtype::getFormURLWithID($itemsId);
                }
            }

            $statusClass = 'sprint-status-' . str_replace('_', '-', $row['status']);

            $items[] = [
                'type_label'   => $typeInfo[2],
                'icon'         => $typeInfo[0],
                'color'        => $typeInfo[1],
                'name'         => $row['name'],
                'url'          => SprintItem::getFormURLWithID($row['id']),
                'linked_name'  => $linkedName,
                'linked_url'   => $linkedUrl,
                'raw_status'   => $row['status'],
                'story_points' => (int)$row['story_points'],
                'users_id'     => (int)$row['users_id'],
                'status'       => '<span class="sprint-badge ' . $statusClass . '" style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:0.8em;font-weight:600;color:#fff;">' .
                                  ($statuses[$row['status']] ?? $row['status']) . '</span>',
                'priority'     => $priorities[$row['priority']] ?? '',
                'member'       => self::getMemberName((int)$row['users_id']),
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
        $allItems = $si->find([
            'plugin_sprint_sprints_id' => $sprintId,
            'users_id'                 => $userId,
        ]);
        $usedCapacity = 0;
        foreach ($allItems as $row) {
            $usedCapacity += (int)($row['capacity'] ?? 0);
        }

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

        $usedWidth = ($total > 0) ? min(round(($usedCapacity / $total) * 100), 100) : 0;

        echo "<div style='margin-top:20px;'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'><th colspan='6'>" .
            "<i class='fas fa-user' style='margin-right:6px;'></i>" .
            __('Your Capacity', 'sprint') . "</th></tr>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Member', 'sprint') . "</th>";
        echo "<th>" . __('Role', 'sprint') . "</th>";
        echo "<th>" . __('Total', 'sprint') . "</th>";
        echo "<th>" . __('Used', 'sprint') . "</th>";
        echo "<th>" . __('Available', 'sprint') . "</th>";
        echo "<th>" . __('Capacity', 'sprint') . "</th>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><i class='fas fa-user' style='margin-right:6px;opacity:0.6;'></i>" . getUserName($userId) . "</td>";
        echo "<td>" . $roleName . "</td>";
        echo "<td class='center'>{$total}%</td>";
        echo "<td class='center'>{$usedCapacity}%</td>";
        echo "<td class='center' style='font-weight:700;color:{$remainColor};'>{$remaining}%{$overload}</td>";
        echo "<td style='min-width:180px;'>";
        echo "<div style='height:16px;background:#198754;border-radius:8px;overflow:hidden;display:flex;justify-content:flex-end;'>";
        if ($usedWidth > 0) {
            echo "<div style='width:{$usedWidth}%;height:100%;background:#dc3545;transition:width 0.3s;' title='" . __('Used', 'sprint') . " {$usedCapacity}%'></div>";
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
}
