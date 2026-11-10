<?php

namespace GlpiPlugin\Sprint;

use CommonGLPI;
use Html;
use Plugin;
use Session;

/**
 * Creditfall: the credits a sprint started with at its kick-off against what
 * it delivered, and where the difference went.
 *
 * Two records make that visible, both written from the moment a sprint
 * starts (status → active):
 *  - the kick-off snapshot: what every item in the sprint held at that moment
 *    (its own credits plus those on its dependencies), with customer and owner;
 *  - the fall log: one row every time credits leave the sprint afterwards,
 *    because an item was carried over, went back to the backlog, was removed
 *    or had its credits lowered.
 *
 * Work that is still open counts as "still open" while the sprint runs and as
 * fallen once the sprint is completed. A sprint that started before this
 * existed has no snapshot and is not shown.
 */
class SprintCreditFall extends CommonGLPI
{
    public static $rightname = 'plugin_sprint_credits';

    const KICKOFF_TABLE = 'glpi_plugin_sprint_creditkickoffs';
    const FALL_TABLE    = 'glpi_plugin_sprint_creditfalls';

    const TYPE_CARRYOVER = 'carryover';
    const TYPE_BACKLOG   = 'backlog';
    const TYPE_LOWERED   = 'lowered';
    const TYPE_REMOVED   = 'removed';
    /** Not logged: open work, read live from the sprint. */
    const TYPE_OPEN      = 'open';

    /** Sprints in the trend at the bottom of the page. */
    const TREND_SPRINTS = 12;

    /** One colour per outcome, checked for colour-blind separation on both themes. */
    const COLORS = [
        'delivered'          => '#1f9d63',
        self::TYPE_CARRYOVER => '#2f7fe6',
        self::TYPE_BACKLOG   => '#e0700f',
        self::TYPE_LOWERED   => '#8b5cf6',
        self::TYPE_OPEN      => '#8a8f98',
    ];

    /** @var array{type:string,target:int,reason:string}|null what the running action is */
    private static ?array $context = null;

    /** @var array<int,string> sprint id => kick-off timestamp, '' when not tracked */
    private static array $kickoffCache = [];

    public static function getTypeName($nb = 0): string
    {
        return __('Creditfall', 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-arrow-trend-down';
    }

    public static function getSearchURL($full = true)
    {
        return Plugin::getWebDir('sprint', $full) . '/front/creditfall.php';
    }

    // =========================================================================
    // Recording
    // =========================================================================

    private static function ready(): bool
    {
        global $DB;
        static $ready = null;
        if ($ready === null) {
            $ready = $DB->tableExists(self::KICKOFF_TABLE)
                && $DB->tableExists(self::FALL_TABLE)
                && $DB->fieldExists(Sprint::getTable(), 'credit_kickoff_date');
        }
        return $ready;
    }

    /** The moment the sprint's credits were snapshotted, '' when it is not tracked. */
    private static function kickoffDate(int $sprintId): string
    {
        global $DB;
        if ($sprintId <= 0 || !self::ready()) {
            return '';
        }
        if (!isset(self::$kickoffCache[$sprintId])) {
            $row = $DB->request([
                'SELECT' => ['credit_kickoff_date'],
                'FROM'   => Sprint::getTable(),
                'WHERE'  => ['id' => $sprintId],
            ])->current();
            self::$kickoffCache[$sprintId] = (string)($row['credit_kickoff_date'] ?? '');
        }
        return self::$kickoffCache[$sprintId];
    }

    /**
     * Run $callback as one named action, so the credits it moves are logged
     * as that action (a carry-over lowers the source item's credits, which
     * would otherwise read as a plain correction).
     */
    public static function during(string $type, int $targetSprintId, string $reason, callable $callback)
    {
        $previous      = self::$context;
        self::$context = ['type' => $type, 'target' => $targetSprintId, 'reason' => trim($reason)];
        try {
            return $callback();
        } finally {
            self::$context = $previous;
        }
    }

    /**
     * Freeze what the sprint holds at its kick-off. Runs once: a sprint that
     * is paused and started again keeps its first snapshot.
     */
    public static function snapshotKickoff(int $sprintId): void
    {
        global $DB;
        if ($sprintId <= 0 || !self::ready() || self::kickoffDate($sprintId) !== '') {
            return;
        }
        $items = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'plugin_sprint_sprintcustomers_id', 'users_id', 'credits'],
            'FROM'   => SprintItem::getTable(),
            'WHERE'  => ['plugin_sprint_sprints_id' => $sprintId],
        ]) as $row) {
            $items[(int)$row['id']] = $row;
        }
        $deps = SprintItemDependency::getCreditsByItem(array_keys($items));
        foreach ($items as $id => $row) {
            $credits = (float)($row['credits'] ?? 0) + (float)($deps[$id] ?? 0);
            if ($credits <= 0) {
                continue;
            }
            $DB->insert(self::KICKOFF_TABLE, [
                'plugin_sprint_sprints_id'         => $sprintId,
                'plugin_sprint_sprintitems_id'     => $id,
                'name'                             => (string)($row['name'] ?? ''),
                'plugin_sprint_sprintcustomers_id' => (int)($row['plugin_sprint_sprintcustomers_id'] ?? 0),
                'users_id'                         => (int)($row['users_id'] ?? 0),
                'credits'                          => $credits,
            ]);
        }
        $now = (string)($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $DB->update(Sprint::getTable(), ['credit_kickoff_date' => $now], ['id' => $sprintId]);
        self::$kickoffCache[$sprintId] = $now;
    }

    /** From SprintItem::post_updateItem(): credits that left a tracked sprint. */
    public static function onItemUpdate(SprintItem $item): void
    {
        $old = $item->oldvalues ?? [];
        if (!array_key_exists('plugin_sprint_sprints_id', $old) && !array_key_exists('credits', $old)) {
            return;
        }
        $fields     = $item->fields;
        $newSprint  = (int)($fields['plugin_sprint_sprints_id'] ?? 0);
        $oldSprint  = array_key_exists('plugin_sprint_sprints_id', $old) ? (int)$old['plugin_sprint_sprints_id'] : $newSprint;
        $newCredits = (float)($fields['credits'] ?? 0);
        $oldCredits = array_key_exists('credits', $old) ? (float)$old['credits'] : $newCredits;
        // The state the item had while it still sat in the sprint.
        $before = array_merge($fields, array_intersect_key($old, array_flip(
            ['name', 'plugin_sprint_sprintcustomers_id', 'users_id', 'status', 'is_blocked']
        )));

        if ($oldSprint > 0 && $oldSprint !== $newSprint) {
            // The whole item left: its own credits and those on its dependencies.
            $id   = (int)$item->getID();
            $deps = SprintItemDependency::getCreditsByItem([$id]);
            self::log(
                $oldSprint,
                $before,
                $newSprint === 0 ? self::TYPE_BACKLOG : self::TYPE_CARRYOVER,
                $oldCredits + (float)($deps[$id] ?? 0),
                $newSprint
            );
            return;
        }
        if ($newSprint > 0 && $newCredits < $oldCredits) {
            $carried = (self::$context['type'] ?? '') === self::TYPE_CARRYOVER;
            self::log(
                $newSprint,
                $before,
                $carried ? self::TYPE_CARRYOVER : self::TYPE_LOWERED,
                $oldCredits - $newCredits,
                $carried ? (int)self::$context['target'] : 0
            );
        }
    }

    /** From SprintItem::cleanDBonPurge(): an unfinished item deleted from a tracked sprint. */
    public static function onItemPurge(SprintItem $item): void
    {
        $fields   = $item->fields;
        $sprintId = (int)($fields['plugin_sprint_sprints_id'] ?? 0);
        if ($sprintId <= 0 || (string)($fields['status'] ?? '') === SprintItem::STATUS_DONE) {
            return;
        }
        $id   = (int)$item->getID();
        $deps = SprintItemDependency::getCreditsByItem([$id]);
        self::log(
            $sprintId,
            $fields,
            (self::$context['type'] ?? '') === self::TYPE_BACKLOG ? self::TYPE_BACKLOG : self::TYPE_REMOVED,
            (float)($fields['credits'] ?? 0) + (float)($deps[$id] ?? 0),
            0
        );
    }

    private static function log(int $sprintId, array $item, string $type, float $credits, int $targetSprintId): void
    {
        global $DB;
        if ($credits <= 0 || self::kickoffDate($sprintId) === '') {
            return;
        }
        $DB->insert(self::FALL_TABLE, [
            'plugin_sprint_sprints_id'         => $sprintId,
            'plugin_sprint_sprintitems_id'     => (int)($item['id'] ?? 0),
            'name'                             => (string)($item['name'] ?? ''),
            'plugin_sprint_sprintcustomers_id' => (int)($item['plugin_sprint_sprintcustomers_id'] ?? 0),
            'users_id'                         => (int)($item['users_id'] ?? 0),
            'type'                             => $type,
            'credits'                          => SprintCreditMath::round2($credits),
            'target_sprints_id'                => $targetSprintId,
            'was_blocked'                      => self::isBlocked($item) ? 1 : 0,
            'reason'                           => (string)(self::$context['reason'] ?? ''),
            'users_id_actor'                   => (int)(Session::getLoginUserID() ?: 0),
            'date_creation'                    => (string)($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    private static function isBlocked(array $item): bool
    {
        return (string)($item['status'] ?? '') === SprintItem::STATUS_BLOCKED
            || (int)($item['is_blocked'] ?? 0) === 1;
    }

    // =========================================================================
    // Data
    // =========================================================================

    private static function emptyTotals(): array
    {
        return [
            'kickoff' => 0.0, 'delivered' => 0.0, 'open' => 0.0, 'blocked' => 0.0,
            self::TYPE_CARRYOVER => 0.0, self::TYPE_BACKLOG => 0.0, self::TYPE_LOWERED => 0.0,
        ];
    }

    /**
     * Everything the page shows for one sprint.
     *
     * `added` is what the kick-off snapshot cannot explain: delivered + open +
     * fallen − kick-off, so work that entered the sprint later (or credits
     * that were raised) is named instead of hidden in the other figures.
     *
     * @return array{
     *   total: array<string,float>,
     *   customers: array<int,array<string,float>>,
     *   users: array<int,array<string,float>>,
     *   rows: array<int,array<string,mixed>>
     * }
     */
    public static function collect(int $sprintId, int $customerId = 0): array
    {
        global $DB;

        $out = ['total' => self::emptyTotals(), 'customers' => [], 'users' => [], 'rows' => []];
        if (self::kickoffDate($sprintId) === '' || !SprintCustomer::canViewCredits()) {
            return $out;
        }
        $visible = array_flip(array_merge([0], SprintCustomer::visibleIds()));
        $shown   = static fn(int $cid): bool => $customerId > 0 ? $cid === $customerId : isset($visible[$cid]);
        $add     = static function (array &$out, int $cid, int $uid, string $key, float $credits): void {
            $out['customers'][$cid] ??= self::emptyTotals();
            $out['users'][$uid]     ??= self::emptyTotals();
            $out['customers'][$cid][$key] += $credits;
            $out['users'][$uid][$key]     += $credits;
            $out['total'][$key]           += $credits;
        };

        foreach ($DB->request(['FROM' => self::KICKOFF_TABLE, 'WHERE' => ['plugin_sprint_sprints_id' => $sprintId]]) as $row) {
            $cid = (int)$row['plugin_sprint_sprintcustomers_id'];
            if ($shown($cid)) {
                $add($out, $cid, (int)$row['users_id'], 'kickoff', (float)$row['credits']);
            }
        }

        // What the sprint holds now: delivered or still open.
        $items = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'status', 'is_blocked', 'users_id', 'plugin_sprint_sprintcustomers_id', 'credits'],
            'FROM'   => SprintItem::getTable(),
            'WHERE'  => ['plugin_sprint_sprints_id' => $sprintId],
        ]) as $row) {
            $items[(int)$row['id']] = $row;
        }
        $deps = SprintItemDependency::getCreditsByItem(array_keys($items));
        $open = [];
        foreach ($items as $id => $row) {
            $cid     = (int)$row['plugin_sprint_sprintcustomers_id'];
            $credits = (float)$row['credits'] + (float)($deps[$id] ?? 0);
            if ($credits <= 0 || !$shown($cid)) {
                continue;
            }
            $uid = (int)$row['users_id'];
            if ((string)$row['status'] === SprintItem::STATUS_DONE) {
                $add($out, $cid, $uid, 'delivered', $credits);
                continue;
            }
            $add($out, $cid, $uid, 'open', $credits);
            if (self::isBlocked($row)) {
                $add($out, $cid, $uid, 'blocked', $credits);
            }
            $open[] = [
                'type'        => self::TYPE_OPEN,
                'item_id'     => $id,
                'name'        => (string)$row['name'],
                'customer_id' => $cid,
                'users_id'    => $uid,
                'credits'     => $credits,
                'target'      => 0,
                'blocked'     => self::isBlocked($row),
                'status'      => (string)$row['status'],
                'reason'      => '',
                'date'        => '',
            ];
        }

        // What left the sprint since the kick-off, newest first.
        $events = [];
        foreach ($DB->request([
            'FROM'  => self::FALL_TABLE,
            'WHERE' => ['plugin_sprint_sprints_id' => $sprintId],
            'ORDER' => ['date_creation DESC', 'id DESC'],
        ]) as $row) {
            $cid = (int)$row['plugin_sprint_sprintcustomers_id'];
            if (!$shown($cid)) {
                continue;
            }
            $type = (string)$row['type'];
            $add($out, $cid, (int)$row['users_id'], $type === self::TYPE_REMOVED ? self::TYPE_LOWERED : $type, (float)$row['credits']);
            $events[] = [
                'type'        => $type,
                'item_id'     => (int)$row['plugin_sprint_sprintitems_id'],
                'name'        => (string)$row['name'],
                'customer_id' => $cid,
                'users_id'    => (int)$row['users_id'],
                'credits'     => (float)$row['credits'],
                'target'      => (int)$row['target_sprints_id'],
                'blocked'     => (int)$row['was_blocked'] === 1,
                'status'      => '',
                'reason'      => (string)($row['reason'] ?? ''),
                'date'        => (string)($row['date_creation'] ?? ''),
            ];
        }
        usort($open, static fn($a, $b) => [$b['blocked'], $b['credits']] <=> [$a['blocked'], $a['credits']]);
        $out['rows'] = array_merge($events, $open);

        $residual = static fn(array $t): float => SprintCreditMath::round2(
            $t['delivered'] + $t['open'] + self::moved($t) - $t['kickoff']
        );
        $out['total']['added'] = $residual($out['total']);
        foreach ($out['customers'] as $cid => $t) {
            $out['customers'][$cid]['added'] = $residual($t);
        }
        return $out;
    }

    /** Credits that left the sprint through an action. */
    private static function moved(array $t): float
    {
        return $t[self::TYPE_CARRYOVER] + $t[self::TYPE_BACKLOG] + $t[self::TYPE_LOWERED];
    }

    /** The creditfall: what left, plus what was still open when the sprint closed. */
    private static function fallen(array $t, bool $completed): float
    {
        return self::moved($t) + ($completed ? $t['open'] : 0.0);
    }

    /** Tracked sprints the session may see, newest first. */
    private static function trackedSprints(): array
    {
        if (!self::ready()) {
            return [];
        }
        return array_values((new Sprint())->find(
            ['NOT' => ['credit_kickoff_date' => null]]
                + getEntitiesRestrictCriteria(Sprint::getTable(), '', '', true),
            ['date_start DESC', 'id DESC']
        ));
    }

    // =========================================================================
    // Page
    // =========================================================================

    public static function show(): void
    {
        if (!SprintCustomer::canViewCredits()) {
            echo "<div class='alert alert-warning'>"
                . htmlescape(__("You don't have permission to perform this action.")) . "</div>";
            return;
        }

        echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'>";
        echo "<h2 class='mb-0'><i class='" . self::getIcon() . " me-2' aria-hidden='true'></i>"
            . htmlescape(self::getTypeName()) . "</h2>";
        echo "<span class='text-muted sprint-small'>"
            . htmlescape(__('The credits a sprint started with at its kick-off against what it delivered, and where the difference went.', 'sprint'))
            . "</span></div>";

        $sprints = self::trackedSprints();
        if (!$sprints) {
            echo "<div class='card'><div class='card-body text-center text-muted py-5'>";
            echo "<i class='fas fa-hourglass-start mb-2' style='font-size:1.6em;' aria-hidden='true'></i>";
            echo "<div class='fw-bold'>" . htmlescape(__('No sprint is being tracked yet', 'sprint')) . "</div>";
            echo "<div class='sprint-small'>"
                . htmlescape(__('Creditfall starts counting at the kick-off of a sprint, the moment it is set to active. Sprints that started earlier have no kick-off snapshot and are not shown.', 'sprint'))
                . "</div>";
            echo "</div></div>";
            return;
        }

        $customers = SprintCustomer::getAll(false);
        $byId      = [];
        foreach ($sprints as $s) {
            $byId[(int)$s['id']] = $s;
        }
        $sprintId = (int)($_GET['sprint'] ?? 0);
        if (!isset($byId[$sprintId])) {
            // The running sprint first, else the most recent one.
            $sprintId = (int)$sprints[0]['id'];
            foreach ($sprints as $s) {
                if ((string)$s['status'] === Sprint::STATUS_ACTIVE) {
                    $sprintId = (int)$s['id'];
                    break;
                }
            }
        }
        $customerId = (int)($_GET['customer'] ?? 0);
        if (!isset($customers[$customerId])) {
            $customerId = 0;
        }
        $sprint    = $byId[$sprintId];
        $completed = (string)$sprint['status'] === Sprint::STATUS_COMPLETED;
        $data      = self::collect($sprintId, $customerId);

        self::renderFilter($sprints, $customers, $sprintId, $customerId);
        self::renderSummary($sprint, $data, $customerId, $completed);
        self::renderCustomers($sprintId, $data, $completed);
        self::renderUsers($data, $completed);
        self::renderItems($data, $byId, $completed);
        self::renderTrend(array_reverse(array_slice($sprints, 0, self::TREND_SPRINTS)), $customerId, $sprintId);
    }

    private static function renderFilter(array $sprints, array $customers, int $sprintId, int $customerId): void
    {
        $statuses = Sprint::getAllStatuses();
        echo "<form method='get' action='" . htmlescape(self::getSearchURL()) . "' "
            . "class='sprint-credit-filter sprint-fall-filter d-flex flex-wrap align-items-end gap-2 p-2 mb-3' role='search' "
            . "aria-label='" . __s('Filter the creditfall page', 'sprint') . "'>";
        echo "<div><label class='form-label sprint-small mb-1' for='sprint-fall-filter-sprint'>"
            . htmlescape(Sprint::getTypeName(1)) . "</label>";
        echo "<select class='form-select form-select-sm' id='sprint-fall-filter-sprint' name='sprint' style='min-width:260px;'>";
        foreach ($sprints as $s) {
            echo "<option value='" . (int)$s['id'] . "'" . ((int)$s['id'] === $sprintId ? ' selected' : '') . ">"
                . htmlescape((string)$s['name'] . ' (' . ($statuses[$s['status']] ?? $s['status']) . ')') . "</option>";
        }
        echo "</select></div>";
        echo "<div><label class='form-label sprint-small mb-1' for='sprint-fall-filter-customer'>"
            . htmlescape(SprintCustomer::getTypeName(1)) . "</label>";
        echo "<select class='form-select form-select-sm' id='sprint-fall-filter-customer' name='customer' style='min-width:220px;'>";
        echo "<option value='0'>" . htmlescape(__('All customers', 'sprint')) . "</option>";
        foreach ($customers as $id => $c) {
            echo "<option value='" . (int)$id . "'" . ((int)$id === $customerId ? ' selected' : '') . ">"
                . htmlescape((string)$c['name']) . "</option>";
        }
        echo "</select></div>";
        echo "<button type='submit' class='btn btn-sm btn-primary'><i class='fas fa-filter me-1' aria-hidden='true'></i>"
            . htmlescape(__('Apply', 'sprint')) . "</button>";
        echo "</form>";
        echo "<script>(function(){var f=document.querySelectorAll('form.sprint-fall-filter');"
            . "var form=f[f.length-1];if(!form){return;}"
            . "form.querySelectorAll('select').forEach(function(s){s.addEventListener('change',function(){form.submit();});});})();</script>";
    }

    /** Label per outcome; open work reads differently once the sprint is closed. */
    private static function label(string $key, bool $completed): string
    {
        return match ($key) {
            'delivered'          => __('Delivered', 'sprint'),
            self::TYPE_CARRYOVER => __('Carried over', 'sprint'),
            self::TYPE_BACKLOG   => __('Back to backlog', 'sprint'),
            self::TYPE_LOWERED   => __('Lowered or removed', 'sprint'),
            self::TYPE_REMOVED   => __('Item removed', 'sprint'),
            default              => $completed ? __('Not finished', 'sprint') : __('Still open', 'sprint'),
        };
    }

    /** The outcome keys in the order every bar and table uses. */
    private static function outcomes(): array
    {
        return ['delivered', self::TYPE_CARRYOVER, self::TYPE_BACKLOG, self::TYPE_LOWERED, self::TYPE_OPEN];
    }

    /** One stacked bar: how the credits of a sprint, customer or person ended up. */
    private static function bar(array $t, float $scale, bool $completed, string $class = ''): string
    {
        $html = "<div class='sprint-fall-bar {$class}'>";
        foreach (self::outcomes() as $key) {
            $value = (float)$t[$key];
            if ($value <= 0 || $scale <= 0) {
                continue;
            }
            $pct   = min(100, round(100 * $value / $scale, 2));
            $html .= "<span class='sprint-fall-seg sprint-fall-seg-{$key}' style='width:{$pct}%;background-color:" . self::COLORS[$key] . ";' title='"
                . htmlescape(self::label($key, $completed) . ': ' . SprintCustomer::formatCredits($value)) . "'></span>";
        }
        return $html . "</div>";
    }

    private static function renderSummary(array $sprint, array $data, int $customerId, bool $completed): void
    {
        $t        = $data['total'];
        $sprintId = (int)$sprint['id'];
        $agreed   = 0.0;
        foreach (SprintCustomer::sprintFunding() as $cid => $bySprint) {
            if ($customerId === 0 || (int)$cid === $customerId) {
                $agreed += (float)($bySprint[$sprintId]['grant'] ?? 0);
            }
        }
        $fallen = self::fallen($t, $completed);
        $gap    = $t['delivered'] - $agreed;

        echo "<div class='sprint-credit-tiles mb-3'>";
        echo SprintCredit::renderTile(__('Agreed with the customer', 'sprint'), SprintCustomer::formatCredits($agreed), 'fas fa-handshake', '#6c757d');
        echo SprintCredit::renderTile(__('At kick-off', 'sprint'), SprintCustomer::formatCredits($t['kickoff']), 'fas fa-flag', '#6c757d');
        echo SprintCredit::renderTile(__('Delivered', 'sprint'), SprintCustomer::formatCredits($t['delivered']), 'fas fa-check-circle', self::COLORS['delivered']);
        echo SprintCredit::renderTile(self::getTypeName(), SprintCustomer::formatCredits($fallen), self::getIcon(), '#dc3545');
        if (!$completed) {
            echo SprintCredit::renderTile(__('Still open', 'sprint'), SprintCustomer::formatCredits($t['open']), 'fas fa-hourglass-half', self::COLORS[self::TYPE_OPEN]);
        }
        if ($agreed > 0) {
            echo SprintCredit::renderTile(
                __('Delivered against the agreement', 'sprint'),
                ($gap > 0 ? '+' : '') . SprintCustomer::formatCredits($gap),
                'fas fa-scale-balanced',
                $gap < 0 ? '#dc3545' : self::COLORS['delivered']
            );
        }
        echo "</div>";

        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<h3 class='card-title'><i class='fas fa-water me-2' aria-hidden='true'></i>"
            . htmlescape(sprintf(__('From kick-off to delivered: %s', 'sprint'), (string)$sprint['name'])) . "</h3>";
        echo "<div class='text-muted sprint-small mb-2'>" . htmlescape($completed
            ? __('How the credits of this sprint ended up. Work that was not finished when the sprint closed counts as creditfall.', 'sprint')
            : __('How the credits of this sprint stand right now. Open work is not creditfall yet, it becomes creditfall when the sprint closes without it.', 'sprint'))
            . "</div>";

        $total = $t['delivered'] + $t['open'] + self::moved($t);
        $scale = max($total, $agreed, $t['kickoff'], 0.01);
        echo "<div class='sprint-fall-hero' role='img' aria-label='" . htmlescape(sprintf(
            __('Kick-off %1$s, delivered %2$s, creditfall %3$s', 'sprint'),
            SprintCustomer::formatCredits($t['kickoff']),
            SprintCustomer::formatCredits($t['delivered']),
            SprintCustomer::formatCredits($fallen)
        )) . "'>";
        foreach ([[$agreed, __('Agreed', 'sprint'), 'top'], [$t['kickoff'], __('Kick-off', 'sprint'), 'bottom']] as [$value, $label, $side]) {
            if ($value <= 0) {
                continue;
            }
            $pct = round(100 * $value / $scale, 2);
            echo "<div class='sprint-fall-marker sprint-fall-marker-{$side}" . ($pct > 60 ? ' sprint-fall-marker-left' : '')
                . "' style='left:{$pct}%;'><span>" . htmlescape($label . ' ' . SprintCustomer::formatCredits($value)) . "</span></div>";
        }
        echo self::bar($t, $scale, $completed);
        echo "</div>";

        echo "<div class='sprint-credit-legend'>";
        foreach (self::outcomes() as $key) {
            echo "<span class='sprint-credit-legend-item sprint-credit-legend-static'>"
                . "<span class='sprint-matrix-dot sprint-fall-seg-{$key}' style='background-color:" . self::COLORS[$key] . ";'></span>"
                . htmlescape(self::label($key, $completed)) . " <strong>" . htmlescape(SprintCustomer::formatCredits($t[$key])) . "</strong>"
                . ($key === self::TYPE_OPEN && $t['blocked'] > 0
                    ? " <span class='text-muted'>(" . htmlescape(sprintf(__('%s blocked', 'sprint'), SprintCustomer::formatCredits($t['blocked']))) . ")</span>"
                    : '')
                . "</span>";
        }
        echo "</div>";
        if (abs((float)$t['added']) >= 0.01) {
            echo "<div class='sprint-small text-muted mt-2'><i class='fas fa-circle-info me-1' aria-hidden='true'></i>"
                . htmlescape(sprintf(
                    $t['added'] > 0
                        ? __('%s was added to the sprint after the kick-off, through new items or raised credits.', 'sprint')
                        : __('%s is no longer in these figures, for example because an item changed customer after the kick-off.', 'sprint'),
                    SprintCustomer::formatCredits(abs((float)$t['added']))
                )) . "</div>";
        }
        echo "</div></div>";
    }

    /** Shared header cells of the customer and the person table. */
    private static function outcomeHeads(bool $completed): string
    {
        $html = '';
        foreach (self::outcomes() as $key) {
            $html .= "<th scope='col' class='text-center'>" . htmlescape(self::label($key, $completed)) . "</th>";
        }
        return $html . "<th scope='col' class='text-center'>" . htmlescape(self::getTypeName()) . "</th>";
    }

    private static function outcomeCells(array $t, bool $completed): string
    {
        $html = '';
        foreach (self::outcomes() as $key) {
            $html .= "<td class='text-center'>" . ((float)$t[$key] > 0
                ? htmlescape(SprintCustomer::formatCredits($t[$key]))
                : "<span class='text-muted'>—</span>");
            if ($key === self::TYPE_OPEN && $t['blocked'] > 0) {
                $html .= "<div class='sprint-small text-muted'>"
                    . htmlescape(sprintf(__('%s blocked', 'sprint'), SprintCustomer::formatCredits($t['blocked']))) . "</div>";
            }
            $html .= "</td>";
        }
        $fallen = self::fallen($t, $completed);
        $share  = $t['kickoff'] > 0 ? ' (' . round(100 * $fallen / $t['kickoff']) . '%)' : '';
        return $html . "<td class='text-center fw-bold'" . ($fallen > 0 ? " style='color:#dc3545;'" : '') . ">"
            . htmlescape(SprintCustomer::formatCredits($fallen)) . "<span class='fw-normal text-muted sprint-small'>"
            . htmlescape($share) . "</span></td>";
    }

    private static function renderCustomers(int $sprintId, array $data, bool $completed): void
    {
        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<h3 class='card-title'><i class='fas fa-building me-2' aria-hidden='true'></i>"
            . htmlescape(__('Creditfall per customer', 'sprint')) . "</h3>";
        echo "<div class='text-muted sprint-small mb-2'>"
            . htmlescape(__('Per customer: what was agreed, what the sprint held at its kick-off and how those credits ended up. The percentage is the creditfall as a share of the kick-off.', 'sprint'))
            . "</div>";
        if (!$data['customers']) {
            echo "<div class='text-muted'>" . htmlescape(__('This sprint held no credits at its kick-off and none were added since.', 'sprint')) . "</div>";
            echo "</div></div>";
            return;
        }
        $rows = $data['customers'];
        uasort($rows, static fn($a, $b) => self::fallen($b, $completed) <=> self::fallen($a, $completed));
        $scale = 0.01;
        foreach ($rows as $t) {
            $scale = max($scale, $t['delivered'] + $t['open'] + self::moved($t));
        }
        $funding = SprintCustomer::sprintFunding();

        echo "<div class='table-responsive'><table class='table table-sm align-middle sprint-credit-table'>";
        echo "<caption class='visually-hidden'>" . htmlescape(__('Creditfall per customer', 'sprint')) . "</caption>";
        echo "<thead><tr><th scope='col' style='min-width:200px;'>" . htmlescape(SprintCustomer::getTypeName(1)) . "</th>"
            . "<th scope='col' class='text-center'>" . htmlescape(__('Agreed', 'sprint')) . "</th>"
            . "<th scope='col' class='text-center'>" . htmlescape(__('Kick-off', 'sprint')) . "</th>"
            . self::outcomeHeads($completed) . "</tr></thead><tbody>";
        foreach ($rows as $cid => $t) {
            $agreed = (float)($funding[$cid][$sprintId]['grant'] ?? 0);
            echo "<tr><td>";
            if ($cid > 0) {
                echo "<a href='" . htmlescape(SprintCustomer::getFormURLWithID((int)$cid)) . "'>"
                    . "<span class='sprint-matrix-dot' style='background:" . htmlescape(SprintCustomer::getColorFor((int)$cid)) . ";'></span>"
                    . htmlescape(SprintCustomer::getNameFor((int)$cid) ?: '#' . $cid) . "</a>";
            } else {
                echo "<span class='text-muted'>" . htmlescape(__('No customer', 'sprint')) . "</span>";
            }
            echo self::bar($t, $scale, $completed, 'sprint-fall-bar-sm mt-1');
            echo "</td>";
            echo "<td class='text-center'>" . ($agreed > 0 ? htmlescape(SprintCustomer::formatCredits($agreed)) : "<span class='text-muted'>—</span>") . "</td>";
            echo "<td class='text-center'>" . htmlescape(SprintCustomer::formatCredits($t['kickoff']));
            if (abs((float)$t['added']) >= 0.01) {
                echo "<div class='sprint-small text-muted' title='" . __s('Added or changed after the kick-off', 'sprint') . "'>"
                    . ($t['added'] > 0 ? '+' : '−') . htmlescape(SprintCustomer::formatCredits(abs((float)$t['added']))) . "</div>";
            }
            echo "</td>" . self::outcomeCells($t, $completed) . "</tr>";
        }
        echo "</tbody></table></div>";
        echo "</div></div>";
    }

    private static function renderUsers(array $data, bool $completed): void
    {
        if (!$data['users']) {
            return;
        }
        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<h3 class='card-title'><i class='fas fa-user-group me-2' aria-hidden='true'></i>"
            . htmlescape(__('Creditfall per person', 'sprint')) . "</h3>";
        echo "<div class='text-muted sprint-small mb-2'>"
            . htmlescape(__('Per owner: the credits on their items at the kick-off and how they ended up. Credits that left count for the owner at that moment.', 'sprint'))
            . "</div>";
        $rows = $data['users'];
        uasort($rows, static fn($a, $b) => self::fallen($b, $completed) <=> self::fallen($a, $completed));
        $scale = 0.01;
        foreach ($rows as $t) {
            $scale = max($scale, $t['delivered'] + $t['open'] + self::moved($t));
        }

        echo "<div class='table-responsive'><table class='table table-sm align-middle sprint-credit-table'>";
        echo "<caption class='visually-hidden'>" . htmlescape(__('Creditfall per person', 'sprint')) . "</caption>";
        echo "<thead><tr><th scope='col' style='min-width:200px;'>" . htmlescape(__('Owner', 'sprint')) . "</th>"
            . "<th scope='col' class='text-center'>" . htmlescape(__('Kick-off', 'sprint')) . "</th>"
            . self::outcomeHeads($completed) . "</tr></thead><tbody>";
        foreach ($rows as $uid => $t) {
            echo "<tr><td>" . ($uid > 0
                ? "<i class='fas fa-user text-muted me-1' aria-hidden='true'></i>" . htmlescape(SprintCache::userName((int)$uid))
                : "<span class='text-muted'>" . htmlescape(__('No owner', 'sprint')) . "</span>");
            echo self::bar($t, $scale, $completed, 'sprint-fall-bar-sm mt-1');
            echo "</td>";
            echo "<td class='text-center'>" . htmlescape(SprintCustomer::formatCredits($t['kickoff'])) . "</td>";
            echo self::outcomeCells($t, $completed) . "</tr>";
        }
        echo "</tbody></table></div>";
        echo "</div></div>";
    }

    private static function renderItems(array $data, array $sprintsById, bool $completed): void
    {
        global $DB;

        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<h3 class='card-title'><i class='fas fa-list-check me-2' aria-hidden='true'></i>"
            . htmlescape(__('Items in the creditfall', 'sprint')) . "</h3>";
        echo "<div class='text-muted sprint-small mb-2'>"
            . htmlescape(__('Every time credits left this sprint since its kick-off, newest first, followed by the work that is still open.', 'sprint'))
            . "</div>";
        if (!$data['rows']) {
            echo "<div class='text-muted'><i class='fas fa-check me-1' aria-hidden='true'></i>"
                . htmlescape(__('Nothing fell out of this sprint and no work is open.', 'sprint')) . "</div>";
            echo "</div></div>";
            return;
        }

        // Logged items may be gone: only link the ones that still exist.
        $ids      = array_values(array_unique(array_filter(array_map(static fn($r) => (int)$r['item_id'], $data['rows']))));
        $existing = [];
        if ($ids) {
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => SprintItem::getTable(), 'WHERE' => ['id' => $ids]]) as $row) {
                $existing[(int)$row['id']] = true;
            }
        }
        $statuses = SprintItem::getAllStatuses();

        echo "<div class='table-responsive'><table class='table table-sm align-middle sprint-credit-table'>";
        echo "<caption class='visually-hidden'>" . htmlescape(__('Items in the creditfall', 'sprint')) . "</caption>";
        echo "<thead><tr>"
            . "<th scope='col'>" . htmlescape(__('Item', 'sprint')) . "</th>"
            . "<th scope='col'>" . htmlescape(SprintCustomer::getTypeName(1)) . "</th>"
            . "<th scope='col'>" . htmlescape(__('Owner', 'sprint')) . "</th>"
            . "<th scope='col'>" . htmlescape(__('What happened', 'sprint')) . "</th>"
            . "<th scope='col' class='text-center'>" . htmlescape(__('Credits', 'sprint')) . "</th>"
            . "<th scope='col'>" . htmlescape(__('Reason', 'sprint')) . "</th>"
            . "<th scope='col'>" . htmlescape(__('When', 'sprint')) . "</th>"
            . "</tr></thead><tbody>";
        foreach ($data['rows'] as $r) {
            $name = $r['name'] !== '' ? $r['name'] : '#' . $r['item_id'];
            $key  = $r['type'] === self::TYPE_REMOVED ? self::TYPE_LOWERED : $r['type'];
            $what = match ($r['type']) {
                self::TYPE_LOWERED => __('Credits lowered', 'sprint'),
                self::TYPE_OPEN    => $statuses[$r['status']] ?? self::label(self::TYPE_OPEN, $completed),
                default            => self::label($r['type'], $completed),
            };
            echo "<tr>";
            echo "<td>" . (isset($existing[$r['item_id']])
                ? "<a href='" . htmlescape(SprintItem::getFormURLWithID($r['item_id'])) . "'>" . htmlescape($name) . "</a>"
                : htmlescape($name)) . "</td>";
            echo "<td>" . ($r['customer_id'] > 0
                ? "<span class='sprint-matrix-dot' style='background:" . htmlescape(SprintCustomer::getColorFor($r['customer_id'])) . ";'></span>"
                    . htmlescape(SprintCustomer::getNameFor($r['customer_id']) ?: '#' . $r['customer_id'])
                : "<span class='text-muted'>—</span>") . "</td>";
            echo "<td>" . ($r['users_id'] > 0
                ? htmlescape(SprintCache::userName($r['users_id']))
                : "<span class='text-muted'>" . htmlescape(__('No owner', 'sprint')) . "</span>") . "</td>";
            echo "<td><span class='sprint-fall-type'><span class='sprint-matrix-dot sprint-fall-seg-{$key}' style='background-color:" . self::COLORS[$key] . ";'></span>"
                . htmlescape($what) . "</span>";
            if ($r['target'] > 0) {
                $target = $sprintsById[$r['target']]['name'] ?? '';
                if ($target === '') {
                    $s      = new Sprint();
                    $target = $s->getFromDB($r['target']) ? (string)$s->fields['name'] : '#' . $r['target'];
                }
                echo " <span class='sprint-small'>→ <a href='" . htmlescape(Sprint::getFormURLWithID($r['target'])) . "'>"
                    . htmlescape($target) . "</a></span>";
            }
            if ($r['blocked'] && $r['status'] !== SprintItem::STATUS_BLOCKED) {
                echo " <span class='badge bg-danger'><i class='fas fa-ban me-1' aria-hidden='true'></i>"
                    . htmlescape(__('Blocked', 'sprint')) . "</span>";
            }
            echo "</td>";
            echo "<td class='text-center fw-bold'>" . htmlescape(SprintCustomer::formatCredits($r['credits'])) . "</td>";
            echo "<td class='sprint-small'>" . ($r['reason'] !== '' ? htmlescape($r['reason']) : "<span class='text-muted'>—</span>") . "</td>";
            echo "<td class='sprint-small text-nowrap'>" . ($r['date'] !== '' ? htmlescape(Html::convDateTime($r['date'])) : "<span class='text-muted'>—</span>") . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";
        echo "</div></div>";
    }

    /** The same bar for every tracked sprint, oldest first, on one scale. */
    private static function renderTrend(array $sprints, int $customerId, int $selected): void
    {
        if (count($sprints) < 2) {
            return;
        }
        $rows  = [];
        $scale = 0.01;
        foreach ($sprints as $s) {
            $t      = self::collect((int)$s['id'], $customerId)['total'];
            $rows[] = [$s, $t];
            $scale  = max($scale, $t['delivered'] + $t['open'] + self::moved($t));
        }
        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<h3 class='card-title'><i class='fas fa-chart-bar me-2' aria-hidden='true'></i>"
            . htmlescape(__('Creditfall per sprint', 'sprint')) . "</h3>";
        echo "<div class='text-muted sprint-small mb-3'>"
            . htmlescape(__('Every tracked sprint on the same scale, oldest first. The figure on the right is the creditfall and its share of the kick-off.', 'sprint'))
            . "</div>";
        echo "<div class='sprint-credit-chart sprint-fall-trend'>";
        foreach ($rows as [$s, $t]) {
            $completed = (string)$s['status'] === Sprint::STATUS_COMPLETED;
            $fallen    = self::fallen($t, $completed);
            $url       = self::getSearchURL() . '?' . http_build_query(array_filter(['sprint' => (int)$s['id'], 'customer' => $customerId]));
            echo "<div class='sprint-credit-chart-row'>";
            echo "<div class='sprint-credit-chart-label'" . ((int)$s['id'] === $selected ? " style='font-weight:700;'" : '') . ">"
                . "<a href='" . htmlescape($url) . "'>" . htmlescape((string)$s['name']) . "</a></div>";
            echo self::bar($t, $scale, $completed, 'sprint-fall-bar-md');
            echo "<div class='sprint-credit-chart-value'>" . htmlescape(SprintCustomer::formatCredits($fallen))
                . ($t['kickoff'] > 0 ? " <span class='fw-normal text-muted'>" . round(100 * $fallen / $t['kickoff']) . "%</span>" : '')
                . "</div>";
            echo "</div>";
        }
        echo "</div></div></div>";
    }
}
