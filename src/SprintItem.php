<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use CommonGLPI;
use Html;
use Session;
use Dropdown;
use Ticket;
use Change;
use ProjectTask;

/**
 * SprintItem - backlog item; may link a GLPI item via itemtype + items_id.
 */
class SprintItem extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_item';
    public $dohistory        = true;

    /** @see withoutAssignGuard() */
    private static bool $skipAssignGuard = false;
    private static bool $linkedStatusAutomation = false;

    /**
     * Cascade purge. SprintItem is the source of truth for the reverse
     * "Sprints" tab, but legacy SprintTicket/Change/ProjectTask rows from
     * older add flows may still point at the same pair and must stay in sync.
     */
    public function cleanDBonPurge()
    {
        global $DB;
        if ((int)($this->fields['plugin_sprint_sprints_id'] ?? 0) === 0) {
            Backlog::logFlow($this->fields, 'out');
        }
        $rel = new SprintFastlaneMember();
        $rel->deleteByCriteria(['plugin_sprint_sprintitems_id' => $this->getID()], 1);

        SprintItemDependency::purgeForItem((int)$this->getID());

        if (SprintRequest::ensureTable()) {
            (new SprintRequest())->deleteByCriteria(
                ['plugin_sprint_sprintitems_id' => $this->getID()],
                1
            );
        }

        if ($DB->tableExists('glpi_plugin_sprint_sprintitemtags')) {
            $DB->delete('glpi_plugin_sprint_sprintitemtags', [
                'plugin_sprint_sprintitems_id' => $this->getID(),
            ]);
        }

        $sprintId = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
        $itemtype = (string)($this->fields['itemtype'] ?? '');
        $itemsId  = (int)($this->fields['items_id'] ?? 0);

        if ($sprintId <= 0 || $itemsId <= 0) {
            return;
        }

        if ($itemtype === 'Ticket') {
            (new SprintTicket())->deleteByCriteria([
                'plugin_sprint_sprints_id' => $sprintId,
                'tickets_id'               => $itemsId,
            ], 1);
        } elseif ($itemtype === 'Change') {
            (new SprintChange())->deleteByCriteria([
                'plugin_sprint_sprints_id' => $sprintId,
                'changes_id'               => $itemsId,
            ], 1);
        } elseif ($itemtype === 'Problem') {
            (new SprintProblem())->deleteByCriteria([
                'plugin_sprint_sprints_id' => $sprintId,
                'problems_id'              => $itemsId,
            ], 1);
        } elseif ($itemtype === 'ProjectTask') {
            (new SprintProjectTask())->deleteByCriteria([
                'plugin_sprint_sprints_id' => $sprintId,
                'projecttasks_id'          => $itemsId,
            ], 1);
        }
    }

    const STATUS_TODO        = 'todo';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_REVIEW      = 'review';
    const STATUS_DONE        = 'done';
    const STATUS_BLOCKED     = 'blocked';
    const STATUS_DEPENDENCY  = 'dependency';

    private function isOwnItem(): bool
    {
        return (int)($this->fields['users_id'] ?? 0) === (int)Session::getLoginUserID();
    }

    /** True when the user has only the "own items" right, not full rights. */
    private static function hasOnlyOwnRight(int $rightFlag): bool
    {
        return !Session::haveRight(self::$rightname, $rightFlag)
            && Session::haveRight(self::$rightname, Profile::RIGHT_OWN_ITEMS);
    }

    public function canCreateItem(): bool
    {
        // OWN_ITEMS right lets users create (assigned to themselves).
        if (self::hasOnlyOwnRight(CREATE)) {
            return true;
        }
        return parent::canCreateItem();
    }

    public function canUpdateItem(): bool
    {
        if (self::hasOnlyOwnRight(UPDATE)) {
            return $this->isOwnItem();
        }
        return parent::canUpdateItem();
    }

    public function canDeleteItem(): bool
    {
        if (self::hasOnlyOwnRight(DELETE)) {
            return $this->isOwnItem();
        }
        return parent::canDeleteItem();
    }

    public function canPurgeItem(): bool
    {
        if (self::hasOnlyOwnRight(PURGE)) {
            return $this->isOwnItem();
        }
        return parent::canPurgeItem();
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Sprint Item', 'Sprint Items', $nb, 'sprint');
    }

    /**
     * Search options for every field the history tracker must label. GLPI looks
     * up each changed field's label by search-option id; fields missing here get
     * id_search_option = 0 and no label on the audit tab.
     */
    public function rawSearchOptions(): array
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'status',
            'name'     => __('Status'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'priority',
            'name'     => __('Priority'),
            'datatype' => 'integer',
        ];
        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'story_points',
            'name'     => __('Story Points', 'sprint'),
            'datatype' => 'integer',
        ];
        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'capacity',
            'name'     => __('Capacity (%)', 'sprint'),
            // DECIMAL(5,1) column; 'integer' would strip the decimal point in filterValues() ("0.5" → 5)
            'datatype' => 'decimal',
        ];
        if (Config::isPlannedActualEnabled()) {
            $tab[] = [
                'id'       => 12,
                'table'    => $this->getTable(),
                'field'    => 'capacity_actual',
                'name'     => __('Actual capacity (%)', 'sprint'),
                'datatype' => 'decimal',
            ];
        }
        $tab[] = [
            'id'       => 7,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'linkfield' => 'users_id',
            'name'     => __('Owner', 'sprint'),
            'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id'       => 8,
            'table'    => $this->getTable(),
            'field'    => 'note',
            'name'     => __('Note', 'sprint'),
            'datatype' => 'text',
        ];
        $tab[] = [
            'id'       => 9,
            'table'    => $this->getTable(),
            'field'    => 'is_fastlane',
            'name'     => __('Is Fastlane', 'sprint'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => 10,
            'table'    => $this->getTable(),
            'field'    => 'itemtype',
            'name'     => __('Linked item type', 'sprint'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => 11,
            'table'    => $this->getTable(),
            'field'    => 'is_blocked',
            'name'     => __('Is Blocked', 'sprint'),
            'datatype' => 'bool',
        ];
        // Also what names the field in the audit tab: history rows carry the
        // search-option id, so without this a category change reads as a bare
        // "Modified".
        $tab[] = [
            'id'        => 13,
            'table'     => SprintCategory::getTable(),
            'field'     => 'name',
            'linkfield' => 'plugin_sprint_sprintcategories_id',
            'name'      => __('Category', 'sprint'),
            'datatype'  => 'dropdown',
        ];

        return $tab;
    }

    public static function getIcon(): string
    {
        return 'fas fa-clipboard-list';
    }

    public static function getAllStatuses(): array
    {
        return [
            self::STATUS_TODO        => __('To Do', 'sprint'),
            self::STATUS_IN_PROGRESS => __('In Progress', 'sprint'),
            self::STATUS_REVIEW      => __('In Review', 'sprint'),
            self::STATUS_DEPENDENCY  => __('Dependency', 'sprint'),
            self::STATUS_DONE        => __('Done', 'sprint'),
            self::STATUS_BLOCKED     => __('Blocked', 'sprint'),
        ];
    }

    public static function getLinkedItemTypes(): array
    {
        return [
            ''            => __('Manual item', 'sprint'),
            'Ticket'      => __('Ticket'),
            'Change'      => __('Change'),
            'Problem'     => __('Problem'),
            'ProjectTask' => __('Project task'),
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Sprint) {
            // Fastlane items live in their own tab, so exclude them here.
            $count = countElementsInTable(
                self::getTable(),
                [
                    'plugin_sprint_sprints_id' => $item->getID(),
                    'is_fastlane'              => 0,
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
     * Display name for a linked GLPI item. For ProjectTask the parent project
     * name is appended, since task names often repeat across projects.
     */
    public function getLinkedItemDisplay(): string
    {
        $itemtype = $this->fields['itemtype'] ?? '';
        $itemsId  = (int)($this->fields['items_id'] ?? 0);

        $allowedTypes = ['Ticket', 'Change', 'Problem', 'ProjectTask'];

        if (empty($itemtype) || $itemsId <= 0) {
            return '';
        }

        if (!in_array($itemtype, $allowedTypes, true) || !class_exists($itemtype)) {
            return '';
        }

        $linkedItem = SprintCache::getObject($itemtype, $itemsId);
        if ($linkedItem === null) {
            return '<span style="color:#dc3545;"><i class="fas fa-exclamation-triangle"></i> ' .
                __('Item not found', 'sprint') . '</span>';
        }

        $icons = [
            'Ticket'      => 'fas fa-ticket-alt',
            'Change'      => 'fas fa-exchange-alt',
            'Problem'     => 'fas fa-exclamation-circle',
            'ProjectTask' => 'fas fa-tasks',
        ];
        $typeNames = [
            'Ticket'      => __('Ticket'),
            'Change'      => __('Change'),
            'Problem'     => __('Problem'),
            'ProjectTask' => __('Project task'),
        ];
        $icon      = $icons[$itemtype] ?? 'fas fa-link';
        $typeLabel = $typeNames[$itemtype] ?? $itemtype;
        $url       = $itemtype::getFormURLWithID($itemsId);
        $rawName   = (string)($linkedItem->fields['name'] ?? '');

        // Compact display: the linked item is nearly always named like the
        // sprint item itself, so show only the type + a go-to link; the full
        // name (and parent project for tasks) moves to the tooltip.
        $tooltip = $rawName;
        if ($itemtype === 'ProjectTask') {
            $projectId = (int)($linkedItem->fields['projects_id'] ?? 0);
            $project   = ($projectId > 0) ? SprintCache::getObject('Project', $projectId) : null;
            if ($project !== null) {
                $tooltip .= ' (' . (string)($project->fields['name'] ?? '') . ')';
            }
        }

        // Progress of the underlying item, where GLPI tracks one (project
        // tasks); updated live by the linked quick-edit modal.
        $pctHtml = '';
        if ($itemtype === 'ProjectTask') {
            $pct = (int)($linkedItem->fields['percent_done'] ?? 0);
            $pctHtml = " <span class='sprint-linked-pct badge bg-secondary-lt text-muted' "
                . "title='" . htmlescape(__('Progress of the linked item', 'sprint')) . "'>{$pct}%</span>";
        }

        // Quick-edit button only when GLPI's own ACL (canUpdate) allows it,
        // so entity / technician / assignee-only restrictions are honored.
        $quickEditBtn = '';
        if ($linkedItem->canUpdateItem()) {
            $currentStatus = 0;
            $currentPercent = 0;
            if ($itemtype === 'ProjectTask') {
                $currentStatus  = (int)($linkedItem->fields['projectstates_id'] ?? 0);
                $currentPercent = (int)($linkedItem->fields['percent_done'] ?? 0);
            } else {
                $currentStatus = (int)($linkedItem->fields['status'] ?? 0);
            }
            $quickEditBtn = " <button type='button' class='btn btn-sm btn-link p-0 ms-1 sprint-linked-quick-edit-btn' "
                . "title='" . htmlescape(__('Quick edit linked item', 'sprint')) . "' "
                . "data-linked-itemtype='" . htmlescape($itemtype) . "' "
                . "data-linked-id='" . $itemsId . "' "
                . "data-linked-name='" . htmlescape($linkedItem->fields['name'] ?? '') . "' "
                . "data-linked-status='" . $currentStatus . "' "
                . "data-linked-percent='" . $currentPercent . "' "
                . "style='color:#6c757d;vertical-align:baseline;'>"
                . "<i class='fas fa-pen' style='font-size:0.8em;'></i></button>";
        }

        return "<span class='sprint-linked-compact' style='white-space:nowrap;'>"
            . "<a href='{$url}' title='" . htmlescape($tooltip) . "'>"
            . "<i class='{$icon}'></i> " . htmlescape($typeLabel)
            . " <i class='fas fa-external-link-alt' style='font-size:0.72em;opacity:0.7;'></i></a>"
            . $pctHtml
            . $quickEditBtn
            . "</span>";
    }

    /**
     * Parent project name for a ProjectTask-linked item, or '' for any other
     * type. Task names often repeat across projects, so list/dashboard
     * renderers show this subtly to disambiguate. See {@see getLinkedItemDisplay()}.
     */
    public static function getParentProjectName(string $itemtype, int $itemsId): string
    {
        if ($itemtype !== 'ProjectTask' || $itemsId <= 0 || !class_exists('ProjectTask')) {
            return '';
        }

        $task = SprintCache::getObject('ProjectTask', $itemsId);
        if ($task === null) {
            return '';
        }

        $projectId = (int)($task->fields['projects_id'] ?? 0);
        if ($projectId <= 0) {
            return '';
        }

        $project = SprintCache::getObject('Project', $projectId);
        if ($project === null) {
            return '';
        }

        return (string)($project->fields['name'] ?? '');
    }

    /**
     * Muted "(project name)" suffix rendered after the item name for
     * ProjectTask-linked rows, so same-named tasks from different projects
     * stay distinguishable now that getLinkedItemDisplay() is compact.
     * Returns '' for any other type.
     */
    public static function renderParentProjectSuffix(string $itemtype, int $itemsId): string
    {
        $projectName = self::getParentProjectName($itemtype, $itemsId);
        if ($projectName === '') {
            return '';
        }
        return " <span class='sprint-parent-project text-muted' style='font-size:0.85em;white-space:nowrap;' "
            . "title='" . htmlescape(__('Project')) . "'>"
            . "<i class='fas fa-folder-open' style='opacity:0.7;font-size:0.85em;'></i> "
            . htmlescape($projectName) . "</span>";
    }

    /**
     * Capacity of a row for the requested view: the actual figure when the
     * planned/actual setting is on and one was recorded, else the planned one.
     */
    public static function capacityFor(array $row, bool $actual): float
    {
        if ($actual && isset($row['capacity_actual']) && $row['capacity_actual'] !== '' && $row['capacity_actual'] !== 'NULL') {
            return (float)$row['capacity_actual'];
        }
        return (float)($row['capacity'] ?? 0);
    }

    /** '' when no actual capacity was recorded, else the formatted figure. */
    public static function formatActualCapacity($value): string
    {
        return ($value === null || $value === '' || $value === 'NULL') ? '' : SprintMember::formatCapacity($value);
    }

    /**
     * Small "Assigned capacity: x%" chip shown after the item name where no
     * dedicated capacity column exists (dashboard). Returns '' when 0.
     */
    public static function renderCapacityChip($capacity): string
    {
        $cap = (float)$capacity;
        if ($cap <= 0) {
            return '';
        }
        return " <span class='sprint-capacity-chip badge bg-secondary-lt text-muted' "
            . "style='font-size:0.72em;font-weight:normal;vertical-align:middle;'>"
            . sprintf(
                htmlescape(__('Assigned capacity: %s%%', 'sprint')),
                SprintMember::formatCapacity($cap)
            ) . "</span>";
    }

    /**
     * True when the linked GLPI item is closed/solved/done. Used to warn when
     * an item is moved to "In Review" while its underlying item is still open.
     * Manual items and vanished links count as "closed" (never warn).
     */
    public function isLinkedItemClosed(): bool
    {
        return self::linkedItemClosedFor(
            (string)($this->fields['itemtype'] ?? ''),
            (int)($this->fields['items_id'] ?? 0)
        );
    }

    /**
     * Static counterpart to isLinkedItemClosed() so list/board/dashboard
     * renderers can flag a plain DB row without instantiating SprintItem.
     * Manual items and vanished links count as "closed".
     */
    public static function linkedItemClosedFor(string $itemtype, int $itemsId): bool
    {
        if ($itemtype === '' || $itemsId <= 0 || !class_exists($itemtype)) {
            return true;
        }

        $linked = SprintCache::getObject($itemtype, $itemsId);
        if ($linked === null) {
            return true;
        }

        if ($itemtype === 'ProjectTask') {
            // Closed only when BOTH fully complete AND in a finished project
            // state (GLPI ProjectState->is_finished, e.g. a Closed/Done state).
            // Either condition on its own still counts as open.
            $fullyDone = (int)($linked->fields['percent_done'] ?? 0) >= 100;
            $stateId   = (int)($linked->fields['projectstates_id'] ?? 0);
            $stateDone = $stateId > 0 && self::isProjectStateFinished($stateId);
            return $fullyDone && $stateDone;
        }

        if (in_array($itemtype, ['Ticket', 'Change', 'Problem'], true)) {
            $status = (int)($linked->fields['status'] ?? 0);
            $closed = array_map('intval', array_merge(
                $itemtype::getSolvedStatusArray(),
                $itemtype::getClosedStatusArray()
            ));
            return in_array($status, $closed, true);
        }

        return true;
    }

    /**
     * Whether a GLPI ProjectState marks completion (its is_finished flag).
     * Result is cached per request since closed-checks run per item in loops.
     * A missing flag/state counts as "not finished" (falls back to percent).
     */
    private static function isProjectStateFinished(int $stateId): bool
    {
        static $cache = [];
        if (array_key_exists($stateId, $cache)) {
            return $cache[$stateId];
        }

        $finished = false;
        if ($stateId > 0 && class_exists('ProjectState')) {
            $state = new \ProjectState();
            if ($state->getFromDB($stateId)) {
                $finished = (int)($state->fields['is_finished'] ?? 0) === 1;
            }
        }

        return $cache[$stateId] = $finished;
    }

    /**
     * Inline "Linked item open" warning badge: item is In Review/Done while
     * its underlying GLPI item is still open. Returns '' when not applicable.
     *
     * @param array<string,mixed> $row  must carry status, itemtype, items_id
     */
    public static function renderLinkedItemOpenBadge(array $row): string
    {
        if (!self::isLinkedItemOpenForRow($row)) {
            return '';
        }
        return " <span class='sprint-review-unclosed-badge' title='"
            . htmlescape(__('The linked ticket/change is not closed/solved yet', 'sprint')) . "'>"
            . "<i class='fas fa-exclamation-triangle'></i> "
            . __('Linked item open', 'sprint') . "</span>";
    }

    /**
     * True when a row should carry the "Linked item open" flag: In Review/Done
     * with its underlying GLPI item still open.
     *
     * @param array<string,mixed> $row  must carry status, itemtype, items_id
     */
    public static function isLinkedItemOpenForRow(array $row): bool
    {
        $status = (string)($row['status'] ?? '');
        if ($status !== self::STATUS_REVIEW && $status !== self::STATUS_DONE) {
            return false;
        }
        return !self::linkedItemClosedFor(
            (string)($row['itemtype'] ?? ''),
            (int)($row['items_id'] ?? 0)
        );
    }

    /**
     * Move a sprint item "back to the backlog".
     *
     * Default: a linked item stays in the sprint as a manual placeholder (so
     * booked capacity keeps counting) and only the underlying GLPI item is
     * decoupled into a fresh backlog row. With $removeFromSprint the whole item
     * leaves the sprint instead. The optional reason is stamped into the note.
     *
     * @return array{ok:bool, stayed:bool, backlog_id:int}
     */
    public static function backToBacklog(int $id, string $reason = '', bool $removeFromSprint = false): array
    {
        $result = ['ok' => false, 'stayed' => false, 'backlog_id' => 0, 'message' => ''];

        $item = new self();
        if ($id <= 0 || !$item->getFromDB($id)) {
            return $result;
        }

        $itemtype = (string)($item->fields['itemtype'] ?? '');
        $itemsId  = (int)($item->fields['items_id'] ?? 0);
        $hasLink  = $itemtype !== '' && $itemsId > 0;

        // A coupled item must exist exactly once across backlog + sprints.
        // Manual items are capacity placeholders with no coupling, so returning
        // one to the backlog would only create meaningless duplicate rows.
        if (!$hasLink) {
            $result['message'] = __('Manual items cannot be sent back to the backlog — they stay in their sprint.', 'sprint');
            return $result;
        }

        $srcFields  = $item->fields;
        $wasBlocked = ($item->fields['status'] ?? '') === self::STATUS_BLOCKED
            || (int)($item->fields['is_blocked'] ?? 0) === 1;
        $srcNote    = (string)($item->fields['note'] ?? '');
        $stamp      = self::buildBacklogReasonNote($reason);

        if (!$removeFromSprint) {
            // Keep the capacity placeholder in this sprint.
            $manualUpdate = [
                'id'       => $id,
                'itemtype' => '',
                'items_id' => 0,
            ];
            if ($stamp !== '') {
                $manualUpdate['note'] = self::appendNote($srcNote, $stamp);
            }
            $item->update($manualUpdate);

            $result['ok']         = true;
            $result['stayed']     = true;
            $result['backlog_id'] = self::placeCouplingOnBacklog($itemtype, $itemsId, $srcFields, $stamp, $wasBlocked);
            return $result;
        }

        // REMOVE: the whole item leaves the sprint for the backlog. If a
        // backlog row already exists for this coupling, fold into it and drop
        // this one to avoid a duplicate.
        $existingBacklogId = self::findBacklogRowId($itemtype, $itemsId);
        if ($existingBacklogId > 0) {
            if ($stamp !== '') {
                $cur = new self();
                $cur->getFromDB($existingBacklogId);
                (new self())->update([
                    'id'   => $existingBacklogId,
                    'note' => self::appendNote((string)($cur->fields['note'] ?? ''), $stamp),
                ]);
            }
            SprintItemDependency::purgeForItem($id);
            $item->delete(['id' => $id], 1);
            $result['ok']         = true;
            $result['backlog_id'] = $existingBacklogId;
            return $result;
        }

        $update = [
            'id'                       => $id,
            'plugin_sprint_sprints_id' => 0,
            'is_fastlane'              => 0,
        ];
        if ($wasBlocked) {
            $update['is_blocked'] = 1;
        }
        if ($stamp !== '') {
            $update['note'] = self::appendNote($srcNote, $stamp);
        }
        if ($item->update($update)) {
            SprintItemDependency::purgeForItem($id);
            $result['ok']         = true;
            $result['backlog_id'] = $id;
        }

        return $result;
    }

    /**
     * Place a coupling on the backlog as a single row: reuse an existing row,
     * skip when it already lives in a real sprint, else create a fresh row.
     * Returns the backlog row id, or 0 when nothing was added.
     */
    private static function placeCouplingOnBacklog(
        string $itemtype,
        int $itemsId,
        array $source,
        string $stamp,
        bool $wasBlocked
    ): int {
        $existingId = self::findBacklogRowId($itemtype, $itemsId);
        if ($existingId > 0) {
            if ($stamp !== '') {
                $cur = new self();
                $cur->getFromDB($existingId);
                (new self())->update([
                    'id'   => $existingId,
                    'note' => self::appendNote((string)($cur->fields['note'] ?? ''), $stamp),
                ]);
            }
            return $existingId;
        }

        // Coupling already active in a sprint → don't create a backlog duplicate.
        if (self::couplingInSprint($itemtype, $itemsId)) {
            return 0;
        }

        return (int)(new self())->add([
            'plugin_sprint_sprints_id' => 0,
            'name'                     => (string)($source['name'] ?? ($itemtype . ' #' . $itemsId)),
            'description'              => (string)($source['description'] ?? ''),
            'itemtype'                 => $itemtype,
            'items_id'                 => $itemsId,
            'status'                   => self::STATUS_TODO,
            'priority'                 => (int)($source['priority'] ?? 3),
            'story_points'             => (int)($source['story_points'] ?? 1),
            'users_id'                 => 0,
            'capacity'                 => 0,
            'is_blocked'               => $wasBlocked ? 1 : 0,
            'note'                     => $stamp,
        ]);
    }

    /**
     * Id of the (single) backlog row carrying this coupling, or 0 if none.
     */
    private static function findBacklogRowId(string $itemtype, int $itemsId): int
    {
        if ($itemtype === '' || $itemsId <= 0) {
            return 0;
        }
        $rows = (new self())->find([
            'plugin_sprint_sprints_id' => 0,
            'itemtype'                 => $itemtype,
            'items_id'                 => $itemsId,
        ]);
        return count($rows) > 0 ? (int)reset($rows)['id'] : 0;
    }

    /**
     * True when this coupling is currently active in a real sprint.
     */
    private static function couplingInSprint(string $itemtype, int $itemsId): bool
    {
        if ($itemtype === '' || $itemsId <= 0) {
            return false;
        }
        return countElementsInTable(self::getTable(), [
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
            ['NOT' => ['plugin_sprint_sprints_id' => 0]],
        ]) > 0;
    }

    /**
     * Drop leftover backlog rows for a coupling (keeping $exceptId), called
     * after assigning a coupled backlog item into a sprint.
     */
    public static function purgeBacklogCoupling(string $itemtype, int $itemsId, int $exceptId = 0): void
    {
        if ($itemtype === '' || $itemsId <= 0) {
            return;
        }
        $rows = (new self())->find([
            'plugin_sprint_sprints_id' => 0,
            'itemtype'                 => $itemtype,
            'items_id'                 => $itemsId,
        ]);
        foreach ($rows as $r) {
            if ((int)$r['id'] === $exceptId) {
                continue;
            }
            (new self())->delete(['id' => (int)$r['id']], 1);
        }
    }

    /**
     * Build the stamped "back to backlog" note line, or '' when no reason.
     */
    private static function buildBacklogReasonNote(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            return '';
        }
        $who = SprintCache::userName((int)Session::getLoginUserID());
        return '[' . date('Y-m-d') . ' — ' . $who . '] '
            . __('Back to backlog', 'sprint') . ': ' . $reason;
    }

    private static function appendNote(string $existing, string $line): string
    {
        return $existing !== '' ? ($existing . "\n" . $line) : $line;
    }

    /** Render the sprint items list and the add form. */
    public static function showForSprint(Sprint $sprint): void
    {
        $ID      = $sprint->getID();
        $canedit = self::canUpdate()
            || Session::haveRight(self::$rightname, Profile::RIGHT_OWN_ITEMS);

        Sprint::renderHeaderBar($sprint);

        if ($canedit) {
            $memberOptions = SprintMember::getSprintMemberOptions($ID);
            $rand = mt_rand();

            echo "<div class='center'>";
            echo "<form method='post' action='" . static::getFormURL() . "'>";
            echo Html::hidden('plugin_sprint_sprints_id', ['value' => $ID]);

            echo "<table class='tab_cadre_fixe sprint-themed'>";
            echo "<tr class='tab_bg_2'>";
            echo "<th colspan='6'>" . __('Add a sprint item', 'sprint') . "</th>";
            echo "</tr>";

            // Row 1: Type + Item selector (dynamic)
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Type', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('itemtype', self::getLinkedItemTypes(), [
                'value' => '',
                'rand'  => $rand,
            ]);
            echo "</td>";
            echo "<td><span id='sprint_label_{$rand}'>" . __('Name') . "</span></td>";
            echo "<td colspan='3'>";
            echo "<div id='sprint_manual_name_{$rand}'>";
            echo Html::input('name', ['size' => 40]);
            echo "</div>";
            // Container for the AJAX-loaded item dropdown.
            echo "<div id='sprint_item_container_{$rand}' style='display:none;'></div>";
            echo "</td></tr>";

            // Inline script: load item dropdown via AJAX when type changes
            echo "<script>
            $(function() {
                var r = '{$rand}';
                var dropdownUrl = CFG_GLPI.root_doc + '/plugins/sprint/ajax/getitemdropdown.php';
                var labels = {
                    '': '" . __('Name') . "',
                    'Ticket': '" . __('Ticket') . "',
                    'Change': '" . __('Change') . "',
                    'Problem': '" . __('Problem') . "',
                    'ProjectTask': '" . __('Project') . " / " . __('Project task') . "'
                };

                var sel = $('select[name=\"itemtype\"]').last();
                sel.on('change', function() {
                    var val = $(this).val();
                    var container = $('#sprint_item_container_' + r);
                    var manualName = $('#sprint_manual_name_' + r);

                    $('#sprint_label_' + r).text(labels[val] || '" . __('Name') . "');

                    if (!val || val === '') {
                        manualName.show();
                        container.hide().empty();
                    } else {
                        manualName.hide();
                        container.html('<i class=\"fas fa-spinner fa-spin\"></i>').show();
                        $.ajax({
                            url: dropdownUrl,
                            type: 'POST',
                            data: {
                                itemtype: val,
                                rand: r,
                                _glpi_csrf_token: $('input[name=\"_glpi_csrf_token\"]').first().val()
                            },
                            success: function(html) {
                                container.html(html);
                            }
                        });
                    }
                });
            });
            </script>";

            // Row 2: Owner, Status, Story Points
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Owner', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('users_id', $memberOptions);
            echo "</td>";
            echo "<td>" . __('Status') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('status', self::getAllStatuses(), [
                'value' => self::STATUS_TODO,
            ]);
            echo "</td>";
            echo "<td>" . __('Story Points', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showNumber('story_points', ['value' => 1, 'min' => 0, 'max' => 100]);
            echo "</td></tr>";

            // Row 3: Capacity, Priority + Submit
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Capacity (%)', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('capacity', SprintMember::getCapacityChoices(), [
                'value' => 0,
            ]);
            if (Config::isPlannedActualEnabled()) {
                echo " <span class='text-muted small ms-2'>" . __('Actual', 'sprint') . "</span> ";
                Dropdown::showFromArray('capacity_actual', ['' => __('Follows planned', 'sprint')] + SprintMember::getCapacityChoices(), [
                    'value' => '',
                ]);
            }
            echo "</td>";
            echo "<td>" . __('Priority') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('priority', [
                1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
                4 => __('High'), 5 => __('Very high'),
            ], ['value' => 3]);
            echo "</td>";
            echo "<td colspan='2'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";

            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        // Fastlane items are excluded — they live in the dedicated Fastlane tab.
        $item  = new self();
        $items = $item->find(
            [
                'plugin_sprint_sprints_id' => $ID,
                'is_fastlane'              => 0,
            ],
            ['sort_order ASC', 'priority DESC']
        );

        $statuses   = self::getAllStatuses();
        $plannedActual = Config::isPlannedActualEnabled();
        $priorities = [
            1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
            4 => __('High'), 5 => __('Very high'),
        ];

        echo "<div class='center'>";

        // Filter bar for the items list (handled by window.SprintFilter).
        self::renderFilterBar('sprint-items-list-table', [
            'statuses'   => $statuses,
            'owners'     => SprintMember::getSprintMemberOptions($ID),
            'tags'       => Config::getDefinedTags(),
            'categories' => SprintCategory::getAll(),
        ]);

        $itemIds  = array_map(fn($r) => (int)$r['id'], $items);
        $tagsById = self::getTagsForItems($itemIds);
        $depsById = SprintItemDependency::getOpenSummariesForItems($itemIds);

        echo "<table class='tab_cadre_fixe sprint-themed sprint-items-list-table'>";
        echo "<tr class='tab_bg_2'>";
        $sc = self::sortClickAttr('sprint-items-list-table');
        echo "<th class='sprint-sortable' data-sort-type='name' style='cursor:pointer;' {$sc}>" . __('Name') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th class='sprint-sortable' data-sort-type='category' style='cursor:pointer;' {$sc}>" . __('Category', 'sprint') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='status' style='cursor:pointer;' {$sc}>" . __('Status') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='priority' style='cursor:pointer;' {$sc}>" . __('Priority') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='story_points' style='cursor:pointer;' {$sc}>" . __('Story Points', 'sprint') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='capacity' style='cursor:pointer;' {$sc}>" . __('Capacity (%)', 'sprint') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='owner' style='cursor:pointer;' {$sc}>" . __('Owner', 'sprint') . " <i class='fas fa-sort text-muted'></i></th>";
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($items) === 0) {
            $cols = $canedit ? 9 : 8;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('No items found', 'sprint') . "</td></tr>";
        }

        foreach ($items as $row) {
            $statusClass = 'sprint-status-' . str_replace('_', '-', $row['status']);
            $statusLabel = $statuses[$row['status']] ?? $row['status'];
            $ownerName   = ((int)$row['users_id'] > 0) ? SprintCache::userName((int)$row['users_id']) : '';

            $linkedDisplay = '<span style="color:#ccc;">-</span>';
            if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
                $tmpItem = new self();
                $tmpItem->fields = $row;
                $linkedDisplay = $tmpItem->getLinkedItemDisplay();
            }

            $rowTags   = $tagsById[(int)$row['id']] ?? [];
            $dataAttrs = self::buildRowDataAttrs($row, $statusLabel, $ownerName, $rowTags);

            $rowDeps = $depsById[(int)$row['id']] ?? [];
            $isAdhoc = (int)($row['is_adhoc'] ?? 0) === 1;
            echo "<tr class='tab_bg_1 sprint-row sprint-filterable-row" . ($isAdhoc ? ' sprint-adhoc' : '') . "' {$dataAttrs}>";
            echo "<td class='sprint-cell-name'><a href='" . static::getFormURLWithID($row['id']) . "'>" .
                htmlescape($row['name']) . "</a>"
                . self::renderParentProjectSuffix((string)($row['itemtype'] ?? ''), (int)($row['items_id'] ?? 0))
                . self::renderAdhocBadge($isAdhoc) . self::renderTagPills($rowTags) . self::renderDependencyBadge($rowDeps)
                . self::renderLinkedItemOpenBadge($row) . "</td>";
            echo "<td class='sprint-row-linked'>" . $linkedDisplay . "</td>";
            echo "<td class='sprint-cell-category'>" . self::renderCategoryCell((int)($row['plugin_sprint_sprintcategories_id'] ?? 0)) . "</td>";
            echo "<td class='sprint-cell-status'><span class='sprint-badge {$statusClass}'>" .
                $statusLabel . "</span></td>";
            echo "<td class='sprint-cell-priority'>" . ($priorities[$row['priority']] ?? $row['priority']) . "</td>";
            echo "<td class='center sprint-cell-story-points'>" . (int)$row['story_points'] . "</td>";
            echo "<td class='center sprint-cell-capacity'>" . SprintMember::formatCapacity($row['capacity'] ?? 0) . "%";
            if ($plannedActual && self::formatActualCapacity($row['capacity_actual'] ?? null) !== '') {
                echo " <span class='text-muted small' title='" . __s('Actual capacity', 'sprint') . "'>/ "
                    . SprintMember::formatCapacity($row['capacity_actual']) . "%</span>";
            }
            echo "</td>";
            echo "<td class='sprint-cell-owner'>" . (((int)$row['users_id'] > 0) ? htmlescape(SprintCache::userName($row['users_id'])) :
                '<span style="color:#999;">' . __('Unassigned', 'sprint') . '</span>') . "</td>";
            if ($canedit) {
                $isOwn = (int)$row['users_id'] === (int)Session::getLoginUserID();
                $canEditRow = self::canUpdate() || (self::hasOnlyOwnRight(UPDATE) && $isOwn);
                $canDeleteRow = Session::haveRight(self::$rightname, PURGE)
                    || (self::hasOnlyOwnRight(PURGE) && $isOwn);

                echo "<td class='center' style='white-space:nowrap;'>";
                if ($canEditRow) {
                    // Quick-edit covers the full item form; the explicit "Edit"
                    // link was redundant and removed.
                    echo "<button type='button' class='btn btn-sm btn-outline-secondary sprint-quick-edit-btn me-1' "
                        . "title='" . __('Quick edit', 'sprint') . "' data-item-id='" . (int)$row['id'] . "'>"
                        . "<i class='fas fa-pen'></i></button> ";
                    // Back-to-backlog only for coupled items (manual placeholders
                    // stay to avoid duplicates). Opens the reason + keep/remove
                    // modal and posts to ajax/backtobacklog.php.
                    if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
                        echo "<button type='button' class='btn btn-sm btn-outline-warning sprint-backlog-btn' "
                            . "title='" . __('Back to backlog', 'sprint') . "' "
                            . "data-backlog-id='" . (int)$row['id'] . "'>"
                            . "<i class='fas fa-undo'></i></button> ";
                    }
                }
                if ($canDeleteRow) {
                    echo "<form method='post' action='" . static::getFormURL() . "' style='display:inline;'>";
                    echo Html::hidden('id', ['value' => $row['id']]);
                    echo Html::submit(__('Delete'), [
                        'name'    => 'purge',
                        'class'   => 'btn btn-sm btn-outline-danger',
                        'confirm' => __('Confirm deletion?'),
                    ]);
                    Html::closeForm();
                }
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</table></div>";

        if ($canedit) {
            self::renderQuickEditUI($ID);
            self::renderBackToBacklogUI();
        }
        self::renderLinkedQuickEditUI();
    }

    /**
     * Modal + JS for the "Back to backlog" button. The user enters a reason
     * (stored in the note) and chooses to keep the item as a manual placeholder
     * (default) or move the whole item back. Posts to ajax/backtobacklog.php.
     */
    public static function renderBackToBacklogUI(): void
    {
        $endpoint = \Plugin::getWebDir('sprint') . '/ajax/backtobacklog.php';
        $tokenUrl = \Plugin::getWebDir('sprint') . '/ajax/csrftoken.php';

        $title        = __('Back to backlog', 'sprint');
        $lblReason    = __('Reason', 'sprint');
        $phReason     = __('Why is this going back to the backlog?', 'sprint');
        $errReason    = __('Please enter a reason.', 'sprint');
        $lblChoice    = __('What should happen to the sprint item?', 'sprint');
        $optKeep      = __('Keep it in the sprint (capacity preserved). Only the linked item returns to the backlog', 'sprint');
        $optRemove    = __('Also remove the item from the current sprint (move the whole item to the backlog)', 'sprint');
        $lblCancel    = __('Cancel');
        $movedLabel   = __('Moved to backlog', 'sprint');

        echo <<<HTML
<div class="modal fade" id="sprint-backlog-reason-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-undo me-1"></i> {$title}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <label class="form-label fw-bold">{$lblReason}</label>
        <textarea name="reason" class="form-control" rows="3" placeholder="{$phReason}"></textarea>
        <div class="text-danger small mt-1 sprint-backlog-reason-error" style="display:none;">{$errReason}</div>
        <div class="mt-3">
          <label class="form-label fw-bold d-block">{$lblChoice}</label>
          <label class="form-check">
            <input class="form-check-input" type="radio" name="remove_from_sprint" value="0" checked>
            <span class="form-check-label">{$optKeep}</span>
          </label>
          <label class="form-check">
            <input class="form-check-input" type="radio" name="remove_from_sprint" value="1">
            <span class="form-check-label">{$optRemove}</span>
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$lblCancel}</button>
        <button type="button" class="btn btn-warning sprint-backlog-reason-confirm">
          <i class="fas fa-undo me-1"></i> {$title}
        </button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }
    var endpoint = "{$endpoint}";
    var tokenUrl = "{$tokenUrl}";
    var \$modal;

    jQuery(function(){
        \$modal = jQuery('#sprint-backlog-reason-modal');
        if (\$modal.length === 0) { return; }
        \$modal = \$modal.detach().appendTo('body');
    });

    jQuery(document).on('click', '.sprint-backlog-btn', function(){
        var \$btn = jQuery(this);
        var itemId = parseInt(\$btn.data('backlog-id'), 10) || 0;
        if (itemId <= 0) { return; }
        \$modal.find('textarea[name=reason]').val('');
        \$modal.find('.sprint-backlog-reason-error').hide();
        \$modal.find('input[name=remove_from_sprint][value="0"]').prop('checked', true);
        \$modal.data('target-item', itemId);
        \$modal.data('target-btn', \$btn);
        bootstrap.Modal.getOrCreateInstance(\$modal[0]).show();
        setTimeout(function(){ \$modal.find('textarea[name=reason]').trigger('focus'); }, 200);
    });

    jQuery(document).on('click', '.sprint-backlog-reason-confirm', function(){
        var reason = (\$modal.find('textarea[name=reason]').val() || '').trim();
        if (reason === '') { \$modal.find('.sprint-backlog-reason-error').show(); return; }
        var itemId = parseInt(\$modal.data('target-item'), 10) || 0;
        var \$btn   = \$modal.data('target-btn');
        var removeFromSprint = \$modal.find('input[name=remove_from_sprint]:checked').val() === '1';
        bootstrap.Modal.getOrCreateInstance(\$modal[0]).hide();
        if (\$btn) { \$btn.prop('disabled', true); }

        jQuery.ajax({ url: tokenUrl, type: 'GET', dataType: 'json', cache: false })
        .then(function(tok){
            return jQuery.ajax({
                url: endpoint, type: 'POST', dataType: 'json',
                data: {
                    id: itemId,
                    reason: reason,
                    remove_from_sprint: removeFromSprint ? 1 : 0,
                    _glpi_csrf_token: tok && tok.token ? tok.token : ''
                }
            });
        }).done(function(resp){
            if (resp && resp.success) {
                if (window.glpi_toast_info) { window.glpi_toast_info(resp.message); }
                var \$row = jQuery('tr.sprint-row[data-item-id="' + itemId + '"]');
                if (resp.stayed) {
                    \$row.find('.sprint-row-linked').html('<span class="text-muted fst-italic">{$movedLabel}</span>');
                    \$row.css('transition','background-color 0.3s').css('background-color','#fff3cd');
                    setTimeout(function(){ \$row.css('background-color',''); }, 800);
                    if (\$btn) { \$btn.prop('disabled', false); }
                } else {
                    \$row.fadeOut(150, function(){ jQuery(this).remove(); });
                }
            } else {
                if (window.glpi_toast_error) { window.glpi_toast_error((resp && resp.message) || 'Failed'); }
                else { alert((resp && resp.message) || 'Failed'); }
                if (\$btn) { \$btn.prop('disabled', false); }
            }
        }).fail(function(){
            if (window.glpi_toast_error) { window.glpi_toast_error('Network error'); }
            if (\$btn) { \$btn.prop('disabled', false); }
        });
    });
})();
</script>
HTML;
    }

    /**
     * Public helper building the quick-edit data-* attribute string from raw
     * fields (computes status label + owner name itself). Lets Kanban cards
     * open the shared quick-edit modal just like table rows.
     */
    public static function buildItemDataAttrs(array $row, array $tags = []): string
    {
        $statuses    = self::getAllStatuses();
        $statusLabel = $statuses[$row['status'] ?? ''] ?? (string)($row['status'] ?? '');
        $ownerName   = ((int)($row['users_id'] ?? 0) > 0) ? SprintCache::userName((int)$row['users_id']) : '';
        return self::buildRowDataAttrs($row, $statusLabel, $ownerName, $tags);
    }

    private static function buildRowDataAttrs(array $row, string $statusLabel, string $ownerName, array $tags = []): string
    {
        $attrs = [
            'data-item-id'           => (int)$row['id'],
            'data-item-name'         => (string)$row['name'],
            'data-item-status'       => (string)$row['status'],
            'data-item-status-label' => $statusLabel,
            'data-item-priority'     => (int)($row['priority'] ?? 3),
            'data-users-id'          => (int)($row['users_id'] ?? 0),
            'data-owner-name'        => $ownerName,
            'data-story-points'      => (int)($row['story_points'] ?? 0),
            'data-capacity'          => SprintMember::formatCapacity($row['capacity'] ?? 0),
            'data-capacity-actual'   => self::formatActualCapacity($row['capacity_actual'] ?? null),
            'data-is-fastlane'       => (int)($row['is_fastlane'] ?? 0),
            'data-is-adhoc'          => (int)($row['is_adhoc'] ?? 0),
            'data-note'              => (string)($row['note'] ?? ''),
            'data-item-tags'         => self::tagsToBlob($tags),
            'data-linked-itemtype'   => (string)($row['itemtype'] ?? ''),
            'data-done-checks'       => implode('|', SprintAgility::checklist((string)($row['done_checks'] ?? ''))),
            'data-epic-id'           => (int)($row['plugin_sprint_sprintepics_id'] ?? 0),
            'data-category-id'       => (int)($row['plugin_sprint_sprintcategories_id'] ?? 0),
            'data-category-name'     => SprintCategory::getFullNameFor((int)($row['plugin_sprint_sprintcategories_id'] ?? 0)),
        ];

        $parts = [];
        foreach ($attrs as $k => $v) {
            $parts[] = $k . '="' . htmlescape((string)$v) . '"';
        }
        return implode(' ', $parts);
    }

    /**
     * True when the current user is the Scrum Master of the sprint: either the
     * sprint owner or a member holding the scrum_master role (same combination
     * as the backlog assign permission).
     */
    public static function currentUserIsScrumMasterOf(int $sprintId): bool
    {
        return Config::isCurrentUserScrumMaster($sprintId)
            || SprintMember::isScrumMaster($sprintId, (int)Session::getLoginUserID());
    }

    /**
     * Inline "Adhoc" badge for items flagged by the Scrum Master as having
     * entered the sprint after kick-off.
     */
    public static function renderAdhocBadge(bool $isAdhoc): string
    {
        if (!$isAdhoc) {
            return '';
        }
        return " <span class='sprint-adhoc-badge' title='"
            . htmlescape(__('Added to the sprint after kick-off', 'sprint')) . "'>"
            . "<i class='fas fa-plus-circle'></i> " . __('Adhoc', 'sprint') . "</span>";
    }

    /**
     * Category cell/pill for a sprint item. Falls back to a muted dash so a
     * categoryless item still reads as "deliberately empty".
     */
    public static function renderCategoryCell(int $categoryId): string
    {
        $pill = SprintCategory::renderPill($categoryId);
        return $pill !== '' ? ltrim($pill) : "<span class='text-muted'>-</span>";
    }

    /** Inline tag-pill markup appended to the name cell. */
    public static function renderTagPills(array $tags): string
    {
        if (empty($tags)) {
            return '';
        }
        $out = " <span class='sprint-tag-pills'>";
        foreach ($tags as $tag) {
            $out .= "<span class='sprint-tag-pill'>" . htmlescape($tag) . "</span>";
        }
        $out .= "</span>";
        return $out;
    }

    /**
     * Inline pill showing how many open dependencies an item has, with helper
     * names + % in the tooltip.
     *
     * @param array<int,array{users_id:int,name:string,capacity:float}> $openSummaries
     */
    public static function renderDependencyBadge(array $openSummaries): string
    {
        if (empty($openSummaries)) {
            return '';
        }
        $count = count($openSummaries);
        $parts = [];
        foreach ($openSummaries as $s) {
            $parts[] = ($s['name'] ?: '?') . ' (' . SprintMember::formatCapacity($s['capacity']) . '%)';
        }
        $tooltip = __('Waiting on', 'sprint') . ': ' . implode(', ', $parts);
        return " <span class='sprint-dep-pill' title='" . htmlescape($tooltip) . "'>"
            . "<i class='fas fa-link'></i> " . $count . "</span>";
    }

    /**
     * Pipe-bracketed lowercased blob for `data-item-tags`. JS does
     * `indexOf('|tag|')` so partial matches never collide.
     */
    public static function tagsToBlob(array $tags): string
    {
        if (empty($tags)) {
            return '|';
        }
        return '|' . implode('|', array_map('mb_strtolower', $tags)) . '|';
    }

    /**
     * Render a shared filter bar (text search + status + owner + reset) for
     * any sprint item table.
     *
     * @param string $tableClass  CSS class on the target <table>.
     * @param array<string,mixed> $options {
     *     statuses: [key=>label] for the status dropdown,
     *     owners:   [uid=>name]  for the owner dropdown,
     * }
     */
    public static function renderFilterBar(string $tableClass, array $options): void
    {
        $statuses   = $options['statuses']   ?? [];
        $owners     = $options['owners']     ?? [];
        $tags       = $options['tags']       ?? [];
        $categories = $options['categories'] ?? [];
        $barId    = 'sprint-filter-' . mt_rand();
        $tc       = htmlescape($tableClass);

        echo "<div id='{$barId}' class='sprint-filter-bar d-flex flex-wrap align-items-center gap-2 p-2 mb-2' "
            . "data-target='{$tc}' style='background:var(--tblr-bg-surface-secondary,#f1f3f5);border-radius:6px;'>";
        echo "<div class='d-flex align-items-center gap-1 text-muted small'>"
            . "<i class='fas fa-filter'></i><span>" . __('Filter', 'sprint') . "</span></div>";

        // Events are delegated from sprint.js (no inline handlers) so a CSP
        // without 'unsafe-inline' still leaves filter + reset functional.
        echo "<input type='search' class='form-control form-control-sm sf-text' "
            . "style='max-width:220px;' placeholder='" . __('Search name...', 'sprint') . "'>";

        if (!empty($statuses)) {
            echo "<select class='form-select form-select-sm sf-status' style='max-width:180px;'>";
            echo "<option value=''>" . __('All statuses', 'sprint') . "</option>";
            echo "<option value='__not_done__'>" . __('Not done', 'sprint') . "</option>";
            foreach ($statuses as $key => $label) {
                echo "<option value='" . htmlescape((string)$key) . "'>" . htmlescape((string)$label) . "</option>";
            }
            echo "</select>";
        }

        if (!empty($owners)) {
            echo "<select class='form-select form-select-sm sf-owner' style='max-width:220px;'>";
            echo "<option value=''>" . __('All owners', 'sprint') . "</option>";
            echo "<option value='__unassigned__'>" . __('Unassigned only', 'sprint') . "</option>";
            foreach ($owners as $uidOpt => $name) {
                if ((int)$uidOpt === 0) continue;
                echo "<option value='" . (int)$uidOpt . "'>" . htmlescape((string)$name) . "</option>";
            }
            echo "</select>";
        }

        if (!empty($tags)) {
            echo "<select class='form-select form-select-sm sf-tag' style='max-width:180px;'>";
            echo "<option value=''>" . __('All tags', 'sprint') . "</option>";
            foreach ($tags as $tag) {
                echo "<option value='" . htmlescape(mb_strtolower($tag)) . "'>" . htmlescape($tag) . "</option>";
            }
            echo "</select>";
        }

        if (!empty($categories)) {
            echo "<select class='form-select form-select-sm sf-category' style='max-width:200px;'>";
            echo "<option value=''>" . __('All categories', 'sprint') . "</option>";
            echo "<option value='0'>" . __('No category', 'sprint') . "</option>";
            echo SprintCategory::dropdownOptions();
            echo "</select>";
        }

        echo "<button type='button' class='btn btn-sm btn-outline-secondary sf-reset' "
            . "data-sprint-action='filter-reset'>"
            . "<i class='fas fa-times me-1'></i>" . __('Reset', 'sprint') . "</button>";
        echo "</div>";
    }

    /**
     * Attribute fragment for a sortable <th>. Uses data-sprint-action so
     * sprint.js handles the click via delegation — CSP-safe, no inline onclick.
     */
    public static function sortClickAttr(string $tableClass = ''): string
    {
        return 'data-sprint-action="sort"';
    }

    /**
     * Render the shared quick-edit modal + JS bindings. Works on any page with
     * .sprint-quick-edit-btn buttons and rows carrying the data-* attributes
     * from {@see buildRowDataAttrs()}.
     */
    public static function renderQuickEditUI(int $sprintId = 0): void
    {
        $statuses       = self::getAllStatuses();
        $priorities     = [
            1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
            4 => __('High'), 5 => __('Very high'),
        ];
        $capacityChoices = SprintMember::getCapacityChoices();
        $plannedActual   = Config::isPlannedActualEnabled();
        $memberOptions  = $sprintId > 0 ? SprintMember::getSprintMemberOptions($sprintId) : [];
        $moveTargets    = $sprintId > 0 ? Sprint::getMoveTargetOptions($sprintId) : [];
        $definedTags    = Config::getDefinedTags();
        $dod            = Config::getDefinitionDone();

        // Epic picker, Scrum Master only.
        $epicOptions = [];
        if ($sprintId > 0 && self::currentUserIsScrumMasterOf($sprintId)) {
            global $DB;
            $sprintObj = new Sprint();
            if ($sprintObj->getFromDB($sprintId)) {
                foreach ($DB->request([
                    'FROM'  => 'glpi_plugin_sprint_sprintepics',
                    'WHERE' => ['entities_id' => (int)($sprintObj->fields['entities_id'] ?? 0)],
                    'ORDER' => 'name ASC',
                ]) as $epic) {
                    $epicOptions[(int)$epic['id']] = (string)$epic['name'];
                }
            }
        }

        // Capacity guard: when restricted to the Scrum Master, others may still
        // pick a new % — it is sent to the Scrum Master as an approval request
        // instead of applied directly (regular items only — fastlane stays free).
        $capacityLocked = Config::isScrumMasterOnlyCapacity()
            && $sprintId > 0
            && !self::currentUserIsScrumMasterOf($sprintId);
        $capacityLockedJs = $capacityLocked ? 'true' : 'false';
        $capacityRequestHint = __s('Capacity changes are sent to the Scrum Master for approval.', 'sprint');

        // Category guard: the category feeds the per-category limits and every
        // category figure on the dashboard, so inside a sprint only the Scrum
        // Master applies it directly — everyone else raises a request.
        $categoryOptions = SprintCategory::getAll();
        $categoryLocked  = $sprintId > 0 && !self::currentUserIsScrumMasterOf($sprintId);
        $categoryLockedJs = $categoryLocked ? 'true' : 'false';
        $categoryRequestHint = __s('Category changes are sent to the Scrum Master for approval.', 'sprint');
        $lblNameFollows = addslashes(__('The name follows the linked item and cannot be edited here.', 'sprint'));

        $cfgRoot = 'CFG_GLPI.root_doc';

        echo "<div class='modal fade' id='sprint-quickedit-modal' tabindex='-1' aria-hidden='true'>";
        echo "<div class='modal-dialog modal-dialog-centered modal-lg'>";
        echo "<div class='modal-content'>";
        echo "<div class='modal-header'>";
        echo "<h5 class='modal-title'><i class='fas fa-pen me-1'></i>" .
            __('Quick edit', 'sprint') . ": <span class='sprint-qe-title'></span></h5>";
        echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>";
        echo "</div>";
        echo "<div class='modal-body'>";
        echo "<div class='alert alert-danger sprint-qe-error' style='display:none;white-space:pre-line;'></div>";
        echo "<input type='hidden' name='id' value=''>";

        echo "<div class='mb-3'><label class='form-label'>" . __('Name') . "</label>";
        echo "<input type='text' name='name' class='form-control' value=''></div>";

        // Adhoc switch: Scrum Master only — others never see or submit it.
        if ($sprintId > 0 && self::currentUserIsScrumMasterOf($sprintId)) {
            echo "<div class='mb-3 form-check form-switch'>";
            echo "<input class='form-check-input' type='checkbox' id='sprint-qe-adhoc' name='is_adhoc'>";
            echo "<label class='form-check-label' for='sprint-qe-adhoc'>"
                . "<span class='sprint-adhoc-badge'><i class='fas fa-plus-circle'></i> " . __('Adhoc', 'sprint') . "</span> "
                . htmlescape(__('Added to the sprint after kick-off (highlights the item)', 'sprint'))
                . "</label>";
            echo "</div>";
        }

        echo "<div class='row g-3'>";
        echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __('Status') . "</label>";
        echo "<select name='status' class='form-select'>";
        foreach ($statuses as $k => $v) {
            echo "<option value='" . htmlescape($k) . "'>" . htmlescape($v) . "</option>";
        }
        echo "</select></div>";
        echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __('Priority') . "</label>";
        echo "<select name='priority' class='form-select'>";
        foreach ($priorities as $k => $v) {
            echo "<option value='{$k}'>" . htmlescape($v) . "</option>";
        }
        echo "</select></div>";
        echo "</div>";

        // DoD checklist; the badge shows completion at a glance.
        if ($dod) {
            echo "<div class='mb-3 sprint-qe-dod-block'><label class='form-label'>"
                . "<i class='fas fa-list-check me-1'></i>" . __('Definition of Done', 'sprint')
                . " <span class='badge sprint-qe-dod-badge'></span></label>";
            foreach ($dod as $check) {
                echo "<label class='form-check d-block'>"
                    . "<input type='checkbox' class='form-check-input sprint-qe-dod' value='" . htmlescape($check) . "'>"
                    . "<span class='form-check-label'>" . htmlescape($check) . "</span></label>";
            }
            echo "</div>";
        }

        if (!empty($categoryOptions)) {
            echo "<div class='mb-3 sprint-qe-category-block'><label class='form-label'>"
                . "<i class='fas fa-folder me-1'></i>" . __('Category', 'sprint') . "</label>";
            echo "<select name='plugin_sprint_sprintcategories_id' class='form-select'>";
            echo "<option value='0'>" . __('No category', 'sprint') . "</option>";
            echo SprintCategory::dropdownOptions();
            echo "</select>";
            if ($categoryLocked) {
                echo "<div class='form-text small text-warning sprint-qe-category-hint'>"
                    . "<i class='fas fa-user-shield me-1'></i>{$categoryRequestHint}</div>";
            }
            echo "</div>";
        }

        if ($epicOptions) {
            echo "<div class='mb-3'><label class='form-label'>" . __('Epic', 'sprint') . "</label>";
            echo "<select name='plugin_sprint_sprintepics_id' class='form-select'>";
            echo "<option value='0'>" . __('No epic', 'sprint') . "</option>";
            foreach ($epicOptions as $eid => $ename) {
                echo "<option value='" . (int)$eid . "'>" . htmlescape($ename) . "</option>";
            }
            echo "</select></div>";
        }

        echo "<div class='row g-3'>";
        echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __('Owner', 'sprint') . "</label>";
        echo "<select name='users_id' class='form-select'>";
        foreach ($memberOptions as $uid => $uname) {
            echo "<option value='" . (int)$uid . "'>" . htmlescape((string)$uname) . "</option>";
        }
        echo "</select></div>";
        echo "<div class='col-md-3 mb-3 sprint-qe-story-points'><label class='form-label'>" . __('Story Points', 'sprint') . "</label>";
        echo "<input type='number' name='story_points' class='form-control' min='0' step='1' value='1'></div>";
        echo "<div class='col-md-3 mb-3 sprint-qe-capacity'><label class='form-label'>" . __('Capacity (%)', 'sprint') . "</label>";
        echo "<select name='capacity' class='form-select'>";
        foreach ($capacityChoices as $val => $label) {
            echo "<option value='" . htmlescape((string)$val) . "'>" . htmlescape((string)$label) . "</option>";
        }
        echo "</select>";
        if ($capacityLocked) {
            echo "<div class='form-text small text-warning sprint-qe-capacity-hint'>"
                . "<i class='fas fa-user-shield me-1'></i>{$capacityRequestHint}</div>";
        }
        echo "</div>";
        if ($plannedActual) {
            echo "<div class='col-md-3 mb-3 sprint-qe-capacity-actual'><label class='form-label text-nowrap' title='" . __s('Actual capacity (%)', 'sprint') . "'>" . __('Actual', 'sprint') . " %</label>";
            echo "<select name='capacity_actual' class='form-select'>";
            echo "<option value=''>" . __('Follows planned', 'sprint') . "</option>";
            foreach ($capacityChoices as $val => $label) {
                echo "<option value='" . htmlescape((string)$val) . "'>" . htmlescape((string)$label) . "</option>";
            }
            echo "</select></div>";
        }
        echo "</div>";

        echo "<div class='mb-3'><label class='form-label'>" . __('Note', 'sprint') . "</label>";
        echo "<textarea name='note' class='form-control' rows='8' style='min-height:180px;'></textarea></div>";

        if (!empty($definedTags)) {
            echo "<div class='mb-3 sprint-qe-tags-block'><label class='form-label'>"
                . "<i class='fas fa-tags me-1'></i>" . __('Tags', 'sprint') . "</label>";
            echo "<div class='d-flex flex-wrap gap-3'>";
            foreach ($definedTags as $tag) {
                echo "<label style='display:inline-flex;align-items:center;gap:6px;'>"
                    . "<input type='checkbox' class='sprint-qe-tag' value='" . htmlescape($tag) . "'>"
                    . "<span>" . htmlescape($tag) . "</span>"
                    . "</label>";
            }
            echo "</div></div>";
        }

        echo "<div class='mb-3'><label class='form-label'>"
            . "<i class='fas fa-forward text-success me-1'></i>"
            . __('Carry over to sprint', 'sprint') . "</label>";
        echo "<select name='carry_over_to_sprint_id' class='form-select'>";
        echo "<option value='0'>— " . htmlescape(__('Do not carry over', 'sprint')) . " —</option>";
        foreach ($moveTargets as $tid => $tlabel) {
            echo "<option value='" . (int)$tid . "'>" . htmlescape((string)$tlabel) . "</option>";
        }
        echo "</select>";
        echo "<div class='form-text small text-muted'>"
            . htmlescape(__('A fresh copy is created in the target sprint. The original stays in this sprint.', 'sprint'))
            . "</div></div>";

        echo "<div class='mb-3 sprint-qe-deps-block'><label class='form-label'>"
            . "<i class='fas fa-link' style='color:#20c997;margin-right:4px;'></i>"
            . __('Dependencies', 'sprint') . "</label>";
        echo "<div class='alert alert-info py-1 small sprint-qe-dep-status mb-2' style='display:none;'></div>";
        // Live dependency list (loaded on open); each row edits % or removes.
        echo "<div class='sprint-qe-dep-list small mb-2'></div>";
        echo "<div class='d-flex flex-wrap gap-2 align-items-center'>";
        echo "<select class='form-select form-select-sm sprint-qe-dep-user' style='max-width:240px;'>";
        echo "<option value='0'>" . htmlescape(__('Select sprint member', 'sprint')) . "</option>";
        foreach ($memberOptions as $uid => $uname) {
            if ((int)$uid > 0) {
                echo "<option value='" . (int)$uid . "'>" . htmlescape((string)$uname) . "</option>";
            }
        }
        echo "</select>";
        echo "<select class='form-select form-select-sm sprint-qe-dep-cap' style='max-width:120px;'>";
        foreach ($capacityChoices as $val => $label) {
            echo "<option value='" . htmlescape((string)$val) . "'" . ((string)$val === '5' ? ' selected' : '') . ">" . htmlescape((string)$label) . "</option>";
        }
        echo "</select>";
        echo "<button type='button' class='btn btn-sm btn-outline-success sprint-qe-dep-add'>"
            . "<i class='fas fa-plus me-1'></i>" . __('Add helper', 'sprint') . "</button>";
        echo "<a href='#' class='btn btn-sm btn-outline-secondary sprint-qe-dep-manage' target='_blank' rel='noopener'>"
            . "<i class='fas fa-external-link-alt me-1'></i>" . __('Manage dependencies', 'sprint') . "</a>";
        echo "</div>";
        echo "<div class='form-text small text-muted'>"
            . htmlescape(__("Couples a colleague to this item with their own capacity %. Open 'Manage' to resolve, reopen or remove.", 'sprint'))
            . "</div></div>";

        echo "</div>";
        echo "<div class='modal-footer'>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . __('Cancel') . "</button>";
        echo "<button type='button' class='btn btn-primary sprint-qe-save'><i class='fas fa-save me-1'></i> " .
            __('Save') . "</button>";
        echo "</div>";
        echo "</div></div></div>";

        $labelUnassigned = addslashes(__('Unassigned', 'sprint'));
        $adhocBadgeJs    = addslashes(self::renderAdhocBadge(true));

        // Capacity <option> list (non-zero) for editing existing dependencies.
        $depCapOptions = '';
        foreach (SprintMember::getCapacityChoices(false) as $cv => $cl) {
            $depCapOptions .= "<option value='" . htmlescape((string)$cv) . "'>" . htmlescape((string)$cl) . "</option>";
        }
        $depNoneLabel    = addslashes(__('No dependencies yet', 'sprint'));
        $depResolvedTxt  = addslashes(__('resolved', 'sprint'));
        $depRemoveTxt    = addslashes(__('Remove dependency', 'sprint'));
        $depRemoveConfirm = addslashes(__('Remove this dependency?', 'sprint'));

        echo <<<JS
<script>
$(function() {
    if (window.__sprintQuickEditBound) { return; }
    window.__sprintQuickEditBound = true;

    var statusColors = {
        'todo': '#6c757d',
        'in_progress': '#0d6efd',
        'review': '#6f42c1',
        'dependency': '#20c997',
        'done': '#198754',
        'blocked': '#dc3545'
    };

    var \$modal = \$('#sprint-quickedit-modal');

    function refreshDodBadge() {
        var \$badge = \$modal.find('.sprint-qe-dod-badge');
        if (!\$badge.length) { return; }
        var total = \$modal.find('.sprint-qe-dod').length;
        var done  = \$modal.find('.sprint-qe-dod:checked').length;
        \$badge.text(done + '/' + total + (done === total ? ' ✓' : ''))
            .toggleClass('bg-success', done === total)
            .toggleClass('bg-secondary', done !== total);
    }
    \$(document).on('change', '.sprint-qe-dod', refreshDodBadge);

    \$(document).on('click', '.sprint-quick-edit-btn', function() {
        // Works for table rows (tr[data-item-id]) and Kanban cards
        // (.sprint-kanban-card[data-item-id]) alike. Match the row/card
        // container explicitly — the table buttons themselves carry a
        // data-item-id, so a bare closest('[data-item-id]') would resolve to
        // the button (which has none of the data-* fields), wiping the item.
        var \$row       = \$(this).closest('tr, .sprint-kanban-card');
        var itemId     = \$row.data('item-id');
        var itemName   = \$row.data('item-name') || '';
        var status     = \$row.data('item-status') || '';
        var priority   = \$row.data('item-priority') || 3;
        var usersId    = \$row.data('users-id') || 0;
        var points     = \$row.data('story-points') || 0;
        var capacity   = \$row.data('capacity') || 0;
        var note       = \$row.data('note') || '';
        var isFastlane = parseInt(\$row.data('is-fastlane'), 10) === 1;

        \$modal.find('.sprint-qe-title').text(itemName);
        \$modal.find('input[name=id]').val(itemId);
        \$modal.find('input[name=name]').val(itemName);
        // Linked items mirror the underlying item's name (server-enforced);
        // only manual items expose an editable name.
        (function() {
            var linkedType = String(\$row.attr('data-linked-itemtype') || '');
            \$modal.find('input[name=name]')
                .prop('readonly', linkedType !== '')
                .attr('title', linkedType !== '' ? '{$lblNameFollows}' : '');
        })();
        \$modal.find('input[name=is_adhoc]').prop('checked', parseInt(\$row.data('is-adhoc'), 10) === 1);
        \$modal.find('select[name=status]').val(String(status));
        \$modal.find('select[name=priority]').val(String(priority));
        \$modal.find('select[name=users_id]').val(String(usersId));
        \$modal.find('input[name=story_points]').val(points);
        // Match the capacity option numerically ("0.5"/"5.0"/5 all resolve to
        // the same option) so decimal formatting never clears the selection.
        (function() {
            var \$sel = \$modal.find('select[name=capacity]');
            var num  = parseFloat(capacity);
            if (isNaN(num)) { num = 0; }
            var match = '';
            \$sel.find('option').each(function() {
                if (parseFloat(this.value) === num) { match = this.value; return false; }
            });
            \$sel.val(match);
            // Baseline for the guarded-capacity reason dialog on save.
            \$modal.data('qe-orig-capacity', num);
        })();
        (function() {
            var \$sel = \$modal.find('select[name=capacity_actual]');
            if (!\$sel.length) { return; }
            var raw = \$row.attr('data-capacity-actual');
            var num = parseFloat(raw);
            var match = '';
            if (raw !== undefined && raw !== '' && !isNaN(num)) {
                \$sel.find('option').each(function() {
                    if (this.value !== '' && parseFloat(this.value) === num) { match = this.value; return false; }
                });
            }
            \$sel.val(match);
        })();
        \$modal.find('textarea[name=note]').val(note);
        \$modal.find('select[name=carry_over_to_sprint_id]').val('0');
        \$modal.find('.sprint-qe-error').hide().text('');
        \$modal.find('.sprint-qe-story-points, .sprint-qe-capacity, .sprint-qe-capacity-actual').toggle(!isFastlane);
        \$modal.data('qe-is-fastlane', isFastlane ? 1 : 0);
        \$modal.removeData('qe-request-reason');
        \$modal.find('.sprint-qe-dep-status').hide().removeClass('alert-danger alert-success').addClass('alert-info').text('');
        \$modal.find('.sprint-qe-dep-user').val('0');
        \$modal.find('.sprint-qe-dep-manage').attr(
            'href',
            {$cfgRoot} + '/plugins/sprint/front/sprintitem.form.php?id=' + encodeURIComponent(itemId) +
                '&forcetab=' + encodeURIComponent('GlpiPlugin\\\\Sprint\\\\SprintItemDependency') + '\$1'
        );
        \$modal.data('deps-added', 0);
        loadQeDeps(itemId);

        // Populate tag checkboxes from the row's `|tag1|tag2|` lowercased blob.
        var tagBlob = String(\$row.attr('data-item-tags') || '|').toLowerCase();
        \$modal.find('.sprint-qe-tag').each(function() {
            var v = String(\$(this).val() || '').toLowerCase();
            \$(this).prop('checked', v !== '' && tagBlob.indexOf('|' + v + '|') !== -1);
        });

        // Definition of Done state from the row's pipe-delimited blob.
        var dodBlob = '|' + String(\$row.attr('data-done-checks') || '') + '|';
        \$modal.find('.sprint-qe-dod').each(function() {
            \$(this).prop('checked', dodBlob.indexOf('|' + String(\$(this).val()) + '|') !== -1);
        });
        refreshDodBadge();

        \$modal.find('select[name=plugin_sprint_sprintepics_id]').val(String(\$row.attr('data-epic-id') || '0'));

        // Category + baseline for the guarded-category reason dialog on save.
        (function() {
            var \$cat = \$modal.find('select[name=plugin_sprint_sprintcategories_id]');
            if (!\$cat.length) { return; }
            // A row that carries no category attribute at all cannot be edited
            // safely — submitting the select would write "no category" over
            // whatever the item really has. Hide the block for those rows.
            var known = typeof \$row.attr('data-category-id') !== 'undefined';
            \$modal.data('qe-category-known', known ? 1 : 0);
            \$modal.find('.sprint-qe-category-block').toggle(known);
            if (!known) { \$modal.removeData('qe-orig-category'); return; }
            var cur = String(parseInt(\$row.attr('data-category-id'), 10) || 0);
            // Drop the placeholder a previous row may have left behind, then
            // re-add one when this row carries a deactivated category — without
            // it the select would fall back to "no category" and saving would
            // silently clear a category the admin only took out of circulation.
            \$cat.find('option.sprint-qe-cat-legacy').remove();
            if (cur !== '0' && !\$cat.find("option[value='" + cur + "']").length) {
                \$cat.append(\$('<option class="sprint-qe-cat-legacy"></option>')
                    .val(cur)
                    .text(\$row.attr('data-category-name') || ('#' + cur)));
            }
            \$cat.val(cur);
            if (\$cat.val() === null) { \$cat.val('0'); }
            \$modal.data('qe-orig-category', \$cat.val());
        })();

        // Guarded capacity for non-scrum-master users: the select stays
        // enabled, but a change is sent to the Scrum Master as an approval
        // request (see the hint under the select) — fastlane items stay free.
        var capacityLocked = {$capacityLockedJs};
        \$modal.find('.sprint-qe-capacity-hint').toggle(capacityLocked && !isFastlane);

        var m = new bootstrap.Modal(\$modal[0]);
        m.show();
        \$modal.data('bs-instance', m);
    });

    \$(document).on('click', '.sprint-qe-save', function() {
        var \$btn = \$(this);
        var id = \$modal.find('input[name=id]').val();
        \$modal.find('.sprint-qe-error').hide().text('');

        // Guarded capacity/category edits become approval requests: collect
        // the requester's motivation for the Scrum Master first. Dismissing the
        // dialog aborts the save. Fastlane items are exempt from the capacity
        // guard only — their category is still Scrum Master territory.
        var capacityGuarded = {$capacityLockedJs};
        var categoryGuarded = {$categoryLockedJs};
        var isFastlaneItem  = parseInt(\$modal.data('qe-is-fastlane'), 10) === 1;

        var capChanged = false;
        if (capacityGuarded && !isFastlaneItem) {
            var origCap = parseFloat(\$modal.data('qe-orig-capacity'));
            if (isNaN(origCap)) { origCap = 0; }
            var newCap = parseFloat(\$modal.find('select[name=capacity]').val());
            if (isNaN(newCap)) { newCap = 0; }
            capChanged = newCap !== origCap;
        }
        var categoryKnown = parseInt(\$modal.data('qe-category-known'), 10) === 1;
        var \$catSel = \$modal.find('select[name=plugin_sprint_sprintcategories_id]');
        var catChanged = categoryGuarded
            && categoryKnown
            && \$catSel.length > 0
            && String(\$catSel.val()) !== String(\$modal.data('qe-orig-category'));

        // One dialog covers both: a single motivation is stored against every
        // request this save raises.
        if ((capChanged || catChanged)
            && !\$modal.data('qe-request-reason')
            && typeof window.sprintRequestReason === 'function') {
            window.sprintRequestReason(function(reason){
                \$modal.data('qe-request-reason', reason);
                \$btn.trigger('click');
            });
            return;
        }

        function runSave(confirmOverflow) {
            \$btn.prop('disabled', true);
            return \$.ajax({
            url: {$cfgRoot} + '/plugins/sprint/ajax/csrftoken.php',
            type: 'GET', dataType: 'json', cache: false
        }).then(function(tokResp) {
            var tags = [];
            \$modal.find('.sprint-qe-tag:checked').each(function() {
                tags.push(\$(this).val());
            });
            // JSON-encode tags so an empty selection still transmits — jQuery
            // drops empty arrays during form serialization, which would
            // otherwise prevent unchecking the last tag from clearing it.
            return \$.ajax({
                url: {$cfgRoot} + '/plugins/sprint/ajax/updateitemquick.php',
                type: 'POST', dataType: 'json',
                data: {
                    id: id,
                    name: \$modal.find('input[name=name]').val(),
                    // Adhoc switch only exists in the Scrum Master's modal;
                    // jQuery drops undefined values for everyone else.
                    is_adhoc: \$modal.find('input[name=is_adhoc]').length
                        ? (\$modal.find('input[name=is_adhoc]').is(':checked') ? 1 : 0)
                        : undefined,
                    status: \$modal.find('select[name=status]').val(),
                    priority: \$modal.find('select[name=priority]').val(),
                    users_id: \$modal.find('select[name=users_id]').val(),
                    story_points: \$modal.find('input[name=story_points]').val(),
                    capacity: \$modal.find('select[name=capacity]').val(),
                    capacity_actual: \$modal.find('select[name=capacity_actual]').length
                        ? \$modal.find('select[name=capacity_actual]').val()
                        : undefined,
                    note: \$modal.find('textarea[name=note]').val(),
                    carry_over_to_sprint_id: \$modal.find('select[name=carry_over_to_sprint_id]').val(),
                    capacity_reason: \$modal.data('qe-request-reason') || undefined,
                    category_reason: \$modal.data('qe-request-reason') || undefined,
                    plugin_sprint_sprintcategories_id: (categoryKnown && \$catSel.length)
                        ? \$catSel.val()
                        : undefined,
                    plugin_sprint_sprintepics_id: \$modal.find('select[name=plugin_sprint_sprintepics_id]').length
                        ? \$modal.find('select[name=plugin_sprint_sprintepics_id]').val()
                        : undefined,
                    _dod_json: \$modal.find('.sprint-qe-dod').length
                        ? JSON.stringify(\$modal.find('.sprint-qe-dod:checked').map(function(){ return \$(this).val(); }).get())
                        : undefined,
                    _tags_json: JSON.stringify(tags),
                    confirm_overflow: confirmOverflow ? 1 : 0,
                    _glpi_csrf_token: tokResp && tokResp.token ? tokResp.token : ''
                }
            });
            });
        }

        function onResp(resp) {
            if (resp && resp.needs_confirm) {
                if (window.confirm(resp.message)) {
                    runSave(true).done(onResp).fail(onFail).always(onAlways);
                } else {
                    \$btn.prop('disabled', false);
                }
                return;
            }
            if (resp && resp.success) {
                if (resp.carried_over && resp.carry_over_message) {
                    try { if (typeof glpi_toast_info === 'function') { glpi_toast_info(resp.carry_over_message); } } catch (e) {}
                }
                // Relay server-side notices (e.g. "capacity change sent to the
                // Scrum Master for approval") that would otherwise be lost.
                if (resp.message && resp.message !== 'Item updated') {
                    try { if (typeof glpi_toast_info === 'function') { glpi_toast_info(resp.message); } } catch (e) {}
                }
                var \$row = \$('tr.sprint-row[data-item-id="' + id + '"], tr.sprint-review-row[data-item-id="' + id + '"]');
                var statusSelect   = \$modal.find('select[name=status]');
                var statusLabel    = statusSelect.find('option:selected').text() || '';
                var ownerSelect    = \$modal.find('select[name=users_id]');
                var ownerLabel     = ownerSelect.find('option:selected').text() || '';
                var prioritySelect = \$modal.find('select[name=priority]');
                var priorityLabel  = prioritySelect.find('option:selected').text() || '';
                var capacitySelect = \$modal.find('select[name=capacity]');
                var capacityLabel  = capacitySelect.find('option:selected').text() || (resp.capacity + '%');
                var statusColor    = statusColors[resp.status] || '#6c757d';
                var ownerId        = parseInt(resp.users_id, 10) || 0;

                // Sync row data attributes so filters/sorts and subsequent
                // edits all start from fresh values.
                \$row.attr('data-item-name', resp.name).data('item-name', resp.name);
                \$row.attr('data-item-status', resp.status).data('item-status', resp.status);
                \$row.attr('data-item-status-label', statusLabel).data('item-status-label', statusLabel);
                \$row.attr('data-item-priority', resp.priority).data('item-priority', resp.priority);
                \$row.attr('data-users-id', resp.users_id).data('users-id', resp.users_id);
                \$row.attr('data-owner-name', ownerLabel).data('owner-name', ownerLabel);
                \$row.attr('data-story-points', resp.story_points).data('story-points', resp.story_points);
                \$row.attr('data-capacity', resp.capacity).data('capacity', resp.capacity);
                if (typeof resp.capacity_actual !== 'undefined') {
                    \$row.attr('data-capacity-actual', resp.capacity_actual);
                }
                \$row.attr('data-note', resp.note).data('note', resp.note);

                if (typeof resp.is_adhoc !== 'undefined') {
                    var adhocOn = parseInt(resp.is_adhoc, 10) === 1;
                    \$row.attr('data-is-adhoc', adhocOn ? '1' : '0').data('is-adhoc', adhocOn ? 1 : 0);
                    \$row.toggleClass('sprint-adhoc', adhocOn);
                    \$row.find('.sprint-adhoc-badge').remove();
                    if (adhocOn) {
                        var \$adhocAnchor = \$row.find("a[href*='sprintitem.form.php']").first();
                        if (\$adhocAnchor.length) {
                            \$adhocAnchor.after('{$adhocBadgeJs}');
                        }
                    }
                }

                if (typeof resp.tags_blob !== 'undefined') {
                    \$row.attr('data-item-tags', resp.tags_blob).data('item-tags', resp.tags_blob);
                }
                if (typeof resp.done_checks_blob !== 'undefined') {
                    \$row.attr('data-done-checks', resp.done_checks_blob).data('done-checks', resp.done_checks_blob);
                }
                if (typeof resp.epic_id !== 'undefined') {
                    \$row.attr('data-epic-id', resp.epic_id).data('epic-id', resp.epic_id);
                }
                // Category: refresh the row attrs plus whichever presentation
                // this page uses — a dedicated column on the items list, an
                // inline pill on Kanban cards and fastlane rows. A guarded edit
                // leaves the item untouched, so the server value always wins.
                if (typeof resp.category_id !== 'undefined') {
                    var \$catCard = \$('.sprint-kanban-card[data-item-id="' + id + '"]');
                    \$row.add(\$catCard)
                        .attr('data-category-id', resp.category_id)
                        .attr('data-category-name', resp.category_name || '');
                    \$row.add(\$catCard).each(function() {
                        var \$r    = \$(this);
                        var \$cell = \$r.find('.sprint-cell-category');
                        if (\$cell.length) {
                            \$cell.html(resp.category_cell_html || '');
                            return;
                        }
                        // Inline pill: drop the old one, re-anchor the fresh
                        // one right after the item link so it lands where the
                        // server-side renderer would have put it.
                        \$r.find('.sprint-category-pill').remove();
                        if (!resp.category_pill_html) { return; }
                        var \$anchor = \$r.find("a[href*='sprintitem.form.php']").first();
                        if (\$anchor.length) { \$anchor.after(resp.category_pill_html); }
                    });
                }
                // Live "Linked item open" badge: shows/hides with the fresh
                // status instead of waiting for a page reload.
                if (typeof resp.linked_open_badge_html !== 'undefined') {
                    \$row.find('.sprint-cell-name .sprint-review-unclosed-badge').remove();
                    if (resp.linked_open_badge_html !== '') {
                        \$row.find('.sprint-cell-name').append(resp.linked_open_badge_html);
                    }
                    var \$cardTitle = \$('.sprint-kanban-card[data-item-id="' + id + '"] .sk-card-title');
                    if (\$cardTitle.length) {
                        \$cardTitle.find('.sprint-review-unclosed-badge').remove();
                        if (resp.linked_open_badge_html !== '') {
                            var \$cardEdit = \$cardTitle.find('.sk-card-edit');
                            if (\$cardEdit.length) {
                                \$cardEdit[0].insertAdjacentHTML('beforebegin', resp.linked_open_badge_html);
                            } else {
                                \$cardTitle[0].insertAdjacentHTML('beforeend', resp.linked_open_badge_html);
                            }
                        }
                    }
                }

                if (typeof resp.tags_pills_html !== 'undefined') {
                    \$row.each(function() {
                        var \$r = \$(this);
                        var \$existing = \$r.find('.sprint-tag-pills');
                        if (\$existing.length) {
                            \$existing.replaceWith(resp.tags_pills_html);
                        } else if (resp.tags_pills_html !== '') {
                            // No pill span yet — anchor it after the row's
                            // sprint-item link so it lands in the same cell
                            // the server-side renderer would have used.
                            var \$anchor = \$r.find("a[href*='sprintitem.form.php']").first();
                            if (\$anchor.length) {
                                \$anchor.after(resp.tags_pills_html);
                            }
                        }
                    });
                }

                // === Refresh visible cells on PHP-rendered list/dashboard rows ===
                var \$listRow = \$row.filter('.sprint-row');
                if (\$listRow.length) {
                    // Name link text
                    \$listRow.find('.sprint-cell-name a').text(resp.name);
                    // Status badge
                    \$listRow.find('.sprint-cell-status .sprint-badge')
                        .removeClass(function(i,c){ return (c.match(/sprint-status-\\S+/g) || []).join(' '); })
                        .addClass('sprint-status-' + String(resp.status).replace(/_/g, '-'))
                        .text(statusLabel)
                        .css('background-color', statusColor);
                    // Priority label
                    \$listRow.find('.sprint-cell-priority').text(priorityLabel);
                    // Owner name (or "Unassigned" placeholder)
                    var \$ownerListCell = \$listRow.find('.sprint-cell-owner');
                    if (\$ownerListCell.length) {
                        if (ownerId > 0 && ownerLabel) {
                            \$ownerListCell.text(ownerLabel);
                        } else {
                            \$ownerListCell.html('<span style="color:#999;">{$labelUnassigned}</span>');
                        }
                    }
                    // Story points
                    \$listRow.find('.sprint-cell-story-points').text(resp.story_points);
                    // Capacity — retain "%" suffix
                    \$listRow.find('.sprint-cell-capacity').text(resp.capacity + '%');
                }

                // === Refresh meeting-review read-only cells ===
                \$row.find('.sprint-review-status')
                    .text(statusLabel)
                    .css('background-color', statusColor);
                var \$ownerCell = \$row.find('.sprint-review-owner');
                if (\$ownerCell.length) {
                    if (ownerId > 0 && ownerLabel) {
                        \$ownerCell.html('<i class="fas fa-user text-muted me-1"></i>' + \$('<div>').text(ownerLabel).html());
                    } else {
                        \$ownerCell.html('<span class="text-muted fst-italic">{$labelUnassigned}</span>');
                    }
                }
                \$row.find('.sprint-note-display').text(resp.note || '');

                // Keep the meeting name cell's link text in sync too. Skip
                // button-styled anchors (e.g. fastlane "Manage members"),
                // which also point at sprintitem.form.php — replacing their
                // <i> icon with the item name corrupts the action cell.
                \$row.find('td a').filter(function() {
                    var \$a = \$(this);
                    if (\$a.hasClass('btn')) { return false; }
                    var href = \$a.attr('href');
                    return href && href.indexOf('sprintitem.form.php') !== -1;
                }).text(resp.name);

                var m = \$modal.data('bs-instance');
                if (m) { m.hide(); }
            } else {
                \$modal.find('.sprint-qe-error').text(resp && resp.message ? resp.message : 'Save failed').show();
            }
        }
        function onFail() {
            \$modal.find('.sprint-qe-error').text('Network error').show();
        }
        function onAlways() {
            \$btn.prop('disabled', false);
        }

        runSave(false).done(onResp).fail(onFail).always(onAlways);
    });

    \$(document).on('click', '.sprint-qe-dep-add', function() {
        var \$btn   = \$(this);
        var itemId = \$modal.find('input[name=id]').val();
        var userId = parseInt(\$modal.find('.sprint-qe-dep-user').val(), 10) || 0;
        var cap    = parseFloat(\$modal.find('.sprint-qe-dep-cap').val()) || 0;
        var \$status = \$modal.find('.sprint-qe-dep-status');

        if (!itemId || userId <= 0 || cap <= 0) {
            \$status.removeClass('alert-info alert-success').addClass('alert-danger')
                .text('Select a member and a capacity > 0').show();
            return;
        }
        function runDepAdd(confirmOverflow) {
            \$btn.prop('disabled', true);
            return \$.ajax({
            url: {$cfgRoot} + '/plugins/sprint/ajax/csrftoken.php',
            type: 'GET', dataType: 'json', cache: false
        }).then(function(tokResp) {
            return \$.ajax({
                url: {$cfgRoot} + '/plugins/sprint/ajax/dependencyadd.php',
                type: 'POST', dataType: 'json',
                data: {
                    plugin_sprint_sprintitems_id: itemId,
                    users_id: userId,
                    capacity: cap,
                    confirm_overflow: confirmOverflow ? 1 : 0,
                    _glpi_csrf_token: tokResp && tokResp.token ? tokResp.token : ''
                }
            });
            });
        }

        function onDepResp(resp) {
            if (resp && resp.needs_confirm) {
                if (window.confirm(resp.message)) {
                    runDepAdd(true).done(onDepResp).fail(onDepFail).always(onDepAlways);
                } else {
                    \$btn.prop('disabled', false);
                }
                return;
            }
            if (resp && resp.success) {
                \$status.removeClass('alert-info alert-danger').addClass('alert-success')
                    .text(resp.message).show();
                \$modal.find('.sprint-qe-dep-user').val('0');
                \$modal.data('deps-added', (parseInt(\$modal.data('deps-added'), 10) || 0) + 1);
                loadQeDeps(\$modal.find('input[name=id]').val());
            } else {
                \$status.removeClass('alert-info alert-success').addClass('alert-danger')
                    .text(resp && resp.message ? resp.message : 'Could not add dependency').show();
            }
        }
        function onDepFail() {
            \$status.removeClass('alert-info alert-success').addClass('alert-danger')
                .text('Network error').show();
        }
        function onDepAlways() {
            \$btn.prop('disabled', false);
        }

        runDepAdd(false).done(onDepResp).fail(onDepFail).always(onDepAlways);
    });

    // ---- Existing dependencies: live list + inline edit/remove ----
    var depCapOptions = "{$depCapOptions}";

    function qeEscapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
        });
    }

    function loadQeDeps(itemId) {
        var \$list = \$modal.find('.sprint-qe-dep-list');
        if (!\$list.length || !itemId) { return; }
        \$list.html('<span class="text-muted">…</span>');
        \$.ajax({
            url: {$cfgRoot} + '/plugins/sprint/ajax/dependencies.php',
            type: 'GET', dataType: 'json', cache: false,
            data: { action: 'list', plugin_sprint_sprintitems_id: itemId }
        }).done(function(resp) {
            if (!resp || !resp.success || !resp.deps || !resp.deps.length) {
                \$list.html('<span class="text-muted fst-italic">{$depNoneLabel}</span>');
                return;
            }
            var html = '';
            resp.deps.forEach(function(d) {
                html += '<div class="d-flex align-items-center gap-2 mb-1" data-dep-row="' + d.id + '">'
                    + '<span class="flex-grow-1"><i class="fas fa-link" style="color:#20c997;"></i> '
                    + qeEscapeHtml(d.name)
                    + (d.is_resolved ? ' <span class="badge bg-secondary">{$depResolvedTxt}</span>' : '')
                    + '</span>'
                    + '<select class="form-select form-select-sm sprint-qe-dep-edit-cap" data-dep-id="' + d.id + '"'
                    + (d.is_resolved ? ' disabled' : '') + ' style="max-width:90px;">' + depCapOptions + '</select>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger sprint-qe-dep-remove" '
                    + 'data-dep-id="' + d.id + '" title="{$depRemoveTxt}"><i class="fas fa-trash"></i></button>'
                    + '</div>';
            });
            \$list.html(html);
            resp.deps.forEach(function(d) {
                \$list.find('select.sprint-qe-dep-edit-cap[data-dep-id="' + d.id + '"]').val(String(d.capacity));
            });
        }).fail(function() {
            \$list.html('<span class="text-danger">Network error</span>');
        });
    }

    \$(document).on('change', '.sprint-qe-dep-edit-cap', function() {
        var \$sel  = \$(this);
        var depId  = parseInt(\$sel.data('dep-id'), 10) || 0;
        var cap    = parseFloat(\$sel.val()) || 0;
        var itemId = \$modal.find('input[name=id]').val();
        var \$status = \$modal.find('.sprint-qe-dep-status');
        if (depId <= 0 || cap <= 0) { return; }
        \$sel.prop('disabled', true);
        \$.ajax({
            url: {$cfgRoot} + '/plugins/sprint/ajax/csrftoken.php',
            type: 'GET', dataType: 'json', cache: false
        }).then(function(tok) {
            return \$.ajax({
                url: {$cfgRoot} + '/plugins/sprint/ajax/dependencies.php',
                type: 'POST', dataType: 'json',
                data: {
                    action: 'update', plugin_sprint_sprintitems_id: itemId, id: depId,
                    capacity: cap, _glpi_csrf_token: tok && tok.token ? tok.token : ''
                }
            });
        }).done(function(resp) {
            if (resp && resp.success) {
                \$status.removeClass('alert-info alert-danger').addClass('alert-success')
                    .text((resp.warning ? (resp.message + ' — ' + resp.warning) : resp.message)).show();
                \$modal.data('deps-added', (parseInt(\$modal.data('deps-added'), 10) || 0) + 1);
            } else {
                \$status.removeClass('alert-info alert-success').addClass('alert-danger')
                    .text(resp && resp.message ? resp.message : 'Could not update dependency').show();
            }
        }).fail(function() {
            \$status.removeClass('alert-info alert-success').addClass('alert-danger').text('Network error').show();
        }).always(function() {
            \$sel.prop('disabled', false);
        });
    });

    \$(document).on('click', '.sprint-qe-dep-remove', function() {
        var depId  = parseInt(\$(this).data('dep-id'), 10) || 0;
        var itemId = \$modal.find('input[name=id]').val();
        if (depId <= 0) { return; }
        if (!window.confirm("{$depRemoveConfirm}")) { return; }
        var \$status = \$modal.find('.sprint-qe-dep-status');
        \$.ajax({
            url: {$cfgRoot} + '/plugins/sprint/ajax/csrftoken.php',
            type: 'GET', dataType: 'json', cache: false
        }).then(function(tok) {
            return \$.ajax({
                url: {$cfgRoot} + '/plugins/sprint/ajax/dependencies.php',
                type: 'POST', dataType: 'json',
                data: {
                    action: 'remove', plugin_sprint_sprintitems_id: itemId, id: depId,
                    _glpi_csrf_token: tok && tok.token ? tok.token : ''
                }
            });
        }).done(function(resp) {
            if (resp && resp.success) {
                \$status.removeClass('alert-info alert-danger').addClass('alert-success').text(resp.message).show();
                \$modal.data('deps-added', (parseInt(\$modal.data('deps-added'), 10) || 0) + 1);
                loadQeDeps(itemId);
            } else {
                \$status.removeClass('alert-info alert-success').addClass('alert-danger')
                    .text(resp && resp.message ? resp.message : 'Could not remove dependency').show();
            }
        }).fail(function() {
            \$status.removeClass('alert-info alert-success').addClass('alert-danger').text('Network error').show();
        });
    });

    \$modal.on('hidden.bs.modal', function() {
        var added = parseInt(\$modal.data('deps-added'), 10) || 0;
        if (added > 0) {
            \$modal.data('deps-added', 0);
            location.reload();
        }
    });
});
</script>
JS;
        // Reason dialog for guarded capacity/category edits (approval requests).
        SprintRequest::renderReasonModalUI();
    }

    /**
     * Render the "quick edit linked item" modal + JS once per page, so users
     * can change the linked Ticket/Change/Problem/ProjectTask status (and
     * ProjectTask percent-done) without leaving the sprint view.
     */
    public static function renderLinkedQuickEditUI(): void
    {
        // One instance per page; the modal attaches to document and is reused.
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        $ticketStatuses  = \Ticket::getAllStatusArray(true);
        $changeStatuses  = \Change::getAllStatusArray(true);
        $problemStatuses = \Problem::getAllStatusArray(true);

        $projectStates = [];
        $ps = new \ProjectState();
        foreach ($ps->find([], ['name ASC']) as $r) {
            $projectStates[(int)$r['id']] = (string)$r['name'];
        }
        // Explicit "none" option for ProjectTask (0 = no state).
        $projectStates = [0 => '-----'] + $projectStates;

        echo "<div class='modal fade' id='sprint-linked-quickedit-modal' tabindex='-1' aria-hidden='true'>";
        echo "<div class='modal-dialog modal-dialog-centered'>";
        echo "<div class='modal-content'>";
        echo "<div class='modal-header'>";
        echo "<h5 class='modal-title'>"
            . "<i class='sprint-lqe-icon fas fa-pen me-1'></i>"
            . "<span class='sprint-lqe-type-label'></span>"
            . ": <span class='sprint-lqe-title'></span></h5>";
        echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>";
        echo "</div>";
        echo "<div class='modal-body'>";
        echo "<div class='alert alert-danger sprint-lqe-error' style='display:none;white-space:pre-line;'></div>";
        echo "<input type='hidden' name='itemtype'>";
        echo "<input type='hidden' name='id'>";

        echo "<div class='mb-3 sprint-lqe-ticket-status' style='display:none;'>";
        echo "<label class='form-label'>" . __('Status') . "</label>";
        echo "<select class='form-select' name='ticket_status'>";
        foreach ($ticketStatuses as $k => $label) {
            echo "<option value='" . (int)$k . "'>" . htmlescape((string)$label) . "</option>";
        }
        echo "</select></div>";

        echo "<div class='mb-3 sprint-lqe-change-status' style='display:none;'>";
        echo "<label class='form-label'>" . __('Status') . "</label>";
        echo "<select class='form-select' name='change_status'>";
        foreach ($changeStatuses as $k => $label) {
            echo "<option value='" . (int)$k . "'>" . htmlescape((string)$label) . "</option>";
        }
        echo "</select></div>";

        echo "<div class='mb-3 sprint-lqe-problem-status' style='display:none;'>";
        echo "<label class='form-label'>" . __('Status') . "</label>";
        echo "<select class='form-select' name='problem_status'>";
        foreach ($problemStatuses as $k => $label) {
            echo "<option value='" . (int)$k . "'>" . htmlescape((string)$label) . "</option>";
        }
        echo "</select></div>";

        echo "<div class='mb-3 sprint-lqe-ptask-status' style='display:none;'>";
        echo "<label class='form-label'>" . __('Status') . "</label>";
        echo "<select class='form-select' name='projectstates_id'>";
        foreach ($projectStates as $k => $label) {
            echo "<option value='" . (int)$k . "'>" . htmlescape((string)$label) . "</option>";
        }
        echo "</select></div>";

        echo "<div class='mb-3 sprint-lqe-ptask-percent' style='display:none;'>";
        echo "<label class='form-label'>" . __('Percent done') . "</label>";
        echo "<input type='number' class='form-control' name='percent_done' min='0' max='100' step='1' value='0'>";
        echo "</div>";

        echo "</div>";
        echo "<div class='modal-footer'>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . __('Cancel') . "</button>";
        echo "<button type='button' class='btn btn-primary sprint-lqe-save'>"
            . "<i class='fas fa-save me-1'></i> " . __('Save') . "</button>";
        echo "</div></div></div></div>";

        $labelTicket  = addslashes(__('Ticket'));
        $labelChange  = addslashes(__('Change'));
        $labelProblem = addslashes(__('Problem'));
        $labelPTask   = addslashes(__('Project task'));

        echo <<<JS
<script>
\$(function() {
    if (window.__sprintLinkedQuickEditBound) { return; }
    window.__sprintLinkedQuickEditBound = true;

    var typeMeta = {
        'Ticket':      { icon: 'fas fa-ticket-alt',       label: '{$labelTicket}' },
        'Change':      { icon: 'fas fa-exchange-alt',     label: '{$labelChange}' },
        'Problem':     { icon: 'fas fa-exclamation-circle', label: '{$labelProblem}' },
        'ProjectTask': { icon: 'fas fa-tasks',            label: '{$labelPTask}' }
    };

    // Closing/reopening the linked item flips the "Linked item open" warning
    // on every view showing that sprint item. Patch list rows, meeting review
    // rows and Kanban cards in place so the badge doesn't linger until the
    // next page load.
    function syncLinkedOpenBadge(itemId, html) {
        var sel = '[data-item-id="' + itemId + '"]';
        \$('tr' + sel + ', .sprint-kanban-card' + sel).each(function() {
            var \$el = \$(this);
            \$el.find('.sprint-review-unclosed-badge').remove();
            // Meeting review rows tint the whole row while the link is open.
            \$el.toggleClass('sprint-review-unclosed', html !== '');
            if (html === '') { return; }

            var \$nameCell = \$el.find('.sprint-cell-name').first();
            if (\$nameCell.length) { \$nameCell.append(html); return; }

            var \$status = \$el.find('.sprint-review-status').first();
            if (\$status.length) { \$status.after(html); return; }

            var \$cardTitle = \$el.find('.sk-card-title').first();
            if (\$cardTitle.length) {
                var \$cardEdit = \$cardTitle.find('.sk-card-edit');
                if (\$cardEdit.length) { \$cardEdit[0].insertAdjacentHTML('beforebegin', html); }
                else { \$cardTitle[0].insertAdjacentHTML('beforeend', html); }
                return;
            }
            // Fallback: after the sprint-item link in the row.
            var \$anchor = \$el.find("a[href*='sprintitem.form.php']").first();
            if (\$anchor.length) { \$anchor.after(html); }
        });
    }

    \$(document).on('click', '.sprint-linked-quick-edit-btn', function(ev) {
        ev.preventDefault();
        ev.stopPropagation();

        var \$btn    = \$(this);
        var itemtype = String(\$btn.data('linked-itemtype') || '');
        var id       = parseInt(\$btn.data('linked-id'), 10) || 0;
        var name     = \$btn.data('linked-name') || '';
        var status   = \$btn.data('linked-status');
        var percent  = parseInt(\$btn.data('linked-percent'), 10) || 0;

        var meta = typeMeta[itemtype];
        if (!meta || id <= 0) { return; }

        var \$m = \$('#sprint-linked-quickedit-modal');
        \$m.find('input[name=itemtype]').val(itemtype);
        \$m.find('input[name=id]').val(id);
        \$m.find('.sprint-lqe-title').text(name);
        \$m.find('.sprint-lqe-type-label').text(meta.label);
        \$m.find('.sprint-lqe-icon').attr('class', 'sprint-lqe-icon ' + meta.icon + ' me-1');
        \$m.find('.sprint-lqe-error').hide().text('');

        \$m.find('.sprint-lqe-ticket-status, .sprint-lqe-change-status, .sprint-lqe-ptask-status, .sprint-lqe-ptask-percent').hide();

        if (itemtype === 'Ticket') {
            \$m.find('.sprint-lqe-ticket-status').show();
            \$m.find('select[name=ticket_status]').val(String(status));
        } else if (itemtype === 'Change') {
            \$m.find('.sprint-lqe-change-status').show();
            \$m.find('select[name=change_status]').val(String(status));
        } else if (itemtype === 'Problem') {
            \$m.find('.sprint-lqe-problem-status').show();
            \$m.find('select[name=problem_status]').val(String(status));
        } else if (itemtype === 'ProjectTask') {
            \$m.find('.sprint-lqe-ptask-status').show();
            \$m.find('.sprint-lqe-ptask-percent').show();
            \$m.find('select[name=projectstates_id]').val(String(status));
            \$m.find('input[name=percent_done]').val(percent);
        }

        var bsm = bootstrap.Modal.getOrCreateInstance(\$m[0]);
        bsm.show();
        \$m.data('bs-instance', bsm);
        \$m.data('trigger-btn', \$btn);
    });

    \$(document).on('click', '.sprint-lqe-save', function() {
        var \$btn = \$(this);
        var \$m   = \$('#sprint-linked-quickedit-modal');
        var itemtype = \$m.find('input[name=itemtype]').val();
        var id       = \$m.find('input[name=id]').val();

        var payload = { itemtype: itemtype, id: id };
        if (itemtype === 'Ticket') {
            payload.status = \$m.find('select[name=ticket_status]').val();
        } else if (itemtype === 'Change') {
            payload.status = \$m.find('select[name=change_status]').val();
        } else if (itemtype === 'Problem') {
            payload.status = \$m.find('select[name=problem_status]').val();
        } else if (itemtype === 'ProjectTask') {
            payload.projectstates_id = \$m.find('select[name=projectstates_id]').val();
            payload.percent_done     = \$m.find('input[name=percent_done]').val();
        }

        \$btn.prop('disabled', true);
        \$m.find('.sprint-lqe-error').hide().text('');

        \$.ajax({
            url: CFG_GLPI.root_doc + '/plugins/sprint/ajax/csrftoken.php',
            type: 'GET', dataType: 'json', cache: false
        }).then(function(tokResp) {
            payload._glpi_csrf_token = tokResp && tokResp.token ? tokResp.token : '';
            return \$.ajax({
                url: CFG_GLPI.root_doc + '/plugins/sprint/ajax/updatelinkedquick.php',
                type: 'POST', dataType: 'json', data: payload
            });
        }).done(function(resp) {
            if (resp && resp.success) {
                // Push the new state back onto the triggering button so a
                // second click without a page reload picks up fresh data.
                var \$trigger = \$m.data('trigger-btn');
                if (\$trigger && \$trigger.length) {
                    if (itemtype === 'ProjectTask') {
                        \$trigger.attr('data-linked-status', resp.projectstates_id)
                            .data('linked-status', resp.projectstates_id);
                        \$trigger.attr('data-linked-percent', resp.percent_done)
                            .data('linked-percent', resp.percent_done);
                        // Keep the compact linked-item % badge in sync.
                        \$trigger.closest('.sprint-linked-compact')
                            .find('.sprint-linked-pct').text(resp.percent_done + '%');
                    } else {
                        \$trigger.attr('data-linked-status', resp.status)
                            .data('linked-status', resp.status);
                    }
                }
                if (resp.affected_items && resp.affected_items.length) {
                    for (var i = 0; i < resp.affected_items.length; i++) {
                        var aff = resp.affected_items[i];
                        syncLinkedOpenBadge(aff.id, aff.badge_html || '');
                    }
                }
                var bsm = \$m.data('bs-instance');
                if (bsm) { bsm.hide(); }
            } else {
                \$m.find('.sprint-lqe-error')
                    .text(resp && resp.message ? resp.message : 'Save failed').show();
            }
        }).fail(function() {
            \$m.find('.sprint-lqe-error').text('Network error').show();
        }).always(function() {
            \$btn.prop('disabled', false);
        });
    });
});
</script>
JS;
    }

    /**
     * Item-form _dod[] → done_checks JSON, restricted to the global definition;
     * must run before the transition policy so status + DoD save together.
     */
    private static function normalizeDodInput(array $input): array
    {
        if (array_key_exists('_dod', $input)) {
            $chosen = is_array($input['_dod']) ? $input['_dod'] : [];
            $input['done_checks'] = json_encode(array_values(array_intersect(Config::getDefinitionDone(), $chosen)));
            unset($input['_dod']);
        }
        return $input;
    }

    /**
     * Process input before adding: resolve linked item name + validate capacity
     */
    public function prepareInputForAdd($input)
    {
        $input = self::sanitizeInput($input);
        $input = $this->resolveLinkedItem($input);
        $input = $this->enforceLinkedItemName($input);
        $input = self::normalizeDodInput($input);

        if (isset($input['status'])) {
            $candidate = new self();
            $candidate->fields = array_merge($this->fields, $input);
            $candidate->fields['plugin_sprint_sprints_id'] = (int)($input['plugin_sprint_sprints_id'] ?? 0);
            $candidate->fields['status'] = self::STATUS_TODO;
            $policy = SprintAgility::validateTransition($candidate, (string)$input['status']);
            if (!$policy['ok']) {
                Session::addMessageAfterRedirect($policy['message'], false, ERROR);
                return false;
            }
        }
        if (!isset($input['story_points']) || $input['story_points'] === '' || $input['story_points'] === null) {
            $input['story_points'] = 1;
        }
        // Adhoc: on backlog items anyone with edit rights may pre-flag it (the
        // flag travels with the item into the sprint); items entering a real
        // sprint directly need that sprint's Scrum Master.
        if (array_key_exists('is_adhoc', $input)) {
            $sprintId = (int)($input['plugin_sprint_sprints_id'] ?? 0);
            if ($sprintId > 0 && !self::currentUserIsScrumMasterOf($sprintId)) {
                unset($input['is_adhoc']);
            }
        }

        // Only the Scrum Master creates straight into a sprint; the rest lands
        // on the backlog with that sprint as a proposal.
        $targetSprint = (int)($input['plugin_sprint_sprints_id'] ?? 0);
        if (
            $targetSprint > 0
            && !self::$skipAssignGuard
            && (int)($input['is_fastlane'] ?? 0) !== 1
            && !self::currentUserIsScrumMasterOf($targetSprint)
        ) {
            $input['plugin_sprint_sprints_id'] = 0;
            $input['proposed_sprints_id']      = $targetSprint;
            Session::addMessageAfterRedirect(
                __('Only the Scrum Master can assign items to a sprint. The item was placed on the backlog with this sprint pre-selected.', 'sprint'),
                false,
                WARNING
            );
        }
        if (!$this->validateCapacity($input)) {
            return false;
        }
        if (!self::validateNoDuplicateLink($input)) {
            return false;
        }
        return parent::prepareInputForAdd($input);
    }

    /**
     * Process input before updating: resolve linked item name + validate capacity
     */
    public function prepareInputForUpdate($input)
    {
        // Approval-queue lock: while any request on this item awaits the Scrum
        // Master, only the Scrum Master of the request's sprint may change the
        // item. Without this the queue is bypassable by editing the item form
        // directly and saving. (Linked-item name sync is unaffected: it writes
        // through $DB->update, not through this hook.)
        $pendingReq = self::$linkedStatusAutomation ? null : SprintRequest::getAnyPendingForItem((int)$this->getID());
        if ($pendingReq !== null) {
            $reqSprintId = (int)($pendingReq['plugin_sprint_sprints_id'] ?? 0);
            if ($reqSprintId > 0 && !self::currentUserIsScrumMasterOf($reqSprintId)) {
                Session::addMessageAfterRedirect(
                    __('This item is locked while a request is pending Scrum Master approval.', 'sprint'),
                    false,
                    ERROR
                );
                return false;
            }
        }

        $input = self::sanitizeInput($input);
        $input = $this->resolveLinkedItem($input);
        $input = $this->enforceLinkedItemName($input);
        $input = self::normalizeDodInput($input);

        if (isset($input['status'])) {
            $policyItem = clone $this;
            if (isset($input['users_id'])) {
                $policyItem->fields['users_id'] = (int)$input['users_id'];
            }
            if (isset($input['done_checks'])) {
                $policyItem->fields['done_checks'] = (string)$input['done_checks'];
            }
            $policy = SprintAgility::validateTransition($policyItem, (string)$input['status']);
            if (!$policy['ok']) {
                Session::addMessageAfterRedirect($policy['message'], false, ERROR);
                return false;
            }
        }

        // Picking a sprint here is the same decision as a backlog assign, so it
        // goes through the same approval queue.
        if (array_key_exists('plugin_sprint_sprints_id', $input)) {
            $currentSprint = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
            $targetSprint  = (int)$input['plugin_sprint_sprints_id'];
            if (
                $targetSprint > 0
                && $targetSprint !== $currentSprint
                && (int)($this->fields['is_fastlane'] ?? 0) !== 1
                && !self::currentUserIsScrumMasterOf($targetSprint)
            ) {
                unset($input['plugin_sprint_sprints_id']);
                if ($currentSprint === 0) {
                    $input['proposed_sprints_id'] = $targetSprint;
                    SprintRequest::createPending(
                        SprintRequest::TYPE_ASSIGN,
                        (int)$this->getID(),
                        $targetSprint,
                        0.0,
                        ''
                    );
                    Session::addMessageAfterRedirect(
                        __('Assignment sent to the Scrum Master for approval. The item stays on the backlog until then.', 'sprint'),
                        false,
                        INFO
                    );
                } else {
                    Session::addMessageAfterRedirect(
                        __('Only the Scrum Master of the target sprint can move this item there.', 'sprint'),
                        false,
                        WARNING
                    );
                }
            }
        }

        // Fastlane is what exempts an item from the assign guard, so inside a
        // sprint only its Scrum Master may flip it — otherwise a self-assigned
        // fastlane item could be turned into a regular one after the fact.
        if (array_key_exists('is_fastlane', $input)) {
            $sprintId = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
            if (
                $sprintId > 0
                && (int)$input['is_fastlane'] !== (int)($this->fields['is_fastlane'] ?? 0)
                && !self::currentUserIsScrumMasterOf($sprintId)
            ) {
                unset($input['is_fastlane']);
                Session::addMessageAfterRedirect(
                    __('Only the Scrum Master of this sprint can change the fastlane flag.', 'sprint'),
                    false,
                    WARNING
                );
            }
        }

        // Adhoc: while the item sits on the backlog anyone with edit rights may
        // toggle the flag (it travels into the sprint on assignment); once the
        // item is in a sprint only that sprint's Scrum Master may change it.
        if (array_key_exists('is_adhoc', $input)) {
            $currentAdhoc = (int)($this->fields['is_adhoc'] ?? 0);
            if ((int)$input['is_adhoc'] !== $currentAdhoc) {
                $sprintId = (int)($input['plugin_sprint_sprints_id'] ?? $this->fields['plugin_sprint_sprints_id'] ?? 0);
                if ($sprintId > 0 && !self::currentUserIsScrumMasterOf($sprintId)) {
                    unset($input['is_adhoc']);
                }
            }
        }

        // When the guard is on, only the Scrum Master may edit capacity on
        // regular items inside a sprint. A change by anyone else becomes a
        // pending SprintRequest for the Scrum Master instead of being applied;
        // the rest of the update still goes through.
        $isFastlane = (int)($this->fields['is_fastlane'] ?? 0) === 1;
        if (
            !$isFastlane
            && array_key_exists('capacity', $input)
            && Config::isScrumMasterOnlyCapacity()
        ) {
            $sprintId = (int)($this->fields['plugin_sprint_sprints_id'] ?? $input['plugin_sprint_sprints_id'] ?? 0);
            if ($sprintId > 0 && !self::currentUserIsScrumMasterOf($sprintId)) {
                $requestedCap = SprintMember::normalizeCapacity($input['capacity']);
                if ($requestedCap != (float)($this->fields['capacity'] ?? 0)) {
                    SprintRequest::createPending(
                        SprintRequest::TYPE_CAPACITY,
                        (int)$this->getID(),
                        $sprintId,
                        $requestedCap,
                        trim((string)($input['_capacity_request_reason'] ?? ''))
                    );
                    Session::addMessageAfterRedirect(
                        sprintf(
                            __('Capacity change to %s%% sent to the Scrum Master for approval.', 'sprint'),
                            SprintMember::formatCapacity($requestedCap)
                        ),
                        false,
                        INFO
                    );
                }
                unset($input['capacity']);
            }
        }

        // Category drives the per-category capacity limits and every
        // category figure on the dashboard, so inside a sprint it is a Scrum
        // Master decision. A change by anyone else becomes a pending
        // SprintRequest instead of being applied; on the backlog the category
        // stays freely editable by everyone with edit rights.
        if (array_key_exists('plugin_sprint_sprintcategories_id', $input)) {
            $sprintId    = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
            $currentCat  = (int)($this->fields['plugin_sprint_sprintcategories_id'] ?? 0);
            $requestedCat = (int)$input['plugin_sprint_sprintcategories_id'];
            if (
                $sprintId > 0
                && $requestedCat !== $currentCat
                && !self::currentUserIsScrumMasterOf($sprintId)
            ) {
                SprintRequest::createPending(
                    SprintRequest::TYPE_CATEGORY,
                    (int)$this->getID(),
                    $sprintId,
                    0.0,
                    trim((string)($input['_category_request_reason'] ?? '')),
                    $requestedCat
                );
                Session::addMessageAfterRedirect(
                    sprintf(
                        __('Category change to %s sent to the Scrum Master for approval.', 'sprint'),
                        $requestedCat > 0
                            ? SprintCategory::getFullNameFor($requestedCat)
                            : __('No category', 'sprint')
                    ),
                    false,
                    INFO
                );
                unset($input['plugin_sprint_sprintcategories_id']);
            }
        }

        // Backlog → sprint: optionally seed story points from the estimated
        // capacity (1% = 1 SP). Only when the plugin setting is on and the item
        // still carries the default estimate (≤1), so an explicitly estimated
        // item never gets clobbered.
        $wasBacklog  = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0) === 0;
        $newSprintId = (int)($input['plugin_sprint_sprints_id'] ?? 0);
        if (
            $wasBacklog
            && $newSprintId > 0
            && !array_key_exists('story_points', $input)
            && (int)($this->fields['story_points'] ?? 0) <= 1
            && Config::isBacklogCapacityToStoryPoints()
        ) {
            // Story points stay whole: 0.5% rounds up to 1 SP.
            $estimatedCap = (float)($input['capacity'] ?? $this->fields['capacity'] ?? 0);
            if ($estimatedCap > 0) {
                $input['story_points'] = (int)ceil($estimatedCap);
            }
        }

        if ($wasBacklog && $newSprintId > 0) {
            $target = new Sprint();
            if ($target->getFromDB($newSprintId)) {
                $preview = array_merge($this->fields, $input);
                $ready = SprintAgility::readiness($preview);
                if (!$ready['ready']) {
                    Session::addMessageAfterRedirect(
                        sprintf(__('Item assigned with readiness warnings: %s', 'sprint'), implode(', ', $ready['missing'])),
                        false,
                        WARNING
                    );
                }
            }
        }

        // Updates often carry only the changed field, so fill itemtype/items_id
        // from the persisted row — otherwise validateNoDuplicateLink can't catch
        // a duplicate move into an already-linked sprint.
        $validationInput = $input;
        if (!isset($validationInput['itemtype'])) {
            $validationInput['itemtype'] = (string)($this->fields['itemtype'] ?? '');
        }
        if (!isset($validationInput['items_id'])) {
            $validationInput['items_id'] = (int)($this->fields['items_id'] ?? 0);
        }
        if (!isset($validationInput['plugin_sprint_sprints_id'])) {
            $validationInput['plugin_sprint_sprints_id'] = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
        }

        if (!$this->validateCapacity($input, (int)($input['id'] ?? 0))) {
            return false;
        }
        if (!self::validateNoDuplicateLink($validationInput, (int)($input['id'] ?? 0))) {
            return false;
        }
        return parent::prepareInputForUpdate($input);
    }

    public static function applyAutomatedStatus(int $itemId, string $status): bool
    {
        $item = new self();
        if ($itemId <= 0 || !$item->getFromDB($itemId)) return false;
        self::$linkedStatusAutomation = true;
        try {
            return (bool)$item->update([
                'id' => $itemId,
                'status' => $status,
                'is_blocked' => $status === self::STATUS_DONE ? 0 : (int)($item->fields['is_blocked'] ?? 0),
            ]);
        } finally {
            self::$linkedStatusAutomation = false;
        }
    }

    /**
     * Drop backlog rows pointing at the same linked GLPI item: a SprintItem in
     * a real sprint and a backlog row for the same item must not coexist.
     * No-op for manual items (no stable identity).
     *
     * @return int Number of backlog rows removed.
     */
    public static function removeBacklogDuplicatesForLinkedItem(string $itemtype, int $itemsId): int
    {
        if ($itemtype === '' || $itemsId <= 0) {
            return 0;
        }

        $si      = new self();
        $rows    = $si->find([
            'plugin_sprint_sprints_id' => 0,
            'itemtype'                 => $itemtype,
            'items_id'                 => $itemsId,
        ]);
        $removed = 0;
        foreach ($rows as $row) {
            if ($si->delete(['id' => (int)$row['id']], 1)) {
                $removed++;
            }
        }
        return $removed;
    }

    public function post_addItem()
    {
        $sprintId = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
        $itemtype = (string)($this->fields['itemtype'] ?? '');
        $itemsId  = (int)($this->fields['items_id'] ?? 0);
        if ($sprintId > 0) {
            self::removeBacklogDuplicatesForLinkedItem($itemtype, $itemsId);
        } else {
            Backlog::logFlow($this->fields, 'in');
        }
        if (array_key_exists('_tags', $this->input)) {
            self::setTagsForItem((int)$this->getID(), (array)$this->input['_tags']);
        }
        $this->seedFastlaneOwnerAsMember();
        parent::post_addItem();
    }

    public function post_updateItem($history = true)
    {
        $sprintId = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
        $itemtype = (string)($this->fields['itemtype'] ?? '');
        $itemsId  = (int)($this->fields['items_id'] ?? 0);
        if ($sprintId > 0) {
            self::removeBacklogDuplicatesForLinkedItem($itemtype, $itemsId);
        }
        if (array_key_exists('_tags', $this->input)) {
            self::setTagsForItem((int)$this->getID(), (array)$this->input['_tags']);
        }
        // Only on sprint-assign or fastlane-flag transitions, so a deliberately
        // emptied member list isn't refilled on unrelated edits.
        if (
            array_key_exists('plugin_sprint_sprints_id', $this->oldvalues ?? [])
            || array_key_exists('is_fastlane', $this->oldvalues ?? [])
        ) {
            $this->seedFastlaneOwnerAsMember();
        }
        // A backlog item that just entered a sprint resolves any pending
        // assignment request for it, whichever path assigned it.
        if (
            array_key_exists('plugin_sprint_sprints_id', $this->oldvalues ?? [])
            && (int)$this->oldvalues['plugin_sprint_sprints_id'] === 0
            && $sprintId > 0
        ) {
            SprintRequest::closePendingAssignForItem((int)$this->getID(), $sprintId);
        }
        // Backlog flow events: 0→sprint = out, sprint→0 = back in.
        if (array_key_exists('plugin_sprint_sprints_id', $this->oldvalues ?? [])) {
            $oldSprint = (int)$this->oldvalues['plugin_sprint_sprints_id'];
            if ($oldSprint === 0 && $sprintId > 0) {
                Backlog::logFlow($this->fields, 'out');
            } elseif ($oldSprint > 0 && $sprintId === 0) {
                Backlog::logFlow($this->fields, 'in');
            }
        }
        if (
            array_key_exists('status', $this->oldvalues ?? [])
            && (string)($this->fields['status'] ?? '') === self::STATUS_BLOCKED
        ) {
            $sprint = new Sprint();
            if ($sprintId > 0 && $sprint->getFromDB($sprintId)) {
                SprintAgility::signal(
                    $sprintId,
                    (int)($sprint->fields['users_id'] ?? 0),
                    'blocked',
                    sprintf(__('“%s” became blocked.', 'sprint'), (string)$this->fields['name']),
                    self::getFormURLWithID((int)$this->getID())
                );
            }
        }
        parent::post_updateItem($history);
    }

    /**
     * A fastlane item in a sprint distributes capacity via fastlane members;
     * without any it books nothing. Seed the owner as first member (with the
     * item's estimated capacity) so a backlog assign never lands memberless.
     */
    private function seedFastlaneOwnerAsMember(): void
    {
        $sprintId = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
        $ownerId  = (int)($this->fields['users_id'] ?? 0);
        if ($sprintId <= 0 || $ownerId <= 0 || (int)($this->fields['is_fastlane'] ?? 0) !== 1) {
            return;
        }
        $existing = countElementsInTable(
            SprintFastlaneMember::getTable(),
            ['plugin_sprint_sprintitems_id' => (int)$this->getID()]
        );
        if ($existing > 0) {
            return;
        }
        (new SprintFastlaneMember())->add([
            'plugin_sprint_sprintitems_id' => (int)$this->getID(),
            'users_id'                     => $ownerId,
            'capacity'                     => (float)($this->fields['capacity'] ?? 0),
        ]);
    }

    /**
     * Tags assigned to an item, in admin-defined order.
     *
     * @return string[]
     */
    public static function getTagsForItem(int $itemId): array
    {
        global $DB;
        if ($itemId <= 0 || !$DB->tableExists('glpi_plugin_sprint_sprintitemtags')) {
            return [];
        }
        $rows = $DB->request([
            'SELECT' => 'tag',
            'FROM'   => 'glpi_plugin_sprint_sprintitemtags',
            'WHERE'  => ['plugin_sprint_sprintitems_id' => $itemId],
        ]);
        $stored = [];
        foreach ($rows as $r) {
            $stored[mb_strtolower((string)$r['tag'])] = (string)$r['tag'];
        }
        $out = [];
        foreach (Config::getDefinedTags() as $tag) {
            $key = mb_strtolower($tag);
            if (isset($stored[$key])) {
                $out[] = $stored[$key];
                unset($stored[$key]);
            }
        }
        foreach ($stored as $orphan) {
            $out[] = $orphan;
        }
        return $out;
    }

    /**
     * Bulk-fetch tags grouped by sprint-item id, ordered by the admin pool.
     *
     * @param int[] $itemIds
     * @return array<int, string[]>
     */
    public static function getTagsForItems(array $itemIds): array
    {
        global $DB;
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if (empty($itemIds) || !$DB->tableExists('glpi_plugin_sprint_sprintitemtags')) {
            return [];
        }
        $rows = $DB->request([
            'SELECT' => ['plugin_sprint_sprintitems_id', 'tag'],
            'FROM'   => 'glpi_plugin_sprint_sprintitemtags',
            'WHERE'  => ['plugin_sprint_sprintitems_id' => $itemIds],
        ]);
        $grouped = [];
        foreach ($rows as $r) {
            $grouped[(int)$r['plugin_sprint_sprintitems_id']][mb_strtolower((string)$r['tag'])] = (string)$r['tag'];
        }
        $order = Config::getDefinedTags();
        $out   = [];
        foreach ($itemIds as $id) {
            $stored = $grouped[$id] ?? [];
            $list   = [];
            foreach ($order as $tag) {
                $key = mb_strtolower($tag);
                if (isset($stored[$key])) {
                    $list[] = $stored[$key];
                    unset($stored[$key]);
                }
            }
            foreach ($stored as $orphan) {
                $list[] = $orphan;
            }
            $out[$id] = $list;
        }
        return $out;
    }

    /**
     * Replace an item's tags with the intersection of input and the admin
     * pool — tags outside the pool are dropped, so a stale form submission
     * can't smuggle in unknown labels.
     */
    public static function setTagsForItem(int $itemId, array $tags): void
    {
        global $DB;
        if ($itemId <= 0 || !$DB->tableExists('glpi_plugin_sprint_sprintitemtags')) {
            return;
        }
        $allowed = [];
        foreach (Config::getDefinedTags() as $tag) {
            $allowed[mb_strtolower($tag)] = $tag;
        }
        $kept = [];
        foreach ($tags as $t) {
            $key = mb_strtolower(trim((string)$t));
            if ($key !== '' && isset($allowed[$key])) {
                $kept[$key] = $allowed[$key];
            }
        }
        $DB->delete('glpi_plugin_sprint_sprintitemtags', [
            'plugin_sprint_sprintitems_id' => $itemId,
        ]);
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        foreach ($kept as $tag) {
            $DB->insert('glpi_plugin_sprint_sprintitemtags', [
                'plugin_sprint_sprintitems_id' => $itemId,
                'tag'                          => $tag,
                'date_creation'                => $now,
            ]);
        }
    }

    /**
     * Reject a second row for the same (sprint, itemtype, items_id) triple.
     * Manual items and backlog rows (sprint id 0) are skipped — backlog has its
     * own de-duplication and manual items may legitimately repeat.
     */
    private static function validateNoDuplicateLink(array $input, int $excludeId = 0): bool
    {
        $itemtype = (string)($input['itemtype'] ?? '');
        $itemsId  = (int)($input['items_id'] ?? 0);
        $sprintId = (int)($input['plugin_sprint_sprints_id'] ?? 0);

        if ($itemtype === '' || $itemsId <= 0 || $sprintId <= 0) {
            return true;
        }

        if (!self::isLinkedItemInSprint($sprintId, $itemtype, $itemsId, $excludeId)) {
            return true;
        }

        $typeLabels = self::getLinkedItemTypes();
        $typeLabel  = $typeLabels[$itemtype] ?? $itemtype;
        Session::addMessageAfterRedirect(
            sprintf(
                __('This %1$s (#%2$d) is already linked to this sprint.', 'sprint'),
                $typeLabel,
                $itemsId
            ),
            false,
            ERROR
        );
        return false;
    }

    /**
     * Carry an item over to another sprint: create a fresh copy in the target
     * while leaving the source intact, so unfinished items stay in the current
     * sprint's review and continue in the next as a new planning entry.
     */
    public static function carryOverTo(int $sourceItemId, int $targetSprintId): int
    {
        if ($sourceItemId <= 0 || $targetSprintId <= 0) {
            return 0;
        }

        $source = new self();
        if (!$source->getFromDB($sourceItemId)) {
            return 0;
        }

        $sourceSprintId = (int)($source->fields['plugin_sprint_sprints_id'] ?? 0);
        if ($sourceSprintId === $targetSprintId) {
            return $sourceItemId;
        }

        $sprint = new Sprint();
        if (!$sprint->getFromDB($targetSprintId)) {
            return 0;
        }

        $itemtype = (string)($source->fields['itemtype'] ?? '');
        $itemsId  = (int)($source->fields['items_id'] ?? 0);

        // Reuse an existing row if the same GLPI item is already linked in the
        // target — manual items have no stable identity.
        if ($itemtype !== '' && $itemsId > 0) {
            $existing = (new self())->find([
                'plugin_sprint_sprints_id' => $targetSprintId,
                'itemtype'                 => $itemtype,
                'items_id'                 => $itemsId,
            ]);
            if (count($existing) > 0) {
                $first = reset($existing);
                return (int)$first['id'];
            }
        }

        $copy = new self();
        return self::withoutAssignGuard(static function () use ($copy, $targetSprintId, $source, $itemtype, $itemsId) {
            return (int)$copy->add([
                'plugin_sprint_sprints_id' => $targetSprintId,
                'name'                     => (string)($source->fields['name'] ?? ''),
                'description'              => (string)($source->fields['description'] ?? ''),
                'itemtype'                 => $itemtype,
                'items_id'                 => $itemsId,
                'status'                   => self::STATUS_TODO,
                'priority'                 => (int)($source->fields['priority'] ?? 3),
                'story_points'             => (int)($source->fields['story_points'] ?? 0),
                // Classification travels with the work — execution state resets.
                'plugin_sprint_sprintcategories_id' => (int)($source->fields['plugin_sprint_sprintcategories_id'] ?? 0),
                'plugin_sprint_sprintepics_id'      => (int)($source->fields['plugin_sprint_sprintepics_id'] ?? 0),
                'users_id'                 => 0,
                'capacity'                 => 0,
                'is_fastlane'              => (int)($source->fields['is_fastlane'] ?? 0),
                'is_blocked'               => 0,
                'note'                     => '',
            ]);
        });
    }

    /** Server-side write that may place items in a sprint without the assign guard. */
    public static function withoutAssignGuard(callable $callback)
    {
        self::$skipAssignGuard = true;
        try {
            return $callback();
        } finally {
            self::$skipAssignGuard = false;
        }
    }

    public static function isLinkedItemInSprint(
        int $sprintId,
        string $itemtype,
        int $itemsId,
        int $excludeId = 0
    ): bool {
        if ($sprintId <= 0 || $itemtype === '' || $itemsId <= 0) {
            return false;
        }

        $criteria = [
            'plugin_sprint_sprints_id' => $sprintId,
            'itemtype'                 => $itemtype,
            'items_id'                 => $itemsId,
        ];
        if ($excludeId > 0) {
            $criteria['NOT'] = ['id' => $excludeId];
        }

        return countElementsInTable(self::getTable(), $criteria) > 0;
    }

    /** Validate itemtype against the allowlist and cast numeric fields. */
    private static function sanitizeInput(array $input): array
    {
        $allowedTypes = array_keys(self::getLinkedItemTypes());
        if (isset($input['itemtype']) && !in_array($input['itemtype'], $allowedTypes, true)) {
            $input['itemtype'] = '';
            $input['items_id'] = 0;
        }

        if (isset($input['items_id']))    $input['items_id']    = (int)$input['items_id'];
        if (isset($input['users_id']))    $input['users_id']    = (int)$input['users_id'];
        if (isset($input['story_points'])) $input['story_points'] = max(0, (int)$input['story_points']);
        if (isset($input['capacity']))    $input['capacity']    = SprintMember::normalizeCapacity($input['capacity']);
        // '' / -1 = no actual figure recorded: the item follows its planned capacity.
        if (array_key_exists('capacity_actual', $input)) {
            $raw = $input['capacity_actual'];
            $input['capacity_actual'] = ($raw === '' || $raw === null || (float)$raw < 0)
                ? 'NULL'
                : SprintMember::normalizeCapacity($raw);
        }
        if (isset($input['priority']))    $input['priority']    = max(1, min(5, (int)$input['priority']));
        if (isset($input['plugin_sprint_sprints_id'])) $input['plugin_sprint_sprints_id'] = (int)$input['plugin_sprint_sprints_id'];
        if (isset($input['is_fastlane']))  $input['is_fastlane'] = (int)(bool)$input['is_fastlane'];
        if (isset($input['is_blocked']))   $input['is_blocked']  = (int)(bool)$input['is_blocked'];
        if (isset($input['is_adhoc']))     $input['is_adhoc']    = (int)(bool)$input['is_adhoc'];

        return $input;
    }

    /**
     * Validate owner capacity. Defers to SprintMember::checkCapacityForUser so
     * regular items and fastlane allocations are counted together.
     *
     * @param array $input    The input data
     * @param int   $excludeId  Item ID to exclude from calculation (for updates)
     * @return bool
     */
    private function validateCapacity(array $input, int $excludeId = 0): bool
    {
        // Fastlane items distribute capacity via SprintFastlaneMember, so the
        // per-row capacity field is irrelevant for them.
        $isFastlane = (int)($input['is_fastlane'] ?? $this->fields['is_fastlane'] ?? 0) === 1;
        if ($isFastlane) {
            return true;
        }

        $capacity = (float)($input['capacity'] ?? 0);
        $userId   = (int)($input['users_id'] ?? 0);
        $sprintId = (int)($input['plugin_sprint_sprints_id'] ?? $this->fields['plugin_sprint_sprints_id'] ?? 0);

        // Overflow only warns, never hard-blocks: a member can be pushed past
        // 100% and the AJAX modal confirms first via SprintMember::overflowInfo().
        // Keeping it non-blocking preserves the no-JS form path.
        return SprintMember::checkCapacityForUser(
            $sprintId,
            $userId,
            $capacity,
            $excludeId,
            0,
            0,
            true
        );
    }

    /**
     * Linked sprint items always mirror the underlying item's name so the UI
     * never shows diverging duplicates; only manual items keep an editable
     * name. Complemented by the item_update hook that pushes GLPI-side
     * renames back onto linked sprint items.
     */
    private function enforceLinkedItemName(array $input): array
    {
        $itemtype = (string)($input['itemtype'] ?? $this->fields['itemtype'] ?? '');
        $itemsId  = (int)($input['items_id'] ?? $this->fields['items_id'] ?? 0);
        if ($itemtype === '' || $itemsId <= 0 || !class_exists($itemtype)) {
            return $input;
        }
        $linked = new $itemtype();
        if ($linked->getFromDB($itemsId)) {
            $linkedName = (string)($linked->fields['name'] ?? '');
            if ($linkedName !== '') {
                $input['name'] = $linkedName;
            }
        }
        return $input;
    }

    /**
     * item_update hook (Ticket/Change/Problem/ProjectTask): a rename of the
     * underlying item propagates to every sprint item linked to it, so names
     * never drift apart. Direct DB update: a mirror sync should not re-run
     * item validation or spam item history.
     */
    public static function onLinkedItemUpdate($item): void
    {
        global $DB;

        $allowed = ['Ticket', 'Change', 'Problem', 'ProjectTask'];
        if (!in_array($item->getType(), $allowed, true)) {
            return;
        }
        if (in_array('name', (array)($item->updates ?? []), true)) {
            $newName = (string)($item->fields['name'] ?? '');
            if ($newName !== '') {
                $DB->update(self::getTable(), ['name' => $newName], [
                    'itemtype' => $item->getType(),
                    'items_id' => (int)$item->getID(),
                ]);
            }
        }
        SprintAgility::syncLinkedStatus($item);
        Backlog::autoCleanupForItem($item);
    }

    // GLPI cron task — nightly sweep that purges backlog rows whose linked
    // item was solved outside the item_update hook (rules, imports, CLI).

    public static function cronInfo(string $name): array
    {
        return [
            'description' => __('Remove backlog items whose linked item is solved or closed', 'sprint'),
        ];
    }

    public static function cronBacklogCleanup(\CronTask $task): int
    {
        $purged = Backlog::purgeSolvedRows();
        $task->addVolume($purged);
        return $purged > 0 ? 1 : 0;
    }

    /**
     * When a linked item type is selected, set items_id and auto-fill the name
     * from the linked item.
     */
    private function resolveLinkedItem(array $input): array
    {
        $itemtype = $input['itemtype'] ?? '';

        if ($itemtype === 'Ticket' && !empty($input['_linked_ticket'])) {
            $input['items_id'] = (int)$input['_linked_ticket'];
            $linked = new Ticket();
            if ($linked->getFromDB($input['items_id']) && empty($input['name'])) {
                $input['name'] = $linked->fields['name'];
            }
        } elseif ($itemtype === 'Change' && !empty($input['_linked_change'])) {
            $input['items_id'] = (int)$input['_linked_change'];
            $linked = new Change();
            if ($linked->getFromDB($input['items_id']) && empty($input['name'])) {
                $input['name'] = $linked->fields['name'];
            }
        } elseif ($itemtype === 'Problem' && !empty($input['_linked_problem'])) {
            $input['items_id'] = (int)$input['_linked_problem'];
            $linked = new \Problem();
            if ($linked->getFromDB($input['items_id']) && empty($input['name'])) {
                $input['name'] = $linked->fields['name'];
            }
        } elseif ($itemtype === 'ProjectTask' && !empty($input['_linked_projecttask'])) {
            $input['items_id'] = (int)$input['_linked_projecttask'];
            $linked = new ProjectTask();
            if ($linked->getFromDB($input['items_id']) && empty($input['name'])) {
                $input['name'] = $linked->fields['name'];
            }
        }

        return $input;
    }

    /** Render the item edit form. */
    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $sprintId = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
        $memberOptions = SprintMember::getSprintMemberOptions($sprintId);
        $isNew = !$this->getID() || $this->isNewItem();

        // Approval-queue lock notice: edits by non-Scrum-Masters are rejected
        // server-side in prepareInputForUpdate() while a request is pending.
        if (!$isNew) {
            $pendingReq = SprintRequest::getAnyPendingForItem((int)$this->getID());
            if ($pendingReq !== null) {
                $reqSprintId = (int)($pendingReq['plugin_sprint_sprints_id'] ?? 0);
                if ($reqSprintId > 0 && !self::currentUserIsScrumMasterOf($reqSprintId)) {
                    echo "<div class='alert alert-warning d-flex align-items-center' style='margin-bottom:10px;'>"
                        . "<i class='fas fa-lock me-2'></i>"
                        . htmlescape(__('This item is locked while a request is pending Scrum Master approval.', 'sprint'))
                        . "</div>";
                }
            }
        }
        $isFastlane = (int)($this->fields['is_fastlane'] ?? 0) === 1;
        $isBlocked  = (int)($this->fields['is_blocked'] ?? 0) === 1;

        // "Back to Sprint" button linking to the parent sprint's Items tab
        if ($sprintId > 0) {
            $sprintUrl = Sprint::getFormURLWithID($sprintId) . '&forcetab=' . urlencode('GlpiPlugin\\Sprint\\SprintItem$1');
            echo "<div style='margin-bottom:10px;'>";
            echo "<a href='$sprintUrl' class='btn btn-outline-secondary'>";
            echo "<i class='fas fa-arrow-left me-1'></i> " . __('Back to Sprint', 'sprint');
            echo "</a></div>";
        }

        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Name') . "</td>";
        // Linked items mirror the underlying item's name (server-enforced);
        // only manual items expose an editable name.
        $isLinkedItem = (string)($this->fields['itemtype'] ?? '') !== ''
            && (int)($this->fields['items_id'] ?? 0) > 0;
        $nameOptions = ['value' => $this->fields['name'] ?? '', 'size' => 40];
        if ($isLinkedItem) {
            $nameOptions['readonly'] = 'readonly';
            $nameOptions['title']    = __('The name follows the linked item and cannot be edited here.', 'sprint');
        }
        echo "<td>" . Html::input('name', $nameOptions) . "</td>";
        echo "<td>" . __('Status') . "</td><td>";
        Dropdown::showFromArray('status', self::getAllStatuses(), [
            'value' => $this->fields['status'] ?? self::STATUS_TODO,
        ]);
        echo "</td></tr>";

        // Paired hidden input ensures unchecking submits 0.
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Is Fastlane', 'sprint') . "</td><td colspan='3'>";
        echo "<input type='hidden' name='is_fastlane' value='0'>";
        echo "<label style='display:inline-flex;align-items:center;gap:6px;'>";
        echo "<input type='checkbox' name='is_fastlane' value='1'" . ($isFastlane ? ' checked' : '') . ">";
        echo "<span class='text-muted'>" . __('Mark this item as fastlane (managed via the Fastlane tab on the sprint).', 'sprint') . "</span>";
        echo "</label>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Is Blocked', 'sprint') . "</td><td colspan='3'>";
        echo "<input type='hidden' name='is_blocked' value='0'>";
        echo "<label style='display:inline-flex;align-items:center;gap:6px;'>";
        echo "<input type='checkbox' name='is_blocked' value='1'" . ($isBlocked ? ' checked' : '') . ">";
        echo "<span class='text-muted'>" . __('Mark this item as blocked (surfaces in the dedicated Blocked section on the backlog for Scrum Master review).', 'sprint') . "</span>";
        echo "</label>";
        echo "</td></tr>";

        // Adhoc: only for items in a sprint; toggle is Scrum Master-only.
        if ($sprintId > 0) {
            $isAdhoc = (int)($this->fields['is_adhoc'] ?? 0) === 1;
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Adhoc', 'sprint') . "</td><td colspan='3'>";
            if (self::currentUserIsScrumMasterOf($sprintId)) {
                echo "<input type='hidden' name='is_adhoc' value='0'>";
                echo "<label style='display:inline-flex;align-items:center;gap:6px;'>";
                echo "<input type='checkbox' name='is_adhoc' value='1'" . ($isAdhoc ? ' checked' : '') . ">";
                echo "<span class='text-muted'>" . __('Added to the sprint after kick-off (highlights the item)', 'sprint') . "</span>";
                echo "</label>";
            } else {
                echo $isAdhoc
                    ? self::renderAdhocBadge(true)
                    : "<span class='text-muted'>" . __('No') . "</span>";
                echo " <span class='text-muted small'>"
                    . __('Only the Scrum Master can change this.', 'sprint') . "</span>";
            }
            echo "</td></tr>";
        }

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Linked item type', 'sprint') . "</td><td>";
        $currentType = $this->fields['itemtype'] ?? '';
        Dropdown::showFromArray('itemtype', self::getLinkedItemTypes(), [
            'value' => $currentType,
        ]);
        echo "</td>";
        echo "<td>" . __('Linked item', 'sprint') . "</td><td>";
        if (!empty($currentType) && (int)($this->fields['items_id'] ?? 0) > 0) {
            echo $this->getLinkedItemDisplay();
            echo Html::hidden('items_id', ['value' => $this->fields['items_id']]);
        } else {
            echo "<span style='color:#999;'>" . __('None', 'sprint') . "</span>";
            echo Html::hidden('items_id', ['value' => 0]);
        }
        echo "</td></tr>";

        // Story Points only for non-fastlane items — fastlane points don't
        // count towards sprint velocity.
        echo "<tr class='tab_bg_1'>";
        if ($isFastlane) {
            echo Html::hidden('story_points', ['value' => $this->fields['story_points'] ?? 0]);
            echo "<td>" . __('Priority') . "</td><td colspan='3'>";
            Dropdown::showFromArray('priority', [
                1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
                4 => __('High'), 5 => __('Very high'),
            ], ['value' => $this->fields['priority'] ?? 3]);
            echo "</td>";
        } else {
            echo "<td>" . __('Story Points', 'sprint') . "</td><td>";
            Dropdown::showNumber('story_points', [
                'value' => $this->fields['story_points'] ?? 0, 'min' => 0, 'max' => 100,
            ]);
            echo "</td><td>" . __('Priority') . "</td><td>";
            Dropdown::showFromArray('priority', [
                1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
                4 => __('High'), 5 => __('Very high'),
            ], ['value' => $this->fields['priority'] ?? 3]);
            echo "</td>";
        }
        echo "</tr>";

        // Capacity + Owner are irrelevant for Fastlane items (capacity is split
        // across members via the Fastlane Members tab); hidden inputs preserve
        // existing values.
        if ($isFastlane) {
            echo Html::hidden('capacity', ['value' => $this->fields['capacity'] ?? 0]);
            echo Html::hidden('users_id', ['value' => $this->fields['users_id'] ?? 0]);
            echo "<tr class='tab_bg_1'><td colspan='4'>";
            echo "<div class='alert alert-info' style='margin:6px 0;'>";
            echo "<i class='fas fa-bolt' style='color:#fd7e14;margin-right:6px;'></i>";
            echo __('Fastlane item: assign sprint members and capacity from the Fastlane Members tab.', 'sprint');
            echo "</div></td></tr>";
        } else {
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Capacity (%)', 'sprint') . "</td><td>";
            Dropdown::showFromArray('capacity', SprintMember::getCapacityChoices(), [
                'value' => SprintMember::capacityKey($this->fields['capacity'] ?? 0),
            ]);
            if (Config::isPlannedActualEnabled()) {
                $actualRaw = $this->fields['capacity_actual'] ?? null;
                echo " <span class='text-muted small ms-2' title='"
                    . __s('Actual capacity: what the item really took, filled in as the work is done. Empty = follows the planned figure.', 'sprint')
                    . "'>" . __('Actual', 'sprint') . "</span> ";
                Dropdown::showFromArray('capacity_actual', ['' => __('Follows planned', 'sprint')] + SprintMember::getCapacityChoices(), [
                    'value' => self::formatActualCapacity($actualRaw) === '' ? '' : SprintMember::capacityKey($actualRaw),
                ]);
            }
            echo "</td><td>" . __('Owner', 'sprint') . "</td><td>";
            Dropdown::showFromArray('users_id', $memberOptions, [
                'value' => $this->fields['users_id'] ?? 0,
            ]);
            echo "</td></tr>";
        }

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Sprint') . "</td><td>";
        Sprint::dropdown(['name' => 'plugin_sprint_sprints_id', 'value' => $sprintId]);
        if (!$isFastlane) {
            echo "<div class='text-muted small'><i class='fas fa-info-circle me-1'></i>"
                . htmlescape(__('Picking a sprint you are not Scrum Master of creates an approval request.', 'sprint'))
                . "</div>";
        }
        echo "</td><td colspan='2'></td></tr>";

        // Category. On the backlog anyone with edit rights sets it; inside a
        // sprint it is Scrum Master territory and a change by anyone else
        // becomes an approval request (enforced in prepareInputForUpdate()).
        $categoryOptions = SprintCategory::getAll();
        if (!empty($categoryOptions)) {
            $currentCategory = (int)($this->fields['plugin_sprint_sprintcategories_id'] ?? 0);
            echo "<tr class='tab_bg_1'>";
            echo "<td><i class='fas fa-folder me-1'></i>" . __('Category', 'sprint') . "</td><td colspan='3'>";
            echo "<select name='plugin_sprint_sprintcategories_id' class='form-select' style='max-width:340px;display:inline-block;'>";
            echo "<option value='0'>" . __('No category', 'sprint') . "</option>";
            echo SprintCategory::dropdownOptionsPreserving($currentCategory);
            echo "</select>";
            if ($sprintId > 0 && !self::currentUserIsScrumMasterOf($sprintId)) {
                echo "<div class='text-muted small'><i class='fas fa-user-shield me-1'></i>"
                    . htmlescape(__('Category changes are sent to the Scrum Master for approval.', 'sprint'))
                    . "</div>";
            }
            echo "</td></tr>";
        }

        echo "<tr class='tab_bg_1'><td>" . __('Description') . "</td>";
        echo "<td colspan='3'><textarea name='description' class='form-control' rows='6' cols='80'>" .
            htmlescape($this->fields['description'] ?? '') . "</textarea></td></tr>";

        $definedTags = Config::getDefinedTags();
        if (!empty($definedTags)) {
            $assigned = $this->getID() > 0 ? self::getTagsForItem((int)$this->getID()) : [];
            $assignedKeys = array_flip(array_map('mb_strtolower', $assigned));
            echo "<tr class='tab_bg_1'><td>" . __('Tags', 'sprint') . "</td>";
            echo "<td colspan='3'>";
            echo "<input type='hidden' name='_tags' value=''>";
            echo "<div class='d-flex flex-wrap gap-3'>";
            foreach ($definedTags as $tag) {
                $checked = isset($assignedKeys[mb_strtolower($tag)]) ? ' checked' : '';
                echo "<label style='display:inline-flex;align-items:center;gap:6px;'>";
                echo "<input type='checkbox' name='_tags[]' value='" . htmlescape($tag) . "'{$checked}>";
                echo "<span>" . htmlescape($tag) . "</span>";
                echo "</label>";
            }
            echo "</div>";
            echo "</td></tr>";
        }

        $dod = Config::getDefinitionDone();
        if (!empty($dod)) {
            $checkedDod = array_flip(SprintAgility::checklist((string)($this->fields['done_checks'] ?? '')));
            echo "<tr class='tab_bg_1'><td><i class='fas fa-list-check me-1'></i>" . __('Definition of Done', 'sprint') . "</td>";
            echo "<td colspan='3'>";
            echo "<input type='hidden' name='_dod' value=''>";
            foreach ($dod as $check) {
                $isChecked = isset($checkedDod[$check]) ? ' checked' : '';
                echo "<label class='form-check d-block'>";
                echo "<input type='checkbox' class='form-check-input' name='_dod[]' value='" . htmlescape($check) . "'{$isChecked}>";
                echo "<span class='form-check-label'>" . htmlescape($check) . "</span>";
                echo "</label>";
            }
            echo "</td></tr>";
        }

        $this->showFormButtons($options);

        return true;
    }
}
