<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use CommonGLPI;
use Html;
use Plugin;
use Session;

/**
 * Approval request from a non-Scrum-Master, handled by the target sprint's
 * Scrum Master:
 *  - 'assign':   move a backlog item into its pre-selected sprint
 *  - 'capacity': change an item's capacity % inside a sprint
 *  - 'category': change an item's backlog category inside a sprint
 *  - 'adhoc':    set or clear an item's adhoc flag inside a sprint
 *  - 'carryover': carry an item over into the target sprint (a fresh copy)
 * Accepting performs the action; rejecting only closes the request. The
 * requester may cancel their own pending request.
 */
class SprintRequest extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_item';

    const TYPE_ASSIGN   = 'assign';
    const TYPE_CAPACITY = 'capacity';
    const TYPE_CATEGORY = 'category';
    const TYPE_ADHOC    = 'adhoc';
    const TYPE_CARRYOVER = 'carryover';

    /** Every request type this class knows how to create and accept. */
    const ALL_TYPES = [
        self::TYPE_ASSIGN, self::TYPE_CAPACITY, self::TYPE_CATEGORY,
        self::TYPE_ADHOC, self::TYPE_CARRYOVER,
    ];

    const STATUS_PENDING   = 'pending';
    const STATUS_ACCEPTED  = 'accepted';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_CANCELLED = 'cancelled';

    public static function getTypeName($nb = 0): string
    {
        return _n('Sprint request', 'Sprint requests', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-inbox';
    }

    /**
     * "Requests" tab on a Sprint: everyone who can see the sprint sees the
     * pending requests (including their own); only the Scrum Master gets the
     * accept/reject buttons.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Sprint && Backlog::canView()) {
            $count = self::ensureTable()
                ? countElementsInTable(self::getTable(), [
                    'plugin_sprint_sprints_id' => (int)$item->getID(),
                    'status'                   => self::STATUS_PENDING,
                ])
                : 0;
            return self::createTabEntry(__('Requests', 'sprint'), $count);
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
     * Tab content: all pending requests targeting this sprint, visible to
     * every viewer; moderation buttons only for the Scrum Master.
     */
    public static function showForSprint(Sprint $sprint): void
    {
        $sprintId = (int)$sprint->getID();
        $requests = self::ensureTable()
            ? (new self())->find([
                'plugin_sprint_sprints_id' => $sprintId,
                'status'                   => self::STATUS_PENDING,
            ], ['date_creation ASC'])
            : [];

        $isMaster = SprintItem::currentUserIsScrumMasterOf($sprintId);

        echo "<div class='center'>";
        echo "<h3 style='text-align:left;'><i class='fas fa-inbox'></i> " . __('Requests', 'sprint')
            . " <span class='badge bg-secondary'>" . count($requests) . "</span></h3>";
        echo "<p class='text-muted' style='text-align:left;'>"
            . ($isMaster
                ? __('Requests from team members: accepting performs the action (assign to sprint / apply the capacity change).', 'sprint')
                : __('Pending requests for this sprint — the Scrum Master accepts or rejects them. You can cancel your own requests.', 'sprint'))
            . "</p>";

        if (count($requests) === 0) {
            echo "<p class='text-muted'>" . __('No pending requests.', 'sprint') . "</p>";
            echo "</div>";
            return;
        }

        self::renderRequestsTable($requests, $isMaster, false);
        echo "</div>";

        self::renderPanelScript();
    }

    /**
     * Create the requests table on demand: this feature ships within an
     * existing plugin version, so the install/upgrade hook (which also
     * creates it for fresh installs) may never re-run. Cached per request.
     */
    public static function ensureTable(): bool
    {
        global $DB;
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }
        $table = self::getTable(); // glpi_plugin_sprint_sprintrequests

        // An early build briefly created the table under a wrong name that
        // does not match getTable(); adopt it instead of leaving it behind.
        $exists = $DB->tableExists($table);
        if (!$exists && $DB->tableExists('glpi_plugin_sprint_requests')) {
            $exists = (bool)$DB->doQuery(
                "RENAME TABLE `glpi_plugin_sprint_requests` TO `{$table}`"
            );
        }

        if ($exists) {
            // Lazy migration: requester motivation shown to the Scrum Master.
            if (!$DB->fieldExists($table, 'reason')) {
                $DB->doQuery(
                    "ALTER TABLE `{$table}` ADD COLUMN `reason` TEXT NULL "
                    . "COMMENT 'Requester motivation shown to the Scrum Master'"
                );
            }
            // Lazy migration: target category for 'category' requests.
            if (!$DB->fieldExists($table, 'requested_category_id')) {
                $DB->doQuery(
                    "ALTER TABLE `{$table}` ADD COLUMN `requested_category_id` INT UNSIGNED NOT NULL DEFAULT 0 "
                    . "COMMENT 'Requested backlog category for a category request'"
                );
            }
            // Lazy migration: requested on/off value for 'adhoc' requests.
            if (!$DB->fieldExists($table, 'requested_flag')) {
                $DB->doQuery(
                    "ALTER TABLE `{$table}` ADD COLUMN `requested_flag` TINYINT NOT NULL DEFAULT 0 "
                    . "COMMENT 'Requested adhoc flag for an adhoc request'"
                );
            }
            return $ready = true;
        }

        $default_charset   = \DBConnection::getDefaultCharset();
        $default_collation = \DBConnection::getDefaultCollation();
        $query = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id`                           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `request_type`                 VARCHAR(20) NOT NULL DEFAULT 'assign' COMMENT 'assign | capacity | category | adhoc | carryover',
            `plugin_sprint_sprintitems_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_sprint_sprints_id`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Target sprint (assign/carryover) or the item sprint (capacity/category/adhoc)',
            `users_id`                     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Requester',
            `requested_capacity`           DECIMAL(5,1) NOT NULL DEFAULT 0 COMMENT 'Requested capacity, or the share to carry over (0 = none)',
            `requested_category_id`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Requested backlog category for a category request',
            `requested_flag`               TINYINT NOT NULL DEFAULT 0 COMMENT 'Requested adhoc flag for an adhoc request',
            `reason`                       TEXT NULL COMMENT 'Requester motivation shown to the Scrum Master',
            `status`                       VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending | accepted | rejected | cancelled',
            `users_id_validate`            INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Scrum Master who handled it',
            `date_creation`                TIMESTAMP NULL DEFAULT NULL,
            `date_mod`                     TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `item` (`plugin_sprint_sprintitems_id`),
            KEY `sprint_status` (`plugin_sprint_sprints_id`, `status`),
            KEY `requester` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";

        return $ready = (bool)$DB->doQuery($query);
    }

    /**
     * Create a pending request, reusing an identical one instead of stacking
     * duplicates. Returns the request id (existing or new), 0 on failure.
     */
    public static function createPending(
        string $type,
        int $itemId,
        int $sprintId,
        float $requestedCapacity = 0.0,
        string $reason = '',
        int $requestedCategoryId = 0,
        int $requestedFlag = 0
    ): int {
        if ($itemId <= 0 || $sprintId <= 0
            || !in_array($type, self::ALL_TYPES, true)
            || !self::ensureTable()) {
            return 0;
        }
        $reason = trim($reason);
        // Same 0.5% grid as every other capacity write path.
        $requestedCapacity = SprintMember::normalizeCapacity($requestedCapacity);
        $requestedFlag     = $requestedFlag ? 1 : 0;

        $existing = (new self())->find([
            'request_type'                 => $type,
            'plugin_sprint_sprintitems_id' => $itemId,
            'status'                       => self::STATUS_PENDING,
        ]);
        if (count($existing) > 0) {
            // Refresh the pending request instead of duplicating: the target
            // sprint, requested % or reason may have changed since the first click.
            $first = reset($existing);
            (new self())->update([
                'id'                       => (int)$first['id'],
                'plugin_sprint_sprints_id' => $sprintId,
                'requested_capacity'       => $requestedCapacity,
                'requested_category_id'    => $requestedCategoryId,
                'requested_flag'           => $requestedFlag,
                'reason'                   => $reason,
                'users_id'                 => (int)Session::getLoginUserID(),
            ]);
            return (int)$first['id'];
        }

        $req = new self();
        $newId = $req->add([
            'request_type'                 => $type,
            'plugin_sprint_sprintitems_id' => $itemId,
            'plugin_sprint_sprints_id'     => $sprintId,
            'users_id'                     => (int)Session::getLoginUserID(),
            'requested_capacity'           => $requestedCapacity,
            'requested_category_id'        => $requestedCategoryId,
            'requested_flag'               => $requestedFlag,
            'reason'                       => $reason,
            'status'                       => self::STATUS_PENDING,
            'date_creation'                => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
        $sprint = new Sprint();
        if ($newId && $sprint->getFromDB($sprintId)) {
            SprintAgility::signal(
                $sprintId,
                (int)($sprint->fields['users_id'] ?? 0),
                'approval',
                sprintf(__('A new %s request is waiting for approval.', 'sprint'), $type),
                Sprint::getFormURLWithID($sprintId)
            );
        }
        return (int)$newId;
    }

    /**
     * Any pending request row for an item, regardless of type. Used to lock
     * the item against edits by non-Scrum-Masters while the queue is open,
     * so the approval flow cannot be bypassed via the item form.
     */
    public static function getAnyPendingForItem(int $itemId): ?array
    {
        if ($itemId <= 0 || !self::ensureTable()) {
            return null;
        }
        $rows = (new self())->find([
            'plugin_sprint_sprintitems_id' => $itemId,
            'status'                       => self::STATUS_PENDING,
        ], ['date_creation ASC']);
        if (count($rows) === 0) {
            return null;
        }
        return reset($rows);
    }

    /**
     * Pending requests the current user may handle (Scrum Master of the
     * request's sprint), optionally limited to one sprint and/or one type.
     *
     * @return array<int,array> request rows
     */
    public static function getPendingForCurrentMaster(int $sprintId = 0, string $type = ''): array
    {
        if (!self::ensureTable()) {
            return [];
        }
        $criteria = ['status' => self::STATUS_PENDING];
        if ($sprintId > 0) {
            $criteria['plugin_sprint_sprints_id'] = $sprintId;
        }
        if ($type !== '') {
            $criteria['request_type'] = $type;
        }

        $out = [];
        $masterCache = [];
        foreach ((new self())->find($criteria, ['date_creation ASC']) as $row) {
            $sid = (int)$row['plugin_sprint_sprints_id'];
            if (!isset($masterCache[$sid])) {
                // A real sprint id is required here: isCurrentUserScrumMaster()
                // returns true for id 0, which must not leak requests.
                $masterCache[$sid] = $sid > 0 && SprintItem::currentUserIsScrumMasterOf($sid);
            }
            if ($masterCache[$sid]) {
                $out[(int)$row['id']] = $row;
            }
        }
        return $out;
    }

    /**
     * Accept a pending request as the current user (must be Scrum Master of
     * the request's sprint) and perform the requested action.
     *
     * @return array{ok: bool, message: string}
     */
    public static function accept(int $requestId): array
    {
        if (!self::ensureTable()) {
            return ['ok' => false, 'message' => __('Request failed', 'sprint')];
        }
        $req = new self();
        if (!$req->getFromDB($requestId)
            || (string)$req->fields['status'] !== self::STATUS_PENDING) {
            return ['ok' => false, 'message' => __('Request no longer exists', 'sprint')];
        }

        $sprintId = (int)$req->fields['plugin_sprint_sprints_id'];
        if ($sprintId <= 0 || !SprintItem::currentUserIsScrumMasterOf($sprintId)) {
            return ['ok' => false, 'message' => __('Only the Scrum Master of the selected sprint can handle this request.', 'sprint')];
        }

        $item = new SprintItem();
        if (!$item->getFromDB((int)$req->fields['plugin_sprint_sprintitems_id'])) {
            $req->closeAs(self::STATUS_REJECTED);
            return ['ok' => false, 'message' => __('The item behind this request no longer exists.', 'sprint')];
        }

        $type = (string)$req->fields['request_type'];
        if ($type === self::TYPE_ASSIGN) {
            if ((int)$item->fields['plugin_sprint_sprints_id'] !== 0) {
                $req->closeAs(self::STATUS_REJECTED);
                return ['ok' => false, 'message' => __('The item is no longer on the backlog.', 'sprint')];
            }
            if (!$item->update([
                'id'                       => (int)$item->getID(),
                'plugin_sprint_sprints_id' => $sprintId,
                'proposed_sprints_id'      => 0,
            ])) {
                return ['ok' => false, 'message' => __('Could not assign item to sprint', 'sprint')];
            }
            SprintItem::purgeBacklogCoupling(
                (string)($item->fields['itemtype'] ?? ''),
                (int)($item->fields['items_id'] ?? 0),
                (int)$item->getID()
            );
            $req->closeAs(self::STATUS_ACCEPTED);
            return ['ok' => true, 'message' => __('Item assigned to sprint', 'sprint')];
        }

        if ($type === self::TYPE_CATEGORY) {
            if ((int)$item->fields['plugin_sprint_sprints_id'] !== $sprintId) {
                $req->closeAs(self::STATUS_REJECTED);
                return ['ok' => false, 'message' => __('The item is no longer part of this sprint.', 'sprint')];
            }
            $targetCategory = (int)$req->fields['requested_category_id'];
            // A category purged while the request sat in the queue would
            // silently reset the item to "no category"; refuse instead.
            if ($targetCategory > 0 && SprintCategory::getNameFor($targetCategory) === '') {
                $req->closeAs(self::STATUS_REJECTED);
                return ['ok' => false, 'message' => __('The requested category no longer exists.', 'sprint')];
            }
            if (!$item->update([
                'id'                                => (int)$item->getID(),
                'plugin_sprint_sprintcategories_id' => $targetCategory,
            ])) {
                return ['ok' => false, 'message' => __('Could not update the category', 'sprint')];
            }
            $req->closeAs(self::STATUS_ACCEPTED);
            return ['ok' => true, 'message' => sprintf(
                __('Category updated to %s', 'sprint'),
                $targetCategory > 0
                    ? plugin_sprint_html(SprintCategory::getFullNameFor($targetCategory))
                    : __('No category', 'sprint')
            )];
        }

        if ($type === self::TYPE_CAPACITY) {
            if ((int)$item->fields['plugin_sprint_sprints_id'] !== $sprintId) {
                $req->closeAs(self::STATUS_REJECTED);
                return ['ok' => false, 'message' => __('The item is no longer part of this sprint.', 'sprint')];
            }
            if (!$item->update([
                'id'       => (int)$item->getID(),
                'capacity' => (float)$req->fields['requested_capacity'],
            ])) {
                return ['ok' => false, 'message' => __('Could not update the capacity', 'sprint')];
            }
            $req->closeAs(self::STATUS_ACCEPTED);
            return ['ok' => true, 'message' => sprintf(
                __('Capacity updated to %s%%', 'sprint'),
                SprintMember::formatCapacity($req->fields['requested_capacity'])
            )];
        }

        if ($type === self::TYPE_ADHOC) {
            if ((int)$item->fields['plugin_sprint_sprints_id'] !== $sprintId) {
                $req->closeAs(self::STATUS_REJECTED);
                return ['ok' => false, 'message' => __('The item is no longer part of this sprint.', 'sprint')];
            }
            $flag = (int)$req->fields['requested_flag'] === 1 ? 1 : 0;
            if (!$item->update(['id' => (int)$item->getID(), 'is_adhoc' => $flag])) {
                return ['ok' => false, 'message' => __('Could not update the item', 'sprint')];
            }
            $req->closeAs(self::STATUS_ACCEPTED);
            return ['ok' => true, 'message' => $flag
                ? __('Item marked as adhoc', 'sprint')
                : __('Adhoc flag removed', 'sprint')];
        }

        if ($type === self::TYPE_CARRYOVER) {
            if ((int)$item->fields['plugin_sprint_sprints_id'] === $sprintId) {
                $req->closeAs(self::STATUS_REJECTED);
                return ['ok' => false, 'message' => __('The item is already part of this sprint.', 'sprint')];
            }
            $share = (float)$req->fields['requested_capacity'];
            $newId = SprintItem::carryOverTo((int)$item->getID(), $sprintId, $share > 0 ? $share : null, null);
            if ($newId <= 0) {
                return ['ok' => false, 'message' => __('Could not carry the item over to the target sprint', 'sprint')];
            }
            $req->closeAs(self::STATUS_ACCEPTED);
            $sprint = new Sprint();
            return ['ok' => true, 'message' => sprintf(
                __('Carried over to %s', 'sprint'),
                $sprint->getFromDB($sprintId) ? plugin_sprint_html((string)$sprint->fields['name']) : ('#' . $sprintId)
            )];
        }

        return ['ok' => false, 'message' => __('Unknown request type', 'sprint')];
    }

    /** Withdraw a pending request (its requester only). */
    public static function cancel(int $requestId): array
    {
        if (!self::ensureTable()) {
            return ['ok' => false, 'message' => __('Request failed', 'sprint')];
        }
        $req = new self();
        if (!$req->getFromDB($requestId)
            || (string)$req->fields['status'] !== self::STATUS_PENDING) {
            return ['ok' => false, 'message' => __('Request no longer exists', 'sprint')];
        }
        if ((int)$req->fields['users_id'] !== (int)Session::getLoginUserID()) {
            return ['ok' => false, 'message' => __('Only the requester can cancel this request.', 'sprint')];
        }
        $req->closeAs(self::STATUS_CANCELLED);
        return ['ok' => true, 'message' => __('Request cancelled', 'sprint')];
    }

    /** Reject a pending request (Scrum Master of the request's sprint only). */
    public static function reject(int $requestId): array
    {
        if (!self::ensureTable()) {
            return ['ok' => false, 'message' => __('Request failed', 'sprint')];
        }
        $req = new self();
        if (!$req->getFromDB($requestId)
            || (string)$req->fields['status'] !== self::STATUS_PENDING) {
            return ['ok' => false, 'message' => __('Request no longer exists', 'sprint')];
        }
        $sprintId = (int)$req->fields['plugin_sprint_sprints_id'];
        if ($sprintId <= 0 || !SprintItem::currentUserIsScrumMasterOf($sprintId)) {
            return ['ok' => false, 'message' => __('Only the Scrum Master of the selected sprint can handle this request.', 'sprint')];
        }
        $req->closeAs(self::STATUS_REJECTED);
        return ['ok' => true, 'message' => __('Request rejected', 'sprint')];
    }

    /**
     * Close pending assign requests for an item that just entered a sprint
     * through any path (direct assign, bulk assign, request accept):
     * accepted when it landed in the requested sprint, rejected otherwise.
     */
    public static function closePendingAssignForItem(int $itemId, int $assignedSprintId): void
    {
        if (!self::ensureTable()) {
            return;
        }
        foreach ((new self())->find([
            'request_type'                 => self::TYPE_ASSIGN,
            'plugin_sprint_sprintitems_id' => $itemId,
            'status'                       => self::STATUS_PENDING,
        ]) as $row) {
            $req = new self();
            if ($req->getFromDB((int)$row['id'])) {
                $req->closeAs(
                    (int)$row['plugin_sprint_sprints_id'] === $assignedSprintId
                        ? self::STATUS_ACCEPTED
                        : self::STATUS_REJECTED
                );
            }
        }
    }

    private function closeAs(string $status): void
    {
        $this->update([
            'id'                => (int)$this->getID(),
            'status'            => $status,
            'users_id_validate' => (int)Session::getLoginUserID(),
            'date_mod'          => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
        SprintAgility::resolveApprovalSignals((int)($this->fields['plugin_sprint_sprints_id'] ?? 0));
    }

    /**
     * Pending-requests panel for the current Scrum Master. Rendered on the
     * backlog page (all sprints the user masters) and on a sprint's items tab
     * ($sprintId > 0: that sprint only). Renders nothing when there is
     * nothing to approve.
     */
    public static function renderPanel(int $sprintId = 0): void
    {
        $requests = self::getPendingForCurrentMaster($sprintId);
        if (count($requests) === 0) {
            return;
        }

        echo "<div class='sprint-request-panel' style='margin:18px 0;border:1px solid #f1c40f;border-radius:8px;overflow:hidden;'>";
        echo "<div style='display:flex;align-items:center;gap:8px;padding:10px 14px;"
            . "background:color-mix(in srgb,#f1c40f 18%,var(--tblr-bg-surface,#fff));font-weight:700;'>";
        echo "<i class='fas fa-inbox'></i>";
        echo "<span>" . __('Requests awaiting your approval', 'sprint') . "</span>";
        echo "<span class='badge bg-warning' style='color:#212529;'>" . count($requests) . "</span>";
        echo "</div>";

        self::renderRequestsTable($requests, true, true);
        echo "</div>";

        self::renderPanelScript();
    }

    /**
     * Shared requests table: used by the Scrum Master's approval panel
     * (backlog page) and the Sprint "Requests" tab (all viewers).
     *
     * @param array $requests    Request rows to render
     * @param bool  $canModerate Render accept/reject buttons
     * @param bool  $showSprint  Include the sprint column (multi-sprint lists)
     */
    private static function renderRequestsTable(array $requests, bool $canModerate, bool $showSprint): void
    {
        $sprintNames = [];
        // Requesters withdraw their own requests; the column shows when
        // there is anything to do in it.
        $me        = (int)Session::getLoginUserID();
        $hasOwn    = count(array_filter($requests, static fn($r) => (int)$r['users_id'] === $me)) > 0;
        $showActions = $canModerate || $hasOwn;

        echo "<table class='tab_cadre_fixe sprint-themed' style='margin:0;'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Type', 'sprint') . "</th>";
        echo "<th>" . __('Item', 'sprint') . "</th>";
        if ($showSprint) {
            echo "<th>" . __('Sprint') . "</th>";
        }
        echo "<th>" . __('Requested by', 'sprint') . "</th>";
        echo "<th style='width:170px;'>" . __('Requested on', 'sprint') . "</th>";
        echo "<th>" . __('Details', 'sprint') . "</th>";
        if ($showActions) {
            echo "<th style='width:150px;'>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        foreach ($requests as $row) {
            $item     = new SprintItem();
            $itemName = $item->getFromDB((int)$row['plugin_sprint_sprintitems_id'])
                ? (string)$item->fields['name']
                : ('#' . (int)$row['plugin_sprint_sprintitems_id']);

            $sid = (int)$row['plugin_sprint_sprints_id'];
            if ($showSprint && !isset($sprintNames[$sid])) {
                $sprint = new Sprint();
                $sprintNames[$sid] = $sprint->getFromDB($sid)
                    ? (string)$sprint->fields['name']
                    : ('#' . $sid);
            }

            $type    = (string)$row['request_type'];
            $details = self::describeChange($row, $item);
            $details = $details !== '' ? $details : '-';

            echo "<tr class='tab_bg_1 sprint-request-row' data-request-id='" . (int)$row['id'] . "'>";
            // The request is not applied yet: say so next to the type, so a
            // requester never reads the proposed value as the current one.
            echo "<td>" . htmlescape(self::getTypeLabel($type))
                . "<div>" . self::renderWaitingLabel() . "</div></td>";
            echo "<td><a href='" . SprintItem::getFormURLWithID((int)$row['plugin_sprint_sprintitems_id']) . "'>"
                . htmlescape($itemName) . "</a></td>";
            if ($showSprint) {
                echo "<td>" . htmlescape($sprintNames[$sid]) . "</td>";
            }
            echo "<td>" . htmlescape(SprintCache::userName((int)$row['users_id'])) . "</td>";
            // Age hint so a request that has been waiting for days stands out.
            $created = (string)($row['date_creation'] ?? '');
            echo "<td style='white-space:nowrap;'>";
            if ($created !== '') {
                echo Html::convDateTime($created);
                $age = self::formatRequestAge($created);
                if ($age !== '') {
                    echo "<div class='text-muted sprint-small'>" . htmlescape($age) . "</div>";
                }
            } else {
                echo "<span class='text-muted'>-</span>";
            }
            echo "</td>";
            // Requester motivation under the technical details, so the Scrum
            // Master can judge the request without opening the item.
            $reason = trim((string)($row['reason'] ?? ''));
            echo "<td>" . htmlescape($details)
                . ($reason !== ''
                    ? "<div class='text-muted sprint-small fst-italic'>"
                        . "<i class='fas fa-comment-dots me-1'></i>" . htmlescape($reason) . "</div>"
                    : "")
                . "</td>";
            if ($showActions) {
                echo "<td class='center' style='white-space:nowrap;'>";
                if ($canModerate) {
                    echo "<button type='button' class='btn btn-sm btn-success sprint-request-accept me-1' "
                        . "data-request-id='" . (int)$row['id'] . "' title='" . __s('Accept', 'sprint') . "' "
                        . "aria-label='" . __s('Accept', 'sprint') . ': ' . htmlescape($itemName) . "'>"
                        . "<i class='fas fa-check'></i></button>";
                    echo "<button type='button' class='btn btn-sm btn-outline-danger sprint-request-reject me-1' "
                        . "data-request-id='" . (int)$row['id'] . "' title='" . __s('Reject', 'sprint') . "' "
                        . "aria-label='" . __s('Reject', 'sprint') . ': ' . htmlescape($itemName) . "'>"
                        . "<i class='fas fa-times'></i></button>";
                }
                if ((int)$row['users_id'] === $me) {
                    echo self::renderCancelButton((int)$row['id']);
                }
                echo "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }

    /** "Cancel request" button for the requester (needs renderPanelScript()). */
    public static function renderCancelButton(int $requestId, bool $withLabel = false): string
    {
        return "<button type='button' class='btn btn-sm btn-outline-secondary sprint-request-cancel' "
            . "data-request-id='" . $requestId . "' title='" . __s('Cancel request', 'sprint') . "' "
            . "aria-label='" . __s('Cancel request', 'sprint') . "'>"
            . "<i class='fas fa-undo'></i>" . ($withLabel ? ' ' . __s('Cancel request', 'sprint') : '') . "</button>";
    }

    /**
     * "Current → requested" for one request row ('' when the type has no
     * value to compare, e.g. a plain assign). $item is the request's item,
     * already loaded; its fields hold the value that is still in force.
     */
    public static function describeChange(array $row, SprintItem $item): string
    {
        $type = (string)($row['request_type'] ?? '');
        $sid  = (int)($row['plugin_sprint_sprints_id'] ?? 0);

        if ($type === self::TYPE_CAPACITY) {
            return sprintf(
                __('%1$s%% → %2$s%%', 'sprint'),
                SprintMember::formatCapacity($item->fields['capacity'] ?? 0),
                SprintMember::formatCapacity($row['requested_capacity'] ?? 0)
            );
        }
        if ($type === self::TYPE_CATEGORY) {
            $noCategory = __('No category', 'sprint');
            $currentCat = (int)($item->fields['plugin_sprint_sprintcategories_id'] ?? 0);
            $targetCat  = (int)($row['requested_category_id'] ?? 0);
            return ($currentCat > 0 ? SprintCategory::getFullNameFor($currentCat) : $noCategory)
                . ' → ' . ($targetCat > 0 ? SprintCategory::getFullNameFor($targetCat) : $noCategory);
        }
        if ($type === self::TYPE_ADHOC) {
            return (int)($row['requested_flag'] ?? 0) === 1
                ? __('Mark as adhoc', 'sprint')
                : __('Remove adhoc flag', 'sprint');
        }
        if ($type === self::TYPE_CARRYOVER || $type === self::TYPE_ASSIGN) {
            $from = new Sprint();
            $fromName = $from->getFromDB((int)($item->fields['plugin_sprint_sprints_id'] ?? 0))
                ? (string)$from->fields['name'] : __('Backlog', 'sprint');
            $to = new Sprint();
            $details = $fromName . ' → ' . ($to->getFromDB($sid) ? (string)$to->fields['name'] : ('#' . $sid));
            if ($type === self::TYPE_CARRYOVER && (float)($row['requested_capacity'] ?? 0) > 0) {
                $details .= ' (' . SprintMember::formatCapacity($row['requested_capacity']) . '%)';
            }
            return $details;
        }
        return '';
    }

    /** Human label for a request type. */
    public static function getTypeLabel(string $type): string
    {
        $labels = [
            self::TYPE_ASSIGN    => __('Assign to sprint', 'sprint'),
            self::TYPE_CAPACITY  => __('Capacity change', 'sprint'),
            self::TYPE_CATEGORY  => __('Category change', 'sprint'),
            self::TYPE_ADHOC     => __('Adhoc', 'sprint'),
            self::TYPE_CARRYOVER => __('Carry over', 'sprint'),
        ];
        return $labels[$type] ?? $type;
    }

    /**
     * The one wording for a request that is not applied yet. Inline colours:
     * `.text-dark` and friends flip in GLPI's dark palettes.
     */
    public static function renderWaitingLabel(): string
    {
        return "<span class='sprint-request-waiting sprint-small' "
            . "style='display:inline-flex;align-items:center;gap:4px;padding:1px 8px;border-radius:10px;"
            . "background:#fff3cd;color:#664d03;border:1px solid #ffe69c;font-weight:600;'>"
            . "<i class='fas fa-hourglass-half' aria-hidden='true'></i>"
            . __s('Requested — waiting for Scrum Master', 'sprint') . "</span>";
    }

    /**
     * Pending requests on one item, for the quick-edit dialogs: what was
     * asked, current → requested value, and whether the current user filed
     * it (and may cancel it). 'locked' tells the dialog that the current user
     * cannot save the item until the Scrum Master has decided.
     *
     * @return array{requests: list<array{id:int,type:string,label:string,change:string,own:bool}>, locked: bool}
     */
    public static function describePendingForItem(int $itemId): array
    {
        $out = ['requests' => [], 'locked' => false];
        if ($itemId <= 0 || !self::ensureTable()) {
            return $out;
        }
        $item = new SprintItem();
        if (!$item->getFromDB($itemId)) {
            return $out;
        }
        $me = (int)Session::getLoginUserID();
        foreach ((new self())->find([
            'plugin_sprint_sprintitems_id' => $itemId,
            'status'                       => self::STATUS_PENDING,
        ], ['date_creation ASC']) as $row) {
            $type = (string)$row['request_type'];
            $out['requests'][] = [
                'id'     => (int)$row['id'],
                'type'   => $type,
                // Plain text: the browser escapes it wherever it lands in HTML.
                'label'  => self::getTypeLabel($type),
                'change' => plugin_sprint_text(self::describeChange($row, $item)),
                'own'    => (int)$row['users_id'] === $me,
            ];
            // Same rule as SprintItem::prepareInputForUpdate()'s queue lock.
            $sid = (int)$row['plugin_sprint_sprints_id'];
            if ($sid > 0 && !SprintItem::currentUserIsScrumMasterOf($sid)) {
                $out['locked'] = true;
            }
        }
        return $out;
    }

    /**
     * "Requested" badge per item id for a list of rows (one query), so a list
     * shows which items carry a pending change that is not applied yet.
     *
     * @param int[] $itemIds
     * @return array<int,string> item id => badge HTML
     */
    public static function pendingBadgesForItems(array $itemIds): array
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds)));
        if (!$itemIds || !self::ensureTable()) {
            return [];
        }
        $byItem = [];
        foreach ((new self())->find([
            'plugin_sprint_sprintitems_id' => $itemIds,
            'status'                       => self::STATUS_PENDING,
        ]) as $row) {
            $byItem[(int)$row['plugin_sprint_sprintitems_id']][] = self::getTypeLabel((string)$row['request_type']);
        }
        $out = [];
        foreach ($byItem as $id => $labels) {
            $out[$id] = self::renderPendingBadge(implode(', ', array_unique($labels)));
        }
        return $out;
    }

    /** Compact row badge for an item with a pending request. */
    public static function renderPendingBadge(string $detail = ''): string
    {
        $title = __('Requested — waiting for Scrum Master', 'sprint') . ($detail !== '' ? ': ' . $detail : '');
        return " <span class='sprint-request-pending-badge sprint-small' title='" . htmlescape($title) . "' "
            . "style='display:inline-flex;align-items:center;gap:3px;padding:0 6px;border-radius:8px;"
            . "background:#fff3cd;color:#664d03;border:1px solid #ffe69c;font-weight:600;white-space:nowrap;'>"
            . "<i class='fas fa-hourglass-half' aria-hidden='true'></i>" . __s('Requested', 'sprint')
            . "<span class='visually-hidden'>: " . htmlescape($title) . "</span></span>";
    }

    /** Human-readable age ("2 hours ago"); '' when the date can't be parsed. */
    private static function formatRequestAge(string $datetime): string
    {
        try {
            $then = new \DateTimeImmutable($datetime);
            $now  = new \DateTimeImmutable($_SESSION['glpi_currenttime'] ?? 'now');
        } catch (\Exception $e) {
            return '';
        }

        $seconds = $now->getTimestamp() - $then->getTimestamp();
        if ($seconds < 0) {
            return '';
        }
        if ($seconds < 3600) {
            $mins = (int)floor($seconds / 60);
            return sprintf(_n('%d minute ago', '%d minutes ago', $mins, 'sprint'), $mins);
        }
        if ($seconds < 86400) {
            $hours = (int)floor($seconds / 3600);
            return sprintf(_n('%d hour ago', '%d hours ago', $hours, 'sprint'), $hours);
        }
        $days = (int)floor($seconds / 86400);
        return sprintf(_n('%d day ago', '%d days ago', $days, 'sprint'), $days);
    }

    /**
     * One-time JS for the accept/reject/cancel buttons (idempotent per page).
     * Same feedback pattern as the quick-edit dialogs: saving state on the
     * button, the error next to it, a short toast on success. A list reload
     * keeps the scroll position and filters; a cancel from inside the
     * quick-edit dialog updates the dialog instead of reloading.
     */
    public static function renderPanelScript(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        $handleUrl  = Plugin::getWebDir('sprint') . '/ajax/requesthandle.php';
        $tokenUrl   = Plugin::getWebDir('sprint') . '/ajax/csrftoken.php';
        $msgFailed  = __s('Could not handle the request.', 'sprint');
        $msgNetwork = __s('Could not reach the server. Try again.', 'sprint');

        SprintItem::renderUiHelpersScript();

        echo <<<HTML
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }
    if (window.__sprintRequestPanelBound) { return; }
    window.__sprintRequestPanelBound = true;

    function toast(msg, level) {
        if (window.sprintUi) { window.sprintUi.toast(msg, level); return; }
        var fn = level === 'error' ? window.glpi_toast_error : window.glpi_toast_info;
        if (typeof fn === 'function') { fn(msg); }
    }

    function handle(action, btn) {
        var \$btn = jQuery(btn);
        var reqId = parseInt(\$btn.data('request-id'), 10) || 0;
        if (reqId <= 0) { return; }
        var \$scope = \$btn.closest('td, li, .sprint-qe-pending');
        var \$siblings = \$scope.find('button').not(\$btn);
        \$scope.find('.sprint-request-error').remove();
        \$siblings.prop('disabled', true);
        if (window.sprintUi) { window.sprintUi.busy(\$btn, true, ''); } else { \$btn.prop('disabled', true); }
        function restore() {
            \$siblings.prop('disabled', false);
            if (window.sprintUi) { window.sprintUi.busy(\$btn, false); } else { \$btn.prop('disabled', false); }
        }
        function fail(msg) {
            restore();
            \$scope.append(jQuery('<div class="text-danger sprint-small sprint-request-error" role="alert"></div>').text(msg));
            toast(msg, 'error');
        }
        jQuery.ajax({ url: "{$tokenUrl}", type: 'GET', dataType: 'json', cache: false })
        .then(function(tok){
            return jQuery.ajax({
                url: "{$handleUrl}", type: 'POST', dataType: 'json',
                data: { id: reqId, request_action: action,
                        _glpi_csrf_token: tok && tok.token ? tok.token : '' }
            });
        }).done(function(resp){
            if (!(resp && resp.success)) {
                fail((resp && resp.message) || "{$msgFailed}");
                return;
            }
            toast(resp.message, 'success');
            // Inside the quick-edit dialog: drop the line, unlock when empty.
            var \$box = \$btn.closest('.sprint-qe-pending');
            if (\$box.length) {
                \$btn.closest('li').remove();
                if (!\$box.find('li').length) {
                    \$box.hide().empty();
                    \$box.closest('.modal').find('.sprint-qe-save').prop('disabled', false).removeAttr('aria-disabled');
                    var itemId = \$box.closest('.modal').find('input[name=id]').val();
                    jQuery('[data-item-id="' + itemId + '"] .sprint-request-pending-badge').remove();
                }
                return;
            }
            if (window.sprintUi) { window.sprintUi.reload(); } else { window.location.reload(); }
        }).fail(function(){
            fail("{$msgNetwork}");
        });
    }

    jQuery(document).on('click', '.sprint-request-accept', function(){ handle('accept', this); });
    jQuery(document).on('click', '.sprint-request-reject', function(){ handle('reject', this); });
    jQuery(document).on('click', '.sprint-request-cancel', function(){ handle('cancel', this); });
})();
</script>
HTML;
    }

    /**
     * Shared "reason for the request" modal (mirrors the back-to-backlog
     * reason dialog). Exposes window.sprintRequestReason(callback): opens the
     * modal, requires a non-empty reason and invokes callback(reason) on
     * confirm; dismissing the modal aborts (callback never fires).
     * Idempotent per page render; safe across GLPI ajax-tab reloads.
     */
    public static function renderReasonModalUI(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        $title     = __s('Request approval', 'sprint');
        $lblReason = __s('Reason', 'sprint');
        $phReason  = __s('Why should the Scrum Master approve this?', 'sprint');
        $errReason = __s('Please enter a reason.', 'sprint');
        $lblCancel = __s('Cancel');
        $lblSend   = __s('Send request', 'sprint');
        $lblClose  = __s('Close');
        $intro     = __s('Your change is not applied yet: the Scrum Master accepts or rejects it. Until then the item shows "Requested — waiting for Scrum Master".', 'sprint');

        SprintItem::renderUiHelpersScript();

        echo <<<HTML
<div class="modal fade sprint-dialog" id="sprint-request-reason-modal" tabindex="-1" aria-hidden="true" aria-labelledby="sprint-reqreason-heading" aria-describedby="sprint-reqreason-intro">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sprint-reqreason-heading"><i class="fas fa-paper-plane me-1" aria-hidden="true"></i> {$title}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{$lblClose}"></button>
      </div>
      <div class="modal-body">
        <p class="sprint-small text-muted" id="sprint-reqreason-intro">{$intro}</p>
        <label class="form-label fw-bold" for="sprint-reqreason-text">{$lblReason}</label>
        <textarea name="reason" id="sprint-reqreason-text" class="form-control" rows="3" placeholder="{$phReason}" data-sprint-autofocus="1" aria-describedby="sprint-reqreason-err"></textarea>
        <div class="text-danger sprint-small mt-1 sprint-reqreason-error" id="sprint-reqreason-err" role="alert" style="display:none;">{$errReason}</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$lblCancel}</button>
        <button type="button" class="btn btn-primary sprint-reqreason-confirm">
          <i class="fas fa-paper-plane me-1" aria-hidden="true"></i> {$lblSend}
        </button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }

    // Move the modal to <body> so it stacks above open modals; a GLPI ajax-tab
    // reload re-echoes it, so drop any stale copy from an earlier load first.
    jQuery(function(){
        // [id=…] rather than #…: an id lookup stops at the first match.
        var all = jQuery('[id=sprint-request-reason-modal]');
        if (all.length > 1) { all.slice(0, all.length - 1).remove(); }
        var last = jQuery('[id=sprint-request-reason-modal]').last();
        if (last.length && !last.parent().is('body')) { last.detach().appendTo('body'); }
    });

    if (window.__sprintReqReasonBound) { return; }
    window.__sprintReqReasonBound = true;

    window.sprintRequestReason = function(callback){
        var m = jQuery('[id=sprint-request-reason-modal]').last();
        if (!m.length) { callback(''); return; }
        m.find('textarea[name=reason]').val('').removeClass('is-invalid').removeAttr('aria-invalid');
        m.find('.sprint-reqreason-error').hide();
        m.data('cb', callback);
        // Back to the action that asked (the Save button underneath).
        m[0].__sprintReturnFocus = document.activeElement;
        bootstrap.Modal.getOrCreateInstance(m[0]).show();
    };

    jQuery(document).on('click', '.sprint-reqreason-confirm', function(){
        var m = jQuery(this).closest('#sprint-request-reason-modal');
        var reason = (m.find('textarea[name=reason]').val() || '').trim();
        if (reason === '') {
            m.find('textarea[name=reason]').addClass('is-invalid').attr('aria-invalid', 'true').trigger('focus');
            m.find('.sprint-reqreason-error').show();
            return;
        }
        var cb = m.data('cb');
        m.removeData('cb');
        bootstrap.Modal.getOrCreateInstance(m[0]).hide();
        if (typeof cb === 'function') { cb(reason); }
    });
})();
</script>
HTML;
    }
}
