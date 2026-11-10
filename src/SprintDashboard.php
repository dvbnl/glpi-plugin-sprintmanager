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

        self::renderNextSteps($sprint);

        // === View toggle (compact segmented control, choice persisted) ===
        // The stand-up is a daily action, so it sits right next to the toggle
        // while the sprint runs ('' when the user may not start or open one).
        $standup = (string)($sprint->fields['status'] ?? '') === Sprint::STATUS_ACTIVE
            ? SprintMeeting::startStandupButton((int)$ID)
            : '';
        echo "<div class='sprint-dash-toolbar'>";
        echo "<div class='btn-group btn-group-sm' role='group' aria-label='" . __s('View', 'sprint') . "'>";
        echo "<button type='button' id='sprint_btn_global' class='btn btn-sm btn-primary' aria-pressed='true' onclick='sprintToggleView(\"global\")'>" .
            "<i class='fas fa-globe me-1' aria-hidden='true'></i>" . __s('Team', 'sprint') . "</button>";
        echo "<button type='button' id='sprint_btn_personal' class='btn btn-sm btn-outline-secondary' aria-pressed='false' onclick='sprintToggleView(\"personal\")'>" .
            "<i class='fas fa-user me-1' aria-hidden='true'></i>" . __s('My work', 'sprint') . "</button>";
        echo "</div>";
        if ($standup !== '') {
            echo "<div class='sprint-dash-toolbar-end'>" . $standup . "</div>";
        }
        echo "</div>";

        // === Global view ===
        // Key figures, then the (collapsible, remembered) analysis zone with
        // the trends, then blockers, the user's own actions and the work lists.
        echo "<div id='sprint_view_global'>";
        self::renderStatsAndProgress($globalStats);
        self::openCollapsible(
            'dash-analysis-' . (int)$ID,
            "<i class='fas fa-chart-pie sprint-head-icon' aria-hidden='true'></i>"
                . "<span>" . __s('Analysis and trends', 'sprint')
                . " <span class='text-muted fw-normal'>— " . __s('burndown, velocity and team activity', 'sprint') . "</span></span>",
            false,
            'sprint-analysis-zone'
        );
        self::showBurndownChart($sprint);
        self::showVelocityChart($sprint);
        self::showMemberActivityChart($ID);
        self::closeCollapsible();
        self::renderMyWork($ID, $currentUserId, $personalStats, count($helperDepIds));
        self::showAtRiskWidget($sprint);
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
        // ?dashboard_view=personal (the "My work" deeplink) wins over the
        // remembered choice; the page URL is read, not the ajax tab URL.
        echo "<script>
        function sprintToggleView(view) {
            var gDiv = document.getElementById('sprint_view_global');
            var pDiv = document.getElementById('sprint_view_personal');
            var gBtn = document.getElementById('sprint_btn_global');
            var pBtn = document.getElementById('sprint_btn_personal');
            if (!gDiv || !pDiv || !gBtn || !pBtn) { return; }
            var personal = view === 'personal';
            gDiv.style.display = personal ? 'none' : '';
            pDiv.style.display = personal ? '' : 'none';
            gBtn.className = personal ? 'btn btn-sm btn-outline-secondary' : 'btn btn-sm btn-primary';
            pBtn.className = personal ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline-secondary';
            gBtn.setAttribute('aria-pressed', personal ? 'false' : 'true');
            pBtn.setAttribute('aria-pressed', personal ? 'true' : 'false');
            try { localStorage.setItem('sprint-dashboard-view', view); } catch (e) {}
        }
        (function() {
            var saved = null;
            try { saved = new URLSearchParams(window.location.search).get('dashboard_view'); } catch (e) {}
            if (saved !== 'personal' && saved !== 'global') {
                try { saved = localStorage.getItem('sprint-dashboard-view'); } catch (e) {}
            }
            if (saved === 'personal') { sprintToggleView('personal'); }
        })();
        </script>";

        SprintItem::renderQuickEditUI($ID);
        SprintItem::renderLinkedQuickEditUI();
    }

    /** Unique body id for a collapsible (ajax tabs can render a section twice). */
    private static function collapsibleBodyId(string $key): string
    {
        return 'sprint-col-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $key) . '-' . mt_rand();
    }

    /**
     * Open a collapsible section with a real <button> header (aria-expanded /
     * aria-controls; js/sprint.js keeps them in sync and remembers the state
     * per key). Close with {@see closeCollapsible()}.
     */
    public static function openCollapsible(string $key, string $headerHtml, bool $collapsed = false, string $extraClass = ''): void
    {
        $bodyId = self::collapsibleBodyId($key);
        $class  = 'sprint-collapsible' . ($collapsed ? ' sprint-collapsed' : '') . ($extraClass !== '' ? ' ' . $extraClass : '');
        echo "<div class='" . htmlescape($class) . "' data-sprint-collapse-key='" . htmlescape($key) . "'>";
        echo "<button type='button' class='sprint-collapsible-header' aria-expanded='" . ($collapsed ? 'false' : 'true') . "' aria-controls='{$bodyId}'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron' aria-hidden='true'></i>";
        echo $headerHtml;
        echo "</button>";
        echo "<div class='sprint-collapsible-body' id='{$bodyId}'>";
    }

    public static function closeCollapsible(): void
    {
        echo "</div></div>";
    }

    /** Form URL of a sprint opened on one of its tabs. */
    private static function sprintTabUrl(int $sprintId, string $tabClass): string
    {
        return Sprint::getFormURLWithID($sprintId) . '&forcetab=' . rawurlencode($tabClass . '$1');
    }

    /**
     * "Next steps" checklist from setting a sprint up to starting it: team,
     * items, meetings, kick-off and the start itself. Shown for a planned
     * sprint until it is started, and for a running one that still misses its
     * team, items or meetings. Also the landing spot right after creating a
     * sprint.
     */
    public static function renderNextSteps(Sprint $sprint): void
    {
        $id     = (int)$sprint->getID();
        $status = (string)($sprint->fields['status'] ?? '');
        if (!in_array($status, [Sprint::STATUS_PLANNED, Sprint::STATUS_ACTIVE], true) || !Sprint::canUpdate()) {
            return;
        }
        $members  = countElementsInTable(SprintMember::getTable(), ['plugin_sprint_sprints_id' => $id]);
        $items    = countElementsInTable(SprintItem::getTable(), ['plugin_sprint_sprints_id' => $id]);
        $meetings = countElementsInTable(SprintMeeting::getTable(), ['plugin_sprint_sprints_id' => $id]);
        $started  = $status === Sprint::STATUS_ACTIVE;
        // A running sprint that is fully set up needs no checklist. A planned
        // one keeps it until it is started: that is the step people miss.
        if ($started && $members > 0 && $items > 0 && $meetings > 0) {
            return;
        }

        // The kick-off counts as held once its guided session is completed or
        // its date has passed. The step links to the meeting when there is one.
        $kickoffDone = false;
        $kickoffUrl  = self::sprintTabUrl($id, SprintMeeting::class);
        $now         = (string)($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        foreach ((new SprintMeeting())->find([
            'plugin_sprint_sprints_id' => $id,
            'meeting_type'             => SprintMeeting::TYPE_KICKOFF,
        ], ['date_meeting ASC']) as $row) {
            $kickoffUrl = SprintMeeting::getFormURLWithID((int)$row['id']);
            if ((string)($row['meeting_status'] ?? '') === 'completed'
                || (!empty($row['date_meeting']) && (string)$row['date_meeting'] <= $now)) {
                $kickoffDone = true;
                break;
            }
        }

        // Each step: done, label, url, icon, and optionally the HTML of an action.
        $steps = [
            [$members > 0, __('Add the team and their capacity', 'sprint'), self::sprintTabUrl($id, SprintMember::class), 'fas fa-users', ''],
        ];
        if (Backlog::canView()) {
            $steps[] = [$items > 0, __('Plan items from the backlog', 'sprint'), Backlog::getSearchURL(), 'fas fa-inbox', ''];
        } else {
            $steps[] = [$items > 0, __('Add sprint items', 'sprint'), self::sprintTabUrl($id, SprintItem::class), 'fas fa-list-ul', ''];
        }
        $steps[] = [$meetings > 0, __('Schedule the meetings', 'sprint'), self::sprintTabUrl($id, SprintMeeting::class), 'fas fa-calendar', ''];
        $steps[] = [$kickoffDone, __('Hold the kick-off', 'sprint'), $kickoffUrl, 'fas fa-flag', ''];
        // Starting is an action, not a page: the step is the start button
        // itself ('' when the user may not start the sprint).
        $steps[] = [$started, __('Start the sprint', 'sprint'), '', 'fas fa-play', $started ? '' : Sprint::startButton($id)];

        echo "<section class='sprint-section sprint-next-steps' aria-label='" . __s('Next steps', 'sprint') . "'>";
        echo "<div class='sprint-section-header'><h3 class='sprint-section-title'><i class='fas fa-route me-2' aria-hidden='true'></i>"
            . __s('Next steps', 'sprint') . "</h3></div>";
        echo "<div class='sprint-section-body'><ol class='sprint-next-steps-list'>";
        foreach ($steps as [$done, $label, $url, $icon, $action]) {
            echo "<li>";
            if ($done) {
                echo "<span class='sprint-chip sprint-chip-lg sprint-chip-success'><i class='fas fa-circle-check' aria-hidden='true'></i> "
                    . htmlescape($label) . " <span class='visually-hidden'>(" . __s('done', 'sprint') . ")</span></span>";
            } elseif ($action !== '') {
                echo $action;
            } elseif ($url !== '') {
                echo "<a class='sprint-chip sprint-chip-lg sprint-chip-info' href='" . htmlescape($url) . "'><i class='" . $icon . "' aria-hidden='true'></i> " . htmlescape($label) . "</a>";
            } else {
                echo "<span class='sprint-chip sprint-chip-lg'><i class='" . $icon . "' aria-hidden='true'></i> " . htmlescape($label) . "</span>";
            }
            echo "</li>";
        }
        echo "</ol></div></section>";
    }

    /**
     * "My work" strip on the global view: the user's own open, blocked and
     * waiting-on-you work, with a one-click switch to the personal view.
     */
    private static function renderMyWork(int $sprintId, int $userId, array $personalStats, int $waitingOnMe): void
    {
        $isMember = countElementsInTable(SprintMember::getTable(), [
            'plugin_sprint_sprints_id' => $sprintId,
            'users_id'                 => $userId,
        ]) > 0;
        if (!$isMember && $personalStats['total_items'] === 0 && $waitingOnMe === 0) {
            return;
        }
        $open = max(0, $personalStats['total_items'] - $personalStats['done_items']);

        echo "<div class='sprint-my-work'>";
        echo "<strong class='sprint-my-work-title'><i class='fas fa-user-check me-1' aria-hidden='true'></i>" . __s('My work', 'sprint') . "</strong>";
        echo "<span class='sprint-my-work-facts'>";
        echo "<span class='sprint-chip'>" . htmlescape(sprintf(_n('%d open item', '%d open items', $open, 'sprint'), $open)) . "</span>";
        if ($personalStats['blocked_items'] > 0) {
            echo "<span class='sprint-chip sprint-chip-danger'>" . htmlescape(sprintf(__('%d blocked', 'sprint'), $personalStats['blocked_items'])) . "</span>";
        }
        if ($waitingOnMe > 0) {
            echo "<span class='sprint-chip sprint-chip-dependency'>" . htmlescape(sprintf(
                _n('%d dependency waiting on you', '%d dependencies waiting on you', $waitingOnMe, 'sprint'),
                $waitingOnMe
            )) . "</span>";
        }
        echo "</span>";
        echo "<button type='button' class='btn btn-sm btn-outline-secondary ms-auto' onclick='sprintToggleView(\"personal\")'>"
            . "<i class='fas fa-user me-1' aria-hidden='true'></i>" . __s('Show my work', 'sprint') . "</button>";
        echo "</div>";
    }

    /**
     * Compute stats from an array of item rows
     */
    private static function computeStats(array $items): array
    {
        $stats = [
            'total_items'      => count($items),
            'todo_items'       => 0,
            'scheduled_items'  => 0,
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
            'scheduled_cap'    => 0,
            'in_progress_cap'  => 0,
            'review_cap'       => 0,
            'dependency_cap'   => 0,
            'done_cap'         => 0,
            'blocked_cap'      => 0,
            // Missing estimates, surfaced next to the figures they distort.
            'no_capacity_items' => 0,
            'no_points_items'   => 0,
        ];

        foreach ($items as $row) {
            $stats['total_points'] += (int)$row['story_points'];
            // Min weight 1 per item: a zero-capacity (unestimated) item still
            // earns its own slice in the bar instead of vanishing, while items
            // with real capacity dominate proportionally.
            $capWeight = max((float)($row['capacity'] ?? 0), 1);
            if ((float)($row['capacity'] ?? 0) <= 0) {
                $stats['no_capacity_items']++;
            }
            if ((int)$row['story_points'] <= 0) {
                $stats['no_points_items']++;
            }
            switch ($row['raw_status']) {
                case SprintItem::STATUS_TODO:
                    $stats['todo_items']++;
                    $stats['todo_cap'] += $capWeight;
                    break;
                case SprintItem::STATUS_SCHEDULED:
                    $stats['scheduled_items']++;
                    $stats['scheduled_cap'] += $capWeight;
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
     * Render stats cards and progress bar.
     *
     * One primary measure per screen: the dashboard leads with progress
     * weighted by capacity (the bar below). Items done and story points
     * delivered sit next to it with their unit spelled out, so a sprint with
     * one big open item and many small done ones reads as "8/10 items, 35%
     * of the capacity" instead of two unexplained, conflicting percentages.
     */
    private static function renderStatsAndProgress(array $stats): void
    {
        $weightTotal = (float)($stats['done_cap'] + $stats['in_progress_cap'] + $stats['review_cap']
            + $stats['dependency_cap'] + $stats['blocked_cap'] + $stats['todo_cap'] + $stats['scheduled_cap']);
        $weightedPct = $weightTotal > 0 ? (int)round($stats['done_cap'] / $weightTotal * 100) : 0;
        $itemsPct    = $stats['total_items'] > 0 ? (int)round($stats['done_items'] / $stats['total_items'] * 100) : 0;

        // === Key figures (about four), the rest on demand ===
        $key = [
            ['label' => __('Progress weighted by capacity', 'sprint'), 'value' => $weightedPct . '%', 'icon' => 'fas fa-weight-hanging', 'accent' => '#0d6efd'],
            ['label' => __('Items done', 'sprint'), 'value' => $stats['done_items'] . '/' . $stats['total_items'] . ' (' . $itemsPct . '%)', 'icon' => 'fas fa-check-circle', 'accent' => '#198754'],
            ['label' => __('Story points delivered', 'sprint'), 'value' => $stats['done_points'] . '/' . $stats['total_points'], 'icon' => 'fas fa-star', 'accent' => '#d68a00'],
            ['label' => __('Blocked', 'sprint'), 'value' => (string)$stats['blocked_items'], 'icon' => 'fas fa-hand-paper', 'accent' => '#dc3545'],
        ];
        $more = [
            ['label' => __('Total Items', 'sprint'),  'value' => (string)$stats['total_items'],     'icon' => 'fas fa-list-ul',      'accent' => '#6c757d'],
            ['label' => __('To Do', 'sprint'),        'value' => (string)$stats['todo_items'],      'icon' => 'fas fa-circle',       'accent' => '#6c757d'],
            ['label' => __('Scheduled', 'sprint'),    'value' => (string)$stats['scheduled_items'], 'icon' => 'fas fa-calendar-check', 'accent' => '#087990'],
            ['label' => __('In Progress', 'sprint'),  'value' => (string)$stats['in_progress'],     'icon' => 'fas fa-circle-notch', 'accent' => '#0d6efd'],
            ['label' => __('In Review', 'sprint'),    'value' => (string)$stats['review_items'],    'icon' => 'fas fa-search',       'accent' => '#6f42c1'],
            ['label' => __('Dependency', 'sprint'),   'value' => (string)$stats['dependency_items'],'icon' => 'fas fa-link',         'accent' => '#20c997'],
        ];
        $renderCards = static function (array $cards): void {
            echo "<div class='sprint-stat-cards'>";
            foreach ($cards as $c) {
                echo "<div class='sprint-stat-card' style='--stat-accent:{$c['accent']};'>";
                echo "<div class='sprint-stat-icon' aria-hidden='true'><i class='{$c['icon']}'></i></div>";
                echo "<div class='sprint-stat-text'>";
                echo "<div class='sprint-stat-value'>" . htmlescape($c['value']) . "</div>";
                echo "<div class='sprint-stat-label'>" . htmlescape($c['label']) . "</div>";
                echo "</div>";
                echo "</div>";
            }
            echo "</div>";
        };
        $renderCards($key);
        echo "<details class='sprint-stat-more'>";
        echo "<summary>" . __s('More figures', 'sprint') . "</summary>";
        $renderCards($more);
        echo "</details>";

        // Missing estimates: say what they do to each figure.
        $notes = [];
        if ($stats['no_capacity_items'] > 0) {
            $notes[] = sprintf(
                _n(
                    '%d item has no capacity estimate. It counts with weight 1 in the progress bar.',
                    '%d items have no capacity estimate. They count with weight 1 in the progress bar.',
                    $stats['no_capacity_items'],
                    'sprint'
                ),
                $stats['no_capacity_items']
            );
        }
        if ($stats['no_points_items'] > 0) {
            $notes[] = sprintf(
                _n(
                    '%d item has no story points. It does not count towards story points delivered.',
                    '%d items have no story points. They do not count towards story points delivered.',
                    $stats['no_points_items'],
                    'sprint'
                ),
                $stats['no_points_items']
            );
        }
        if ($notes) {
            echo "<div class='sprint-estimate-notes sprint-small text-muted'>"
                . "<i class='fas fa-circle-info me-1' aria-hidden='true'></i>"
                . htmlescape(implode(' ', $notes)) . "</div>";
        }

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
            'scheduled'  => (float)($stats['scheduled_cap'] ?? 0),
            'todo'       => (float)($stats['todo_cap'] ?? 0),
        ];
        $total = max(array_sum($weights), 1);
        // Segment order, CSS key (`.sprint-seg-*` carries the status colour) and label.
        $segments = [
            'done'       => __('Done', 'sprint'),
            'progress'   => __('In Progress', 'sprint'),
            'review'     => __('In Review', 'sprint'),
            'dependency' => __('Dependency', 'sprint'),
            'blocked'    => __('Blocked', 'sprint'),
            'scheduled'  => __('Scheduled', 'sprint'),
            'todo'       => __('To Do', 'sprint'),
        ];
        $pcts = [];
        foreach ($segments as $key => $label) {
            $pcts[$key] = round(($weights[$key] / $total) * 100, 1);
        }
        $donePct = $pcts['done'];

        echo "<div class='sprint-progress'>";
        echo "<div class='sprint-progress-title'>" . __s('Progress weighted by capacity', 'sprint')
            . " <span class='text-muted'>" . htmlescape(sprintf(__('%s%% done', 'sprint'), $donePct)) . "</span></div>";
        echo "<div class='sprint-progress-legend'>";
        foreach ($segments as $key => $label) {
            echo "<span><span class='sprint-progress-dot sprint-seg-{$key}' aria-hidden='true'></span>"
                . htmlescape($label) . " {$pcts[$key]}%</span>";
        }
        echo "</div>";
        // The min-width in the CSS keeps a tiny (e.g. single unestimated item) slice visible.
        echo "<div class='sprint-progress-track'>";
        foreach ($segments as $key => $label) {
            if ($pcts[$key] > 0) {
                echo "<div class='sprint-progress-seg sprint-seg-{$key}' style='width:{$pcts[$key]}%;' title='"
                    . htmlescape($label . ' ' . $pcts[$key] . '%') . "'></div>";
            }
        }
        echo "</div>";
        echo "<details class='sprint-measure-help sprint-small'>";
        echo "<summary>" . __s('How is progress measured?', 'sprint') . "</summary>";
        echo "<ul class='mb-0'>";
        echo "<li><strong>" . __s('Progress weighted by capacity', 'sprint') . "</strong>: "
            . __s('share of the allocated capacity (%) that sits in done items. An item without capacity counts with weight 1. This is the main measure on this dashboard.', 'sprint') . "</li>";
        echo "<li><strong>" . __s('Items done', 'sprint') . "</strong>: "
            . __s('number of done items out of all items, regardless of size. The sprint header shows this measure.', 'sprint') . "</li>";
        echo "<li><strong>" . __s('Story points delivered', 'sprint') . "</strong>: "
            . __s('story points of done items out of all planned story points. Velocity and the overview use this measure.', 'sprint') . "</li>";
        echo "</ul>";
        echo "</details>";
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
        $bodyId = self::collapsibleBodyId($collapseKey);
        echo "<button type='button' class='sprint-collapsible-header' aria-expanded='true' aria-controls='{$bodyId}'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron' aria-hidden='true'></i>";
        echo "<i class='fas fa-list-ul sprint-head-icon' aria-hidden='true'></i>";
        echo "<span>" . __('Sprint items', 'sprint') . " <span class='text-muted fw-normal'>(" . count($items) . ")</span></span>";
        echo "</button>";
        echo "<div class='sprint-collapsible-body' id='{$bodyId}'>";

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
        // Keyboard-operable sort headers; sprint.js updates aria-sort on click.
        $sc = SprintItem::sortClickAttr($tableSelector) . " scope='col' tabindex='0' aria-sort='none'";
        echo "<th class='sprint-sortable center' data-sort-type='type' style='width:60px;' {$sc}>" . __('Type') . " <i class='fas fa-sort text-muted' aria-hidden='true'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='name' {$sc}>" . __('Name') . " <i class='fas fa-sort text-muted' aria-hidden='true'></i></th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th class='sprint-sortable' data-sort-type='status' {$sc}>" . __('Status') . " <i class='fas fa-sort text-muted' aria-hidden='true'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='priority' {$sc}>" . __('Priority') . " <i class='fas fa-sort text-muted' aria-hidden='true'></i></th>";
        if ($showOwner) {
            echo "<th class='sprint-sortable' data-sort-type='owner' {$sc}>" . __('Owner', 'sprint') . " <i class='fas fa-sort text-muted' aria-hidden='true'></i></th>";
        }
        echo "<th style='width:60px;'></th>";
        echo "</tr>";

        if (count($items) === 0) {
            $emptyCols = $showOwner ? 7 : 6;
            echo "<tr class='tab_bg_1'><td colspan='{$emptyCols}' class='sprint-dash-empty'>" .
                "<i class='fas fa-inbox' aria-hidden='true'></i>" .
                htmlescape($emptyMessage) . "</td></tr>";
        }

        foreach ($items as $row) {
            $linkedDisplay = !empty($row['linked_display'])
                ? $row['linked_display']
                : "<span class='text-muted' aria-hidden='true'>-</span>";

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
                . (SprintCustomer::canUseCredits()
                    ? ' data-customer-id="' . (int)($row['customer_id'] ?? 0) . '"'
                        . ' data-customer-name="' . htmlescape(SprintCustomer::getNameFor((int)($row['customer_id'] ?? 0))) . '"'
                        . ' data-credits="' . htmlescape(SprintCustomer::formatCredits($row['credits'] ?? 0)) . '"'
                        . ' data-credit-product-id="' . (int)($row['credit_product_id'] ?? 0) . '"'
                    : '')
                . ' data-is-fastlane="0"'
                . ' data-is-adhoc="' . ($isAdhoc ? 1 : 0) . '"'
                . ' data-item-tags="' . htmlescape(SprintItem::tagsToBlob($rowTags)) . '"'
                . ' data-note="' . htmlescape((string)($row['note'] ?? '')) . '"'
                . ' data-done-checks="' . htmlescape(implode('|', SprintAgility::checklist((string)($row['done_checks'] ?? '')))) . '"'
                . ' data-epic-id="' . (int)($row['epic_id'] ?? 0) . '"'
                // Quick edit writes the category back from this attribute — a
                // row without it would save "no category" over a real one.
                . ' data-category-id="' . (int)($row['category_id'] ?? 0) . '"'
                . ' data-category-name="' . htmlescape(SprintCategory::getFullNameFor((int)($row['category_id'] ?? 0))) . '"';

            $rowDeps = $depsById[(int)$row['item_id']] ?? [];
            $linkedOpenBadge = SprintItem::renderLinkedItemOpenBadge([
                'status'   => $row['raw_status'] ?? '',
                'itemtype' => $row['itemtype'] ?? ($row['itemtype_code'] ?? ''),
                'items_id' => (int)($row['items_id'] ?? 0),
            ]);
            echo "<tr {$dataAttrs}>";
            // Icon only: the "Linked item" column already spells the type out.
            echo "<td class='center sprint-nowrap'><i class='{$row['icon']} sprint-type-icon' style='color:{$row['color']};' title='"
                . htmlescape((string)$row['type_label']) . "' aria-hidden='true'></i>"
                . "<span class='visually-hidden'>" . htmlescape((string)$row['type_label']) . "</span></td>";
            $projectSuffix = SprintItem::renderParentProjectSuffix(
                (string)($row['itemtype'] ?? ($row['itemtype_code'] ?? '')),
                (int)($row['items_id'] ?? 0)
            );
            $capacityChip = SprintItem::renderCapacityChip($row['capacity'] ?? 0);
            echo "<td class='sprint-cell-name'><a href='{$row['url']}'>" . htmlescape($row['name']) . "</a>" . $projectSuffix . SprintItem::renderAdhocBadge($isAdhoc) . SprintCategory::renderPill((int)($row['category_id'] ?? 0)) . SprintItem::renderTagPills($rowTags) . SprintItem::renderDependencyBadge($rowDeps) . $linkedOpenBadge . $capacityChip . "</td>";
            echo "<td>{$linkedDisplay}</td>";
            echo "<td class='sprint-cell-status'>{$row['status']}</td>";
            echo "<td class='sprint-cell-priority'>{$row['priority']}</td>";
            if ($showOwner) {
                echo "<td class='sprint-cell-owner'>{$row['member']}</td>";
            }
            echo "<td class='text-center'>";
            echo "<button type='button' class='btn btn-sm btn-outline-secondary sprint-quick-edit-btn' "
                . "title='" . __s('Quick edit', 'sprint') . "' data-item-id='" . (int)$row['item_id'] . "' "
                . "aria-label='" . htmlescape(sprintf(__('Quick edit: %s', 'sprint'), (string)$row['name'])) . "'>"
                . "<i class='fas fa-pen' aria-hidden='true'></i></button>";
            echo "</td>";
            echo "</tr>";
        }

        echo "</table>";
        echo "</div>"; // .sprint-collapsible-body
        echo "</div>"; // .sprint-collapsible
    }

    /**
     * Team capacity table on the shared model (SprintMember::figuresForMember()):
     * Set, Available, Planned (regular + fastlane + helping) and Remaining,
     * with the same bar, badges, wording and legend as the team cards.
     */
    private static function showMemberCapacity(int $sprintId): void
    {
        $member  = new SprintMember();
        $members = $member->find(['plugin_sprint_sprints_id' => $sprintId], ['role ASC']);

        if (count($members) === 0) {
            return;
        }

        $roles      = SprintMember::getAllRoles();
        $sprintName = (string)(SprintCache::getObject(Sprint::class, $sprintId)?->fields['name'] ?? '');
        $sprintFastlaneTotal   = SprintFastlaneMember::getTotalFastlaneCapacityForSprint($sprintId);
        $sprintDependencyTotal = SprintItemDependency::getTotalOpenDependencyCapacityForSprint($sprintId);
        $pct = static fn(float $v): string => SprintMember::formatCapacity($v) . '%';

        echo "<div class='table-responsive sprint-dash-section'>";
        echo "<table class='tab_cadre_fixe sprint-themed sprint-cap-table'>";
        echo "<tr class='tab_bg_2'><th colspan='10'>" .
            "<i class='fas fa-users me-1' aria-hidden='true'></i>" .
            __('Team Capacity', 'sprint') .
            " &mdash; <i class='fas fa-bolt sprint-tone-fastlane' aria-hidden='true'></i> " .
            sprintf(__('Fastlane total: %s%%', 'sprint'), SprintMember::formatCapacity($sprintFastlaneTotal)) .
            " &mdash; <i class='fas fa-link sprint-tone-dependency' aria-hidden='true'></i> " .
            sprintf(__('Dependency total: %s%%', 'sprint'), SprintMember::formatCapacity($sprintDependencyTotal)) .
            "</th></tr>";
        self::capacityTableHeader();

        foreach ($members as $row) {
            $uid = (int)$row['users_id'];
            self::capacityTableRow(
                SprintMember::figuresForMember($sprintId, $row),
                SprintCache::userName($uid),
                (string)($roles[$row['role']] ?? $row['role']),
                $sprintName,
                $pct
            );
        }

        echo "<tr class='tab_bg_1'><td colspan='10'>" . SprintMember::renderCapacityLegend() . "</td></tr>";
        echo "</table></div>";
    }

    /** Column headers shared by the team and personal capacity tables. */
    private static function capacityTableHeader(): void
    {
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Member', 'sprint') . "</th>";
        echo "<th>" . __('Role', 'sprint') . "</th>";
        echo "<th title='" . __s('Agreed share in the member settings', 'sprint') . "'>" . __('Set', 'sprint') . "</th>";
        echo "<th title='" . __s('Set capacity minus leave and absence', 'sprint') . "'>" . __('Available', 'sprint') . "</th>";
        echo "<th><span class='sprint-cap-key sprint-cap-key-regular' aria-hidden='true'></span>" . __('Regular', 'sprint') . "</th>";
        echo "<th><span class='sprint-cap-key sprint-cap-key-fastlane' aria-hidden='true'></span>" . __('Fastlane', 'sprint') . "</th>";
        echo "<th><span class='sprint-cap-key sprint-cap-key-helping' aria-hidden='true'></span>" . __('Helping', 'sprint') . "</th>";
        echo "<th title='" . __s('Regular + fastlane + helping', 'sprint') . "'>" . __('Planned', 'sprint') . "</th>";
        echo "<th title='" . __s('Available minus planned, in percentage points', 'sprint') . "'>" . __('Remaining', 'sprint') . "</th>";
        echo "<th>" . __('Capacity', 'sprint') . "</th>";
        echo "</tr>";
    }

    /** One member row for the capacity tables, from the shared figures. */
    private static function capacityTableRow(array $f, string $userName, string $roleName, string $sprintName, callable $pct): void
    {
        echo "<tr class='tab_bg_1'>";
        echo "<td><i class='fas fa-user me-1 text-muted' aria-hidden='true'></i>" . htmlescape($userName) . "</td>";
        echo "<td>" . htmlescape($roleName) . "</td>";
        echo "<td class='center'>" . $pct($f['set']) . "</td>";
        echo "<td class='center'>" . $pct($f['available'])
            . ($f['leave'] > 0
                ? "<div class='text-muted sprint-small'>" . htmlescape(sprintf(__('%s not available', 'sprint'), $pct($f['leave']))) . "</div>"
                : '')
            . "</td>";
        echo "<td class='center'>" . $pct($f['regular']) . "</td>";
        echo "<td class='center'>" . $pct($f['fastlane']) . "</td>";
        echo "<td class='center'>" . $pct($f['helping'])
            . ($f['helping_resolved'] > 0
                ? "<div class='text-muted sprint-small'>" . htmlescape(sprintf(__('%s resolved', 'sprint'), $pct($f['helping_resolved']))) . "</div>"
                : '')
            . "</td>";
        echo "<td class='center'><strong>" . $pct($f['planned']) . "</strong></td>";
        echo "<td class='center'>" . SprintMember::renderRemainingCell($f) . "</td>";
        echo "<td style='min-width:180px;'>";
        echo SprintMember::renderFiguresBar($f, SprintMember::capacityDescription($f, $userName, $sprintName), 16, '8px');
        echo "</td>";
        echo "</tr>";
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
        $bodyId = self::collapsibleBodyId($collapseKey);
        echo "<button type='button' class='sprint-collapsible-header' aria-expanded='true' aria-controls='{$bodyId}'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron' aria-hidden='true'></i>";
        echo "<i class='fas fa-bolt sprint-head-icon sprint-tone-fastlane' aria-hidden='true'></i>";
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
            " <span class='text-muted fw-normal'>(" . $visibleCount . ")</span>" .
            " &mdash; " . $capText;
        if ($forUserId === null && $fastlaneCap > 0 && $sprintFastlaneTotal > $fastlaneCap) {
            echo " <span class='badge bg-danger ms-1'>"
                . sprintf(__('+%s%% overflow', 'sprint'), SprintMember::formatCapacity($sprintFastlaneTotal - $fastlaneCap))
                . "</span>";
        }
        echo "</span>";
        echo "</button>";
        echo "<div class='sprint-collapsible-body' id='{$bodyId}'>";

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

            $linkedDisplay = "<span class='text-muted' aria-hidden='true'>-</span>";
            if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
                $tmp = new SprintItem();
                $tmp->fields = $row;
                $linkedDisplay = $tmp->getLinkedItemDisplay();
            }

            $statusLabel = $statuses[$row['status']] ?? $row['status'];

            $rowTags = $tagsById[$itemId] ?? [];
            $rowDeps = $depsById[$itemId] ?? [];

            // sprint-row + sprint-cell-* let the quick-edit save refresh this
            // row in place; the bolt sits outside the link so the name update
            // does not wipe it.
            echo "<tr class='tab_bg_1 sprint-row' " . SprintItem::buildItemDataAttrs($row, $rowTags) . ">";
            echo "<td class='sprint-cell-name'><i class='fas fa-bolt me-1 sprint-tone-fastlane' aria-hidden='true'></i>"
                . "<a href='" . SprintItem::getFormURLWithID($itemId) . "'>" .
                htmlescape($row['name']) . "</a>"
                . SprintCategory::renderPill((int)($row['plugin_sprint_sprintcategories_id'] ?? 0))
                . SprintItem::renderTagPills($rowTags) . SprintItem::renderDependencyBadge($rowDeps) . "</td>";
            echo "<td>{$linkedDisplay}</td>";
            echo "<td class='sprint-cell-status'>" . self::statusPill((string)$row['status'], (string)$statusLabel) . "</td>";
            echo "<td>" . (count($memberLines) > 0 ? implode('<br>', $memberLines) :
                "<span class='text-muted'>" . __s('None', 'sprint') . "</span>") . "</td>";
            echo "<td class='center'><strong>" . SprintMember::formatCapacity($totalCap) . "%</strong></td>";
            echo "<td class='text-center'>";
            echo "<button type='button' class='btn btn-sm btn-outline-secondary sprint-quick-edit-btn' "
                . "title='" . __s('Quick edit', 'sprint') . "' data-item-id='{$itemId}' "
                . "aria-label='" . htmlescape(sprintf(__('Quick edit: %s', 'sprint'), (string)$row['name'])) . "'>"
                . "<i class='fas fa-pen' aria-hidden='true'></i></button>";
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
        $bodyId = self::collapsibleBodyId($collapseKey);
        echo "<button type='button' class='sprint-collapsible-header' aria-expanded='true' aria-controls='{$bodyId}'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron' aria-hidden='true'></i>";
        echo "<i class='fas fa-link sprint-head-icon sprint-tone-dependency' aria-hidden='true'></i>";
        echo "<span>" . __('Dependencies', 'sprint') .
            " <span class='text-muted fw-normal'>(" . $visibleCount . ")</span>" .
            " &mdash; " . $openCapText .
            "</span>";
        echo "</button>";
        echo "<div class='sprint-collapsible-body' id='{$bodyId}'>";

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
                    $helperLines[] = "<span class='text-muted text-decoration-line-through'>{$name} ({$capLabel}%)</span>";
                } else {
                    $openCap      += $cap;
                    $helperLines[] = "{$name} ({$capLabel}%)";
                }
            }

            $statusLabel = $statuses[$row['status']] ?? $row['status'];

            $ownerName = ((int)$row['users_id'] > 0)
                ? htmlescape(SprintCache::userName((int)$row['users_id']))
                : "<span class='sprint-unassigned'>" . __s('Unassigned', 'sprint') . "</span>";

            echo "<tr class='tab_bg_1'>";
            echo "<td><a href='" . SprintItem::getFormURLWithID($itemId) . "'>" .
                "<i class='fas fa-link me-1 sprint-tone-dependency' aria-hidden='true'></i>" .
                htmlescape($row['name']) . "</a></td>";
            echo "<td>{$ownerName}</td>";
            echo "<td class='sprint-cell-status'>" . self::statusPill((string)$row['status'], (string)$statusLabel) . "</td>";
            echo "<td>" . (count($helperLines) > 0 ? implode('<br>', $helperLines) :
                "<span class='text-muted'>" . __s('None', 'sprint') . "</span>") . "</td>";
            echo "<td class='center'><strong>" . SprintMember::formatCapacity($openCap) . "%</strong></td>";
            if ($forUserId !== null && $ownRow !== null) {
                $ownResolved = (int)($ownRow['is_resolved'] ?? 0) === 1;
                echo "<td class='center sprint-nowrap'>";
                if ($ownResolved) {
                    echo "<span class='sprint-badge sprint-badge-sm sprint-status-done'>"
                        . "<i class='fas fa-check' aria-hidden='true'></i> " . __s('Resolved', 'sprint') . "</span>";
                } else {
                    echo "<span class='sprint-badge sprint-badge-sm sprint-status-blocked'>"
                        . "<i class='fas fa-link' aria-hidden='true'></i> " . __s('Open', 'sprint') . "</span>";
                    echo "<form method='post' action='" . SprintItemDependency::getFormURL() . "' class='d-inline-block ms-1'>";
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
     * The status pill of a sprint item: colour by class only
     * (`.sprint-status-*` in css/sprint.css), never an inline hex.
     */
    private static function statusPill(string $status, string $label): string
    {
        return "<span class='sprint-badge sprint-status-" . htmlescape(str_replace('_', '-', $status)) . "'>"
            . htmlescape($label) . "</span>";
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
                'customer_id'   => (int)($row['plugin_sprint_sprintcustomers_id'] ?? 0),
                'credits'       => (float)($row['credits'] ?? 0),
                'credit_product_id' => (int)($row['plugin_sprint_sprintcreditproducts_id'] ?? 0),
                'users_id'      => (int)$row['users_id'],
                'is_adhoc'      => (int)($row['is_adhoc'] ?? 0),
                'note'          => (string)($row['note'] ?? ''),
                'done_checks'   => (string)($row['done_checks'] ?? ''),
                'epic_id'       => (int)($row['plugin_sprint_sprintepics_id'] ?? 0),
                'category_id'   => (int)($row['plugin_sprint_sprintcategories_id'] ?? 0),
                'member_name'   => ((int)$row['users_id'] > 0) ? SprintCache::userName((int)$row['users_id']) : '',
                'status'        => self::statusPill((string)$row['status'], (string)($statuses[$row['status']] ?? $row['status'])),
                'priority'      => $priorities[$row['priority']] ?? '',
                'member'        => self::getMemberName((int)$row['users_id']),
            ];
        }

        return $items;
    }

    /**
     * Capacity of the current user: the same summary as their team card
     * (shared model, bar, badges and wording), including what is still
     * proposed for them from the backlog.
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

        $memberData = reset($members);
        $roles      = SprintMember::getAllRoles();
        $roleName   = (string)($roles[$memberData['role']] ?? $memberData['role']);
        $sprintName = (string)(SprintCache::getObject(Sprint::class, $sprintId)?->fields['name'] ?? '');
        $proposed   = (float)(SprintMember::proposedCapacityBySprint($sprintId)[$userId] ?? 0.0);
        $figures    = SprintMember::figuresForMember($sprintId, $memberData, $proposed);

        echo "<div class='card sprint-dash-section'><div class='card-body'>";
        echo SprintUi::cardHeader(
            'fas fa-user',
            __('Your Capacity', 'sprint'),
            SprintCache::userName($userId) . ' · ' . $roleName
        );
        echo SprintMember::renderCapacitySummary($figures, SprintCache::userName($userId), $sprintName);
        echo SprintMember::renderCapacityLegend($figures['proposed'] > 0);
        echo "</div></div>";
    }

    private static function getMemberName(int $usersId): string
    {
        if ($usersId > 0) {
            return htmlescape(SprintCache::userName($usersId));
        }
        return "<span class='sprint-unassigned'>" . __s('Unassigned', 'sprint') . "</span>";
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
        $bodyId = self::collapsibleBodyId($collapseKey);
        echo "<button type='button' class='sprint-collapsible-header' aria-expanded='true' aria-controls='{$bodyId}'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron' aria-hidden='true'></i>";
        echo "<i class='fas fa-triangle-exclamation sprint-head-icon " . (count($risks) > 0 ? 'sprint-tone-danger' : 'sprint-tone-success') . "' aria-hidden='true'></i>";
        echo "<span>" . __('At risk', 'sprint') . " <span class='badge " . (count($risks) > 0 ? 'bg-danger' : 'bg-success') . "'>" . count($risks) . "</span></span>";
        echo "</button>";
        echo "<div class='sprint-collapsible-body' id='{$bodyId}'>";

        if (count($risks) === 0) {
            echo "<div class='sprint-atrisk-clear sprint-tone-success'><i class='fas fa-check-circle me-1' aria-hidden='true'></i>"
                . __s('Nothing at risk right now.', 'sprint') . "</div>";
        } else {
            echo "<div class='sprint-atrisk-list'>";
            foreach ($risks as $r) {
                echo "<div class='sprint-atrisk-row'>";
                echo "<div class='sprint-atrisk-main'>";
                echo "<a href='" . $r['url'] . "' class='sprint-atrisk-name'>" . htmlescape($r['name']) . "</a>";
                if ($r['project'] !== '' || $r['owner'] !== '' || !empty($r['helpers'])) {
                    echo "<span class='sprint-atrisk-meta'>";
                    if ($r['project'] !== '') {
                        echo "<span class='sprint-atrisk-project'><i class='fas fa-diagram-project' aria-hidden='true'></i> "
                            . htmlescape($r['project']) . "</span>";
                    }
                    if ($r['owner'] !== '') {
                        echo "<span class='sprint-atrisk-owner'><i class='fas fa-user' aria-hidden='true'></i> "
                            . htmlescape($r['owner']) . "</span>";
                    }
                    if (!empty($r['helpers'])) {
                        echo "<span class='sprint-atrisk-owner sprint-tone-dependency' title='"
                            . htmlescape(__('Waiting on', 'sprint')) . "'>"
                            . "<i class='fas fa-link' aria-hidden='true'></i> "
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
        $bodyId = self::collapsibleBodyId($collapseKey);
        echo "<button type='button' class='sprint-collapsible-header' aria-expanded='true' aria-controls='{$bodyId}'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron' aria-hidden='true'></i>";
        echo "<i class='fas fa-chart-column sprint-head-icon' aria-hidden='true'></i>";
        echo "<span>" . __('Velocity', 'sprint') . "</span>";
        echo "</button>";
        echo "<div class='sprint-collapsible-body' id='{$bodyId}'>";
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
        // Same entity as the sprint (another team's velocity says nothing
        // about this one), and never beyond what the session may see.
        $criteria = [
            'NOT'         => ['status' => Sprint::STATUS_CANCELLED],
            'entities_id' => (int)($sprint->fields['entities_id'] ?? 0),
        ] + getEntitiesRestrictCriteria(Sprint::getTable(), '', '', true);
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
     *
     * Bars (story points and item counts) share the left axis. The fastlane
     * capacity % is a different unit, so it sits in its own strip below the
     * bars with its own small scale instead of riding over them.
     */
    public static function renderVelocityChartBody(Sprint $sprint): void
    {
        $completed  = self::getVelocityWindow($sprint);
        $currentId  = (int)$sprint->getID();

        if (count($completed) === 0) {
            echo SprintChart::emptyState(
                'fas fa-chart-column',
                __('Velocity will appear once you have at least one completed sprint.', 'sprint'),
                ''
            );
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
                'running' => ($sp['status'] ?? '') === Sprint::STATUS_ACTIVE,
            ];

            // A sprint still in flight would drag the reference line down.
            if (($sp['status'] ?? '') === Sprint::STATUS_COMPLETED) {
                $sum += $pts;
                $sumCount++;
            }
        }
        $avg = $sumCount > 0 ? $sum / $sumCount : 0;

        // One colour per metric, the same as on the overview.
        $series = [
            'points'   => ['field' => 'pts',   'label' => __('Completed points', 'sprint'),         'color' => SprintChart::metricColor('completed')],
            'fastlane' => ['field' => 'fl',    'label' => __('Completed fastlane items', 'sprint'), 'color' => SprintChart::metricColor('fastlane')],
            'adhoc'    => ['field' => 'adhoc', 'label' => __('Adhoc items', 'sprint'),              'color' => SprintChart::metricColor('adhoc')],
        ];
        $capColor = SprintChart::metricColor('fastlane');
        $capLabel = __('Fastlane capacity %', 'sprint');
        $capFmt   = static fn(float $v): string => SprintMember::formatCapacity($v) . '%';

        $maxLeft = 1;
        $maxCap  = 0.0;
        $curPts  = 0;
        foreach ($bars as $b) {
            $maxLeft = max($maxLeft, $b['pts'], $b['fl'], $b['adhoc']);
            $maxCap  = max($maxCap, $b['cap']);
            if ($b['current']) {
                $curPts = (int)$b['pts'];
            }
        }
        $hasCap = $maxCap > 0;

        // === Geometry: main plot, then (when there is fastlane capacity) the strip ===
        $n      = count($bars);
        $num    = [SprintChart::class, 'num'];
        $w      = 1000;
        $padL   = 52;
        $padR   = 24;
        $padT   = 30;
        $x1     = $w - $padR;
        $slot   = ($x1 - $padL) / max(1, $n);
        $axis   = SprintChart::axisLabels(array_column($bars, 'label'), $slot);
        $yB     = $padT + 170;
        $stripT = $yB + 40;
        $stripB = $stripT + 44;
        $axisY  = $hasCap ? $stripB : $yB;
        $h      = (int)ceil($axisY + $axis['height'] + 8);

        $scale    = SprintChart::niceScale((float)max($maxLeft, $avg), 4, true);
        $capScale = SprintChart::niceScale($maxCap, 1);
        $xAt      = static fn(int $i): float => $padL + $slot * ($i + 0.5);
        $yAt      = static fn(float $v): float => SprintChart::yAt($v, $scale, $padT, $yB);
        $yCapAt   = static fn(float $v): float => SprintChart::yAt($v, $capScale, $stripT, $stripB);
        $barW     = min(24.0, $slot * 0.2);
        $gap      = 4.0;

        $aria = $sumCount > 0
            ? sprintf(
                __('Velocity of this sprint and the %1$d before it. This sprint: %2$d story points completed. Average of the completed sprints: %3$s.', 'sprint'),
                $n - 1,
                $curPts,
                number_format($avg, 1)
            )
            : sprintf(
                __('Velocity of this sprint and the %1$d before it. This sprint: %2$d story points completed. No completed sprint to average yet.', 'sprint'),
                $n - 1,
                $curPts
            );

        echo "<div class='sprint-chart-scope' data-chart-scope>";
        echo "<div class='sprint-card-hint'>"
            . htmlescape(__('This sprint and the six before it. Bars: completed story points, fastlane items and adhoc items. The strip below shows the total fastlane capacity on its own scale.', 'sprint'))
            . "</div>";
        echo "<div class='sprint-chart-box'>";
        echo SprintChart::svgOpen($aria, $w, $h);
        echo SprintChart::yAxis($scale, $padL, $x1, $padT, $yB);
        if ($hasCap) {
            echo "<text class='sprint-chart-label' x='{$padL}' y='" . $num($stripT - 25) . "'>" . htmlescape($capLabel) . "</text>";
            echo SprintChart::yAxis($capScale, $padL, $x1, $stripT, $stripB, $capFmt);
        }

        foreach ($bars as $i => $b) {
            if ($b['running']) {
                echo "<rect class='sprint-chart-running' x='" . $num($padL + $slot * $i + 2) . "' y='" . ($padT - 6) . "' width='"
                    . $num(max(0, $slot - 4)) . "' height='" . $num($axisY - $padT + 6) . "' rx='4'/>";
                echo "<text class='sprint-chart-label sprint-chart-label-sub' x='" . $num($xAt($i)) . "' y='" . ($padT - 12)
                    . "' text-anchor='middle'>" . htmlescape(__('running', 'sprint')) . "</text>";
            }
            echo SprintChart::axisLabel($axis, $i, $xAt($i), $axisY, $b['current'] ? 'is-current' : '');
        }

        // Three bars centred on the slot: points | fastlane | adhoc. A zero
        // draws no bar and no label, the tooltip and the table still name it.
        $k = 0;
        foreach ($series as $key => $s) {
            $color = htmlescape($s['color']);
            echo "<g class='sprint-chart-series' data-series='{$key}'>";
            foreach ($bars as $i => $b) {
                $v = (int)$b[$s['field']];
                if ($v <= 0) {
                    continue;
                }
                $x = $xAt($i) - 1.5 * $barW - $gap + $k * ($barW + $gap);
                $y = $yAt($v);
                echo "<rect x='" . $num($x) . "' y='" . $num($y) . "' width='" . $num($barW) . "' height='" . $num($yB - $y)
                    . "' rx='2' fill='{$color}'/>";
                echo "<text class='sprint-chart-value' x='" . $num($x + $barW / 2) . "' y='" . $num($y - 4)
                    . "' text-anchor='middle'>{$v}</text>";
            }
            echo "</g>";
            $k++;
        }

        // Story-points average reference line (completed sprints only).
        if ($sumCount > 0) {
            $ay = $num($yAt($avg));
            echo "<line class='sprint-chart-refline' x1='{$padL}' y1='{$ay}' x2='{$x1}' y2='{$ay}' stroke='"
                . htmlescape($series['points']['color']) . "'/>";
        }

        // Fastlane capacity %: own strip, own scale.
        if ($hasCap) {
            $color = htmlescape($capColor);
            $poly  = [];
            foreach ($bars as $i => $b) {
                $poly[] = $num($xAt($i)) . ',' . $num($yCapAt((float)$b['cap']));
            }
            echo "<g class='sprint-chart-series' data-series='cap'>";
            echo "<polyline class='sprint-chart-line' points='" . implode(' ', $poly) . "' stroke='{$color}'/>";
            foreach ($bars as $i => $b) {
                $cy = $yCapAt((float)$b['cap']);
                echo SprintChart::marker(
                    0,
                    $xAt($i),
                    $cy,
                    3.5,
                    $b['cap'] > 0
                        ? "class='sprint-chart-point' fill='{$color}'"
                        : "class='sprint-chart-point sprint-chart-point-zero' stroke='{$color}'"
                );
                echo "<text class='sprint-chart-value' x='" . $num($xAt($i)) . "' y='" . $num($cy - 8)
                    . "' text-anchor='middle'>" . htmlescape($capFmt((float)$b['cap'])) . "</text>";
            }
            echo "</g>";
        }

        // One hover and focus target per sprint over the full slot, on top.
        foreach ($bars as $i => $b) {
            $title = $b['label'] . ($b['running'] ? ' · ' . __('running', 'sprint') : '');
            $rows  = [];
            foreach ($series as $s) {
                $rows[] = [$s['label'], (string)(int)$b[$s['field']], $s['color']];
            }
            $rows[] = [$capLabel, $capFmt((float)$b['cap']), $capColor];
            $plain  = $title . ': ' . implode(', ', array_map(static fn($r) => $r[0] . ' ' . $r[1], $rows));
            $cx     = $num($xAt($i));
            echo "<g class='sprint-chart-slot'>";
            echo "<line class='sprint-chart-guide' x1='{$cx}' y1='{$padT}' x2='{$cx}' y2='" . $num($axisY) . "'/>";
            echo "<rect class='sprint-chart-hit' x='" . $num($padL + $slot * $i) . "' y='{$padT}' width='" . $num($slot)
                . "' height='" . $num($axisY - $padT) . "' tabindex='0'" . SprintChart::tipAttr($title, $rows) . ">"
                . "<title>" . htmlescape($plain) . "</title></rect>";
            echo "</g>";
        }
        echo "</svg></div>";

        $legend = [];
        foreach ($series as $key => $s) {
            $legend[] = ['label' => $s['label'], 'color' => $s['color'], 'mark' => 'bar', 'key' => $key];
        }
        if ($hasCap) {
            $legend[] = ['label' => $capLabel, 'color' => $capColor, 'mark' => 'line', 'shape' => 0, 'key' => 'cap'];
        }
        if ($sumCount > 0) {
            $legend[] = [
                'label' => sprintf(__('Avg points: %s', 'sprint'), number_format($avg, 1)),
                'color' => $series['points']['color'],
                'mark'  => 'ref',
            ];
        }
        echo SprintChart::legend($legend, true);

        // The same figures as a table, for screen readers and for copying.
        echo "<details class='sprint-chart-table'><summary>" . htmlescape(__('Show as table', 'sprint')) . "</summary>";
        echo "<div class='table-responsive'><table class='table table-sm align-middle'>";
        echo "<caption class='visually-hidden'>" . htmlescape(__('Velocity', 'sprint')) . "</caption>";
        echo "<thead><tr><th scope='col'>" . htmlescape(__('Sprint', 'sprint')) . "</th>";
        foreach ($series as $s) {
            echo "<th scope='col' class='text-end'>" . htmlescape($s['label']) . "</th>";
        }
        echo "<th scope='col' class='text-end'>" . htmlescape($capLabel) . "</th></tr></thead><tbody>";
        foreach ($bars as $b) {
            echo "<tr><th scope='row' class='fw-normal'>" . htmlescape($b['label'])
                . ($b['running'] ? " <span class='sprint-chip sprint-chip-info'>" . htmlescape(__('running', 'sprint')) . "</span>" : '')
                . "</th>";
            foreach ($series as $s) {
                echo "<td class='text-end'>" . (int)$b[$s['field']] . "</td>";
            }
            echo "<td class='text-end'>" . htmlescape($capFmt((float)$b['cap'])) . "</td></tr>";
        }
        echo "</tbody></table></div></details>";

        // What the average (the usual basis for a forecast) is built on.
        echo "<div class='sprint-small text-muted mt-1'><i class='fas fa-circle-info me-1' aria-hidden='true'></i>"
            . htmlescape($sumCount > 0
                ? sprintf(
                    _n(
                        'The average uses %d completed sprint in this window. Sprints still running or cancelled are left out. Story points only: fastlane and adhoc work are not in it.',
                        'The average uses %d completed sprints in this window. Sprints still running or cancelled are left out. Story points only: fastlane and adhoc work are not in it.',
                        $sumCount,
                        'sprint'
                    ),
                    $sumCount
                )
                : __('No completed sprint in this window yet, so there is no average to forecast with.', 'sprint'))
            . "</div>";
        echo "</div>"; // [data-chart-scope]
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
        $bodyId = self::collapsibleBodyId($collapseKey);
        echo "<button type='button' class='sprint-collapsible-header' aria-expanded='true' aria-controls='{$bodyId}'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron' aria-hidden='true'></i>";
        echo "<i class='fas fa-chart-area sprint-head-icon' aria-hidden='true'></i>";
        echo "<span>" . __('Burndown', 'sprint') . "</span>";
        echo "</button>";
        echo "<div class='sprint-collapsible-body' id='{$bodyId}'>";
        self::renderBurndownChartBody($sprint);
        echo "</div></div>";
    }

    /**
     * A date in the user's format (Html::convDate) without the year, for axis
     * ticks: "10-11-2026" gives "10-11", "2026-11-10" gives "11-10".
     */
    private static function shortDate(string $ymd): string
    {
        $full  = (string)Html::convDate($ymd);
        $short = preg_replace('/^\d{4}[-\/.]|[-\/.]\d{4}$/', '', $full);
        return ($short !== null && $short !== '') ? $short : $full;
    }

    /** Saturday or Sunday. */
    private static function isWeekend(\DateTimeInterface $day): bool
    {
        return (int)$day->format('N') >= 6;
    }

    /**
     * Light bands behind the weekend days of a day-based x-axis.
     *
     * @param \DateTimeInterface[] $days one per x position
     * @param callable             $xAt  fn(int): float, centre of day $i
     * @param float                $slot width of one day
     */
    private static function weekendBands(array $days, callable $xAt, float $slot, float $x0, float $x1, float $yTop, float $yBottom): string
    {
        $out = '';
        foreach ($days as $i => $day) {
            if (!self::isWeekend($day)) {
                continue;
            }
            $from = max($x0, $xAt($i) - $slot / 2);
            $to   = min($x1, $xAt($i) + $slot / 2);
            if ($to > $from) {
                $out .= "<rect class='sprint-chart-weekend' x='" . SprintChart::num($from) . "' y='" . SprintChart::num($yTop)
                    . "' width='" . SprintChart::num($to - $from) . "' height='" . SprintChart::num($yBottom - $yTop) . "'/>";
            }
        }
        return $out;
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
            echo SprintChart::emptyState(
                'fas fa-calendar-day',
                __('Set the sprint start and end date to see the burndown.', 'sprint'),
                ''
            );
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
            echo SprintChart::emptyState(
                'fas fa-chart-area',
                __('The sprint window is too short to plot a burndown.', 'sprint'),
                ''
            );
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
            echo SprintChart::emptyState(
                'fas fa-chart-area',
                __('No story points in this sprint yet to burn down.', 'sprint'),
                __('Give the sprint items story points to see the burndown.', 'sprint')
            );
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
        // One log query for every day instead of one per day.
        $stamps = [];
        foreach ($days as $i => $day) {
            if ($day <= $today) {
                $stamps[$i] = $day->format('Y-m-d') . ' 23:59:59';
            }
        }
        $timeline = SprintAudit::getItemStatusTimeline($itemIds, array_values($stamps), $currentStatuses);
        foreach ($days as $i => $day) {
            if ($day > $today) {
                $actual[$i]  = null;
                $blocked[$i] = null;
                continue;
            }
            $statusAt   = $timeline[$stamps[$i]] ?? [];
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

        // Ideal line: scope to zero, burning on working days only. Every
        // value is the end of that day, like the actuals, so the first day
        // still holds the full scope and a weekend day stays flat.
        $workTotal = 0;
        for ($i = 1; $i < $nDays; $i++) {
            if (!self::isWeekend($days[$i])) {
                $workTotal++;
            }
        }
        $ideal  = [];
        $worked = 0;
        for ($i = 0; $i < $nDays; $i++) {
            if ($i > 0 && !self::isWeekend($days[$i])) {
                $worked++;
            }
            $ideal[$i] = $workTotal > 0
                ? $scope * (1 - $worked / $workTotal)
                : $scope * (1 - $i / ($nDays - 1));
        }

        $hasBlocked = false;
        foreach ($blocked as $v) {
            if ($v !== null && $v > 0) { $hasBlocked = true; break; }
        }
        $lastIdx = null;
        foreach ($actual as $i => $v) {
            if ($v !== null) { $lastIdx = $i; }
        }

        // Items in review normally close at the next stand-up; show where the line lands then.
        $reviewPts = 0;
        foreach ($itemIds as $id) {
            if ($currentStatuses[$id] === SprintItem::STATUS_REVIEW) {
                $reviewPts += $points[$id];
            }
        }
        $hasProjection = ($reviewPts > 0 && $lastIdx !== null);

        // === Geometry ===
        $num   = [SprintChart::class, 'num'];
        $w     = 1000;
        $padL  = 52;
        $padR  = 24;
        $padT  = 26;
        $x1    = $w - $padR;
        $xStep = ($x1 - $padL) / ($nDays - 1);
        $axis  = SprintChart::axisLabels(array_map(static fn($d) => self::shortDate($d->format('Y-m-d')), $days), $xStep);
        $yB    = $padT + 200;
        $h     = (int)ceil($yB + $axis['height'] + 8);
        $scale = SprintChart::niceScale((float)$scope, 4, true);
        $xAt   = static fn(int $i): float => $padL + $xStep * $i;
        $yAt   = static fn(float $v): float => SprintChart::yAt($v, $scale, $padT, $yB);
        $r     = $nDays > 31 ? 2.5 : 3.5;

        $cRemaining  = SprintChart::metricColor('completed');
        $cWorkable   = SprintChart::seriesColor(2);
        $cBlocked    = SprintChart::metricColor('blocked');
        $cIdeal      = SprintChart::NEUTRAL;
        $cProjection = SprintChart::seriesColor(6);
        $lRemaining  = __('Remaining', 'sprint');
        $lWorkable   = __('Remaining excl. blocked', 'sprint');
        $lBlocked    = __('Blocked share', 'sprint');
        $lIdeal      = __('Ideal', 'sprint');
        $lProjection = __('Projected after review', 'sprint');

        $todayIdx = ($today >= $start && $today <= $end) ? (int)$start->diff($today)->days : null;
        if ($todayIdx !== null && $todayIdx >= $nDays) {
            $todayIdx = null;
        }
        $now = $lastIdx !== null ? (int)$actual[$lastIdx] : $scope;
        if ($today < $start) {
            $aria = sprintf(__('Burndown: the sprint has not started yet. The scope is %d story points.', 'sprint'), $scope);
        } elseif ($today > $end) {
            $aria = sprintf(__('Burndown: %1$d of %2$d story points remained at the end of the sprint.', 'sprint'), $now, $scope);
        } else {
            $left = (int)$today->diff($end)->days;
            $aria = sprintf(
                _n(
                    'Burndown: %1$d of %2$d story points remaining, %3$d day to go.',
                    'Burndown: %1$d of %2$d story points remaining, %3$d days to go.',
                    $left,
                    'sprint'
                ),
                $now,
                $scope,
                $left
            );
        }

        echo "<div class='sprint-chart-scope' data-chart-scope>";
        echo "<div class='sprint-card-hint'>"
            . htmlescape(__('Remaining story points against the ideal line. The ideal line only falls on working days, weekends are shaded.', 'sprint'))
            . "</div>";
        echo "<div class='sprint-chart-box'>";
        echo SprintChart::svgOpen($aria, $w, $h);
        echo self::weekendBands($days, $xAt, $xStep, $padL, $x1, $padT, $yB);
        echo SprintChart::yAxis($scale, $padL, $x1, $padT, $yB);
        foreach ($days as $i => $day) {
            echo SprintChart::axisLabel($axis, $i, $xAt($i), $yB, self::isWeekend($day) ? 'is-dim' : '');
        }

        // Today: dashed vertical with its label, clamped inside the plot.
        if ($todayIdx !== null) {
            $tx = $xAt($todayIdx);
            echo "<line class='sprint-chart-today' x1='" . $num($tx) . "' y1='" . ($padT - 4) . "' x2='" . $num($tx) . "' y2='" . $num($yB) . "'/>";
            echo "<text class='sprint-chart-value' x='" . $num(min(max($tx, $padL + 18), $x1 - 18)) . "' y='" . ($padT - 9)
                . "' text-anchor='middle'>" . htmlescape(__('Today', 'sprint')) . "</text>";
        }

        // Ideal line: a reference, so it dashes.
        $idealPts = [];
        foreach ($ideal as $i => $iv) {
            $idealPts[] = $num($xAt($i)) . ',' . $num($yAt($iv));
        }
        echo "<g class='sprint-chart-series' data-series='ideal'>";
        echo "<polyline class='sprint-chart-refline' points='" . implode(' ', $idealPts) . "' stroke='" . htmlescape($cIdeal) . "'/>";
        echo "</g>";

        // Blocked overlay: points stuck in blocked status never burn, so the
        // remaining line can't reach the ideal. A second line shows what's
        // left when blocked points are excluded ("workable remaining"), with
        // the gap shaded so the blocked share is visible per day.
        $actualLinePts = [];
        $adjLinePts    = [];
        foreach ($actual as $i => $v) {
            if ($v === null) { continue; }
            $x = $num($xAt($i));
            $actualLinePts[] = $x . ',' . $num($yAt((float)$v));
            $adjLinePts[]    = $x . ',' . $num($yAt((float)max($v - (int)$blocked[$i], 0)));
        }
        if ($hasBlocked && count($adjLinePts) > 1) {
            echo "<g class='sprint-chart-series' data-series='blocked'>";
            echo "<polygon class='sprint-chart-area sprint-chart-area-strong' points='"
                . implode(' ', array_merge($actualLinePts, array_reverse($adjLinePts))) . "' fill='" . htmlescape($cBlocked) . "'/>";
            echo "</g>";
            $color = htmlescape($cWorkable);
            echo "<g class='sprint-chart-series' data-series='workable'>";
            echo "<polyline class='sprint-chart-line' points='" . implode(' ', $adjLinePts) . "' stroke='{$color}' stroke-dasharray='5 4'/>";
            foreach ($actual as $i => $v) {
                if ($v === null || (int)$blocked[$i] <= 0) { continue; }
                echo SprintChart::marker(1, $xAt($i), $yAt((float)max($v - (int)$blocked[$i], 0)), $r - 0.5, "class='sprint-chart-point' fill='{$color}'");
            }
            echo "</g>";
        }

        // Actual line (only days up to today), with the area under it.
        if ($lastIdx !== null) {
            $color   = htmlescape($cRemaining);
            $firstI  = null;
            foreach ($actual as $i => $v) {
                if ($v !== null) { $firstI = $i; break; }
            }
            echo "<g class='sprint-chart-series' data-series='remaining'>";
            if (count($actualLinePts) > 1) {
                echo "<polygon class='sprint-chart-area' points='" . $num($xAt((int)$firstI)) . ',' . $num($yB) . ' '
                    . implode(' ', $actualLinePts) . ' ' . $num($xAt($lastIdx)) . ',' . $num($yB) . "' fill='{$color}'/>";
                echo "<polyline class='sprint-chart-line sprint-chart-line-main' points='" . implode(' ', $actualLinePts) . "' stroke='{$color}'/>";
            }
            foreach ($actual as $i => $v) {
                if ($v === null) { continue; }
                echo SprintChart::marker(0, $xAt($i), $yAt((float)$v), $i === $lastIdx ? $r + 1 : $r, "class='sprint-chart-point' fill='{$color}'");
            }
            // The figure that matters: where the line stands now.
            $lx = $xAt($lastIdx);
            echo "<text class='sprint-chart-value' x='" . $num($lx > $x1 - 30 ? $lx - 9 : $lx + 9) . "' y='" . $num($yAt((float)$actual[$lastIdx]) - 8)
                . "' text-anchor='" . ($lx > $x1 - 30 ? 'end' : 'start') . "'>" . (int)$actual[$lastIdx] . "</text>";
            echo "</g>";
        }

        $projTitle = '';
        if ($hasProjection) {
            $projVal = max((float)$actual[$lastIdx] - $reviewPts, 0);
            $projIdx = min($lastIdx + 1, $nDays - 1);
            $color   = htmlescape($cProjection);
            $projTitle = sprintf(
                __('Projected after review: %d points remaining (%d in review)', 'sprint'),
                (int)$projVal,
                $reviewPts
            );
            echo "<g class='sprint-chart-series' data-series='projection'>";
            echo "<line class='sprint-chart-line' x1='" . $num($xAt($lastIdx)) . "' y1='" . $num($yAt((float)$actual[$lastIdx]))
                . "' x2='" . $num($xAt($projIdx)) . "' y2='" . $num($yAt($projVal)) . "' stroke='{$color}' stroke-dasharray='3 3'/>";
            echo SprintChart::marker(2, $xAt($projIdx), $yAt($projVal), $r, "class='sprint-chart-point sprint-chart-point-zero' stroke='{$color}'");
            echo "</g>";
        }

        // One hover and focus target per day over the full slot, on top.
        foreach ($days as $i => $day) {
            $title = (string)Html::convDate($day->format('Y-m-d')) . ($i === $todayIdx ? ' · ' . __('Today', 'sprint') : '');
            $rows  = [];
            if ($actual[$i] !== null) {
                $rows[] = [$lRemaining, sprintf(__('%d points', 'sprint'), (int)$actual[$i]), $cRemaining];
                if ((int)$blocked[$i] > 0) {
                    $rows[] = [$lWorkable, sprintf(__('%d points', 'sprint'), max((int)$actual[$i] - (int)$blocked[$i], 0)), $cWorkable];
                    $rows[] = [__('Blocked', 'sprint'), sprintf(__('%d points', 'sprint'), (int)$blocked[$i]), $cBlocked];
                }
            }
            $rows[] = [$lIdeal, number_format($ideal[$i], 1), $cIdeal];
            if ($hasProjection && $i === $lastIdx) {
                $rows[] = [$lProjection, sprintf(__('%d points', 'sprint'), (int)max((float)$actual[$lastIdx] - $reviewPts, 0)), $cProjection];
            }
            $plain = $title . ': ' . implode(', ', array_map(static fn($row) => $row[0] . ' ' . $row[1], $rows));
            $from  = max($padL, $xAt($i) - $xStep / 2);
            $to    = min($x1, $xAt($i) + $xStep / 2);
            $cx    = $num($xAt($i));
            echo "<g class='sprint-chart-slot'>";
            echo "<line class='sprint-chart-guide' x1='{$cx}' y1='{$padT}' x2='{$cx}' y2='" . $num($yB) . "'/>";
            echo "<rect class='sprint-chart-hit' x='" . $num($from) . "' y='{$padT}' width='" . $num($to - $from)
                . "' height='" . $num($yB - $padT) . "' tabindex='0'" . SprintChart::tipAttr($title, $rows) . ">"
                . "<title>" . htmlescape($plain) . "</title></rect>";
            echo "</g>";
        }
        echo "</svg></div>";

        $legend = [['label' => $lRemaining, 'color' => $cRemaining, 'mark' => 'line', 'shape' => 0, 'key' => 'remaining']];
        if ($hasBlocked) {
            $legend[] = ['label' => $lWorkable, 'color' => $cWorkable, 'mark' => 'ref', 'key' => 'workable'];
            $legend[] = ['label' => $lBlocked, 'color' => $cBlocked, 'mark' => 'bar', 'key' => 'blocked'];
        }
        $legend[] = ['label' => $lIdeal, 'color' => $cIdeal, 'mark' => 'ref', 'key' => 'ideal'];
        if ($hasProjection) {
            $legend[] = ['label' => $lProjection, 'color' => $cProjection, 'mark' => 'ref', 'key' => 'projection'];
        }
        echo "<div class='sprint-chart-foot'>";
        echo SprintChart::legend($legend, true);
        echo "<span class='text-muted sprint-small'>" . htmlescape(sprintf(__('Scope: %d points', 'sprint'), $scope))
            . ($projTitle !== '' ? ' · ' . htmlescape($projTitle) : '') . "</span>";
        echo "</div>";

        // The same figures as a table, for screen readers and for copying.
        echo "<details class='sprint-chart-table'><summary>" . htmlescape(__('Show as table', 'sprint')) . "</summary>";
        echo "<div class='table-responsive'><table class='table table-sm align-middle'>";
        echo "<caption class='visually-hidden'>" . htmlescape(__('Burndown', 'sprint')) . "</caption>";
        echo "<thead><tr><th scope='col'>" . htmlescape(__('Date')) . "</th>"
            . "<th scope='col' class='text-end'>" . htmlescape($lRemaining) . "</th>"
            . "<th scope='col' class='text-end'>" . htmlescape(__('Blocked', 'sprint')) . "</th>"
            . "<th scope='col' class='text-end'>" . htmlescape($lIdeal) . "</th></tr></thead><tbody>";
        foreach ($days as $i => $day) {
            $dash = "<span class='text-muted'>—</span>";
            echo "<tr" . (self::isWeekend($day) ? " class='text-muted'" : '') . "><th scope='row' class='fw-normal'>"
                . htmlescape((string)Html::convDate($day->format('Y-m-d')))
                . ($i === $todayIdx ? " <span class='sprint-chip sprint-chip-info'>" . htmlescape(__('Today', 'sprint')) . "</span>" : '')
                . "</th>";
            echo "<td class='text-end'>" . ($actual[$i] === null ? $dash : (int)$actual[$i]) . "</td>";
            echo "<td class='text-end'>" . ($blocked[$i] === null ? $dash : (int)$blocked[$i]) . "</td>";
            echo "<td class='text-end'>" . htmlescape(number_format($ideal[$i], 1)) . "</td></tr>";
        }
        echo "</tbody></table></div></details>";
        echo "</div>"; // [data-chart-scope]
    }

    private static function showMemberActivityChart(int $sprintId): void
    {
        $collapseKey = 'dash-activity-' . (int)$sprintId;
        echo "<div class='sprint-collapsible' data-sprint-collapse-key='" . htmlescape($collapseKey) . "'>";
        $bodyId = self::collapsibleBodyId($collapseKey);
        echo "<button type='button' class='sprint-collapsible-header' aria-expanded='true' aria-controls='{$bodyId}'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron' aria-hidden='true'></i>";
        echo "<i class='fas fa-chart-line sprint-head-icon' aria-hidden='true'></i>";
        echo "<span>" . __('Team activity', 'sprint') . "</span>";
        echo "</button>";
        echo "<div class='sprint-collapsible-body' id='{$bodyId}'>";

        echo "<div class='sprint-activity-chart-wrap' data-sprint-id='" . (int)$sprintId . "'>";
        self::renderActivityChartFragment($sprintId, null, null);
        echo "</div>";

        echo "</div>"; // .sprint-collapsible-body
        echo "</div>"; // .sprint-collapsible
    }

    /**
     * Render the activity chart inner fragment (range picker + SVG + legend).
     * Reused on initial render and by the AJAX refresh endpoint. Tooltip and
     * legend isolate come from the shared charts block in js/sprint.js, which
     * is delegated, so a refreshed fragment needs no re-binding.
     */
    public static function renderActivityChartFragment(int $sprintId, ?\DateTimeImmutable $overrideFrom, ?\DateTimeImmutable $overrideTo): void
    {
        $data    = SprintAudit::getMemberActivity($sprintId, $overrideFrom, $overrideTo);
        $dates   = array_values($data['dates']);
        $members = array_values($data['members']);

        echo "<div class='sprint-card-hint'>"
            . htmlescape(__('Audit-log events per member per day — spot uneven workloads.', 'sprint'))
            . "</div>";

        self::renderActivityRangePicker($sprintId, $dates, $overrideFrom, $overrideTo);

        if (count($dates) < 1 || count($members) === 0) {
            // Explain the empty state (no members, past retention window, no logged actions yet).
            echo SprintChart::emptyState(
                'fas fa-chart-line',
                self::diagnoseActivityEmptyReason($sprintId, $dates, $members),
                ''
            );
            return;
        }

        // Members arrive most active first. Past the palette the least active
        // ones fold into one "Other" line instead of reusing a colour.
        if (count($members) > SprintChart::SERIES_COUNT) {
            $rest    = array_splice($members, SprintChart::SERIES_COUNT - 1);
            $folded  = array_fill(0, count($dates), 0);
            $foldSum = 0;
            foreach ($rest as $m) {
                foreach ($m['counts'] as $i => $c) {
                    $folded[$i] = ($folded[$i] ?? 0) + (int)$c;
                }
                $foldSum += (int)$m['total'];
            }
            $members[] = [
                'name'   => sprintf(_n('Other (%d member)', 'Other (%d members)', count($rest), 'sprint'), count($rest)),
                'counts' => $folded,
                'total'  => $foldSum,
                'other'  => true,
            ];
        }
        $max        = 1;
        $grandTotal = 0;
        foreach ($members as $idx => $m) {
            $members[$idx]['color'] = !empty($m['other']) ? SprintChart::OTHER : SprintChart::seriesColor($idx);
            $max         = max($max, ...array_map('intval', $m['counts'] ?: [0]));
            $grandTotal += (int)$m['total'];
        }

        $days = [];
        foreach ($dates as $d) {
            try {
                $days[] = new \DateTimeImmutable((string)$d);
            } catch (\Exception $e) {
                $days[] = new \DateTimeImmutable('today');
            }
        }

        // === Geometry ===
        $num    = [SprintChart::class, 'num'];
        $nDates = count($dates);
        $w      = 1000;
        $padL   = 52;
        $padR   = 24;
        $padT   = 18;
        $x1     = $w - $padR;
        $xStep  = $nDates > 1 ? ($x1 - $padL) / ($nDates - 1) : (float)($x1 - $padL);
        $axis   = SprintChart::axisLabels(array_map(static fn($d) => self::shortDate((string)$d), $dates), $xStep);
        $yB     = $padT + 190;
        $h      = (int)ceil($yB + $axis['height'] + 8);
        $scale  = SprintChart::niceScale((float)$max, 4, true);
        $xAt    = static fn(int $i): float => $nDates > 1 ? $padL + $xStep * $i : ($padL + $x1) / 2;
        $yAt    = static fn(float $v): float => SprintChart::yAt($v, $scale, $padT, $yB);
        $r      = $nDates > 31 ? 2.5 : 3.5;

        $fromLabel = (string)Html::convDate($dates[0]);
        $toLabel   = (string)Html::convDate($dates[$nDates - 1]);
        $aria = sprintf(
            __('Team activity from %1$s to %2$s: %3$d logged events. Most active: %4$s.', 'sprint'),
            $fromLabel,
            $toLabel,
            $grandTotal,
            (string)$members[0]['name']
        );

        echo "<div class='sprint-member-activity' data-chart-scope>";
        echo "<div class='sprint-chart-box'>";
        echo SprintChart::svgOpen($aria, $w, $h);
        if ($nDates > 1) {
            echo self::weekendBands($days, $xAt, $xStep, $padL, $x1, $padT, $yB);
        }
        echo SprintChart::yAxis($scale, $padL, $x1, $padT, $yB);
        foreach ($days as $i => $day) {
            echo SprintChart::axisLabel($axis, $i, $xAt($i), $yB, self::isWeekend($day) ? 'is-dim' : '');
        }

        // One line per member, with its own marker shape. A run of zeros
        // draws no line: it would only stack every member on the baseline.
        foreach ($members as $idx => $m) {
            $color = htmlescape($m['color']);
            $d     = '';
            $prev  = null;
            foreach ($m['counts'] as $i => $c) {
                $c   = (int)$c;
                $gap = $prev === null || ($prev <= 0 && $c <= 0);
                $d  .= ($gap ? 'M' : 'L') . $num($xAt($i)) . ',' . $num($yAt($c)) . ' ';
                $prev = $c;
            }
            echo "<g class='sprint-chart-series' data-series='" . (int)$idx . "'>";
            echo "<path class='sprint-chart-line' d='" . trim($d) . "' stroke='{$color}'/>";
            foreach ($m['counts'] as $i => $c) {
                if ((int)$c <= 0) {
                    continue;
                }
                echo SprintChart::marker($idx, $xAt($i), $yAt((int)$c), $r, "class='sprint-chart-point' fill='{$color}'");
                echo "<text class='sprint-chart-value sprint-chart-pointlabel' x='" . $num($xAt($i)) . "' y='" . $num($yAt((int)$c) - 8)
                    . "' text-anchor='middle'>" . (int)$c . "</text>";
            }
            echo "</g>";
        }

        // One hover and focus target per day over the full slot, on top.
        foreach ($days as $i => $day) {
            $title = (string)Html::convDate($dates[$i]);
            $rows  = [];
            foreach ($members as $m) {
                if ((int)($m['counts'][$i] ?? 0) > 0) {
                    $rows[] = [(string)$m['name'], (string)(int)$m['counts'][$i], $m['color']];
                }
            }
            usort($rows, static fn($a, $b) => (int)$b[1] <=> (int)$a[1]);
            if (!$rows) {
                $rows[] = [__('No activity', 'sprint'), ''];
            }
            $plain = $title . ': ' . implode(', ', array_map(static fn($row) => trim($row[0] . ' ' . $row[1]), $rows));
            $from  = max($padL, $xAt($i) - $xStep / 2);
            $to    = min($x1, $xAt($i) + $xStep / 2);
            $cx    = $num($xAt($i));
            echo "<g class='sprint-chart-slot'>";
            echo "<line class='sprint-chart-guide' x1='{$cx}' y1='{$padT}' x2='{$cx}' y2='" . $num($yB) . "'/>";
            echo "<rect class='sprint-chart-hit' x='" . $num($from) . "' y='{$padT}' width='" . $num($to - $from)
                . "' height='" . $num($yB - $padT) . "' tabindex='0'" . SprintChart::tipAttr($title, $rows) . ">"
                . "<title>" . htmlescape($plain) . "</title></rect>";
            echo "</g>";
        }
        echo "</svg></div>";

        // Legend: a button per member, click isolates the line and shows its values.
        $legend = [];
        foreach ($members as $idx => $m) {
            $legend[] = [
                'label' => (string)$m['name'],
                'color' => $m['color'],
                'mark'  => 'line',
                'shape' => $idx,
                'key'   => $idx,
                'value' => (string)(int)$m['total'],
            ];
        }
        echo SprintChart::legend($legend, true);

        // The same figures as a table, for screen readers and for copying.
        echo "<details class='sprint-chart-table'><summary>" . htmlescape(__('Show as table', 'sprint')) . "</summary>";
        echo "<div class='table-responsive'><table class='table table-sm align-middle'>";
        echo "<caption class='visually-hidden'>" . htmlescape(__('Team activity', 'sprint')) . "</caption>";
        echo "<thead><tr><th scope='col'>" . htmlescape(__('Date')) . "</th>";
        foreach ($members as $m) {
            echo "<th scope='col' class='text-end'>" . htmlescape((string)$m['name']) . "</th>";
        }
        echo "</tr></thead><tbody>";
        foreach ($days as $i => $day) {
            echo "<tr" . (self::isWeekend($day) ? " class='text-muted'" : '') . "><th scope='row' class='fw-normal'>"
                . htmlescape((string)Html::convDate($dates[$i])) . "</th>";
            foreach ($members as $m) {
                echo "<td class='text-end'>" . (int)($m['counts'][$i] ?? 0) . "</td>";
            }
            echo "</tr>";
        }
        echo "<tr><th scope='row'>" . htmlescape(__('Total', 'sprint')) . "</th>";
        foreach ($members as $m) {
            echo "<td class='text-end fw-bold'>" . (int)$m['total'] . "</td>";
        }
        echo "</tr></tbody></table></div></details>";

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
            . "class='sprint-activity-range sprint-small d-flex flex-wrap align-items-end gap-2 mb-2' "
            . "data-sprint-id='" . (int)$sprintId . "'>";
        echo Html::hidden('id', ['value' => (int)$sprintId]);
        echo Html::hidden('forcetab', ['value' => $tab]);
        echo "<span class='me-auto text-muted sprint-small align-self-center'>"
            . __('Activity range follows the sprint window.', 'sprint')
            . "</span>";
        echo "<label class='d-flex flex-column gap-1'>"
            . "<span class='text-muted'>" . __('From', 'sprint') . "</span>"
            . "<input type='date' class='form-control form-control-sm' name='activity_from' "
            . "value='" . htmlescape($fromStr) . "' min='{$minStr}' max='{$maxStr}'>"
            . "</label>";
        echo "<label class='d-flex flex-column gap-1'>"
            . "<span class='text-muted'>" . __('To', 'sprint') . "</span>"
            . "<input type='date' class='form-control form-control-sm' name='activity_to' "
            . "value='" . htmlescape($toStr) . "' min='{$minStr}' max='{$maxStr}'>"
            . "</label>";
        echo "<button type='submit' class='btn btn-sm btn-primary'>"
            . "<i class='fas fa-search me-1' aria-hidden='true'></i>" . __s('Apply', 'sprint') . "</button>";
        if ($isCustom) {
            echo "<button type='button' class='btn btn-sm btn-outline-secondary' "
                . "data-sprint-action='activity-reset' data-sprint-id='" . (int)$sprintId . "'>"
                . "<i class='fas fa-rotate-left me-1' aria-hidden='true'></i>" . __s('Reset to sprint range', 'sprint') . "</button>";
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
