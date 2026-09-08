<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use Html;
use Session;

/**
 * A customer that buys credits up front. Sprint items are charged to one
 * customer, so planned work can be held against what they paid for.
 */
class SprintCustomer extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_credits';

    public $dohistory = true;

    /** Per-sprint overrides of the retainer (no own class), like the category caps. */
    const SPRINT_CREDITS_TABLE = 'glpi_plugin_sprint_sprintcustomercredits';

    /** @var array<int,array<string,mixed>>|null visible customers, per request */
    private static ?array $allCache = null;

    /** @var array<int,array<int,array{consumed:float,reserved:float,pipeline:float}>>|null */
    private static ?array $usageCache = null;

    /** @var array<int,array<int,array{credits:?float,max:?float}>>|null */
    private static ?array $overrideCache = null;

    /** @var array<int,array<int,array<string,mixed>>>|null */
    private static ?array $fundingCache = null;

    /** @var array<int,array<string,mixed>|null> sprint rows by id */
    private static array $sprintRows = [];

    public static function getTypeName($nb = 0): string
    {
        return _n('Customer', 'Customers', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-building';
    }

    /** Profile right, or a named grant in the plugin settings. */
    public static function canViewCredits(): bool
    {
        return Session::haveRight(self::$rightname, READ)
            || Config::currentUserCreditLevel() !== '';
    }

    /** May the current user manage customers and book credit purchases? */
    public static function canEditCredits(): bool
    {
        return Session::haveRight(self::$rightname, UPDATE)
            || Config::currentUserCreditLevel() === 'manage';
    }

    /**
     * The customer and the credit amount on an item are ordinary item fields —
     * the team fills them in while planning. Only the money view behind them
     * (balances, the credits page, the wallet) needs the credits right.
     */
    public static function canUseCredits(): bool
    {
        return Session::haveRight(SprintItem::$rightname, READ) || self::canViewCredits();
    }

    // GLPI's checks only see the profile; route them through the pair above so
    // a named credit manager is not locked out.
    public static function canView(): bool
    {
        return self::canViewCredits();
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(self::$rightname, CREATE) || Config::currentUserCreditLevel() === 'manage';
    }

    public static function canUpdate(): bool
    {
        return self::canEditCredits();
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(self::$rightname, PURGE) || Config::currentUserCreditLevel() === 'manage';
    }

    // =========================================================================
    // Lookup
    // =========================================================================

    /** @return array<int,array<string,mixed>> visible customers by id, name order */
    public static function getAll(bool $onlyActive = true): array
    {
        if (self::$allCache === null) {
            self::$allCache = [];
            foreach ((new self())->find(getEntitiesRestrictCriteria(self::getTable(), '', '', true), ['name ASC']) as $row) {
                self::$allCache[(int)$row['id']] = $row;
            }
        }
        if (!$onlyActive) {
            return self::$allCache;
        }
        return array_filter(self::$allCache, static fn($r) => (int)($r['is_active'] ?? 1) === 1);
    }

    /** Drop every per-request cache; called after any write that feeds them. */
    public static function invalidateCaches(): void
    {
        SprintRetainer::invalidate();
        self::$allCache      = null;
        self::$usageCache    = null;
        self::$overrideCache = null;
        self::$fundingCache  = null;
        self::$sprintRows    = [];
    }

    /**
     * Ids of the customers the session may see. Every credit aggregate is
     * scoped to these, so figures never cross an entity boundary.
     *
     * @return int[]
     */
    public static function visibleIds(bool $onlyActive = false): array
    {
        return array_map('intval', array_keys(self::getAll($onlyActive)));
    }

    /** True when $id is a customer the session may see (0 = unattributed). */
    public static function isVisible(int $id): bool
    {
        return $id === 0 || isset(self::getAll(false)[$id]);
    }

    /**
     * The entity rule on the row itself, so bookkeeping can apply it without
     * a session: the customer belongs to $entityId, or to an ancestor of it
     * and is recursive — the same rule GLPI applies to any entity-assigned
     * object. Seeing both entities is not enough to pair them.
     */
    public static function customerFitsEntity(array $customer, int $entityId): bool
    {
        $customerEntity = (int)($customer['entities_id'] ?? 0);
        if ($customerEntity === $entityId) {
            return true;
        }
        return (int)($customer['is_recursive'] ?? 0) === 1
            && in_array($customerEntity, getAncestorsOf('glpi_entities', $entityId), true);
    }

    /** Same rule for a customer the session can see; false for an unknown id. */
    public static function isAvailableInEntity(int $customerId, int $entityId): bool
    {
        $row = self::getAll(false)[$customerId] ?? null;
        return $row !== null && self::customerFitsEntity($row, $entityId);
    }

    /**
     * May $customerId be charged on an item in $sprintId? On the backlog
     * (sprint 0) there is no entity yet, so the session only needs to see the
     * customer; inside a sprint the customer must be valid for its entity.
     * Server-side bookkeeping passes $sessionScoped = false and is judged on
     * the rows alone.
     */
    public static function isChargeableInSprint(int $customerId, int $sprintId, bool $sessionScoped = true): bool
    {
        if ($customerId <= 0) {
            return true;
        }
        $customer = $sessionScoped ? (self::getAll(false)[$customerId] ?? null) : self::rawCustomer($customerId);
        if ($customer === null) {
            return false;
        }
        if ($sprintId <= 0) {
            return true;
        }
        $sprint = self::sprintRow($sprintId);
        return $sprint !== null && self::customerFitsEntity($customer, (int)($sprint['entities_id'] ?? 0));
    }

    /** The customer row regardless of the session's entities. */
    private static function rawCustomer(int $customerId): ?array
    {
        $row = new self();
        return $customerId > 0 && $row->getFromDB($customerId) ? $row->fields : null;
    }

    /** A sprint row by id: from the funding window when it is in it, else loaded. */
    private static function sprintRow(int $sprintId): ?array
    {
        if ($sprintId <= 0) {
            return null;
        }
        if (!array_key_exists($sprintId, self::$sprintRows)) {
            self::$sprintRows[$sprintId] = null;
            foreach (self::fundingSprints() as $sprint) {
                if ((int)$sprint['id'] === $sprintId) {
                    self::$sprintRows[$sprintId] = $sprint;
                    break;
                }
            }
            if (self::$sprintRows[$sprintId] === null) {
                $sprint = new Sprint();
                if ($sprint->getFromDB($sprintId)) {
                    self::$sprintRows[$sprintId] = $sprint->fields;
                }
            }
        }
        return self::$sprintRows[$sprintId];
    }

    /** @return int[] sprints the session may see, for scoping item aggregates */
    public static function visibleSprintIds(): array
    {
        return array_map(static fn($s) => (int)$s['id'], self::fundingSprints());
    }

    public static function getNameFor(int $id): string
    {
        $all = self::getAll(false);
        return $id > 0 && isset($all[$id]) ? (string)$all[$id]['name'] : '';
    }

    public static function getColorFor(int $id): string
    {
        $all = self::getAll(false);
        return $id > 0 && isset($all[$id]) ? (string)$all[$id]['color'] : '#6c757d';
    }

    /** Small coloured customer pill, '' when the item has no customer. */
    public static function renderPill(int $id): string
    {
        $name = self::getNameFor($id);
        if ($name === '') {
            return '';
        }
        $color = htmlescape(self::getColorFor($id));
        return " <span class='sprint-customer-pill' style='background:color-mix(in srgb,{$color} 18%,transparent);"
            . "border:1px solid {$color};color:{$color};'>"
            . "<i class='fas fa-building' style='font-size:0.75em;'></i> "
            . htmlescape($name) . "</span>";
    }

    /** `<option>` list of active customers. */
    public static function dropdownOptions(int $selected = 0, bool $onlyActive = true): string
    {
        $html = '';
        foreach (self::getAll($onlyActive) as $id => $row) {
            $html .= "<option value='" . (int)$id . "'" . ((int)$id === $selected ? ' selected' : '') . ">"
                . htmlescape((string)$row['name']) . "</option>";
        }
        return $html;
    }

    /** Active customers plus $selected, so editing never drops a deactivated one. */
    public static function dropdownOptionsPreserving(int $selected): string
    {
        $html   = self::dropdownOptions($selected);
        $active = self::getAll(true);
        if ($selected > 0 && !isset($active[$selected])) {
            $name = self::getNameFor($selected);
            if ($name !== '') {
                $html .= "<option value='" . $selected . "' selected>"
                    . htmlescape($name) . ' (' . __('inactive', 'sprint') . ")</option>";
            }
        }
        return $html;
    }

    // =========================================================================
    // Credit arithmetic — the rules live in SprintCreditMath; this class only
    // gathers the rows and applies the session's entity scope.
    // =========================================================================

    /** "10", "10.5", "12.25". */
    public static function formatCredits($value): string
    {
        $v = round((float)$value, 2);
        if ($v === floor($v)) {
            return (string)(int)$v;
        }
        return rtrim(number_format($v, 2, '.', ''), '0');
    }

    /** Normalize a submitted credit amount: two decimals, never negative. */
    public static function normalizeCredits($value): float
    {
        return max(0.0, round((float)$value, 2));
    }

    /** '' for an empty/zero date, the plain Y-m-d otherwise. */
    public static function dateValue($value): string
    {
        return SprintCreditMath::dateValue($value);
    }

    /**
     * Ledger rows per customer, oldest first, shaped for SprintCreditMath::wallet().
     *
     * @return array<int,array<int,array{id:int,date:string,expire:string,credits:float}>>
     */
    private static function ledgerByCustomer(): array
    {
        global $DB;

        $out       = [];
        $customers = self::canViewCredits() ? self::visibleIds() : [];
        if (!$customers) {
            return $out;
        }
        foreach ($DB->request([
            'SELECT' => ['id', 'plugin_sprint_sprintcustomers_id', 'credits', 'date', 'date_expire'],
            'FROM'   => SprintCredit::getTable(),
            'WHERE'  => ['plugin_sprint_sprintcustomers_id' => $customers],
            'ORDER'  => ['date ASC', 'id ASC'],
        ]) as $row) {
            $out[(int)$row['plugin_sprint_sprintcustomers_id']][] = [
                'id'      => (int)$row['id'],
                'date'    => self::dateValue($row['date'] ?? null),
                'expire'  => self::dateValue($row['date_expire'] ?? null),
                'credits' => (float)$row['credits'],
            ];
        }
        return $out;
    }

    /**
     * Credits on items, bucketed per customer per sprint — one query per
     * request, every aggregate derives from it.
     *
     * A parked item that is not done is out of the active planning: it
     * neither reserves nor sits in the pipeline. Delivered work always
     * counts, parked or not — history does not vanish when an item is parked
     * later. Items carry no entity of their own; they inherit their sprint's,
     * so rows are scoped on the sprints this session may see (a cancelled
     * sprint is not one of them, so its items are not charged). A backlog
     * item (sprint 0) has no entity at all — the backlog is global.
     *
     * @return array<int,array<int,array{consumed:float,reserved:float,pipeline:float}>> [customer][sprint]
     */
    private static function itemUsage(): array
    {
        global $DB;

        if (self::$usageCache !== null) {
            return self::$usageCache;
        }
        self::$usageCache = [];
        if (!self::canViewCredits()) {
            return self::$usageCache;
        }
        foreach ($DB->request([
            'SELECT' => ['plugin_sprint_sprintcustomers_id', 'plugin_sprint_sprints_id', 'status', 'credits', 'is_parked'],
            'FROM'   => SprintItem::getTable(),
            'WHERE'  => [
                'plugin_sprint_sprintcustomers_id' => array_merge([0], self::visibleIds()),
                'plugin_sprint_sprints_id'         => array_merge([0], self::visibleSprintIds()),
                'NOT'                              => ['credits' => 0],
            ],
        ]) as $row) {
            $bucket = self::bucketFor($row);
            if ($bucket === null) {
                continue;
            }
            $cid = (int)$row['plugin_sprint_sprintcustomers_id'];
            $sid = (int)$row['plugin_sprint_sprints_id'];
            self::$usageCache[$cid][$sid] ??= ['consumed' => 0.0, 'reserved' => 0.0, 'pipeline' => 0.0];
            self::$usageCache[$cid][$sid][$bucket] += (float)$row['credits'];
        }
        return self::$usageCache;
    }

    /** Which bucket one item row falls into; null when it counts for nothing. */
    public static function bucketFor(array $row): ?string
    {
        $done = (string)($row['status'] ?? '') === SprintItem::STATUS_DONE;
        if (!$done && (int)($row['is_parked'] ?? 0) === 1) {
            return null;
        }
        if ((int)($row['plugin_sprint_sprints_id'] ?? 0) <= 0) {
            return 'pipeline';
        }
        return $done ? 'consumed' : 'reserved';
    }

    /**
     * Committed credits per customer: consumed = done in a sprint, reserved =
     * in a sprint but open, pipeline = still on the backlog.
     *
     * @return array<int,array{consumed:float,reserved:float,pipeline:float}>
     */
    public static function usageByCustomer(): array
    {
        $out = [];
        foreach (self::itemUsage() as $cid => $bySprint) {
            $out[$cid] = ['consumed' => 0.0, 'reserved' => 0.0, 'pipeline' => 0.0];
            foreach ($bySprint as $bucket) {
                $out[$cid]['consumed'] += $bucket['consumed'];
                $out[$cid]['reserved'] += $bucket['reserved'];
                $out[$cid]['pipeline'] += $bucket['pipeline'];
            }
        }
        return $out;
    }

    /**
     * @param int[] $sprintIds
     * @return array<int,array<int,array{consumed:float,reserved:float}>> [customer][sprint]
     */
    public static function usageBySprint(array $sprintIds): array
    {
        $out  = [];
        $keep = array_flip(array_map('intval', $sprintIds));
        foreach (self::itemUsage() as $cid => $bySprint) {
            foreach ($bySprint as $sid => $bucket) {
                if ($sid > 0 && isset($keep[$sid])) {
                    $out[$cid][$sid] = ['consumed' => $bucket['consumed'], 'reserved' => $bucket['reserved']];
                }
            }
        }
        return $out;
    }

    // =========================================================================
    // Retainer: a recurring per-sprint grant that lapses at the sprint's end
    // =========================================================================

    /**
     * Sprints the funding runs over, oldest first; cancelled ones grant nothing.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function fundingSprints(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = array_values((new Sprint())->find(
                ['status' => [Sprint::STATUS_PLANNED, Sprint::STATUS_ACTIVE, Sprint::STATUS_COMPLETED]]
                    + getEntitiesRestrictCriteria(Sprint::getTable(), '', '', true),
                ['date_start ASC', 'id ASC']
            ));
        }
        return $cache;
    }

    /** The per-sprint table as stored, no session scope: bookkeeping only. */
    private static function loadOverrides(): array
    {
        global $DB;

        if (self::$overrideCache !== null) {
            return self::$overrideCache;
        }
        self::$overrideCache = [];
        if (!$DB->tableExists(self::SPRINT_CREDITS_TABLE)) {
            return self::$overrideCache;
        }
        foreach ($DB->request(['FROM' => self::SPRINT_CREDITS_TABLE]) as $row) {
            self::$overrideCache[(int)$row['plugin_sprint_sprints_id']][(int)$row['plugin_sprint_sprintcustomers_id']] = [
                'credits' => $row['credits'] === null ? null : (float)$row['credits'],
                'max'     => $row['max_credits'] === null ? null : (float)$row['max_credits'],
            ];
        }
        return self::$overrideCache;
    }

    /**
     * A sprint may be given more (or less) than the standing agreement.
     * Commercial figures: empty without the credits right.
     *
     * @return array<int,array<int,array{credits:?float,max:?float}>> [sprint][customer]
     */
    public static function getSprintOverrides(): array
    {
        return self::canViewCredits() ? self::loadOverrides() : [];
    }

    /** The agreement for a customer × sprint pair, on the rows. */
    private static function resolve(array $customer, array $sprint): array
    {
        return SprintCreditMath::resolveAgreement(
            SprintRetainer::rulesFor((int)$customer['id']),
            $sprint,
            self::loadOverrides()[(int)$sprint['id']][(int)$customer['id']] ?? null,
            self::customerFitsEntity($customer, (int)($sprint['entities_id'] ?? 0))
        );
    }

    /**
     * The agreement in force for one customer × sprint — the override when
     * one is recorded, else the retainer inside its window — or null for a
     * pair the session may not see.
     *
     * @return array{grant:float,cap:float,source:string}|null
     */
    public static function agreementFor(int $customerId, int $sprintId): ?array
    {
        if (!self::canViewCredits()) {
            return null;
        }
        $customer = self::getAll(false)[$customerId] ?? null;
        $sprint   = self::sprintRow($sprintId);
        if ($customer === null || $sprint === null) {
            return null;
        }
        return self::resolve($customer, $sprint);
    }

    /**
     * What an empty override field falls back to: the standing retainer and
     * cap for that sprint, 0 outside the window or the sprint's entity.
     *
     * @return array{grant:float,cap:float}
     */
    public static function standingAgreementFor(int $customerId, int $sprintId): array
    {
        $customer = self::canViewCredits() ? (self::getAll(false)[$customerId] ?? null) : null;
        $sprint   = self::sprintRow($sprintId);
        if ($customer === null || $sprint === null) {
            return ['grant' => 0.0, 'cap' => 0.0];
        }
        $resolved = SprintCreditMath::resolveAgreement(
            SprintRetainer::rulesFor($customerId),
            ['status' => Sprint::STATUS_PLANNED] + $sprint,
            null,
            self::customerFitsEntity($customer, (int)($sprint['entities_id'] ?? 0))
        );
        return ['grant' => $resolved['grant'], 'cap' => $resolved['cap']];
    }

    /**
     * Freeze the agreement in force into the per-sprint table when a sprint
     * completes, so the figures it reports stay what they were on the day it
     * closed: a retainer or cap changed later never rewrites them.
     *
     * Bookkeeping, not a view: every customer valid for the sprint's entity
     * is considered, whatever the acting session may see. A value already
     * recorded by hand wins; a partial record is completed, never replaced.
     * Running it twice changes nothing. A closed sprint without a record
     * resolves to nothing, so a zero agreement needs no row.
     */
    public static function snapshotAgreements(int $sprintId): void
    {
        $sprint = new Sprint();
        if ($sprintId <= 0 || !$sprint->getFromDB($sprintId)) {
            return;
        }
        $entityId  = (int)($sprint->fields['entities_id'] ?? 0);
        $overrides = self::loadOverrides()[$sprintId] ?? [];
        $failed    = 0;
        foreach ((new self())->find([], ['id ASC']) as $customer) {
            $cid = (int)$customer['id'];
            if (!self::customerFitsEntity($customer, $entityId)) {
                continue;
            }
            $over = $overrides[$cid] ?? ['credits' => null, 'max' => null];
            if ($over['credits'] !== null && $over['max'] !== null) {
                continue;
            }
            $standing = SprintCreditMath::retainerFor(SprintRetainer::rawRulesFor($cid), $sprint->fields);
            $grant    = $over['credits'] ?? $standing['grant'];
            $cap      = $over['max'] ?? $standing['cap'];
            if ($grant <= 0 && $cap <= 0) {
                continue;
            }
            if (!self::setSprintCredits($sprintId, $cid, $grant, $cap)) {
                $failed++;
            }
        }
        if ($failed > 0) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __('The credit agreement could not be recorded for %d customer(s) on this sprint. Until it is, their figures follow the current retainer.', 'sprint'),
                    $failed
                ),
                false,
                WARNING
            );
        }
    }

    /** Two nulls clear the override, so the standing retainer applies again. */
    public static function setSprintCredits(int $sprintId, int $customerId, ?float $credits, ?float $max): bool
    {
        global $DB;

        if ($sprintId <= 0 || $customerId <= 0 || !$DB->tableExists(self::SPRINT_CREDITS_TABLE)) {
            return false;
        }
        $credits = $credits === null ? null : max(0.0, round($credits, 2));
        $max     = $max === null ? null : max(0.0, round($max, 2));

        $existing = $DB->request([
            'FROM'  => self::SPRINT_CREDITS_TABLE,
            'WHERE' => [
                'plugin_sprint_sprints_id'         => $sprintId,
                'plugin_sprint_sprintcustomers_id' => $customerId,
            ],
        ])->current();

        // Every aggregate derives from this table.
        self::$overrideCache = null;
        self::$fundingCache  = null;

        if ($credits === null && $max === null) {
            return $existing === null
                || (bool)$DB->delete(self::SPRINT_CREDITS_TABLE, ['id' => (int)$existing['id']]);
        }
        $values = ['credits' => $credits, 'max_credits' => $max, 'date_mod' => date('Y-m-d H:i:s')];
        if ($existing === null) {
            return (bool)$DB->insert(self::SPRINT_CREDITS_TABLE, $values + [
                'plugin_sprint_sprints_id'         => $sprintId,
                'plugin_sprint_sprintcustomers_id' => $customerId,
            ]);
        }
        return (bool)$DB->update(self::SPRINT_CREDITS_TABLE, $values, ['id' => (int)$existing['id']]);
    }

    /**
     * The day a sprint's wallet draw is dated: its end date, else its start
     * date, never later than today. A running sprint draws today.
     */
    private static function drawDateFor(array $sprint, string $today): string
    {
        if ((string)($sprint['status'] ?? '') !== Sprint::STATUS_COMPLETED) {
            return $today;
        }
        $date = self::dateValue($sprint['date_end'] ?? null) ?: self::dateValue($sprint['date_start'] ?? null) ?: $today;
        return min($date, $today);
    }

    /**
     * Per customer per sprint: the agreement and how the sprint's work splits
     * over it and the wallet (see SprintCreditMath::splitSprint()). Cells with
     * neither a grant nor a claim are left out. Empty without the credits right.
     *
     * @return array<int,array<int,array<string,mixed>>> [customer][sprint]
     */
    public static function sprintFunding(): array
    {
        if (self::$fundingCache !== null) {
            return self::$fundingCache;
        }
        self::$fundingCache = [];
        if (!self::canViewCredits()) {
            return self::$fundingCache;
        }

        $sprints = self::fundingSprints();
        $usage   = self::itemUsage();
        $today   = date('Y-m-d');
        foreach (self::getAll(false) as $cid => $customer) {
            $cid = (int)$cid;
            foreach ($sprints as $sprint) {
                $sid       = (int)$sprint['id'];
                $bucket    = $usage[$cid][$sid] ?? null;
                $agreement = self::resolve($customer, $sprint);
                $completed = (string)$sprint['status'] === Sprint::STATUS_COMPLETED;
                $split     = SprintCreditMath::splitSprint(
                    $agreement['grant'],
                    $agreement['cap'],
                    $bucket ? (float)$bucket['consumed'] : 0.0,
                    $bucket ? (float)$bucket['reserved'] : 0.0,
                    $completed
                );
                if ($split['grant'] <= 0 && $split['claimed'] <= 0) {
                    continue;
                }
                self::$fundingCache[$cid][$sid] = $split + [
                    // Names the screens grew up with.
                    'used'      => $split['grant_used'],
                    'expired'   => $split['lapsed'],
                    'overflow'  => $split['wallet_consumed'],
                    'completed' => $completed,
                    'source'    => $agreement['source'],
                    'draw_date' => self::drawDateFor($sprint, $today),
                ];
            }
        }
        return self::$fundingCache;
    }

    /** @return array<string,mixed>|null null when there is neither a grant nor a claim */
    public static function fundingFor(int $customerId, int $sprintId): ?array
    {
        return self::sprintFunding()[$customerId][$sprintId] ?? null;
    }

    /**
     * Balance sheet per customer, in name order.
     *
     * The retainer half sums the sprint cells: granted, delivered against the
     * grant, reserved on it, lapsed. The wallet half is built chronologically
     * from the ledger and the draws the sprints made past their grant (see
     * SprintCreditMath::wallet()): what is left today, what lapsed unused,
     * what open work still needs beyond the grants (wallet_reserved), and
     * available = wallet_balance − wallet_reserved, the room to plan new work
     * in. The pipeline is reported apart: it is not committed to a sprint
     * yet, it is only what the balance has to absorb next.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function balances(bool $onlyActive = true): array
    {
        $ledger  = self::ledgerByCustomer();
        $usage   = self::usageByCustomer();
        $funding = self::sprintFunding();
        $today   = date('Y-m-d');

        $out = [];
        foreach (self::getAll($onlyActive) as $id => $row) {
            $id  = (int)$id;
            $use = $usage[$id] ?? ['consumed' => 0.0, 'reserved' => 0.0, 'pipeline' => 0.0];

            $granted = $grantUsed = $grantReserved = $expired = $overflow = $walletReserved = 0.0;
            $draws   = [];
            foreach ($funding[$id] ?? [] as $cell) {
                $granted        += $cell['grant'];
                $grantUsed      += $cell['grant_used'];
                $grantReserved  += $cell['grant_reserved'];
                $expired        += $cell['lapsed'];
                $overflow       += $cell['wallet_consumed'];
                $walletReserved += $cell['wallet_reserved'];
                if ($cell['wallet_consumed'] > 0) {
                    $draws[] = ['date' => $cell['draw_date'], 'credits' => $cell['wallet_consumed']];
                }
            }
            $wallet = SprintCreditMath::wallet($ledger[$id] ?? [], $draws, $today);
            // The rule in force today, and the next one when a change is planned.
            $current = SprintCreditMath::retainerFor(SprintRetainer::rulesFor($id), ['date_start' => $today]);
            $next    = SprintRetainer::nextRuleFor($id, $today);

            $out[$id] = [
                'id'              => $id,
                'name'            => (string)$row['name'],
                'color'           => (string)$row['color'],
                'is_active'       => (int)($row['is_active'] ?? 1),
                'alert'           => (float)($row['credit_alert'] ?? 0),
                'per_sprint'      => $current['grant'],
                'cap'             => $current['cap'],
                'next_rule'       => $next,
                // Retainer half.
                'granted'         => SprintCreditMath::round2($granted),
                'grant_used'      => SprintCreditMath::round2($grantUsed),
                'grant_reserved'  => SprintCreditMath::round2($grantReserved),
                'expired'         => SprintCreditMath::round2($expired),
                // Wallet half.
                'purchased'       => $wallet['booked'],
                'corrections'     => $wallet['corrections'],
                'purchase_lapsed' => $wallet['lapsed'],
                'future'          => $wallet['future'],
                'uncovered'       => $wallet['uncovered'],
                'overflow'        => SprintCreditMath::round2($overflow),
                'wallet_reserved' => SprintCreditMath::round2($walletReserved),
                'wallet_balance'  => $wallet['balance'],
                'available'       => SprintCreditMath::round2($wallet['balance'] - $walletReserved),
                // Work, whichever side pays for it.
                'consumed'        => (float)$use['consumed'],
                'reserved'        => (float)$use['reserved'],
                'pipeline'        => (float)$use['pipeline'],
            ];
        }
        return $out;
    }

    /**
     * Credits on items with no customer, so unattributed work stays visible.
     *
     * @return array{consumed:float,reserved:float,pipeline:float}
     */
    public static function unassignedUsage(): array
    {
        $usage = self::usageByCustomer();
        return $usage[0] ?? ['consumed' => 0.0, 'reserved' => 0.0, 'pipeline' => 0.0];
    }

    // =========================================================================
    // Form
    // =========================================================================

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Name') . "</td><td>";
        echo Html::input('name', ['value' => $this->fields['name'] ?? '', 'size' => 40]);
        echo "</td>";
        echo "<td>" . __('Reference code', 'sprint') . "</td><td>";
        echo Html::input('code', ['value' => $this->fields['code'] ?? '', 'size' => 20]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Contact', 'sprint') . "</td><td>";
        echo Html::input('contact', ['value' => $this->fields['contact'] ?? '', 'size' => 40]);
        echo "</td>";
        echo "<td>" . __('Email') . "</td><td>";
        echo Html::input('email', ['value' => $this->fields['email'] ?? '', 'size' => 40]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Color', 'sprint') . "</td><td>";
        echo "<input type='color' class='form-control form-control-color' name='color' value='"
            . htmlescape((string)($this->fields['color'] ?? '#0d6efd')) . "'>";
        echo "</td>";
        echo "<td>" . __('Low-balance alert', 'sprint') . "<br>";
        echo "<span class='text-muted sprint-small'>"
            . __('Flag the customer once fewer than this many credits are left. 0 = no alert.', 'sprint')
            . "</span></td><td>";
        echo "<input type='number' class='form-control' name='credit_alert' min='0' step='0.25' style='max-width:140px;' value='"
            . self::formatCredits($this->fields['credit_alert'] ?? 0) . "'>";
        echo "</td></tr>";

        // Retainer rules: dated rows edited inline, saved with the customer.
        SprintRetainer::renderEditor((int)$ID > 0 ? (int)$ID : 0, self::canEditCredits());

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Active') . "</td><td>";
        \Dropdown::showYesNo('is_active', $this->fields['is_active'] ?? 1);
        echo "</td><td>" . __('Comments') . "</td><td>";
        echo "<textarea class='form-control' name='comment' rows='3'>"
            . htmlescape((string)($this->fields['comment'] ?? '')) . "</textarea>";
        echo "</td></tr>";

        $this->showFormButtons($options);
        return true;
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'code',
            'name'     => __('Reference code', 'sprint'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'contact',
            'name'     => __('Contact', 'sprint'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'email',
            'name'     => __('Email'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'is_active',
            'name'     => __('Active'),
            'datatype' => 'bool',
        ];
        // DECIMAL column: 'integer' would strip the decimal point in filterValues().
        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'credit_alert',
            'name'     => __('Low-balance alert', 'sprint'),
            'datatype' => 'decimal',
        ];
        return $tab;
    }

    public function prepareInputForAdd($input)
    {
        return $this->sanitize($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->sanitize($input);
    }

    /** Same hex-colour rule as {@see SprintCategory}; the colour reaches an inline style. */
    private function sanitize(array $input): array
    {
        if (isset($input['color'])) {
            $color = trim((string)$input['color']);
            $input['color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#0d6efd';
        }
        if (isset($input['credit_alert'])) {
            $input['credit_alert'] = self::normalizeCredits($input['credit_alert']);
        }
        if (isset($input['is_active'])) {
            $input['is_active'] = (int)(bool)$input['is_active'];
        }
        return $input;
    }

    public function post_addItem()
    {
        self::invalidateCaches();
        parent::post_addItem();
    }

    public function post_updateItem($history = true)
    {
        self::invalidateCaches();
        parent::post_updateItem($history);
    }

    /** Purging a customer leaves its items in place, unattributed. */
    public function post_purgeItem()
    {
        global $DB;

        self::invalidateCaches();

        $DB->update(SprintItem::getTable(), ['plugin_sprint_sprintcustomers_id' => 0], [
            'plugin_sprint_sprintcustomers_id' => (int)$this->getID(),
        ]);
        $DB->delete(SprintCredit::getTable(), [
            'plugin_sprint_sprintcustomers_id' => (int)$this->getID(),
        ]);
        $DB->delete(self::SPRINT_CREDITS_TABLE, [
            'plugin_sprint_sprintcustomers_id' => (int)$this->getID(),
        ]);
        $DB->delete(SprintRetainer::getTable(), [
            'plugin_sprint_sprintcustomers_id' => (int)$this->getID(),
        ]);
        SprintRetainer::invalidate();
    }
}
