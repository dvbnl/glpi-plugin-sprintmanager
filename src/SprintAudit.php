<?php

namespace GlpiPlugin\Sprint;

use CommonGLPI;
use DBmysqlIterator;
use Html;
use Session;

/**
 * SprintAudit - Consolidated audit trail for a sprint.
 *
 * Aggregates rows from GLPI's native `glpi_logs` table for the sprint
 * itself plus every owned sub-entity (items, members, meetings,
 * fastlane allocations), and renders them as a single chronological
 * audit log with timestamps + acting user.
 *
 * Registered as a tab on Sprint. Uses only GLPI's built-in logging —
 * no new tables, no writes. Works out of the box because each tracked
 * class declares `$dohistory = true` on their CommonDBTM subclass.
 */
class SprintAudit extends CommonGLPI
{
    public static $rightname = 'plugin_sprint_sprint';

    /**
     * Audit-log retention is now scoped per-sprint: log rows are kept
     * as long as the sprint they belong to exists, so we can always look
     * back at a finished sprint's history. This constant is kept as a
     * fallback for code paths that still need a default upper bound when
     * a sprint has no start/end date set.
     */
    const RETENTION_DAYS = 14;

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

    /**
     * Render the audit log for a single sprint.
     */
    public static function showForSprint(Sprint $sprint): void
    {
        $sprintId = (int)$sprint->getID();
        if ($sprintId <= 0) {
            return;
        }

        // Opportunistic cleanup — keeps the glpi_logs table from growing
        // unbounded for sprint-related itemtypes. Cheap when there's
        // nothing to delete (indexed on date_mod).
        self::pruneOldLogs();

        $entries = self::collectEntries($sprintId);

        echo "<div class='center'>";

        $barId = 'sprint-audit-filter-' . mt_rand();
        echo "<div id='{$barId}' class='sprint-filter-bar d-flex flex-wrap align-items-center gap-2 p-2 mb-2' "
            . "style='background:#f1f3f5;border-radius:6px;max-width:1200px;margin-left:auto;margin-right:auto;'>";
        echo "<div class='d-flex align-items-center gap-1 text-muted small'>"
            . "<i class='fas fa-filter'></i><span>" . __('Filter', 'sprint') . "</span></div>";
        echo "<input type='search' class='form-control form-control-sm sf-text' "
            . "style='max-width:240px;' placeholder='" . __('Search action or user...', 'sprint') . "'>";
        echo "<select class='form-select form-select-sm sf-status' style='max-width:180px;'>";
        echo "<option value=''>" . __('All areas', 'sprint') . "</option>";
        $areas = self::getAreaLabels();
        foreach ($areas as $key => $label) {
            echo "<option value='" . htmlescape($key) . "'>" . htmlescape($label) . "</option>";
        }
        echo "</select>";
        echo "<button type='button' class='btn btn-sm btn-outline-secondary sf-reset' "
            . "data-sprint-action='filter-reset'>"
            . "<i class='fas fa-times me-1'></i>" . __('Reset', 'sprint') . "</button>";
        echo "<span class='ms-auto text-muted small'>" . count($entries) . " " . __('entries', 'sprint') . "</span>";
        echo "</div>";

        echo "<table class='tab_cadre_fixe sprint-audit-table'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th style='width:160px;'>" . __('When', 'sprint') . "</th>";
        echo "<th style='width:120px;'>" . __('Area', 'sprint') . "</th>";
        echo "<th>" . __('Item') . "</th>";
        echo "<th>" . __('Action', 'sprint') . "</th>";
        echo "<th>" . __('Change', 'sprint') . "</th>";
        echo "<th style='width:160px;'>" . __('User') . "</th>";
        echo "</tr>";

        if (count($entries) === 0) {
            echo "<tr class='tab_bg_1'><td colspan='6' class='center' style='padding:32px;color:#6c757d;'>"
                . "<i class='fas fa-inbox' style='font-size:2em;display:block;margin-bottom:8px;opacity:0.5;'></i>"
                . __('No audit entries yet', 'sprint') . "</td></tr>";
        }

        foreach ($entries as $e) {
            $areaLabel = $areas[$e['area']] ?? $e['area'];
            $areaColor = self::areaColor($e['area']);
            echo "<tr class='tab_bg_1 sprint-filterable-row' "
                . "data-item-name='" . htmlescape(mb_strtolower($e['search'])) . "' "
                . "data-item-status='" . htmlescape($e['area']) . "'>";
            echo "<td style='white-space:nowrap;color:#495057;'><i class='far fa-clock text-muted me-1'></i>"
                . htmlescape($e['when']) . "</td>";
            echo "<td><span style='display:inline-block;padding:3px 10px;border-radius:10px;"
                . "background:{$areaColor}22;color:{$areaColor};font-weight:600;font-size:0.78em;'>"
                . htmlescape($areaLabel) . "</span></td>";
            echo "<td>" . $e['item_html'] . "</td>";
            echo "<td>" . htmlescape($e['action']) . $e['source_html'] . "</td>";
            echo "<td>" . $e['change_html'] . "</td>";
            echo "<td style='white-space:nowrap;'>"
                . ($e['user_name'] !== ''
                    ? "<i class='fas fa-user text-muted me-1'></i>" . htmlescape($e['user_name'])
                    : '<span class="text-muted fst-italic">' . __('System', 'sprint') . '</span>')
                . "</td>";
            echo "</tr>";
        }

        echo "</table>";
        echo "</div>";
    }

    /**
     * Collect log entries across all sprint-related itemtypes, sort by
     * timestamp descending, and return a render-ready list.
     */
    private static function collectEntries(int $sprintId): array
    {
        global $DB;

        if (!$DB->tableExists('glpi_logs')) {
            return [];
        }

        // Map every itemtype we log for this sprint to the area it
        // belongs to + the specific ids that fall under this sprint.
        $targets = self::resolveTargetIds($sprintId);

        // Build a DB criteria array — GLPI 11 forbids raw SQL strings
        // passed through $DB->request().
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

        // Top-level array keys are implicitly AND'd in GLPI's DB iterator.
        // Nested 'OR' groups the itemtype/items_id combinations together.
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
            'LIMIT'  => 500,
        ];

        $entries = [];
        $nameCache = [];
        $logIds = [];

        foreach ($DB->request($criteria) as $row) {
            $area = self::areaForItemtype($row['itemtype']);

            // glpi_logs stores the actor as a single string in `user_name`,
            // typically formatted as "Display Name (login_id)". Parse out
            // the numeric id when present so we can resolve the user's
            // current display name (respecting rename etc.).
            $rawUser = (string)($row['user_name'] ?? '');
            $userId  = 0;
            if (preg_match('/\((\d+)\)\s*$/', $rawUser, $m)) {
                $userId = (int)$m[1];
            }
            if ($userId > 0) {
                $userName = $nameCache[$userId] ?? ($nameCache[$userId] = getUserName($userId));
            } else {
                $userName = self::stripUserName($rawUser);
            }

            $linkedAction = (int)($row['linked_action'] ?? 0);
            $searchOptId  = (int)($row['id_search_option'] ?? 0);

            // Action label: map numeric linked_action to a short verb,
            // plus the affected field name when this is a field update.
            $fieldLabel = self::fieldLabel((string)$row['itemtype'], $searchOptId);
            $action = self::actionLabel($linkedAction);
            if ($fieldLabel !== '' && $linkedAction === 0) {
                $action .= ': ' . $fieldLabel;
            }

            // Change summary: "<old> → <new>" for field updates, or a
            // plain description for adds/purges.
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
     * Fetch the display name for the affected item. Falls back to the
     * itemtype + id when the row has already been purged.
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
            $name = getUserName((int)$obj->fields['users_id']);
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
     * Resolve a search option id back to its field label, using each
     * tracked itemtype's rawSearchOptions(). Cached per-itemtype so
     * the lookup stays cheap over the 500-row loop.
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
     * GLPI stores linked_action as a numeric enum. Translate the ones we
     * care about into short, localized verbs.
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
     * Render the old→new diff. Values are already plain strings from
     * glpi_logs; truncate long text to keep the table readable.
     */
    private static function formatChange(int $action, string $oldValue, string $newValue): string
    {
        $short = function (string $s): string {
            $s = trim(strip_tags($s));
            if (mb_strlen($s) > 80) {
                $s = mb_substr($s, 0, 77) . '…';
            }
            return htmlescape($s);
        };

        // Purely informational actions don't carry a diff.
        if (in_array($action, [20, 21, 22, 23], true)) {
            return '<span class="text-muted fst-italic">-</span>';
        }

        $oldHtml = $oldValue !== '' ? $short($oldValue) : '<span class="text-muted">∅</span>';
        $newHtml = $newValue !== '' ? $short($newValue) : '<span class="text-muted">∅</span>';

        return $oldHtml . ' <i class="fas fa-long-arrow-alt-right text-muted mx-1"></i> ' . $newHtml;
    }

    /**
     * GLPI stores `user_name` as "Login (id)" or a raw login string.
     * Strip any trailing id to keep only the display part.
     */
    private static function stripUserName(string $raw): string
    {
        return trim(preg_replace('/\s*\(\d+\)\s*$/', '', $raw));
    }

    /**
     * Returns [windowStart, windowEnd] (Y-m-d H:i:s strings or null) for
     * the audit log of a specific sprint. Bounded by the sprint's own
     * start/end dates so old sprints stay fully viewable; nulls mean "no
     * bound" — i.e. the sprint hasn't started or hasn't ended yet.
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
        global $DB;

        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        $out = [];
        if (empty($itemIds)) {
            return $out;
        }

        $logsByItem = [];
        if ($timestamp !== '' && $DB->tableExists('glpi_logs')) {
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

        foreach ($itemIds as $id) {
            $valueBefore   = null;
            $firstAfterOld = null;
            foreach ($logsByItem[$id] ?? [] as $log) {
                if ((string)$log['date_mod'] <= $timestamp) {
                    $valueBefore = (string)$log['new_value'];
                } elseif ($firstAfterOld === null) {
                    $firstAfterOld = (string)$log['old_value'];
                }
            }
            if ($valueBefore !== null) {
                $out[$id] = $valueBefore;
            } elseif ($firstAfterOld !== null) {
                $out[$id] = $firstAfterOld;
            } else {
                $out[$id] = (string)($currentStatuses[$id] ?? '');
            }
        }

        return $out;
    }

    /**
     * Delete sprint-related glpi_logs rows that fall outside the union of
     * all existing sprints' windows. As long as a sprint still exists,
     * its audit history is preserved indefinitely — this matters when
     * looking back at completed sprints.
     *
     * Called opportunistically from the audit tab, and on a schedule by
     * {@see cronAuditCleanup()}.
     */
    public static function pruneOldLogs(): int
    {
        global $DB;

        if (!$DB->tableExists('glpi_logs')) {
            return 0;
        }

        // Earliest sprint start across the system. Anything older than
        // that cannot belong to a visible sprint window and is safe to
        // purge. If no sprint has a start date, leave glpi_logs alone.
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
     * Highest current glpi_logs.id, or 0 if the table is empty/missing.
     * Use as a "before" marker around an update; pair with
     * tagNewLogsAsMeetingSourced() to attribute the new rows.
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
     * Mark every glpi_logs row written for this SprintItem after the
     * given snapshot as caused by a meeting save. No-op when the
     * side-table doesn't exist or any id is missing.
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
     * Aggregate audit-log events per sprint member per day, for the
     * global-dashboard activity chart. Only counts rows from glpi_logs
     * (actual field/relation changes — GLPI does not log views) and only
     * attributes events to users who are registered members of this
     * sprint.
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
            // User-specified range — clamped to the sprint window so we
            // don't advertise days outside the sprint we're viewing.
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

        // Sprint members define which users count as "team activity".
        // Events from people outside the team are ignored.
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

        // glpi_logs.user_name is typically formatted as "Display Name (login)"
        // — where `login` is the user's login string, NOT their numeric id.
        // Build a lookup so we can resolve either form to a sprint member.
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

        // Meeting-record edits don't count as item work; SprintItem rows
        // that fanned out from a meeting save are filtered via the
        // audit_sources side-table below.
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
            // glpi_logs typically stores actor as "Display Name (login)".
            // The token in parens is usually the login string, occasionally
            // a numeric id. Try numeric first, then resolve login → id via
            // the sprint-member lookup we built above.
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

        // Stable palette — cycle through a handful of distinct hues. Keeps
        // labels legible without pulling in a palette library.
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
                'name'    => getUserName($uid) ?: ('#' . $uid),
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

    // === GLPI cron task integration ===
    //
    // Registered via plugin_sprint_install() so a nightly task prunes
    // sprint-related log entries even for sprints nobody opens.

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
