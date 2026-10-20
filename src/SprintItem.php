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
    /** @var bool guard bypass for server-side writes touching customer/credits */
    private static bool $skipCreditGuard = false;
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

    /**
     * Entity scoping for sprint items. The items table has no entities_id, so
     * CommonDBTM's automatic entity check never engages; the parent sprint's
     * entity is authoritative instead. Backlog items (no sprint) are not
     * entity-scoped, and neither is a row whose sprint no longer exists.
     */
    public static function sprintEntityAccessible(int $sprintId): bool
    {
        if ($sprintId <= 0) {
            return true;
        }
        $sprint = SprintCache::getObject(Sprint::class, $sprintId);
        if ($sprint === null) {
            return true;
        }
        return Session::haveAccessToEntity(
            (int)($sprint->fields['entities_id'] ?? 0),
            (bool)($sprint->fields['is_recursive'] ?? false)
        );
    }

    /** Whether the current user may reach this item's sprint entity. */
    public function hasEntityAccess(): bool
    {
        return self::sprintEntityAccessible((int)($this->fields['plugin_sprint_sprints_id'] ?? 0));
    }

    public function canViewItem(): bool
    {
        return $this->hasEntityAccess() && parent::canViewItem();
    }

    public function canCreateItem(): bool
    {
        if (!$this->hasEntityAccess()) {
            return false;
        }
        // OWN_ITEMS right lets users create (assigned to themselves).
        if (self::hasOnlyOwnRight(CREATE)) {
            return true;
        }
        return parent::canCreateItem();
    }

    public function canUpdateItem(): bool
    {
        if (!$this->hasEntityAccess()) {
            return false;
        }
        if (self::hasOnlyOwnRight(UPDATE)) {
            return $this->isOwnItem();
        }
        return parent::canUpdateItem();
    }

    public function canDeleteItem(): bool
    {
        if (!$this->hasEntityAccess()) {
            return false;
        }
        if (self::hasOnlyOwnRight(DELETE)) {
            return $this->isOwnItem();
        }
        return parent::canDeleteItem();
    }

    public function canPurgeItem(): bool
    {
        if (!$this->hasEntityAccess()) {
            return false;
        }
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
        if (SprintCustomer::canUseCredits()) {
            $tab[] = [
                'id'        => 12,
                'table'     => SprintCustomer::getTable(),
                'field'     => 'name',
                'linkfield' => 'plugin_sprint_sprintcustomers_id',
                'name'      => SprintCustomer::getTypeName(1),
                'datatype'  => 'dropdown',
            ];
            $tab[] = [
                'id'       => 14,
                'table'    => $this->getTable(),
                'field'    => 'credits',
                'name'     => __('Credits', 'sprint'),
                // DECIMAL(10,2) column; 'integer' would strip the decimal point.
                'datatype' => 'decimal',
            ];
            $tab[] = [
                'id'        => 15,
                'table'     => SprintCreditProduct::getTable(),
                'field'     => 'name',
                'linkfield' => 'plugin_sprint_sprintcreditproducts_id',
                'name'      => SprintCreditProduct::getTypeName(1),
                'datatype'  => 'dropdown',
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

    /** Customer picker; disabled for a session without the item READ right (see SprintCustomer::canUseCredits()). */
    public static function customerSelect(string $name, int $selected, string $extraClass = ''): string
    {
        $disabled = SprintCustomer::canUseCredits() ? '' : ' disabled';
        $class    = trim('form-select ' . $extraClass);
        return "<select name='" . htmlescape($name) . "' class='" . htmlescape($class) . "'{$disabled}>"
            . "<option value='0'>-----</option>"
            . SprintCustomer::dropdownOptionsPreserving($selected)
            . "</select>";
    }

    /** Credit amount input; read-only for a session without the item READ right (see SprintCustomer::canUseCredits()). */
    public static function creditsInput(string $name, $value, string $extraClass = ''): string
    {
        $readonly = SprintCustomer::canUseCredits() ? '' : ' readonly';
        $class    = trim('form-control ' . $extraClass);
        return "<input type='number' min='0' step='0.25' name='" . htmlescape($name) . "' "
            . "class='" . htmlescape($class) . "' style='max-width:140px;' value='"
            . htmlescape(SprintCustomer::formatCredits($value)) . "'{$readonly}>";
    }

    /**
     * Catalogue picker: choosing a product fills and locks the credits input
     * named $creditsInputName in the same form or modal (see js/sprint.js);
     * a different amount needs the "Manual credits" option.
     */
    public static function productSelect(string $name, int $selected, string $creditsInputName, string $extraClass = ''): string
    {
        $disabled = SprintCustomer::canUseCredits() ? '' : ' disabled';
        $class    = trim('form-select sprint-credit-product ' . $extraClass);
        return "<select name='" . htmlescape($name) . "' class='" . htmlescape($class) . "' "
            . "data-credits-for='" . htmlescape($creditsInputName) . "'{$disabled}>"
            . "<option value='0' data-credits=''>" . htmlescape(__('Manual credits', 'sprint')) . "</option>"
            . SprintCreditProduct::dropdownOptions($selected)
            . "</select>";
    }

    /**
     * Re-syncs every productSelect() just rendered; the behaviour itself is
     * in js/sprint.js. Needed for markup that arrives over AJAX (tabs), where
     * no DOMContentLoaded follows.
     */
    public static function creditProductScript(): void
    {
        echo "<script>if (window.sprintCreditProductSync) { window.sprintCreditProductSync(); }</script>";
    }

    /** Customer pill + credit amount for a list cell; '' when neither is set. */
    public static function renderCreditCell(int $customerId, $credits, int $productId = 0, float $dependencyCredits = 0.0): string
    {
        $credits = (float)$credits;
        $out     = $customerId > 0 ? SprintCustomer::renderPill($customerId) : '';
        if ($credits > 0) {
            $product = $productId > 0 ? SprintCreditProduct::getFullNameFor($productId) : '';
            $out .= " <span class='sprint-credit-chip' title='" . htmlescape($product !== '' ? $product : __('Credits', 'sprint')) . "'>"
                . "<i class='fas fa-coins'></i> " . htmlescape(SprintCustomer::formatCredits($credits)) . "</span>";
        }
        // Credits the helpers charge on top of the item's own (see the
        // Dependencies tab); shown apart so the item figure stays what was entered.
        if ($dependencyCredits > 0) {
            $out .= " <span class='sprint-credit-chip' style='opacity:0.8;' title='"
                . htmlescape(__('Credits on dependencies (helpers)', 'sprint')) . "'>"
                . "<i class='fas fa-link'></i> +" . htmlescape(SprintCustomer::formatCredits($dependencyCredits)) . "</span>";
        }
        return $out !== '' ? $out : "<span class='text-muted'>-</span>";
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
            echo "</td>";
            echo "<td>" . __('Priority') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('priority', [
                1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
                4 => __('High'), 5 => __('Very high'),
            ], ['value' => 3]);
            echo "</td>";
            if (SprintCustomer::canUseCredits()) {
                echo "<td>" . SprintCustomer::getTypeName(1) . "</td>";
                echo "<td>";
                echo self::customerSelect('plugin_sprint_sprintcustomers_id', 0);
                echo "</td></tr>";

                echo "<tr class='tab_bg_1'>";
                echo "<td>" . __('Credits', 'sprint') . "</td>";
                echo "<td><div class='d-flex flex-wrap gap-2 align-items-center'>"
                    . self::productSelect('plugin_sprint_sprintcreditproducts_id', 0, 'credits', 'w-auto')
                    . self::creditsInput('credits', 0) . "</div></td>";
                self::creditProductScript();
                echo "<td colspan='4'>";
            } else {
                echo "<td colspan='2'>";
            }
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
        $depCreditsById = SprintCustomer::canUseCredits() ? SprintItemDependency::getCreditsByItem($itemIds) : [];
        $depsById = SprintItemDependency::getOpenSummariesForItems($itemIds);
        // Items with a change waiting for the Scrum Master say so in the list.
        $pendingById = SprintRequest::pendingBadgesForItems($itemIds);

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
        $showCredits = SprintCustomer::canUseCredits();
        if ($showCredits) {
            echo "<th>" . __('Customer / credits', 'sprint') . "</th>";
        }
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($items) === 0) {
            // "No items at all" with the next step — distinct from the
            // filtered-empty state the filter bar shows.
            $cols = 8 + ($canedit ? 1 : 0) + ($showCredits ? 1 : 0);
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center' style='padding:24px;'>"
                . "<i class='fas fa-inbox' style='font-size:1.6em;opacity:.6;' aria-hidden='true'></i>"
                . "<div class='fw-bold mt-1'>" . __s('No items in this sprint', 'sprint') . "</div>"
                . "<div class='text-muted sprint-small'>"
                . ($canedit
                    ? __s('Add one with the form above, or plan items from the backlog.', 'sprint')
                    : __s('Items appear here once they are planned into this sprint.', 'sprint'))
                . "</div></td></tr>";
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
                . self::renderLinkedItemOpenBadge($row) . ($pendingById[(int)$row['id']] ?? '') . "</td>";
            echo "<td class='sprint-row-linked'>" . $linkedDisplay . "</td>";
            echo "<td class='sprint-cell-category'>" . self::renderCategoryCell((int)($row['plugin_sprint_sprintcategories_id'] ?? 0)) . "</td>";
            echo "<td class='sprint-cell-status'><span class='sprint-badge {$statusClass}'>" .
                $statusLabel . "</span></td>";
            echo "<td class='sprint-cell-priority'>" . ($priorities[$row['priority']] ?? $row['priority']) . "</td>";
            echo "<td class='center sprint-cell-story-points'>" . (int)$row['story_points'] . "</td>";
            echo "<td class='center sprint-cell-capacity'>" . SprintMember::formatCapacity($row['capacity'] ?? 0) . "%";
            echo "</td>";
            echo "<td class='sprint-cell-owner'>" . (((int)$row['users_id'] > 0) ? htmlescape(SprintCache::userName($row['users_id'])) :
                '<span style="color:#999;">' . __('Unassigned', 'sprint') . '</span>') . "</td>";
            if ($showCredits) {
                echo "<td class='sprint-cell-credits'>" . self::renderCreditCell(
                    (int)($row['plugin_sprint_sprintcustomers_id'] ?? 0),
                    $row['credits'] ?? 0,
                    (int)($row['plugin_sprint_sprintcreditproducts_id'] ?? 0),
                    (float)($depCreditsById[(int)$row['id']] ?? 0)
                ) . "</td>";
            }
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
                        . "title='" . __s('Quick edit', 'sprint') . "' aria-haspopup='dialog' "
                        . "aria-label='" . __s('Quick edit', 'sprint') . ': ' . htmlescape((string)$row['name']) . "' "
                        . "data-item-id='" . (int)$row['id'] . "'>"
                        . "<i class='fas fa-pen' aria-hidden='true'></i></button> ";
                    // Back-to-backlog only for coupled items (manual placeholders
                    // stay to avoid duplicates). Opens the reason + keep/remove
                    // modal and posts to ajax/backtobacklog.php.
                    if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
                        echo "<button type='button' class='btn btn-sm btn-outline-warning sprint-backlog-btn' "
                            . "title='" . __s('Back to backlog', 'sprint') . "' aria-haspopup='dialog' "
                            . "aria-label='" . __s('Back to backlog', 'sprint') . ': ' . htmlescape((string)$row['name']) . "' "
                            . "data-backlog-id='" . (int)$row['id'] . "'>"
                            . "<i class='fas fa-undo' aria-hidden='true'></i></button> ";
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
        $movedLabel   = __s('Moved to backlog', 'sprint');
        $lblClose     = __s('Close');
        $lblWorking   = __s('Saving…', 'sprint');
        $msgNetwork   = __s('Could not reach the server. Your input is still here — try again.', 'sprint');
        $msgFailed    = __s('Could not move item back to backlog', 'sprint');

        self::renderUiHelpersScript();

        echo <<<HTML
<div class="modal fade sprint-dialog" id="sprint-backlog-reason-modal" tabindex="-1" aria-hidden="true" aria-labelledby="sprint-backlog-reason-heading">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sprint-backlog-reason-heading"><i class="fas fa-undo me-1" aria-hidden="true"></i> {$title}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{$lblClose}"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger sprint-backlog-reason-failure" role="alert" tabindex="-1" style="display:none;white-space:pre-line;"></div>
        <label class="form-label fw-bold" for="sprint-backlog-reason-text">{$lblReason}</label>
        <textarea name="reason" id="sprint-backlog-reason-text" class="form-control" rows="3" placeholder="{$phReason}" data-sprint-autofocus="1" aria-describedby="sprint-backlog-reason-err"></textarea>
        <div class="text-danger sprint-small mt-1 sprint-backlog-reason-error" id="sprint-backlog-reason-err" role="alert" style="display:none;">{$errReason}</div>
        <fieldset class="mt-3">
          <legend class="form-label fw-bold d-block" style="font-size:inherit;">{$lblChoice}</legend>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="remove_from_sprint" id="sprint-backlog-keep" value="0" checked>
            <label class="form-check-label" for="sprint-backlog-keep">{$optKeep}</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="remove_from_sprint" id="sprint-backlog-remove" value="1">
            <label class="form-check-label" for="sprint-backlog-remove">{$optRemove}</label>
          </div>
        </fieldset>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$lblCancel}</button>
        <button type="button" class="btn btn-warning sprint-backlog-reason-confirm">
          <i class="fas fa-undo me-1" aria-hidden="true"></i> {$title}
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
        if (itemId <= 0 || !\$modal) { return; }
        \$modal.find('textarea[name=reason]').val('').removeClass('is-invalid').removeAttr('aria-invalid');
        \$modal.find('.sprint-backlog-reason-error, .sprint-backlog-reason-failure').hide();
        \$modal.find('input[name=remove_from_sprint][value="0"]').prop('checked', true);
        \$modal.data('target-item', itemId);
        \$modal.data('target-btn', \$btn);
        \$modal[0].__sprintReturnFocus = this;
        bootstrap.Modal.getOrCreateInstance(\$modal[0]).show();
    });

    // The dialog stays open until the server answered, so a failure keeps
    // the reason and can be retried with the same button.
    jQuery(document).on('click', '.sprint-backlog-reason-confirm', function(){
        var \$confirm = jQuery(this);
        var \$text = \$modal.find('textarea[name=reason]');
        var reason = (\$text.val() || '').trim();
        if (reason === '') {
            \$text.addClass('is-invalid').attr('aria-invalid', 'true').trigger('focus');
            \$modal.find('.sprint-backlog-reason-error').show();
            return;
        }
        \$text.removeClass('is-invalid').removeAttr('aria-invalid');
        \$modal.find('.sprint-backlog-reason-error, .sprint-backlog-reason-failure').hide();
        var itemId = parseInt(\$modal.data('target-item'), 10) || 0;
        var \$btn   = \$modal.data('target-btn');
        var removeFromSprint = \$modal.find('input[name=remove_from_sprint]:checked').val() === '1';
        function failure(msg) {
            if (window.sprintUi) { window.sprintUi.busy(\$confirm, false); } else { \$confirm.prop('disabled', false); }
            \$modal.find('.sprint-backlog-reason-failure').text(msg).show().trigger('focus');
        }
        if (window.sprintUi) { window.sprintUi.busy(\$confirm, true, "{$lblWorking}"); } else { \$confirm.prop('disabled', true); }

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
            if (!(resp && resp.success)) {
                failure((resp && resp.message) || "{$msgFailed}");
                return;
            }
            if (window.sprintUi) { window.sprintUi.busy(\$confirm, false); window.sprintUi.toast(resp.message, 'success'); }
            else { \$confirm.prop('disabled', false); }
            bootstrap.Modal.getOrCreateInstance(\$modal[0]).hide();
            var \$row = jQuery('tr.sprint-row[data-item-id="' + itemId + '"], tr.sprint-review-row[data-item-id="' + itemId + '"]');
            if (resp.stayed) {
                \$row.find('.sprint-row-linked, .sprint-review-linked').html('<span class="text-muted fst-italic">{$movedLabel}</span>');
                \$row.css('transition','background-color 0.3s').css('background-color','#fff3cd');
                setTimeout(function(){ \$row.css('background-color',''); }, 800);
            } else {
                \$row.fadeOut(150, function(){ jQuery(this).remove(); });
            }
        }).fail(function(){
            failure("{$msgNetwork}");
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

        if (SprintCustomer::canUseCredits()) {
            $customerId = (int)($row['plugin_sprint_sprintcustomers_id'] ?? 0);
            $attrs['data-customer-id']   = $customerId;
            $attrs['data-customer-name'] = SprintCustomer::getNameFor($customerId);
            $attrs['data-credits']       = SprintCustomer::formatCredits($row['credits'] ?? 0);
            $attrs['data-credit-product-id'] = (int)($row['plugin_sprint_sprintcreditproducts_id'] ?? 0);
        }

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
        // No sprint means no Scrum Master: fail closed (see Config helper).
        if ($sprintId <= 0) {
            return false;
        }
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

        // Active filters show as removable chips plus "x of y items"; when
        // the filters hide every row an empty state with one reset appears
        // (js/sprint.js, sprintFilterAfterApply). Texts travel as data-*.
        echo "<div id='{$barId}' class='sprint-filter-bar d-flex flex-wrap align-items-center gap-2 p-2 mb-2' "
            . "data-target='{$tc}' role='search' aria-label='" . __s('Filter items', 'sprint') . "' "
            . self::filterBarTextAttrs()
            . " style='background:var(--tblr-bg-surface-secondary,#f1f3f5);border-radius:6px;'>";
        echo "<div class='d-flex align-items-center gap-1 text-muted sprint-small' aria-hidden='true'>"
            . "<i class='fas fa-filter'></i><span>" . __s('Filter', 'sprint') . "</span></div>";

        // Events are delegated from sprint.js (no inline handlers) so a CSP
        // without 'unsafe-inline' still leaves filter + reset functional.
        echo "<input type='search' class='form-control form-control-sm sf-text' "
            . "style='max-width:220px;' placeholder='" . __s('Search name...', 'sprint') . "' "
            . "aria-label='" . __s('Search name', 'sprint') . "' data-sf-label='" . __s('Search', 'sprint') . "'>";

        if (!empty($statuses)) {
            echo "<select class='form-select form-select-sm sf-status' style='max-width:180px;' "
                . "aria-label='" . __s('Status') . "' data-sf-label='" . __s('Status') . "'>";
            echo "<option value=''>" . __s('All statuses', 'sprint') . "</option>";
            echo "<option value='__not_done__'>" . __s('Not done', 'sprint') . "</option>";
            foreach ($statuses as $key => $label) {
                echo "<option value='" . htmlescape((string)$key) . "'>" . htmlescape((string)$label) . "</option>";
            }
            echo "</select>";
        }

        if (!empty($owners)) {
            echo "<select class='form-select form-select-sm sf-owner' style='max-width:220px;' "
                . "aria-label='" . __s('Owner', 'sprint') . "' data-sf-label='" . __s('Owner', 'sprint') . "'>";
            echo "<option value=''>" . __s('All owners', 'sprint') . "</option>";
            echo "<option value='__unassigned__'>" . __s('Unassigned only', 'sprint') . "</option>";
            foreach ($owners as $uidOpt => $name) {
                if ((int)$uidOpt === 0) continue;
                echo "<option value='" . (int)$uidOpt . "'>" . htmlescape((string)$name) . "</option>";
            }
            echo "</select>";
        }

        if (!empty($tags)) {
            echo "<select class='form-select form-select-sm sf-tag' style='max-width:180px;' "
                . "aria-label='" . __s('Tag', 'sprint') . "' data-sf-label='" . __s('Tag', 'sprint') . "'>";
            echo "<option value=''>" . __s('All tags', 'sprint') . "</option>";
            foreach ($tags as $tag) {
                echo "<option value='" . htmlescape(mb_strtolower($tag)) . "'>" . htmlescape($tag) . "</option>";
            }
            echo "</select>";
        }

        if (!empty($categories)) {
            echo "<select class='form-select form-select-sm sf-category' style='max-width:200px;' "
                . "aria-label='" . __s('Category', 'sprint') . "' data-sf-label='" . __s('Category', 'sprint') . "'>";
            echo "<option value=''>" . __s('All categories', 'sprint') . "</option>";
            echo "<option value='0'>" . __s('No category', 'sprint') . "</option>";
            echo SprintCategory::dropdownOptions();
            echo "</select>";
        }

        echo "<span class='sf-summary text-muted sprint-small ms-auto' aria-live='polite'></span>";
        // The one reset: clears every filter of this bar.
        echo "<button type='button' class='btn btn-sm btn-outline-secondary sf-reset' "
            . "data-sprint-action='filter-reset'>"
            . "<i class='fas fa-times me-1' aria-hidden='true'></i>" . __s('Reset filters', 'sprint') . "</button>";
        echo "<div class='sf-chips d-flex flex-wrap gap-1 w-100' style='display:none !important;' "
            . "aria-label='" . __s('Active filters', 'sprint') . "'></div>";
        echo "</div>";
    }

    /**
     * Translated texts for the filter bar's chips, result count and empty
     * state, as data-* attributes (shared by every bar that opts in by
     * containing .sf-summary / .sf-chips, including the meeting templates).
     */
    public static function filterBarTextAttrs(): string
    {
        $attrs = [
            'data-sf-all-one'  => _n('%s item', '%s items', 1, 'sprint'),
            'data-sf-all-many' => _n('%s item', '%s items', 2, 'sprint'),
            'data-sf-of-one'   => _n('%1$s of %2$s item', '%1$s of %2$s items', 1, 'sprint'),
            'data-sf-of-many'  => _n('%1$s of %2$s item', '%1$s of %2$s items', 2, 'sprint'),
            'data-sf-empty'    => __('No items match these filters.', 'sprint'),
            'data-sf-empty-hint' => __('The list has items, but the active filters hide all of them.', 'sprint'),
            'data-sf-reset-label' => __('Reset filters', 'sprint'),
            'data-sf-remove'   => __('Remove filter: %s', 'sprint'),
        ];
        $parts = [];
        foreach ($attrs as $k => $v) {
            $parts[] = $k . "='" . htmlescape($v) . "'";
        }
        return implode(' ', $parts);
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
     * One feedback pattern for every sprint dialog, once per page:
     *  - window.sprintUi.toast(msg, level): GLPI toast when available, an
     *    accessible fallback toast otherwise (level: info|success|warning|error);
     *  - window.sprintUi.busy(btn, on, label): saving state on the action;
     *  - window.sprintUi.reload(): reload that keeps scroll position and the
     *    values of the filter bars;
     *  - focus: a `.modal.sprint-dialog` focuses its first `[data-sprint-autofocus]`
     *    (or first) field when shown and returns focus to the trigger on close.
     * Written without `$` identifiers so the heredoc needs no escaping.
     */
    public static function renderUiHelpersScript(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        // Translated runtime messages for js/sprint.js (window.sprintT).
        SprintI18n::render();

        echo <<<JS
<script>
(function() {
    if (window.sprintUi) { return; }
    var ui = {};

    var glpiFns = { info: 'glpi_toast_info', success: 'glpi_toast_info', warning: 'glpi_toast_warning', error: 'glpi_toast_error' };
    var accents = { info: '#0d6efd', success: '#198754', warning: '#b35c00', error: '#dc3545' };

    function fallbackToast(msg, level) {
        var box = document.getElementById('sprint-toast-fallback');
        if (!box) {
            box = document.createElement('div');
            box.id = 'sprint-toast-fallback';
            box.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:2100;display:flex;flex-direction:column;gap:8px;'
                + 'max-width:min(420px, calc(100vw - 32px));';
            document.body.appendChild(box);
        }
        var t = document.createElement('div');
        t.setAttribute('role', level === 'error' ? 'alert' : 'status');
        t.style.cssText = 'background:var(--tblr-bg-surface,#fff);color:var(--tblr-body-color,#212529);'
            + 'border:1px solid var(--tblr-border-color,#dee2e6);border-left:4px solid ' + (accents[level] || accents.info) + ';'
            + 'border-radius:6px;padding:10px 12px;box-shadow:0 4px 16px rgba(0,0,0,.18);white-space:pre-line;';
        t.textContent = msg;
        box.appendChild(t);
        setTimeout(function() { if (t.parentNode) { t.parentNode.removeChild(t); } }, level === 'error' ? 9000 : 5000);
    }

    ui.toast = function(msg, level) {
        msg = String(msg == null ? '' : msg).trim();
        if (msg === '') { return; }
        level = accents[level] ? level : 'info';
        var fn = window[glpiFns[level]];
        if (level === 'success' && typeof window.glpi_toast_success === 'function') { fn = window.glpi_toast_success; }
        if (typeof fn === 'function') {
            try { fn(msg); return; } catch (e) { /* fall through to our own toast */ }
        }
        fallbackToast(msg, level);
    };

    ui.busy = function(btn, on, label) {
        var el = btn && btn.jquery ? btn[0] : btn;
        if (!el) { return; }
        if (on) {
            if (!el.hasAttribute('data-sprint-idle-html')) { el.setAttribute('data-sprint-idle-html', el.innerHTML); }
            el.disabled = true;
            el.setAttribute('aria-busy', 'true');
            el.innerHTML = '<span class="spinner-border spinner-border-sm' + (label ? ' me-1' : '') + '" aria-hidden="true"></span>';
            if (label) { el.appendChild(document.createTextNode(label)); }
        } else {
            if (el.hasAttribute('data-sprint-idle-html')) {
                el.innerHTML = el.getAttribute('data-sprint-idle-html');
                el.removeAttribute('data-sprint-idle-html');
            }
            el.disabled = false;
            el.removeAttribute('aria-busy');
        }
    };

    // ---- Reload without losing the user's place ------------------------
    var KEY = 'sprint-reload-state';
    function barControls(bar) {
        return bar.querySelectorAll('.sf-text, .sf-status, .sf-owner, .sf-type, .sf-tag, .sf-category, .sf-sprint');
    }
    ui.reload = function() {
        try {
            var bars = [];
            document.querySelectorAll('.sprint-filter-bar').forEach(function(bar, i) {
                var vals = [];
                barControls(bar).forEach(function(c) { vals.push(c.value); });
                bars.push({ i: i, target: bar.getAttribute('data-target') || '', vals: vals });
            });
            sessionStorage.setItem(KEY, JSON.stringify({
                url: location.pathname + location.search, y: window.scrollY, bars: bars
            }));
        } catch (e) { /* storage blocked: plain reload */ }
        window.location.reload();
    };
    function restore() {
        var st = null;
        try {
            st = JSON.parse(sessionStorage.getItem(KEY) || 'null');
            sessionStorage.removeItem(KEY);
        } catch (e) { return; }
        if (!st || st.url !== location.pathname + location.search) { return; }
        var tries = 0;
        (function attempt() {
            var bars = document.querySelectorAll('.sprint-filter-bar');
            // GLPI tabs arrive over AJAX: wait for the bars to exist.
            if ((st.bars || []).length && bars.length < st.bars.length && tries++ < 30) {
                setTimeout(attempt, 150);
                return;
            }
            (st.bars || []).forEach(function(b) {
                var bar = bars[b.i];
                if (!bar || (bar.getAttribute('data-target') || '') !== b.target) { return; }
                var ctrls = barControls(bar);
                b.vals.forEach(function(v, idx) {
                    var c = ctrls[idx];
                    if (!c || v === '' || c.value === v) { return; }
                    c.value = v;
                    c.dispatchEvent(new Event(c.tagName === 'INPUT' ? 'input' : 'change', { bubbles: true }));
                });
            });
            window.scrollTo(0, st.y || 0);
        })();
    }
    if (document.readyState === 'complete') { restore(); } else { window.addEventListener('load', restore); }

    // ---- Focus in and out of sprint dialogs ----------------------------
    function visible(el) { return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); }
    function usable(el) { return visible(el) && !el.disabled && !el.readOnly; }
    document.addEventListener('show.bs.modal', function(e) {
        var m = e.target;
        if (!m || !m.classList || !m.classList.contains('sprint-dialog')) { return; }
        var a = document.activeElement;
        if (a && a !== document.body && !m.contains(a)) { m.__sprintReturnFocus = a; }
    });
    document.addEventListener('shown.bs.modal', function(e) {
        var m = e.target;
        if (!m || !m.classList || !m.classList.contains('sprint-dialog')) { return; }
        var pick = null;
        m.querySelectorAll('[data-sprint-autofocus]').forEach(function(el) { if (!pick && usable(el)) { pick = el; } });
        if (!pick) {
            m.querySelectorAll('.modal-body input:not([type=hidden]), .modal-body select, .modal-body textarea').forEach(function(el) {
                if (!pick && usable(el)) { pick = el; }
            });
        }
        if (pick) { pick.focus(); }
    });
    document.addEventListener('hidden.bs.modal', function(e) {
        var m = e.target;
        if (!m || !m.classList || !m.classList.contains('sprint-dialog')) { return; }
        var back = m.__sprintReturnFocus;
        m.__sprintReturnFocus = null;
        // Another dialog still open (stacked reason / split dialog) keeps focus.
        if (back && document.contains(back) && !back.disabled && !document.querySelector('.modal.show')) {
            back.focus();
        } else if (back && document.contains(back) && back.closest && back.closest('.modal.show')) {
            back.focus();
        }
    });

    window.sprintUi = ui;
})();
</script>
JS;
    }

    /**
     * Render the shared quick-edit modal + JS bindings. Works on any page with
     * .sprint-quick-edit-btn buttons and rows carrying the data-* attributes
     * from {@see buildRowDataAttrs()} — board, dashboard, items tab, fastlane
     * and the meeting view all use this one dialog, so labels, field order,
     * validation and feedback are identical everywhere.
     *
     * Layout: context line (manual sprint item vs. linked GLPI item), then the
     * everyday fields (status, owner, capacity, a short note) and the rest
     * grouped under collapsible "Planning", "Customer & credits" and
     * "Dependencies" sections. The body scrolls, the footer stays visible, and
     * closing with unsaved changes asks first.
     *
     * @param int $meetingId Meeting the edits are made from (tags the history
     *                       rows as meeting-sourced and keeps the page in place)
     */
    public static function renderQuickEditUI(int $sprintId = 0, int $meetingId = 0): void
    {
        $statuses       = self::getAllStatuses();
        $priorities     = [
            1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
            4 => __('High'), 5 => __('Very high'),
        ];
        $capacityChoices = SprintMember::getCapacityChoices();
        $memberOptions  = $sprintId > 0 ? SprintMember::getSprintMemberOptions($sprintId) : [];
        $moveTargets    = $sprintId > 0 ? Sprint::getMoveTargetOptions($sprintId) : [];
        $definedTags    = Config::getDefinedTags();
        $tagRequired    = Config::isTagRequired();
        $dod            = Config::getDefinitionDone();
        $isMaster       = $sprintId > 0 && self::currentUserIsScrumMasterOf($sprintId);
        $canCredits     = SprintCustomer::canUseCredits();

        // Epic picker, Scrum Master only.
        $epicOptions = [];
        if ($isMaster) {
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
        $capacityLocked = Config::isScrumMasterOnlyCapacity() && $sprintId > 0 && !$isMaster;

        // Category guard: the category feeds the per-category limits and every
        // category figure on the dashboard, so inside a sprint only the Scrum
        // Master applies it directly — everyone else raises a request.
        $categoryOptions = SprintCategory::getAll();
        $categoryLocked  = $sprintId > 0 && !$isMaster;
        // Adhoc: same rule (see prepareInputForUpdate()).
        $adhocLocked     = $sprintId > 0 && !$isMaster;

        $rand = mt_rand();
        $requestHint = static fn(string $text, string $class = ''): string =>
            "<div class='form-text sprint-small sprint-qe-request-hint {$class}' style='color:#b35c00;'>"
            . "<i class='fas fa-user-shield me-1' aria-hidden='true'></i>" . htmlescape($text) . "</div>";

        echo "<div class='modal fade sprint-dialog' id='sprint-quickedit-modal' tabindex='-1' aria-hidden='true' "
            . "aria-labelledby='sprint-qe-heading' aria-describedby='sprint-qe-context' data-qe-rand='{$rand}'>";
        echo "<div class='modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg'>";
        echo "<div class='modal-content'>";

        // --- Header: what is being edited ---
        echo "<div class='modal-header align-items-start'>";
        echo "<div class='flex-grow-1' style='min-width:0;'>";
        echo "<h5 class='modal-title text-truncate' id='sprint-qe-heading'><i class='fas fa-pen me-1' aria-hidden='true'></i>"
            . __s('Quick edit', 'sprint') . ": <span class='sprint-qe-title'></span></h5>";
        echo "<div class='sprint-qe-context sprint-small text-muted mt-1' id='sprint-qe-context'></div>";
        echo "</div>";
        echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='" . __s('Close') . "'></button>";
        echo "</div>";

        echo "<div class='modal-body'>";
        echo "<div class='alert alert-danger sprint-qe-error d-flex align-items-start gap-2' role='alert' tabindex='-1' style='display:none !important;'>"
            . "<i class='fas fa-exclamation-circle mt-1' aria-hidden='true'></i>"
            . "<div class='flex-grow-1'><div class='sprint-qe-error-text' style='white-space:pre-line;'></div>"
            . "<button type='button' class='btn btn-sm btn-outline-danger mt-2 sprint-qe-retry' style='display:none;'>"
            . "<i class='fas fa-redo me-1' aria-hidden='true'></i>" . __s('Try again', 'sprint') . "</button></div></div>";
        // Pending approval requests on this item (filled when the dialog opens).
        echo "<div class='alert alert-warning sprint-qe-pending sprint-small py-2' style='display:none;' role='status'></div>";
        echo "<input type='hidden' name='id' value=''>";

        // --- Everyday fields ---
        echo "<div class='mb-3'><label class='form-label' for='sprint-qe-name'>" . __s('Name') . "</label>";
        echo "<input type='text' name='name' id='sprint-qe-name' class='form-control' value='' data-sprint-autofocus='1' "
            . "aria-describedby='sprint-qe-name-hint'>";
        echo "<div class='form-text sprint-small text-muted sprint-qe-name-hint' id='sprint-qe-name-hint' style='display:none;'>"
            . __s('The name follows the linked item and cannot be edited here.', 'sprint') . "</div></div>";

        echo "<div class='row g-3'>";
        echo "<div class='col-md-4 mb-3'><label class='form-label' for='sprint-qe-status'>" . __s('Status') . "</label>";
        echo "<select name='status' id='sprint-qe-status' class='form-select' data-sprint-autofocus='1'>";
        foreach ($statuses as $k => $v) {
            echo "<option value='" . htmlescape($k) . "'>" . htmlescape($v) . "</option>";
        }
        echo "</select></div>";
        echo "<div class='col-md-5 mb-3 sprint-qe-owner'><label class='form-label' for='sprint-qe-owner'>" . __s('Owner', 'sprint') . "</label>";
        echo "<select name='users_id' id='sprint-qe-owner' class='form-select'>";
        foreach ($memberOptions as $uid => $uname) {
            echo "<option value='" . (int)$uid . "'>" . htmlescape((string)$uname) . "</option>";
        }
        echo "</select></div>";
        // Fastlane items spread capacity over several members; the single
        // owner select is not authoritative there, so rows that carry their
        // allocations show those instead (meeting view).
        echo "<div class='col-md-5 mb-3 sprint-qe-fastlane-allocations' style='display:none;'>";
        echo "<div class='form-label'><i class='fas fa-bolt text-warning me-1' aria-hidden='true'></i>" . __s('Fastlane allocations', 'sprint') . "</div>";
        echo "<div class='sprint-qe-fastlane-list sprint-small'></div>";
        echo "<a href='#' class='sprint-qe-fastlane-manage btn btn-sm btn-outline-warning mt-2' target='_blank' rel='noopener'>"
            . "<i class='fas fa-cog me-1' aria-hidden='true'></i>" . __s('Manage fastlane members', 'sprint') . "</a>";
        echo "</div>";
        echo "<div class='col-md-3 mb-3 sprint-qe-capacity'><label class='form-label' for='sprint-qe-capacity'>" . __s('Capacity (%)', 'sprint') . "</label>";
        echo "<select name='capacity' id='sprint-qe-capacity' class='form-select'>";
        foreach ($capacityChoices as $val => $label) {
            echo "<option value='" . htmlescape((string)$val) . "'>" . htmlescape((string)$label) . "</option>";
        }
        echo "</select>";
        if ($capacityLocked) {
            echo $requestHint(__('Capacity changes are sent to the Scrum Master for approval.', 'sprint'), 'sprint-qe-capacity-hint');
        }
        echo "</div>";
        echo "</div>";

        // Short note by default; the toggle (or the resize handle) gives room
        // for a longer one.
        echo "<div class='mb-3'>";
        echo "<div class='d-flex align-items-center justify-content-between'>";
        echo "<label class='form-label mb-1' for='sprint-qe-note'>" . __s('Note', 'sprint') . "</label>";
        echo "<button type='button' class='btn btn-sm btn-link p-0 sprint-qe-note-toggle' aria-controls='sprint-qe-note' aria-expanded='false' "
            . "data-label-expand='" . __s('Expand note', 'sprint') . "' data-label-collapse='" . __s('Collapse note', 'sprint') . "'>"
            . "<i class='fas fa-expand-alt me-1' aria-hidden='true'></i><span>" . __s('Expand note', 'sprint') . "</span></button>";
        echo "</div>";
        echo "<textarea name='note' id='sprint-qe-note' class='form-control' rows='3' style='resize:vertical;'></textarea></div>";

        $tagsBlock = '';
        if (!empty($definedTags)) {
            $tagsBlock .= "<fieldset class='mb-3 sprint-qe-tags-block' data-tag-required='" . ($tagRequired ? 1 : 0) . "'>"
                . "<legend class='form-label' style='font-size:inherit;'><i class='fas fa-tags me-1' aria-hidden='true'></i>" . __s('Tags', 'sprint')
                . ($tagRequired ? " <span class='text-danger' aria-hidden='true'>*</span><span class='visually-hidden'>(" . __s('Required', 'sprint') . ")</span>" : '')
                . "</legend><div class='d-flex flex-wrap gap-3'>";
            foreach ($definedTags as $i => $tag) {
                $tagId = 'sprint-qe-tag-' . (int)$i;
                $tagsBlock .= "<span class='form-check mb-0'>"
                    . "<input type='checkbox' class='form-check-input sprint-qe-tag' id='{$tagId}' value='" . htmlescape($tag) . "'>"
                    . "<label class='form-check-label' for='{$tagId}'>" . htmlescape($tag) . "</label></span>";
            }
            $tagsBlock .= "</div></fieldset>";
        }
        // A required field never hides inside a collapsed section.
        if ($tagRequired) {
            echo $tagsBlock;
        }

        // --- Planning ---
        echo "<details class='sprint-qe-section mb-2' data-section='planning'>";
        echo "<summary class='sprint-qe-section-summary'><span class='fw-bold'>" . __s('Planning', 'sprint') . "</span>"
            . " <span class='sprint-qe-sum text-muted sprint-small'></span></summary>";
        echo "<div class='sprint-qe-section-body'>";
        echo "<div class='row g-3'>";
        echo "<div class='col-md-6 mb-3'><label class='form-label' for='sprint-qe-priority'>" . __s('Priority') . "</label>";
        echo "<select name='priority' id='sprint-qe-priority' class='form-select'>";
        foreach ($priorities as $k => $v) {
            echo "<option value='{$k}'>" . htmlescape($v) . "</option>";
        }
        echo "</select></div>";
        echo "<div class='col-md-6 mb-3 sprint-qe-story-points'><label class='form-label' for='sprint-qe-sp'>" . __s('Story Points', 'sprint') . "</label>";
        echo "<input type='number' name='story_points' id='sprint-qe-sp' class='form-control' min='0' step='1' value='1'></div>";
        echo "</div>";

        // Adhoc switch: the Scrum Master applies it, anyone else's change
        // becomes an approval request (prepareInputForUpdate()).
        if ($sprintId > 0) {
            echo "<div class='mb-3 form-check form-switch'>";
            echo "<input class='form-check-input' type='checkbox' id='sprint-qe-adhoc' name='is_adhoc'>";
            echo "<label class='form-check-label' for='sprint-qe-adhoc'>"
                . "<span class='sprint-adhoc-badge'><i class='fas fa-plus-circle' aria-hidden='true'></i> " . __s('Adhoc', 'sprint') . "</span> "
                . __s('Added to the sprint after kick-off (highlights the item)', 'sprint')
                . "</label>";
            if ($adhocLocked) {
                echo $requestHint(__('Adhoc changes are sent to the Scrum Master for approval.', 'sprint'));
            }
            echo "</div>";
        }

        if (!empty($categoryOptions)) {
            echo "<div class='mb-3 sprint-qe-category-block'><label class='form-label' for='sprint-qe-category'>"
                . "<i class='fas fa-folder me-1' aria-hidden='true'></i>" . __s('Category', 'sprint') . "</label>";
            echo "<select name='plugin_sprint_sprintcategories_id' id='sprint-qe-category' class='form-select'>";
            echo "<option value='0'>" . __s('No category', 'sprint') . "</option>";
            echo SprintCategory::dropdownOptions();
            echo "</select>";
            if ($categoryLocked) {
                echo $requestHint(__('Category changes are sent to the Scrum Master for approval.', 'sprint'), 'sprint-qe-category-hint');
            }
            echo "</div>";
        }

        if ($epicOptions) {
            echo "<div class='mb-3 sprint-qe-epic-block'><label class='form-label' for='sprint-qe-epic'>" . __s('Epic', 'sprint') . "</label>";
            echo "<select name='plugin_sprint_sprintepics_id' id='sprint-qe-epic' class='form-select'>";
            echo "<option value='0'>" . __s('No epic', 'sprint') . "</option>";
            foreach ($epicOptions as $eid => $ename) {
                echo "<option value='" . (int)$eid . "'>" . htmlescape($ename) . "</option>";
            }
            echo "</select></div>";
        }

        // DoD checklist; the badge shows completion at a glance.
        if ($dod) {
            echo "<fieldset class='mb-3 sprint-qe-dod-block'><legend class='form-label' style='font-size:inherit;'>"
                . "<i class='fas fa-list-check me-1' aria-hidden='true'></i>" . __s('Definition of Done', 'sprint')
                . " <span class='badge sprint-qe-dod-badge'></span></legend>";
            foreach (array_values($dod) as $i => $check) {
                $dodId = 'sprint-qe-dod-' . (int)$i;
                echo "<div class='form-check'>"
                    . "<input type='checkbox' class='form-check-input sprint-qe-dod' id='{$dodId}' value='" . htmlescape($check) . "'>"
                    . "<label class='form-check-label' for='{$dodId}'>" . htmlescape($check) . "</label></div>";
            }
            echo "</fieldset>";
        }

        if (!$tagRequired) {
            echo $tagsBlock;
        }

        echo "<div class='mb-2'><label class='form-label' for='sprint-qe-carry'>"
            . "<i class='fas fa-forward text-success me-1' aria-hidden='true'></i>"
            . __s('Carry over to sprint', 'sprint') . "</label>";
        echo "<select name='carry_over_to_sprint_id' id='sprint-qe-carry' class='form-select' aria-describedby='sprint-qe-carry-hint'>";
        echo "<option value='0'>— " . __s('Do not carry over', 'sprint') . " —</option>";
        foreach ($moveTargets as $tid => $tlabel) {
            echo "<option value='" . (int)$tid . "'>" . htmlescape((string)$tlabel) . "</option>";
        }
        echo "</select>";
        echo "<div class='form-text sprint-small text-muted' id='sprint-qe-carry-hint'>"
            . __s('A fresh copy is created in the target sprint. The original stays in this sprint.', 'sprint')
            . ($isMaster ? '' : ' ' . __s("Unless you are the target sprint's Scrum Master, this becomes a request.", 'sprint'))
            . "</div></div>";
        echo "</div></details>";

        // --- Customer & credits ---
        if ($canCredits) {
            echo "<details class='sprint-qe-section mb-2' data-section='credits'>";
            echo "<summary class='sprint-qe-section-summary'><span class='fw-bold'>" . __s('Customer & credits', 'sprint') . "</span>"
                . " <span class='sprint-qe-sum text-muted sprint-small'></span></summary>";
            echo "<div class='sprint-qe-section-body'>";
            echo "<div class='row g-3'>";
            echo "<div class='col-md-8 mb-3'><label class='form-label' for='sprint-qe-customer'>"
                . htmlescape(SprintCustomer::getTypeName(1)) . "</label>";
            // The shared helpers render without an id: add one for the label.
            echo preg_replace('/^<select /', "<select id='sprint-qe-customer' ", self::customerSelect('plugin_sprint_sprintcustomers_id', 0), 1);
            echo "</div>";
            echo "<div class='col-md-4 mb-3'><label class='form-label' for='sprint-qe-credits'>"
                . __s('Credits', 'sprint') . "</label>";
            echo preg_replace('/^<input /', "<input id='sprint-qe-credits' ", self::creditsInput('credits', 0, 'w-100'), 1);
            echo "</div>";
            echo "</div>";
            echo "<div class='mb-2'><label class='form-label' for='sprint-qe-product'>"
                . "<i class='fas fa-tags me-1' aria-hidden='true'></i>" . htmlescape(SprintCreditProduct::getTypeName(1)) . "</label>";
            echo preg_replace('/^<select /', "<select id='sprint-qe-product' aria-describedby='sprint-qe-product-hint' ", self::productSelect('plugin_sprint_sprintcreditproducts_id', 0, 'credits', 'w-100'), 1);
            echo "<div class='form-text sprint-small text-muted' id='sprint-qe-product-hint'>"
                . __s('Picking a product sets its credits. Choose "Manual credits" to enter a different amount.', 'sprint') . "</div>";
            echo "</div>";
            echo "</div></details>";
            self::creditProductScript();
        }

        // --- Dependencies (immediate actions, not part of Save) ---
        echo "<details class='sprint-qe-section mb-2 sprint-qe-deps-block' data-section='deps'>";
        echo "<summary class='sprint-qe-section-summary'><span class='fw-bold'>"
            . "<i class='fas fa-link me-1' style='color:#20c997;' aria-hidden='true'></i>" . __s('Dependencies', 'sprint') . "</span>"
            . " <span class='sprint-qe-sum text-muted sprint-small'></span></summary>";
        echo "<div class='sprint-qe-section-body'>";
        echo "<div class='alert py-1 sprint-small sprint-qe-dep-status mb-2' style='display:none;' role='status'></div>";
        // Live dependency list (loaded on open); each row edits % or removes.
        echo "<div class='sprint-qe-dep-list sprint-small mb-2' aria-live='polite'></div>";
        echo "<div class='d-flex flex-wrap gap-2 align-items-center'>";
        echo "<label class='visually-hidden' for='sprint-qe-dep-user'>" . __s('Select sprint member', 'sprint') . "</label>";
        echo "<select id='sprint-qe-dep-user' class='form-select form-select-sm sprint-qe-dep-user sprint-qe-nodirty' style='max-width:240px;'>";
        echo "<option value='0'>" . __s('Select sprint member', 'sprint') . "</option>";
        foreach ($memberOptions as $uid => $uname) {
            if ((int)$uid > 0) {
                echo "<option value='" . (int)$uid . "'>" . htmlescape((string)$uname) . "</option>";
            }
        }
        echo "</select>";
        echo "<label class='visually-hidden' for='sprint-qe-dep-cap'>" . __s('Helper capacity (%)', 'sprint') . "</label>";
        echo "<select id='sprint-qe-dep-cap' class='form-select form-select-sm sprint-qe-dep-cap sprint-qe-nodirty' style='max-width:120px;'>";
        foreach ($capacityChoices as $val => $label) {
            echo "<option value='" . htmlescape((string)$val) . "'" . ((string)$val === '5' ? ' selected' : '') . ">" . htmlescape((string)$label) . "</option>";
        }
        echo "</select>";
        if ($canCredits) {
            // The helper's own billable share, charged to the item's customer.
            echo "<label class='visually-hidden' for='sprint-qe-dep-product'>" . htmlescape(SprintCreditProduct::getTypeName(1)) . "</label>";
            echo preg_replace('/^<select /', "<select id='sprint-qe-dep-product' ", self::productSelect('_qe_dep_product', 0, '_qe_dep_credits', 'form-select-sm sprint-qe-dep-product sprint-qe-nodirty'), 1);
            echo "<label class='visually-hidden' for='sprint-qe-dep-credits'>" . __s('Helper credits', 'sprint') . "</label>";
            echo "<input type='number' min='0' step='0.25' name='_qe_dep_credits' id='sprint-qe-dep-credits' class='form-control form-control-sm sprint-qe-dep-credits sprint-qe-nodirty' "
                . "style='max-width:90px;' value='0' title='" . __s('Credits', 'sprint') . "' placeholder='" . __s('Credits', 'sprint') . "'>";
        }
        echo "<button type='button' class='btn btn-sm btn-outline-success sprint-qe-dep-add'>"
            . "<i class='fas fa-plus me-1' aria-hidden='true'></i>" . __s('Add helper', 'sprint') . "</button>";
        echo "<a href='#' class='btn btn-sm btn-outline-secondary sprint-qe-dep-manage' target='_blank' rel='noopener'>"
            . "<i class='fas fa-external-link-alt me-1' aria-hidden='true'></i>" . __s('Manage dependencies', 'sprint')
            . "<span class='visually-hidden'> (" . __s('opens in a new tab', 'sprint') . ")</span></a>";
        echo "</div>";
        echo "<div class='form-text sprint-small text-muted'>"
            . htmlescape($canCredits
                ? __("Couples a colleague to this item with their own capacity % and, when their share is billable, their own credits on this item's customer. Open 'Manage' to resolve, reopen or remove.", 'sprint')
                : __("Couples a colleague to this item with their own capacity %. Open 'Manage' to resolve, reopen or remove.", 'sprint'))
            . " " . __s('Changes here are saved immediately.', 'sprint')
            . "</div></div></details>";

        echo "</div>"; // modal-body

        // --- Footer: always visible (scrollable body) ---
        echo "<div class='modal-footer flex-wrap'>";
        echo "<div class='sprint-qe-discard me-auto d-flex flex-wrap align-items-center gap-2' role='alert' style='display:none !important;'>"
            . "<span class='fw-bold' style='color:#b35c00;'><i class='fas fa-exclamation-triangle me-1' aria-hidden='true'></i>"
            . __s('You have unsaved changes.', 'sprint') . "</span>"
            . "<button type='button' class='btn btn-sm btn-outline-secondary sprint-qe-keep'>" . __s('Keep editing', 'sprint') . "</button>"
            . "<button type='button' class='btn btn-sm btn-outline-danger sprint-qe-discard-confirm'>" . __s('Discard changes', 'sprint') . "</button>"
            . "</div>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . __s('Cancel') . "</button>";
        echo "<button type='button' class='btn btn-primary sprint-qe-save'><i class='fas fa-save me-1' aria-hidden='true'></i>"
            . __s('Save') . "</button>";
        echo "</div>";
        echo "</div></div></div>";

        // Capacity <option> list (non-zero) for editing existing dependencies.
        $depCapOptions = '';
        foreach (SprintMember::getCapacityChoices(false) as $cv => $cl) {
            $depCapOptions .= "<option value='" . htmlescape((string)$cv) . "'>" . htmlescape((string)$cl) . "</option>";
        }

        $cfg = [
            'rand'           => $rand,
            'meetingId'      => $meetingId,
            'capacityLocked' => $capacityLocked,
            'categoryLocked' => $categoryLocked,
            'adhocLocked'    => $adhocLocked,
            'depCapOptions'  => $depCapOptions,
            'adhocBadgeHtml' => self::renderAdhocBadge(true),
            'pendingBadgeHtml' => SprintRequest::renderPendingBadge(),
            'statusColors'   => [
                'todo' => '#6c757d', 'in_progress' => '#0d6efd', 'review' => '#6f42c1',
                'dependency' => '#20c997', 'done' => '#198754', 'blocked' => '#dc3545',
            ],
            'types' => [
                'Ticket' => __('Ticket'), 'Change' => __('Change'),
                'Problem' => __('Problem'), 'ProjectTask' => __('Project task'),
            ],
            'labels' => [
                'manualItem'    => __('Sprint item', 'sprint'),
                'manualHint'    => __('Manual item: every field here is the sprint item itself.', 'sprint'),
                'linkedItem'    => __('Sprint item linked to a GLPI item', 'sprint'),
                'linkedHint'    => __('You edit the sprint item. The linked item keeps its own status — change that with its own edit button.', 'sprint'),
                'fastlane'      => __('Fastlane', 'sprint'),
                'unassigned'    => __('Unassigned', 'sprint'),
                'inactive'      => __('inactive', 'sprint'),
                'noAllocations' => __('No allocations', 'sprint'),
                'total'         => __('Total', 'sprint'),
                'none'          => __('None', 'sprint'),
                'saving'        => __('Saving…', 'sprint'),
                'saved'         => __('Changes saved', 'sprint'),
                'saveFailed'    => __('Could not save the changes.', 'sprint'),
                'networkError'  => __('Could not reach the server. Your input is still here — try again.', 'sprint'),
                'tagRequired'   => __('Pick at least one tag — tags are required on every item.', 'sprint'),
                'requested'     => __('Requested — waiting for Scrum Master', 'sprint'),
                'requestedIntro' => __('Not applied yet. The Scrum Master decides on:', 'sprint'),
                'locked'        => __('The item is locked until the Scrum Master has decided. You can cancel your own request.', 'sprint'),
                'cancelRequest' => __('Cancel request', 'sprint'),
                'priority'      => __('Priority'),
                'storyPoints'   => __('SP', 'sprint'),
                'dod'           => __('DoD', 'sprint'),
                'adhoc'         => __('Adhoc', 'sprint'),
                'carryTo'       => __('Carry over to %s', 'sprint'),
                'noCustomer'    => __('No customer', 'sprint'),
                'credits'       => __('%s credits', 'sprint'),
                'depCount'      => __('%s linked', 'sprint'),
                'depNone'       => __('No dependencies yet', 'sprint'),
                'depLoading'    => __('Loading…', 'sprint'),
                'depLoadFailed' => __('Could not load the dependencies.', 'sprint'),
                'depResolved'   => __('resolved', 'sprint'),
                'depRemove'     => __('Remove dependency on %s', 'sprint'),
                'depRemoveConfirm' => __('Remove the dependency on %s? Their capacity on this item is released.', 'sprint'),
                'depCapFor'     => __('Capacity of %s', 'sprint'),
                'depCreditsFor' => __('Credits of %s', 'sprint'),
                'depCredits'    => __('Credits', 'sprint'),
                'depPickMember' => __('Please select a sprint member and a capacity > 0', 'sprint'),
                'depAddFailed'  => __('Could not add dependency', 'sprint'),
                'depUpdateFailed' => __('Could not update dependency', 'sprint'),
                'depRemoveFailed' => __('Could not remove dependency', 'sprint'),
                'expand'        => __('Expand note', 'sprint'),
                'collapse'      => __('Collapse note', 'sprint'),
            ],
        ];
        $cfgJson = json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        self::renderUiHelpersScript();
        SprintRequest::renderPanelScript();

        // Written without `$` identifiers (jq = jQuery) so this heredoc needs
        // no escaping; the only PHP value is the JSON config.
        echo <<<JS
<script>
jQuery(function() {
    var jq  = jQuery;
    var cfg = {$cfgJson};

    // GLPI ajax tabs can echo this dialog more than once: keep the copy that
    // was just rendered, on <body> (it must not sit inside a form or table),
    // and drop stale ones that are not open.
    (function adopt() {
        var fresh = jq('[data-qe-rand="' + cfg.rand + '"]');
        if (!fresh.length) { return; }
        jq('[id="sprint-quickedit-modal"]').not(fresh).each(function() {
            if (!jq(this).hasClass('show')) { jq(this).remove(); }
        });
        if (!fresh.parent().is('body')) { fresh.detach().appendTo('body'); }
        window.__sprintQuickEditCfg = cfg;
    })();

    if (window.__sprintQuickEditBound) { return; }
    window.__sprintQuickEditBound = true;

    function C() { return window.__sprintQuickEditCfg || cfg; }
    function L(key) { return (C().labels || {})[key] || ''; }
    function M() { return jq('[id="sprint-quickedit-modal"]').last(); }
    function ui() { return window.sprintUi || null; }
    function toast(msg, level) {
        if (ui()) { ui().toast(msg, level); return; }
        try { if (typeof window.glpi_toast_info === 'function') { window.glpi_toast_info(msg); } } catch (e) {}
    }
    function busy(btn, on, label) {
        if (ui()) { ui().busy(btn, on, label); return; }
        jq(btn).prop('disabled', !!on);
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
        });
    }
    function fmtLabel(key, value) { return L(key).replace('%s', value); }
    function token() {
        return jq.ajax({ url: CFG_GLPI.root_doc + '/plugins/sprint/ajax/csrftoken.php', type: 'GET', dataType: 'json', cache: false });
    }
    function rowsFor(id) {
        return jq('tr.sprint-row[data-item-id="' + id + '"], tr.sprint-review-row[data-item-id="' + id + '"]');
    }
    function itemLink(row) {
        return row.find('a').filter(function() {
            var a = jq(this);
            var href = a.attr('href');
            return !a.hasClass('btn') && href && href.indexOf('sprintitem.form.php') !== -1;
        }).first();
    }

    // ---- Feedback inside the dialog ------------------------------------
    function showError(modal, msg, retryable) {
        var box = modal.find('.sprint-qe-error');
        box.find('.sprint-qe-error-text').text(msg || L('saveFailed'));
        box.find('.sprint-qe-retry').toggle(!!retryable);
        box.attr('style', '');
        box[0].scrollIntoView({ block: 'nearest' });
        box.trigger('focus');
    }
    function clearError(modal) {
        var box = modal.find('.sprint-qe-error');
        box.attr('style', 'display:none !important;');
        box.find('.sprint-qe-error-text').text('');
        modal.find('.is-invalid').removeClass('is-invalid');
        modal.find('.sprint-qe-tags-block').removeClass('sprint-qe-invalid');
    }
    function depStatus(modal, msg, kind) {
        modal.find('.sprint-qe-dep-status')
            .removeClass('alert-info alert-success alert-danger alert-warning')
            .addClass('alert-' + (kind || 'info'))
            .attr('role', kind === 'danger' ? 'alert' : 'status')
            .text(msg).show();
    }

    // ---- Unsaved-changes guard -----------------------------------------
    function snapshot(modal) {
        var parts = [];
        modal.find('.modal-body').find('input, select, textarea').not('.sprint-qe-nodirty, [type=hidden], .sprint-qe-dep-list *').each(function() {
            parts.push(this.type === 'checkbox' || this.type === 'radio' ? (this.checked ? '1' : '0') : String(jq(this).val()));
        });
        return parts.join('\u0001');
    }
    function isDirty(modal) {
        var base = modal.data('qe-snapshot');
        return typeof base === 'string' && base !== snapshot(modal);
    }
    function showDiscard(modal, on) {
        var bar = modal.find('.sprint-qe-discard');
        bar.attr('style', on ? '' : 'display:none !important;');
        if (on) { bar.find('.sprint-qe-keep').trigger('focus'); }
    }

    // ---- Section summaries (visible while collapsed) -------------------
    function refreshSums(modal) {
        var plan = [];
        var pr = modal.find('select[name=priority] option:selected').text();
        if (pr) { plan.push(L('priority') + ': ' + pr); }
        if (parseInt(modal.data('qe-is-fastlane'), 10) !== 1) {
            plan.push(L('storyPoints') + ' ' + (modal.find('input[name=story_points]').val() || '0'));
        }
        var cat = modal.find('select[name=plugin_sprint_sprintcategories_id]');
        if (cat.length && parseInt(modal.data('qe-category-known'), 10) === 1 && cat.val() !== '0') {
            plan.push(String(cat.find('option:selected').text()).replace(/^[\s\u00a0—-]+/, '').trim());
        }
        if (modal.find('input[name=is_adhoc]').is(':checked')) { plan.push(L('adhoc')); }
        var dodTotal = modal.find('.sprint-qe-dod').length;
        if (dodTotal && parseInt(modal.data('qe-dod-known'), 10) === 1) {
            plan.push(L('dod') + ' ' + modal.find('.sprint-qe-dod:checked').length + '/' + dodTotal);
        }
        // Tags only count here when they live inside Planning (not required).
        if (modal.find('.sprint-qe-tags-block').closest('.sprint-qe-section').length) {
            var tags = modal.find('.sprint-qe-tag:checked').map(function() { return jq(this).val(); }).get();
            if (tags.length) { plan.push(tags.join(', ')); }
        }
        var carry = parseInt(modal.find('select[name=carry_over_to_sprint_id]').val(), 10) || 0;
        if (carry > 0) { plan.push(fmtLabel('carryTo', modal.find('select[name=carry_over_to_sprint_id] option:selected').text())); }
        modal.find('.sprint-qe-section[data-section=planning] .sprint-qe-sum').text(plan.length ? '· ' + plan.join(' · ') : '');

        var cust = modal.find('select[name=plugin_sprint_sprintcustomers_id]');
        if (cust.length) {
            var custTxt = cust.val() && cust.val() !== '0' ? String(cust.find('option:selected').text()).trim() : L('noCustomer');
            var cr = parseFloat(modal.find('input[name=credits]').val()) || 0;
            modal.find('.sprint-qe-section[data-section=credits] .sprint-qe-sum')
                .text('· ' + custTxt + (cr > 0 ? ' · ' + fmtLabel('credits', String(cr)) : ''));
        }
    }
    jq(document).on('change input', '#sprint-quickedit-modal .modal-body :input', function() {
        var modal = jq(this).closest('.modal');
        refreshSums(modal);
        if (jq(this).hasClass('sprint-qe-dod')) { refreshDodBadge(modal); }
    });

    function refreshDodBadge(modal) {
        var badge = modal.find('.sprint-qe-dod-badge');
        if (!badge.length) { return; }
        var total = modal.find('.sprint-qe-dod').length;
        var done  = modal.find('.sprint-qe-dod:checked').length;
        badge.text(done + '/' + total + (done === total ? ' ✓' : ''))
            .toggleClass('bg-success', done === total)
            .toggleClass('bg-secondary', done !== total);
    }

    // ---- Note: short by default, expandable ----------------------------
    function setNoteExpanded(modal, on) {
        var btn = modal.find('.sprint-qe-note-toggle');
        modal.find('textarea[name=note]').attr('rows', on ? 10 : 3);
        btn.attr('aria-expanded', on ? 'true' : 'false');
        btn.find('span').text(on ? L('collapse') : L('expand'));
        btn.find('i').attr('class', (on ? 'fas fa-compress-alt' : 'fas fa-expand-alt') + ' me-1');
    }
    jq(document).on('click', '#sprint-quickedit-modal .sprint-qe-note-toggle', function() {
        var modal = jq(this).closest('.modal');
        setNoteExpanded(modal, jq(this).attr('aria-expanded') !== 'true');
        modal.find('textarea[name=note]').trigger('focus');
    });

    // ---- Context line: sprint item vs. linked GLPI item ----------------
    function renderContext(modal, row, isFastlane) {
        var type   = String(row.attr('data-linked-itemtype') || '');
        var ctx    = modal.find('.sprint-qe-context').empty();
        var badge  = jq('<span class="badge me-1"></span>');
        if (type !== '') {
            var linkedTxt = String(row.find('.sprint-row-linked, .sprint-review-linked').first().text()).trim().replace(/\s+/g, ' ');
            if (linkedTxt.length > 90) { linkedTxt = linkedTxt.substring(0, 87) + '…'; }
            badge.css({ background: '#e7f1ff', color: '#084298', border: '1px solid #b6d4fe' })
                .html('<i class="fas fa-link me-1" aria-hidden="true"></i>').append(document.createTextNode(L('linkedItem')));
            ctx.append(badge)
                .append(jq('<span class="fw-bold me-1"></span>').text((C().types || {})[type] || type))
                .append(document.createTextNode(linkedTxt))
                .append(jq('<div></div>').text(L('linkedHint')));
        } else {
            badge.css({ background: '#f1f3f5', color: '#343a40', border: '1px solid #dee2e6' })
                .html('<i class="fas fa-clipboard-list me-1" aria-hidden="true"></i>').append(document.createTextNode(L('manualItem')));
            ctx.append(badge).append(document.createTextNode(L('manualHint')));
        }
        if (isFastlane) {
            ctx.prepend(jq('<span class="badge me-1" style="background:#fff3e0;color:#8a3c00;border:1px solid #ffc078;"></span>')
                .html('<i class="fas fa-bolt me-1" aria-hidden="true"></i>').append(document.createTextNode(L('fastlane'))));
        }
    }

    // ---- Pending approval requests -------------------------------------
    function renderPending(modal, info) {
        var box = modal.find('.sprint-qe-pending').empty();
        var reqs = (info && info.requests) || [];
        modal.data('qe-locked', info && info.locked ? 1 : 0);
        modal.find('.sprint-qe-save').prop('disabled', !!(info && info.locked))
            .attr('aria-disabled', info && info.locked ? 'true' : null);
        if (!reqs.length) { box.hide(); return; }
        box.append(jq('<div class="fw-bold"></div>')
            .html('<i class="fas fa-hourglass-half me-1" aria-hidden="true"></i>').append(document.createTextNode(L('requested'))));
        box.append(jq('<div></div>').text(L('requestedIntro')));
        var list = jq('<ul class="mb-1 ps-3"></ul>');
        reqs.forEach(function(r) {
            var li = jq('<li></li>').text(r.label + (r.change ? ': ' + r.change : ''));
            if (r.own) {
                li.append(' ').append(jq('<button type="button" class="btn btn-sm btn-link p-0 align-baseline sprint-request-cancel"></button>')
                    .attr('data-request-id', r.id).text(L('cancelRequest')));
            }
            list.append(li);
        });
        box.append(list);
        if (info.locked) { box.append(jq('<div></div>').text(L('locked'))); }
        box.show();
    }
    function markRowPending(id, reqs) {
        var rows = rowsFor(id).add(jq('.sprint-kanban-card[data-item-id="' + id + '"]'));
        rows.find('.sprint-request-pending-badge').remove();
        if (!reqs || !reqs.length) { return; }
        var detail = reqs.map(function(r) { return r.label + (r.change ? ': ' + r.change : ''); }).join('; ');
        rows.each(function() {
            var r = jq(this);
            var badge = jq(C().pendingBadgeHtml);
            badge.attr('title', L('requested') + ': ' + detail);
            var anchor = itemLink(r);
            if (anchor.length) { anchor.after(badge); }
            else { r.find('.sk-card-title').first().append(badge); }
        });
    }

    // ---- Open ------------------------------------------------------------
    jq(document).on('click', '.sprint-quick-edit-btn', function() {
        // Works for table rows (tr[data-item-id]) and Kanban cards
        // (.sprint-kanban-card[data-item-id]) alike. Match the row/card
        // container explicitly — the table buttons themselves carry a
        // data-item-id, so a bare closest('[data-item-id]') would resolve to
        // the button (which has none of the data-* fields), wiping the item.
        var row   = jq(this).closest('tr, .sprint-kanban-card');
        var modal = M();
        if (!row.length || !modal.length) { return; }

        var itemId     = row.data('item-id');
        var itemName   = row.data('item-name') || '';
        var isFastlane = parseInt(row.data('is-fastlane'), 10) === 1;
        var linkedType = String(row.attr('data-linked-itemtype') || '');

        modal.removeData('qe-snapshot qe-request-reason qe-carry-split qe-allow-close');
        clearError(modal);
        showDiscard(modal, false);
        modal.find('.sprint-qe-pending').hide().empty();
        modal.find('.sprint-qe-save').prop('disabled', false).removeAttr('aria-disabled');
        modal.find('details.sprint-qe-section').prop('open', false);

        modal.find('.sprint-qe-title').text(itemName);
        modal.find('input[name=id]').val(itemId);
        // Linked items mirror the underlying item's name (server-enforced);
        // only manual items expose an editable name.
        modal.find('input[name=name]').val(itemName).prop('readonly', linkedType !== '');
        modal.find('.sprint-qe-name-hint').toggle(linkedType !== '');
        renderContext(modal, row, isFastlane);

        modal.find('input[name=is_adhoc]').prop('checked', parseInt(row.data('is-adhoc'), 10) === 1);
        modal.find('select[name=status]').val(String(row.data('item-status') || ''));
        modal.find('select[name=priority]').val(String(row.data('item-priority') || 3));
        modal.find('select[name=users_id]').val(String(row.data('users-id') || 0));
        modal.find('input[name=story_points]').val(row.data('story-points') || 0);

        // Match the capacity option numerically ("0.5"/"5.0"/5 all resolve to
        // the same option) so decimal formatting never clears the selection.
        (function() {
            var sel = modal.find('select[name=capacity]');
            var num = parseFloat(row.data('capacity'));
            if (isNaN(num)) { num = 0; }
            var match = '';
            sel.find('option').each(function() {
                if (parseFloat(this.value) === num) { match = this.value; return false; }
            });
            // A carry-over split leaves amounts outside the fixed choices
            // (15% → 12% + 3%): inject the stored value so saving keeps it.
            sel.find('option.sprint-qe-capacity-legacy').remove();
            if (match === '' && num > 0) {
                match = String(num);
                sel.append(jq('<option class="sprint-qe-capacity-legacy"></option>').val(match).text(match + '%'));
            }
            sel.val(match);
            // Baseline for the guarded-capacity reason dialog on save.
            modal.data('qe-orig-capacity', num);
        })();

        (function() {
            var cust = modal.find('select[name=plugin_sprint_sprintcustomers_id]');
            if (cust.length) {
                // A row charged to a deactivated customer has no matching
                // option — inject one so saving cannot silently drop it. The
                // injected option (sprint-qe-customer-legacy) goes when the
                // next item is opened.
                var cur = String(parseInt(row.attr('data-customer-id'), 10) || 0);
                cust.find('option.sprint-qe-customer-legacy').remove();
                if (cur !== '0' && !cust.find("option[value='" + cur + "']").length) {
                    cust.append(jq('<option class="sprint-qe-customer-legacy"></option>')
                        .val(cur)
                        .text((row.attr('data-customer-name') || ('#' + cur)) + ' (' + L('inactive') + ')'));
                }
                cust.val(cur);
            }
            var cred = modal.find('input[name=credits]');
            if (cred.length) { cred.val(row.attr('data-credits') || '0'); }
            var prod = modal.find('select[name=plugin_sprint_sprintcreditproducts_id]');
            if (prod.length) {
                var pid = String(parseInt(row.attr('data-credit-product-id'), 10) || 0);
                prod.val(prod.find("option[value='" + pid + "']").length ? pid : '0');
            }
        })();

        modal.find('textarea[name=note]').val(row.data('note') || '');
        setNoteExpanded(modal, false);
        modal.find('select[name=carry_over_to_sprint_id]').val('0');
        modal.find('.sprint-qe-story-points, .sprint-qe-capacity').toggle(!isFastlane);
        modal.data('qe-is-fastlane', isFastlane ? 1 : 0);

        // Fastlane rows that carry their per-member allocations (meeting
        // view) show those instead of the single, non-authoritative owner.
        (function() {
            var raw   = row.attr('data-fastlane-allocations');
            var owner = modal.find('.sprint-qe-owner');
            var box   = modal.find('.sprint-qe-fastlane-allocations');
            if (!isFastlane || typeof raw === 'undefined') { owner.show(); box.hide(); return; }
            var allocs = [];
            try { allocs = JSON.parse(raw) || []; } catch (e) { allocs = []; }
            var list = box.find('.sprint-qe-fastlane-list').empty();
            if (!allocs.length) {
                list.append(jq('<span class="text-muted fst-italic"></span>').text(L('noAllocations')));
            } else {
                var total = 0;
                allocs.forEach(function(a) {
                    total += parseFloat(a.capacity) || 0;
                    list.append(jq('<div class="d-flex justify-content-between"></div>')
                        .append(jq('<span></span>').text(a.name || ''))
                        .append(jq('<strong style="color:#c75d00;"></strong>').text((a.capacity || 0) + '%')));
                });
                list.append(jq('<div class="text-muted mt-1"></div>').text(L('total') + ': ' + total + '%'));
            }
            var url = row.attr('data-fastlane-url') || '';
            box.find('.sprint-qe-fastlane-manage').attr('href', url || '#').toggle(url !== '');
            owner.hide();
            box.show();
        })();

        // Tags from the row's `|tag1|tag2|` lowercased blob.
        var tagBlob = String(row.attr('data-item-tags') || '|').toLowerCase();
        modal.find('.sprint-qe-tag').each(function() {
            var v = String(jq(this).val() || '').toLowerCase();
            jq(this).prop('checked', v !== '' && tagBlob.indexOf('|' + v + '|') !== -1);
        });

        // DoD and epic: a row that does not carry them cannot be edited
        // safely — saving would write "nothing checked" / "no epic" over
        // what the item really has. Hide those blocks and do not send them.
        var dodKnown = typeof row.attr('data-done-checks') !== 'undefined';
        modal.data('qe-dod-known', dodKnown ? 1 : 0);
        modal.find('.sprint-qe-dod-block').toggle(dodKnown);
        var dodBlob = '|' + String(row.attr('data-done-checks') || '') + '|';
        modal.find('.sprint-qe-dod').each(function() {
            jq(this).prop('checked', dodKnown && dodBlob.indexOf('|' + String(jq(this).val()) + '|') !== -1);
        });
        refreshDodBadge(modal);

        var epicKnown = typeof row.attr('data-epic-id') !== 'undefined';
        modal.data('qe-epic-known', epicKnown ? 1 : 0);
        modal.find('.sprint-qe-epic-block').toggle(epicKnown);
        modal.find('select[name=plugin_sprint_sprintepics_id]').val(String(row.attr('data-epic-id') || '0'));

        // Category + baseline for the guarded-category reason dialog on save.
        (function() {
            var cat = modal.find('select[name=plugin_sprint_sprintcategories_id]');
            if (!cat.length) { return; }
            var known = typeof row.attr('data-category-id') !== 'undefined';
            modal.data('qe-category-known', known ? 1 : 0);
            modal.find('.sprint-qe-category-block').toggle(known);
            if (!known) { modal.removeData('qe-orig-category'); return; }
            var cur = String(parseInt(row.attr('data-category-id'), 10) || 0);
            // Re-add a placeholder when this row carries a deactivated
            // category — without it the select would fall back to "no
            // category" and saving would silently clear it.
            cat.find('option.sprint-qe-cat-legacy').remove();
            if (cur !== '0' && !cat.find("option[value='" + cur + "']").length) {
                cat.append(jq('<option class="sprint-qe-cat-legacy"></option>')
                    .val(cur)
                    .text(row.attr('data-category-name') || ('#' + cur)));
            }
            cat.val(cur);
            if (cat.val() === null) { cat.val('0'); }
            modal.data('qe-orig-category', cat.val());
        })();
        modal.data('qe-orig-adhoc', modal.find('input[name=is_adhoc]').is(':checked') ? 1 : 0);

        // Guarded capacity hint only matters for regular items.
        modal.find('.sprint-qe-capacity-hint').toggle(C().capacityLocked && !isFastlane);

        // Dependencies (+ pending requests) load in the background.
        modal.find('.sprint-qe-dep-status').hide().text('');
        modal.find('.sprint-qe-dep-user').val('0');
        modal.find('.sprint-qe-dep-manage').attr(
            'href',
            CFG_GLPI.root_doc + '/plugins/sprint/front/sprintitem.form.php?id=' + encodeURIComponent(itemId) +
                '&forcetab=' + encodeURIComponent('GlpiPlugin\\\\Sprint\\\\SprintItemDependency') + '\$1'
        );
        modal.data('deps-changed', 0);
        loadQeDeps(modal, itemId);

        refreshSums(modal);
        // Explicit: Safari does not focus a clicked button.
        modal[0].__sprintReturnFocus = this;
        bootstrap.Modal.getOrCreateInstance(modal[0]).show();
    });

    // Baseline for the unsaved-changes guard: taken once the dialog is
    // shown, after every show.bs.modal listener (credit product sync) ran.
    document.addEventListener('shown.bs.modal', function(e) {
        if (!e.target || e.target.id !== 'sprint-quickedit-modal') { return; }
        var modal = jq(e.target);
        if (typeof modal.data('qe-snapshot') !== 'string') { modal.data('qe-snapshot', snapshot(modal)); }
    });
    document.addEventListener('hide.bs.modal', function(e) {
        if (!e.target || e.target.id !== 'sprint-quickedit-modal') { return; }
        var modal = jq(e.target);
        if (modal.data('qe-allow-close') || !isDirty(modal)) { return; }
        e.preventDefault();
        showDiscard(modal, true);
    });
    document.addEventListener('hidden.bs.modal', function(e) {
        if (!e.target || e.target.id !== 'sprint-quickedit-modal') { return; }
        var modal = jq(e.target);
        showDiscard(modal, false);
        modal.removeData('qe-allow-close qe-snapshot');
        // Dependency edits change badges and credit cells all over the page;
        // the meeting view patches its own "Waiting on" cell instead.
        if ((parseInt(modal.data('deps-changed'), 10) || 0) > 0 && !(C().meetingId > 0)) {
            modal.data('deps-changed', 0);
            if (ui()) { ui().reload(); } else { window.location.reload(); }
        }
    });
    jq(document).on('click', '#sprint-quickedit-modal .sprint-qe-keep', function() {
        var modal = jq(this).closest('.modal');
        showDiscard(modal, false);
        modal.find('.modal-body :input:visible:not([readonly]):not(:disabled)').first().trigger('focus');
    });
    jq(document).on('click', '#sprint-quickedit-modal .sprint-qe-discard-confirm', function() {
        var modal = jq(this).closest('.modal');
        modal.data('qe-allow-close', 1);
        bootstrap.Modal.getOrCreateInstance(modal[0]).hide();
    });
    jq(document).on('click', '#sprint-quickedit-modal .sprint-qe-retry', function() {
        jq(this).closest('.modal').find('.sprint-qe-save').trigger('click');
    });

    // ---- Save ------------------------------------------------------------
    jq(document).on('click', '#sprint-quickedit-modal .sprint-qe-save', function() {
        var btn   = jq(this);
        var modal = btn.closest('.modal');
        var id    = modal.find('input[name=id]').val();
        if (btn.prop('disabled')) { return; }
        clearError(modal);
        showDiscard(modal, false);

        // Tag required (plugin setting): refuse client-side before the round
        // trip; the server enforces the same rule.
        var tagBlock = modal.find('.sprint-qe-tags-block');
        if (tagBlock.length && parseInt(tagBlock.attr('data-tag-required'), 10) === 1
            && modal.find('.sprint-qe-tag:checked').length === 0) {
            tagBlock.closest('details').prop('open', true);
            tagBlock.addClass('sprint-qe-invalid');
            showError(modal, L('tagRequired'), false);
            tagBlock.find('.sprint-qe-tag').first().trigger('focus');
            return;
        }

        // Guarded capacity/category/adhoc edits become approval requests:
        // collect the requester's motivation for the Scrum Master first.
        // Dismissing that dialog aborts the save. Fastlane items are exempt
        // from the capacity guard only.
        var isFastlaneItem = parseInt(modal.data('qe-is-fastlane'), 10) === 1;
        var capChanged = false;
        if (C().capacityLocked && !isFastlaneItem) {
            var origCap = parseFloat(modal.data('qe-orig-capacity'));
            var newCap  = parseFloat(modal.find('select[name=capacity]').val());
            capChanged = (isNaN(newCap) ? 0 : newCap) !== (isNaN(origCap) ? 0 : origCap);
        }
        var categoryKnown = parseInt(modal.data('qe-category-known'), 10) === 1;
        var catSel = modal.find('select[name=plugin_sprint_sprintcategories_id]');
        var catChanged = C().categoryLocked && categoryKnown && catSel.length > 0
            && String(catSel.val()) !== String(modal.data('qe-orig-category'));
        var adhocInput = modal.find('input[name=is_adhoc]');
        var adhocChanged = C().adhocLocked && adhocInput.length > 0
            && (adhocInput.is(':checked') ? 1 : 0) !== (parseInt(modal.data('qe-orig-adhoc'), 10) || 0);

        // One dialog covers all: a single motivation is stored against every
        // request this save raises.
        if ((capChanged || catChanged || adhocChanged)
            && !modal.data('qe-request-reason')
            && typeof window.sprintRequestReason === 'function') {
            window.sprintRequestReason(function(reason) {
                modal.data('qe-request-reason', reason);
                btn.trigger('click');
            });
            return;
        }

        // Carrying over asks first how the work splits over both sprints. A
        // guarded capacity edit is only a request, so the stored value counts.
        var carryTo = parseInt(modal.find('select[name=carry_over_to_sprint_id]').val(), 10) || 0;
        if (carryTo > 0 && !modal.data('qe-carry-split') && typeof window.sprintCarrySplit === 'function') {
            var splitCap = C().capacityLocked
                ? parseFloat(modal.data('qe-orig-capacity'))
                : parseFloat(modal.find('select[name=capacity]').val());
            window.sprintCarrySplit({
                capacity: isFastlaneItem ? 0 : (splitCap || 0),
                credits: parseFloat(modal.find('input[name=credits]').val()) || 0,
                sprintLabel: modal.find('select[name=carry_over_to_sprint_id] option:selected').text()
            }, function(split) {
                modal.data('qe-carry-split', split);
                btn.trigger('click');
            });
            return;
        }
        var carrySplit = modal.data('qe-carry-split') || {};
        var reason     = modal.data('qe-request-reason') || undefined;

        function field(sel) {
            var el = modal.find(sel);
            return el.length ? el.val() : undefined;
        }
        function payload(confirmOverflow, tok) {
            var tags = modal.find('.sprint-qe-tag:checked').map(function() { return jq(this).val(); }).get();
            return {
                id: id,
                name: modal.find('input[name=name]').val(),
                is_adhoc: adhocInput.length ? (adhocInput.is(':checked') ? 1 : 0) : undefined,
                status: modal.find('select[name=status]').val(),
                priority: modal.find('select[name=priority]').val(),
                users_id: modal.find('.sprint-qe-owner').is(':visible') || !isFastlaneItem
                    ? modal.find('select[name=users_id]').val() : undefined,
                story_points: modal.find('input[name=story_points]').val(),
                capacity: modal.find('select[name=capacity]').val(),
                plugin_sprint_sprintcustomers_id: field('select[name=plugin_sprint_sprintcustomers_id]'),
                credits: field('input[name=credits]'),
                plugin_sprint_sprintcreditproducts_id: field('select[name=plugin_sprint_sprintcreditproducts_id]'),
                note: modal.find('textarea[name=note]').val(),
                carry_over_to_sprint_id: modal.find('select[name=carry_over_to_sprint_id]').val(),
                carry_capacity: carrySplit.carry_capacity,
                carry_credits: carrySplit.carry_credits,
                carry_reason: reason,
                capacity_reason: reason,
                category_reason: reason,
                adhoc_reason: reason,
                plugin_sprint_sprintcategories_id: (categoryKnown && catSel.length) ? catSel.val() : undefined,
                plugin_sprint_sprintepics_id: parseInt(modal.data('qe-epic-known'), 10) === 1
                    ? field('select[name=plugin_sprint_sprintepics_id]') : undefined,
                _dod_json: (modal.find('.sprint-qe-dod').length && parseInt(modal.data('qe-dod-known'), 10) === 1)
                    ? JSON.stringify(modal.find('.sprint-qe-dod:checked').map(function() { return jq(this).val(); }).get())
                    : undefined,
                // JSON-encode tags so an empty selection still transmits —
                // jQuery drops empty arrays during form serialization.
                _tags_json: modal.find('.sprint-qe-tags-block').length ? JSON.stringify(tags) : undefined,
                meeting_id: C().meetingId > 0 ? C().meetingId : undefined,
                confirm_overflow: confirmOverflow ? 1 : 0,
                _glpi_csrf_token: tok && tok.token ? tok.token : ''
            };
        }
        function runSave(confirmOverflow) {
            busy(btn, true, L('saving'));
            return token().then(function(tok) {
                return jq.ajax({
                    url: CFG_GLPI.root_doc + '/plugins/sprint/ajax/updateitemquick.php',
                    type: 'POST', dataType: 'json', data: payload(confirmOverflow, tok)
                });
            });
        }
        function done() { busy(btn, false); }
        function onFail() {
            done();
            showError(modal, L('networkError'), true);
        }
        function onResp(resp) {
            if (resp && resp.needs_confirm) {
                // A necessary confirmation: the server message names the
                // member and the overflow.
                if (window.confirm(resp.message)) {
                    runSave(true).done(onResp).fail(onFail);
                } else {
                    done();
                }
                return;
            }
            done();
            if (!resp || !resp.success) {
                if (resp && resp.pending) { renderPending(modal, resp.pending); }
                showError(modal, resp && resp.message ? resp.message : L('saveFailed'), false);
                return;
            }
            applyToRows(modal, id, resp);
            announce(resp);
            modal.data('qe-allow-close', 1);
            bootstrap.Modal.getOrCreateInstance(modal[0]).hide();
        }
        runSave(false).done(onResp).fail(onFail);
    });

    // One success message per save: requests are reported as requests,
    // never as if the value were applied.
    function announce(resp) {
        var reqs = (resp.requests || []).filter(function(r) { return r.own; });
        if (reqs.length) {
            // Labels and changes are plain text (names inside): GLPI toasts
            // parse HTML, so escape them here.
            toast(L('requested') + '\\n' + reqs.map(function(r) {
                return '• ' + esc(r.label) + (r.change ? ': ' + esc(r.change) : '');
            }).join('\\n'), 'warning');
        }
        if (resp.carried_over && resp.carry_over_message) {
            toast(resp.carry_over_message, 'success');
        } else if (!reqs.length) {
            toast(L('saved'), 'success');
        }
        if (resp.notice) { toast(resp.notice, 'warning'); }
    }

    // ---- Push the saved values back into every view of the item ----------
    function applyToRows(modal, id, resp) {
        var row            = rowsFor(id);
        var statusLabel    = modal.find('select[name=status] option[value="' + resp.status + '"]').text() || '';
        var ownerLabel     = modal.find('select[name=users_id] option[value="' + resp.users_id + '"]').text() || '';
        var priorityLabel  = modal.find('select[name=priority] option[value="' + resp.priority + '"]').text() || '';
        var statusColor    = (C().statusColors || {})[resp.status] || '#6c757d';
        var ownerId        = parseInt(resp.users_id, 10) || 0;
        if (ownerId <= 0) { ownerLabel = ''; }

        // Sync row data attributes so filters/sorts and subsequent edits all
        // start from the server's values (a request leaves them untouched).
        row.attr('data-item-name', resp.name).data('item-name', resp.name);
        row.attr('data-item-status', resp.status).data('item-status', resp.status);
        row.attr('data-item-status-label', statusLabel).data('item-status-label', statusLabel);
        row.attr('data-item-priority', resp.priority).data('item-priority', resp.priority);
        row.attr('data-users-id', resp.users_id).data('users-id', resp.users_id);
        row.attr('data-owner-name', ownerLabel).data('owner-name', ownerLabel);
        row.attr('data-story-points', resp.story_points).data('story-points', resp.story_points);
        row.attr('data-capacity', resp.capacity).data('capacity', resp.capacity);
        row.attr('data-note', resp.note).data('note', resp.note);
        if (typeof resp.credits !== 'undefined') {
            row.attr('data-credits', resp.credits).data('credits', resp.credits);
            row.attr('data-customer-id', resp.customer_id).data('customer-id', resp.customer_id);
            row.attr('data-customer-name', resp.customer_name).data('customer-name', resp.customer_name);
            row.attr('data-credit-product-id', resp.credit_product_id || 0);
            row.find('.sprint-cell-credits').html(resp.credit_cell_html);
        }
        if (typeof resp.is_adhoc !== 'undefined') {
            var adhocOn = parseInt(resp.is_adhoc, 10) === 1;
            row.attr('data-is-adhoc', adhocOn ? '1' : '0').data('is-adhoc', adhocOn ? 1 : 0);
            row.toggleClass('sprint-adhoc', adhocOn);
            row.find('.sprint-adhoc-badge').remove();
            if (adhocOn) {
                row.each(function() {
                    var a = itemLink(jq(this));
                    if (a.length) { a.after(C().adhocBadgeHtml); }
                });
            }
        }
        if (typeof resp.tags_blob !== 'undefined') {
            row.attr('data-item-tags', resp.tags_blob).data('item-tags', resp.tags_blob);
        }
        if (typeof resp.done_checks_blob !== 'undefined' && row.is('[data-done-checks]')) {
            row.filter('[data-done-checks]').attr('data-done-checks', resp.done_checks_blob).data('done-checks', resp.done_checks_blob);
        }
        if (typeof resp.epic_id !== 'undefined') {
            row.filter('[data-epic-id]').attr('data-epic-id', resp.epic_id).data('epic-id', resp.epic_id);
        }
        // Category: a dedicated column on the items list, an inline pill on
        // Kanban cards, fastlane and meeting rows. A guarded edit leaves the
        // item untouched, so the server value always wins.
        if (typeof resp.category_id !== 'undefined') {
            var card = jq('.sprint-kanban-card[data-item-id="' + id + '"]');
            row.add(card)
                .attr('data-category-id', resp.category_id)
                .attr('data-category-name', resp.category_name || '');
            row.add(card).each(function() {
                var r    = jq(this);
                var cell = r.find('.sprint-cell-category');
                if (cell.length) { cell.html(resp.category_cell_html || ''); return; }
                r.find('.sprint-category-pill').remove();
                if (!resp.category_pill_html) { return; }
                var a = itemLink(r);
                if (a.length) { a.after(resp.category_pill_html); }
            });
        }
        // Live "Linked item open" badge.
        if (typeof resp.linked_open_badge_html !== 'undefined') {
            row.find('.sprint-cell-name .sprint-review-unclosed-badge').remove();
            if (resp.linked_open_badge_html !== '') {
                row.find('.sprint-cell-name').append(resp.linked_open_badge_html);
            }
            var cardTitle = jq('.sprint-kanban-card[data-item-id="' + id + '"] .sk-card-title');
            if (cardTitle.length) {
                cardTitle.find('.sprint-review-unclosed-badge').remove();
                if (resp.linked_open_badge_html !== '') {
                    var cardEdit = cardTitle.find('.sk-card-edit');
                    if (cardEdit.length) { cardEdit[0].insertAdjacentHTML('beforebegin', resp.linked_open_badge_html); }
                    else { cardTitle[0].insertAdjacentHTML('beforeend', resp.linked_open_badge_html); }
                }
            }
        }
        if (typeof resp.tags_pills_html !== 'undefined') {
            row.each(function() {
                var r = jq(this);
                var existing = r.find('.sprint-tag-pills');
                if (existing.length) {
                    existing.replaceWith(resp.tags_pills_html);
                } else if (resp.tags_pills_html !== '') {
                    var a = itemLink(r);
                    if (a.length) { a.after(resp.tags_pills_html); }
                }
            });
        }

        // PHP-rendered list/dashboard rows.
        var listRow = row.filter('.sprint-row');
        if (listRow.length) {
            listRow.find('.sprint-cell-name a').first().text(resp.name);
            listRow.find('.sprint-cell-status .sprint-badge')
                .removeClass(function(i, c) { return (c.match(/sprint-status-\S+/g) || []).join(' '); })
                .addClass('sprint-status-' + String(resp.status).replace(/_/g, '-'))
                .text(statusLabel)
                .css('background-color', statusColor);
            listRow.find('.sprint-cell-priority').text(priorityLabel);
            var ownerCell = listRow.find('.sprint-cell-owner');
            if (ownerCell.length) {
                if (ownerId > 0 && ownerLabel) { ownerCell.text(ownerLabel); }
                else { ownerCell.empty().append(jq('<span style="color:#999;"></span>').text(L('unassigned'))); }
            }
            listRow.find('.sprint-cell-story-points').text(resp.story_points);
            listRow.find('.sprint-cell-capacity').text(resp.capacity + '%');
        }

        // Meeting review rows.
        row.find('.sprint-review-status').text(statusLabel).css('background-color', statusColor);
        var reviewOwner = row.filter('.sprint-review-row').find('.sprint-review-owner');
        reviewOwner.each(function() {
            var cell = jq(this);
            // Fastlane rows list their allocations here; leave those alone.
            if (cell.closest('tr').attr('data-is-fastlane') === '1') { return; }
            if (ownerId > 0 && ownerLabel) {
                cell.html('<i class="fas fa-user text-muted me-1" aria-hidden="true"></i>').append(document.createTextNode(ownerLabel));
            } else {
                cell.empty().append(jq('<span class="text-muted fst-italic"></span>').text(L('unassigned')));
            }
        });
        row.find('.sprint-note-display').text(resp.note || '');
        row.each(function() { itemLink(jq(this)).text(resp.name); });

        markRowPending(id, (resp.requests || []));
        // Re-run the filter bars (js/sprint.js listens for input on .sf-text)
        // so counts and chips follow the new values.
        jq('.sprint-filter-bar .sf-text').each(function() {
            this.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    // ---- Dependencies: live list + inline edit/remove ---------------------
    function loadQeDeps(modal, itemId) {
        var list = modal.find('.sprint-qe-dep-list');
        if (!list.length || !itemId) { return; }
        list.empty().append(jq('<span class="text-muted"></span>').text(L('depLoading')));
        jq.ajax({
            url: CFG_GLPI.root_doc + '/plugins/sprint/ajax/dependencies.php',
            type: 'GET', dataType: 'json', cache: false,
            data: { action: 'list', plugin_sprint_sprintitems_id: itemId }
        }).done(function(resp) {
            if (String(modal.find('input[name=id]').val()) !== String(itemId)) { return; }
            if (resp && resp.pending) { renderPending(modal, resp.pending); }
            var deps = (resp && resp.success && resp.deps) ? resp.deps : [];
            var open = deps.filter(function(d) { return !d.is_resolved; });
            modal.find('.sprint-qe-section[data-section=deps] .sprint-qe-sum')
                .text(deps.length ? '· ' + fmtLabel('depCount', String(open.length)) : '');
            syncWaitingOnCell(itemId, open);
            if (!deps.length) {
                list.empty().append(jq('<span class="text-muted fst-italic"></span>').text(L('depNone')));
                return;
            }
            var depCreditsOn = modal.find('.sprint-qe-dep-credits').length > 0;
            var html = '';
            deps.forEach(function(d) {
                var nm = esc(d.name);
                html += '<div class="d-flex align-items-center gap-2 mb-1" data-dep-row="' + d.id + '">'
                    + '<span class="flex-grow-1"><i class="fas fa-link" style="color:#20c997;" aria-hidden="true"></i> ' + nm
                    + (d.is_resolved ? ' <span class="badge bg-secondary">' + esc(L('depResolved')) + '</span>' : '')
                    + '</span>'
                    + '<select class="form-select form-select-sm sprint-qe-dep-edit-cap sprint-qe-nodirty" data-dep-id="' + d.id + '"'
                    + ' aria-label="' + esc(fmtLabel('depCapFor', d.name)) + '"'
                    + (d.is_resolved ? ' disabled' : '') + ' style="max-width:90px;">' + C().depCapOptions + '</select>'
                    + (depCreditsOn
                        ? '<input type="number" min="0" step="0.25" class="form-control form-control-sm sprint-qe-dep-edit-credits sprint-qe-nodirty" '
                            + 'data-dep-id="' + d.id + '" style="max-width:80px;" title="' + esc(d.product || L('depCredits')) + '" '
                            + 'aria-label="' + esc(fmtLabel('depCreditsFor', d.name)) + '" value="' + esc(d.credits) + '">'
                        : '')
                    + '<button type="button" class="btn btn-sm btn-outline-danger sprint-qe-dep-remove" '
                    + 'data-dep-id="' + d.id + '" data-dep-name="' + nm + '" title="' + esc(fmtLabel('depRemove', d.name)) + '" '
                    + 'aria-label="' + esc(fmtLabel('depRemove', d.name)) + '"><i class="fas fa-trash" aria-hidden="true"></i></button>'
                    + '</div>';
            });
            list.html(html);
            deps.forEach(function(d) {
                list.find('select.sprint-qe-dep-edit-cap[data-dep-id="' + d.id + '"]').val(String(d.capacity));
            });
        }).fail(function() {
            list.empty().append(jq('<span class="text-danger"></span>').text(L('depLoadFailed')));
        });
    }

    // Meeting view: the "Waiting on" cell follows the dialog's list.
    function syncWaitingOnCell(itemId, open) {
        var cell = jq('tr.sprint-review-row[data-item-id="' + itemId + '"] .sprint-review-deps');
        if (!cell.length) { return; }
        cell.empty();
        if (!open.length) {
            cell.append(jq('<span class="text-muted fst-italic"></span>').text(L('none')));
            return;
        }
        open.forEach(function(d) {
            cell.append(jq('<div></div>')
                .append(jq('<i class="fas fa-link" style="color:#20c997;font-size:0.75em;" aria-hidden="true"></i>'))
                .append(document.createTextNode(' ' + (d.name || '') + ' '))
                .append(jq('<span class="text-muted"></span>').text('(' + (d.capacity || 0) + '%)')));
        });
    }

    function depChanged(modal) {
        modal.data('deps-changed', (parseInt(modal.data('deps-changed'), 10) || 0) + 1);
        loadQeDeps(modal, modal.find('input[name=id]').val());
    }

    jq(document).on('click', '#sprint-quickedit-modal .sprint-qe-dep-add', function() {
        var btn    = jq(this);
        var modal  = btn.closest('.modal');
        var itemId = modal.find('input[name=id]').val();
        var userId = parseInt(modal.find('.sprint-qe-dep-user').val(), 10) || 0;
        var cap    = parseFloat(modal.find('.sprint-qe-dep-cap').val()) || 0;
        var depCredits = modal.find('.sprint-qe-dep-credits');
        var depProduct = modal.find('.sprint-qe-dep-product');

        if (!itemId || userId <= 0 || cap <= 0) {
            depStatus(modal, L('depPickMember'), 'danger');
            (userId <= 0 ? modal.find('.sprint-qe-dep-user') : modal.find('.sprint-qe-dep-cap')).trigger('focus');
            return;
        }
        function runDepAdd(confirmOverflow) {
            busy(btn, true, L('saving'));
            return token().then(function(tok) {
                return jq.ajax({
                    url: CFG_GLPI.root_doc + '/plugins/sprint/ajax/dependencyadd.php',
                    type: 'POST', dataType: 'json',
                    data: {
                        plugin_sprint_sprintitems_id: itemId,
                        users_id: userId,
                        capacity: cap,
                        credits: depCredits.length ? depCredits.val() : undefined,
                        plugin_sprint_sprintcreditproducts_id: depProduct.length ? depProduct.val() : undefined,
                        confirm_overflow: confirmOverflow ? 1 : 0,
                        _glpi_csrf_token: tok && tok.token ? tok.token : ''
                    }
                });
            });
        }
        function onDepResp(resp) {
            if (resp && resp.needs_confirm) {
                if (window.confirm(resp.message)) {
                    runDepAdd(true).done(onDepResp).fail(onDepFail);
                } else {
                    busy(btn, false);
                }
                return;
            }
            busy(btn, false);
            if (resp && resp.success) {
                depStatus(modal, resp.warning ? (resp.message + ' — ' + resp.warning) : resp.message, resp.warning ? 'warning' : 'success');
                modal.find('.sprint-qe-dep-user').val('0');
                if (depCredits.length) { depCredits.val('0'); }
                if (depProduct.length) { depProduct.val('0'); }
                depChanged(modal);
            } else {
                depStatus(modal, resp && resp.message ? resp.message : L('depAddFailed'), 'danger');
            }
        }
        function onDepFail() {
            busy(btn, false);
            depStatus(modal, L('networkError'), 'danger');
        }
        runDepAdd(false).done(onDepResp).fail(onDepFail);
    });

    function depUpdate(input, data) {
        var modal  = input.closest('.modal');
        var depId  = parseInt(input.data('dep-id'), 10) || 0;
        var itemId = modal.find('input[name=id]').val();
        if (depId <= 0) { return; }
        input.prop('disabled', true).attr('aria-busy', 'true');
        token().then(function(tok) {
            return jq.ajax({
                url: CFG_GLPI.root_doc + '/plugins/sprint/ajax/dependencies.php',
                type: 'POST', dataType: 'json',
                data: jq.extend({ action: 'update', plugin_sprint_sprintitems_id: itemId, id: depId,
                    _glpi_csrf_token: tok && tok.token ? tok.token : '' }, data)
            });
        }).done(function(resp) {
            if (resp && resp.success) {
                if (typeof resp.credits !== 'undefined' && input.hasClass('sprint-qe-dep-edit-credits')) { input.val(resp.credits); }
                depStatus(modal, resp.warning ? (resp.message + ' — ' + resp.warning) : resp.message, resp.warning ? 'warning' : 'success');
                modal.data('deps-changed', (parseInt(modal.data('deps-changed'), 10) || 0) + 1);
            } else {
                depStatus(modal, resp && resp.message ? resp.message : L('depUpdateFailed'), 'danger');
            }
        }).fail(function() {
            depStatus(modal, L('networkError'), 'danger');
        }).always(function() {
            input.prop('disabled', false).removeAttr('aria-busy');
        });
    }
    jq(document).on('change', '#sprint-quickedit-modal .sprint-qe-dep-edit-cap', function() {
        var cap = parseFloat(jq(this).val()) || 0;
        if (cap > 0) { depUpdate(jq(this), { capacity: cap }); }
    });
    jq(document).on('change', '#sprint-quickedit-modal .sprint-qe-dep-edit-credits', function() {
        depUpdate(jq(this), { credits: jq(this).val() });
    });

    jq(document).on('click', '#sprint-quickedit-modal .sprint-qe-dep-remove', function() {
        var btn    = jq(this);
        var modal  = btn.closest('.modal');
        var depId  = parseInt(btn.data('dep-id'), 10) || 0;
        var itemId = modal.find('input[name=id]').val();
        if (depId <= 0) { return; }
        // Necessary confirmation, naming who is uncoupled and the effect.
        if (!window.confirm(fmtLabel('depRemoveConfirm', String(btn.attr('data-dep-name') || '')))) { return; }
        busy(btn, true, '');
        token().then(function(tok) {
            return jq.ajax({
                url: CFG_GLPI.root_doc + '/plugins/sprint/ajax/dependencies.php',
                type: 'POST', dataType: 'json',
                data: { action: 'remove', plugin_sprint_sprintitems_id: itemId, id: depId,
                    _glpi_csrf_token: tok && tok.token ? tok.token : '' }
            });
        }).done(function(resp) {
            busy(btn, false);
            if (resp && resp.success) {
                depStatus(modal, resp.message, 'success');
                depChanged(modal);
                modal.find('.sprint-qe-dep-user').trigger('focus');
            } else {
                depStatus(modal, resp && resp.message ? resp.message : L('depRemoveFailed'), 'danger');
            }
        }).fail(function() {
            busy(btn, false);
            depStatus(modal, L('networkError'), 'danger');
        });
    });
});
</script>
JS;
        // Reason dialog for guarded capacity/category/adhoc edits (approval requests).
        SprintRequest::renderReasonModalUI();
        self::renderCarrySplitModalUI();
    }

    /**
     * "How does the work split?" dialog behind a carry-over, once per page:
     * window.sprintCarrySplit({capacity, credits, sprintLabel}, callback) asks
     * what stays and what moves on — both always add up to the original — and
     * calls back with {carry_capacity, carry_credits}. Nothing to split means
     * no dialog.
     */
    public static function renderCarrySplitModalUI(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        $title     = __s('Carry over: split the work', 'sprint');
        $intro     = __s('Carrying over to %s. What was done stays in this sprint and the rest moves on. Both always add up to the original.', 'sprint');
        $lblCap    = __s('Capacity (%)', 'sprint');
        $lblCred   = __s('Credits', 'sprint');
        $lblStay   = __s('Stays in this sprint', 'sprint');
        $lblCarry  = __s('Carry over', 'sprint');
        $lblTotal  = __s('Total', 'sprint');
        $lblCancel = __s('Cancel');
        $lblClose  = __s('Close');

        self::renderUiHelpersScript();

        echo <<<HTML
<div class="modal fade sprint-dialog" id="sprint-carry-split-modal" tabindex="-1" aria-hidden="true" aria-labelledby="sprint-carry-split-heading">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sprint-carry-split-heading"><i class="fas fa-forward text-success me-1" aria-hidden="true"></i> {$title}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{$lblClose}"></button>
      </div>
      <div class="modal-body">
        <p class="sprint-small text-muted sprint-carry-split-intro" data-text="{$intro}"></p>
        <fieldset class="sprint-carry-split-row mb-3" data-kind="capacity" data-step="0.5">
          <legend class="form-label fw-bold" style="font-size:inherit;">{$lblCap} <span class="text-muted fw-normal">· {$lblTotal}: <span class="sprint-carry-split-total"></span>%</span></legend>
          <div class="row g-2">
            <div class="col-6"><label class="form-label sprint-small mb-1" for="sprint-carry-cap-stay">{$lblStay}</label>
              <input type="number" min="0" step="0.5" id="sprint-carry-cap-stay" class="form-control sprint-carry-split-stay"></div>
            <div class="col-6"><label class="form-label sprint-small mb-1" for="sprint-carry-cap-carry">{$lblCarry}</label>
              <input type="number" min="0" step="0.5" id="sprint-carry-cap-carry" class="form-control sprint-carry-split-carry" data-sprint-autofocus="1"></div>
          </div>
        </fieldset>
        <fieldset class="sprint-carry-split-row" data-kind="credits" data-step="0.25">
          <legend class="form-label fw-bold" style="font-size:inherit;">{$lblCred} <span class="text-muted fw-normal">· {$lblTotal}: <span class="sprint-carry-split-total"></span></span></legend>
          <div class="row g-2">
            <div class="col-6"><label class="form-label sprint-small mb-1" for="sprint-carry-cred-stay">{$lblStay}</label>
              <input type="number" min="0" step="0.25" id="sprint-carry-cred-stay" class="form-control sprint-carry-split-stay"></div>
            <div class="col-6"><label class="form-label sprint-small mb-1" for="sprint-carry-cred-carry">{$lblCarry}</label>
              <input type="number" min="0" step="0.25" id="sprint-carry-cred-carry" class="form-control sprint-carry-split-carry" data-sprint-autofocus="1"></div>
          </div>
        </fieldset>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$lblCancel}</button>
        <button type="button" class="btn btn-primary sprint-carry-split-confirm">
          <i class="fas fa-forward me-1" aria-hidden="true"></i> {$lblCarry}
        </button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }

    // Same stacking trick as the request-reason dialog: live on <body>, and
    // drop the stale copy a GLPI ajax-tab reload leaves behind.
    jQuery(function(){
        // [id=…] rather than #…: an id lookup stops at the first match.
        var all = jQuery('[id=sprint-carry-split-modal]');
        if (all.length > 1) { all.slice(0, all.length - 1).remove(); }
        var last = jQuery('[id=sprint-carry-split-modal]').last();
        if (last.length && !last.parent().is('body')) { last.detach().appendTo('body'); }
    });

    if (window.__sprintCarrySplitBound) { return; }
    window.__sprintCarrySplitBound = true;

    function fmt(v) { return String(Math.round(v * 100) / 100); }
    // Snap to the row's step and keep both halves inside [0, total].
    function clamp(row, v) {
        var step  = parseFloat(row.attr('data-step')) || 1;
        var total = parseFloat(row.data('total')) || 0;
        if (isNaN(v)) { v = 0; }
        v = Math.round(v / step) * step;
        return Math.max(0, Math.min(total, v));
    }

    window.sprintCarrySplit = function(opts, callback){
        var m = jQuery('[id=sprint-carry-split-modal]').last();
        var totals = { capacity: parseFloat(opts.capacity) || 0, credits: parseFloat(opts.credits) || 0 };
        if (!m.length || (totals.capacity <= 0 && totals.credits <= 0)) {
            callback({});
            return;
        }
        var intro = m.find('.sprint-carry-split-intro');
        intro.text(String(intro.attr('data-text')).replace('%s', opts.sprintLabel || ''));
        m.find('.sprint-carry-split-row').each(function(){
            var row   = jQuery(this);
            var total = totals[row.attr('data-kind')];
            row.data('total', total).toggle(total > 0);
            row.find('.sprint-carry-split-total').text(fmt(total));
            row.find('input').attr('max', total);
            row.find('.sprint-carry-split-stay').val(fmt(total));
            row.find('.sprint-carry-split-carry').val('0');
        });
        m.data('cb', callback);
        bootstrap.Modal.getOrCreateInstance(m[0]).show();
        setTimeout(function(){ m.find('.sprint-carry-split-row:visible .sprint-carry-split-carry').first().trigger('focus').trigger('select'); }, 200);
    };

    // Either half drives the other, so the pair always sums to the total.
    jQuery(document).on('input change', '#sprint-carry-split-modal input', function(e){
        var input = jQuery(this);
        var row   = input.closest('.sprint-carry-split-row');
        var total = parseFloat(row.data('total')) || 0;
        var v     = clamp(row, parseFloat(input.val()));
        var other = input.hasClass('sprint-carry-split-stay') ? '.sprint-carry-split-carry' : '.sprint-carry-split-stay';
        row.find(other).val(fmt(total - v));
        if (e.type === 'change') { input.val(fmt(v)); }
    });

    // An answer only holds for the numbers it was given for.
    jQuery(document).on('change', '#sprint-quickedit-modal select[name=carry_over_to_sprint_id], #sprint-quickedit-modal select[name=capacity], #sprint-quickedit-modal input[name=credits]', function(){
        jQuery(this).closest('.modal').removeData('qe-carry-split');
    });

    jQuery(document).on('click', '.sprint-carry-split-confirm', function(){
        var m = jQuery(this).closest('#sprint-carry-split-modal');
        var out = {};
        m.find('.sprint-carry-split-row').each(function(){
            var row = jQuery(this);
            if ((parseFloat(row.data('total')) || 0) <= 0) { return; }
            out['carry_' + row.attr('data-kind')] = clamp(row, parseFloat(row.find('.sprint-carry-split-carry').val()));
        });
        // The other half of a split is 0, never "not sent".
        if (out.carry_capacity === undefined) { out.carry_capacity = 0; }
        if (out.carry_credits === undefined) { out.carry_credits = 0; }
        var cb = m.data('cb');
        m.removeData('cb');
        bootstrap.Modal.getOrCreateInstance(m[0]).hide();
        if (typeof cb === 'function') { cb(out); }
    });
})();
</script>
HTML;
    }

    /**
     * Render the "quick edit linked item" modal + JS once per page, so users
     * can change the linked Ticket/Change/Problem/ProjectTask status (and
     * ProjectTask percent-done) without leaving the sprint view. Same
     * feedback, focus and unsaved-changes behaviour as the sprint item dialog
     * ({@see renderQuickEditUI()}); the header says it edits the GLPI item.
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

        $statusSelect = static function (string $block, string $name, string $id, array $options): string {
            $html = "<div class='mb-3 {$block}' style='display:none;'>"
                . "<label class='form-label' for='{$id}'>" . __s('Status') . "</label>"
                . "<select class='form-select' name='{$name}' id='{$id}' data-sprint-autofocus='1'>";
            foreach ($options as $k => $label) {
                $html .= "<option value='" . (int)$k . "'>" . htmlescape((string)$label) . "</option>";
            }
            return $html . "</select></div>";
        };

        echo "<div class='modal fade sprint-dialog' id='sprint-linked-quickedit-modal' tabindex='-1' aria-hidden='true' "
            . "aria-labelledby='sprint-lqe-heading' aria-describedby='sprint-lqe-context'>";
        echo "<div class='modal-dialog modal-dialog-centered modal-dialog-scrollable'>";
        echo "<div class='modal-content'>";
        echo "<div class='modal-header align-items-start'>";
        echo "<div class='flex-grow-1' style='min-width:0;'>";
        echo "<h5 class='modal-title text-truncate' id='sprint-lqe-heading'>"
            . "<i class='sprint-lqe-icon fas fa-pen me-1' aria-hidden='true'></i>"
            . "<span class='sprint-lqe-type-label'></span>"
            . ": <span class='sprint-lqe-title'></span></h5>";
        echo "<div class='sprint-small text-muted mt-1' id='sprint-lqe-context'>"
            . "<span class='badge me-1' style='background:#e7f1ff;color:#084298;border:1px solid #b6d4fe;'>"
            . "<i class='fas fa-link me-1' aria-hidden='true'></i>" . __s('Linked GLPI item', 'sprint') . "</span>"
            . __s('This changes the GLPI item itself, not the sprint item.', 'sprint') . "</div>";
        echo "</div>";
        echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='" . __s('Close') . "'></button>";
        echo "</div>";
        echo "<div class='modal-body'>";
        echo "<div class='alert alert-danger sprint-lqe-error' role='alert' tabindex='-1' style='display:none;'>"
            . "<div class='sprint-lqe-error-text' style='white-space:pre-line;'></div>"
            . "<button type='button' class='btn btn-sm btn-outline-danger mt-2 sprint-lqe-retry' style='display:none;'>"
            . "<i class='fas fa-redo me-1' aria-hidden='true'></i>" . __s('Try again', 'sprint') . "</button></div>";
        echo "<input type='hidden' name='itemtype'>";
        echo "<input type='hidden' name='id'>";

        echo $statusSelect('sprint-lqe-ticket-status', 'ticket_status', 'sprint-lqe-ticket-status', $ticketStatuses);
        echo $statusSelect('sprint-lqe-change-status', 'change_status', 'sprint-lqe-change-status', $changeStatuses);
        echo $statusSelect('sprint-lqe-problem-status', 'problem_status', 'sprint-lqe-problem-status', $problemStatuses);
        echo $statusSelect('sprint-lqe-ptask-status', 'projectstates_id', 'sprint-lqe-ptask-state', $projectStates);

        echo "<div class='mb-3 sprint-lqe-ptask-percent' style='display:none;'>";
        echo "<label class='form-label' for='sprint-lqe-percent'>" . __s('Percent done') . "</label>";
        echo "<input type='number' class='form-control' name='percent_done' id='sprint-lqe-percent' min='0' max='100' step='1' value='0'>";
        echo "</div>";

        echo "</div>";
        echo "<div class='modal-footer flex-wrap'>";
        echo "<div class='sprint-lqe-discard me-auto d-flex flex-wrap align-items-center gap-2' role='alert' style='display:none !important;'>"
            . "<span class='fw-bold' style='color:#b35c00;'><i class='fas fa-exclamation-triangle me-1' aria-hidden='true'></i>"
            . __s('You have unsaved changes.', 'sprint') . "</span>"
            . "<button type='button' class='btn btn-sm btn-outline-secondary sprint-lqe-keep'>" . __s('Keep editing', 'sprint') . "</button>"
            . "<button type='button' class='btn btn-sm btn-outline-danger sprint-lqe-discard-confirm'>" . __s('Discard changes', 'sprint') . "</button>"
            . "</div>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . __s('Cancel') . "</button>";
        echo "<button type='button' class='btn btn-primary sprint-lqe-save'>"
            . "<i class='fas fa-save me-1' aria-hidden='true'></i>" . __s('Save') . "</button>";
        echo "</div></div></div></div>";

        $cfgJson = json_encode([
            'types' => [
                'Ticket'      => ['icon' => 'fas fa-ticket-alt',         'label' => __('Ticket')],
                'Change'      => ['icon' => 'fas fa-exchange-alt',       'label' => __('Change')],
                'Problem'     => ['icon' => 'fas fa-exclamation-circle', 'label' => __('Problem')],
                'ProjectTask' => ['icon' => 'fas fa-tasks',              'label' => __('Project task')],
            ],
            'labels' => [
                'saving'       => __('Saving…', 'sprint'),
                'saved'        => __('Changes saved', 'sprint'),
                'saveFailed'   => __('Could not save the changes.', 'sprint'),
                'networkError' => __('Could not reach the server. Your input is still here — try again.', 'sprint'),
            ],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        self::renderUiHelpersScript();

        // Written without `$` identifiers (jq = jQuery): no heredoc escaping.
        echo <<<JS
<script>
jQuery(function() {
    var jq = jQuery;
    // Live on <body>, not inside a form/table (meeting view), once.
    (function() {
        var all = jq('[id="sprint-linked-quickedit-modal"]');
        if (all.length > 1) { all.slice(0, all.length - 1).not('.show').remove(); }
        var last = jq('[id="sprint-linked-quickedit-modal"]').last();
        if (last.length && !last.parent().is('body')) { last.detach().appendTo('body'); }
    })();
    if (window.__sprintLinkedQuickEditBound) { return; }
    window.__sprintLinkedQuickEditBound = true;

    var cfg = {$cfgJson};
    function L(k) { return cfg.labels[k] || ''; }
    function M() { return jq('[id="sprint-linked-quickedit-modal"]').last(); }
    function toast(msg, level) {
        if (window.sprintUi) { window.sprintUi.toast(msg, level); return; }
        try { if (typeof window.glpi_toast_info === 'function') { window.glpi_toast_info(msg); } } catch (e) {}
    }
    function busy(btn, on) {
        if (window.sprintUi) { window.sprintUi.busy(btn, on, L('saving')); return; }
        jq(btn).prop('disabled', !!on);
    }
    function showError(m, msg, retryable) {
        var box = m.find('.sprint-lqe-error');
        box.find('.sprint-lqe-error-text').text(msg);
        box.find('.sprint-lqe-retry').toggle(!!retryable);
        box.show().trigger('focus');
    }
    function snapshot(m) {
        return m.find('.modal-body select, .modal-body input[type=number]').map(function() { return String(jq(this).val()); }).get().join('|');
    }
    function showDiscard(m, on) {
        var bar = m.find('.sprint-lqe-discard');
        bar.attr('style', on ? '' : 'display:none !important;');
        if (on) { bar.find('.sprint-lqe-keep').trigger('focus'); }
    }

    // Closing/reopening the linked item flips the "Linked item open" warning
    // on every view showing that sprint item. Patch list rows, meeting review
    // rows and Kanban cards in place so the badge doesn't linger until the
    // next page load.
    function syncLinkedOpenBadge(itemId, html) {
        var sel = '[data-item-id="' + itemId + '"]';
        jq('tr' + sel + ', .sprint-kanban-card' + sel).each(function() {
            var el = jq(this);
            el.find('.sprint-review-unclosed-badge').remove();
            // Meeting review rows tint the whole row while the link is open.
            el.toggleClass('sprint-review-unclosed', html !== '');
            if (html === '') { return; }

            var nameCell = el.find('.sprint-cell-name').first();
            if (nameCell.length) { nameCell.append(html); return; }

            var status = el.find('.sprint-review-status').first();
            if (status.length) { status.after(html); return; }

            var cardTitle = el.find('.sk-card-title').first();
            if (cardTitle.length) {
                var cardEdit = cardTitle.find('.sk-card-edit');
                if (cardEdit.length) { cardEdit[0].insertAdjacentHTML('beforebegin', html); }
                else { cardTitle[0].insertAdjacentHTML('beforeend', html); }
                return;
            }
            // Fallback: after the sprint-item link in the row.
            var anchor = el.find("a[href*='sprintitem.form.php']").first();
            if (anchor.length) { anchor.after(html); }
        });
    }

    jq(document).on('click', '.sprint-linked-quick-edit-btn', function(ev) {
        ev.preventDefault();
        ev.stopPropagation();

        var btn      = jq(this);
        var itemtype = String(btn.data('linked-itemtype') || '');
        var id       = parseInt(btn.data('linked-id'), 10) || 0;
        var meta     = cfg.types[itemtype];
        var m        = M();
        if (!meta || id <= 0 || !m.length) { return; }

        m.find('input[name=itemtype]').val(itemtype);
        m.find('input[name=id]').val(id);
        m.find('.sprint-lqe-title').text(btn.data('linked-name') || '');
        m.find('.sprint-lqe-type-label').text(meta.label);
        m.find('.sprint-lqe-icon').attr('class', 'sprint-lqe-icon ' + meta.icon + ' me-1');
        m.find('.sprint-lqe-error').hide().find('.sprint-lqe-error-text').text('');
        showDiscard(m, false);
        m.removeData('lqe-allow-close lqe-snapshot');

        m.find('.sprint-lqe-ticket-status, .sprint-lqe-change-status, .sprint-lqe-problem-status, .sprint-lqe-ptask-status, .sprint-lqe-ptask-percent').hide();
        var status = String(btn.data('linked-status'));
        if (itemtype === 'Ticket') {
            m.find('.sprint-lqe-ticket-status').show();
            m.find('select[name=ticket_status]').val(status);
        } else if (itemtype === 'Change') {
            m.find('.sprint-lqe-change-status').show();
            m.find('select[name=change_status]').val(status);
        } else if (itemtype === 'Problem') {
            m.find('.sprint-lqe-problem-status').show();
            m.find('select[name=problem_status]').val(status);
        } else if (itemtype === 'ProjectTask') {
            m.find('.sprint-lqe-ptask-status, .sprint-lqe-ptask-percent').show();
            m.find('select[name=projectstates_id]').val(status);
            m.find('input[name=percent_done]').val(parseInt(btn.data('linked-percent'), 10) || 0);
        }
        m.data('lqe-snapshot', snapshot(m));
        m.data('trigger-btn', btn);
        m[0].__sprintReturnFocus = this;
        bootstrap.Modal.getOrCreateInstance(m[0]).show();
    });

    document.addEventListener('hide.bs.modal', function(e) {
        if (!e.target || e.target.id !== 'sprint-linked-quickedit-modal') { return; }
        var m = jq(e.target);
        if (m.data('lqe-allow-close') || m.data('lqe-snapshot') === snapshot(m)) { return; }
        e.preventDefault();
        showDiscard(m, true);
    });
    jq(document).on('click', '.sprint-lqe-keep', function() { showDiscard(jq(this).closest('.modal'), false); });
    jq(document).on('click', '.sprint-lqe-discard-confirm', function() {
        var m = jq(this).closest('.modal');
        m.data('lqe-allow-close', 1);
        bootstrap.Modal.getOrCreateInstance(m[0]).hide();
    });
    jq(document).on('click', '.sprint-lqe-retry', function() {
        jq(this).closest('.modal').find('.sprint-lqe-save').trigger('click');
    });

    jq(document).on('click', '.sprint-lqe-save', function() {
        var btn      = jq(this);
        var m        = btn.closest('.modal');
        var itemtype = m.find('input[name=itemtype]').val();
        var id       = m.find('input[name=id]').val();

        var payload = { itemtype: itemtype, id: id };
        if (itemtype === 'Ticket') {
            payload.status = m.find('select[name=ticket_status]').val();
        } else if (itemtype === 'Change') {
            payload.status = m.find('select[name=change_status]').val();
        } else if (itemtype === 'Problem') {
            payload.status = m.find('select[name=problem_status]').val();
        } else if (itemtype === 'ProjectTask') {
            payload.projectstates_id = m.find('select[name=projectstates_id]').val();
            payload.percent_done     = m.find('input[name=percent_done]').val();
        }

        busy(btn, true);
        m.find('.sprint-lqe-error').hide();
        showDiscard(m, false);

        jq.ajax({
            url: CFG_GLPI.root_doc + '/plugins/sprint/ajax/csrftoken.php',
            type: 'GET', dataType: 'json', cache: false
        }).then(function(tokResp) {
            payload._glpi_csrf_token = tokResp && tokResp.token ? tokResp.token : '';
            return jq.ajax({
                url: CFG_GLPI.root_doc + '/plugins/sprint/ajax/updatelinkedquick.php',
                type: 'POST', dataType: 'json', data: payload
            });
        }).done(function(resp) {
            busy(btn, false);
            if (resp && resp.success) {
                // Push the new state back onto the triggering button so a
                // second click without a page reload picks up fresh data.
                var trigger = m.data('trigger-btn');
                if (trigger && trigger.length) {
                    if (itemtype === 'ProjectTask') {
                        trigger.attr('data-linked-status', resp.projectstates_id).data('linked-status', resp.projectstates_id);
                        trigger.attr('data-linked-percent', resp.percent_done).data('linked-percent', resp.percent_done);
                        // Keep the compact linked-item % badge in sync.
                        trigger.closest('.sprint-linked-compact').find('.sprint-linked-pct').text(resp.percent_done + '%');
                    } else {
                        trigger.attr('data-linked-status', resp.status).data('linked-status', resp.status);
                    }
                }
                (resp.affected_items || []).forEach(function(aff) { syncLinkedOpenBadge(aff.id, aff.badge_html || ''); });
                toast(L('saved') + (resp.status_label ? ': ' + resp.status_label : ''), 'success');
                if (resp.message) { toast(resp.message, 'warning'); }
                m.data('lqe-allow-close', 1);
                bootstrap.Modal.getOrCreateInstance(m[0]).hide();
            } else {
                showError(m, resp && resp.message ? resp.message : L('saveFailed'), false);
            }
        }).fail(function() {
            busy(btn, false);
            showError(m, L('networkError'), true);
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
        if (!self::validateRequiredTags($input)) {
            return false;
        }

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
            if (!empty($policy['overridden'])) {
                Session::addMessageAfterRedirect($policy['message'], false, WARNING);
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
        if (!$this->validateCustomer($input, (int)($input['plugin_sprint_sprints_id'] ?? 0))) {
            return false;
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

        $input = self::sanitizeInput($input, $this->fields);
        $input = $this->resolveLinkedItem($input);
        $input = $this->enforceLinkedItemName($input);
        $input = self::normalizeDodInput($input);
        if (!self::validateRequiredTags($input)) {
            return false;
        }

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
            if (!empty($policy['overridden'])) {
                Session::addMessageAfterRedirect($policy['message'], false, WARNING);
                // Picked up in post_updateItem(): the skipped checks land in the
                // item history so an overruled DoD stays auditable.
                $input['_dod_override_note'] = (string)$policy['message'];
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
        // item is in a sprint only that sprint's Scrum Master applies it, and
        // a change by anyone else becomes an approval request.
        if (array_key_exists('is_adhoc', $input)) {
            $currentAdhoc = (int)($this->fields['is_adhoc'] ?? 0);
            $requestedAdhoc = (int)$input['is_adhoc'] ? 1 : 0;
            if ($requestedAdhoc !== $currentAdhoc) {
                $sprintId = (int)($input['plugin_sprint_sprints_id'] ?? $this->fields['plugin_sprint_sprints_id'] ?? 0);
                if ($sprintId > 0 && !self::currentUserIsScrumMasterOf($sprintId)) {
                    // Only for the sprint the item is in now; a simultaneous
                    // move goes through its own guard.
                    if ($sprintId === (int)($this->fields['plugin_sprint_sprints_id'] ?? 0)) {
                        SprintRequest::createPending(
                            SprintRequest::TYPE_ADHOC,
                            (int)$this->getID(),
                            $sprintId,
                            0.0,
                            trim((string)($input['_adhoc_request_reason'] ?? '')),
                            0,
                            $requestedAdhoc
                        );
                        Session::addMessageAfterRedirect(
                            $requestedAdhoc
                                ? __('Adhoc flag sent to the Scrum Master for approval.', 'sprint')
                                : __('Removing the adhoc flag was sent to the Scrum Master for approval.', 'sprint'),
                            false,
                            INFO
                        );
                    }
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

        if (!$this->validateCustomer($input, (int)$validationInput['plugin_sprint_sprints_id'])) {
            return false;
        }
        if (!$this->validateCapacity($input, (int)($input['id'] ?? 0))) {
            return false;
        }
        if (!self::validateNoDuplicateLink($validationInput, (int)($input['id'] ?? 0))) {
            return false;
        }
        return parent::prepareInputForUpdate($input);
    }

    /**
     * The customer charged on an item must be one the session may see and,
     * once the item sits in a sprint, valid for that sprint's entity. An
     * invalid pick — or a move into a sprint the customer is not valid for —
     * is refused with a message, never silently re-attributed. Only a changed
     * customer or a changed sprint is judged; unrelated edits leave the pair
     * alone. Server-side bookkeeping (carry-over) is judged on the rows.
     */
    private function validateCustomer(array $input, int $sprintId): bool
    {
        $current    = (int)($this->fields['plugin_sprint_sprintcustomers_id'] ?? 0);
        $customerId = (int)($input['plugin_sprint_sprintcustomers_id'] ?? $current);
        if ($customerId <= 0) {
            return true;
        }
        $changed = array_key_exists('plugin_sprint_sprintcustomers_id', $input) && $customerId !== $current;
        $moved   = $sprintId > 0 && $sprintId !== (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
        if (!$changed && !$moved) {
            return true;
        }
        if (SprintCustomer::isChargeableInSprint($customerId, $sprintId, !self::$skipCreditGuard)) {
            return true;
        }
        $name = SprintCustomer::getNameFor($customerId) ?: ('#' . $customerId);
        Session::addMessageAfterRedirect(
            $sprintId > 0
                ? sprintf(__('Customer “%s” is not available in the entity of that sprint.', 'sprint'), $name)
                : __('This customer is not available in your entities.', 'sprint'),
            false,
            ERROR
        );
        return false;
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
        SprintCustomer::invalidateCaches();
        SprintMember::invalidateUsedCapacity();
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
        SprintCustomer::invalidateCaches();
        SprintMember::invalidateUsedCapacity();
        if (!empty($this->input['_dod_override_note'])) {
            \Log::history((int)$this->getID(), self::class, [0, '', (string)$this->input['_dod_override_note']]);
        }
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
     * Tags from a form, reduced to the admin pool (pool casing, de-duplicated).
     *
     * @return array<string,string> lowercased key => pool label
     */
    public static function filterTagsToPool(array $tags): array
    {
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
        return $kept;
    }

    /**
     * The "tag required" setting: a save that carries the tag field must keep
     * at least one pool tag. Saves without the field (status drags, linked
     * item sync, templates, carry-over) are not judged, so automation never
     * stalls on it.
     */
    private static function validateRequiredTags(array $input): bool
    {
        if (!array_key_exists('_tags', $input) || !Config::isTagRequired()) {
            return true;
        }
        if (count(self::filterTagsToPool((array)$input['_tags'])) > 0) {
            return true;
        }
        Session::addMessageAfterRedirect(
            __('Pick at least one tag — tags are required on every item.', 'sprint'),
            false,
            ERROR
        );
        return false;
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
        $kept = self::filterTagsToPool($tags);
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
     *
     * $carryCapacity / $carryCredits split the work: that share moves to the
     * copy and comes off the source, so both sprints add up to the original
     * (5% planned, 2% done → keep 2%, carry 3%). The copy then keeps the owner
     * when they are a member of the target sprint. Without a split (null) the
     * copy starts unowned at 0 and is re-estimated. Fastlane capacity lives
     * on SprintFastlaneMember and is never split here.
     */
    public static function carryOverTo(int $sourceItemId, int $targetSprintId, ?float $carryCapacity = null, ?float $carryCredits = null): int
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

        // The customer travels with the work, but only into a sprint it is
        // valid for; elsewhere the copy lands unattributed, and says so.
        $customerId = (int)($source->fields['plugin_sprint_sprintcustomers_id'] ?? 0);
        if ($customerId > 0 && !SprintCustomer::isChargeableInSprint($customerId, $targetSprintId, false)) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Customer “%s” is not available in the entity of the target sprint. The carried-over item has no customer.', 'sprint'),
                    SprintCustomer::getNameFor($customerId) ?: ('#' . $customerId)
                ),
                false,
                WARNING
            );
            $customerId = 0;
        }

        // The carried share never exceeds what the source holds.
        $isFastlane = (int)($source->fields['is_fastlane'] ?? 0) === 1;
        $split      = $carryCapacity !== null || $carryCredits !== null;
        $capacity   = $isFastlane ? 0.0 : min(
            SprintMember::normalizeCapacity($carryCapacity ?? 0),
            (float)($source->fields['capacity'] ?? 0)
        );
        $credits    = min(
            SprintCustomer::normalizeCredits($carryCredits ?? 0),
            (float)($source->fields['credits'] ?? 0)
        );
        $ownerId    = 0;
        if ($split) {
            $sourceOwner = (int)($source->fields['users_id'] ?? 0);
            if ($sourceOwner > 0 && countElementsInTable(SprintMember::getTable(), [
                'plugin_sprint_sprints_id' => $targetSprintId,
                'users_id'                 => $sourceOwner,
            ]) > 0) {
                $ownerId = $sourceOwner;
            }
        }

        $copy  = new self();
        $newId = self::withoutAssignGuard(static fn() => self::withoutCreditGuard(
            static function () use ($copy, $targetSprintId, $source, $itemtype, $itemsId, $customerId, $ownerId, $capacity, $credits) {
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
                'plugin_sprint_sprintcustomers_id'  => $customerId,
                'users_id'                 => $ownerId,
                // The carried share; 0 without a split, to be re-estimated.
                'capacity'                 => $capacity,
                'credits'                  => $credits,
                'plugin_sprint_sprintcreditproducts_id' => (int)($source->fields['plugin_sprint_sprintcreditproducts_id'] ?? 0),
                'is_fastlane'              => (int)($source->fields['is_fastlane'] ?? 0),
                'is_blocked'               => 0,
                'note'                     => '',
            ]);
            }
        ));

        // What moved on comes off the source. Bookkeeping, not a re-plan: the
        // total is unchanged, so it skips the capacity approval (an AJAX-layer
        // guard) and the credits right, like the copy above does.
        if ($newId > 0 && ($capacity > 0 || $credits > 0)) {
            $rest = ['id' => $sourceItemId];
            if ($capacity > 0) {
                $rest['capacity'] = (float)($source->fields['capacity'] ?? 0) - $capacity;
            }
            if ($credits > 0) {
                $rest['credits'] = (float)($source->fields['credits'] ?? 0) - $credits;
            }
            self::withoutCreditGuard(static fn() => $source->update($rest));
        }

        return $newId;
    }

    /**
     * Carry-over entry point for every UI path. The Scrum Master of the target
     * sprint carries over straight away; anyone else files a 'carryover'
     * request that lands in that sprint's Requests tab. Checks entity access
     * to both sprints; the caller checks the edit right on the item.
     *
     * @return array{ok: bool, requested: bool, new_id: int, message: string}
     */
    public static function carryOverOrRequest(
        int $itemId,
        int $targetSprintId,
        ?float $carryCapacity = null,
        ?float $carryCredits = null,
        string $reason = ''
    ): array {
        $fail = static fn(string $msg) => ['ok' => false, 'requested' => false, 'new_id' => 0, 'message' => $msg];

        $item = new self();
        if ($itemId <= 0 || !$item->getFromDB($itemId) || !$item->hasEntityAccess()) {
            return $fail(__('Request failed', 'sprint'));
        }
        $target = new Sprint();
        if ($targetSprintId <= 0
            || !$target->getFromDB($targetSprintId)
            || !$target->canViewItem()
            || !isset(Sprint::getMoveTargetOptions()[$targetSprintId])) {
            return $fail(__('Please select a sprint', 'sprint'));
        }
        if ((int)($item->fields['plugin_sprint_sprints_id'] ?? 0) === $targetSprintId) {
            return $fail(__('The item is already part of this sprint.', 'sprint'));
        }
        $name = (string)$target->fields['name'];

        if (!self::currentUserIsScrumMasterOf($targetSprintId)) {
            $reqId = SprintRequest::createPending(
                SprintRequest::TYPE_CARRYOVER,
                $itemId,
                $targetSprintId,
                (float)($carryCapacity ?? 0),
                $reason
            );
            if ($reqId <= 0) {
                return $fail(__('Request failed', 'sprint'));
            }
            return [
                'ok'        => true,
                'requested' => true,
                'new_id'    => 0,
                'message'   => sprintf(
                    __('Carry-over requested — the Scrum Master of %s will review it.', 'sprint'),
                    plugin_sprint_html($name)
                ),
            ];
        }

        $newId = self::carryOverTo($itemId, $targetSprintId, $carryCapacity, $carryCredits);
        if ($newId <= 0) {
            return $fail(__('Could not carry the item over to the target sprint', 'sprint'));
        }
        return [
            'ok'        => true,
            'requested' => false,
            'new_id'    => $newId,
            'message'   => sprintf(__('Carried over to %s', 'sprint'), plugin_sprint_html($name)),
        ];
    }

    /**
     * Server-side write that may set customer/credits regardless of the acting
     * user's right — a carry-over must not drop the customer it is charged to.
     */
    public static function withoutCreditGuard(callable $callback)
    {
        self::$skipCreditGuard = true;
        try {
            return $callback();
        } finally {
            self::$skipCreditGuard = false;
        }
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
    private static function sanitizeInput(array $input, ?array $stored = null): array
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
        // An empty customer field means "not sent" (a disabled picker or a
        // selection that never loaded), never "no customer": only an explicit
        // 0 detaches. Validity is judged in validateCustomer().
        if (array_key_exists('plugin_sprint_sprintcustomers_id', $input)) {
            $raw = $input['plugin_sprint_sprintcustomers_id'];
            if ($raw === '' || $raw === null) {
                unset($input['plugin_sprint_sprintcustomers_id']);
            } else {
                $input['plugin_sprint_sprintcustomers_id'] = max(0, (int)$raw);
            }
        }
        if (isset($input['credits']))     $input['credits']     = SprintCustomer::normalizeCredits($input['credits']);
        if (isset($input['plugin_sprint_sprintcreditproducts_id'])) {
            $productId = (int)$input['plugin_sprint_sprintcreditproducts_id'];
            // A catalogue entry outside the session's entities is not usable
            // here; the credits themselves are kept as entered.
            $input['plugin_sprint_sprintcreditproducts_id'] = (self::$skipCreditGuard ? SprintCreditProduct::isVisible($productId) : SprintCreditProduct::isPickable($productId)) || $productId === 0 ? $productId : 0;
            // The product is leading (see leadingCredits()); server-side
            // bookkeeping such as a carry-over sets its own amounts.
            if (!self::$skipCreditGuard) {
                $leading = SprintCreditProduct::leadingCredits($input['plugin_sprint_sprintcreditproducts_id'], $input, $stored);
                if ($leading !== null) {
                    $input['credits'] = $leading;
                }
            }
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
                    $isOwnReq = (int)($pendingReq['users_id'] ?? 0) === (int)Session::getLoginUserID();
                    echo "<div class='alert alert-warning d-flex align-items-center gap-2' style='margin-bottom:10px;'>"
                        . "<i class='fas fa-lock'></i>"
                        . "<span class='flex-grow-1'>" . htmlescape(__('This item is locked while a request is pending Scrum Master approval.', 'sprint')) . "</span>"
                        . ($isOwnReq ? SprintRequest::renderCancelButton((int)$pendingReq['id'], true) : '')
                        . "</div>";
                    if ($isOwnReq) {
                        SprintRequest::renderPanelScript();
                    }
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
        echo "<span class='text-muted'>" . __('Fastlane: urgent work outside the sprint plan. It has its own capacity share and does not count towards the WIP limit. Members are assigned on the Fastlane tab of the sprint.', 'sprint') . "</span>";
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

        // Adhoc: only for items in a sprint. The Scrum Master applies it;
        // anyone else's change becomes an approval request.
        if ($sprintId > 0) {
            $isAdhoc = (int)($this->fields['is_adhoc'] ?? 0) === 1;
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Adhoc', 'sprint') . "</td><td colspan='3'>";
            echo "<input type='hidden' name='is_adhoc' value='0'>";
            echo "<label style='display:inline-flex;align-items:center;gap:6px;'>";
            echo "<input type='checkbox' name='is_adhoc' value='1'" . ($isAdhoc ? ' checked' : '') . ">";
            echo "<span class='text-muted'>" . __('Added to the sprint after kick-off (highlights the item)', 'sprint') . "</span>";
            echo "</label>";
            if (!self::currentUserIsScrumMasterOf($sprintId)) {
                echo "<div class='text-muted sprint-small'><i class='fas fa-user-shield me-1'></i>"
                    . htmlescape(__('Adhoc changes are sent to the Scrum Master for approval.', 'sprint')) . "</div>";
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
            echo "</td><td>" . __('Owner', 'sprint') . "</td><td>";
            Dropdown::showFromArray('users_id', $memberOptions, [
                'value' => $this->fields['users_id'] ?? 0,
            ]);
            echo "</td></tr>";
        }

        if (SprintCustomer::canUseCredits()) {
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . SprintCustomer::getTypeName(1) . "</td><td>";
            echo self::customerSelect(
                'plugin_sprint_sprintcustomers_id',
                (int)($this->fields['plugin_sprint_sprintcustomers_id'] ?? 0)
            );
            echo "</td><td>" . __('Credits', 'sprint') . "<br>";
            echo "<span class='text-muted sprint-small'>"
                . htmlescape(__('Charged to the customer once this item is delivered. Pick a product from the catalogue to fill in its default, or enter the credits by hand.', 'sprint')) . "</span>";
            echo "</td><td>";
            echo "<div class='d-flex flex-wrap gap-2 align-items-center'>";
            echo self::productSelect(
                'plugin_sprint_sprintcreditproducts_id',
                (int)($this->fields['plugin_sprint_sprintcreditproducts_id'] ?? 0),
                'credits',
                'w-auto'
            );
            echo self::creditsInput('credits', $this->fields['credits'] ?? 0);
            echo "</div>";
            self::creditProductScript();
            echo "</td></tr>";
        }

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Sprint') . "</td><td>";
        Sprint::dropdown(['name' => 'plugin_sprint_sprints_id', 'value' => $sprintId]);
        if (!$isFastlane) {
            echo "<div class='text-muted sprint-small'><i class='fas fa-info-circle me-1'></i>"
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
                echo "<div class='text-muted sprint-small'><i class='fas fa-user-shield me-1'></i>"
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
            echo "<tr class='tab_bg_1'><td>" . __('Tags', 'sprint')
                . (Config::isTagRequired() ? " <span class='text-danger' title='" . __s('Required', 'sprint') . "'>*</span>" : '')
                . "</td>";
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

        if (!$isNew && $this->canUpdateItem()) {
            self::renderCarryOverCard($this);
        }

        return true;
    }

    /**
     * "Carry over to sprint" card under the item form and in the planning
     * line on a linked Ticket/Change/Problem/Project task: the way to pick an
     * item up again in a new sprint without opening an old meeting (e.g. a
     * ticket reopened after its sprint was completed). Posts to
     * front/sprintitem.form.php; the target sprint's Scrum Master carries
     * over at once, anyone else files a request.
     */
    public static function renderCarryOverCard(self $item, string $redirect = ''): void
    {
        $itemId   = (int)$item->getID();
        $sprintId = (int)($item->fields['plugin_sprint_sprints_id'] ?? 0);
        if ($itemId <= 0 || $sprintId <= 0) {
            return;
        }
        $targets = Sprint::getMoveTargetOptions($sprintId);
        // A linked GLPI item already in a target sprint is not offered again.
        $itemtype = (string)($item->fields['itemtype'] ?? '');
        $itemsId  = (int)($item->fields['items_id'] ?? 0);
        foreach (array_keys($targets) as $tid) {
            if (self::isLinkedItemInSprint((int)$tid, $itemtype, $itemsId)) {
                unset($targets[$tid]);
            }
        }

        $pending = null;
        foreach (SprintRequest::ensureTable() ? (new SprintRequest())->find([
            'request_type'                 => SprintRequest::TYPE_CARRYOVER,
            'plugin_sprint_sprintitems_id' => $itemId,
            'status'                       => SprintRequest::STATUS_PENDING,
        ], [], 1) : [] as $row) {
            $pending = $row;
        }

        echo "<div class='card mt-3 sprint-carry-card' style='max-width:980px;margin:12px auto;'>";
        echo "<div class='card-body'>";
        echo "<h4 class='card-title mb-1'><i class='fas fa-forward text-success me-1'></i>"
            . __('Carry over to sprint', 'sprint') . "</h4>";
        echo "<p class='text-muted sprint-small mb-2'>"
            . htmlescape(__('A fresh copy is created in the target sprint. The original stays in this sprint.', 'sprint'))
            . "</p>";

        if ($pending !== null) {
            $target = new Sprint();
            $label  = $target->getFromDB((int)$pending['plugin_sprint_sprints_id'])
                ? (string)$target->fields['name'] : ('#' . (int)$pending['plugin_sprint_sprints_id']);
            echo "<div class='d-flex flex-wrap align-items-center gap-2'>";
            echo "<span class='badge bg-warning' style='color:#212529;'><i class='fas fa-hourglass-half me-1'></i>"
                . htmlescape(sprintf(__('Requested — waiting for the Scrum Master of %s', 'sprint'), $label)) . "</span>";
            if ((int)$pending['users_id'] === (int)Session::getLoginUserID()) {
                echo SprintRequest::renderCancelButton((int)$pending['id'], true);
                SprintRequest::renderPanelScript();
            }
            echo "</div></div></div>";
            return;
        }

        if (!$targets) {
            echo "<p class='text-muted mb-0'>" . __('No planned or active sprint to carry this item over to.', 'sprint') . "</p>";
            echo "</div></div>";
            return;
        }

        echo "<form method='post' action='" . self::getFormURL() . "' class='d-flex flex-wrap align-items-end gap-2'>";
        echo Html::hidden('id', ['value' => $itemId]);
        if ($redirect !== '') {
            echo Html::hidden('_redirect', ['value' => $redirect]);
        }
        echo "<div><label class='form-label' for='sprint-carry-target-{$itemId}'>" . __('Target sprint', 'sprint') . "</label>";
        echo "<select class='form-select' name='carry_over_to_sprint_id' id='sprint-carry-target-{$itemId}' required>";
        echo "<option value=''>-----</option>";
        foreach ($targets as $tid => $tlabel) {
            echo "<option value='" . (int)$tid . "'>" . htmlescape((string)$tlabel) . "</option>";
        }
        echo "</select></div>";
        echo "<div class='flex-grow-1' style='min-width:220px;'><label class='form-label' for='sprint-carry-reason-{$itemId}'>"
            . __('Reason', 'sprint') . "</label>";
        echo "<input type='text' class='form-control' name='carry_reason' id='sprint-carry-reason-{$itemId}' "
            . "placeholder='" . __s('e.g. the ticket was reopened', 'sprint') . "'></div>";
        echo "<button type='submit' name='carry_over_item' value='1' class='btn btn-success'>"
            . "<i class='fas fa-forward me-1'></i>" . __('Carry over', 'sprint') . "</button>";
        Html::closeForm();
        echo "<div class='form-text sprint-small text-muted'><i class='fas fa-user-shield me-1'></i>"
            . htmlescape(__('Carry-overs into a sprint you are not Scrum Master of are sent to that sprint\'s Scrum Master for approval.', 'sprint'))
            . "</div>";
        echo "</div></div>";
    }
}
