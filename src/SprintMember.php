<?php

namespace GlpiPlugin\Sprint;

use CommonDBRelation;
use CommonGLPI;
use Html;
use Session;
use User;
use Dropdown;

/**
 * Link GLPI Users to Sprints with a role and a per-sprint capacity %.
 */
class SprintMember extends CommonDBRelation
{
    public static $itemtype_1 = 'GlpiPlugin\Sprint\Sprint';
    public static $items_id_1 = 'plugin_sprint_sprints_id';
    public static $itemtype_2 = 'User';
    public static $items_id_2 = 'users_id';

    public static $rightname  = 'plugin_sprint_sprint';
    public $dohistory          = true;

    const ROLE_SCRUM_MASTER  = 'scrum_master';
    const ROLE_PRODUCT_OWNER = 'product_owner';
    const ROLE_DEVELOPER     = 'developer';
    const ROLE_TESTER        = 'tester';
    const ROLE_DESIGNER      = 'designer';
    const ROLE_DEVOPS        = 'devops';
    const ROLE_ANALYST       = 'analyst';
    const ROLE_OTHER         = 'other';

    public static function getTypeName($nb = 0): string
    {
        return _n('Sprint Member', 'Sprint Members', $nb, 'sprint');
    }

    // Explicit entity scoping on the parent sprint, on top of the relation's
    // own checks (the members table carries no entities_id).
    private function hasSprintEntityAccess(): bool
    {
        return SprintItem::sprintEntityAccessible((int)($this->fields['plugin_sprint_sprints_id'] ?? 0));
    }

    public function canViewItem(): bool
    {
        return $this->hasSprintEntityAccess() && parent::canViewItem();
    }

    public function canCreateItem(): bool
    {
        return $this->hasSprintEntityAccess() && parent::canCreateItem();
    }

    public function canUpdateItem(): bool
    {
        return $this->hasSprintEntityAccess() && parent::canUpdateItem();
    }

    public function canDeleteItem(): bool
    {
        return $this->hasSprintEntityAccess() && parent::canDeleteItem();
    }

    public function canPurgeItem(): bool
    {
        return $this->hasSprintEntityAccess() && parent::canPurgeItem();
    }

    public static function getIcon(): string
    {
        return 'fas fa-user-friends';
    }

    /** Clamp to 0..100 and snap to 0.5% steps. */
    public static function normalizeCapacity($value): float
    {
        $v = round((float)$value * 2) / 2;
        return max(0.0, min(100.0, $v));
    }

    /**
     * Display form: "5" for whole numbers, "0.5" for halves. Snaps to 0.5%
     * steps but does NOT clamp to 100 — callers pass sums, team totals and
     * per-sprint caps that legitimately exceed 100% (clamping belongs to
     * normalizeCapacity, which guards a single member's stored capacity).
     */
    public static function formatCapacity($value): string
    {
        $v = round((float)$value * 2) / 2;
        return ($v === floor($v)) ? (string)(int)$v : number_format($v, 1, '.', '');
    }

    /**
     * Canonical dropdown key so DECIMAL values ("5.0") match
     * getCapacityChoices() keys.
     *
     * @return int|string
     */
    public static function capacityKey($value)
    {
        $v = self::normalizeCapacity($value);
        return ($v === floor($v)) ? (int)$v : number_format($v, 1, '.', '');
    }

    /**
     * Capacity-percent dropdown choices: 0.5 and 1..10 for tiny allocations,
     * then per-5. Estimates start at 0.5% — a 0% allocation adds nothing.
     *
     * @param bool $includeZero Kept for backward compatibility; ignored.
     * @return array<int|string,string> [value => label]
     */
    public static function getCapacityChoices(bool $includeZero = true): array
    {
        $values = ['0.5'];
        for ($i = 1; $i <= 10; $i++) {
            $values[] = $i;
        }
        for ($i = 15; $i <= 100; $i += 5) {
            $values[] = $i;
        }
        $out = [];
        foreach ($values as $v) {
            $out[$v] = $v . '%';
        }
        return $out;
    }

    /**
     * Capacity number input: any value 0..100 on the 0.5 grid, with the
     * getCapacityChoices() presets offered as suggestions (a <datalist>), so
     * 12.5% can be typed while 5/10/50% stay one click away. The server
     * still normalises every write through normalizeCapacity().
     *
     * Options: id, class, aria_label, required (bool), min (default 0),
     * placeholder, attrs (extra raw attribute string, already escaped).
     */
    public static function capacityInput(string $name, $value, array $opts = []): string
    {
        $id   = (string)($opts['id'] ?? ('sprint-cap-' . preg_replace('/[^a-z0-9_]+/i', '-', $name) . '-' . mt_rand()));
        $list = $id . '-presets';
        $min  = self::formatCapacity(max(0.0, (float)($opts['min'] ?? 0)));
        $val  = ($value === '' || $value === null) ? '' : self::formatCapacity(self::normalizeCapacity($value));

        $out = "<input type='number' name='" . htmlescape($name) . "' id='" . htmlescape($id) . "'"
            . " class='" . htmlescape((string)($opts['class'] ?? 'form-control')) . " sprint-cap-input'"
            . " min='{$min}' max='100' step='0.5' inputmode='decimal' list='" . htmlescape($list) . "'"
            . " value='" . htmlescape($val) . "'"
            . (isset($opts['aria_label']) ? " aria-label='" . htmlescape((string)$opts['aria_label']) . "'" : '')
            . (isset($opts['placeholder']) ? " placeholder='" . htmlescape((string)$opts['placeholder']) . "'" : '')
            . (!empty($opts['required']) ? ' required' : '')
            . (isset($opts['attrs']) ? ' ' . $opts['attrs'] : '')
            . ">";
        $out .= "<datalist id='" . htmlescape($list) . "'>";
        foreach (array_keys(self::getCapacityChoices()) as $v) {
            $out .= "<option value='" . htmlescape((string)$v) . "'></option>";
        }
        return $out . "</datalist>";
    }

    /**
     * One member's capacity in one sprint, in the terms every capacity view
     * uses (see SprintCapacityMath): Set − not available = Available,
     * Planned = regular + fastlane + helping (resolved help stays planned,
     * that capacity was spent), Remaining = Available − Planned, or "over".
     * Proposed backlog work is carried separately.
     *
     * @return array see SprintCapacityMath::figures()
     */
    public static function capacityFigures(
        float $set,
        float $available,
        float $regular,
        float $fastlane,
        float $helping,
        float $helpingOpen,
        float $proposed = 0.0
    ): array {
        return SprintCapacityMath::figures($set, $available, $regular, $fastlane, $helping, $helpingOpen, $proposed);
    }

    /**
     * The shared model for a member row of a sprint, straight from the data:
     * effective availability plus the grouped usage of usedCapacityBySprint().
     * Every view (team card, dashboards, report, CSV) calls this, so the same
     * member and sprint always show the same figures.
     *
     * @param array $memberRow glpi_plugin_sprint_sprintmembers row
     */
    public static function figuresForMember(int $sprintId, array $memberRow, float $proposed = 0.0): array
    {
        $userId = (int)($memberRow['users_id'] ?? 0);
        $set    = self::normalizeCapacity($memberRow['capacity_percent'] ?? 0);
        $used   = self::usedCapacityBySprint($sprintId)[$userId] ?? [];
        return self::capacityFigures(
            $set,
            SprintAgility::effectiveCapacity($sprintId, $userId, $set),
            (float)($used['regular'] ?? 0.0),
            (float)($used['fastlane'] ?? 0.0),
            (float)($used['dependency'] ?? 0.0),
            (float)($used['dependency_open'] ?? 0.0),
            $proposed
        );
    }

    /** "15%" style label on the shared rounding (0.5 grid). */
    private static function pct(float $v): string
    {
        return self::formatCapacity($v) . '%';
    }

    /**
     * "Remaining 15%" / "10% over capacity" (or the "after proposals"
     * variants). Over capacity is a difference in percentage points.
     */
    public static function remainingText(array $f, bool $afterProposals = false): string
    {
        $over = $afterProposals ? $f['over_after_proposals'] : $f['over'];
        if ($over > SprintCapacityMath::EPSILON) {
            return $afterProposals
                ? sprintf(__('%s%% over capacity after proposals', 'sprint'), self::formatCapacity($over))
                : sprintf(__('%s%% over capacity', 'sprint'), self::formatCapacity($over));
        }
        return $afterProposals
            ? sprintf(__('Remaining after proposals %s', 'sprint'), self::pct($f['remaining_after_proposals']))
            : sprintf(__('Remaining %s', 'sprint'), self::pct($f['remaining']));
    }

    /**
     * Badge for the shared warning state. Normal planning gets no badge.
     * Full (exactly 0 remaining) and over capacity are distinct states.
     */
    public static function capacityStateBadge(string $state, array $f = []): string
    {
        $map = [
            SprintCapacityMath::STATE_NONE        => ['fa-user-clock', __('Not available in this sprint', 'sprint')],
            SprintCapacityMath::STATE_ALMOST_FULL => ['fa-hourglass-half', __('Almost full', 'sprint')],
            SprintCapacityMath::STATE_FULL        => ['fa-circle-check', __('Full', 'sprint')],
            SprintCapacityMath::STATE_OVER        => ['fa-triangle-exclamation', __('Over capacity', 'sprint')],
        ];
        if (!isset($map[$state])) {
            return '';
        }
        [$icon, $label] = $map[$state];
        $title = $state === SprintCapacityMath::STATE_ALMOST_FULL
            ? sprintf(__('Less than %s%% remaining', 'sprint'), self::formatCapacity(SprintCapacityMath::ALMOST_FULL_PP))
            : $label;
        return "<span class='sprint-cap-state sprint-cap-state-{$state}' title='" . htmlescape($title) . "'>"
            . "<i class='fas {$icon}' aria-hidden='true'></i> " . htmlescape($label) . "</span>";
    }

    /**
     * Accessible description of a bar: whose, which sprint, the scale and
     * the figures. Plain text (callers escape).
     */
    public static function capacityDescription(array $f, string $userName, string $sprintName): string
    {
        $geo   = SprintCapacityMath::bar($f);
        $whose = $sprintName !== ''
            ? sprintf(__('Capacity of %1$s in %2$s', 'sprint'), $userName, $sprintName)
            : sprintf(__('Capacity of %s', 'sprint'), $userName);
        $parts = [
            sprintf(__('Set %s', 'sprint'), self::pct($f['set'])),
        ];
        if ($f['leave'] > 0) {
            $parts[] = sprintf(__('Not available %s', 'sprint'), self::pct($f['leave']));
        }
        $parts[] = sprintf(__('Available %s', 'sprint'), self::pct($f['available']));
        $parts[] = sprintf(__('Planned %s', 'sprint'), self::pct($f['planned']))
            . ' (' . sprintf(__('Regular %s', 'sprint'), self::pct($f['regular']))
            . ', ' . sprintf(__('Fastlane %s', 'sprint'), self::pct($f['fastlane']))
            . ', ' . sprintf(__('Helping %s', 'sprint'), self::pct($f['helping'])) . ')';
        $parts[] = self::remainingText($f);
        if ($f['proposed'] > 0) {
            $parts[] = sprintf(__('Proposed %s', 'sprint'), self::pct($f['proposed']));
            $parts[] = self::remainingText($f, true);
        }
        return $whose . ' — ' . sprintf(__('scale 0–%s', 'sprint'), self::pct($geo['scale'])) . ': ' . implode(', ', $parts);
    }

    /**
     * The one capacity bar. Segment order everywhere: regular, fastlane,
     * helping, proposed (hatched), then not-available (grey, from the
     * available marker to the set capacity). Overload is a striped overlay
     * from the available marker over segments that are already drawn, so it
     * never counts work twice and is never clipped away.
     */
    public static function renderFiguresBar(array $f, string $description = '', int $height = 10, string $borderRadius = '5px'): string
    {
        $g = SprintCapacityMath::bar($f);
        $html = "<div class='sprint-capacity-bar'"
            . ($description !== '' ? " role='img' aria-label='" . htmlescape($description) . "' title='" . htmlescape($description) . "'" : '')
            . " style='position:relative;display:flex;height:{$height}px;background:var(--tblr-border-color,#e9ecef);"
            . "border-radius:{$borderRadius};overflow:hidden;-webkit-print-color-adjust:exact;print-color-adjust:exact;'>";
        if ($g['unavailable_width'] > 0) {
            $html .= "<div class='sprint-cap-seg-unavailable' style='position:absolute;top:0;bottom:0;left:{$g['unavailable_left']}%;"
                . "width:{$g['unavailable_width']}%;background:#6c757d;'></div>";
        }
        $colors = [
            'regular'  => '#0d6efd',
            'fastlane' => '#fd7e14',
            'helping'  => '#20c997',
            'proposed' => 'repeating-linear-gradient(135deg,rgba(13,110,253,.55) 0 3px,rgba(13,110,253,.18) 3px 6px)',
        ];
        foreach ($g['segments'] as $key => $w) {
            if ($w > 0) {
                $html .= "<div class='sprint-cap-seg-{$key}' style='position:relative;width:{$w}%;height:100%;background:{$colors[$key]};'></div>";
            }
        }
        if ($g['overflow_width'] > 0) {
            $html .= "<div class='sprint-cap-seg-over' style='position:absolute;top:0;bottom:0;left:{$g['overflow_left']}%;width:{$g['overflow_width']}%;"
                . "background:repeating-linear-gradient(45deg,rgba(220,53,69,.9) 0 3px,transparent 3px 7px);box-shadow:inset 0 0 0 1px #dc3545;'></div>";
        }
        if ($g['overflow_width'] > 0 || $g['unavailable_width'] > 0) {
            $html .= "<div style='position:absolute;top:0;bottom:0;left:calc({$g['marker']}% - 1px);width:2px;background:var(--tblr-body-color,#212529);'></div>";
        }
        return $html . "</div>";
    }

    /** Legend for the shared bar, with the one-line reading aid. */
    public static function renderCapacityLegend(bool $withProposed = false): string
    {
        $key = static fn(string $cls, string $label): string =>
            "<span><span class='sprint-cap-key sprint-cap-key-{$cls}' aria-hidden='true'></span>" . htmlescape($label) . "</span>";
        $out = "<div class='sprint-cap-legend'>"
            . $key('regular', __('Regular', 'sprint'))
            . $key('fastlane', __('Fastlane', 'sprint'))
            . $key('helping', __('Helping', 'sprint'))
            . ($withProposed ? $key('proposed', __('Proposed (backlog, not assigned yet)', 'sprint')) : '')
            . $key('unavailable', __('Not available (leave, absence)', 'sprint'))
            . $key('over', __('Over capacity', 'sprint'))
            . "</div>";
        $out .= "<div class='sprint-cap-note'>" . htmlescape(sprintf(
            __('Figures are shares of a full sprint, in percentage points. Remaining = available − planned, not a utilisation rate. Almost full: less than %s%% remaining.', 'sprint'),
            self::formatCapacity(SprintCapacityMath::ALMOST_FULL_PP)
        )) . "</div>";
        return $out;
    }

    /**
     * Remaining cell for capacity tables: "15%" or "10% over capacity",
     * plus the state badge (almost full / full).
     */
    public static function renderRemainingCell(array $f): string
    {
        if ($f['over'] > SprintCapacityMath::EPSILON) {
            return "<span class='sprint-cap-over'><i class='fas fa-triangle-exclamation' aria-hidden='true'></i> "
                . htmlescape(self::remainingText($f)) . "</span>";
        }
        $badge = in_array($f['state'], [SprintCapacityMath::STATE_ALMOST_FULL, SprintCapacityMath::STATE_FULL, SprintCapacityMath::STATE_NONE], true)
            ? ' ' . self::capacityStateBadge($f['state'], $f) : '';
        return "<strong>" . self::pct($f['remaining']) . "</strong>" . $badge;
    }

    /**
     * "Available 80% · Planned 65% · Remaining 15%" with the bar and the
     * breakdown that explains it, for one member in one sprint. Low
     * availability on its own is neutral. Almost full, full and over
     * capacity are named in words as well as colour. Proposed backlog work
     * (when passed in the figures) is listed apart, with what remains after it.
     *
     * @param array $f capacityFigures() result
     */
    public static function renderCapacitySummary(array $f, string $userName, string $sprintName): string
    {
        $pct = static fn(float $v): string => self::pct($v);

        $out = "<div class='sprint-cap-summary'>";
        $out .= "<div class='sprint-cap-caption'>"
            . htmlescape($sprintName !== '' ? sprintf(__('Capacity in %s', 'sprint'), $sprintName) : __('Capacity', 'sprint'))
            . "</div>";
        $out .= "<div class='sprint-cap-figures'>"
            . "<span>" . __s('Available', 'sprint') . " <strong>" . $pct($f['available']) . "</strong></span>"
            . "<span aria-hidden='true'>·</span>"
            . "<span>" . __s('Planned', 'sprint') . " <strong>" . $pct($f['planned']) . "</strong></span>"
            . "<span aria-hidden='true'>·</span>";
        if ($f['over'] > SprintCapacityMath::EPSILON) {
            $out .= "<span class='sprint-cap-over'><i class='fas fa-triangle-exclamation' aria-hidden='true'></i> "
                . htmlescape(self::remainingText($f)) . "</span>";
        } else {
            $out .= "<span>" . __s('Remaining', 'sprint') . " <strong>" . $pct($f['remaining']) . "</strong></span>";
            $out .= self::capacityStateBadge($f['state'], $f);
        }
        $out .= "</div>";
        $out .= self::renderFiguresBar($f, self::capacityDescription($f, $userName, $sprintName));

        // Why the numbers are what they are.
        $out .= "<ul class='sprint-cap-breakdown'>";
        if ($f['leave'] > 0) {
            $out .= "<li><span class='sprint-cap-key sprint-cap-key-unavailable' aria-hidden='true'></span>" . htmlescape(sprintf(
                __('Set %1$s − not available %2$s = available %3$s', 'sprint'),
                $pct($f['set']),
                $pct($f['leave']),
                $pct($f['available'])
            )) . "</li>";
        }
        $parts = [
            "<span class='sprint-cap-key sprint-cap-key-regular' aria-hidden='true'></span>"
                . htmlescape(sprintf(__('Regular %s', 'sprint'), $pct($f['regular']))),
            "<span class='sprint-cap-key sprint-cap-key-fastlane' aria-hidden='true'></span>"
                . htmlescape(sprintf(__('Fastlane %s', 'sprint'), $pct($f['fastlane']))),
        ];
        $helping = "<span class='sprint-cap-key sprint-cap-key-helping' aria-hidden='true'></span>"
            . htmlescape(sprintf(__('Helping %s', 'sprint'), $pct($f['helping'])));
        if ($f['helping'] > 0) {
            $helping .= " <span class='text-muted'>(" . htmlescape(sprintf(
                __('%1$s open, %2$s resolved', 'sprint'),
                $pct($f['helping_open']),
                $pct($f['helping_resolved'])
            )) . ")</span>";
        }
        $parts[] = $helping;
        $out .= "<li>" . __s('Planned', 'sprint') . ": " . implode(' · ', $parts) . "</li>";
        if ($f['proposed'] > 0) {
            $out .= "<li><span class='sprint-cap-key sprint-cap-key-proposed' aria-hidden='true'></span>"
                . htmlescape(sprintf(__('Proposed from the backlog %s (not assigned yet)', 'sprint'), $pct($f['proposed'])))
                . " · <span class='" . ($f['over_after_proposals'] > SprintCapacityMath::EPSILON ? 'sprint-cap-over' : '') . "'>"
                . htmlescape(self::remainingText($f, true)) . "</span></li>";
        }
        $out .= "</ul>";
        $out .= "</div>";
        return $out;
    }

    public static function getAllRoles(): array
    {
        return [
            self::ROLE_SCRUM_MASTER  => __('Scrum Master', 'sprint'),
            self::ROLE_PRODUCT_OWNER => __('Product Owner', 'sprint'),
            self::ROLE_DEVELOPER     => __('Developer', 'sprint'),
            self::ROLE_TESTER        => __('Tester', 'sprint'),
            self::ROLE_DESIGNER      => __('Designer', 'sprint'),
            self::ROLE_DEVOPS        => __('DevOps', 'sprint'),
            self::ROLE_ANALYST       => __('Analyst', 'sprint'),
            self::ROLE_OTHER         => __('Other', 'sprint'),
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Sprint) {
            $count = countElementsInTable(
                self::getTable(),
                ['plugin_sprint_sprints_id' => $item->getID()]
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

    public function prepareInputForAdd($input)
    {
        if (isset($input['users_id']))                 $input['users_id']                 = (int)$input['users_id'];
        if (isset($input['plugin_sprint_sprints_id'])) $input['plugin_sprint_sprints_id'] = (int)$input['plugin_sprint_sprints_id'];
        if (isset($input['capacity_percent']))          $input['capacity_percent']          = self::normalizeCapacity($input['capacity_percent']);
        if (isset($input['role']) && !array_key_exists($input['role'], self::getAllRoles())) {
            $input['role'] = self::ROLE_DEVELOPER;
        }
        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['capacity_percent'])) {
            $input['capacity_percent'] = self::normalizeCapacity($input['capacity_percent']);
        }
        return parent::prepareInputForUpdate($input);
    }

    /** Show members list + add form for a sprint. */
    public static function showForSprint(Sprint $sprint): void
    {
        $ID      = $sprint->getID();
        $canedit = Sprint::canUpdate();
        $roles   = self::getAllRoles();

        if ($canedit) {
            echo "<div class='center'>";
            echo "<form method='post' action='" . static::getFormURL() . "'>";
            echo Html::hidden('plugin_sprint_sprints_id', ['value' => $ID]);

            echo "<table class='tab_cadre_fixe sprint-themed'>";
            echo "<tr class='tab_bg_2'><th colspan='6'>" .
                __('Add a team member', 'sprint') . "</th></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('User') . "</td>";
            echo "<td>";
            User::dropdown([
                'name'  => 'users_id',
                'right' => 'all',
            ]);
            echo "</td>";
            echo "<td>" . __('Role', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('role', $roles, [
                'value'      => self::ROLE_DEVELOPER,
                'aria_label' => __('Role', 'sprint'),
            ]);
            echo "</td>";
            echo "<td><label for='sprint-member-add-capacity'>" . __('Set capacity (%)', 'sprint') . "</label></td>";
            echo "<td>";
            echo self::capacityInput('capacity_percent', 100, [
                'id'       => 'sprint-member-add-capacity',
                'required' => true,
            ]);
            echo "</td>";
            echo "</tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Comment') . "</td>";
            echo "<td colspan='3'>";
            echo "<textarea name='comment' rows='2' cols='60' aria-label='" . __s('Comment') . "'></textarea>";
            echo "</td>";
            echo "<td colspan='2'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td>";
            echo "</tr>";

            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        $member  = new self();
        $members = $member->find(
            ['plugin_sprint_sprints_id' => $ID],
            ['role ASC']
        );

        $memberStatusCounts = self::getMemberStatusCounts($ID);

        self::renderMembersDashboard($ID, $members, $memberStatusCounts, (string)($sprint->fields['name'] ?? ''));

        // Leave / training per member sits next to the capacity settings; the
        // effective capacity in the cards above already accounts for it.
        echo "<div style='margin:16px 0;text-align:left;'>";
        SprintAgility::renderAvailability($ID, $canedit && Config::isCurrentUserScrumMaster($ID));
        echo "</div>";

        $roleIcons = [
            self::ROLE_SCRUM_MASTER  => 'fas fa-hat-wizard',
            self::ROLE_PRODUCT_OWNER => 'fas fa-briefcase',
            self::ROLE_DEVELOPER     => 'fas fa-code',
            self::ROLE_TESTER        => 'fas fa-bug',
            self::ROLE_DESIGNER      => 'fas fa-palette',
            self::ROLE_DEVOPS        => 'fas fa-server',
            self::ROLE_ANALYST       => 'fas fa-chart-bar',
            self::ROLE_OTHER         => 'fas fa-user',
        ];

        echo "<div class='center' style='margin-top:16px;'><table class='tab_cadre_fixe sprint-themed'>";
        echo "<tr class='tab_bg_2'><th colspan='" . ($canedit ? 4 : 3) . "'>" .
            "<i class='fas fa-user-cog' style='margin-right:6px;'></i>" .
            __('Member settings', 'sprint') . "</th></tr>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('User') . "</th>";
        echo "<th>" . __('Role', 'sprint') . "</th>";
        echo "<th>" . __('Set capacity', 'sprint') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($members) === 0) {
            $cols = $canedit ? 4 : 3;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('No team members added', 'sprint') . "</td></tr>";
        }

        foreach ($members as $row) {
            $icon       = $roleIcons[$row['role']] ?? 'fas fa-user';
            $roleName   = $roles[$row['role']] ?? $row['role'];
            $userId     = (int)$row['users_id'];

            echo "<tr class='tab_bg_1'>";
            echo "<td><i class='fas fa-user'></i> " . htmlescape(SprintCache::userName($userId)) . "</td>";
            echo "<td><i class='{$icon}'></i> {$roleName}</td>";
            echo "<td class='center'>";
            // The configured share is a fact, not a verdict and not progress:
            // a neutral, outlined "Set capacity" bar whatever the value (a 20%
            // part-timer is not a problem). Planning and overload show on the
            // team dashboard above.
            $pct       = self::normalizeCapacity($row['capacity_percent']);
            $pctLabel  = self::formatCapacity($pct);
            $available = SprintAgility::effectiveCapacity($ID, $userId, $pct);
            $setLabel  = sprintf(__('Set capacity %s', 'sprint'), $pctLabel . '%');
            echo "<div style='display:flex;align-items:center;gap:8px;flex-wrap:wrap;'>";
            echo "<div class='sprint-cap-setbar' role='img' aria-label='" . htmlescape($setLabel) . "' title='" . htmlescape($setLabel) . "'>";
            echo "<div style='width:{$pct}%;'></div>";
            echo "</div>";
            echo "<span>" . htmlescape($setLabel) . "</span>";
            if (($pct - $available) > 0.01) {
                echo "<span class='text-muted sprint-small'>" . htmlescape(sprintf(
                    __('%s%% available in this sprint', 'sprint'),
                    self::formatCapacity($available)
                )) . "</span>";
            }
            echo "</div>";
            echo "</td>";

            if ($canedit) {
                echo "<td class='center'>";
                $memberName = SprintCache::userName($userId);
                echo "<a href='" . static::getFormURLWithID($row['id']) .
                    "' class='btn btn-sm btn-outline-primary' title='" . __s('Edit') . "' "
                    . "aria-label='" . htmlescape(sprintf(__('Edit %s', 'sprint'), $memberName)) . "'>" .
                    "<i class='fas fa-edit' aria-hidden='true'></i></a> ";
                echo "<form method='post' action='" . static::getFormURL() .
                    "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::submit(__('Delete'), [
                    'name'    => 'purge',
                    'class'   => 'btn btn-sm btn-outline-danger',
                    'confirm' => __('Remove this member?', 'sprint'),
                ]);
                Html::closeForm();
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</table></div>";
    }

    /**
     * Top-of-tab dashboard: one card per member with status distribution,
     * capacity usage, and done-items progress.
     *
     * @param array<int,array<string,int>> $memberStatusCounts
     */
    private static function renderMembersDashboard(int $sprintId, array $members, array $memberStatusCounts, string $sprintName = ''): void
    {
        if (count($members) === 0) {
            return;
        }

        $roles = self::getAllRoles();
        $roleIcons = [
            self::ROLE_SCRUM_MASTER  => 'fas fa-hat-wizard',
            self::ROLE_PRODUCT_OWNER => 'fas fa-briefcase',
            self::ROLE_DEVELOPER     => 'fas fa-code',
            self::ROLE_TESTER        => 'fas fa-bug',
            self::ROLE_DESIGNER      => 'fas fa-palette',
            self::ROLE_DEVOPS        => 'fas fa-server',
            self::ROLE_ANALYST       => 'fas fa-chart-bar',
            self::ROLE_OTHER         => 'fas fa-user',
        ];

        echo "<div style='margin-bottom:16px;'>";
        echo "<h4 style='margin:10px 0 12px;'><i class='fas fa-tachometer-alt me-2'></i>" .
            __('Team dashboard', 'sprint') . "</h4>";
        echo "<div style='display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;'>";

        // One capacity dataset for the whole team, not three queries per
        // member, and one model: the same figures as the dashboard and report.
        $proposedByUser = self::proposedCapacityBySprint($sprintId);
        foreach ($members as $row) {
            $userId   = (int)$row['users_id'];
            $roleName = $roles[$row['role']] ?? $row['role'];
            $roleIcon = $roleIcons[$row['role']] ?? 'fas fa-user';

            $counts = $memberStatusCounts[$userId] ?? [];
            $total  = (int)($counts['total'] ?? 0);
            $done   = (int)($counts[SprintItem::STATUS_DONE] ?? 0);
            $progressPct = $total > 0 ? round(($done / $total) * 100) : 0;

            $fastlaneItemCount   = (int)($counts['fastlane_total'] ?? 0);
            $dependencyItemCount = (int)($counts['dependency_total'] ?? 0);

            $progressColor = $progressPct >= 80 ? '#198754' : ($progressPct >= 50 ? '#ffc107' : ($progressPct > 0 ? '#0d6efd' : '#adb5bd'));

            echo "<div style='border:1px solid var(--tblr-border-color,#e9ecef);border-radius:10px;padding:14px 16px;background:var(--tblr-bg-surface,#fff);box-shadow:0 1px 2px rgba(0,0,0,0.03);'>";

            echo "<div style='display:flex;align-items:center;gap:10px;margin-bottom:10px;'>";
            echo "<i class='fas fa-user-circle' style='font-size:1.6em;color:#6c757d;'></i>";
            echo "<div style='flex:1;'>";
            echo "<div style='font-weight:700;'>" . htmlescape(SprintCache::userName($userId)) . "</div>";
            echo "<div style='font-size:0.82em;color:#6c757d;'><i class='{$roleIcon}'></i> " . htmlescape($roleName) . "</div>";
            echo "</div>";
            if ($fastlaneItemCount > 0) {
                echo "<span class='sprint-cap-chip sprint-cap-chip-fastlane' title='" . __s('Fastlane items assigned', 'sprint') . "'>"
                    . "<i class='fas fa-bolt' aria-hidden='true'></i> {$fastlaneItemCount}"
                    . "<span class='visually-hidden'> " . __s('Fastlane items assigned', 'sprint') . "</span></span>";
            }
            if ($dependencyItemCount > 0) {
                echo "<span class='sprint-cap-chip sprint-cap-chip-helping' title='" . __s('Open dependency allocations', 'sprint') . "'>"
                    . "<i class='fas fa-link' aria-hidden='true'></i> {$dependencyItemCount}"
                    . "<span class='visually-hidden'> " . __s('Open dependency allocations', 'sprint') . "</span></span>";
            }
            echo "</div>";

            echo "<div style='margin-bottom:8px;'>";
            echo "<div style='display:flex;justify-content:space-between;font-size:0.78em;color:#6c757d;margin-bottom:3px;'>";
            echo "<span>" . __('Sprint progress', 'sprint') . "</span>";
            echo "<span><strong>{$done}</strong> / {$total} " . __('items done', 'sprint') . " ({$progressPct}%)</span>";
            echo "</div>";
            echo "<div style='height:10px;background:var(--tblr-border-color,#e9ecef);border-radius:5px;overflow:hidden;'>";
            echo "<div style='width:{$progressPct}%;height:100%;background:{$progressColor};transition:width 0.3s;'></div>";
            echo "</div>";
            echo "</div>";

            $figures = self::figuresForMember($sprintId, $row, (float)($proposedByUser[$userId] ?? 0.0));
            echo "<div style='margin-bottom:10px;'>";
            echo self::renderCapacitySummary($figures, SprintCache::userName($userId), $sprintName);
            echo "</div>";

            echo self::renderStatusDistribution($counts);

            // Items where this user helps on at least one open dependency.
            $helperItems = SprintItemDependency::getOpenItemsForHelper($sprintId, $userId);
            if (count($helperItems) > 0) {
                echo "<div style='margin-top:10px;padding-top:8px;border-top:1px dashed #e9ecef;'>";
                echo "<div style='font-size:0.78em;color:#6c757d;margin-bottom:4px;'>"
                    . "<i class='fas fa-link' style='color:#20c997;margin-right:4px;'></i>"
                    . __('Helping on', 'sprint') . "</div>";
                $shown = 0;
                foreach ($helperItems as $h) {
                    if ($shown >= 3) {
                        $more = count($helperItems) - 3;
                        echo "<div style='font-size:0.78em;color:#adb5bd;font-style:italic;'>"
                            . sprintf(__('+ %d more', 'sprint'), $more) . "</div>";
                        break;
                    }
                    $itemUrl = SprintItem::getFormURLWithID($h['item_id']);
                    echo "<div style='font-size:0.82em;line-height:1.3;'>"
                        . "<a href='" . htmlescape($itemUrl) . "'>" . htmlescape($h['name']) . "</a> "
                        . "<span class='text-muted'>(" . self::formatCapacity($h['capacity']) . "%";
                    if ($h['owner_name'] !== '') {
                        echo " — " . htmlescape($h['owner_name']);
                    }
                    echo ")</span>"
                        . "</div>";
                    $shown++;
                }
                echo "</div>";
            }

            echo "</div>";
        }

        echo "</div>";
        echo self::renderCapacityLegend(count($proposedByUser) > 0);
        echo "</div>";
    }

    /**
     * Count sprint items per member per status for the distribution bar.
     * Includes regular items (owned via users_id) plus Fastlane items the
     * member is allocated on, so it reflects the user's full workload.
     *
     * @return array<int, array<string,int>> [users_id => [status => count]]
     */
    private static function getMemberStatusCounts(int $sprintId): array
    {
        $counts = [];

        $ensureUser = function (int $uid) use (&$counts): void {
            if (!isset($counts[$uid])) {
                $counts[$uid] = [
                    SprintItem::STATUS_TODO        => 0,
                    SprintItem::STATUS_SCHEDULED   => 0,
                    SprintItem::STATUS_IN_PROGRESS => 0,
                    SprintItem::STATUS_REVIEW      => 0,
                    SprintItem::STATUS_DEPENDENCY  => 0,
                    SprintItem::STATUS_DONE        => 0,
                    SprintItem::STATUS_BLOCKED     => 0,
                    'total'                        => 0,
                    'fastlane_total'               => 0,
                    'dependency_total'             => 0,
                ];
            }
        };

        $si = new SprintItem();

        // Regular (non-fastlane) items, owned by the row's users_id.
        foreach ($si->find([
            'plugin_sprint_sprints_id' => $sprintId,
            'is_fastlane'              => 0,
        ]) as $row) {
            $uid = (int)$row['users_id'];
            if ($uid <= 0) {
                continue;
            }
            $ensureUser($uid);
            $status = $row['status'] ?? SprintItem::STATUS_TODO;
            if (isset($counts[$uid][$status])) {
                $counts[$uid][$status]++;
            }
            $counts[$uid]['total']++;
        }

        // Fastlane items: attribute each to every user assigned via the
        // junction table so fastlane shows up on each member's card.
        $fastlaneItems = $si->find([
            'plugin_sprint_sprints_id' => $sprintId,
            'is_fastlane'              => 1,
        ]);
        if (count($fastlaneItems) > 0) {
            $rel = new SprintFastlaneMember();
            $itemIds = array_map(fn($r) => (int)$r['id'], $fastlaneItems);
            $itemsById = [];
            foreach ($fastlaneItems as $r) {
                $itemsById[(int)$r['id']] = $r;
            }
            foreach ($rel->find(['plugin_sprint_sprintitems_id' => $itemIds]) as $r) {
                $uid = (int)$r['users_id'];
                if ($uid <= 0) {
                    continue;
                }
                $itemId = (int)$r['plugin_sprint_sprintitems_id'];
                $item   = $itemsById[$itemId] ?? null;
                if ($item === null) {
                    continue;
                }
                $ensureUser($uid);
                $status = $item['status'] ?? SprintItem::STATUS_TODO;
                if (isset($counts[$uid][$status])) {
                    $counts[$uid][$status]++;
                }
                $counts[$uid]['total']++;
                $counts[$uid]['fastlane_total']++;
            }
        }

        // Only bump dependency_total: the parent item is already counted for
        // its owner, so counting it again for the helper would double-count.
        if (SprintItemDependency::isTableReady()) {
            $allItems = $si->find(['plugin_sprint_sprints_id' => $sprintId]);
            if (count($allItems) > 0) {
                $allItemIds = array_map(fn($r) => (int)$r['id'], $allItems);
                $depRel = new SprintItemDependency();
                foreach ($depRel->find([
                    'plugin_sprint_sprintitems_id' => $allItemIds,
                    'is_resolved'                  => 0,
                ]) as $r) {
                    $uid = (int)$r['users_id'];
                    if ($uid <= 0) {
                        continue;
                    }
                    $ensureUser($uid);
                    $counts[$uid]['dependency_total']++;
                }
            }
        }

        return $counts;
    }

    /** Compact per-member status distribution: count pills + mini progress bar. */
    private static function renderStatusDistribution(array $counts): string
    {
        $total = (int)($counts['total'] ?? 0);
        if ($total <= 0) {
            return "<span style='color:#adb5bd;font-style:italic;'>" .
                __('No items assigned', 'sprint') . "</span>";
        }

        $segments = [
            [SprintItem::STATUS_DONE,        __('Done', 'sprint'),        '#198754', 'fas fa-check-circle'],
            [SprintItem::STATUS_IN_PROGRESS, __('In Progress', 'sprint'), '#0d6efd', 'fas fa-circle-notch'],
            [SprintItem::STATUS_REVIEW,      __('In Review', 'sprint'),   '#6f42c1', 'fas fa-search'],
            [SprintItem::STATUS_DEPENDENCY,  __('Dependency', 'sprint'),  '#20c997', 'fas fa-link'],
            [SprintItem::STATUS_SCHEDULED,   __('Scheduled', 'sprint'),   '#087990', 'fas fa-calendar-check'],
            [SprintItem::STATUS_BLOCKED,     __('Blocked', 'sprint'),     '#dc3545', 'fas fa-hand-paper'],
            [SprintItem::STATUS_TODO,        __('To Do', 'sprint'),       '#d5d8dc', 'fas fa-list-ul'],
        ];

        $out = "<div style='display:flex;align-items:center;gap:10px;flex-wrap:wrap;'>";

        $out .= "<div style='display:flex;gap:6px;flex-wrap:wrap;'>";
        foreach ($segments as [$key, $label, $color, $icon]) {
            $n = (int)($counts[$key] ?? 0);
            if ($n === 0) {
                continue;
            }
            $out .= "<span title='" . htmlescape($label) . "' "
                . "style='display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;"
                . "background:{$color}1a;color:{$color};font-size:0.78em;font-weight:600;'>"
                . "<i class='{$icon}'></i>{$n}</span>";
        }
        $out .= "</div>";

        $out .= "<div style='flex:1;min-width:120px;height:10px;background:var(--tblr-border-color,#e9ecef);border-radius:5px;overflow:hidden;display:flex;'>";
        foreach ($segments as [$key, $label, $color, $icon]) {
            $n = (int)($counts[$key] ?? 0);
            if ($n === 0) {
                continue;
            }
            $pct = round(($n / $total) * 100, 2);
            $out .= "<div title='" . htmlescape($label) . " {$n}' "
                . "style='width:{$pct}%;height:100%;background:{$color};'></div>";
        }
        $out .= "</div>";

        $out .= "<span style='font-size:0.78em;color:#6c757d;'>{$total}</span>";
        $out .= "</div>";
        return $out;
    }

    /** Show edit form for a member. */
    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        // "Back to Sprint" button linking to the parent sprint's Members tab.
        $sprintId = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
        if ($sprintId > 0) {
            $sprintUrl = Sprint::getFormURLWithID($sprintId) . '&forcetab=' . urlencode('GlpiPlugin\\Sprint\\SprintMember$1');
            echo "<div style='margin-bottom:10px;'>";
            echo "<a href='$sprintUrl' class='btn btn-outline-secondary'>";
            echo "<i class='fas fa-arrow-left me-1'></i> " . __('Back to Sprint', 'sprint');
            echo "</a></div>";
        }

        if (class_exists('Glpi\Application\View\TemplateRenderer')) {
            \Glpi\Application\View\TemplateRenderer::getInstance()->display(
                '@sprint/sprintmember.form.html.twig',
                [
                    'item'             => $this,
                    'params'           => $options,
                    'roles'            => self::getAllRoles(),
                    'capacity_input'   => self::capacityInput('capacity_percent', $this->fields['capacity_percent'] ?? 100, [
                        'id'       => 'sprint-member-capacity',
                        'required' => true,
                    ]),
                ]
            );
        } else {
            $this->showFormHeader($options);
            $roles = self::getAllRoles();

            echo "<tr class='tab_bg_1'><td>" . __('User') . "</td><td>";
            User::dropdown(['name' => 'users_id', 'value' => $this->fields['users_id'] ?? 0, 'right' => 'all']);
            echo "</td><td>" . __('Role', 'sprint') . "</td><td>";
            Dropdown::showFromArray('role', $roles, ['value' => $this->fields['role'] ?? self::ROLE_DEVELOPER]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'><td><label for='sprint-member-capacity'>" . __('Set capacity (%)', 'sprint') . "</label></td><td>";
            echo self::capacityInput('capacity_percent', $this->fields['capacity_percent'] ?? 100, [
                'id'       => 'sprint-member-capacity',
                'required' => true,
            ]);
            echo "</td><td>" . __('Sprint') . "</td><td>";
            Sprint::dropdown(['name' => 'plugin_sprint_sprints_id', 'value' => $this->fields['plugin_sprint_sprints_id'] ?? 0]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'><td>" . __('Comment') . "</td>";
            echo "<td colspan='3'><textarea name='comment' rows='3' cols='80'>" .
                htmlescape($this->fields['comment'] ?? '') . "</textarea></td></tr>";

            $this->showFormButtons($options);
        }
        return true;
    }

    /** @var array<int,array<int,array{regular:float,fastlane:float,dependency:float,dependency_open:float,total:float}>> per sprint, per request */
    private static array $usedBySprint = [];

    /**
     * Used capacity for every user of a sprint in three grouped queries —
     * regular items, fastlane allocations, dependency allocations — cached
     * per request. The read paths (dashboards, tables, suggestions, exports,
     * the cron) take it from here, so the query count no longer grows with
     * the team. Validation keeps using getUsedCapacityForUser(): it runs
     * between writes and must see them.
     *
     * @return array<int,array{regular:float,fastlane:float,dependency:float,dependency_open:float,total:float}> by user id
     */
    public static function usedCapacityBySprint(int $sprintId): array
    {
        global $DB;

        if ($sprintId <= 0) {
            return [];
        }
        if (isset(self::$usedBySprint[$sprintId])) {
            return self::$usedBySprint[$sprintId];
        }
        $out  = [];
        $bump = static function (int $uid, string $key, float $value) use (&$out): void {
            if ($uid <= 0 || $value == 0.0) {
                return;
            }
            $out[$uid] ??= ['regular' => 0.0, 'fastlane' => 0.0, 'dependency' => 0.0, 'dependency_open' => 0.0, 'total' => 0.0];
            $out[$uid][$key]    += $value;
            $out[$uid]['total'] += $value;
        };

        $items = SprintItem::getTable();
        foreach ($DB->request([
            'SELECT'  => ['users_id', 'SUM' => 'capacity AS used'],
            'FROM'    => $items,
            'WHERE'   => ['plugin_sprint_sprints_id' => $sprintId, 'is_fastlane' => 0],
            'GROUPBY' => 'users_id',
        ]) as $row) {
            $bump((int)$row['users_id'], 'regular', (float)$row['used']);
        }

        // Fastlane items spread their load over the junction table.
        $fastlane = SprintFastlaneMember::getTable();
        foreach ($DB->request([
            'SELECT'     => ["{$fastlane}.users_id", 'SUM' => "{$fastlane}.capacity AS used"],
            'FROM'       => $fastlane,
            'INNER JOIN' => [$items => ['ON' => [$fastlane => 'plugin_sprint_sprintitems_id', $items => 'id']]],
            'WHERE'      => ["{$items}.plugin_sprint_sprints_id" => $sprintId, "{$items}.is_fastlane" => 1],
            'GROUPBY'    => "{$fastlane}.users_id",
        ]) as $row) {
            $bump((int)$row['users_id'], 'fastlane', (float)$row['used']);
        }

        // Resolved dependency rows still count: that capacity was spent.
        if (SprintItemDependency::isTableReady()) {
            $deps = SprintItemDependency::getTable();
            // Grouped by is_resolved too, so views can tell open help from
            // resolved help; 'dependency_open' is a subset, not added to total.
            foreach ($DB->request([
                'SELECT'     => ["{$deps}.users_id", "{$deps}.is_resolved", 'SUM' => "{$deps}.capacity AS used"],
                'FROM'       => $deps,
                'INNER JOIN' => [$items => ['ON' => [$deps => 'plugin_sprint_sprintitems_id', $items => 'id']]],
                'WHERE'      => ["{$items}.plugin_sprint_sprints_id" => $sprintId],
                'GROUPBY'    => ["{$deps}.users_id", "{$deps}.is_resolved"],
            ]) as $row) {
                $uid = (int)$row['users_id'];
                $bump($uid, 'dependency', (float)$row['used']);
                if ((int)$row['is_resolved'] === 0 && isset($out[$uid])) {
                    $out[$uid]['dependency_open'] += (float)$row['used'];
                }
            }
        }

        return self::$usedBySprint[$sprintId] = $out;
    }

    /** Forget the per-sprint totals after a write that changes them. */
    public static function invalidateUsedCapacity(): void
    {
        self::$usedBySprint = [];
    }

    /**
     * Capacity already used by a user in a sprint: regular + Fastlane + open
     * Dependency allocations. Exclude ids let an update re-check the row it edits.
     *
     * @param int $sprintId
     * @param int $userId
     * @param int $excludeRegularItemId      SprintItem id to exclude
     * @param int $excludeFastlaneMemberId   SprintFastlaneMember id to exclude
     * @param int $excludeDependencyId       SprintItemDependency id to exclude
     * @return float  Used capacity %
     */
    public static function getUsedCapacityForUser(
        int $sprintId,
        int $userId,
        int $excludeRegularItemId = 0,
        int $excludeFastlaneMemberId = 0,
        int $excludeDependencyId = 0
    ): float {
        // Fastlane items have multiple owners via the junction table, so
        // only sum non-fastlane items the user owns here.
        $si = new SprintItem();
        $criteria = [
            'plugin_sprint_sprints_id' => $sprintId,
            'users_id'                 => $userId,
            'is_fastlane'              => 0,
        ];
        if ($excludeRegularItemId > 0) {
            $criteria['NOT'] = ['id' => $excludeRegularItemId];
        }
        $regularUsed = 0.0;
        foreach ($si->find($criteria) as $row) {
            $regularUsed += (float)($row['capacity'] ?? 0);
        }

        $fastlaneUsed = SprintFastlaneMember::getUsedFastlaneCapacityForUser(
            $sprintId,
            $userId,
            $excludeFastlaneMemberId
        );

        $dependencyUsed = SprintItemDependency::getUsedDependencyCapacityForUser(
            $sprintId,
            $userId,
            $excludeDependencyId
        );

        return $regularUsed + $fastlaneUsed + $dependencyUsed;
    }

    /**
     * Capacity snapshot for the backlog: how full is $userId in $sprintId
     * right now (items already in the sprint), plus how much is already
     * pencilled in by other backlog items proposed for that same sprint.
     * Cached per request so rendering many backlog rows stays cheap.
     *
     * @return array{total: float, used: float, pending_other: float, is_member: bool, figures: ?array}|null
     *         null when the sprint/user pair is invalid.
     */
    public static function backlogCapacityPreview(int $sprintId, int $userId, int $excludeItemId = 0): ?array
    {
        if ($sprintId <= 0 || $userId <= 0) {
            return null;
        }

        $s        = self::backlogPlanningData($sprintId);
        $isMember = isset($s['members'][$userId]);
        $pendingIds = $s['pending'][$userId] ?? [];
        $depIds     = $s['deps'][$userId] ?? [];
        $pendingOther = array_sum($pendingIds) + array_sum($depIds);
        if ($excludeItemId > 0 && isset($pendingIds[$excludeItemId])) {
            $pendingOther -= $pendingIds[$excludeItemId];
        }
        if ($excludeItemId > 0 && isset($depIds[$excludeItemId])) {
            $pendingOther -= $depIds[$excludeItemId];
        }
        $used = $s['used'][$userId] ?? [];

        return [
            // Effective capacity (availability exceptions applied), matching the sprint views.
            'total'         => $isMember ? SprintAgility::effectiveCapacity($sprintId, $userId, $s['members'][$userId]) : 0.0,
            'used'          => (float)($used['total'] ?? 0.0),
            'pending_other' => $pendingOther,
            'is_member'     => $isMember,
            // The shared model (without this item's own estimate), so the
            // dialog speaks the same terms as the sprint views.
            'figures'       => $isMember ? self::capacityFigures(
                $s['members'][$userId],
                SprintAgility::effectiveCapacity($sprintId, $userId, $s['members'][$userId]),
                (float)($used['regular'] ?? 0.0),
                (float)($used['fastlane'] ?? 0.0),
                (float)($used['dependency'] ?? 0.0),
                (float)($used['dependency_open'] ?? 0.0),
                $pendingOther
            ) : null,
        ];
    }

    /**
     * Which backlog rows count as "proposed" for a sprint: still on the
     * backlog, pencilled in for it, not parked and not blocked. Shared by the
     * edit-dialog preview, the team cards and the member × sprint forecast,
     * so their proposal totals agree.
     *
     * @param int|int[] $sprintIds
     */
    public static function proposedBacklogCriteria($sprintIds): array
    {
        return [
            'plugin_sprint_sprints_id' => 0,
            'proposed_sprints_id'      => $sprintIds,
            'is_parked'                => 0,
            'is_blocked'               => 0,
        ];
    }

    /**
     * Proposed backlog capacity per user for one sprint: owned non-fastlane
     * rows (a fastlane row spreads its load only once it is in a sprint) plus
     * open dependency allocations where the user is the coupled helper.
     *
     * @return array<int,float> by user id
     */
    public static function proposedCapacityBySprint(int $sprintId): array
    {
        if ($sprintId <= 0) {
            return [];
        }
        $s   = self::backlogPlanningData($sprintId);
        $out = [];
        foreach ([$s['pending'], $s['deps']] as $byUser) {
            foreach ($byUser as $uid => $byItem) {
                $out[(int)$uid] = ($out[(int)$uid] ?? 0.0) + array_sum($byItem);
            }
        }
        return $out;
    }

    /**
     * Everything the backlog previews need for one sprint, loaded once per
     * request: members, the backlog rows proposed for it, their open
     * dependency allocations and the used capacity per user.
     *
     * @return array{members:array<int,float>,pending:array<int,array<int,float>>,deps:array<int,array<int,float>>,used:array}
     */
    private static function backlogPlanningData(int $sprintId): array
    {
        static $sprintCache = [];
        if (isset($sprintCache[$sprintId])) {
            return $sprintCache[$sprintId];
        }
        $members = [];
        foreach ((new self())->find(['plugin_sprint_sprints_id' => $sprintId]) as $m) {
            $members[(int)$m['users_id']] = self::normalizeCapacity($m['capacity_percent'] ?? 0);
        }

        $pendingByUser = [];
        $depByUser     = [];
        $allProposed   = (new SprintItem())->find(self::proposedBacklogCriteria($sprintId));
        foreach ($allProposed as $r) {
            $owner = (int)$r['users_id'];
            if ($owner > 0 && (int)($r['is_fastlane'] ?? 0) === 0) {
                $pendingByUser[$owner][(int)$r['id']] = (float)($r['capacity'] ?? 0);
            }
        }
        $proposedItemIds = array_map(fn($r) => (int)$r['id'], $allProposed);
        if (count($proposedItemIds) > 0 && SprintItemDependency::isTableReady()) {
            foreach ((new SprintItemDependency())->find([
                'plugin_sprint_sprintitems_id' => $proposedItemIds,
                'is_resolved'                  => 0,
            ]) as $d) {
                $helper = (int)$d['users_id'];
                $iid    = (int)$d['plugin_sprint_sprintitems_id'];
                $depByUser[$helper][$iid] = ($depByUser[$helper][$iid] ?? 0.0) + (float)$d['capacity'];
            }
        }

        return $sprintCache[$sprintId] = [
            'members' => $members,
            'pending' => $pendingByUser,
            'deps'    => $depByUser,
            'used'    => self::usedCapacityBySprint($sprintId),
        ];
    }

    /**
     * Validate that adding $additional% stays within the member's capacity.
     * Queues a Session message on failure (or a warning when $allowOverflow).
     */
    public static function checkCapacityForUser(
        int $sprintId,
        int $userId,
        float $additional,
        int $excludeRegularItemId = 0,
        int $excludeFastlaneMemberId = 0,
        int $excludeDependencyId = 0,
        bool $allowOverflow = false
    ): bool {
        if ($additional <= 0 || $userId <= 0 || $sprintId <= 0) {
            return true;
        }

        $member  = new self();
        $members = $member->find([
            'plugin_sprint_sprints_id' => $sprintId,
            'users_id'                 => $userId,
        ]);
        if (count($members) === 0) {
            return true;
        }
        $row           = reset($members);
        $totalCapacity = SprintAgility::effectiveCapacity(
            $sprintId,
            $userId,
            self::normalizeCapacity($row['capacity_percent'])
        );

        $used      = self::getUsedCapacityForUser($sprintId, $userId, $excludeRegularItemId, $excludeFastlaneMemberId, $excludeDependencyId);
        $remaining = $totalCapacity - $used;

        if ($additional > $remaining) {
            if ($allowOverflow) {
                $newUsed = $used + $additional;
                Session::addMessageAfterRedirect(
                    sprintf(
                        __('%s is now over capacity: %s%% used of %s%% (+%s%% overflow).', 'sprint'),
                        SprintCache::userName($userId),
                        self::formatCapacity($newUsed),
                        self::formatCapacity($totalCapacity),
                        self::formatCapacity($newUsed - $totalCapacity)
                    ),
                    false,
                    WARNING
                );
                return true;
            }
            Session::addMessageAfterRedirect(
                sprintf(
                    __('%s has only %s%% capacity remaining (total: %s%%, used: %s%%). Cannot assign %s%%.', 'sprint'),
                    SprintCache::userName($userId),
                    self::formatCapacity(max($remaining, 0)),
                    self::formatCapacity($totalCapacity),
                    self::formatCapacity($used),
                    self::formatCapacity($additional)
                ),
                false,
                ERROR
            );
            return false;
        }
        return true;
    }

    /**
     * Non-mutating overflow probe for the AJAX endpoints: reports whether
     * assigning $additional% would push the user past capacity, and by how
     * much, so they can be asked to confirm before committing. Queues no
     * messages and never blocks (unlike checkCapacityForUser()).
     *
     * Returns null when there is no member row, no positive assignment, or the
     * result still fits. Callers should also skip the prompt when the change
     * does not increase load, so an already-overflowed member isn't nagged on
     * every unrelated edit.
     *
     * @return array{used:float,total:float,after:float,overflow:float,name:string}|null
     */
    public static function overflowInfo(
        int $sprintId,
        int $userId,
        float $additional,
        int $excludeRegularItemId = 0,
        int $excludeFastlaneMemberId = 0,
        int $excludeDependencyId = 0
    ): ?array {
        if ($additional <= 0 || $userId <= 0 || $sprintId <= 0) {
            return null;
        }

        $member  = new self();
        $members = $member->find([
            'plugin_sprint_sprints_id' => $sprintId,
            'users_id'                 => $userId,
        ]);
        if (count($members) === 0) {
            return null;
        }
        $total = SprintAgility::effectiveCapacity(
            $sprintId,
            $userId,
            self::normalizeCapacity(reset($members)['capacity_percent'])
        );
        $used  = self::getUsedCapacityForUser($sprintId, $userId, $excludeRegularItemId, $excludeFastlaneMemberId, $excludeDependencyId);
        $after = $used + $additional;
        if ($after <= $total) {
            return null;
        }

        return [
            'used'     => $used,
            'total'    => $total,
            'after'    => $after,
            'overflow' => $after - $total,
            'name'     => SprintCache::userName($userId),
        ];
    }

    /**
     * Plain-text overflow confirmation question. Shown in a native JS
     * confirm() dialog, so no HTML escaping is needed.
     */
    public static function overflowConfirmMessage(array $info): string
    {
        return sprintf(
            __('%1$s is already at %2$s%% of %3$s%% capacity. This brings the total to %4$s%% (+%5$s%% over). Assign anyway?', 'sprint'),
            $info['name'],
            self::formatCapacity($info['used']),
            self::formatCapacity($info['total']),
            self::formatCapacity($info['after']),
            self::formatCapacity($info['overflow'])
        );
    }

    /**
     * Get members of a sprint as dropdown options.
     *
     * @param int $sprintId
     * @return array [users_id => "Username (Role)"]
     */
    /**
     * True when the user is a Scrum Master (member with ROLE_SCRUM_MASTER) on
     * the sprint. Complements the Sprint.users_id check in Config.
     */
    public static function isScrumMaster(int $sprintId, int $userId): bool
    {
        if ($sprintId <= 0 || $userId <= 0) {
            return false;
        }
        return countElementsInTable(self::getTable(), [
            'plugin_sprint_sprints_id' => $sprintId,
            'users_id'                 => $userId,
            'role'                     => self::ROLE_SCRUM_MASTER,
        ]) > 0;
    }

    public static function getSprintMemberOptions(int $sprintId): array
    {
        $options = [0 => Dropdown::EMPTY_VALUE];

        $member  = new self();
        $members = $member->find(['plugin_sprint_sprints_id' => $sprintId]);
        $roles   = self::getAllRoles();

        foreach ($members as $row) {
            $name     = SprintCache::userName($row['users_id']);
            $roleName = $roles[$row['role']] ?? $row['role'];
            $options[(int)$row['users_id']] = "{$name} ({$roleName})";
        }

        return $options;
    }
}
