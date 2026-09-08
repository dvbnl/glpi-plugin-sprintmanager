<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use Html;
use Session;

/**
 * One retainer rule of a customer: from its start date the customer is
 * granted this many credits per sprint (with an optional cap), until the
 * next rule starts. A newer rule always ends the one before it; a rule with
 * 0 credits ends the retainer. A rule without a start date applies from the
 * beginning. Rules are edited inline on the customer form.
 */
class SprintRetainer extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_credits';

    public $dohistory = true;

    /** @var array<int,array<int,array{id:int,date_start:string,credits:float,cap:float,comment:string}>>|null */
    private static ?array $cache = null;

    public static function getTypeName($nb = 0): string
    {
        return _n('Retainer rule', 'Retainer rules', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-repeat';
    }

    public static function canView(): bool
    {
        return SprintCustomer::canViewCredits();
    }

    public static function canCreate(): bool
    {
        return SprintCustomer::canEditCredits();
    }

    public static function canUpdate(): bool
    {
        return SprintCustomer::canEditCredits();
    }

    public static function canPurge(): bool
    {
        return SprintCustomer::canEditCredits();
    }

    // =========================================================================
    // Lookup
    // =========================================================================

    /** Drop the per-request cache after a write. */
    public static function invalidate(): void
    {
        self::$cache = null;
    }

    /**
     * Every rule of the customers the session may see, oldest first (no
     * date, then date, then id) — the order SprintCreditMath::retainerFor()
     * expects. One query per request.
     *
     * @return array<int,array<int,array{id:int,date_start:string,credits:float,cap:float,comment:string}>> by customer
     */
    public static function rulesByCustomer(): array
    {
        global $DB;

        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        if (!$DB->tableExists(self::getTable())) {
            return self::$cache;
        }
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'ORDER' => ['plugin_sprint_sprintcustomers_id ASC', 'date_start ASC', 'id ASC'],
        ]) as $row) {
            self::$cache[(int)$row['plugin_sprint_sprintcustomers_id']][] = self::shape($row);
        }
        // NULL sorts first in MySQL, so "no date" already leads; keep that
        // guarantee whatever the engine does.
        foreach (self::$cache as &$rules) {
            usort($rules, static fn(array $a, array $b): int => [$a['date_start'], $a['id']] <=> [$b['date_start'], $b['id']]);
        }
        unset($rules);
        return self::$cache;
    }

    /** @return array<int,array{id:int,date_start:string,credits:float,cap:float,comment:string}> */
    public static function rulesFor(int $customerId): array
    {
        return self::rulesByCustomer()[$customerId] ?? [];
    }

    /**
     * Rules of one customer regardless of the session's entities — for
     * bookkeeping such as the snapshot on sprint completion.
     *
     * @return array<int,array{id:int,date_start:string,credits:float,cap:float,comment:string}>
     */
    public static function rawRulesFor(int $customerId): array
    {
        $rules = [];
        foreach ((new self())->find(['plugin_sprint_sprintcustomers_id' => $customerId], ['date_start ASC', 'id ASC']) as $row) {
            $rules[] = self::shape($row);
        }
        usort($rules, static fn(array $a, array $b): int => [$a['date_start'], $a['id']] <=> [$b['date_start'], $b['id']]);
        return $rules;
    }

    /** @return array{id:int,date_start:string,credits:float,cap:float,comment:string} */
    private static function shape(array $row): array
    {
        return [
            'id'         => (int)$row['id'],
            'date_start' => SprintCreditMath::dateValue($row['date_start'] ?? null),
            'credits'    => (float)($row['credits_per_sprint'] ?? 0),
            'cap'        => (float)($row['credits_cap_per_sprint'] ?? 0),
            'comment'    => (string)($row['comment'] ?? ''),
        ];
    }

    /**
     * The first rule that starts after $today, or null: what the retainer
     * will change to.
     *
     * @return array{id:int,date_start:string,credits:float,cap:float,comment:string}|null
     */
    public static function nextRuleFor(int $customerId, string $today): ?array
    {
        foreach (self::rulesFor($customerId) as $rule) {
            if ($rule['date_start'] !== '' && $rule['date_start'] > $today) {
                return $rule;
            }
        }
        return null;
    }

    // =========================================================================
    // Inline editor on the customer form
    // =========================================================================

    /**
     * The rules table inside the customer form (one <form>, so the rows are
     * saved with the customer and a rule is removed through a named submit
     * button that the form handler picks up before the update).
     */
    public static function renderEditor(int $customerId, bool $canedit): void
    {
        $today = date('Y-m-d');
        $rules = self::rulesFor($customerId);

        echo "<tr class='tab_bg_2'><th colspan='4'><i class='" . self::getIcon() . " me-1'></i>"
            . __('Retainer', 'sprint') . "</th></tr>";
        echo "<tr class='tab_bg_1'><td colspan='4' class='text-muted' style='font-size:0.85em;'>"
            . __('A retainer grants the customer a fixed number of credits every sprint. Unused credits lapse when that sprint completes; work beyond the grant is drawn from the credits bought through the ledger.', 'sprint')
            . ' '
            . __('A rule applies from its start date until the next rule starts, so a newer rule always ends the one before it. A rule with 0 credits ends the retainer; a rule without a date applies from the beginning.', 'sprint')
            . "</td></tr>";

        if ($customerId <= 0) {
            echo "<tr class='tab_bg_1'><td colspan='4' class='text-muted'>"
                . __('Save the customer first, then add the retainer rules.', 'sprint') . "</td></tr>";
            return;
        }

        echo "<tr class='tab_bg_1'><td colspan='4' style='padding:0;'>";
        echo "<table class='table table-sm mb-0 sprint-retainer-rules'>";
        echo "<thead><tr>"
            . "<th style='width:200px;'>" . __('Starts on', 'sprint') . "</th>"
            . "<th style='width:140px;'>" . __('Credits per sprint', 'sprint') . "</th>"
            . "<th style='width:140px;'>" . __('Cap per sprint', 'sprint') . "</th>"
            . "<th>" . __('Comments') . "</th>"
            . "<th style='width:110px;'></th>"
            . "</tr></thead><tbody>";

        if (!$rules) {
            echo "<tr><td colspan='5' class='text-muted'>" . __('No retainer: this customer only draws on bought credits.', 'sprint') . "</td></tr>";
        }

        $inForceId = null;
        foreach ($rules as $rule) {
            if ($rule['date_start'] === '' || $rule['date_start'] <= $today) {
                $inForceId = $rule['id'];
            }
        }
        foreach ($rules as $rule) {
            $id     = $rule['id'];
            $status = $id === $inForceId
                ? "<span class='badge bg-success'>" . __('In force', 'sprint') . "</span>"
                : (($rule['date_start'] !== '' && $rule['date_start'] > $today)
                    ? "<span class='badge bg-info'>" . __('Upcoming', 'sprint') . "</span>"
                    : "<span class='badge bg-secondary'>" . __('Ended', 'sprint') . "</span>");
            echo "<tr>";
            echo "<td>";
            if ($canedit) {
                Html::showDateField("retainer[{$id}][date_start]", ['value' => $rule['date_start'], 'rand' => 700000 + $id]);
            } else {
                echo $rule['date_start'] !== '' ? htmlescape(Html::convDate($rule['date_start'])) : "<span class='text-muted'>" . __('from the beginning', 'sprint') . "</span>";
            }
            echo "</td>";
            echo "<td><input type='number' class='form-control form-control-sm' name='retainer[{$id}][credits]' min='0' step='0.25' value='"
                . htmlescape(SprintCustomer::formatCredits($rule['credits'])) . "'" . ($canedit ? '' : ' readonly') . "></td>";
            echo "<td><input type='number' class='form-control form-control-sm' name='retainer[{$id}][cap]' min='0' step='0.25' value='"
                . htmlescape(SprintCustomer::formatCredits($rule['cap'])) . "'" . ($canedit ? '' : ' readonly') . "></td>";
            echo "<td><input type='text' class='form-control form-control-sm' name='retainer[{$id}][comment]' value='"
                . htmlescape($rule['comment']) . "'" . ($canedit ? '' : ' readonly') . "></td>";
            echo "<td class='text-nowrap'>" . $status;
            if ($canedit) {
                echo " <button type='submit' name='delete_retainer' value='{$id}' class='btn btn-sm btn-outline-danger' formnovalidate "
                    . "title='" . __s('Remove this rule', 'sprint') . "' onclick=\"return confirm('" . __s('Remove this rule?', 'sprint') . "');\">"
                    . "<i class='fas fa-trash'></i></button>";
            }
            echo "</td></tr>";
        }

        if ($canedit) {
            echo "<tr class='sprint-retainer-new'>";
            echo "<td>";
            Html::showDateField('retainer_new[date_start]', ['value' => '', 'rand' => 699999]);
            echo "</td>";
            echo "<td><input type='number' class='form-control form-control-sm' name='retainer_new[credits]' min='0' step='0.25' value='' placeholder='"
                . __s('Credits', 'sprint') . "'></td>";
            echo "<td><input type='number' class='form-control form-control-sm' name='retainer_new[cap]' min='0' step='0.25' value='' placeholder='"
                . __s('Cap', 'sprint') . "'></td>";
            echo "<td><input type='text' class='form-control form-control-sm' name='retainer_new[comment]' value='' placeholder='"
                . __s('New rule: fill in the credits and save', 'sprint') . "'></td>";
            echo "<td class='text-muted sprint-small'>" . __('New rule', 'sprint') . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
        echo "</td></tr>";
    }

    /**
     * Apply the rows posted with the customer form: changed rules are
     * updated, a filled-in new row is added. Returns the number of writes.
     */
    public static function applyPostedRules(int $customerId, array $post): int
    {
        if ($customerId <= 0 || !SprintCustomer::canEditCredits()) {
            return 0;
        }
        $writes = 0;
        $known  = [];
        foreach (self::rulesFor($customerId) as $rule) {
            $known[$rule['id']] = $rule;
        }
        foreach ((array)($post['retainer'] ?? []) as $id => $row) {
            $id = (int)$id;
            if (!isset($known[$id]) || !is_array($row)) {
                continue;
            }
            $input = self::inputFromRow($row);
            $current = $known[$id];
            if (
                $input['date_start'] === ($current['date_start'] === '' ? 'NULL' : $current['date_start'])
                && $input['credits_per_sprint'] == $current['credits']
                && $input['credits_cap_per_sprint'] == $current['cap']
                && $input['comment'] === $current['comment']
            ) {
                continue;
            }
            $rule = new self();
            if ($rule->update(['id' => $id] + $input)) {
                $writes++;
            }
        }
        $new = (array)($post['retainer_new'] ?? []);
        if (trim((string)($new['credits'] ?? '')) !== '') {
            $rule = new self();
            if ($rule->add(['plugin_sprint_sprintcustomers_id' => $customerId] + self::inputFromRow($new))) {
                $writes++;
            }
        }
        return $writes;
    }

    /** @return array{date_start:string,credits_per_sprint:float,credits_cap_per_sprint:float,comment:string} */
    private static function inputFromRow(array $row): array
    {
        $date = SprintCreditMath::dateValue($row['date_start'] ?? null);
        return [
            'date_start'             => $date === '' ? 'NULL' : $date,
            'credits_per_sprint'     => SprintCustomer::normalizeCredits($row['credits'] ?? 0),
            'credits_cap_per_sprint' => SprintCustomer::normalizeCredits($row['cap'] ?? 0),
            'comment'                => trim((string)($row['comment'] ?? '')),
        ];
    }

    // =========================================================================
    // Persistence
    // =========================================================================

    public function prepareInputForAdd($input)
    {
        return $this->sanitize($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->sanitize($input);
    }

    /** @return array<string,mixed>|false */
    private function sanitize(array $input)
    {
        if (!SprintCustomer::canEditCredits()) {
            Session::addMessageAfterRedirect(__('You are not allowed to manage credits', 'sprint'), false, ERROR);
            return false;
        }
        if (isset($input['plugin_sprint_sprintcustomers_id'])) {
            $input['plugin_sprint_sprintcustomers_id'] = (int)$input['plugin_sprint_sprintcustomers_id'];
        }
        foreach (['credits_per_sprint', 'credits_cap_per_sprint'] as $field) {
            if (isset($input[$field])) {
                $input[$field] = SprintCustomer::normalizeCredits($input[$field]);
            }
        }
        if (array_key_exists('date_start', $input)) {
            $date = SprintCreditMath::dateValue($input['date_start']);
            $input['date_start'] = $date === '' ? 'NULL' : $date;
        }
        return $input;
    }

    public function post_addItem()
    {
        self::invalidate();
        SprintCustomer::invalidateCaches();
        parent::post_addItem();
    }

    public function post_updateItem($history = true)
    {
        self::invalidate();
        SprintCustomer::invalidateCaches();
        parent::post_updateItem($history);
    }

    public function post_purgeItem()
    {
        self::invalidate();
        SprintCustomer::invalidateCaches();
        parent::post_purgeItem();
    }
}
