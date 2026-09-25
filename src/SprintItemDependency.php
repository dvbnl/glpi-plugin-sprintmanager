<?php

namespace GlpiPlugin\Sprint;

use CommonDBRelation;
use CommonGLPI;
use Html;
use Dropdown;

/**
 * Junction linking a SprintItem to a sprint member the owner is waiting on.
 * The parent item keeps its owner; the dependency row consumes part of the
 * helper's sprint capacity until is_resolved = 1 (capacity freed, row kept
 * for audit).
 */
class SprintItemDependency extends CommonDBRelation
{
    public static $itemtype_1 = 'GlpiPlugin\Sprint\SprintItem';
    public static $items_id_1 = 'plugin_sprint_sprintitems_id';
    public static $itemtype_2 = 'User';
    public static $items_id_2 = 'users_id';

    public static $rightname  = 'plugin_sprint_item';
    public $dohistory          = true;

    public static function getTypeName($nb = 0): string
    {
        return _n('Dependency', 'Dependencies', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-link';
    }

    /**
     * Guard for DB reads: render paths can run before the migration creates
     * the table, so this prevents a 500 on first load after new code lands.
     */
    public static function isTableReady(): bool
    {
        global $DB;
        return $DB->tableExists(self::getTable());
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!self::isTableReady()) {
            return '';
        }
        if ($item instanceof SprintItem && (int)($item->fields['plugin_sprint_sprints_id'] ?? 0) > 0) {
            $count = countElementsInTable(
                self::getTable(),
                [
                    'plugin_sprint_sprintitems_id' => $item->getID(),
                    'is_resolved'                  => 0,
                ]
            );
            return self::createTabEntry(self::getTypeName(2), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof SprintItem) {
            self::showForSprintItem($item);
            return true;
        }
        return false;
    }

    public function prepareInputForAdd($input)
    {
        if (isset($input['users_id']))                     $input['users_id']                     = (int)$input['users_id'];
        if (isset($input['plugin_sprint_sprintitems_id'])) $input['plugin_sprint_sprintitems_id'] = (int)$input['plugin_sprint_sprintitems_id'];
        if (isset($input['capacity']))                     $input['capacity']                     = SprintMember::normalizeCapacity($input['capacity']);
        if (isset($input['is_resolved']))                  $input['is_resolved']                  = (int)(bool)$input['is_resolved'];
        $input = self::sanitizeCredits($input);

        // One dependency row per (item, member) — table has a UNIQUE index.
        // Catch dupes here for a clear message instead of a DB 500.
        $dupeItemId = (int)($input['plugin_sprint_sprintitems_id'] ?? 0);
        $dupeUserId = (int)($input['users_id'] ?? 0);
        if ($dupeItemId > 0 && $dupeUserId > 0 && self::isTableReady()) {
            $existing = countElementsInTable(self::getTable(), [
                'plugin_sprint_sprintitems_id' => $dupeItemId,
                'users_id'                     => $dupeUserId,
            ]);
            if ($existing > 0) {
                \Session::addMessageAfterRedirect(
                    __('This member is already a dependency on this item — adjust the existing one instead.', 'sprint'),
                    false,
                    ERROR
                );
                return false;
            }
        }

        if (!$this->validateCapacity($input)) {
            return false;
        }
        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['capacity']))     $input['capacity']    = SprintMember::normalizeCapacity($input['capacity']);
        if (isset($input['users_id']))     $input['users_id']    = (int)$input['users_id'];
        if (isset($input['is_resolved']))  $input['is_resolved'] = (int)(bool)$input['is_resolved'];
        $input = self::sanitizeCredits($input, $this->fields);

        if (!$this->validateCapacity($input, (int)($input['id'] ?? 0))) {
            return false;
        }
        return parent::prepareInputForUpdate($input);
    }

    /**
     * Credits + catalogue product, same rules as on the item: an unknown or
     * unpickable product falls back to "by hand", and the product is leading
     * for the amount (see SprintCreditProduct::leadingCredits()).
     */
    private static function sanitizeCredits(array $input, ?array $stored = null): array
    {
        if (isset($input['credits'])) {
            $input['credits'] = SprintCustomer::normalizeCredits($input['credits']);
        }
        if (isset($input['plugin_sprint_sprintcreditproducts_id'])) {
            $productId = (int)$input['plugin_sprint_sprintcreditproducts_id'];
            $input['plugin_sprint_sprintcreditproducts_id'] = SprintCreditProduct::isPickable($productId) ? $productId : 0;
            $leading = SprintCreditProduct::leadingCredits($input['plugin_sprint_sprintcreditproducts_id'], $input, $stored);
            if ($leading !== null) {
                $input['credits'] = $leading;
            }
        }
        return $input;
    }

    public function post_updateItem($history = true)
    {
        SprintCustomer::invalidateCaches();
        parent::post_updateItem($history);
    }

    public function post_purgeItem()
    {
        SprintCustomer::invalidateCaches();
        parent::post_purgeItem();
    }

    public function post_addItem(): void
    {
        SprintCustomer::invalidateCaches();
        $item = new SprintItem();
        $itemId = (int)($this->fields['plugin_sprint_sprintitems_id'] ?? 0);
        if ($itemId > 0 && $item->getFromDB($itemId)) {
            SprintAgility::signal(
                (int)($item->fields['plugin_sprint_sprints_id'] ?? 0),
                (int)($this->fields['users_id'] ?? 0),
                'dependency',
                sprintf(__('You are needed on “%s”.', 'sprint'), (string)$item->fields['name']),
                SprintItem::getFormURLWithID($itemId)
            );
        }
        parent::post_addItem();
    }

    private function validateCapacity(array $input, int $excludeId = 0): bool
    {
        $capacity = (float)($input['capacity'] ?? 0);
        $userId   = (int)($input['users_id'] ?? $this->fields['users_id'] ?? 0);
        $itemId   = (int)($input['plugin_sprint_sprintitems_id'] ?? $this->fields['plugin_sprint_sprintitems_id'] ?? 0);

        if ($capacity <= 0 || $userId <= 0 || $itemId <= 0) {
            return true;
        }

        $sprintItem = new SprintItem();
        if (!$sprintItem->getFromDB($itemId)) {
            return true;
        }
        $sprintId = (int)$sprintItem->fields['plugin_sprint_sprints_id'];
        if ($sprintId <= 0) {
            return true;
        }

        return SprintMember::checkCapacityForUser(
            $sprintId,
            $userId,
            $capacity,
            0,
            0,
            $excludeId,
            true                // dependency allocations may overflow capacity
        );
    }

    public static function showForSprintItem(SprintItem $sprintItem): void
    {
        $itemId   = $sprintItem->getID();
        $sprintId = (int)$sprintItem->fields['plugin_sprint_sprints_id'];
        $canedit  = $sprintItem->canUpdateItem();

        if (!self::isTableReady() || $sprintId <= 0) {
            echo "<div class='center'><p class='text-muted'>" .
                __('Dependencies are sprint-scoped — assign this item to a sprint first.', 'sprint') .
                "</p></div>";
            return;
        }

        if ($canedit) {
            $memberOptions = SprintMember::getSprintMemberOptions($sprintId);
            $ownerId       = (int)($sprintItem->fields['users_id'] ?? 0);
            if ($ownerId > 0) {
                unset($memberOptions[$ownerId]);
            }

            echo "<div class='center'>";
            echo "<form method='post' action='" . static::getFormURL() . "'>";
            echo Html::hidden('plugin_sprint_sprintitems_id', ['value' => $itemId]);

            echo "<table class='tab_cadre_fixe sprint-themed'>";
            echo "<tr class='tab_bg_2'><th colspan='4'>" .
                __('Add a dependency on a colleague for this item', 'sprint') . "</th></tr>";
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Sprint member', 'sprint') . "</td><td>";
            Dropdown::showFromArray('users_id', $memberOptions);
            echo "</td>";
            echo "<td>" . __('Capacity (%)', 'sprint') . "</td><td>";
            Dropdown::showFromArray('capacity', SprintMember::getCapacityChoices(), [
                'value' => 5,
            ]);
            echo "</td></tr>";
            if (SprintCustomer::canUseCredits()) {
                // The helper's own billable share (a check, a review), charged
                // to the parent item's customer on top of the item's credits.
                echo "<tr class='tab_bg_1'>";
                echo "<td><i class='fas fa-tags me-1'></i>" . SprintCreditProduct::getTypeName(1) . "</td><td>";
                echo SprintItem::productSelect('plugin_sprint_sprintcreditproducts_id', 0, 'credits');
                echo "</td>";
                echo "<td><i class='fas fa-coins me-1'></i>" . __('Credits', 'sprint') . "</td><td>";
                echo SprintItem::creditsInput('credits', 0);
                echo "</td></tr>";
                SprintItem::creditProductScript();
            }
            echo "<tr class='tab_bg_1'><td colspan='4' class='center'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        $rel  = new self();
        $rows = $rel->find(['plugin_sprint_sprintitems_id' => $itemId]);

        $showCredits = SprintCustomer::canUseCredits();
        echo "<div class='center'><table class='tab_cadre_fixe sprint-themed'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Helper', 'sprint') . "</th>";
        echo "<th>" . __('Capacity (%)', 'sprint') . "</th>";
        if ($showCredits) {
            echo "<th>" . __('Credits', 'sprint') . "</th>";
        }
        echo "<th>" . __('Status') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($rows) === 0) {
            $cols = 3 + ($canedit ? 1 : 0) + ($showCredits ? 1 : 0);
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('No dependencies yet', 'sprint') . "</td></tr>";
        }

        $openCap      = 0.0;
        $resolvedCap  = 0.0;
        $totalCredits = 0.0;
        foreach ($rows as $row) {
            $uid        = (int)$row['users_id'];
            $cap        = (float)$row['capacity'];
            $capLabel   = SprintMember::formatCapacity($cap);
            $isResolved = (int)($row['is_resolved'] ?? 0) === 1;
            $credits    = (float)($row['credits'] ?? 0);
            $productId  = (int)($row['plugin_sprint_sprintcreditproducts_id'] ?? 0);
            $totalCredits += $credits;
            if ($isResolved) {
                $resolvedCap += $cap;
            } else {
                $openCap += $cap;
            }

            $rowStyle = $isResolved ? "opacity:0.55;" : "";
            echo "<tr class='tab_bg_1' style='{$rowStyle}'>";
            echo "<td><i class='fas fa-user' style='margin-right:6px;opacity:0.6;'></i>" . htmlescape(SprintCache::userName($uid)) . "</td>";
            echo "<td class='center'>{$capLabel}%</td>";
            if ($showCredits) {
                echo "<td class='center'>";
                if ($credits > 0) {
                    $product = $productId > 0 ? SprintCreditProduct::getFullNameFor($productId) : '';
                    echo "<span class='sprint-credit-chip' title='" . htmlescape($product !== '' ? $product : __('Credits', 'sprint')) . "'>"
                        . "<i class='fas fa-coins'></i> " . htmlescape(SprintCustomer::formatCredits($credits)) . "</span>";
                } else {
                    echo "<span class='text-muted'>-</span>";
                }
                echo "</td>";
            }
            echo "<td class='center'>";
            if ($isResolved) {
                echo "<span class='sprint-badge' style='background:#198754;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.78em;'>"
                    . "<i class='fas fa-check'></i> " . __('Resolved', 'sprint') . "</span>";
            } else {
                echo "<span class='sprint-badge' style='background:#6f42c1;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.78em;'>"
                    . "<i class='fas fa-link'></i> " . __('Open', 'sprint') . "</span>";
            }
            echo "</td>";
            if ($canedit) {
                echo "<td class='center' style='white-space:nowrap;'>";
                if (!$isResolved) {
                    echo "<form method='post' action='" . static::getFormURL() . "' style='display:inline-flex;gap:4px;align-items:center;margin-right:6px;'>";
                    echo Html::hidden('id', ['value' => $row['id']]);
                    Dropdown::showFromArray('capacity', SprintMember::getCapacityChoices(), [
                        'value' => SprintMember::capacityKey($cap),
                    ]);
                    if ($showCredits) {
                        echo "<input type='number' min='0' step='0.25' name='credits' class='form-control form-control-sm' "
                            . "style='max-width:90px;' title='" . __s('Credits', 'sprint') . "' value='"
                            . htmlescape(SprintCustomer::formatCredits($credits)) . "'>";
                    }
                    echo "<button type='submit' name='update' value='1' class='btn btn-sm btn-outline-primary' title='" . __('Update') . "'><i class='fas fa-save'></i></button>";
                    Html::closeForm();
                    echo "<form method='post' action='" . static::getFormURL() . "' style='display:inline;margin-right:4px;'>";
                    echo Html::hidden('id', ['value' => $row['id']]);
                    echo Html::submit(__('Resolve', 'sprint'), [
                        'name'  => 'resolve',
                        'class' => 'btn btn-sm btn-outline-success',
                    ]);
                    Html::closeForm();
                } else {
                    echo "<form method='post' action='" . static::getFormURL() . "' style='display:inline;margin-right:4px;'>";
                    echo Html::hidden('id', ['value' => $row['id']]);
                    echo Html::submit(__('Reopen', 'sprint'), [
                        'name'  => 'reopen',
                        'class' => 'btn btn-sm btn-outline-warning',
                    ]);
                    Html::closeForm();
                }
                echo "<form method='post' action='" . static::getFormURL() . "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::submit(__('Remove', 'sprint'), [
                    'name'    => 'purge',
                    'class'   => 'btn btn-sm btn-outline-danger',
                    'confirm' => __('Confirm deletion?'),
                ]);
                Html::closeForm();
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "<tr class='tab_bg_2'>";
        echo "<th class='right'>" . __('Open dependency capacity', 'sprint') . "</th>";
        echo "<th class='center'>" . SprintMember::formatCapacity($openCap) . "%</th>";
        if ($showCredits) {
            echo "<th class='center'>" . htmlescape(SprintCustomer::formatCredits($totalCredits)) . "</th>";
        }
        echo "<th class='center'><span class='text-muted'>" . sprintf(__('Resolved: %s%%', 'sprint'), SprintMember::formatCapacity($resolvedCap)) . "</span></th>";
        if ($canedit) {
            echo "<th></th>";
        }
        echo "</tr>";

        echo "</table></div>";
    }

    /**
     * Dependency credits per parent item, resolved rows included: a helper's
     * check was delivered and billed whether or not the dependency is still
     * open. Same shape as getCapacityByItem().
     *
     * @param int[] $itemIds
     * @return array<int,float> item id => credits
     */
    public static function getCreditsByItem(array $itemIds): array
    {
        global $DB;
        $out     = [];
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if (empty($itemIds) || !self::isTableReady() || !$DB->fieldExists(self::getTable(), 'credits')) {
            return $out;
        }
        foreach ($DB->request([
            'SELECT' => ['plugin_sprint_sprintitems_id', 'SUM' => 'credits AS total'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_sprint_sprintitems_id' => $itemIds],
            'GROUPBY' => 'plugin_sprint_sprintitems_id',
        ]) as $r) {
            $out[(int)$r['plugin_sprint_sprintitems_id']] = (float)$r['total'];
        }
        return $out;
    }

    /**
     * Every dependency row that carries credits, with the parent item's
     * customer, sprint, status, parked flag, category and product — the shape
     * SprintCustomer::bucketFor() and the credit flow charts consume. The
     * parent's product is only a fallback: a helper's check normally picks
     * its own catalogue entry.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function creditRows(): array
    {
        global $DB;
        if (!self::isTableReady() || !$DB->fieldExists(self::getTable(), 'credits')) {
            return [];
        }
        $out = [];
        foreach ($DB->request([
            'SELECT' => [
                'd.id AS dependency_id',
                'd.plugin_sprint_sprintitems_id AS item_id',
                'd.credits AS credits',
                'd.plugin_sprint_sprintcreditproducts_id AS product_id',
                'i.plugin_sprint_sprintcustomers_id',
                'i.plugin_sprint_sprints_id',
                'i.proposed_sprints_id',
                'i.status',
                'i.is_parked',
                'i.plugin_sprint_sprintcategories_id',
                'i.plugin_sprint_sprintcreditproducts_id AS item_product_id',
            ],
            'FROM'   => self::getTable() . ' AS d',
            'INNER JOIN' => [
                SprintItem::getTable() . ' AS i' => ['ON' => ['d' => 'plugin_sprint_sprintitems_id', 'i' => 'id']],
            ],
            'WHERE'  => ['NOT' => ['d.credits' => 0]],
        ]) as $r) {
            $out[] = [
                'dependency_id'                        => (int)$r['dependency_id'],
                'id'                                   => (int)$r['item_id'],
                'credits'                              => (float)$r['credits'],
                'plugin_sprint_sprintcreditproducts_id' => (int)$r['product_id'] > 0 ? (int)$r['product_id'] : (int)$r['item_product_id'],
                'plugin_sprint_sprintcustomers_id'     => (int)$r['plugin_sprint_sprintcustomers_id'],
                'plugin_sprint_sprints_id'             => (int)$r['plugin_sprint_sprints_id'],
                'proposed_sprints_id'                  => (int)$r['proposed_sprints_id'],
                'status'                               => (string)$r['status'],
                'is_parked'                            => (int)$r['is_parked'],
                'plugin_sprint_sprintcategories_id'    => (int)$r['plugin_sprint_sprintcategories_id'],
            ];
        }
        return $out;
    }

    /**
     * @return int[]
     */
    private static function getItemIdsForSprint(int $sprintId): array
    {
        $si  = new SprintItem();
        $ids = [];
        foreach ($si->find(['plugin_sprint_sprints_id' => $sprintId]) as $row) {
            $ids[] = (int)$row['id'];
        }
        return $ids;
    }

    /**
     * Resolved rows still count: the helper already spent that capacity, so
     * freeing it would make the sprint capacity bar understate real spend.
     */
    public static function getUsedDependencyCapacityForUser(int $sprintId, int $userId, int $excludeId = 0): float
    {
        if (!self::isTableReady()) {
            return 0.0;
        }

        $itemIds = self::getItemIdsForSprint($sprintId);
        if (count($itemIds) === 0) {
            return 0.0;
        }

        $criteria = [
            'plugin_sprint_sprintitems_id' => $itemIds,
            'users_id'                     => $userId,
        ];
        if ($excludeId > 0) {
            $criteria['NOT'] = ['id' => $excludeId];
        }

        $rel   = new self();
        $total = 0.0;
        foreach ($rel->find($criteria) as $row) {
            $total += (float)$row['capacity'];
        }
        return $total;
    }

    public static function getTotalOpenDependencyCapacityForSprint(int $sprintId): float
    {
        if (!self::isTableReady()) {
            return 0.0;
        }

        $itemIds = self::getItemIdsForSprint($sprintId);
        if (count($itemIds) === 0) {
            return 0.0;
        }

        $rel   = new self();
        $total = 0.0;
        foreach ($rel->find(['plugin_sprint_sprintitems_id' => $itemIds, 'is_resolved' => 0]) as $row) {
            $total += (float)$row['capacity'];
        }
        return $total;
    }

    public static function purgeForItem(int $sprintItemId): void
    {
        if ($sprintItemId <= 0 || !self::isTableReady()) {
            return;
        }
        (new self())->deleteByCriteria(
            ['plugin_sprint_sprintitems_id' => $sprintItemId],
            1
        );
    }

    public static function countOpenForItem(int $sprintItemId): int
    {
        if ($sprintItemId <= 0 || !self::isTableReady()) {
            return 0;
        }
        return (int)countElementsInTable(
            self::getTable(),
            [
                'plugin_sprint_sprintitems_id' => $sprintItemId,
                'is_resolved'                  => 0,
            ]
        );
    }

    /**
     * @param int[] $itemIds
     * @return array<int, array{users_id:int,name:string,capacity:float}[]>
     */
    public static function getOpenSummariesForItems(array $itemIds): array
    {
        $out = [];
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if (empty($itemIds) || !self::isTableReady()) {
            return $out;
        }
        $rel = new self();
        foreach ($rel->find([
            'plugin_sprint_sprintitems_id' => $itemIds,
            'is_resolved'                  => 0,
        ]) as $r) {
            $itemId = (int)$r['plugin_sprint_sprintitems_id'];
            $uid    = (int)$r['users_id'];
            $out[$itemId][] = [
                'users_id' => $uid,
                'name'     => $uid > 0 ? SprintCache::userName($uid) : '',
                'capacity' => (float)$r['capacity'],
            ];
        }
        return $out;
    }

    /**
     * Dependency capacity per parent item, in one query. Forecasts need this:
     * a dependency claims the helper's capacity on top of the item's own
     * estimate, so any capacity total built from SprintItem.capacity alone
     * understates the real load.
     *
     * @param int[] $itemIds
     * @param bool  $openOnly  false also counts resolved rows (capacity already spent)
     * @return array<int, float>  item id => capacity %
     */
    public static function getCapacityByItem(array $itemIds, bool $openOnly = true): array
    {
        $out = [];
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if (empty($itemIds) || !self::isTableReady()) {
            return $out;
        }
        $criteria = ['plugin_sprint_sprintitems_id' => $itemIds];
        if ($openOnly) {
            $criteria['is_resolved'] = 0;
        }
        foreach ((new self())->find($criteria) as $r) {
            $iid       = (int)$r['plugin_sprint_sprintitems_id'];
            $out[$iid] = ($out[$iid] ?? 0.0) + (float)$r['capacity'];
        }
        return $out;
    }

    /**
     * Sprint items where $userId is helper on an open dependency, with the
     * parent owner's name. Renders "Helpt op:" on the member card.
     *
     * @return array<int, array{item_id:int,name:string,owner_id:int,owner_name:string,capacity:float}>
     */
    public static function getOpenItemsForHelper(int $sprintId, int $userId): array
    {
        $out = [];
        if ($sprintId <= 0 || $userId <= 0 || !self::isTableReady()) {
            return $out;
        }
        $itemIds = self::getItemIdsForSprint($sprintId);
        if (count($itemIds) === 0) {
            return $out;
        }
        $rel  = new self();
        $rows = $rel->find([
            'plugin_sprint_sprintitems_id' => $itemIds,
            'users_id'                     => $userId,
            'is_resolved'                  => 0,
        ]);
        if (count($rows) === 0) {
            return $out;
        }
        $si       = new SprintItem();
        $itemMap  = [];
        $parentIds = array_map(fn($r) => (int)$r['plugin_sprint_sprintitems_id'], $rows);
        foreach ($si->find(['id' => $parentIds]) as $r) {
            $itemMap[(int)$r['id']] = $r;
        }
        foreach ($rows as $r) {
            $itemId = (int)$r['plugin_sprint_sprintitems_id'];
            $item   = $itemMap[$itemId] ?? null;
            if ($item === null) { continue; }
            $ownerId = (int)($item['users_id'] ?? 0);
            $out[] = [
                'item_id'    => $itemId,
                'name'       => (string)($item['name'] ?? ''),
                'owner_id'   => $ownerId,
                'owner_name' => $ownerId > 0 ? SprintCache::userName($ownerId) : '',
                'capacity'   => (float)$r['capacity'],
            ];
        }
        return $out;
    }

    /**
     * Auto-flip the parent SprintItem from STATUS_DEPENDENCY back to
     * STATUS_IN_PROGRESS once its last open dependency closes.
     */
    public static function maybeUnblockParent(int $sprintItemId): void
    {
        if ($sprintItemId <= 0) {
            return;
        }
        if (self::countOpenForItem($sprintItemId) > 0) {
            return;
        }
        $item = new SprintItem();
        if (!$item->getFromDB($sprintItemId)) {
            return;
        }
        if (($item->fields['status'] ?? '') !== SprintItem::STATUS_DEPENDENCY) {
            return;
        }
        $item->update([
            'id'     => $sprintItemId,
            'status' => SprintItem::STATUS_IN_PROGRESS,
        ]);
    }
}
