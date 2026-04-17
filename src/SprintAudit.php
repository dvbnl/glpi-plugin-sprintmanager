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

    /** Log entries older than this many days are hidden from the audit
     *  tab AND purged by {@see pruneOldLogs()}. */
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

        // Filter bar: free-text search + event type dropdown + apply + reset.
        $barId = 'sprint-audit-filter-' . mt_rand();
        echo "<div id='{$barId}' class='sprint-filter-bar d-flex flex-wrap align-items-center gap-2 p-2 mb-2' "
            . "style='background:#f1f3f5;border-radius:6px;max-width:1200px;margin-left:auto;margin-right:auto;'>";
        echo "<div class='d-flex align-items-center gap-1 text-muted small'>"
            . "<i class='fas fa-filter'></i><span>" . __('Filter', 'sprint') . "</span></div>";
        // No inline handlers — sprint.js detects audit mode by the
        // presence of `.sprint-audit-kind` inside the bar and then
        // filters `tr.sprint-audit-row` on `data-search` + `data-area`.
        echo "<input type='search' class='form-control form-control-sm sf-text' "
            . "style='max-width:240px;' placeholder='" . __('Search action or user...', 'sprint') . "'>";
        echo "<select class='form-select form-select-sm sprint-audit-kind' style='max-width:180px;'>";
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
            echo "<tr class='tab_bg_1 sprint-audit-row' "
                . "data-search='" . htmlescape(mb_strtolower($e['search'])) . "' "
                . "data-area='" . htmlescape($e['area']) . "'>";
            echo "<td style='white-space:nowrap;color:#495057;'><i class='far fa-clock text-muted me-1'></i>"
                . htmlescape($e['when']) . "</td>";
            echo "<td><span style='display:inline-block;padding:3px 10px;border-radius:10px;"
                . "background:{$areaColor}22;color:{$areaColor};font-weight:600;font-size:0.78em;'>"
                . htmlescape($areaLabel) . "</span></td>";
            echo "<td>" . $e['item_html'] . "</td>";
            echo "<td>" . htmlescape($e['action']) . "</td>";
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

        // The audit filter is wired by sprint.js (CSP-safe delegation on
        // data-sprint-action="audit-reset" + input/change on
        // .sprint-audit-kind / .sf-text inside a .sprint-filter-bar that
        // contains rows with class .sprint-audit-row). No inline <script>
        // is emitted here so a strict Content-Security-Policy (no
        // `'unsafe-inline'`) won't block it.
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

        $cutoff = date('Y-m-d H:i:s', time() - (self::RETENTION_DAYS * 86400));

        // Top-level array keys are implicitly AND'd in GLPI's DB iterator.
        // Nested 'OR' groups the itemtype/items_id combinations together.
        $criteria = [
            'SELECT' => '*',
            'FROM'   => 'glpi_logs',
            'WHERE'  => [
                'date_mod' => ['>=', $cutoff],
                'OR'       => $orClauses,
            ],
            'ORDER'  => ['date_mod DESC'],
            'LIMIT'  => 500,
        ];

        $entries = [];
        $nameCache = [];

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

            $search = implode(' ', [
                $userName,
                $action,
                strip_tags($itemHtml),
                strip_tags($changeHtml),
                $area,
            ]);

            $entries[] = [
                'when'        => $whenText,
                'ts'          => $whenTs ?: 0,
                'area'        => $area,
                'item_html'   => $itemHtml,
                'action'      => $action,
                'change_html' => $changeHtml,
                'user_id'     => $userId,
                'user_name'   => $userName ?: '',
                'search'      => $search,
            ];
        }

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
        if (!empty($itemIds)) {
            foreach ((new SprintFastlaneMember())->find(['plugin_sprint_sprintitems_id' => $itemIds]) as $r) {
                $fastlaneMemberIds[] = (int)$r['id'];
            }
        }

        return [
            ['itemtype' => Sprint::class,               'ids' => [$sprintId]],
            ['itemtype' => SprintItem::class,           'ids' => $itemIds],
            ['itemtype' => SprintMember::class,         'ids' => $memberIds],
            ['itemtype' => SprintMeeting::class,        'ids' => $meetingIds],
            ['itemtype' => SprintFastlaneMember::class, 'ids' => $fastlaneMemberIds],
        ];
    }

    private static function getAreaLabels(): array
    {
        return [
            'sprint'    => __('Sprint', 'sprint'),
            'item'      => __('Sprint item', 'sprint'),
            'member'    => __('Member', 'sprint'),
            'meeting'   => __('Meeting', 'sprint'),
            'fastlane'  => __('Fastlane', 'sprint'),
        ];
    }

    private static function areaForItemtype(string $itemtype): string
    {
        switch ($itemtype) {
            case Sprint::class:                return 'sprint';
            case SprintItem::class:            return 'item';
            case SprintMember::class:          return 'member';
            case SprintMeeting::class:         return 'meeting';
            case SprintFastlaneMember::class:  return 'fastlane';
            default:                           return 'sprint';
        }
    }

    private static function areaColor(string $area): string
    {
        switch ($area) {
            case 'sprint':   return '#0d6efd';
            case 'item':     return '#198754';
            case 'member':   return '#6f42c1';
            case 'meeting':  return '#e67e22';
            case 'fastlane': return '#fd7e14';
            default:         return '#6c757d';
        }
    }

    /**
     * Fetch the display name for the affected item. Falls back to the
     * itemtype + id when the row has already been purged.
     */
    private static function formatItem(string $itemtype, int $itemsId): string
    {
        $typeLabel = [
            Sprint::class               => __('Sprint', 'sprint'),
            SprintItem::class           => __('Sprint item', 'sprint'),
            SprintMember::class         => __('Member', 'sprint'),
            SprintMeeting::class        => __('Meeting', 'sprint'),
            SprintFastlaneMember::class => __('Fastlane allocation', 'sprint'),
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
     * Delete sprint-related glpi_logs rows older than the retention
     * window. Safe to call from any request — the DELETE is scoped to
     * the plugin's own itemtypes.
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

        $cutoff = date('Y-m-d H:i:s', time() - (self::RETENTION_DAYS * 86400));
        $itemtypes = [
            Sprint::class,
            SprintItem::class,
            SprintMember::class,
            SprintMeeting::class,
            SprintFastlaneMember::class,
        ];

        $DB->delete('glpi_logs', [
            'itemtype' => $itemtypes,
            ['date_mod' => ['<', $cutoff]],
        ]);

        return (int)$DB->affectedRows();
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
