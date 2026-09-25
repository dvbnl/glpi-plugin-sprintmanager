<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use CommonGLPI;
use Html;
use Session;

/**
 * One movement in a customer's credit ledger: a purchase (positive) or a
 * correction (negative). A ledger rather than a single balance field, so the
 * credits page can chart when credits came in and forecast when they run out.
 */
class SprintCredit extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_credits';

    public $dohistory = true;

    public static function getTypeName($nb = 0): string
    {
        return _n('Credit booking', 'Credit bookings', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-coins';
    }

    public static function canView(): bool
    {
        return SprintCustomer::canViewCredits();
    }

    // Same right model as the customer itself: CREATE books, UPDATE edits,
    // PURGE deletes permanently. A named credit manager holds all three.
    public static function canCreate(): bool
    {
        return Session::haveRight(self::$rightname, CREATE) || Config::currentUserCreditLevel() === 'manage';
    }

    public static function canUpdate(): bool
    {
        return SprintCustomer::canEditCredits();
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(self::$rightname, PURGE) || Config::currentUserCreditLevel() === 'manage';
    }

    /** Also guards a direct delete() call, not only the form handler. */
    public function pre_deleteItem()
    {
        if (!static::canPurge()) {
            Session::addMessageAfterRedirect(__('You are not allowed to delete credit bookings', 'sprint'), false, ERROR);
            return false;
        }
        return parent::pre_deleteItem();
    }

    // =========================================================================
    // Tab on SprintCustomer
    // =========================================================================

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof SprintCustomer) {
            $count = countElementsInTable(self::getTable(), [
                'plugin_sprint_sprintcustomers_id' => (int)$item->getID(),
            ]);
            return self::createTabEntry(__('Credits', 'sprint'), $count, $item::getType(), self::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof SprintCustomer) {
            self::showForCustomer($item);
            return true;
        }
        return false;
    }

    /** Ledger + "book credits" form for one customer. */
    public static function showForCustomer(SprintCustomer $customer): void
    {
        $customerId = (int)$customer->getID();
        $canbook    = static::canCreate();
        $canpurge   = static::canPurge();

        $balances = SprintCustomer::balances(false);
        $balance  = $balances[$customerId] ?? null;

        echo "<div class='center'>";

        if ($balance !== null) {
            echo "<div class='sprint-credit-tiles'>";
            foreach (self::balanceTiles($balance) as $tile) {
                echo self::renderTile($tile[0], $tile[1], $tile[2], $tile[3]);
            }
            echo "</div>";
        }

        if ($canbook) {
            echo "<form method='post' action='" . self::getFormURL() . "'>";
            echo Html::hidden('plugin_sprint_sprintcustomers_id', ['value' => $customerId]);
            echo "<table class='tab_cadre_fixe sprint-themed'>";
            echo "<tr class='tab_bg_2'><th colspan='4'><i class='" . self::getIcon() . " me-1'></i>"
                . __('Book credits', 'sprint') . "</th></tr>";
            echo "<tr class='tab_bg_1'><td colspan='4' class='text-muted' style='font-size:0.85em;'>"
                . __('A positive amount is a purchase, a negative amount a correction or write-off. Leave the expiry date empty for credits that never lapse.', 'sprint')
                . "</td></tr>";
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Description', 'sprint') . "</td><td>";
            echo Html::input('name', ['value' => '', 'size' => 30]);
            echo "</td><td>" . __('Credits', 'sprint') . "</td><td>";
            echo "<input type='number' class='form-control' name='credits' step='0.25' style='max-width:140px;' value='0'>";
            echo "</td></tr>";
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Reference', 'sprint') . "</td><td>";
            echo Html::input('reference', ['value' => '', 'size' => 30]);
            echo "</td><td>" . __('Booking date', 'sprint') . "</td><td>";
            Html::showDateField('date', ['value' => date('Y-m-d')]);
            echo "</td></tr>";
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Expires on', 'sprint') . "</td><td>";
            Html::showDateField('date_expire', ['value' => '']);
            echo "</td><td colspan='2' class='center'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
        }

        $rows = (new self())->find(
            ['plugin_sprint_sprintcustomers_id' => $customerId],
            ['date DESC', 'id DESC']
        );

        echo "<table class='tab_cadre_fixe sprint-themed' style='margin-top:14px;'>";
        echo "<tr class='tab_bg_2'>"
            . "<th>" . __('Booking date', 'sprint') . "</th>"
            . "<th>" . __('Description', 'sprint') . "</th>"
            . "<th>" . __('Reference', 'sprint') . "</th>"
            . "<th>" . __('Expires on', 'sprint') . "</th>"
            . "<th>" . __('Booked by', 'sprint') . "</th>"
            . "<th class='center'>" . __('Credits', 'sprint') . "</th>"
            . ($canpurge ? "<th class='center'>" . __('Actions') . "</th>" : '')
            . "</tr>";

        if (count($rows) === 0) {
            echo "<tr class='tab_bg_1'><td colspan='" . ($canpurge ? 7 : 6) . "' class='center'>"
                . __('No credits booked yet', 'sprint') . "</td></tr>";
        }

        $today = date('Y-m-d');
        foreach ($rows as $row) {
            $credits = (float)$row['credits'];
            $expire  = (string)($row['date_expire'] ?? '');
            $expired = $expire !== '' && $expire !== 'NULL' && $expire < $today;
            echo "<tr class='tab_bg_1'" . ($expired ? " style='opacity:0.55;'" : '') . ">";
            echo "<td>" . htmlescape(Html::convDate((string)($row['date'] ?? ''))) . "</td>";
            echo "<td>" . htmlescape((string)($row['name'] ?? '')) . "</td>";
            echo "<td>" . htmlescape((string)($row['reference'] ?? '')) . "</td>";
            echo "<td>" . ($expire !== '' && $expire !== 'NULL'
                ? htmlescape(Html::convDate($expire)) . ($expired
                    ? " <span class='badge bg-secondary'>" . __('expired', 'sprint') . "</span>"
                    : '')
                : "<span class='text-muted'>—</span>") . "</td>";
            $bookedBy = (int)($row['users_id'] ?? 0);
            echo "<td class='text-muted sprint-small'>"
                . ($bookedBy > 0 ? htmlescape(\getUserName($bookedBy)) : '—') . "</td>";
            echo "<td class='center fw-bold' style='color:" . ($credits < 0 ? '#dc3545' : '#198754') . ";'>"
                . ($credits > 0 ? '+' : '') . SprintCustomer::formatCredits($credits) . "</td>";
            if ($canpurge) {
                echo "<td class='center'>";
                echo "<form method='post' action='" . self::getFormURL() . "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => (int)$row['id']]);
                echo "<button type='submit' name='purge' class='btn btn-sm btn-outline-danger' "
                    . "title='" . __s('Delete permanently') . "'><i class='fas fa-trash'></i></button>";
                Html::closeForm();
                echo "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
    }

    /**
     * The figures describing one customer's position: the retainer half
     * (granted, used, lapsed) and the wallet half (bought, drawn, left).
     *
     * @return array<int,array{0:string,1:string,2:string,3:string}> label, value, icon, colour
     */
    public static function balanceTiles(array $balance): array
    {
        $left      = (float)($balance['wallet_balance'] ?? $balance['available'] ?? 0);
        $free      = (float)($balance['available'] ?? $left);
        $alert     = (float)($balance['alert'] ?? 0);
        $leftColor = $left < 0
            ? '#dc3545'
            : (($free < 0 || ($alert > 0 && $free < $alert)) ? '#fd7e14' : '#198754');
        $expired  = (float)($balance['expired'] ?? 0);
        $reserved = (float)($balance['wallet_reserved'] ?? 0);

        return [
            [__('Granted', 'sprint'), SprintCustomer::formatCredits($balance['granted'] ?? 0), 'fas fa-repeat', '#0d6efd'],
            [__('Used', 'sprint'), SprintCustomer::formatCredits($balance['grant_used'] ?? 0), 'fas fa-circle-check', '#198754'],
            [__('Lapsed', 'sprint'), SprintCustomer::formatCredits($expired), 'fas fa-hourglass-end', $expired > 0 ? '#dc3545' : '#6c757d'],
            [__('In backlog', 'sprint'), SprintCustomer::formatCredits($balance['pipeline'] ?? 0), 'fas fa-layer-group', '#6c757d'],
            [__('Bought', 'sprint'), SprintCustomer::formatCredits($balance['purchased'] ?? 0), 'fas fa-cart-shopping', '#6f42c1'],
            [__('From wallet', 'sprint'), SprintCustomer::formatCredits($balance['overflow'] ?? 0), 'fas fa-arrow-down', '#fd7e14'],
            [__('Reserved', 'sprint'), SprintCustomer::formatCredits($reserved), 'fas fa-bookmark', $reserved > 0 ? '#fd7e14' : '#6c757d'],
            [__('Left', 'sprint'), SprintCustomer::formatCredits($left), 'fas fa-wallet', $leftColor],
        ];
    }

    public static function renderTile(string $label, string $value, string $icon, string $color): string
    {
        return "<div class='sprint-credit-tile' style='--sprint-tile-color:" . htmlescape($color) . ";'>"
            . "<div class='sprint-credit-tile-icon'><i class='" . htmlescape($icon) . "'></i></div>"
            . "<div class='sprint-credit-tile-value'>" . htmlescape($value) . "</div>"
            . "<div class='sprint-credit-tile-label'>" . htmlescape($label) . "</div>"
            . "</div>";
    }

    // =========================================================================
    // Persistence
    // =========================================================================

    public function prepareInputForAdd($input)
    {
        return $this->sanitize($input, static::canCreate());
    }

    public function prepareInputForUpdate($input)
    {
        return $this->sanitize($input, static::canUpdate());
    }

    public function post_addItem()
    {
        SprintCustomer::invalidateCaches();
        parent::post_addItem();
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

    /**
     * Corrections may be negative, so the amount is rounded, never clamped.
     *
     * @return array<string,mixed>|false false aborts the write
     */
    private function sanitize(array $input, bool $allowed)
    {
        if (!$allowed) {
            Session::addMessageAfterRedirect(
                __('You are not allowed to manage credits', 'sprint'),
                false,
                ERROR
            );
            return false;
        }
        if (isset($input['plugin_sprint_sprintcustomers_id'])) {
            $input['plugin_sprint_sprintcustomers_id'] = (int)$input['plugin_sprint_sprintcustomers_id'];
        }
        if (array_key_exists('credits', $input)) {
            $input['credits'] = round((float)$input['credits'], 2);
        }
        foreach (['date', 'date_expire'] as $field) {
            if (array_key_exists($field, $input)) {
                $value = trim((string)$input[$field]);
                $input[$field] = ($value === '' || $value === 'NULL' || str_starts_with($value, '0000-00-00'))
                    ? 'NULL'
                    : substr($value, 0, 10);
            }
        }
        if (!isset($input['id'])) {
            $input['users_id'] = (int)Session::getLoginUserID();
            if (!isset($input['date']) || $input['date'] === 'NULL') {
                $input['date'] = date('Y-m-d');
            }
        }
        return $input;
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'        => 3,
            'table'     => SprintCustomer::getTable(),
            'field'     => 'name',
            'linkfield' => 'plugin_sprint_sprintcustomers_id',
            'name'      => SprintCustomer::getTypeName(1),
            'datatype'  => 'dropdown',
        ];
        // DECIMAL column: 'integer' would strip the decimal point in filterValues().
        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'credits',
            'name'     => __('Credits', 'sprint'),
            'datatype' => 'decimal',
        ];
        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'date',
            'name'     => __('Booking date', 'sprint'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'date_expire',
            'name'     => __('Expires on', 'sprint'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'reference',
            'name'     => __('Reference', 'sprint'),
            'datatype' => 'string',
        ];

        return $tab;
    }
}
