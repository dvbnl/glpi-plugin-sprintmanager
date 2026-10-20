<?php

namespace GlpiPlugin\Sprint;

use CommonGLPI;

/**
 * SprintAudit - consolidated audit trail for a sprint.
 *
 * Aggregates GLPI's native glpi_logs rows for the sprint and its owned
 * sub-entities into one chronological log. Read-only: no new tables or
 * writes; it works because each tracked class sets $dohistory = true.
 */
class SprintAudit extends CommonGLPI
{
    public static $rightname = 'plugin_sprint_sprint';

    public static function getTypeName($nb = 0): string
    {
        return __('Audit log', 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-history';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Sprint) {
            return self::createTabEntry(self::getTypeName());
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

    /** Rows per page in the audit table. */
    const PAGE_SIZE = 50;

    /** Log rows read per sprint; the newest win when a sprint has more. */
    const MAX_ENTRIES = 2000;

    /**
     * Render the audit log for a single sprint: search, area and period
     * filters, a result count, pages of PAGE_SIZE rows and long changes
     * folded into an expandable detail. Filtering runs in the browser over
     * the rows read, so it stays instant on a long history.
     */
    public static function showForSprint(Sprint $sprint): void
    {
        $sprintId = (int)$sprint->getID();
        if ($sprintId <= 0) {
            return;
        }

        // Opportunistic cleanup so glpi_logs doesn't grow unbounded; cheap
        // when there's nothing to delete (indexed on date_mod).
        self::pruneOldLogs();

        $entries = self::collectEntries($sprintId);
        $areas   = self::getAreaLabels();
        $rand    = mt_rand();

        echo "<div class='sprint-audit' id='sprint-audit-{$rand}' style='max-width:1200px;margin:0 auto;'>";

        echo "<div class='sprint-audit-bar d-flex flex-wrap align-items-end gap-2 p-2 mb-2' role='search' "
            . "aria-label='" . __s('Filter the history', 'sprint') . "' "
            . "style='background:var(--tblr-bg-surface-secondary,#f1f3f5);border-radius:6px;'>";
        echo "<div><label class='form-label sprint-small mb-1' for='sa-text-{$rand}'>" . __s('Search', 'sprint') . "</label>"
            . "<input type='search' id='sa-text-{$rand}' class='form-control form-control-sm sa-text' style='width:220px;' "
            . "placeholder='" . __s('Search action or user...', 'sprint') . "'></div>";
        echo "<div><label class='form-label sprint-small mb-1' for='sa-area-{$rand}'>" . __s('Area', 'sprint') . "</label>"
            . "<select id='sa-area-{$rand}' class='form-select form-select-sm sa-area' style='width:170px;'>";
        echo "<option value=''>" . __('All areas', 'sprint') . "</option>";
        foreach ($areas as $key => $label) {
            echo "<option value='" . htmlescape($key) . "'>" . htmlescape($label) . "</option>";
        }
        echo "</select></div>";
        echo "<div><label class='form-label sprint-small mb-1' for='sa-period-{$rand}'>" . __s('Period', 'sprint') . "</label>"
            . "<select id='sa-period-{$rand}' class='form-select form-select-sm sa-period' style='width:150px;'>"
            . "<option value=''>" . __s('Whole sprint', 'sprint') . "</option>"
            . "<option value='1'>" . __s('Last 24 hours', 'sprint') . "</option>"
            . "<option value='7'>" . __s('Last 7 days', 'sprint') . "</option>"
            . "<option value='30'>" . __s('Last 30 days', 'sprint') . "</option>"
            . "<option value='custom'>" . __s('Between dates…', 'sprint') . "</option>"
            . "</select></div>";
        echo "<div class='sa-custom' hidden><label class='form-label sprint-small mb-1' for='sa-from-{$rand}'>" . __s('From', 'sprint') . "</label>"
            . "<input type='date' id='sa-from-{$rand}' class='form-control form-control-sm sa-from'></div>";
        echo "<div class='sa-custom' hidden><label class='form-label sprint-small mb-1' for='sa-to-{$rand}'>" . __s('To', 'sprint') . "</label>"
            . "<input type='date' id='sa-to-{$rand}' class='form-control form-control-sm sa-to'></div>";
        echo "<button type='button' class='btn btn-sm btn-outline-secondary sa-reset'>"
            . "<i class='fas fa-times me-1' aria-hidden='true'></i>" . __('Reset', 'sprint') . "</button>";
        echo "<span class='ms-auto text-muted sprint-small sa-count' role='status' aria-live='polite'>"
            . htmlescape(sprintf(_n('%d entry', '%d entries', count($entries), 'sprint'), count($entries))) . "</span>";
        echo "</div>";

        if (count($entries) >= self::MAX_ENTRIES) {
            echo "<div class='alert alert-info py-2 sprint-small'>" . htmlescape(sprintf(
                __('This sprint has a long history: only the newest %d changes are shown.', 'sprint'),
                self::MAX_ENTRIES
            )) . "</div>";
        }

        echo "<table class='tab_cadre_fixe sprint-themed sprint-audit-table' style='width:100%;'>";
        echo "<caption class='visually-hidden'>" . __s('Sprint history', 'sprint') . "</caption>";
        echo "<thead><tr class='tab_bg_2'>";
        echo "<th scope='col' style='width:160px;'>" . __('When', 'sprint') . "</th>";
        echo "<th scope='col' style='width:120px;'>" . __('Area', 'sprint') . "</th>";
        echo "<th scope='col'>" . __('Item') . "</th>";
        echo "<th scope='col'>" . __('Action', 'sprint') . "</th>";
        echo "<th scope='col'>" . __('Change', 'sprint') . "</th>";
        echo "<th scope='col' style='width:160px;'>" . __('User') . "</th>";
        echo "</tr></thead><tbody>";

        if (count($entries) === 0) {
            echo "<tr class='tab_bg_1'><td colspan='6' class='center' style='padding:32px;color:var(--tblr-secondary,#6c757d);'>"
                . "<i class='fas fa-inbox' aria-hidden='true' style='font-size:2em;display:block;margin-bottom:8px;opacity:0.5;'></i>"
                . __('No audit entries yet', 'sprint') . "</td></tr>";
        }

        foreach ($entries as $e) {
            $areaLabel = $areas[$e['area']] ?? $e['area'];
            $areaColor = self::areaColor($e['area']);
            echo "<tr class='tab_bg_1 sprint-audit-entry' "
                . "data-search='" . htmlescape(mb_strtolower($e['search'])) . "' "
                . "data-area='" . htmlescape($e['area']) . "' "
                . "data-ts='" . (int)$e['ts'] . "'>";
            echo "<td style='white-space:nowrap;'><i class='far fa-clock text-muted me-1' aria-hidden='true'></i>"
                . "<time datetime='" . htmlescape($e['ts'] ? date('c', $e['ts']) : '') . "'>" . htmlescape($e['when']) . "</time></td>";
            echo "<td><span style='display:inline-block;padding:3px 10px;border-radius:10px;"
                . "background:{$areaColor}22;color:{$areaColor};font-weight:600;font-size:0.78em;'>"
                . htmlescape($areaLabel) . "</span></td>";
            echo "<td>" . $e['item_html'] . "</td>";
            echo "<td>" . htmlescape($e['action']) . $e['source_html'] . "</td>";
            echo "<td>" . $e['change_html'] . "</td>";
            echo "<td style='white-space:nowrap;'>"
                . ($e['user_name'] !== ''
                    ? "<i class='fas fa-user text-muted me-1' aria-hidden='true'></i>" . htmlescape($e['user_name'])
                    : '<span class="text-muted fst-italic">' . __('System', 'sprint') . '</span>')
                . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";

        if (count($entries) > 0) {
            echo "<div class='sa-empty text-muted text-center p-4' hidden>"
                . htmlescape(__('No entries match these filters.', 'sprint'))
                . " <button type='button' class='btn btn-sm btn-link sa-reset'>" . __s('Reset filters', 'sprint') . "</button></div>";
            echo "<nav class='sa-pager d-flex align-items-center justify-content-center gap-2 mt-2' aria-label='" . __s('History pages', 'sprint') . "'>"
                . "<button type='button' class='btn btn-sm btn-outline-secondary sa-prev'>"
                . "<i class='fas fa-chevron-left me-1' aria-hidden='true'></i>" . __s('Newer', 'sprint') . "</button>"
                . "<span class='sprint-small text-muted sa-page'></span>"
                . "<button type='button' class='btn btn-sm btn-outline-secondary sa-next'>" . __s('Older', 'sprint')
                . "<i class='fas fa-chevron-right ms-1' aria-hidden='true'></i></button>"
                . "</nav>";
        }
        echo "</div>";

        self::renderAuditScript();
    }

    /** Client-side filter + pager for the audit table; one delegated binding. */
    private static function renderAuditScript(): void
    {
        $i18n = json_encode([
            'count' => __('%1$s of %2$s entries', 'sprint'),
            'page'  => __('Page %1$s of %2$s', 'sprint'),
            'size'  => self::PAGE_SIZE,
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        echo <<<HTML
<script>
(function(){
    var T = {$i18n};
    function fmt(s, a, b) { return s.replace('%1\$s', a).replace('%2\$s', b); }
    function dayStart(v) { var d = new Date(v + 'T00:00:00'); return isNaN(d) ? null : d.getTime() / 1000; }

    function apply(root, keepPage) {
        var q      = (root.querySelector('.sa-text').value || '').toLowerCase().trim();
        var area   = root.querySelector('.sa-area').value || '';
        var period = root.querySelector('.sa-period').value || '';
        var from = null, to = null, now = Date.now() / 1000;
        root.querySelectorAll('.sa-custom').forEach(function(el){ el.hidden = period !== 'custom'; });
        if (period === 'custom') {
            from = dayStart(root.querySelector('.sa-from').value || '');
            var t = dayStart(root.querySelector('.sa-to').value || '');
            to = t === null ? null : t + 86400;
        } else if (period !== '') {
            from = now - parseInt(period, 10) * 86400;
        }
        var rows = Array.prototype.slice.call(root.querySelectorAll('tr.sprint-audit-entry'));
        var match = rows.filter(function(r){
            if (q && (r.getAttribute('data-search') || '').indexOf(q) === -1) { return false; }
            if (area && r.getAttribute('data-area') !== area) { return false; }
            var ts = parseInt(r.getAttribute('data-ts'), 10) || 0;
            if (from !== null && ts < from) { return false; }
            if (to !== null && ts >= to) { return false; }
            return true;
        });
        var pages = Math.max(1, Math.ceil(match.length / T.size));
        var page  = keepPage ? Math.min(parseInt(root.getAttribute('data-page'), 10) || 1, pages) : 1;
        root.setAttribute('data-page', page);
        rows.forEach(function(r){ r.hidden = true; });
        match.slice((page - 1) * T.size, page * T.size).forEach(function(r){ r.hidden = false; });

        var count = root.querySelector('.sa-count');
        if (count) { count.textContent = fmt(T.count, String(match.length), String(rows.length)); }
        var empty = root.querySelector('.sa-empty');
        if (empty) { empty.hidden = match.length > 0; }
        var pager = root.querySelector('.sa-pager');
        if (pager) {
            pager.hidden = pages <= 1;
            pager.querySelector('.sa-page').textContent = fmt(T.page, String(page), String(pages));
            pager.querySelector('.sa-prev').disabled = page <= 1;
            pager.querySelector('.sa-next').disabled = page >= pages;
        }
    }

    if (!window.__sprintAuditBound) {
        window.__sprintAuditBound = true;
        var onChange = function(e){
            var root = e.target && e.target.closest ? e.target.closest('.sprint-audit') : null;
            if (root && e.target.matches('.sa-text, .sa-area, .sa-period, .sa-from, .sa-to')) { apply(root, false); }
        };
        document.addEventListener('input', onChange);
        document.addEventListener('change', onChange);
        document.addEventListener('click', function(e){
            var t = e.target && e.target.closest ? e.target : null;
            var root = t ? t.closest('.sprint-audit') : null;
            if (!root) { return; }
            if (t.closest('.sa-reset')) {
                root.querySelector('.sa-text').value = '';
                root.querySelector('.sa-area').value = '';
                root.querySelector('.sa-period').value = '';
                root.querySelector('.sa-from').value = '';
                root.querySelector('.sa-to').value = '';
                apply(root, false);
                root.querySelector('.sa-text').focus();
            } else if (t.closest('.sa-prev') || t.closest('.sa-next')) {
                var page = parseInt(root.getAttribute('data-page'), 10) || 1;
                root.setAttribute('data-page', page + (t.closest('.sa-next') ? 1 : -1));
                apply(root, true);
                var table = root.querySelector('.sprint-audit-table');
                if (table) { table.scrollIntoView({ block: 'start' }); }
            }
        });
    }
    // Every copy GLPI rendered (ajax tabs can render twice) gets its first page.
    document.querySelectorAll('.sprint-audit').forEach(function(root){ apply(root, false); });
})();
</script>
HTML;
    }

    /**
     * Collect log entries across all sprint-related itemtypes, newest
     * first, as a render-ready list.
     */
    private static function collectEntries(int $sprintId): array
    {
        global $DB;

        if (!$DB->tableExists('glpi_logs')) {
            return [];
        }

        // Itemtypes we log for this sprint, with the ids under this sprint.
        $targets = self::resolveTargetIds($sprintId);

        // Build a criteria array — GLPI 11 forbids raw SQL strings in $DB->request().
        $orClauses = [];
        foreach ($targets as $t) {
            if (empty($t['ids'])) {
                continue;
            }
            $orClauses[] = [
                'itemtype' => $t['itemtype'],
                'items_id' => array_map('intval', $t['ids']),
            ];
        }

        if (empty($orClauses)) {
            return [];
        }

        [$windowStart, $windowEnd] = self::getRetentionWindowForSprint($sprintId);

        // GLPI's iterator AND's top-level keys; nested 'OR' groups the
        // itemtype/items_id combinations.
        $where = ['OR' => $orClauses];
        if ($windowStart !== null) {
            $where['date_mod'] = ['>=', $windowStart];
        }
        if ($windowEnd !== null) {
            $where[] = ['date_mod' => ['<=', $windowEnd]];
        }
        $criteria = [
            'SELECT' => '*',
            'FROM'   => 'glpi_logs',
            'WHERE'  => $where,
            'ORDER'  => ['date_mod DESC'],
            'LIMIT'  => self::MAX_ENTRIES,
        ];

        $entries = [];
        $logIds = [];

        foreach ($DB->request($criteria) as $row) {
            $area = self::areaForItemtype($row['itemtype']);

            // glpi_logs.user_name is a string like "Display Name (login_id)".
            // Parse any numeric id so we can resolve the current display name.
            $rawUser = (string)($row['user_name'] ?? '');
            $userId  = 0;
            if (preg_match('/\((\d+)\)\s*$/', $rawUser, $m)) {
                $userId = (int)$m[1];
            }
            if ($userId > 0) {
                $userName = SprintCache::userName($userId);
            } else {
                $userName = self::stripUserName($rawUser);
            }

            $linkedAction = (int)($row['linked_action'] ?? 0);
            $searchOptId  = (int)($row['id_search_option'] ?? 0);

            // Short verb for linked_action, plus the field name on field updates.
            $fieldLabel = self::fieldLabel((string)$row['itemtype'], $searchOptId);
            $action = self::actionLabel($linkedAction);
            if ($fieldLabel !== '' && $linkedAction === 0) {
                $action .= ': ' . $fieldLabel;
            }

            // "<old> → <new>" for field updates, plain text for adds/purges.
            $changeHtml = self::formatChange($linkedAction, (string)($row['old_value'] ?? ''), (string)($row['new_value'] ?? ''));

            $itemHtml = self::formatItem($row['itemtype'], (int)$row['items_id']);

            $whenTs   = strtotime($row['date_mod']);
            $whenText = $whenTs ? date('Y-m-d H:i', $whenTs) : (string)$row['date_mod'];

            $logId = (int)($row['id'] ?? 0);
            if ($logId > 0) {
                $logIds[] = $logId;
            }

            $entries[] = [
                'log_id'      => $logId,
                'when'        => $whenText,
                'ts'          => $whenTs ?: 0,
                'area'        => $area,
                'item_html'   => $itemHtml,
                'action'      => $action,
                'change_html' => $changeHtml,
                'user_id'     => $userId,
                'user_name'   => $userName ?: '',
                'search'      => '',
                'source_html' => '',
                'source_text' => '',
            ];
        }

        // Tag rows that fanned out from a meeting save with a "via" badge.
        $sources = self::loadSourcesForLogIds($logIds);
        foreach ($entries as &$e) {
            if (!isset($sources[$e['log_id']])) {
                continue;
            }
            $src = $sources[$e['log_id']];
            $sourceLabel = self::sourceTypeLabel($src['itemtype']);
            $sourceLink  = self::formatItem($src['itemtype'], $src['items_id']);
            $e['source_html'] = " <span class='badge bg-warning-subtle text-warning-emphasis ms-1' "
                . "style='font-weight:500;font-size:0.72em;'>"
                . "<i class='fas fa-link me-1'></i>"
                . htmlescape(__('via', 'sprint')) . " "
                . htmlescape($sourceLabel) . " " . $sourceLink
                . "</span>";
            $e['source_text'] = __('via', 'sprint') . ' ' . $sourceLabel . ' ' . strip_tags($sourceLink);
        }
        unset($e);

        // Build the search blob last so source text is filterable.
        foreach ($entries as &$e) {
            $e['search'] = implode(' ', [
                $e['user_name'],
                $e['action'],
                strip_tags($e['item_html']),
                strip_tags($e['change_html']),
                $e['area'],
                $e['source_text'],
            ]);
        }
        unset($e);

        return $entries;
    }

    /**
     * Figure out which item-ids (per itemtype) belong to this sprint.
     */
    private static function resolveTargetIds(int $sprintId): array
    {
        $itemIds = [];
        foreach ((new SprintItem())->find(['plugin_sprint_sprints_id' => $sprintId]) as $r) {
            $itemIds[] = (int)$r['id'];
        }

        $memberIds = [];
        foreach ((new SprintMember())->find(['plugin_sprint_sprints_id' => $sprintId]) as $r) {
            $memberIds[] = (int)$r['id'];
        }

        $meetingIds = [];
        foreach ((new SprintMeeting())->find(['plugin_sprint_sprints_id' => $sprintId]) as $r) {
            $meetingIds[] = (int)$r['id'];
        }

        $fastlaneMemberIds = [];
        $dependencyIds     = [];
        if (!empty($itemIds)) {
            foreach ((new SprintFastlaneMember())->find(['plugin_sprint_sprintitems_id' => $itemIds]) as $r) {
                $fastlaneMemberIds[] = (int)$r['id'];
            }
            if (SprintItemDependency::isTableReady()) {
                foreach ((new SprintItemDependency())->find(['plugin_sprint_sprintitems_id' => $itemIds]) as $r) {
                    $dependencyIds[] = (int)$r['id'];
                }
            }
        }

        return [
            ['itemtype' => Sprint::class,                'ids' => [$sprintId]],
            ['itemtype' => SprintItem::class,            'ids' => $itemIds],
            ['itemtype' => SprintMember::class,          'ids' => $memberIds],
            ['itemtype' => SprintMeeting::class,         'ids' => $meetingIds],
            ['itemtype' => SprintFastlaneMember::class,  'ids' => $fastlaneMemberIds],
            ['itemtype' => SprintItemDependency::class,  'ids' => $dependencyIds],
        ];
    }

    private static function getAreaLabels(): array
    {
        return [
            'sprint'     => __('Sprint', 'sprint'),
            'item'       => __('Sprint item', 'sprint'),
            'member'     => __('Member', 'sprint'),
            'meeting'    => __('Meeting', 'sprint'),
            'fastlane'   => __('Fastlane', 'sprint'),
            'dependency' => __('Dependency', 'sprint'),
        ];
    }

    private static function sourceTypeLabel(string $itemtype): string
    {
        switch ($itemtype) {
            case SprintMeeting::class: return __('Meeting', 'sprint');
            default:                   return $itemtype;
        }
    }

    private static function areaForItemtype(string $itemtype): string
    {
        switch ($itemtype) {
            case Sprint::class:                 return 'sprint';
            case SprintItem::class:             return 'item';
            case SprintMember::class:           return 'member';
            case SprintMeeting::class:          return 'meeting';
            case SprintFastlaneMember::class:   return 'fastlane';
            case SprintItemDependency::class:   return 'dependency';
            default:                            return 'sprint';
        }
    }

    private static function areaColor(string $area): string
    {
        switch ($area) {
            case 'sprint':     return '#0d6efd';
            case 'item':       return '#198754';
            case 'member':     return '#6f42c1';
            case 'meeting':    return '#e67e22';
            case 'fastlane':   return '#fd7e14';
            case 'dependency': return '#20c997';
            default:           return '#6c757d';
        }
    }

    /**
     * Display name for the affected item; falls back to itemtype + id
     * when the row has already been purged.
     */
    private static function formatItem(string $itemtype, int $itemsId): string
    {
        $typeLabel = [
            Sprint::class                => __('Sprint', 'sprint'),
            SprintItem::class            => __('Sprint item', 'sprint'),
            SprintMember::class          => __('Member', 'sprint'),
            SprintMeeting::class         => __('Meeting', 'sprint'),
            SprintFastlaneMember::class  => __('Fastlane allocation', 'sprint'),
            SprintItemDependency::class  => __('Dependency allocation', 'sprint'),
        ][$itemtype] ?? $itemtype;

        if (!class_exists($itemtype)) {
            return htmlescape($typeLabel) . " #{$itemsId}";
        }

        $obj = new $itemtype();
        if (!$obj->getFromDB($itemsId)) {
            return "<span class='text-muted'>" . htmlescape($typeLabel) . " #{$itemsId}</span>";
        }

        $name = (string)($obj->fields['name'] ?? '');
        if ($name === '' && isset($obj->fields['users_id'])) {
            $name = SprintCache::userName((int)$obj->fields['users_id']);
        }
        if ($name === '') {
            $name = $typeLabel . " #{$itemsId}";
        }

        $url = '';
        if (method_exists($itemtype, 'getFormURLWithID')) {
            $url = $itemtype::getFormURLWithID($itemsId);
        }

        if ($url !== '') {
            return "<a href='" . htmlescape($url) . "'>" . htmlescape($name) . "</a>";
        }
        return htmlescape($name);
    }

    /**
     * Resolve a search-option id to its field label via the itemtype's
     * rawSearchOptions(). Cached per-itemtype to stay cheap over 500 rows.
     */
    private static function fieldLabel(string $itemtype, int $searchOptId): string
    {
        static $cache = [];
        if ($searchOptId <= 0 || !class_exists($itemtype)) {
            return '';
        }
        if (!isset($cache[$itemtype])) {
            $cache[$itemtype] = [];
            $obj = new $itemtype();
            if (method_exists($obj, 'rawSearchOptions')) {
                foreach ($obj->rawSearchOptions() as $opt) {
                    if (isset($opt['id'], $opt['name'])) {
                        $cache[$itemtype][(int)$opt['id']] = (string)$opt['name'];
                    }
                }
            }
        }
        return $cache[$itemtype][$searchOptId] ?? '';
    }

    /**
     * Translate GLPI's numeric linked_action enum into short localized verbs.
     */
    private static function actionLabel(int $action): string
    {
        switch ($action) {
            case 0:   return __('Modified', 'sprint');       // HISTORY_UPDATE_*
            case 1:   return __('Relation added', 'sprint');
            case 2:   return __('Relation updated', 'sprint');
            case 3:   return __('Relation removed', 'sprint');
            case 4:   return __('Document added', 'sprint');
            case 5:   return __('Document removed', 'sprint');
            case 6:   return __('Relation added', 'sprint');
            case 7:   return __('Relation removed', 'sprint');
            case 8:   return __('Problem added', 'sprint');
            case 9:   return __('Problem removed', 'sprint');
            case 10:  return __('Linked to item', 'sprint');
            case 11:  return __('Unlinked from item', 'sprint');
            case 14:  return __('User added', 'sprint');
            case 15:  return __('User removed', 'sprint');
            case 16:  return __('Group added', 'sprint');
            case 17:  return __('Group removed', 'sprint');
            case 20:  return __('Created', 'sprint');
            case 21:  return __('Deleted (soft)', 'sprint');
            case 22:  return __('Restored', 'sprint');
            case 23:  return __('Purged', 'sprint');
            default:  return __('Modified', 'sprint');
        }
    }

    /**
     * Render the old→new diff. A long value is cut for the table and the
     * full text sits in an expandable detail right under it.
     */
    private static function formatChange(int $action, string $oldValue, string $newValue): string
    {
        // Purely informational actions don't carry a diff.
        if (in_array($action, [20, 21, 22, 23], true)) {
            return '<span class="text-muted fst-italic">-</span>';
        }

        $old  = trim(strip_tags($oldValue));
        $new  = trim(strip_tags($newValue));
        $long = mb_strlen($old) > 80 || mb_strlen($new) > 80;
        $short = static function (string $s): string {
            if ($s === '') {
                return '<span class="text-muted">∅</span>';
            }
            return htmlescape(mb_strlen($s) > 80 ? mb_substr($s, 0, 77) . '…' : $s);
        };
        $arrow = ' <i class="fas fa-long-arrow-alt-right text-muted mx-1" aria-hidden="true"></i>'
            . '<span class="visually-hidden">' . htmlescape(__('changed to', 'sprint')) . '</span> ';

        $html = $short($old) . $arrow . $short($new);
        if (!$long) {
            return $html;
        }
        return $html . '<details class="sprint-audit-full sprint-small mt-1"><summary>'
            . htmlescape(__('Show full change', 'sprint')) . '</summary>'
            . '<div class="sprint-audit-full-body"><div><strong>' . htmlescape(__('Before', 'sprint')) . ':</strong> '
            . ($old !== '' ? nl2br(htmlescape($old)) : '∅') . '</div>'
            . '<div><strong>' . htmlescape(__('After', 'sprint')) . ':</strong> '
            . ($new !== '' ? nl2br(htmlescape($new)) : '∅') . '</div></div></details>';
    }

    /**
     * glpi_logs.user_name is "Login (id)" or a raw login; strip any
     * trailing id to keep the display part.
     */
    private static function stripUserName(string $raw): string
    {
        return trim(preg_replace('/\s*\(\d+\)\s*$/', '', $raw));
    }

    /**
     * Returns [windowStart, windowEnd] (Y-m-d H:i:s strings or null),
     * bounded by the sprint's own start/end dates. Null means "no bound"
     * (sprint hasn't started/ended yet).
     */
    public static function getRetentionWindowForSprint(int $sprintId): array
    {
        $sprint = new Sprint();
        if ($sprintId <= 0 || !$sprint->getFromDB($sprintId)) {
            return [null, null];
        }
        $start = !empty($sprint->fields['date_start'])
            ? date('Y-m-d 00:00:00', strtotime((string)$sprint->fields['date_start']))
            : null;
        $end = !empty($sprint->fields['date_end'])
            ? date('Y-m-d 23:59:59', strtotime((string)$sprint->fields['date_end']))
            : null;
        return [$start, $end];
    }

    /**
     * Reconstruct the `status` value of each given SprintItem as it was at
     * (or just before) $timestamp, using glpi_logs (id_search_option = 3,
     * the SprintItem status field). Returns [itemId => statusString].
     *
     * Reconstruction per item:
     *   - latest status-change log with date_mod <= $timestamp → its new_value
     *   - else, earliest log after $timestamp → its old_value
     *   - else (no status changes recorded) → the current status passed in,
     *     since the status never changed it also held at $timestamp.
     *
     * @param int[]             $itemIds
     * @param string            $timestamp        Y-m-d H:i:s
     * @param array<int,string> $currentStatuses  [itemId => current status]
     * @return array<int,string>
     */
    public static function getItemStatusAtTimestamp(array $itemIds, string $timestamp, array $currentStatuses = []): array
    {
        return self::getItemStatusTimeline($itemIds, [$timestamp], $currentStatuses)[$timestamp] ?? [];
    }

    /**
     * Status of every item at each of the given timestamps, from one query on
     * the log. Same reconstruction as {@see getItemStatusAtTimestamp()} — the
     * burndowns ask for every day of a sprint, which used to cost a query a day.
     *
     * @param int[]    $itemIds
     * @param string[] $timestamps 'Y-m-d H:i:s'
     * @return array<string,array<int,string>> [timestamp][item id] => status
     */
    public static function getItemStatusTimeline(array $itemIds, array $timestamps, array $currentStatuses = []): array
    {
        global $DB;

        $itemIds    = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        $timestamps = array_values(array_unique(array_map('strval', $timestamps)));
        $out        = array_fill_keys($timestamps, []);
        if (empty($itemIds) || empty($timestamps)) {
            return $out;
        }

        $logsByItem = [];
        if ($DB->tableExists('glpi_logs')) {
            foreach ($DB->request([
                'SELECT' => ['items_id', 'date_mod', 'old_value', 'new_value'],
                'FROM'   => 'glpi_logs',
                'WHERE'  => [
                    'itemtype'         => SprintItem::class,
                    'items_id'         => $itemIds,
                    'id_search_option' => 3,
                ],
                'ORDER'  => ['date_mod ASC', 'id ASC'],
            ]) as $r) {
                $logsByItem[(int)$r['items_id']][] = $r;
            }
        }

        foreach ($timestamps as $timestamp) {
            foreach ($itemIds as $id) {
                $valueBefore   = null;
                $firstAfterOld = null;
                if ($timestamp !== '') {
                    foreach ($logsByItem[$id] ?? [] as $log) {
                        if ((string)$log['date_mod'] <= $timestamp) {
                            $valueBefore = (string)$log['new_value'];
                        } elseif ($firstAfterOld === null) {
                            $firstAfterOld = (string)$log['old_value'];
                        }
                    }
                }
                if ($valueBefore !== null) {
                    $out[$timestamp][$id] = $valueBefore;
                } elseif ($firstAfterOld !== null) {
                    $out[$timestamp][$id] = $firstAfterOld;
                } else {
                    $out[$timestamp][$id] = (string)($currentStatuses[$id] ?? '');
                }
            }
        }

        return $out;
    }

    /**
     * Delete sprint-related glpi_logs rows older than every existing
     * sprint's window, so a sprint's history survives as long as it does.
     * Called opportunistically from the audit tab and via {@see cronAuditCleanup()}.
     */
    public static function pruneOldLogs(): int
    {
        global $DB;

        if (!$DB->tableExists('glpi_logs')) {
            return 0;
        }

        // Anything older than the earliest sprint start can't belong to a
        // visible window and is safe to purge; no start dates → leave alone.
        $earliest = null;
        foreach ($DB->request([
            'SELECT' => ['MIN' => 'date_start AS earliest'],
            'FROM'   => Sprint::getTable(),
            'WHERE'  => ['NOT' => ['date_start' => null]],
        ]) as $r) {
            $earliest = $r['earliest'] ?? null;
        }
        if ($earliest === null) {
            return 0;
        }

        $itemtypes = [
            Sprint::class,
            SprintItem::class,
            SprintMember::class,
            SprintMeeting::class,
            SprintFastlaneMember::class,
            SprintItemDependency::class,
        ];

        $DB->delete('glpi_logs', [
            'itemtype' => $itemtypes,
            ['date_mod' => ['<', $earliest]],
        ]);

        $deleted = (int)$DB->affectedRows();

        // Drop attribution rows pointing at logs we just pruned.
        if ($DB->tableExists('glpi_plugin_sprint_audit_sources')) {
            $DB->doQuery(
                "DELETE s FROM `glpi_plugin_sprint_audit_sources` s
                 LEFT JOIN `glpi_logs` l ON l.`id` = s.`glpi_logs_id`
                 WHERE l.`id` IS NULL"
            );
        }

        return $deleted;
    }

    /**
     * {logId => true} for log rows attributed to a meeting save.
     */
    private static function loadMeetingSourcedLogIds(): array
    {
        global $DB;

        $ids = [];
        if (!$DB->tableExists('glpi_plugin_sprint_audit_sources')) {
            return $ids;
        }

        $iter = $DB->request([
            'SELECT' => ['glpi_logs_id'],
            'FROM'   => 'glpi_plugin_sprint_audit_sources',
            'WHERE'  => ['source_itemtype' => SprintMeeting::class],
        ]);
        foreach ($iter as $r) {
            $ids[(int)$r['glpi_logs_id']] = true;
        }
        return $ids;
    }

    /**
     * Highest glpi_logs.id (0 if empty/missing). A "before" marker around
     * an update; pair with tagNewLogsAsMeetingSourced() to attribute new rows.
     */
    public static function snapshotMaxLogId(): int
    {
        global $DB;

        if (!$DB->tableExists('glpi_logs')) {
            return 0;
        }
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_logs',
            'ORDER'  => ['id DESC'],
            'LIMIT'  => 1,
        ]) as $r) {
            return (int)$r['id'];
        }
        return 0;
    }

    /**
     * Tag glpi_logs rows written for this SprintItem after the snapshot as
     * meeting-sourced. No-op without the side-table or with a missing id.
     */
    public static function tagNewLogsAsMeetingSourced(int $afterLogId, int $itemId, int $meetingId): void
    {
        global $DB;

        if ($meetingId <= 0 || $itemId <= 0) {
            return;
        }
        if (!$DB->tableExists('glpi_plugin_sprint_audit_sources')) {
            return;
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_logs',
            'WHERE'  => [
                'id'       => ['>', $afterLogId],
                'itemtype' => SprintItem::class,
                'items_id' => $itemId,
            ],
        ]);
        foreach ($iter as $logRow) {
            $DB->insert('glpi_plugin_sprint_audit_sources', [
                'glpi_logs_id'    => (int)$logRow['id'],
                'source_itemtype' => SprintMeeting::class,
                'source_items_id' => $meetingId,
                'date_creation'   => $now,
            ]);
        }
    }

    /**
     * @param int[] $logIds
     * @return array<int, array{itemtype:string, items_id:int}>
     */
    private static function loadSourcesForLogIds(array $logIds): array
    {
        global $DB;

        $sources = [];
        if (empty($logIds) || !$DB->tableExists('glpi_plugin_sprint_audit_sources')) {
            return $sources;
        }

        $iter = $DB->request([
            'SELECT' => ['glpi_logs_id', 'source_itemtype', 'source_items_id'],
            'FROM'   => 'glpi_plugin_sprint_audit_sources',
            'WHERE'  => ['glpi_logs_id' => array_map('intval', $logIds)],
        ]);
        foreach ($iter as $r) {
            $sources[(int)$r['glpi_logs_id']] = [
                'itemtype' => (string)$r['source_itemtype'],
                'items_id' => (int)$r['source_items_id'],
            ];
        }
        return $sources;
    }

    /**
     * Aggregate audit events per member per day for the dashboard activity
     * chart. Counts only glpi_logs rows (real changes; GLPI doesn't log
     * views) and only for registered sprint members.
     *
     * @return array {
     *   dates:   string[]   list of YYYY-MM-DD day buckets (ascending),
     *   members: array<array{user_id:int,name:string,color:string,counts:int[],total:int}>
     * }
     */
    public static function getMemberActivity(int $sprintId, ?\DateTimeImmutable $overrideFrom = null, ?\DateTimeImmutable $overrideTo = null): array
    {
        global $DB;

        $empty = ['dates' => [], 'members' => []];

        if ($sprintId <= 0 || !$DB->tableExists('glpi_logs')) {
            return $empty;
        }

        $today      = new \DateTimeImmutable('today');
        $sprint     = new Sprint();
        $sprintStart = null;
        $sprintEnd   = null;
        if ($sprint->getFromDB($sprintId)) {
            if (!empty($sprint->fields['date_start'])) {
                try {
                    $sprintStart = new \DateTimeImmutable(substr((string)$sprint->fields['date_start'], 0, 10));
                } catch (\Exception $e) { /* ignore */ }
            }
            if (!empty($sprint->fields['date_end'])) {
                try {
                    $sprintEnd = new \DateTimeImmutable(substr((string)$sprint->fields['date_end'], 0, 10));
                } catch (\Exception $e) { /* ignore */ }
            }
        }

        if ($overrideFrom !== null || $overrideTo !== null) {
            // User-specified range, clamped to the sprint window.
            $rangeStart = $overrideFrom ?? $sprintStart ?? $today->modify('-30 days');
            $rangeEnd   = $overrideTo   ?? $sprintEnd   ?? $today;
            if ($sprintStart && $rangeStart < $sprintStart) { $rangeStart = $sprintStart; }
            if ($sprintEnd   && $rangeEnd   > $sprintEnd)   { $rangeEnd   = $sprintEnd; }
        } else {
            $rangeStart = $sprintStart ?? $today->modify('-30 days');
            $rangeEnd   = $sprintEnd   ?? $today;
        }
        if ($rangeEnd < $rangeStart) {
            return $empty;
        }

        // Build the ordered list of day buckets.
        $dates = [];
        $cursor = $rangeStart;
        while ($cursor <= $rangeEnd) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
        $dateIndex = array_flip($dates);

        // Only sprint members count as team activity; outsiders are ignored.
        $memberIds = [];
        foreach ((new SprintMember())->find(['plugin_sprint_sprints_id' => $sprintId]) as $r) {
            $uid = (int)$r['users_id'];
            if ($uid > 0) {
                $memberIds[$uid] = true;
            }
        }
        if (empty($memberIds)) {
            return $empty;
        }

        // glpi_logs.user_name is "Display Name (login)" — the paren token is
        // the login string, NOT a numeric id. Lookup to resolve it to a member.
        $loginToUid = [];
        $userIter = $DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['id' => array_keys($memberIds)],
        ]);
        foreach ($userIter as $u) {
            $login = trim((string)($u['name'] ?? ''));
            if ($login !== '') {
                $loginToUid[$login] = (int)$u['id'];
            }
        }

        // Meeting edits aren't item work; meeting-driven SprintItem rows are
        // filtered via the audit_sources side-table below.
        $targets = self::resolveTargetIds($sprintId);
        $orClauses = [];
        foreach ($targets as $t) {
            if (empty($t['ids']) || $t['itemtype'] === SprintMeeting::class) {
                continue;
            }
            $orClauses[] = [
                'itemtype' => $t['itemtype'],
                'items_id' => array_map('intval', $t['ids']),
            ];
        }
        if (empty($orClauses)) {
            return $empty;
        }

        $startSql = $rangeStart->format('Y-m-d') . ' 00:00:00';
        $endSql   = $rangeEnd->format('Y-m-d')   . ' 23:59:59';

        // Drop log rows attributed to a meeting save (set is small —
        // only meeting-driven logs are recorded).
        $meetingSourcedLogIds = self::loadMeetingSourcedLogIds();

        $criteria = [
            'SELECT' => ['id', 'user_name', 'date_mod'],
            'FROM'   => 'glpi_logs',
            'WHERE'  => [
                'date_mod' => ['>=', $startSql],
                ['date_mod' => ['<=', $endSql]],
                'OR'       => $orClauses,
            ],
        ];

        // { user_id => { date_str => count } }
        $counts = [];
        foreach ($DB->request($criteria) as $row) {
            if (isset($meetingSourcedLogIds[(int)$row['id']])) {
                continue;
            }
            $raw = (string)($row['user_name'] ?? '');
            $uid = 0;
            // Paren token is usually the login, occasionally a numeric id:
            // try numeric first, then resolve login → id via the member lookup.
            if (preg_match('/\(([^)]+)\)\s*$/', $raw, $m)) {
                $token = trim($m[1]);
                if (ctype_digit($token)) {
                    $uid = (int)$token;
                } elseif (isset($loginToUid[$token])) {
                    $uid = $loginToUid[$token];
                }
            }
            if ($uid <= 0 || !isset($memberIds[$uid])) {
                continue;
            }

            $day = substr((string)$row['date_mod'], 0, 10);
            if (!isset($dateIndex[$day])) {
                continue;
            }

            if (!isset($counts[$uid])) {
                $counts[$uid] = array_fill_keys($dates, 0);
            }
            $counts[$uid][$day]++;
        }

        // Stable palette of distinct hues, cycled — no palette library needed.
        $palette = [
            '#0d6efd', '#198754', '#fd7e14', '#6f42c1', '#dc3545',
            '#20c997', '#d63384', '#0dcaf0', '#ffc107', '#6c757d',
        ];

        $members = [];
        $paletteIdx = 0;
        foreach ($counts as $uid => $byDay) {
            $seq = [];
            $total = 0;
            foreach ($dates as $d) {
                $c = (int)($byDay[$d] ?? 0);
                $seq[] = $c;
                $total += $c;
            }
            if ($total === 0) {
                continue;
            }
            $members[] = [
                'user_id' => $uid,
                'name'    => SprintCache::userName($uid) ?: ('#' . $uid),
                'color'   => $palette[$paletteIdx % count($palette)],
                'counts'  => $seq,
                'total'   => $total,
            ];
            $paletteIdx++;
        }

        // Most-active first in the legend.
        usort($members, fn($a, $b) => $b['total'] <=> $a['total']);

        return ['dates' => $dates, 'members' => $members];
    }

    // GLPI cron task — registered via plugin_sprint_install() so a nightly
    // task prunes sprint logs even for sprints nobody opens.

    public static function cronInfo(string $name): array
    {
        return [
            'description' => __('Purge sprint audit log entries older than the retention window', 'sprint'),
        ];
    }

    public static function cronAuditCleanup(\CronTask $task): int
    {
        $deleted = self::pruneOldLogs();
        $task->addVolume($deleted);
        return $deleted > 0 ? 1 : 0;
    }
}
