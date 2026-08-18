<?php

namespace GlpiPlugin\Sprint;

use CommonGLPI;
use Html;
use Session;

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

        Sprint::renderHeaderBar($sprint);

        // Collect all items once
        $allItems      = self::getAllLinkedItems($ID);
        $personalItems = array_filter($allItems, function ($row) use ($currentUserId) {
            return (int)$row['users_id'] === $currentUserId;
        });

        $globalStats   = self::computeStats($allItems);
        $personalStats = self::computeStats($personalItems);

        // The personal dependency card must also count items where the current
        // user is helper on an open dependency — being assigned by someone
        // else counts too, not only owning an item in dependency status.
        $helperDepIds = [];
        foreach (SprintItemDependency::getOpenItemsForHelper($ID, $currentUserId) as $dep) {
            $helperDepIds[(int)$dep['item_id']] = true;
        }
        foreach ($personalItems as $row) {
            if ($row['raw_status'] === SprintItem::STATUS_DEPENDENCY) {
                unset($helperDepIds[(int)$row['item_id']]);
            }
        }
        $personalStats['dependency_items'] += count($helperDepIds);

        // === View toggle (compact segmented control, choice persisted) ===
        echo "<div style='display:flex;justify-content:center;padding:4px 0 2px;'>";
        echo "<div class='btn-group btn-group-sm' role='group' aria-label='" . __s('View', 'sprint') . "'>";
        echo "<button id='sprint_btn_global' class='btn btn-sm btn-primary' onclick='sprintToggleView(\"global\")'>" .
            "<i class='fas fa-globe' style='margin-right:5px;'></i>" . __('Global View', 'sprint') . "</button>";
        echo "<button id='sprint_btn_personal' class='btn btn-sm btn-outline-secondary' onclick='sprintToggleView(\"personal\")'>" .
            "<i class='fas fa-user' style='margin-right:5px;'></i>" . __('Personal View', 'sprint') . "</button>";
        echo "</div>";
        echo "</div>";

        // === Global view ===
        echo "<div id='sprint_view_global'>";
        self::renderStatsAndProgress($globalStats);
        self::showAtRiskWidget($sprint);
        self::showBurndownChart($sprint);
        self::showVelocityChart($sprint);
        self::showMemberActivityChart($ID);
        self::showFastlaneItems($ID, null, 'global');
        self::showDependencyItems($ID, null, 'global');
        self::renderItemsTable($allItems, __('No items in this sprint yet', 'sprint'), 'sprint-dashboard-items-global', $ID, 'global');
        self::showMemberCapacity($ID);
        echo "</div>";

        // === Personal view (hidden by default) ===
        echo "<div id='sprint_view_personal' style='display:none;'>";
        self::renderStatsAndProgress($personalStats);
        self::showFastlaneItems($ID, $currentUserId, 'personal');
        self::showDependencyItems($ID, $currentUserId, 'personal');
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
                gBtn.className = 'btn btn-sm btn-outline-secondary';
                pBtn.className = 'btn btn-sm btn-primary';
            } else {
                gDiv.style.display = '';
                pDiv.style.display = 'none';
                gBtn.className = 'btn btn-sm btn-primary';
                pBtn.className = 'btn btn-sm btn-outline-secondary';
            }
            try { localStorage.setItem('sprint-dashboard-view', view); } catch (e) {}
        }
        (function() {
            var saved = null;
            try { saved = localStorage.getItem('sprint-dashboard-view'); } catch (e) {}
            if (saved === 'personal') { sprintToggleView('personal'); }
        })();
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
            'total_items'      => count($items),
            'todo_items'       => 0,
            'in_progress'      => 0,
            'review_items'     => 0,
            'dependency_items' => 0,
            'done_items'       => 0,
            'blocked_items'    => 0,
            'total_points'     => 0,
            'done_points'      => 0,
            // Allocated capacity (%) per status — drives the progress bar so a
            // single heavy item weighs more than a trivial one. See {@see renderStatsAndProgress()}.
            'todo_cap'         => 0,
            'in_progress_cap'  => 0,
            'review_cap'       => 0,
            'dependency_cap'   => 0,
            'done_cap'         => 0,
            'blocked_cap'      => 0,
        ];

        foreach ($items as $row) {
            $stats['total_points'] += (int)$row['story_points'];
            // Min weight 1 per item: a zero-capacity (unestimated) item still
            // earns its own slice in the bar instead of vanishing, while items
            // with real capacity dominate proportionally.
            $capWeight = max((float)($row['capacity'] ?? 0), 1);
            switch ($row['raw_status']) {
                case SprintItem::STATUS_TODO:
                    $stats['todo_items']++;
                    $stats['todo_cap'] += $capWeight;
                    break;
                case SprintItem::STATUS_IN_PROGRESS:
                    $stats['in_progress']++;
                    $stats['in_progress_cap'] += $capWeight;
                    break;
                case SprintItem::STATUS_REVIEW:
                    $stats['review_items']++;
                    $stats['review_cap'] += $capWeight;
                    break;
                case SprintItem::STATUS_DEPENDENCY:
                    $stats['dependency_items']++;
                    $stats['dependency_cap'] += $capWeight;
                    break;
                case SprintItem::STATUS_DONE:
                    $stats['done_items']++;
                    $stats['done_points'] += (int)$row['story_points'];
                    $stats['done_cap'] += $capWeight;
                    break;
                case SprintItem::STATUS_BLOCKED:
                    $stats['blocked_items']++;
                    $stats['blocked_cap'] += $capWeight;
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
        echo "<div class='sprint-stat-cards'>";

        $cards = [
            ['label' => __('Total Items', 'sprint'),  'value' => $stats['total_items'],     'icon' => 'fas fa-list-ul',      'accent' => '#6c757d'],
            ['label' => __('Done', 'sprint'),          'value' => $stats['done_items'],      'icon' => 'fas fa-check-circle', 'accent' => '#198754'],
            ['label' => __('In Progress', 'sprint'),   'value' => $stats['in_progress'],     'icon' => 'fas fa-circle-notch', 'accent' => '#0d6efd'],
            ['label' => __('In Review', 'sprint'),     'value' => $stats['review_items'],    'icon' => 'fas fa-search',       'accent' => '#6f42c1'],
            ['label' => __('Dependency', 'sprint'),    'value' => $stats['dependency_items'],'icon' => 'fas fa-link',         'accent' => '#20c997'],
            ['label' => __('Blocked', 'sprint'),       'value' => $stats['blocked_items'],   'icon' => 'fas fa-hand-paper',   'accent' => '#dc3545'],
            ['label' => __('Story Points', 'sprint'),  'value' => $stats['done_points'] . ' / ' . $stats['total_points'], 'icon' => 'fas fa-star', 'accent' => '#d68a00'],
        ];

        foreach ($cards as $c) {
            echo "<div class='sprint-stat-card' style='--stat-accent:{$c['accent']};'>";
            echo "<div class='sprint-stat-icon'><i class='{$c['icon']}'></i></div>";
            echo "<div class='sprint-stat-text'>";
            echo "<div class='sprint-stat-value'>{$c['value']}</div>";
            echo "<div class='sprint-stat-label'>{$c['label']}</div>";
            echo "</div>";
            echo "</div>";
        }
        echo "</div>";

        // === Progress bar ===
        // Weighted by allocated capacity (%) so the bar reflects effort, not a
        // raw item headcount — a blocked item using 25% capacity dominates a 2%
        // one. Each item carries a minimum weight of 1 (see computeStats), so a
        // sprint with no capacities set degrades cleanly to an even item split.
        $weights = [
            'done'       => (float)($stats['done_cap'] ?? 0),
            'progress'   => (float)($stats['in_progress_cap'] ?? 0),
            'review'     => (float)($stats['review_cap'] ?? 0),
            'dependency' => (float)($stats['dependency_cap'] ?? 0),
            'blocked'    => (float)($stats['blocked_cap'] ?? 0),
            'todo'       => (float)($stats['todo_cap'] ?? 0),
        ];
        $total = max(array_sum($weights), 1);
        $donePct       = round(($weights['done'] / $total) * 100, 1);
        $progressPct   = round(($weights['progress'] / $total) * 100, 1);
        $reviewPct     = round(($weights['review'] / $total) * 100, 1);
        $dependencyPct = round(($weights['dependency'] / $total) * 100, 1);
        $blockedPct    = round(($weights['blocked'] / $total) * 100, 1);
        $todoPct       = round(($weights['todo'] / $total) * 100, 1);

        echo "<div style='margin:0 0 24px;'>";
        echo "<div style='display:flex;gap:18px;justify-content:center;margin-bottom:8px;font-size:0.82em;color:#6c757d;flex-wrap:wrap;'>";
        echo "<span><span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:#198754;margin-right:4px;vertical-align:middle;'></span>" . __('Done', 'sprint') . " {$donePct}%</span>";
        echo "<span><span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:#0d6efd;margin-right:4px;vertical-align:middle;'></span>" . __('In Progress', 'sprint') . " {$progressPct}%</span>";
        echo "<span><span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:#6f42c1;margin-right:4px;vertical-align:middle;'></span>" . __('In Review', 'sprint') . " {$reviewPct}%</span>";
        echo "<span><span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:#20c997;margin-right:4px;vertical-align:middle;'></span>" . __('Dependency', 'sprint') . " {$dependencyPct}%</span>";
        echo "<span><span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:#dc3545;margin-right:4px;vertical-align:middle;'></span>" . __('Blocked', 'sprint') . " {$blockedPct}%</span>";
        echo "<span><span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:#d5d8dc;margin-right:4px;vertical-align:middle;'></span>" . __('To Do', 'sprint') . " {$todoPct}%</span>";
        echo "</div>";
        // min-width keeps a tiny (e.g. single unestimated item) slice visible.
        $segStyle = "height:100%;transition:width 0.4s;min-width:6px;";
        echo "<div style='width:100%;height:20px;background:var(--tblr-border-color,#e9ecef);border-radius:10px;overflow:hidden;display:flex;'>";
        if ($donePct > 0)       echo "<div style='width:{$donePct}%;{$segStyle}background:#198754;' title='" . __('Done', 'sprint') . "'></div>";
        if ($progressPct > 0)   echo "<div style='width:{$progressPct}%;{$segStyle}background:#0d6efd;' title='" . __('In Progress', 'sprint') . "'></div>";
        if ($reviewPct > 0)     echo "<div style='width:{$reviewPct}%;{$segStyle}background:#6f42c1;' title='" . __('In Review', 'sprint') . "'></div>";
        if ($dependencyPct > 0) echo "<div style='width:{$dependencyPct}%;{$segStyle}background:#20c997;' title='" . __('Dependency', 'sprint') . "'></div>";
        if ($blockedPct > 0)    echo "<div style='width:{$blockedPct}%;{$segStyle}background:#dc3545;' title='" . __('Blocked', 'sprint') . "'></div>";
        if ($todoPct > 0)       echo "<div style='width:{$todoPct}%;{$segStyle}background:#d5d8dc;' title='" . __('To Do', 'sprint') . "'></div>";
        echo "</div>";
        echo "</div>";
    }

    /**
     * Render items table
     *
     * @param array  $items
     * @param string $emptyMessage
     * @param string $tableSelector  CSS class scoping filter/sort JS so global & personal views stay independent.
     */
    private static function renderItemsTable(array $items, string $emptyMessage, string $tableSelector = 'sprint-dashboard-items', int $sprintId = 0, string $viewKey = 'global'): void
    {
        $statuses   = SprintItem::getAllStatuses();
        $priorities = [
            1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
            4 => __('High'), 5 => __('Very high'),
        ];

        // Collapse state persisted per sprint + view (global/personal independent).
        $collapseKey = 'dash-items-' . (int)$sprintId . '-' . $viewKey;
        echo "<div class='sprint-collapsible' data-sprint-collapse-key='" . htmlescape($collapseKey) . "'>";
        echo "<div class='sprint-collapsible-header'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron'></i>";
        echo "<i class='fas fa-list-ul' style='margin-left:2px;'></i>";
        echo "<span>" . __('Sprint items', 'sprint') . " <span class='text-muted' style='font-weight:400;'>(" . count($items) . ")</span></span>";
        echo "</div>";
        echo "<div class='sprint-collapsible-body'>";

        // The personal view only ever lists the current user's items, so the
        // owner column and owner filter are redundant there.
        $showOwner = $viewKey !== 'personal';

        // Shared filter bar (window.SprintFilter wires the JS).
        SprintItem::renderFilterBar($tableSelector, [
            'statuses' => $statuses,
            'owners'   => ($showOwner && $sprintId > 0) ? SprintMember::getSprintMemberOptions($sprintId) : [],
            'tags'     => Config::getDefinedTags(),
        ]);

        $allItemIds = array_map(fn($r) => (int)$r['item_id'], $items);
        $tagsById = SprintItem::getTagsForItems($allItemIds);
        $depsById = SprintItemDependency::getOpenSummariesForItems($allItemIds);

        echo "<table class='tab_cadre_fixe sprint-themed {$tableSelector} sprint-dashboard-table'>";
        echo "<tr class='tab_bg_2'>";
        $sc = SprintItem::sortClickAttr($tableSelector);
        echo "<th class='sprint-sortable center' data-sort-type='type' style='cursor:pointer;width:60px;' {$sc}>" . __('Type') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='name' style='cursor:pointer;' {$sc}>" . __('Name') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th class='sprint-sortable' data-sort-type='status' style='cursor:pointer;' {$sc}>" . __('Status') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='priority' style='cursor:pointer;' {$sc}>" . __('Priority') . " <i class='fas fa-sort text-muted'></i></th>";
        if ($showOwner) {
            echo "<th class='sprint-sortable' data-sort-type='owner' style='cursor:pointer;' {$sc}>" . __('Owner', 'sprint') . " <i class='fas fa-sort text-muted'></i></th>";
        }
        echo "<th style='width:60px;'></th>";
        echo "</tr>";

        if (count($items) === 0) {
            $emptyCols = $showOwner ? 7 : 6;
            echo "<tr class='tab_bg_1'><td colspan='{$emptyCols}' style='padding:32px;color:#adb5bd;text-align:center;'>" .
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

            // Expose data-* attrs on the row so quick-edit + filter/sort JS read them without traversing cells.
            $rowTags = $tagsById[(int)$row['item_id']] ?? [];

            $isAdhoc = (int)($row['is_adhoc'] ?? 0) === 1;
            $dataAttrs = 'class="tab_bg_1 sprint-row sprint-dashboard-row sprint-filterable-row' . ($isAdhoc ? ' sprint-adhoc' : '') . '"'
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
                . ' data-capacity="' . SprintMember::formatCapacity($row['capacity'] ?? 0) . '"'
                . ' data-capacity-actual="' . SprintItem::formatActualCapacity($row['capacity_actual'] ?? null) . '"'
                . ' data-is-fastlane="0"'
                . ' data-is-adhoc="' . ($isAdhoc ? 1 : 0) . '"'
                . ' data-item-tags="' . htmlescape(SprintItem::tagsToBlob($rowTags)) . '"'
                . ' data-note="' . htmlescape((string)($row['note'] ?? '')) . '"'
                . ' data-done-checks="' . htmlescape(implode('|', SprintAgility::checklist((string)($row['done_checks'] ?? '')))) . '"'
                . ' data-epic-id="' . (int)($row['epic_id'] ?? 0) . '"';

            $rowDeps = $depsById[(int)$row['item_id']] ?? [];
            $linkedOpenBadge = SprintItem::renderLinkedItemOpenBadge([
                'status'   => $row['raw_status'] ?? '',
                'itemtype' => $row['itemtype'] ?? ($row['itemtype_code'] ?? ''),
                'items_id' => (int)($row['items_id'] ?? 0),
            ]);
            echo "<tr {$dataAttrs}>";
            // Icon only: the "Linked item" column already spells the type out.
            echo "<td class='center' style='white-space:nowrap;'><i class='{$row['icon']}' style='color:{$row['color']};opacity:0.85;' title='"
                . htmlescape((string)$row['type_label']) . "'></i></td>";
            $projectSuffix = SprintItem::renderParentProjectSuffix(
                (string)($row['itemtype'] ?? ($row['itemtype_code'] ?? '')),
                (int)($row['items_id'] ?? 0)
            );
            $capacityChip = SprintItem::renderCapacityChip($row['capacity'] ?? 0);
            echo "<td class='sprint-cell-name'><a href='{$row['url']}'>" . htmlescape($row['name']) . "</a>" . $projectSuffix . SprintItem::renderAdhocBadge($isAdhoc) . SprintItem::renderTagPills($rowTags) . SprintItem::renderDependencyBadge($rowDeps) . $linkedOpenBadge . $capacityChip . "</td>";
            echo "<td>{$linkedDisplay}</td>";
            echo "<td class='sprint-cell-status'>{$row['status']}</td>";
            echo "<td class='sprint-cell-priority'>{$row['priority']}</td>";
            if ($showOwner) {
                echo "<td class='sprint-cell-owner'>{$row['member']}</td>";
            }
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
     * Member capacity overview, split Regular vs Fastlane vs Dependency.
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
                $regularUsed[$uid] = ($regularUsed[$uid] ?? 0) + (float)($row['capacity'] ?? 0);
            }
        }

        $fastlaneUsed   = [];
        $dependencyUsed = [];
        foreach ($members as $row) {
            $uid = (int)$row['users_id'];
            $fastlaneUsed[$uid]   = SprintFastlaneMember::getUsedFastlaneCapacityForUser($sprintId, $uid);
            $dependencyUsed[$uid] = SprintItemDependency::getUsedDependencyCapacityForUser($sprintId, $uid);
        }

        $roles = SprintMember::getAllRoles();
        $sprintFastlaneTotal   = SprintFastlaneMember::getTotalFastlaneCapacityForSprint($sprintId);
        $sprintDependencyTotal = SprintItemDependency::getTotalOpenDependencyCapacityForSprint($sprintId);

        echo "<div style='margin-top:20px;'>";
        echo "<table class='tab_cadre_fixe sprint-themed'>";
        echo "<tr class='tab_bg_2'><th colspan='9'>" .
            "<i class='fas fa-users' style='margin-right:6px;'></i>" .
            __('Team Capacity', 'sprint') .
            " &mdash; <i class='fas fa-bolt' style='color:#fd7e14;'></i> " .
            sprintf(__('Fastlane total: %s%%', 'sprint'), SprintMember::formatCapacity($sprintFastlaneTotal)) .
            " &mdash; <i class='fas fa-link' style='color:#20c997;'></i> " .
            sprintf(__('Dependency total: %s%%', 'sprint'), SprintMember::formatCapacity($sprintDependencyTotal)) .
            "</th></tr>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Member', 'sprint') . "</th>";
        echo "<th>" . __('Role', 'sprint') . "</th>";
        echo "<th>" . __('Total', 'sprint') . "</th>";
        echo "<th>" . __('Regular', 'sprint') . "</th>";
        echo "<th><i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;'></i>" . __('Fastlane', 'sprint') . "</th>";
        echo "<th><i class='fas fa-link' style='color:#20c997;margin-right:4px;'></i>" . __('Dependencies', 'sprint') . "</th>";
        echo "<th>" . __('Used', 'sprint') . "</th>";
        echo "<th>" . __('Available', 'sprint') . "</th>";
        echo "<th>" . __('Capacity', 'sprint') . "</th>";
        echo "</tr>";

        foreach ($members as $row) {
            $uid        = (int)$row['users_id'];
            // Effective capacity (availability exceptions applied), matching the team dashboard cards.
            $baseCap    = SprintMember::normalizeCapacity($row['capacity_percent']);
            $total      = SprintAgility::effectiveCapacity($sprintId, $uid, $baseCap);
            $regular    = $regularUsed[$uid] ?? 0;
            $fastlane   = $fastlaneUsed[$uid] ?? 0;
            $dependency = $dependencyUsed[$uid] ?? 0;
            $used       = $regular + $fastlane + $dependency;
            $remaining  = max($total - $used, 0);
            $pctUsed    = ($total > 0) ? round(($used / $total) * 100) : 0;
            $roleName   = $roles[$row['role']] ?? $row['role'];

            $remainColor = '#198754';
            if ($remaining <= 0) {
                $remainColor = '#dc3545';
            } elseif ($pctUsed >= 80) {
                $remainColor = '#e67e22';
            }

            // Show the overflow amount instead of clamping "Available" to 0%.
            $overflow = max($used - $total, 0);
            if ($overflow > 0) {
                $availableDisplay = '-' . SprintMember::formatCapacity($overflow) . '% <span style="font-weight:600;">('
                    . sprintf(__('%s%% overflow', 'sprint'), SprintMember::formatCapacity($overflow)) . ')</span>';
            } else {
                $availableDisplay = SprintMember::formatCapacity($remaining) . '%';
            }

            $totalLabel      = SprintMember::formatCapacity($total);
            $regularLabel    = SprintMember::formatCapacity($regular);
            $fastlaneLabel   = SprintMember::formatCapacity($fastlane);
            $dependencyLabel = SprintMember::formatCapacity($dependency);
            $usedLabel       = SprintMember::formatCapacity($used);

            echo "<tr class='tab_bg_1'>";
            echo "<td><i class='fas fa-user' style='margin-right:6px;opacity:0.6;'></i>" . htmlescape(SprintCache::userName($uid)) . "</td>";
            echo "<td>" . $roleName . "</td>";
            echo "<td class='center'>{$totalLabel}%</td>";
            echo "<td class='center'>{$regularLabel}%</td>";
            echo "<td class='center'>" . ($fastlane > 0
                ? "<strong style='color:#fd7e14;'>{$fastlaneLabel}%</strong>"
                : "{$fastlaneLabel}%") . "</td>";
            echo "<td class='center'>" . ($dependency > 0
                ? "<strong style='color:#20c997;'>{$dependencyLabel}%</strong>"
                : "{$dependencyLabel}%") . "</td>";
            echo "<td class='center'>{$usedLabel}%</td>";
            echo "<td class='center' style='font-weight:700;color:{$remainColor};'>{$availableDisplay}</td>";
            echo "<td style='min-width:180px;'>";
            echo SprintMember::renderCapacityBar($total, $regular, $fastlane, $dependency, 16, '8px', $baseCap);
            echo "</td>";
            echo "</tr>";
        }

        echo "</table></div>";
    }

    /**
     * Render the dashboard fastlane items table. $forUserId restricts to items the user is a fastlane member of.
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
            SprintItem::STATUS_DEPENDENCY  => '#20c997',
            SprintItem::STATUS_DONE        => '#198754',
            SprintItem::STATUS_BLOCKED     => '#dc3545',
        ];

        // Pre-load fastlane member rows + tags + dep summaries in one batch each
        $rel       = new SprintFastlaneMember();
        $itemIds   = array_map(fn($r) => (int)$r['id'], $items);
        $relRowsByItem = [];
        $tagsById      = [];
        $depsById      = [];
        if (count($itemIds) > 0) {
            $allRels = $rel->find(['plugin_sprint_sprintitems_id' => $itemIds]);
            foreach ($allRels as $r) {
                $relRowsByItem[(int)$r['plugin_sprint_sprintitems_id']][] = $r;
            }
            $tagsById = SprintItem::getTagsForItems($itemIds);
            $depsById = SprintItemDependency::getOpenSummariesForItems($itemIds);
        }

        $sprintFastlaneTotal = SprintFastlaneMember::getTotalFastlaneCapacityForSprint($sprintId);

        // Optional per-sprint hard cap: display total vs. cap plus the overflow.
        $fastlaneCap = 0.0;
        $sprintObj   = new Sprint();
        if ($sprintObj->getFromDB($sprintId)) {
            $fastlaneCap = (float)($sprintObj->fields['fastlane_capacity'] ?? 0);
        }

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
        if ($forUserId !== null) {
            // Personal view: only the capacity allocated to this user — the
            // sprint-wide total belongs on the global view.
            $ownFastlaneTotal = 0.0;
            foreach ($relRowsByItem as $relRows) {
                foreach ($relRows as $r) {
                    if ((int)$r['users_id'] === $forUserId) {
                        $ownFastlaneTotal += (float)$r['capacity'];
                    }
                }
            }
            $capText = sprintf(__('Your capacity: %s%%', 'sprint'), SprintMember::formatCapacity($ownFastlaneTotal));
        } else {
            $capText = ($fastlaneCap > 0)
                ? sprintf(__('Total capacity: %1$s%% / %2$s%%', 'sprint'), SprintMember::formatCapacity($sprintFastlaneTotal), SprintMember::formatCapacity($fastlaneCap))
                : sprintf(__('Total capacity: %s%%', 'sprint'), SprintMember::formatCapacity($sprintFastlaneTotal));
        }
        echo "<span>" . __('Fastlane', 'sprint') .
            " <span class='text-muted' style='font-weight:400;'>(" . $visibleCount . ")</span>" .
            " &mdash; " . $capText;
        if ($forUserId === null && $fastlaneCap > 0 && $sprintFastlaneTotal > $fastlaneCap) {
            echo " <span class='badge bg-danger' style='margin-left:4px;'>"
                . sprintf(__('+%s%% overflow', 'sprint'), SprintMember::formatCapacity($sprintFastlaneTotal - $fastlaneCap))
                . "</span>";
        }
        echo "</span>";
        echo "</div>";
        echo "<div class='sprint-collapsible-body'>";

        echo "<table class='tab_cadre_fixe sprint-themed'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Name') . "</th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th>" . __('Status') . "</th>";
        echo "<th>" . __('Members', 'sprint') . "</th>";
        echo "<th>" . __('Total', 'sprint') . "</th>";
        echo "<th style='width:52px;'></th>";
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

            $totalCap = 0.0;
            $memberLines = [];
            foreach ($relRows as $r) {
                $cap = (float)$r['capacity'];
                $totalCap += $cap;
                $memberLines[] = htmlescape(SprintCache::userName((int)$r['users_id'])) . " (" . SprintMember::formatCapacity($cap) . "%)";
            }

            $linkedDisplay = '<span style="color:#ccc;">-</span>';
            if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
                $tmp = new SprintItem();
                $tmp->fields = $row;
                $linkedDisplay = $tmp->getLinkedItemDisplay();
            }

            $statusBg    = $statusBgColors[$row['status']] ?? '#6c757d';
            $statusLabel = $statuses[$row['status']] ?? $row['status'];

            $rowTags = $tagsById[$itemId] ?? [];
            $rowDeps = $depsById[$itemId] ?? [];

            echo "<tr class='tab_bg_1' " . SprintItem::buildItemDataAttrs($row, $rowTags) . ">";
            echo "<td><a href='" . SprintItem::getFormURLWithID($itemId) . "'>" .
                "<i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;'></i>" .
                htmlescape($row['name']) . "</a>" . SprintItem::renderTagPills($rowTags) . SprintItem::renderDependencyBadge($rowDeps) . "</td>";
            echo "<td>{$linkedDisplay}</td>";
            echo "<td><span class='sprint-badge' style='display:inline-block;padding:4px 12px;border-radius:20px;font-size:0.8em;font-weight:600;color:#fff;background-color:{$statusBg};'>" .
                $statusLabel . "</span></td>";
            echo "<td>" . (count($memberLines) > 0 ? implode('<br>', $memberLines) :
                "<span style='color:#999;'>" . __('None', 'sprint') . "</span>") . "</td>";
            echo "<td class='center'><strong>" . SprintMember::formatCapacity($totalCap) . "%</strong></td>";
            echo "<td class='text-center'>";
            echo "<button type='button' class='btn btn-sm btn-outline-secondary sprint-quick-edit-btn' "
                . "title='" . __('Quick edit', 'sprint') . "' data-item-id='{$itemId}'>"
                . "<i class='fas fa-pen'></i></button>";
            echo "</td>";
            echo "</tr>";
            $rendered++;
        }

        if ($rendered === 0) {
            echo "<tr class='tab_bg_1'><td colspan='6' class='center'>" .
                __('No fastlane items', 'sprint') . "</td></tr>";
        }

        echo "</table>";
        echo "</div>"; // .sprint-collapsible-body
        echo "</div>"; // .sprint-collapsible
    }

    /**
     * Personal view filters to items where $forUserId is the helper of an
     * open row.
     */
    private static function showDependencyItems(int $sprintId, ?int $forUserId = null, string $viewKey = 'global'): void
    {
        if (!SprintItemDependency::isTableReady()) {
            return;
        }
        $si          = new SprintItem();
        $sprintItems = [];
        foreach ($si->find(['plugin_sprint_sprints_id' => $sprintId]) as $row) {
            $sprintItems[(int)$row['id']] = $row;
        }
        if (count($sprintItems) === 0) {
            return;
        }

        // Scoped to this sprint's items — find([]) pulled the whole table.
        $depRel  = new SprintItemDependency();
        $allRels = $depRel->find(
            ['plugin_sprint_sprintitems_id' => array_keys($sprintItems)],
            ['date_creation DESC']
        );

        $relRowsByItem = [];
        foreach ($allRels as $r) {
            $relRowsByItem[(int)$r['plugin_sprint_sprintitems_id']][] = $r;
        }
        if (count($relRowsByItem) === 0) {
            return;
        }

        $statuses = SprintItem::getAllStatuses();
        $statusBgColors = [
            SprintItem::STATUS_TODO        => '#6c757d',
            SprintItem::STATUS_IN_PROGRESS => '#0d6efd',
            SprintItem::STATUS_REVIEW      => '#6f42c1',
            SprintItem::STATUS_DEPENDENCY  => '#20c997',
            SprintItem::STATUS_DONE        => '#198754',
            SprintItem::STATUS_BLOCKED     => '#dc3545',
        ];

        $sprintDependencyTotal = SprintItemDependency::getTotalOpenDependencyCapacityForSprint($sprintId);

        // Personal view keeps resolved rows visible with a badge + quick-resolve.
        $visibleCount = 0;
        foreach ($relRowsByItem as $itemId => $relRows) {
            if ($forUserId !== null) {
                $hasUser = false;
                foreach ($relRows as $r) {
                    if ((int)$r['users_id'] === $forUserId) {
                        $hasUser = true;
                        break;
                    }
                }
                if (!$hasUser) { continue; }
            }
            $visibleCount++;
        }
        if ($visibleCount === 0) {
            return;
        }

        // Personal view: only the open capacity coupled to this user — the
        // sprint-wide open total reads as if it were all yours otherwise.
        if ($forUserId !== null) {
            $ownOpenTotal = 0.0;
            foreach ($relRowsByItem as $relRows) {
                foreach ($relRows as $r) {
                    if ((int)$r['users_id'] === $forUserId
                        && (int)($r['is_resolved'] ?? 0) === 0) {
                        $ownOpenTotal += (float)$r['capacity'];
                    }
                }
            }
            $openCapText = sprintf(__('Your open capacity: %s%%', 'sprint'), SprintMember::formatCapacity($ownOpenTotal));
        } else {
            $openCapText = sprintf(__('Open capacity: %s%%', 'sprint'), SprintMember::formatCapacity($sprintDependencyTotal));
        }

        $collapseKey = 'dash-dependencies-' . (int)$sprintId . '-' . $viewKey;
        echo "<div class='sprint-collapsible' data-sprint-collapse-key='" . htmlescape($collapseKey) . "'>";
        echo "<div class='sprint-collapsible-header'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron'></i>";
        echo "<i class='fas fa-link' style='color:#20c997;margin-left:2px;'></i>";
        echo "<span>" . __('Dependencies', 'sprint') .
            " <span class='text-muted' style='font-weight:400;'>(" . $visibleCount . ")</span>" .
            " &mdash; " . $openCapText .
            "</span>";
        echo "</div>";
        echo "<div class='sprint-collapsible-body'>";

        echo "<table class='tab_cadre_fixe sprint-themed'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Name') . "</th>";
        echo "<th>" . __('Owner', 'sprint') . "</th>";
        echo "<th>" . __('Status') . "</th>";
        echo "<th>" . __('Helpers (open)', 'sprint') . "</th>";
        echo "<th>" . __('Open total', 'sprint') . "</th>";
        if ($forUserId !== null) {
            echo "<th>" . __('Your dependency', 'sprint') . "</th>";
        }
        echo "</tr>";

        foreach ($relRowsByItem as $itemId => $relRows) {
            $ownRow = null;
            if ($forUserId !== null) {
                foreach ($relRows as $r) {
                    if ((int)$r['users_id'] === $forUserId) {
                        $ownRow = $r;
                        break;
                    }
                }
                if ($ownRow === null) {
                    continue;
                }
            }

            $row = $sprintItems[$itemId];

            $openCap     = 0.0;
            $helperLines = [];
            foreach ($relRows as $r) {
                $resolved = (int)($r['is_resolved'] ?? 0) === 1;
                $cap      = (float)$r['capacity'];
                $capLabel = SprintMember::formatCapacity($cap);
                $name     = htmlescape(SprintCache::userName((int)$r['users_id']));
                if ($resolved) {
                    $helperLines[] = "<span class='text-muted' style='text-decoration:line-through;'>{$name} ({$capLabel}%)</span>";
                } else {
                    $openCap      += $cap;
                    $helperLines[] = "{$name} ({$capLabel}%)";
                }
            }

            $statusBg    = $statusBgColors[$row['status']] ?? '#6c757d';
            $statusLabel = $statuses[$row['status']] ?? $row['status'];

            $ownerName = ((int)$row['users_id'] > 0)
                ? htmlescape(SprintCache::userName((int)$row['users_id']))
                : '<span style="color:#adb5bd;font-style:italic;">' . __('Unassigned', 'sprint') . '</span>';

            echo "<tr class='tab_bg_1'>";
            echo "<td><a href='" . SprintItem::getFormURLWithID($itemId) . "'>" .
                "<i class='fas fa-link' style='color:#20c997;margin-right:4px;'></i>" .
                htmlescape($row['name']) . "</a></td>";
            echo "<td>{$ownerName}</td>";
            echo "<td><span class='sprint-badge' style='display:inline-block;padding:4px 12px;border-radius:20px;font-size:0.8em;font-weight:600;color:#fff;background-color:{$statusBg};'>" .
                $statusLabel . "</span></td>";
            echo "<td>" . (count($helperLines) > 0 ? implode('<br>', $helperLines) :
                "<span style='color:#999;'>" . __('None', 'sprint') . "</span>") . "</td>";
            echo "<td class='center'><strong>" . SprintMember::formatCapacity($openCap) . "%</strong></td>";
            if ($forUserId !== null && $ownRow !== null) {
                $ownResolved = (int)($ownRow['is_resolved'] ?? 0) === 1;
                echo "<td class='center' style='white-space:nowrap;'>";
                if ($ownResolved) {
                    echo "<span class='sprint-badge' style='background:#198754;color:#fff;padding:2px 10px;border-radius:10px;font-size:0.78em;'>"
                        . "<i class='fas fa-check'></i> " . __('Resolved', 'sprint') . "</span>";
                } else {
                    echo "<span class='sprint-badge' style='background:#dc3545;color:#fff;padding:2px 10px;border-radius:10px;font-size:0.78em;'>"
                        . "<i class='fas fa-link'></i> " . __('Open', 'sprint') . "</span>";
                    echo "<form method='post' action='" . SprintItemDependency::getFormURL() . "' style='display:inline;margin-left:6px;'>";
                    echo Html::hidden('id', ['value' => (int)$ownRow['id']]);
                    echo Html::submit(__('Resolve', 'sprint'), [
                        'name'  => 'resolve',
                        'class' => 'btn btn-sm btn-outline-success',
                    ]);
                    Html::closeForm();
                }
                echo "</td>";
            }
            echo "</tr>";
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
            'Problem'     => ['fas fa-exclamation-circle', '#dc3545', __('Problem')],
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

            // Delegate linked-item rendering so the dashboard stays consistent with the items tab and meeting review.
            $tmp = new SprintItem();
            $tmp->fields = $row;
            $linkedDisplayHtml = $tmp->getLinkedItemDisplay();

            $statusClass = 'sprint-status-' . str_replace('_', '-', $row['status']);

            // Inline bg color so badges stay readable when the plugin's CSS vars aren't resolved.
            $statusBgColors = [
                SprintItem::STATUS_TODO        => '#6c757d',
                SprintItem::STATUS_IN_PROGRESS => '#0d6efd',
                SprintItem::STATUS_REVIEW      => '#6f42c1',
                SprintItem::STATUS_DEPENDENCY  => '#20c997',
                SprintItem::STATUS_DONE        => '#198754',
                SprintItem::STATUS_BLOCKED     => '#dc3545',
            ];
            $statusBg = $statusBgColors[$row['status']] ?? '#6c757d';

            $items[] = [
                'item_id'        => (int)$row['id'],
                'itemtype_code'  => $itemtype,
                'itemtype'       => $itemtype,
                'items_id'       => $itemsId,
                'type_label'     => $typeInfo[2],
                'icon'           => $typeInfo[0],
                'color'          => $typeInfo[1],
                'name'           => $row['name'],
                'url'            => SprintItem::getFormURLWithID($row['id']),
                'linked_display' => $linkedDisplayHtml,
                'raw_status'    => $row['status'],
                'raw_priority'  => (int)($row['priority'] ?? 3),
                'story_points'  => (int)$row['story_points'],
                'capacity'      => (float)($row['capacity'] ?? 0),
                'capacity_actual' => $row['capacity_actual'] ?? null,
                'users_id'      => (int)$row['users_id'],
                'is_adhoc'      => (int)($row['is_adhoc'] ?? 0),
                'note'          => (string)($row['note'] ?? ''),
                'done_checks'   => (string)($row['done_checks'] ?? ''),
                'epic_id'       => (int)($row['plugin_sprint_sprintepics_id'] ?? 0),
                'member_name'   => ((int)$row['users_id'] > 0) ? SprintCache::userName((int)$row['users_id']) : '',
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
        $regularUsed = 0.0;
        foreach ($si->find([
            'plugin_sprint_sprints_id' => $sprintId,
            'users_id'                 => $userId,
            'is_fastlane'              => 0,
        ]) as $row) {
            $regularUsed += (float)($row['capacity'] ?? 0);
        }
        $fastlaneUsed   = SprintFastlaneMember::getUsedFastlaneCapacityForUser($sprintId, $userId);
        $dependencyUsed = SprintItemDependency::getUsedDependencyCapacityForUser($sprintId, $userId);
        $usedCapacity   = $regularUsed + $fastlaneUsed + $dependencyUsed;

        $roles = SprintMember::getAllRoles();
        $memberData = reset($members);
        // Effective capacity (availability exceptions applied), matching the team capacity table.
        $baseCap    = SprintMember::normalizeCapacity($memberData['capacity_percent']);
        $total      = SprintAgility::effectiveCapacity($sprintId, $userId, $baseCap);
        $remaining  = max($total - $usedCapacity, 0);
        $pctUsed    = ($total > 0) ? round(($usedCapacity / $total) * 100) : 0;
        $roleName   = $roles[$memberData['role']] ?? $memberData['role'];

        $remainColor = '#198754';
        if ($remaining <= 0) {
            $remainColor = '#dc3545';
        } elseif ($pctUsed >= 80) {
            $remainColor = '#e67e22';
        }

        // Show overflow (e.g. "-26%") instead of clamping "Available" to 0% — mirrors the member summary card.
        $overflow = max($usedCapacity - $total, 0);
        if ($overflow > 0) {
            $availableDisplay = '-' . SprintMember::formatCapacity($overflow) . '% <span style="font-weight:600;">('
                . sprintf(__('%s%% overflow', 'sprint'), SprintMember::formatCapacity($overflow)) . ')</span>';
        } else {
            $availableDisplay = SprintMember::formatCapacity($remaining) . '%';
        }

        echo "<div style='margin-top:20px;'>";
        echo "<table class='tab_cadre_fixe sprint-themed'>";
        echo "<tr class='tab_bg_2'><th colspan='9'>" .
            "<i class='fas fa-user' style='margin-right:6px;'></i>" .
            __('Your Capacity', 'sprint') . "</th></tr>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Member', 'sprint') . "</th>";
        echo "<th>" . __('Role', 'sprint') . "</th>";
        echo "<th>" . __('Total', 'sprint') . "</th>";
        echo "<th>" . __('Regular', 'sprint') . "</th>";
        echo "<th><i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;'></i>" . __('Fastlane', 'sprint') . "</th>";
        echo "<th><i class='fas fa-link' style='color:#20c997;margin-right:4px;'></i>" . __('Dependencies', 'sprint') . "</th>";
        echo "<th>" . __('Used', 'sprint') . "</th>";
        echo "<th>" . __('Available', 'sprint') . "</th>";
        echo "<th>" . __('Capacity', 'sprint') . "</th>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><i class='fas fa-user' style='margin-right:6px;opacity:0.6;'></i>" . htmlescape(SprintCache::userName($userId)) . "</td>";
        $totalLabel      = SprintMember::formatCapacity($total);
        $regularLabel    = SprintMember::formatCapacity($regularUsed);
        $fastlaneLabel   = SprintMember::formatCapacity($fastlaneUsed);
        $dependencyLabel = SprintMember::formatCapacity($dependencyUsed);
        $usedLabel       = SprintMember::formatCapacity($usedCapacity);
        echo "<td>" . $roleName . "</td>";
        echo "<td class='center'>{$totalLabel}%</td>";
        echo "<td class='center'>{$regularLabel}%</td>";
        echo "<td class='center'>" . ($fastlaneUsed > 0
            ? "<strong style='color:#fd7e14;'>{$fastlaneLabel}%</strong>"
            : "{$fastlaneLabel}%") . "</td>";
        echo "<td class='center'>" . ($dependencyUsed > 0
            ? "<strong style='color:#20c997;'>{$dependencyLabel}%</strong>"
            : "{$dependencyLabel}%") . "</td>";
        echo "<td class='center'>{$usedLabel}%</td>";
        echo "<td class='center' style='font-weight:700;color:{$remainColor};'>{$availableDisplay}</td>";
        echo "<td style='min-width:180px;'>";
        echo SprintMember::renderCapacityBar($total, $regularUsed, $fastlaneUsed, $dependencyUsed, 16, '8px', $baseCap);
        echo "</td>";
        echo "</tr>";

        echo "</table></div>";
    }

    private static function getMemberName(int $usersId): string
    {
        if ($usersId > 0) {
            return htmlescape(SprintCache::userName($usersId));
        }
        return '<span style="color:#adb5bd;font-style:italic;">' . __('Unassigned', 'sprint') . '</span>';
    }

    /**
     * "At-risk" widget: blocked items, Review/Done items whose linked ticket
     * is still open, and items with open cross-member dependencies.
     */
    private static function showAtRiskWidget(Sprint $sprint): void
    {
        $sprintId = (int)$sprint->getID();
        $items    = (new SprintItem())->find(['plugin_sprint_sprints_id' => $sprintId]);
        $depsById = SprintItemDependency::getOpenSummariesForItems(
            array_map(fn($r) => (int)$r['id'], $items)
        );

        $risks = [];
        foreach ($items as $row) {
            $id      = (int)$row['id'];
            $status  = (string)($row['status'] ?? '');
            $reasons = [];

            if ($status === SprintItem::STATUS_BLOCKED) {
                $reasons[] = ['#dc3545', __('Blocked', 'sprint')];
            }

            if (!empty($row['itemtype']) && (int)$row['items_id'] > 0
                && in_array($status, [SprintItem::STATUS_REVIEW, SprintItem::STATUS_DONE], true)) {
                $tmp = new SprintItem();
                $tmp->fields = $row;
                if (!$tmp->isLinkedItemClosed()) {
                    $reasons[] = ['#f0ad4e', __('Linked item open', 'sprint')];
                }
            }

            $openDeps = $depsById[$id] ?? [];
            if (count($openDeps) > 0) {
                $reasons[] = ['#20c997', sprintf(_n('%d open dependency', '%d open dependencies', count($openDeps), 'sprint'), count($openDeps))];
            }

            if (!empty($reasons)) {
                // The item waits on its dependency helpers, not its owner.
                $helperNames = [];
                foreach ($openDeps as $dep) {
                    $helperNames[] = ($dep['name'] ?: '?')
                        . ' (' . SprintMember::formatCapacity($dep['capacity']) . '%)';
                }

                $risks[] = [
                    'name'    => (string)$row['name'],
                    'url'     => SprintItem::getFormURLWithID($id),
                    'reasons' => $reasons,
                    'project' => SprintItem::getParentProjectName(
                        (string)($row['itemtype'] ?? ''),
                        (int)($row['items_id'] ?? 0)
                    ),
                    'owner'   => ((int)($row['users_id'] ?? 0) > 0)
                        ? SprintCache::userName((int)$row['users_id'])
                        : '',
                    'helpers' => $helperNames,
                ];
            }
        }

        $collapseKey = 'dash-atrisk-' . $sprintId;
        echo "<div class='sprint-collapsible' data-sprint-collapse-key='" . htmlescape($collapseKey) . "'>";
        echo "<div class='sprint-collapsible-header'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron'></i>";
        echo "<i class='fas fa-triangle-exclamation' style='margin-left:2px;color:" . (count($risks) > 0 ? '#dc3545' : '#198754') . ";'></i>";
        echo "<span>" . __('At risk', 'sprint') . " <span class='badge " . (count($risks) > 0 ? 'bg-danger' : 'bg-success') . "'>" . count($risks) . "</span></span>";
        echo "</div>";
        echo "<div class='sprint-collapsible-body'>";

        if (count($risks) === 0) {
            echo "<div style='padding:12px;color:#198754;'><i class='fas fa-check-circle' style='margin-right:6px;'></i>"
                . __('Nothing at risk right now.', 'sprint') . "</div>";
        } else {
            echo "<div class='sprint-atrisk-list'>";
            foreach ($risks as $r) {
                echo "<div class='sprint-atrisk-row'>";
                echo "<div class='sprint-atrisk-main'>";
                echo "<a href='" . $r['url'] . "' class='sprint-atrisk-name'>" . htmlescape($r['name']) . "</a>";
                if ($r['project'] !== '' || $r['owner'] !== '' || !empty($r['helpers'])) {
                    echo "<span class='sprint-atrisk-meta'>";
                    if ($r['project'] !== '') {
                        echo "<span class='sprint-atrisk-project'><i class='fas fa-diagram-project'></i> "
                            . htmlescape($r['project']) . "</span>";
                    }
                    if ($r['owner'] !== '') {
                        echo "<span class='sprint-atrisk-owner'><i class='fas fa-user'></i> "
                            . htmlescape($r['owner']) . "</span>";
                    }
                    if (!empty($r['helpers'])) {
                        echo "<span class='sprint-atrisk-owner' style='color:#20c997;' title='"
                            . htmlescape(__('Waiting on', 'sprint')) . "'>"
                            . "<i class='fas fa-link'></i> "
                            . htmlescape(__('Waiting on', 'sprint') . ': ' . implode(', ', $r['helpers']))
                            . "</span>";
                    }
                    echo "</span>";
                }
                echo "</div>";
                echo "<span class='sprint-atrisk-chips'>";
                foreach ($r['reasons'] as $rsn) {
                    echo "<span class='sprint-atrisk-chip' style='background:" . htmlescape($rsn[0]) . ";'>" . htmlescape($rsn[1]) . "</span>";
                }
                echo "</span>";
                echo "</div>";
            }
            echo "</div>";
        }

        echo "</div></div>";
    }

    /**
     * Velocity chart (inline SVG): completed story points per recent sprint, with an average line.
     */
    private static function showVelocityChart(Sprint $sprint): void
    {
        $collapseKey = 'dash-velocity-' . (int)$sprint->getID();
        echo "<div class='sprint-collapsible' data-sprint-collapse-key='" . htmlescape($collapseKey) . "'>";
        echo "<div class='sprint-collapsible-header'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron'></i>";
        echo "<i class='fas fa-chart-column' style='margin-left:2px;color:#0d6efd;'></i>";
        echo "<span>" . __('Velocity', 'sprint') . "</span>";
        echo "</div>";
        echo "<div class='sprint-collapsible-body'>";
        self::renderVelocityChartBody($sprint);
        echo "</div></div>";
    }

    /**
     * This sprint plus the six that started before it, oldest first.
     *
     * @return array<int,array> sprint rows
     */
    private static function getVelocityWindow(Sprint $sprint): array
    {
        $criteria = ['NOT' => ['status' => Sprint::STATUS_CANCELLED]];
        $start    = (string)($sprint->fields['date_start'] ?? '');
        if ($start !== '') {
            $criteria['date_start'] = ['<=', $start];
        }

        $rows = array_reverse(array_values(
            (new Sprint())->find($criteria, ['date_start DESC', 'id DESC'], 7)
        ));

        // A cancelled or date-less sprint falls outside the query but stays
        // the right-hand reference point.
        $ids = array_map(static fn($r) => (int)$r['id'], $rows);
        if (!in_array((int)$sprint->getID(), $ids, true)) {
            $rows[] = $sprint->fields;
            if (count($rows) > 7) {
                array_shift($rows);
            }
        }

        return $rows;
    }

    /**
     * Velocity chart body without the collapsible wrapper, so the export can reuse it. See {@see showVelocityChart()}.
     */
    public static function renderVelocityChartBody(Sprint $sprint): void
    {
        $completed  = self::getVelocityWindow($sprint);
        $currentId  = (int)$sprint->getID();

        if (count($completed) === 0) {
            echo "<div style='padding:16px;text-align:center;color:#6c757d;'>"
                . __('Velocity will appear once you have at least one completed sprint.', 'sprint')
                . "</div>";
            return;
        }

        // Per sprint: completed story points (regular throughput), plus two
        // fastlane metrics — completed item count and total allocated capacity %
        // — since fastlane work carries no story points.
        $bars      = [];
        $sum       = 0;
        $sumCount  = 0;
        $flMember  = new SprintFastlaneMember();
        foreach ($completed as $sp) {
            $sid  = (int)$sp['id'];
            $rows = (new SprintItem())->find([
                'plugin_sprint_sprints_id' => $sid,
                'status'                   => SprintItem::STATUS_DONE,
            ]);
            $pts = 0;
            foreach ($rows as $r) { $pts += (int)($r['story_points'] ?? 0); }

            $flItems = (new SprintItem())->find([
                'plugin_sprint_sprints_id' => $sid,
                'is_fastlane'              => 1,
            ]);
            $flDone = 0;
            $flIds  = [];
            foreach ($flItems as $fr) {
                $flIds[] = (int)$fr['id'];
                if (($fr['status'] ?? '') === SprintItem::STATUS_DONE) { $flDone++; }
            }
            $flCap = 0.0;
            if (!empty($flIds)) {
                foreach ($flMember->find(['plugin_sprint_sprintitems_id' => $flIds]) as $a) {
                    $flCap += (float)($a['capacity'] ?? 0);
                }
            }

            // Adhoc = work injected after kick-off, regardless of status.
            $adhoc = count((new SprintItem())->find([
                'plugin_sprint_sprints_id' => $sid,
                'is_adhoc'                 => 1,
            ]));

            $bars[] = [
                'label'   => (string)$sp['name'],
                'pts'     => $pts,
                'fl'      => $flDone,
                'adhoc'   => $adhoc,
                'cap'     => $flCap,
                'current' => $sid === $currentId,
            ];

            // A sprint still in flight would drag the reference line down.
            if (($sp['status'] ?? '') === Sprint::STATUS_COMPLETED) {
                $sum += $pts;
                $sumCount++;
            }
        }
        $avg = $sumCount > 0 ? $sum / $sumCount : 0;

        $width = 820; $height = 220;
        $padL = 40; $padR = 20; $padT = 16; $padB = 44;
        $plotW = $width - $padL - $padR;
        $plotH = $height - $padT - $padB;

        // Left axis spans points and fastlane item counts (comparable integer
        // throughput); the capacity line rides its own 0..capMax scale.
        $maxLeft = 1;
        $maxCap  = 0;
        foreach ($bars as $b) {
            $maxLeft = max($maxLeft, $b['pts'], $b['fl'], $b['adhoc']);
            $maxCap  = max($maxCap, $b['cap']);
        }
        $yTickStep = (int)max(1, ceil($maxLeft / 4));
        $yMax      = $yTickStep * 4;
        $capMax    = max(20, (int)(ceil($maxCap / 20) * 20));

        $n    = count($bars);
        $slot = $plotW / max(1, $n);
        $barW = min(22, $slot * 0.22);
        $gap  = 4;
        $yAt    = fn(float $v) => $padT + $plotH - ($plotH * ($v / $yMax));
        $yCapAt = fn(float $v) => $padT + $plotH - ($plotH * ($v / $capMax));

        echo "<div style='font-size:0.85em;color:#6c757d;margin-bottom:6px;'>"
            . __('This sprint and the six before it: completed story points, fastlane and adhoc items (bars), with total fastlane capacity % (line).', 'sprint') . "</div>";
        echo "<div style='overflow-x:auto;'>";
        echo "<svg class='sprint-responsive-chart' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$width} {$height}' "
            . "preserveAspectRatio='xMinYMin meet' style='width:100%;height:auto;display:block;font-family:sans-serif;font-size:11px;'>";

        for ($t = 0; $t <= 4; $t++) {
            $yv = (int)round($yMax * $t / 4);
            $y  = number_format($yAt($yv), 2, '.', '');
            echo "<line x1='{$padL}' y1='{$y}' x2='" . ($padL + $plotW) . "' y2='{$y}' stroke='#e9ecef' stroke-width='1' />";
            echo "<text x='" . ($padL - 6) . "' y='" . number_format($yAt($yv) + 3, 2, '.', '') . "' text-anchor='end' fill='#6c757d'>{$yv}</text>";
        }

        // Same halo as the capacity-% labels: keeps bar values readable where
        // the capacity line crosses them.
        $barLblStyle = "paint-order:stroke;stroke:var(--tblr-bg-surface,#fff);stroke-width:3.5px;stroke-linejoin:round;";

        $capPts = [];
        foreach ($bars as $i => $b) {
            $cx = $padL + ($slot * $i) + ($slot / 2);
            $bw = number_format($barW, 2, '.', '');

            // Three bars centered on the slot: points | fastlane | adhoc.
            $startX = $cx - (1.5 * $barW) - $gap;

            // Story-points bar (left).
            $ptsX = number_format($startX, 2, '.', '');
            $ptsY = number_format($yAt($b['pts']), 2, '.', '');
            $ptsH = number_format($plotH - ($yAt($b['pts']) - $padT), 2, '.', '');
            echo "<rect x='{$ptsX}' y='{$ptsY}' width='{$bw}' height='{$ptsH}' rx='2' fill='#0d6efd'><title>"
                . htmlescape($b['label'] . ' — ' . sprintf(__('%d points', 'sprint'), $b['pts'])) . "</title></rect>";
            echo "<text x='" . number_format($startX + $barW / 2, 2, '.', '') . "' y='" . number_format($yAt($b['pts']) - 4, 2, '.', '') . "' "
                . "text-anchor='middle' fill='#495057' font-weight='600' style='{$barLblStyle}'>" . (int)$b['pts'] . "</text>";

            // Fastlane item-count bar (middle).
            $flX = number_format($startX + $barW + $gap, 2, '.', '');
            $flY = number_format($yAt($b['fl']), 2, '.', '');
            $flH = number_format($plotH - ($yAt($b['fl']) - $padT), 2, '.', '');
            echo "<rect x='{$flX}' y='{$flY}' width='{$bw}' height='{$flH}' rx='2' fill='#fd7e14'><title>"
                . htmlescape($b['label'] . ' — ' . sprintf(__('%d fastlane items', 'sprint'), $b['fl'])) . "</title></rect>";
            echo "<text x='" . number_format($startX + 1.5 * $barW + $gap, 2, '.', '') . "' y='" . number_format($yAt($b['fl']) - 4, 2, '.', '') . "' "
                . "text-anchor='middle' fill='#b35900' font-weight='600' style='{$barLblStyle}'>" . (int)$b['fl'] . "</text>";

            // Adhoc item-count bar (right).
            $adX = number_format($startX + 2 * ($barW + $gap), 2, '.', '');
            $adY = number_format($yAt($b['adhoc']), 2, '.', '');
            $adH = number_format($plotH - ($yAt($b['adhoc']) - $padT), 2, '.', '');
            echo "<rect x='{$adX}' y='{$adY}' width='{$bw}' height='{$adH}' rx='2' fill='#d63384'><title>"
                . htmlescape($b['label'] . ' — ' . sprintf(__('%d adhoc items', 'sprint'), $b['adhoc'])) . "</title></rect>";
            echo "<text x='" . number_format($startX + 2.5 * $barW + 2 * $gap, 2, '.', '') . "' y='" . number_format($yAt($b['adhoc']) - 4, 2, '.', '') . "' "
                . "text-anchor='middle' fill='#d63384' font-weight='600' style='{$barLblStyle}'>" . (int)$b['adhoc'] . "</text>";

            $lbl = mb_strlen($b['label']) > 12 ? (mb_substr($b['label'], 0, 11) . '…') : $b['label'];
            $lblFill   = $b['current'] ? '#0d6efd' : '#6c757d';
            $lblWeight = $b['current'] ? "600" : "400";
            echo "<text x='" . number_format($cx, 2, '.', '') . "' y='" . ($padT + $plotH + 16) . "' "
                . "text-anchor='middle' fill='{$lblFill}' font-weight='{$lblWeight}'>" . htmlescape($lbl)
                . "<title>" . htmlescape($b['label']) . "</title></text>";

            $capPts[] = ['x' => $cx, 'y' => $yCapAt((float)$b['cap']), 'cap' => (int)$b['cap']];
        }

        // Story-points average reference line (completed sprints only).
        if ($sumCount > 0) {
            $ay = number_format($yAt($avg), 2, '.', '');
            echo "<line x1='{$padL}' y1='{$ay}' x2='" . ($padL + $plotW) . "' y2='{$ay}' stroke='#0d6efd' stroke-width='1.2' stroke-dasharray='5,4' opacity='0.6' />";
        }

        // Fastlane capacity % line (own 0..capMax scale).
        if (count($capPts) > 0) {
            $poly = [];
            foreach ($capPts as $p) {
                $poly[] = number_format($p['x'], 2, '.', '') . ',' . number_format($p['y'], 2, '.', '');
            }
            echo "<polyline points='" . implode(' ', $poly) . "' fill='none' stroke='#fd7e14' stroke-width='2' stroke-linejoin='round' />";
            // Halo behind the % labels so they stay readable over bars and the
            // line itself; resolves to white in the print/PDF export.
            $capLblStyle = "paint-order:stroke;stroke:var(--tblr-bg-surface,#fff);stroke-width:3.5px;stroke-linejoin:round;";
            foreach ($capPts as $i => $p) {
                $px = number_format($p['x'], 2, '.', '');
                $py = number_format($p['y'], 2, '.', '');
                echo "<circle cx='{$px}' cy='{$py}' r='2.8' fill='#fd7e14'><title>"
                    . htmlescape(sprintf(__('Fastlane capacity: %s%%', 'sprint'), SprintMember::formatCapacity($p['cap']))) . "</title></circle>";

                // Above the point by default; flip below when that would sit on
                // a bar value label or run off the top of the plot.
                $labelY  = $p['y'] - 8;
                $ptsLblY = $yAt((float)$bars[$i]['pts']) - 4;
                $flLblY  = $yAt((float)$bars[$i]['fl']) - 4;
                $adLblY  = $yAt((float)$bars[$i]['adhoc']) - 4;
                if ($labelY < $padT + 10 || abs($labelY - $ptsLblY) < 12 || abs($labelY - $flLblY) < 12 || abs($labelY - $adLblY) < 12) {
                    $labelY = min($p['y'] + 16, $padT + $plotH - 2);
                }
                $labelX = min(max($p['x'], $padL + 16), $padL + $plotW - 16);
                echo "<text x='" . number_format($labelX, 2, '.', '') . "' y='" . number_format($labelY, 2, '.', '') . "' "
                    . "text-anchor='middle' fill='#b35900' font-weight='600' style='{$capLblStyle}'>" . $p['cap'] . "%</text>";
            }
        }

        echo "</svg></div>";
        echo "<div style='display:flex;flex-wrap:wrap;gap:16px;margin-top:8px;font-size:0.85em;color:#6c757d;'>";
        echo "<span><span style='display:inline-block;width:14px;height:10px;background:#0d6efd;border-radius:2px;vertical-align:middle;'></span> "
            . __('Completed points', 'sprint') . "</span>";
        echo "<span><span style='display:inline-block;width:14px;height:10px;background:#fd7e14;border-radius:2px;vertical-align:middle;'></span> "
            . __('Completed fastlane items', 'sprint') . "</span>";
        echo "<span><span style='display:inline-block;width:14px;height:10px;background:#d63384;border-radius:2px;vertical-align:middle;'></span> "
            . __('Adhoc items', 'sprint') . "</span>";
        echo "<span><span style='display:inline-block;width:16px;height:0;border-top:2px solid #fd7e14;vertical-align:middle;'></span> "
            . __('Fastlane capacity %', 'sprint') . "</span>";
        if ($sumCount > 0) {
            echo "<span><span style='display:inline-block;width:16px;height:0;border-top:2px dashed #0d6efd;vertical-align:middle;opacity:0.6;'></span> "
                . sprintf(__('Avg points: %s', 'sprint'), number_format($avg, 1)) . "</span>";
        }
        echo "</div>";
    }

    /**
     * Sprint burndown chart: ideal line vs. actual remaining points per day.
     * Actuals are reconstructed from the audit log (item counts as remaining
     * if its end-of-day status wasn't Done); future days have no data point.
     */
    private static function showBurndownChart(Sprint $sprint): void
    {
        $sprintId = (int)$sprint->getID();

        $collapseKey = 'dash-burndown-' . $sprintId;
        echo "<div class='sprint-collapsible' data-sprint-collapse-key='" . htmlescape($collapseKey) . "'>";
        echo "<div class='sprint-collapsible-header'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron'></i>";
        echo "<i class='fas fa-chart-area' style='margin-left:2px;color:#0d6efd;'></i>";
        echo "<span>" . __('Burndown', 'sprint') . "</span>";
        echo "</div>";
        echo "<div class='sprint-collapsible-body'>";
        self::renderBurndownChartBody($sprint);
        echo "</div></div>";
    }

    /**
     * Burndown chart body without the collapsible wrapper, so the export can reuse it. See {@see showBurndownChart()}.
     */
    public static function renderBurndownChartBody(Sprint $sprint): void
    {
        $sprintId = (int)$sprint->getID();
        $startRaw = (string)($sprint->fields['date_start'] ?? '');
        $endRaw   = (string)($sprint->fields['date_end'] ?? '');
        if ($startRaw === '' || $endRaw === '') {
            echo "<div style='padding:16px;text-align:center;color:#6c757d;background:var(--tblr-bg-surface-secondary,#f8f9fa);border:1px dashed var(--tblr-border-color,#dee2e6);border-radius:6px;'>"
                . "<i class='fas fa-info-circle' style='margin-right:6px;'></i>"
                . __('Set the sprint start and end date to see the burndown.', 'sprint')
                . "</div>";
            return;
        }

        try {
            $start = new \DateTimeImmutable(substr($startRaw, 0, 10));
            $end   = new \DateTimeImmutable(substr($endRaw, 0, 10));
        } catch (\Exception $e) {
            return;
        }
        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        // Day list, capped so a misconfigured range can't loop forever.
        $days = [];
        $cursor = $start;
        $guard  = 0;
        while ($cursor <= $end && $guard < 120) {
            $days[] = $cursor;
            $cursor = $cursor->modify('+1 day');
            $guard++;
        }
        if (count($days) < 2) {
            echo "<div style='padding:16px;text-align:center;color:#6c757d;'>"
                . __('The sprint window is too short to plot a burndown.', 'sprint')
                . "</div>";
            return;
        }

        $si    = new SprintItem();
        $items = $si->find(['plugin_sprint_sprints_id' => $sprintId]);

        $points          = [];   // id => story points
        $currentStatuses = [];   // id => status
        $scope = 0;
        foreach ($items as $row) {
            $id = (int)$row['id'];
            $p  = (int)($row['story_points'] ?? 0);
            $points[$id]          = $p;
            $currentStatuses[$id] = (string)($row['status'] ?? '');
            $scope += $p;
        }
        $itemIds = array_keys($points);

        if ($scope <= 0 || count($itemIds) === 0) {
            echo "<div style='padding:16px;text-align:center;color:#6c757d;'>"
                . __('No story points in this sprint yet to burn down.', 'sprint')
                . "</div>";
            return;
        }

        // What counts as "done" — match both the stored key and its label,
        // since GLPI's history can log either depending on the field type.
        $statuses      = SprintItem::getAllStatuses();
        $doneTokens    = [SprintItem::STATUS_DONE, (string)($statuses[SprintItem::STATUS_DONE] ?? '')];
        $blockedTokens = [SprintItem::STATUS_BLOCKED, (string)($statuses[SprintItem::STATUS_BLOCKED] ?? '')];
        $today         = new \DateTimeImmutable('today');

        $nDays   = count($days);
        $actual  = [];   // index => remaining points (or null for future days)
        $blocked = [];   // index => points sitting in blocked status that day
        foreach ($days as $i => $day) {
            if ($day > $today) {
                $actual[$i]  = null;
                $blocked[$i] = null;
                continue;
            }
            $eod        = $day->format('Y-m-d') . ' 23:59:59';
            $statusAt   = SprintAudit::getItemStatusAtTimestamp($itemIds, $eod, $currentStatuses);
            $remaining  = 0;
            $blockedPts = 0;
            foreach ($itemIds as $id) {
                $st = (string)($statusAt[$id] ?? $currentStatuses[$id]);
                if (!in_array($st, $doneTokens, true)) {
                    $remaining += $points[$id];
                    if (in_array($st, $blockedTokens, true)) {
                        $blockedPts += $points[$id];
                    }
                }
            }
            $actual[$i]  = $remaining;
            $blocked[$i] = $blockedPts;
        }

        // === SVG geometry (mirrors the activity chart) ===
        $width = 820; $height = 220;
        $padL = 40; $padR = 20; $padT = 16; $padB = 36;
        $plotW = $width - $padL - $padR;
        $plotH = $height - $padT - $padB;

        $yMaxRaw   = max(1, $scope);
        $yTickStep = (int)max(1, ceil($yMaxRaw / 4));
        $yMax      = $yTickStep * 4;

        $xStep = ($nDays > 1) ? $plotW / ($nDays - 1) : 0;
        $xAt = fn(int $i) => $padL + ($xStep * $i);
        $yAt = fn(float $v) => $padT + $plotH - ($plotH * ($v / $yMax));

        echo "<div style='font-size:0.85em;color:#6c757d;margin-bottom:6px;'>"
            . __('Remaining story points vs. the ideal line across the sprint.', 'sprint') . "</div>";
        echo "<div style='overflow-x:auto;'>";
        echo "<svg class='sprint-responsive-chart' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$width} {$height}' "
            . "preserveAspectRatio='xMinYMin meet' style='width:100%;height:auto;display:block;font-family:sans-serif;font-size:11px;'>";

        // Horizontal grid + y labels
        for ($t = 0; $t <= 4; $t++) {
            $yv = (int)round($yMax * $t / 4);
            $y  = number_format($yAt($yv), 2, '.', '');
            echo "<line x1='{$padL}' y1='{$y}' x2='" . ($padL + $plotW) . "' y2='{$y}' stroke='#e9ecef' stroke-width='1' />";
            echo "<text x='" . ($padL - 6) . "' y='" . number_format($yAt($yv) + 3, 2, '.', '') . "' text-anchor='end' fill='#6c757d'>{$yv}</text>";
        }

        // X labels (thinned)
        $labelEvery = max(1, (int)ceil($nDays / 10));
        for ($i = 0; $i < $nDays; $i++) {
            if ($i % $labelEvery !== 0 && $i !== $nDays - 1) { continue; }
            $x = number_format($xAt($i), 2, '.', '');
            echo "<text x='{$x}' y='" . ($padT + $plotH + 16) . "' text-anchor='middle' fill='#6c757d'>"
                . htmlescape($days[$i]->format('d/m')) . "</text>";
        }

        // Axes
        echo "<line x1='{$padL}' y1='{$padT}' x2='{$padL}' y2='" . ($padT + $plotH) . "' stroke='#adb5bd' stroke-width='1' />";
        echo "<line x1='{$padL}' y1='" . ($padT + $plotH) . "' x2='" . ($padL + $plotW) . "' y2='" . ($padT + $plotH) . "' stroke='#adb5bd' stroke-width='1' />";

        // Ideal line (scope → 0), dashed grey
        $idealPts = [];
        for ($i = 0; $i < $nDays; $i++) {
            $iv = $scope - ($scope * $i / ($nDays - 1));
            $idealPts[] = number_format($xAt($i), 2, '.', '') . ',' . number_format($yAt($iv), 2, '.', '');
        }
        echo "<polyline points='" . implode(' ', $idealPts) . "' fill='none' stroke='#adb5bd' "
            . "stroke-width='1.5' stroke-dasharray='5,4' />";

        // Blocked overlay: points stuck in blocked status never burn, so the
        // remaining line can't reach the ideal. A second line shows what's
        // left when blocked points are excluded ("workable remaining"), with
        // the gap shaded red so the blocked share is visible per day.
        $hasBlocked = false;
        foreach ($blocked as $v) {
            if ($v !== null && $v > 0) { $hasBlocked = true; break; }
        }
        if ($hasBlocked) {
            $actualLinePts = [];
            $adjLinePts    = [];
            foreach ($actual as $i => $v) {
                if ($v === null) { continue; }
                $x = number_format($xAt($i), 2, '.', '');
                $actualLinePts[] = $x . ',' . number_format($yAt((float)$v), 2, '.', '');
                $adjLinePts[]    = $x . ',' . number_format($yAt((float)max($v - (int)$blocked[$i], 0)), 2, '.', '');
            }
            if (count($adjLinePts) > 1) {
                echo "<polygon points='" . implode(' ', array_merge($actualLinePts, array_reverse($adjLinePts)))
                    . "' fill='#dc3545' fill-opacity='0.28' stroke='none' />";
                echo "<polyline points='" . implode(' ', $adjLinePts) . "' fill='none' stroke='#20c997' "
                    . "stroke-width='2' stroke-dasharray='4,3' stroke-linejoin='round' stroke-linecap='round' />";
            }
        }

        // Actual line (only days up to today)
        $actualPts = [];
        foreach ($actual as $i => $v) {
            if ($v === null) { continue; }
            $actualPts[] = number_format($xAt($i), 2, '.', '') . ',' . number_format($yAt((float)$v), 2, '.', '');
        }
        if (count($actualPts) > 0) {
            echo "<polyline points='" . implode(' ', $actualPts) . "' fill='none' stroke='#0d6efd' "
                . "stroke-width='2.5' stroke-linejoin='round' stroke-linecap='round' />";
            foreach ($actual as $i => $v) {
                if ($v === null) { continue; }
                $cx = number_format($xAt($i), 2, '.', '');
                $cy = number_format($yAt((float)$v), 2, '.', '');
                $title = $days[$i]->format('Y-m-d') . ': ' . $v . ' ' . __('points remaining', 'sprint');
                if (($blocked[$i] ?? 0) > 0) {
                    $title .= ' — ' . sprintf(__('%d blocked', 'sprint'), (int)$blocked[$i]);
                }
                echo "<circle cx='{$cx}' cy='{$cy}' r='2.8' fill='#0d6efd'><title>" . htmlescape($title) . "</title></circle>";
            }
        }

        // Items in review normally close at the next stand-up; show where the line lands then.
        $reviewPts = 0;
        foreach ($itemIds as $id) {
            if ($currentStatuses[$id] === SprintItem::STATUS_REVIEW) {
                $reviewPts += $points[$id];
            }
        }
        $lastIdx = null;
        foreach ($actual as $i => $v) {
            if ($v !== null) { $lastIdx = $i; }
        }
        $hasProjection = ($reviewPts > 0 && $lastIdx !== null);
        if ($hasProjection) {
            $projVal = max((float)$actual[$lastIdx] - $reviewPts, 0);
            $projIdx = min($lastIdx + 1, $nDays - 1);
            $x1 = number_format($xAt($lastIdx), 2, '.', '');
            $y1 = number_format($yAt((float)$actual[$lastIdx]), 2, '.', '');
            $x2 = number_format($xAt($projIdx), 2, '.', '');
            $y2 = number_format($yAt($projVal), 2, '.', '');
            $projTitle = sprintf(
                __('Projected after review: %d points remaining (%d in review)', 'sprint'),
                (int)$projVal,
                $reviewPts
            );
            echo "<line x1='{$x1}' y1='{$y1}' x2='{$x2}' y2='{$y2}' stroke='#6f42c1' "
                . "stroke-width='2' stroke-dasharray='3,3' stroke-linecap='round' />";
            echo "<circle cx='{$x2}' cy='{$y2}' r='3.2' fill='none' stroke='#6f42c1' stroke-width='2'>"
                . "<title>" . htmlescape($projTitle) . "</title></circle>";
        }

        echo "</svg>";
        echo "</div>"; // overflow

        // Legend
        echo "<div style='display:flex;gap:16px;margin-top:8px;font-size:0.85em;color:#6c757d;flex-wrap:wrap;'>";
        echo "<span><span style='display:inline-block;width:16px;height:3px;background:#0d6efd;border-radius:2px;vertical-align:middle;'></span> "
            . __('Remaining', 'sprint') . "</span>";
        if ($hasBlocked) {
            echo "<span><span style='display:inline-block;width:16px;height:0;border-top:2px dashed #20c997;vertical-align:middle;'></span> "
                . __('Remaining excl. blocked', 'sprint') . "</span>";
            echo "<span><span style='display:inline-block;width:16px;height:10px;background:#dc354548;border:1px solid #dc354599;border-radius:2px;vertical-align:middle;'></span> "
                . __('Blocked share', 'sprint') . "</span>";
        }
        echo "<span><span style='display:inline-block;width:16px;height:0;border-top:2px dashed #adb5bd;vertical-align:middle;'></span> "
            . __('Ideal', 'sprint') . "</span>";
        if ($hasProjection) {
            echo "<span><span style='display:inline-block;width:16px;height:0;border-top:2px dashed #6f42c1;vertical-align:middle;'></span> "
                . __('Projected after review', 'sprint') . "</span>";
        }
        echo "<span class='text-muted'>" . sprintf(__('Scope: %d points', 'sprint'), $scope) . "</span>";
        echo "</div>";
    }

    private static function showMemberActivityChart(int $sprintId): void
    {
        $collapseKey = 'dash-activity-' . (int)$sprintId;
        echo "<div class='sprint-collapsible' data-sprint-collapse-key='" . htmlescape($collapseKey) . "'>";
        echo "<div class='sprint-collapsible-header'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron'></i>";
        echo "<i class='fas fa-chart-line' style='margin-left:2px;'></i>";
        echo "<span>" . __('Team activity', 'sprint') . "</span>";
        echo "</div>";
        echo "<div class='sprint-collapsible-body'>";

        echo "<div class='sprint-activity-chart-wrap' data-sprint-id='" . (int)$sprintId . "'>";
        self::renderActivityChartFragment($sprintId, null, null);
        echo "</div>";

        echo "</div>"; // .sprint-collapsible-body
        echo "</div>"; // .sprint-collapsible
    }

    /**
     * Render the activity chart inner fragment (range picker + SVG + legend).
     * Reused on initial render and by the AJAX refresh endpoint.
     */
    public static function renderActivityChartFragment(int $sprintId, ?\DateTimeImmutable $overrideFrom, ?\DateTimeImmutable $overrideTo): void
    {
        $data    = SprintAudit::getMemberActivity($sprintId, $overrideFrom, $overrideTo);
        $dates   = $data['dates'];
        $members = $data['members'];

        echo "<div style='font-size:0.85em;color:#6c757d;margin-bottom:6px;'>" .
            __('Audit-log events per member per day — spot uneven workloads.', 'sprint') .
            "</div>";

        self::renderActivityRangePicker($sprintId, $dates, $overrideFrom, $overrideTo);

        if (count($dates) < 1 || count($members) === 0) {
            // Explain the empty state (no members, past retention window, no logged actions yet).
            $reason = self::diagnoseActivityEmptyReason($sprintId, $dates, $members);
            echo "<div style='padding:18px;text-align:center;color:#6c757d;background:var(--tblr-bg-surface-secondary,#f8f9fa);border:1px dashed var(--tblr-border-color,#dee2e6);border-radius:6px;'>"
                . "<i class='fas fa-info-circle' style='margin-right:6px;'></i>"
                . htmlescape($reason)
                . "</div>";
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
        echo "<svg class='sprint-responsive-chart' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$width} {$height}' "
            . "preserveAspectRatio='xMinYMin meet' style='width:100%;height:auto;display:block;font-family:sans-serif;font-size:11px;'>";

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
    }
    /**
     * Date-range picker above the activity chart. Submits as GET to the
     * sprint form with `forcetab` so the user lands back on this dashboard.
     */
    private static function renderActivityRangePicker(int $sprintId, array $dates, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): void
    {
        $today    = new \DateTimeImmutable('today');
        [$winStart, $winEnd] = SprintAudit::getRetentionWindowForSprint($sprintId);
        try {
            $earliest = $winStart ? new \DateTimeImmutable(substr($winStart, 0, 10)) : $today->modify('-30 days');
            $latest   = $winEnd   ? new \DateTimeImmutable(substr($winEnd,   0, 10)) : $today;
        } catch (\Exception $e) {
            $earliest = $today->modify('-30 days');
            $latest   = $today;
        }
        $minStr   = $earliest->format('Y-m-d');
        $maxStr   = $latest->format('Y-m-d');
        $fromStr  = $from ? $from->format('Y-m-d') : (count($dates) > 0 ? $dates[0] : '');
        $toStr    = $to   ? $to->format('Y-m-d')   : (count($dates) > 0 ? $dates[count($dates) - 1] : '');
        $isCustom = ($from !== null || $to !== null);

        $action = Sprint::getFormURL();
        $tab    = 'GlpiPlugin\\Sprint\\SprintDashboard$1';

        echo "<form method='get' action='" . htmlescape($action) . "' "
            . "class='sprint-activity-range d-flex flex-wrap align-items-end gap-2 mb-2' "
            . "data-sprint-id='" . (int)$sprintId . "' "
            . "style='font-size:0.85em;'>";
        echo Html::hidden('id', ['value' => (int)$sprintId]);
        echo Html::hidden('forcetab', ['value' => $tab]);
        echo "<span class='me-auto text-muted small align-self-center'>"
            . __('Activity range follows the sprint window.', 'sprint')
            . "</span>";
        echo "<label class='d-flex flex-column' style='gap:2px;'>"
            . "<span class='text-muted'>" . __('From', 'sprint') . "</span>"
            . "<input type='date' class='form-control form-control-sm' name='activity_from' "
            . "value='" . htmlescape($fromStr) . "' min='{$minStr}' max='{$maxStr}'>"
            . "</label>";
        echo "<label class='d-flex flex-column' style='gap:2px;'>"
            . "<span class='text-muted'>" . __('To', 'sprint') . "</span>"
            . "<input type='date' class='form-control form-control-sm' name='activity_to' "
            . "value='" . htmlescape($toStr) . "' min='{$minStr}' max='{$maxStr}'>"
            . "</label>";
        echo "<button type='submit' class='btn btn-sm btn-primary'>"
            . "<i class='fas fa-search me-1'></i>" . __('Apply', 'sprint') . "</button>";
        if ($isCustom) {
            echo "<button type='button' class='btn btn-sm btn-outline-secondary' "
                . "data-sprint-action='activity-reset' data-sprint-id='" . (int)$sprintId . "'>"
                . "<i class='fas fa-undo me-1'></i>" . __('Reset to sprint range', 'sprint') . "</button>";
        }
        echo "</form>";
    }

    /**
     * Explain why the activity chart has nothing to plot: no members, past
     * the retention window, or no logged actions yet.
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
            $startTs = !empty($sprint->fields['date_start'])
                ? strtotime((string)$sprint->fields['date_start'])
                : 0;
            if ($startTs > time()) {
                return __('Sprint has not started yet — activity will appear here once team members make changes.', 'sprint');
            }
            return __('No tracked activity yet for this sprint.', 'sprint');
        }

        // Dates exist, but no member has a logged action in range.
        return __('No tracked activity yet for this sprint — make a change in any sprint item, member or meeting and it will show up here.', 'sprint');
    }
}
