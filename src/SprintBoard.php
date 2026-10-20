<?php

namespace GlpiPlugin\Sprint;

use CommonGLPI;
use Session;
use Plugin;

/**
 * Kanban board tab on a Sprint. Not a CommonDBTM: a virtual view over the
 * sprint's SprintItem rows grouped by status. Cards drag between columns
 * (native HTML5 DnD) and persist via ajax/updatestatus.php, which enforces
 * the per-item permission.
 */
class SprintBoard extends CommonGLPI
{
    public static $rightname = 'plugin_sprint_item';

    public static function getTypeName($nb = 0): string
    {
        return _n('Board', 'Board', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-columns';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Sprint) {
            $count = countElementsInTable(
                SprintItem::getTable(),
                ['plugin_sprint_sprints_id' => $item->getID()]
            );
            return self::createTabEntry(self::getTypeName(1), $count);
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
     * Workflow columns, in board order, with their accent colour.
     */
    private static function columns(): array
    {
        return [
            SprintItem::STATUS_TODO        => '#6c757d',
            SprintItem::STATUS_IN_PROGRESS => '#0d6efd',
            SprintItem::STATUS_REVIEW      => '#6f42c1',
            SprintItem::STATUS_DEPENDENCY  => '#20c997',
            SprintItem::STATUS_BLOCKED     => '#dc3545',
            SprintItem::STATUS_DONE        => '#198754',
        ];
    }

    public static function showForSprint(Sprint $sprint): void
    {
        $sprintId = $sprint->getID();
        $fullEdit = SprintItem::canUpdate();
        $ownEdit  = Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS);
        $canedit  = $fullEdit || $ownEdit;
        $uid      = (int)Session::getLoginUserID();

        Sprint::renderHeaderBar($sprint);

        $si    = new SprintItem();
        $items = $si->find(
            ['plugin_sprint_sprints_id' => $sprintId],
            ['sort_order ASC', 'priority DESC']
        );

        $statuses = SprintItem::getAllStatuses();
        $columns  = self::columns();

        $byStatus = [];
        foreach (array_keys($columns) as $st) {
            $byStatus[$st] = [];
        }
        foreach ($items as $row) {
            $st = (string)($row['status'] ?? SprintItem::STATUS_TODO);
            if (!isset($byStatus[$st])) {
                // Unknown/legacy status falls into To-do so it's never hidden.
                $st = SprintItem::STATUS_TODO;
            }
            $byStatus[$st][] = $row;
        }

        $itemIds  = array_map(fn($r) => (int)$r['id'], $items);
        $tagsById = SprintItem::getTagsForItems($itemIds);
        $depsById = SprintItemDependency::getOpenSummariesForItems($itemIds);

        $memberCount = countElementsInTable(
            SprintMember::getTable(),
            ['plugin_sprint_sprints_id' => $sprintId]
        );
        $wipLimits = SprintAgility::getWipLimits($sprint->fields);

        echo "<div class='sprint-kanban-intro text-muted' style='padding:10px 4px;'>"
            . "<i class='" . self::getIcon() . "' style='margin-right:6px;'></i>"
            . ($canedit
                ? __('Drag a card to another column to change its status.', 'sprint')
                : __('Sprint items grouped by status.', 'sprint'))
            . "</div>";

        self::renderViewTools($items, $tagsById, $columns, $statuses);

        echo "<div class='sprint-kanban' data-sprint-id='" . (int)$sprintId . "'>";
        foreach ($columns as $status => $color) {
            $label = $statuses[$status] ?? $status;
            $cards = $byStatus[$status];
            $limit = (int)($wipLimits[$status] ?? 0);
            // In-progress without a configured limit falls back to team size.
            $teamLimit = ($limit <= 0 && $status === SprintItem::STATUS_IN_PROGRESS) ? $memberCount : 0;
            $wip = self::wipState($cards, $limit, $teamLimit);
            // The WIP figures are re-derived client-side after every move,
            // revert and filter change (renderBoardScript() → recount()); the
            // server render is the no-JS / first-paint version of the same rule.
            echo "<section class='sprint-kanban-col" . ($wip['over'] ? ' sk-over-wip' : '') . "'"
                . " data-status='" . htmlescape($status) . "'"
                . " data-wip-limit='" . $limit . "' data-wip-team='" . (int)$teamLimit . "'"
                . " aria-label='" . htmlescape($label) . "'"
                . " style='--sk-accent:" . htmlescape($color) . ";'>";
            echo "<div class='sprint-kanban-col-header'>"
                . "<span class='sprint-kanban-dot' aria-hidden='true' style='background:" . htmlescape($color) . ";'></span>"
                . "<span class='sprint-kanban-col-title'>" . htmlescape($label) . "</span>"
                . "<span class='sprint-kanban-count'>" . htmlescape(sprintf(_n('%d item', '%d items', count($cards), 'sprint'), count($cards))) . "</span>"
                . "</div>";
            if ($limit > 0 || $teamLimit > 0) {
                echo "<div class='sk-col-wip' title='" . __s('Work in progress (WIP): the most items one person has in this column at the same time.', 'sprint')
                    . ' ' . __s('Fastlane items do not count towards the WIP limit.', 'sprint') . "'>"
                    . "<i class='fas fa-gauge' aria-hidden='true'></i> "
                    . htmlescape($limit > 0
                        ? sprintf(__('WIP: max %d per person', 'sprint'), $limit)
                        : sprintf(__('WIP: max %d (team size)', 'sprint'), $teamLimit))
                    . "</div>";
            }
            echo "<div class='sk-col-warning' role='status'" . ($wip['over'] ? '' : ' hidden') . ">"
                . ($wip['over']
                    ? "<i class='fas fa-triangle-exclamation' aria-hidden='true'></i> " . htmlescape($wip['message'])
                    : '')
                . "</div>";
            echo "<div class='sprint-kanban-body' data-status='" . htmlescape($status) . "'>";
            foreach ($cards as $row) {
                self::renderCard($row, $tagsById, $depsById, $fullEdit, $ownEdit, $uid, $columns, $statuses);
            }
            echo "</div>"; // body
            echo "</section>"; // col
        }
        echo "</div>"; // kanban

        self::renderBoardScript();
        // Quick-edit modals opened by the card and linked-item pencil buttons.
        if ($canedit) {
            SprintItem::renderQuickEditUI($sprintId);
        }
        SprintItem::renderLinkedQuickEditUI();
    }

    /**
     * WIP verdict for one column. A per-person limit flags the busiest owner
     * (unassigned and fastlane cards are exempt); the team fallback compares
     * the column's non-fastlane cards with the team size. The board script's
     * recount() applies the same rule in the browser.
     *
     * @return array{over:bool,message:string}
     */
    private static function wipState(array $cards, int $limit, int $teamLimit): array
    {
        $perOwner = [];
        $counted  = 0;
        foreach ($cards as $row) {
            if ((int)($row['is_fastlane'] ?? 0) === 1) {
                continue;
            }
            $counted++;
            $ownerKey = (int)($row['users_id'] ?? 0);
            if ($ownerKey > 0) {
                $perOwner[$ownerKey] = ($perOwner[$ownerKey] ?? 0) + 1;
            }
        }
        if ($limit > 0 && $perOwner && max($perOwner) > $limit) {
            $max   = max($perOwner);
            $owner = (int)array_search($max, $perOwner, true);
            return [
                'over'    => true,
                'message' => sprintf(
                    __('%1$s has %2$d items (max %3$d per person)', 'sprint'),
                    SprintCache::userName($owner),
                    $max,
                    $limit
                ),
            ];
        }
        if ($limit <= 0 && $teamLimit > 0 && $counted > $teamLimit) {
            return [
                'over'    => true,
                'message' => sprintf(__('WIP limit exceeded: %1$d items, limit %2$d', 'sprint'), $counted, $teamLimit),
            ];
        }
        return ['over' => false, 'message' => ''];
    }

    private static function renderCard(
        array $row,
        array $tagsById,
        array $depsById,
        bool $fullEdit,
        bool $ownEdit,
        int $uid,
        array $columns,
        array $statuses
    ): void {
        $itemId    = (int)$row['id'];
        $isOwn     = (int)($row['users_id'] ?? 0) === $uid;
        $draggable = $fullEdit || ($ownEdit && $isOwn);

        $rowTags = $tagsById[$itemId] ?? [];
        $rowDeps = $depsById[$itemId] ?? [];

        $linkedDisplay = '';
        if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
            $tmp = new SprintItem();
            $tmp->fields = $row;
            $linkedDisplay = $tmp->getLinkedItemDisplay();
        }

        $ownerId   = (int)($row['users_id'] ?? 0);
        $ownerName = $ownerId > 0 ? SprintCache::userName($ownerId) : '';
        $points    = (int)($row['story_points'] ?? 0);
        $capacity  = (float)($row['capacity'] ?? 0);
        $isFastlane = (int)($row['is_fastlane'] ?? 0) === 1;

        // Quick-edit data-* attrs (includes data-item-id — don't duplicate it).
        $dataAttrs = SprintItem::buildItemDataAttrs($row, $rowTags);
        echo "<div class='sprint-kanban-card' {$dataAttrs} "
            . "data-status='" . htmlescape((string)($row['status'] ?? '')) . "'"
            . ($draggable ? " draggable='true'" : " data-locked='1'")
            . ">";

        echo "<div class='sk-card-title'>";
        if ($isFastlane) {
            echo "<i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;' title='" . __s('Fastlane', 'sprint') . "' aria-hidden='true'></i>"
                . "<span class='visually-hidden'>" . __s('Fastlane', 'sprint') . "</span>";
        }
        echo "<a href='" . SprintItem::getFormURLWithID($itemId) . "'>" . htmlescape($row['name']) . "</a>";
        echo SprintCategory::renderPill((int)($row['plugin_sprint_sprintcategories_id'] ?? 0));
        echo SprintItem::renderTagPills($rowTags);
        echo SprintItem::renderDependencyBadge($rowDeps);
        echo SprintItem::renderLinkedItemOpenBadge($row);
        if ($draggable) {
            echo "<button type='button' class='btn btn-sm btn-link p-0 sk-card-edit sprint-quick-edit-btn' "
                . "title='" . __s('Quick edit', 'sprint') . "' "
                . "aria-label='" . htmlescape(sprintf(__('Quick edit: %s', 'sprint'), (string)$row['name'])) . "'>"
                . "<i class='fas fa-pen' aria-hidden='true'></i></button>";
        }
        echo "</div>";

        if ($linkedDisplay !== '') {
            echo "<div class='sk-card-linked'>" . $linkedDisplay
                . SprintItem::renderParentProjectSuffix((string)($row['itemtype'] ?? ''), (int)($row['items_id'] ?? 0))
                . "</div>";
        }

        echo "<div class='sk-card-meta'>";
        if ($ownerName !== '') {
            echo "<span class='sk-card-owner'><i class='fas fa-user'></i> " . htmlescape($ownerName) . "</span>";
        } else {
            echo "<span class='sk-card-owner text-muted fst-italic'>" . __('Unassigned', 'sprint') . "</span>";
        }
        echo "<span class='sk-card-stats'>";
        echo "<span title='" . __s('Story Points', 'sprint') . "'><i class='fas fa-bullseye' aria-hidden='true'></i>"
            . "<span class='visually-hidden'>" . __s('Story Points', 'sprint') . ":</span> " . $points . "</span>";
        if ($capacity > 0) {
            echo " <span title='" . __s('Capacity (%)', 'sprint') . "'><i class='fas fa-gauge-high' aria-hidden='true'></i>"
                . "<span class='visually-hidden'>" . __s('Capacity (%)', 'sprint') . ":</span> " . SprintMember::formatCapacity($capacity) . "%</span>";
        }
        echo "</span>";
        if ($draggable) {
            // Keyboard / touch alternative to drag & drop: same move flow
            // (DoD, linked-item and transition checks) as a drop.
            $current = (string)($row['status'] ?? '');
            echo "<div class='dropdown sk-card-move'>"
                . "<button type='button' class='btn btn-sm btn-outline-secondary dropdown-toggle sk-card-move-btn' "
                . "data-bs-toggle='dropdown' aria-expanded='false' "
                // Fixed strategy: the board scrolls horizontally, which would clip an absolute menu.
                . "data-bs-popper-config='{&quot;strategy&quot;:&quot;fixed&quot;}' "
                . "title='" . __s('Change status', 'sprint') . "' "
                . "aria-label='" . htmlescape(sprintf(__('Change status: %s', 'sprint'), (string)$row['name'])) . "'>"
                . "<i class='fas fa-right-left' aria-hidden='true'></i></button>"
                . "<ul class='dropdown-menu dropdown-menu-end'>";
            foreach (array_keys($columns) as $st) {
                $isCurrent = $st === $current;
                echo "<li><button type='button' class='dropdown-item sk-move-to" . ($isCurrent ? ' active' : '') . "' "
                    . "data-status='" . htmlescape($st) . "'" . ($isCurrent ? " aria-current='true' disabled" : '') . ">"
                    . htmlescape($statuses[$st] ?? $st) . "</button></li>";
            }
            echo "</ul></div>";
        }
        echo "</div>";

        echo "</div>"; // card
    }

    private static function renderViewTools(array $items, array $tagsById, array $columns, array $statuses): void
    {
        $owners = []; $tags = [];
        foreach ($items as $row) {
            $uid = (int)($row['users_id'] ?? 0);
            $owners[$uid] = $uid > 0 ? SprintCache::userName($uid) : __('Unassigned', 'sprint');
            foreach ($tagsById[(int)$row['id']] ?? [] as $tag) $tags[$tag] = $tag;
        }
        asort($owners); natcasesort($tags);
        echo "<div class='sk-view-tools d-flex flex-wrap gap-2 align-items-center mb-2 p-2 rounded border'><i class='fas fa-filter text-muted' aria-hidden='true'></i>"
            . "<select class='form-select form-select-sm' id='sb-owner' style='width:auto' aria-label='" . __s('Filter by owner', 'sprint') . "'><option value=''>" . __('All owners', 'sprint') . '</option>';
        foreach ($owners as $id => $name) echo "<option value='" . (int)$id . "'>" . htmlescape($name) . '</option>';
        echo "</select><select class='form-select form-select-sm' id='sb-tag' style='width:auto' aria-label='" . __s('Filter by tag', 'sprint') . "'><option value=''>" . __('All tags', 'sprint') . '</option>';
        foreach ($tags as $tag) echo "<option value='" . htmlescape(mb_strtolower($tag)) . "'>" . htmlescape($tag) . '</option>';
        echo "</select>";
        // Narrow screens: look at one column at a time (CSS shows this
        // picker only below the board's mobile breakpoint).
        echo "<select class='form-select form-select-sm sk-column-picker' id='sb-column' style='width:auto' aria-label='" . __s('Show column', 'sprint') . "'>"
            . "<option value=''>" . __('All columns', 'sprint') . '</option>';
        foreach (array_keys($columns) as $st) {
            echo "<option value='" . htmlescape($st) . "' data-label='" . htmlescape($statuses[$st] ?? $st) . "'>" . htmlescape($statuses[$st] ?? $st) . '</option>';
        }
        echo "</select>";
        echo "<label class='form-check form-switch mb-0'><input class='form-check-input' id='sb-swimlane' type='checkbox'><span class='form-check-label'>" . __('Owner swimlanes', 'sprint') . "</span></label><button type='button' class='btn btn-sm btn-outline-secondary' id='sb-reset'>" . __('Reset') . '</button>'
            . "<span class='sk-visible-summary text-muted sprint-small ms-auto' aria-live='polite'></span></div>";
    }

    private static function renderBoardScript(): void
    {
        $endpoint = Plugin::getWebDir('sprint') . '/ajax/updatestatus.php';
        $tokenUrl = Plugin::getWebDir('sprint') . '/ajax/csrftoken.php';
        $errMove  = addslashes(__('Could not change the status — you can only move your own items.', 'sprint'));

        // Confirmation dialog shown when a card moves to Review/Done while its
        // underlying GLPI item is still open (see ajax/updatestatus.php).
        $ttlLinkedOpen = htmlescape(__('Linked item open', 'sprint'));
        $msgLinkedOpen = htmlescape(__('The linked ticket/change is not closed/solved yet', 'sprint'));
        $btnOpenLinked = htmlescape(__('Open linked item', 'sprint'));
        $btnContinue   = htmlescape(__('Continue anyway', 'sprint'));
        $btnCancel     = htmlescape(__('Cancel'));
        $btnClose      = htmlescape(__('Close'));
        $unassignedJs  = addslashes(__('Unassigned', 'sprint'));
        echo "<div class='modal fade' id='sprint-linkedopen-modal' tabindex='-1' aria-hidden='true' aria-labelledby='sprint-linkedopen-modal-title'>"
            . "<div class='modal-dialog modal-dialog-centered'>"
            . "<div class='modal-content'>"
            . "<div class='modal-header'>"
            . "<h5 class='modal-title' id='sprint-linkedopen-modal-title'><i class='fas fa-exclamation-triangle text-warning me-2' aria-hidden='true'></i>{$ttlLinkedOpen}</h5>"
            . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='{$btnClose}'></button>"
            . "</div>"
            . "<div class='modal-body'>"
            . "<p class='mb-1'>{$msgLinkedOpen}</p>"
            . "<p class='sprint-lo-name fw-bold mb-0'></p>"
            . "</div>"
            . "<div class='modal-footer'>"
            . "<a href='#' target='_blank' rel='noopener' class='btn btn-outline-primary sprint-lo-open'>"
            . "<i class='fas fa-external-link-alt me-1'></i>{$btnOpenLinked}</a>"
            . "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>{$btnCancel}</button>"
            . "<button type='button' class='btn btn-warning sprint-lo-continue'>{$btnContinue}</button>"
            . "</div>"
            . "</div></div></div>";

        // DoD dialog for moves to Review/Done (needs_dod response).
        $ttlDod = htmlescape(__('Definition of Done', 'sprint'));
        $msgDod = htmlescape(__('Confirm every check before moving this item on.', 'sprint'));
        $btnDod = htmlescape(__('Confirm', 'sprint'));
        $btnDodOverride = htmlescape(__('Overrule as Scrum Master', 'sprint'));
        $msgDodOverride = htmlescape(__('As Scrum Master you can move this item on without completing every check. The skipped checks are recorded in the history.', 'sprint'));
        echo "<div class='modal fade' id='sprint-dod-modal' tabindex='-1' aria-hidden='true' aria-labelledby='sprint-dod-modal-title'>"
            . "<div class='modal-dialog modal-dialog-centered'>"
            . "<div class='modal-content'>"
            . "<div class='modal-header'>"
            . "<h5 class='modal-title' id='sprint-dod-modal-title'><i class='fas fa-list-check me-2' aria-hidden='true'></i>{$ttlDod}</h5>"
            . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='{$btnClose}'></button>"
            . "</div>"
            . "<div class='modal-body'>"
            . "<p class='text-muted sprint-small mb-2'>{$msgDod}</p>"
            . "<div class='sprint-dod-list'></div>"
            . "<p class='text-muted sprint-small mt-2 mb-0 sprint-dod-override-hint' style='display:none'>"
            . "<i class='fas fa-hat-wizard me-1'></i>{$msgDodOverride}</p>"
            . "</div>"
            . "<div class='modal-footer'>"
            . "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>{$btnCancel}</button>"
            . "<button type='button' class='btn btn-warning sprint-dod-override' style='display:none'>"
            . "<i class='fas fa-hat-wizard me-1'></i>{$btnDodOverride}</button>"
            . "<button type='button' class='btn btn-success sprint-dod-go' disabled>{$btnDod}</button>"
            . "</div>"
            . "</div></div></div>";

        // Strings for the board script. Placeholders become {a}/{b}/{c}/{n}/{v}
        // so the JS never has to deal with printf syntax.
        $statusLabels = [];
        foreach (SprintItem::getAllStatuses() as $st => $lbl) {
            $statusLabels[(string)$st] = (string)$lbl;
        }
        $i18n = json_encode([
            'item1'     => str_replace('%d', '{n}', _n('%d item', '%d items', 1, 'sprint')),
            'itemN'     => str_replace('%d', '{n}', _n('%d item', '%d items', 2, 'sprint')),
            'visibleOf' => str_replace(['%1$d', '%2$d'], ['{v}', '{n}'], __('%1$d of %2$d visible', 'sprint')),
            'showingOf' => str_replace(['%1$d', '%2$d'], ['{v}', '{n}'], __('Showing %1$d of %2$d items', 'sprint')),
            'wipPerson' => str_replace(['%1$s', '%2$d', '%3$d'], ['{a}', '{b}', '{c}'], __('%1$s has %2$d items (max %3$d per person)', 'sprint')),
            'wipTeam'   => str_replace(['%1$d', '%2$d'], ['{a}', '{b}'], __('WIP limit exceeded: %1$d items, limit %2$d', 'sprint')),
            'saving'    => __('Saving...', 'sprint'),
            'updated'   => __('Status updated', 'sprint'),
            'network'   => __('Network error', 'sprint'),
            'statuses'  => $statusLabels,
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        echo <<<JS
<script>
(function(){
    if (window.__sprintKanbanBound) { return; }
    window.__sprintKanbanBound = true;
    if (typeof jQuery === 'undefined') { return; }

    var T = {$i18n};
    var endpoint = "{$endpoint}";
    var tokenUrl = "{$tokenUrl}";
    // Set by the view-settings block below; re-applies filters + swimlanes.
    var applyView = null;

    function fmt(tpl, vals) {
        return String(tpl).replace(/\{(\w+)\}/g, function(m, k){
            return Object.prototype.hasOwnProperty.call(vals, k) ? String(vals[k]) : m;
        });
    }
    function isShown(card) { return card.style.display !== 'none'; }

    // Column counters, WIP verdicts and the filter summary, re-derived from
    // the DOM after every move, revert, quick edit and filter change. WIP is
    // judged on the whole column (the agreed scope), never on the filtered
    // subset; fastlane and unassigned cards are exempt from the per-person
    // limit, matching SprintBoard::wipState().
    function recount() {
        var shown = 0, all = 0;
        document.querySelectorAll('.sprint-kanban-col').forEach(function(col){
            var cards = Array.prototype.slice.call(col.querySelectorAll('.sprint-kanban-card'));
            var n = cards.length;
            var v = cards.filter(isShown).length;
            all += n; shown += v;
            var c = col.querySelector('.sprint-kanban-count');
            if (c) {
                c.textContent = v < n ? fmt(T.visibleOf, {v: v, n: n}) : fmt(n === 1 ? T.item1 : T.itemN, {n: n});
            }

            var limit = parseInt(col.getAttribute('data-wip-limit') || '0', 10) || 0;
            var team  = parseInt(col.getAttribute('data-wip-team') || '0', 10) || 0;
            var perOwner = {}, names = {}, counted = 0;
            cards.forEach(function(card){
                if (String(card.getAttribute('data-is-fastlane')) === '1') { return; }
                counted++;
                var o = parseInt(card.getAttribute('data-users-id') || '0', 10) || 0;
                if (o > 0) {
                    perOwner[o] = (perOwner[o] || 0) + 1;
                    names[o] = card.getAttribute('data-owner-name') || '';
                }
            });
            var msg = '';
            if (limit > 0) {
                var top = 0, topId = 0;
                Object.keys(perOwner).forEach(function(k){ if (perOwner[k] > top) { top = perOwner[k]; topId = k; } });
                if (top > limit) { msg = fmt(T.wipPerson, {a: names[topId], b: top, c: limit}); }
            } else if (team > 0 && counted > team) {
                msg = fmt(T.wipTeam, {a: counted, b: team});
            }
            col.classList.toggle('sk-over-wip', msg !== '');
            var w = col.querySelector('.sk-col-warning');
            // Only touch the live region when the verdict changes, so screen
            // readers announce a new warning once instead of on every recount.
            if (w && w.getAttribute('data-msg') !== msg) {
                w.setAttribute('data-msg', msg);
                w.textContent = '';
                if (msg) {
                    var icon = document.createElement('i');
                    icon.className = 'fas fa-triangle-exclamation';
                    icon.setAttribute('aria-hidden', 'true');
                    w.appendChild(icon);
                    w.appendChild(document.createTextNode(' ' + msg));
                }
                w.hidden = msg === '';
            }

            var opt = document.querySelector('#sb-column option[value="' + col.getAttribute('data-status') + '"]');
            if (opt) { opt.textContent = (opt.getAttribute('data-label') || '') + ' (' + n + ')'; }
        });
        document.querySelectorAll('.sk-visible-summary').forEach(function(sum){
            var text = shown < all ? fmt(T.showingOf, {v: shown, n: all}) : '';
            if (sum.textContent !== text) { sum.textContent = text; }
        });
    }

    // Personal display settings stay local.
    (function(){
        var owner = document.getElementById('sb-owner'), tag = document.getElementById('sb-tag'), swim = document.getElementById('sb-swimlane');
        var column = document.getElementById('sb-column');
        if (!owner || !tag || !swim) { recount(); return; }
        document.querySelectorAll('.sprint-kanban-body').forEach(function(body){Array.from(body.querySelectorAll('.sprint-kanban-card')).forEach(function(card,index){card.dataset.boardOrder=String(index);});});
        var key = 'sprint-board-view-' + (document.querySelector('.sprint-kanban') || {}).dataset.sprintId;
        try { var saved = JSON.parse(localStorage.getItem(key) || '{}'); owner.value=saved.owner||'';tag.value=saved.tag||'';swim.checked=!!saved.swim; if (column) { column.value=saved.col||''; if (column.selectedIndex < 0) { column.value=''; } } } catch(e) {}
        // One-column view (narrow screens): the picker is hidden by CSS above
        // the breakpoint, and the .sk-single rules only apply below it.
        function applyColumn(){
            var val = column ? column.value : '';
            document.querySelectorAll('.sprint-kanban').forEach(function(k){ k.classList.toggle('sk-single', val !== ''); });
            document.querySelectorAll('.sprint-kanban-col').forEach(function(col){
                col.classList.toggle('sk-col-active', val !== '' && col.getAttribute('data-status') === val);
            });
        }
        function apply(){
            document.querySelectorAll('.sprint-kanban-card').forEach(function(card){
                var ownerOk=!owner.value||String(card.dataset.usersId||'0')===owner.value;
                var blob=String(card.dataset.itemTags||'').toLowerCase();
                var tagOk=!tag.value||blob.indexOf('|'+tag.value+'|')!==-1;
                card.style.display=ownerOk&&tagOk?'':'none';
            });
            document.querySelectorAll('.sb-owner-lane').forEach(function(n){n.remove();});
            if(swim.checked){
                document.querySelectorAll('.sprint-kanban-body').forEach(function(body){
                    var last='';
                    Array.from(body.querySelectorAll('.sprint-kanban-card')).sort(function(a,b){return String(a.dataset.ownerName).localeCompare(String(b.dataset.ownerName));}).forEach(function(card){
                        var name=card.dataset.ownerName||'{$unassignedJs}';
                        if(card.style.display!== 'none' && name!==last){var h=document.createElement('div');h.className='sb-owner-lane sprint-small fw-bold text-muted mt-2 mb-1';h.textContent=name;body.appendChild(h);last=name;}
                        body.appendChild(card);
                    });
                });
            } else {
                document.querySelectorAll('.sprint-kanban-body').forEach(function(body){
                    Array.from(body.querySelectorAll('.sprint-kanban-card')).sort(function(a,b){return parseInt(a.dataset.boardOrder||'0',10)-parseInt(b.dataset.boardOrder||'0',10);}).forEach(function(card){body.appendChild(card);});
                });
            }
            applyColumn();
            recount();
            try { localStorage.setItem(key,JSON.stringify({owner:owner.value,tag:tag.value,swim:swim.checked,col:column?column.value:''})); } catch(e) {}
        }
        applyView = function(){ if (swim.checked) { apply(); } else { recount(); } };
        owner.addEventListener('change',apply);tag.addEventListener('change',apply);swim.addEventListener('change',apply);
        if (column) { column.addEventListener('change',apply); }
        document.getElementById('sb-reset').addEventListener('click',function(){owner.value='';tag.value='';swim.checked=false;if(column){column.value='';}apply();});
        setTimeout(apply,0);
    })();

    // After a card changed column or owner: lanes need a rebuild when they
    // are on, the counters always.
    function afterLayoutChange() {
        if (applyView) { applyView(); } else { recount(); }
    }

    // Highlight the card's current column in its "Change status" menu.
    function syncMoveMenu(card) {
        var st = card.getAttribute('data-status');
        card.querySelectorAll('.sk-move-to').forEach(function(b){
            var cur = b.getAttribute('data-status') === st;
            b.classList.toggle('active', cur);
            b.disabled = cur;
            if (cur) { b.setAttribute('aria-current', 'true'); } else { b.removeAttribute('aria-current'); }
        });
    }

    // Waiting state while a move is being saved (or awaits a DoD / linked
    // item confirmation): the card is dimmed, says "Saving..." and cannot be
    // moved again until the server answered.
    function setSaving(card, on) {
        card.classList.toggle('sk-saving', !!on);
        var toggle = card.querySelector('.sk-card-move-btn');
        // aria-disabled, not disabled: the toggle keeps keyboard focus while
        // the move it started is saved; moveCard() ignores saving cards.
        if (toggle) { toggle.setAttribute('aria-disabled', on ? 'true' : 'false'); }
        if (on) {
            card.setAttribute('aria-busy', 'true');
            if (!card.querySelector('.sk-saving-indicator')) {
                var s = document.createElement('span');
                s.className = 'sk-saving-indicator';
                s.setAttribute('role', 'status');
                var i = document.createElement('i');
                i.className = 'fas fa-spinner fa-spin';
                i.setAttribute('aria-hidden', 'true');
                s.appendChild(i);
                s.appendChild(document.createTextNode(' ' + T.saving));
                (card.querySelector('.sk-card-meta') || card).appendChild(s);
            }
            return;
        }
        card.removeAttribute('aria-busy');
        card.querySelectorAll('.sk-saving-indicator').forEach(function(n){ n.remove(); });
        // A move started from the menu hands focus back to its button (or
        // the column picker when a one-column view hid the card).
        if (card.__skFocusBack) {
            card.__skFocusBack = false;
            if (toggle && toggle.offsetParent !== null) { toggle.focus(); }
            else {
                var picker = document.getElementById('sb-column');
                if (picker && picker.offsetParent !== null) { picker.focus(); }
            }
        }
    }

    // Saved: the quick-edit data attributes follow the new status so a later
    // quick edit opens with the right value (jQuery caches .data()).
    function markSaved(card, newStatus) {
        jQuery(card)
            .attr('data-item-status', newStatus).data('item-status', newStatus)
            .attr('data-item-status-label', (T.statuses && T.statuses[newStatus]) || newStatus)
            .data('item-status-label', (T.statuses && T.statuses[newStatus]) || newStatus);
        setSaving(card, false);
    }

    function moveCard(card, body) {
        var fromBody = card.parentNode;
        if (!body || !fromBody || fromBody === body || card.classList.contains('sk-saving')) { return; }
        var newStatus = body.getAttribute('data-status');
        card.__skFromNext = card.nextSibling;
        // Optimistic move.
        body.appendChild(card);
        card.setAttribute('data-status', newStatus);
        syncMoveMenu(card);
        setSaving(card, true);
        afterLayoutChange();
        persistStatus(card.getAttribute('data-item-id'), newStatus, card, fromBody, false);
    }

    document.addEventListener('dragstart', function(e){
        var card = e.target.closest && e.target.closest('.sprint-kanban-card');
        if (!card || card.getAttribute('draggable') !== 'true') { return; }
        if (card.classList.contains('sk-saving')) { e.preventDefault(); return; }
        try { e.dataTransfer.setData('text/plain', card.getAttribute('data-item-id')); } catch (ex) {}
        e.dataTransfer.effectAllowed = 'move';
        card.classList.add('sk-dragging');
    });

    document.addEventListener('dragend', function(e){
        var card = e.target.closest && e.target.closest('.sprint-kanban-card');
        if (card) { card.classList.remove('sk-dragging'); }
        document.querySelectorAll('.sprint-kanban-body.sk-drag-over')
            .forEach(function(b){ b.classList.remove('sk-drag-over'); });
    });

    document.addEventListener('dragover', function(e){
        var body = e.target.closest && e.target.closest('.sprint-kanban-body');
        if (!body) { return; }
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        body.classList.add('sk-drag-over');
    });

    document.addEventListener('dragleave', function(e){
        var body = e.target.closest && e.target.closest('.sprint-kanban-body');
        if (body && !body.contains(e.relatedTarget)) { body.classList.remove('sk-drag-over'); }
    });

    // Context of a move waiting on the "linked item still open" confirmation.
    var pendingMove = null;
    var pendingMoveConfirmed = false;

    // Rejected or cancelled: back to the original column and position, and
    // the counters/WIP warning follow.
    function revertMove(card, fromBody) {
        var next = card.__skFromNext;
        if (next && next.parentNode === fromBody) { fromBody.insertBefore(card, next); }
        else { fromBody.appendChild(card); }
        card.__skFromNext = null;
        card.setAttribute('data-status', fromBody.getAttribute('data-status'));
        syncMoveMenu(card);
        setSaving(card, false);
        afterLayoutChange();
    }

    // Swap the card's "Linked item open" warning badge for the fresh
    // server-rendered state (empty string removes it).
    function syncLinkedOpenBadge(card, html) {
        var title = card.querySelector('.sk-card-title');
        if (!title) { return; }
        title.querySelectorAll('.sprint-review-unclosed-badge').forEach(function(b){ b.remove(); });
        if (html) {
            var editBtn = title.querySelector('.sk-card-edit');
            if (editBtn) { editBtn.insertAdjacentHTML('beforebegin', html); }
            else { title.insertAdjacentHTML('beforeend', html); }
        }
    }

    function persistStatus(id, newStatus, card, fromBody, confirmOpen, doneList, dodOverride) {
        setSaving(card, true);
        jQuery.ajax({ url: tokenUrl, type: 'GET', dataType: 'json', cache: false })
        .then(function(tok){
            var data = {
                id: id, status: newStatus,
                confirm_linked_open: confirmOpen ? 1 : 0,
                dod_override: dodOverride ? 1 : 0,
                _glpi_csrf_token: tok && tok.token ? tok.token : ''
            };
            if (doneList && doneList.length) { data.done = doneList; }
            return jQuery.ajax({
                url: endpoint, type: 'POST', dataType: 'json',
                data: data
            });
        }).done(function(resp){
            if (resp && resp.needs_dod) {
                var dodEl = document.getElementById('sprint-dod-modal');
                if (!dodEl) {
                    revertMove(card, fromBody);
                    if (window.glpi_toast_error) { window.glpi_toast_error(resp.message || "{$errMove}"); }
                    return;
                }
                pendingMove = { id: id, newStatus: newStatus, card: card, fromBody: fromBody, doneList: doneList || null, dodOverride: false };
                pendingMoveConfirmed = false;
                var list = dodEl.querySelector('.sprint-dod-list');
                // Scrum Master only: the overrule button and its hint.
                var overrideBtn = dodEl.querySelector('.sprint-dod-override');
                var overrideHint = dodEl.querySelector('.sprint-dod-override-hint');
                if (overrideBtn) { overrideBtn.style.display = resp.can_override ? '' : 'none'; }
                if (overrideHint) { overrideHint.style.display = resp.can_override ? '' : 'none'; }
                list.innerHTML = '';
                (resp.dod || []).forEach(function(check){
                    var label = document.createElement('label');
                    label.className = 'form-check d-block';
                    var input = document.createElement('input');
                    input.type = 'checkbox';
                    input.className = 'form-check-input sprint-dod-check';
                    input.value = check;
                    input.checked = (resp.checked || []).indexOf(check) !== -1;
                    var span = document.createElement('span');
                    span.className = 'form-check-label';
                    span.textContent = check;
                    label.appendChild(input);
                    label.appendChild(span);
                    list.appendChild(label);
                });
                var goBtn = dodEl.querySelector('.sprint-dod-go');
                var refreshGo = function(){
                    goBtn.disabled = !!list.querySelector('.sprint-dod-check:not(:checked)');
                };
                list.querySelectorAll('.sprint-dod-check').forEach(function(cb){
                    cb.addEventListener('change', refreshGo);
                });
                refreshGo();
                bootstrap.Modal.getOrCreateInstance(dodEl).show();
                return;
            }
            if (resp && resp.needs_confirm) {
                var modalEl = document.getElementById('sprint-linkedopen-modal');
                if (!modalEl) {
                    // Modal missing (shouldn't happen): behave like before.
                    revertMove(card, fromBody);
                    if (window.glpi_toast_error) { window.glpi_toast_error(resp.message || "{$errMove}"); }
                    return;
                }
                pendingMove = { id: id, newStatus: newStatus, card: card, fromBody: fromBody, doneList: doneList || null, dodOverride: !!dodOverride };
                pendingMoveConfirmed = false;
                var nameEl = modalEl.querySelector('.sprint-lo-name');
                if (nameEl) { nameEl.textContent = resp.linked_name || ''; }
                var openBtn = modalEl.querySelector('.sprint-lo-open');
                if (openBtn) {
                    if (resp.linked_url) {
                        openBtn.style.display = '';
                        openBtn.setAttribute('href', resp.linked_url);
                    } else {
                        openBtn.style.display = 'none';
                    }
                }
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
                return;
            }
            if (resp && resp.success) {
                card.__skFromNext = null;
                markSaved(card, newStatus);
                syncLinkedOpenBadge(card, resp.linked_open_badge_html || '');
                if (resp.dod_overridden && window.glpi_toast_warning) {
                    window.glpi_toast_warning(resp.message || T.updated);
                } else if (window.glpi_toast_info) {
                    window.glpi_toast_info(resp.message || T.updated);
                }
            } else {
                revertMove(card, fromBody);
                var msg = (resp && resp.message && resp.message !== 'Update failed')
                    ? resp.message : "{$errMove}";
                if (window.glpi_toast_error) { window.glpi_toast_error(msg); }
            }
        }).fail(function(){
            revertMove(card, fromBody);
            if (window.glpi_toast_error) { window.glpi_toast_error(T.network); }
        });
    }

    document.addEventListener('drop', function(e){
        var body = e.target.closest && e.target.closest('.sprint-kanban-body');
        if (!body) { return; }
        e.preventDefault();
        body.classList.remove('sk-drag-over');
        var id = null;
        try { id = e.dataTransfer.getData('text/plain'); } catch (ex) {}
        if (!id) { return; }
        var card = document.querySelector('.sprint-kanban-card[data-item-id="' + id + '"]');
        if (!card) { return; }
        moveCard(card, body);
    });

    // Explicit "Change status" menu on each card: the keyboard and touch
    // route to the same move as a drop.
    document.addEventListener('click', function(e){
        var btn = e.target.closest && e.target.closest('.sk-move-to');
        if (!btn) { return; }
        var card = btn.closest('.sprint-kanban-card');
        var kanban = card && card.closest('.sprint-kanban');
        if (!kanban) { return; }
        e.preventDefault();
        var toggle = card.querySelector('.sk-card-move-btn');
        if (toggle && window.bootstrap && bootstrap.Dropdown) {
            try { bootstrap.Dropdown.getOrCreateInstance(toggle).hide(); } catch (ex) {}
        }
        var body = kanban.querySelector('.sprint-kanban-body[data-status="' + btn.getAttribute('data-status') + '"]');
        if (!body || body === card.parentNode) { return; }
        card.__skFocusBack = true;
        moveCard(card, body);
        if (toggle) { toggle.focus(); }
    });

    // Quick edit (SprintItem::renderQuickEditUI) saves status and owner on its
    // own and rewrites the card's data-* attributes; follow those here so the
    // card lands in its new column and the counters stay right.
    if (window.MutationObserver) {
        new MutationObserver(function(mutations){
            var dirty = false;
            mutations.forEach(function(m){
                var card = m.target;
                if (!card.classList || !card.classList.contains('sprint-kanban-card')) { return; }
                dirty = true;
                if (m.attributeName !== 'data-item-status' || card.classList.contains('sk-saving')) { return; }
                var st = card.getAttribute('data-item-status');
                if (!st || st === card.getAttribute('data-status')) { return; }
                var kanban = card.closest('.sprint-kanban');
                var body = kanban && kanban.querySelector('.sprint-kanban-body[data-status="' + st + '"]');
                if (body) {
                    body.appendChild(card);
                    card.setAttribute('data-status', st);
                    syncMoveMenu(card);
                }
            });
            if (dirty) { afterLayoutChange(); }
        }).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['data-item-status', 'data-users-id', 'data-is-fastlane'] });
    }

    // "Continue anyway": retry the move with the confirmation flag set.
    jQuery(document).on('click', '#sprint-linkedopen-modal .sprint-lo-continue', function(){
        var modalEl = document.getElementById('sprint-linkedopen-modal');
        pendingMoveConfirmed = true;
        var inst = modalEl ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
        if (inst) { inst.hide(); }
        if (pendingMove) {
            var mv = pendingMove;
            pendingMove = null;
            persistStatus(mv.id, mv.newStatus, mv.card, mv.fromBody, true, mv.doneList, mv.dodOverride);
        }
    });

    // DoD confirmed: retry the move carrying the checked DoD values.
    jQuery(document).on('click', '#sprint-dod-modal .sprint-dod-go', function(){
        var dodEl = document.getElementById('sprint-dod-modal');
        pendingMoveConfirmed = true;
        var done = [];
        dodEl.querySelectorAll('.sprint-dod-check:checked').forEach(function(cb){ done.push(cb.value); });
        bootstrap.Modal.getOrCreateInstance(dodEl).hide();
        if (pendingMove) {
            var mv = pendingMove;
            pendingMove = null;
            persistStatus(mv.id, mv.newStatus, mv.card, mv.fromBody, false, done, false);
        }
    });

    // Scrum Master overrule: retry with whatever was ticked plus the override flag.
    jQuery(document).on('click', '#sprint-dod-modal .sprint-dod-override', function(){
        var dodEl = document.getElementById('sprint-dod-modal');
        pendingMoveConfirmed = true;
        var done = [];
        dodEl.querySelectorAll('.sprint-dod-check:checked').forEach(function(cb){ done.push(cb.value); });
        bootstrap.Modal.getOrCreateInstance(dodEl).hide();
        if (pendingMove) {
            var mv = pendingMove;
            pendingMove = null;
            persistStatus(mv.id, mv.newStatus, mv.card, mv.fromBody, false, done, true);
        }
    });

    // Dismissed without confirming (Cancel, X, backdrop): undo the move.
    jQuery(document).on('hidden.bs.modal', '#sprint-linkedopen-modal, #sprint-dod-modal', function(){
        if (pendingMoveConfirmed) { pendingMoveConfirmed = false; return; }
        if (pendingMove) {
            revertMove(pendingMove.card, pendingMove.fromBody);
            pendingMove = null;
        }
    });
})();
</script>
JS;
    }
}
