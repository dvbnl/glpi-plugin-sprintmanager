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
 * Accepting performs the action; rejecting only closes the request.
 */
class SprintRequest extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_item';

    const TYPE_ASSIGN   = 'assign';
    const TYPE_CAPACITY = 'capacity';

    const STATUS_PENDING  = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';

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
                : __('Pending requests for this sprint — the Scrum Master accepts or rejects them.', 'sprint'))
            . "</p>";

        if (count($requests) === 0) {
            echo "<p class='text-muted'>" . __('No pending requests.', 'sprint') . "</p>";
            echo "</div>";
            return;
        }

        self::renderRequestsTable($requests, $isMaster, false);
        echo "</div>";

        if ($isMaster) {
            self::renderPanelScript();
        }
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
            return $ready = true;
        }

        $default_charset   = \DBConnection::getDefaultCharset();
        $default_collation = \DBConnection::getDefaultCollation();
        $query = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id`                           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `request_type`                 VARCHAR(20) NOT NULL DEFAULT 'assign' COMMENT 'assign | capacity',
            `plugin_sprint_sprintitems_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_sprint_sprints_id`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Target sprint (assign) or the item sprint (capacity)',
            `users_id`                     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Requester',
            `requested_capacity`           DECIMAL(5,1) NOT NULL DEFAULT 0,
            `reason`                       TEXT NULL COMMENT 'Requester motivation shown to the Scrum Master',
            `status`                       VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending | accepted | rejected',
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
        string $reason = ''
    ): int {
        if ($itemId <= 0 || $sprintId <= 0
            || !in_array($type, [self::TYPE_ASSIGN, self::TYPE_CAPACITY], true)
            || !self::ensureTable()) {
            return 0;
        }
        $reason = trim($reason);
        // Same 0.5% grid as every other capacity write path.
        $requestedCapacity = SprintMember::normalizeCapacity($requestedCapacity);

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
            'reason'                       => $reason,
            'status'                       => self::STATUS_PENDING,
            'date_creation'                => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
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

    /** Pending request row for an item+type, or null. */
    public static function getPendingForItem(int $itemId, string $type): ?array
    {
        if ($itemId <= 0 || !self::ensureTable()) {
            return null;
        }
        $rows = (new self())->find([
            'request_type'                 => $type,
            'plugin_sprint_sprintitems_id' => $itemId,
            'status'                       => self::STATUS_PENDING,
        ]);
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

        return ['ok' => false, 'message' => __('Unknown request type', 'sprint')];
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
        echo "<span class='badge bg-warning text-dark'>" . count($requests) . "</span>";
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
        $typeLabels  = [
            self::TYPE_ASSIGN   => __('Assign to sprint', 'sprint'),
            self::TYPE_CAPACITY => __('Capacity change', 'sprint'),
        ];

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
        if ($canModerate) {
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

            $type = (string)$row['request_type'];
            $details = '-';
            if ($type === self::TYPE_CAPACITY) {
                $current = $item->fields['capacity'] ?? 0;
                $details = sprintf(
                    __('%1$s%% → %2$s%%', 'sprint'),
                    SprintMember::formatCapacity($current),
                    SprintMember::formatCapacity($row['requested_capacity'])
                );
            }

            echo "<tr class='tab_bg_1 sprint-request-row' data-request-id='" . (int)$row['id'] . "'>";
            echo "<td>" . htmlescape($typeLabels[$type] ?? $type) . "</td>";
            echo "<td><a href='" . SprintItem::getFormURLWithID((int)$row['plugin_sprint_sprintitems_id']) . "'>"
                . htmlescape($itemName) . "</a></td>";
            if ($showSprint) {
                echo "<td>" . htmlescape($sprintNames[$sid]) . "</td>";
            }
            echo "<td>" . htmlescape(getUserName((int)$row['users_id'])) . "</td>";
            // Age hint so a request that has been waiting for days stands out.
            $created = (string)($row['date_creation'] ?? '');
            echo "<td style='white-space:nowrap;'>";
            if ($created !== '') {
                echo Html::convDateTime($created);
                $age = self::formatRequestAge($created);
                if ($age !== '') {
                    echo "<div class='text-muted small'>" . htmlescape($age) . "</div>";
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
                    ? "<div class='text-muted small fst-italic'>"
                        . "<i class='fas fa-comment-dots me-1'></i>" . htmlescape($reason) . "</div>"
                    : "")
                . "</td>";
            if ($canModerate) {
                echo "<td class='center' style='white-space:nowrap;'>";
                echo "<button type='button' class='btn btn-sm btn-success sprint-request-accept me-1' "
                    . "data-request-id='" . (int)$row['id'] . "' title='" . __s('Accept', 'sprint') . "'>"
                    . "<i class='fas fa-check'></i></button>";
                echo "<button type='button' class='btn btn-sm btn-outline-danger sprint-request-reject' "
                    . "data-request-id='" . (int)$row['id'] . "' title='" . __s('Reject', 'sprint') . "'>"
                    . "<i class='fas fa-times'></i></button>";
                echo "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
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

    /** One-time JS for the accept/reject buttons (idempotent per page). */
    private static function renderPanelScript(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        $handleUrl = Plugin::getWebDir('sprint') . '/ajax/requesthandle.php';
        $tokenUrl  = Plugin::getWebDir('sprint') . '/ajax/csrftoken.php';

        echo <<<HTML
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }

    function handle(action, btn) {
        var \$btn = jQuery(btn);
        var reqId = parseInt(\$btn.data('request-id'), 10) || 0;
        if (reqId <= 0) { return; }
        \$btn.closest('td').find('button').prop('disabled', true);
        jQuery.ajax({ url: "{$tokenUrl}", type: 'GET', dataType: 'json', cache: false })
        .then(function(tok){
            return jQuery.ajax({
                url: "{$handleUrl}", type: 'POST', dataType: 'json',
                data: { id: reqId, request_action: action,
                        _glpi_csrf_token: tok && tok.token ? tok.token : '' }
            });
        }).done(function(resp){
            if (resp && resp.success) {
                if (window.glpi_toast_info) { window.glpi_toast_info(resp.message || 'OK'); }
                window.location.reload();
            } else {
                if (window.glpi_toast_error) { window.glpi_toast_error((resp && resp.message) || 'Failed'); }
                \$btn.closest('td').find('button').prop('disabled', false);
            }
        }).fail(function(){
            if (window.glpi_toast_error) { window.glpi_toast_error('Network error'); }
            \$btn.closest('td').find('button').prop('disabled', false);
        });
    }

    jQuery(document).on('click', '.sprint-request-accept', function(){ handle('accept', this); });
    jQuery(document).on('click', '.sprint-request-reject', function(){ handle('reject', this); });
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

        echo <<<HTML
<div class="modal fade" id="sprint-request-reason-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-paper-plane me-1"></i> {$title}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <label class="form-label fw-bold">{$lblReason}</label>
        <textarea name="reason" class="form-control" rows="3" placeholder="{$phReason}"></textarea>
        <div class="text-danger small mt-1 sprint-reqreason-error" style="display:none;">{$errReason}</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$lblCancel}</button>
        <button type="button" class="btn btn-primary sprint-reqreason-confirm">
          <i class="fas fa-paper-plane me-1"></i> {$lblSend}
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
        var all = jQuery('#sprint-request-reason-modal, body > #sprint-request-reason-modal');
        if (all.length > 1) { all.slice(0, all.length - 1).remove(); }
        var last = jQuery('#sprint-request-reason-modal').last();
        if (last.length && !last.parent().is('body')) { last.detach().appendTo('body'); }
    });

    if (window.__sprintReqReasonBound) { return; }
    window.__sprintReqReasonBound = true;

    window.sprintRequestReason = function(callback){
        var m = jQuery('#sprint-request-reason-modal').last();
        if (!m.length) { callback(''); return; }
        m.find('textarea[name=reason]').val('');
        m.find('.sprint-reqreason-error').hide();
        m.data('cb', callback);
        bootstrap.Modal.getOrCreateInstance(m[0]).show();
        setTimeout(function(){ m.find('textarea[name=reason]').trigger('focus'); }, 200);
    };

    jQuery(document).on('click', '.sprint-reqreason-confirm', function(){
        var m = jQuery(this).closest('#sprint-request-reason-modal');
        var reason = (m.find('textarea[name=reason]').val() || '').trim();
        if (reason === '') { m.find('.sprint-reqreason-error').show(); return; }
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
