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

    public static function getIcon(): string
    {
        return 'fas fa-user-friends';
    }

    /**
     * Capacity-percent dropdown choices. Granular 1..5 so tiny allocations
     * (e.g. a 1% Fastlane slot) are expressible, then per-5 to keep it short.
     *
     * @param bool $includeZero If true, prepends a 0% option.
     * @return array<int,string> [value => label]
     */
    public static function getCapacityChoices(bool $includeZero = true): array
    {
        $values = [];
        if ($includeZero) {
            $values[] = 0;
        }
        for ($i = 1; $i <= 5; $i++) {
            $values[] = $i;
        }
        for ($i = 10; $i <= 100; $i += 5) {
            $values[] = $i;
        }
        $out = [];
        foreach ($values as $v) {
            $out[$v] = $v . '%';
        }
        return $out;
    }

    /**
     * Render a stacked capacity bar. Segments are sized against max(total, used)
     * so they fit even when over capacity; the slice past total renders as a
     * striped red overflow band with a 100% marker line.
     */
    public static function renderCapacityBar(
        int $total,
        int $regularUsed,
        int $fastlaneUsed,
        int $dependencyUsed,
        int $height = 10,
        string $borderRadius = '5px'
    ): string {
        $used      = $regularUsed + $fastlaneUsed + $dependencyUsed;
        $denom     = max($total, $used, 1);
        $regularW    = ($denom > 0) ? round(($regularUsed / $denom) * 100, 2) : 0;
        $fastlaneW   = ($denom > 0) ? round(($fastlaneUsed / $denom) * 100, 2) : 0;
        $dependencyW = ($denom > 0) ? round(($dependencyUsed / $denom) * 100, 2) : 0;

        $overflow  = max($used - $total, 0);
        $overflowW = ($denom > 0 && $overflow > 0) ? round(($overflow / $denom) * 100, 2) : 0;

        $totalMarkerLeft = ($used > $total && $denom > 0) ? round(($total / $denom) * 100, 2) : 0;
        $regularColor    = $used > $total ? '#dc3545' : ($used >= $total * 0.8 ? '#e67e22' : '#198754');

        $html = "<div style='position:relative;display:flex;height:{$height}px;background:#e9ecef;border-radius:{$borderRadius};overflow:hidden;'>";
        if ($regularW > 0) {
            $html .= "<div style='width:{$regularW}%;height:100%;background:{$regularColor};' title='" . __('Regular', 'sprint') . " {$regularUsed}%'></div>";
        }
        if ($fastlaneW > 0) {
            $html .= "<div style='width:{$fastlaneW}%;height:100%;background:#fd7e14;' title='" . __('Fastlane', 'sprint') . " {$fastlaneUsed}%'></div>";
        }
        if ($dependencyW > 0) {
            $html .= "<div style='width:{$dependencyW}%;height:100%;background:#20c997;' title='" . __('Dependencies', 'sprint') . " {$dependencyUsed}%'></div>";
        }
        if ($overflowW > 0) {
            $stripe = 'repeating-linear-gradient(45deg,#dc3545,#dc3545 4px,#a71d2a 4px,#a71d2a 8px)';
            $html .= "<div style='width:{$overflowW}%;height:100%;background:{$stripe};' title='" . sprintf(__('Overflow %d%%', 'sprint'), $overflow) . "'></div>";
            $html .= "<div style='position:absolute;top:-2px;bottom:-2px;left:{$totalMarkerLeft}%;width:2px;background:#212529;' title='100%'></div>";
        }
        $html .= "</div>";
        return $html;
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
        if (isset($input['capacity_percent']))          $input['capacity_percent']          = max(0, min(100, (int)$input['capacity_percent']));
        if (isset($input['role']) && !array_key_exists($input['role'], self::getAllRoles())) {
            $input['role'] = self::ROLE_DEVELOPER;
        }
        return parent::prepareInputForAdd($input);
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

            echo "<table class='tab_cadre_fixe'>";
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
                'value' => self::ROLE_DEVELOPER,
            ]);
            echo "</td>";
            echo "<td>" . __('Capacity (%)', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('capacity_percent', self::getCapacityChoices(), [
                'value' => 100,
            ]);
            echo "</td>";
            echo "</tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Comment') . "</td>";
            echo "<td colspan='3'>";
            echo "<textarea name='comment' rows='2' cols='60'></textarea>";
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

        self::renderMembersDashboard($ID, $members, $memberStatusCounts);

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

        echo "<div class='center' style='margin-top:16px;'><table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'><th colspan='" . ($canedit ? 4 : 3) . "'>" .
            "<i class='fas fa-user-cog' style='margin-right:6px;'></i>" .
            __('Member settings', 'sprint') . "</th></tr>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('User') . "</th>";
        echo "<th>" . __('Role', 'sprint') . "</th>";
        echo "<th>" . __('Capacity', 'sprint') . "</th>";
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
            echo "<td><i class='fas fa-user'></i> " . htmlescape(getUserName($userId)) . "</td>";
            echo "<td><i class='{$icon}'></i> {$roleName}</td>";
            echo "<td class='center'>";
            $pct = (int)$row['capacity_percent'];
            $barColor = $pct >= 80 ? '#198754' : ($pct >= 50 ? '#ffc107' : '#dc3545');
            echo "<div style='display:flex;align-items:center;gap:8px;'>";
            echo "<div style='width:80px;height:10px;background:#e9ecef;border-radius:5px;overflow:hidden;'>";
            echo "<div style='width:{$pct}%;height:100%;background:{$barColor};'></div>";
            echo "</div>";
            echo "<span>{$pct}%</span>";
            echo "</div>";
            echo "</td>";

            if ($canedit) {
                echo "<td class='center'>";
                echo "<a href='" . static::getFormURLWithID($row['id']) .
                    "' class='btn btn-sm btn-outline-primary' title='" . __('Edit') . "'>" .
                    "<i class='fas fa-edit'></i></a> ";
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
    private static function renderMembersDashboard(int $sprintId, array $members, array $memberStatusCounts): void
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

        $si = new SprintItem();
        foreach ($members as $row) {
            $userId   = (int)$row['users_id'];
            $totalCap = (int)$row['capacity_percent'];
            $roleName = $roles[$row['role']] ?? $row['role'];
            $roleIcon = $roleIcons[$row['role']] ?? 'fas fa-user';

            $regularUsed = 0;
            foreach ($si->find([
                'plugin_sprint_sprints_id' => $sprintId,
                'users_id'                 => $userId,
                'is_fastlane'              => 0,
            ]) as $r) {
                $regularUsed += (int)($r['capacity'] ?? 0);
            }
            $fastlaneUsed   = SprintFastlaneMember::getUsedFastlaneCapacityForUser($sprintId, $userId);
            $dependencyUsed = SprintItemDependency::getUsedDependencyCapacityForUser($sprintId, $userId);
            $usedCap        = $regularUsed + $fastlaneUsed + $dependencyUsed;
            $remaining      = max($totalCap - $usedCap, 0);
            $capUsedPct     = $totalCap > 0 ? round(($usedCap / $totalCap) * 100) : 0;

            $counts = $memberStatusCounts[$userId] ?? [];
            $total  = (int)($counts['total'] ?? 0);
            $done   = (int)($counts[SprintItem::STATUS_DONE] ?? 0);
            $progressPct = $total > 0 ? round(($done / $total) * 100) : 0;

            $fastlaneItemCount   = (int)($counts['fastlane_total'] ?? 0);
            $dependencyItemCount = (int)($counts['dependency_total'] ?? 0);

            $progressColor = $progressPct >= 80 ? '#198754' : ($progressPct >= 50 ? '#ffc107' : ($progressPct > 0 ? '#0d6efd' : '#adb5bd'));
            $capBarColor   = $capUsedPct >= 100 ? '#dc3545' : ($capUsedPct >= 80 ? '#e67e22' : '#198754');

            echo "<div style='border:1px solid #e9ecef;border-radius:10px;padding:14px 16px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,0.03);'>";

            echo "<div style='display:flex;align-items:center;gap:10px;margin-bottom:10px;'>";
            echo "<i class='fas fa-user-circle' style='font-size:1.6em;color:#6c757d;'></i>";
            echo "<div style='flex:1;'>";
            echo "<div style='font-weight:700;'>" . htmlescape(getUserName($userId)) . "</div>";
            echo "<div style='font-size:0.82em;color:#6c757d;'><i class='{$roleIcon}'></i> " . htmlescape($roleName) . "</div>";
            echo "</div>";
            if ($fastlaneItemCount > 0) {
                echo "<span title='" . __('Fastlane items assigned', 'sprint') . "' "
                    . "style='background:#fff3cd;color:#fd7e14;padding:2px 8px;border-radius:10px;font-size:0.78em;font-weight:700;margin-right:4px;'>"
                    . "<i class='fas fa-bolt'></i> {$fastlaneItemCount}</span>";
            }
            if ($dependencyItemCount > 0) {
                echo "<span title='" . __('Open dependency allocations', 'sprint') . "' "
                    . "style='background:#d1f2ea;color:#20c997;padding:2px 8px;border-radius:10px;font-size:0.78em;font-weight:700;'>"
                    . "<i class='fas fa-link'></i> {$dependencyItemCount}</span>";
            }
            echo "</div>";

            echo "<div style='margin-bottom:8px;'>";
            echo "<div style='display:flex;justify-content:space-between;font-size:0.78em;color:#6c757d;margin-bottom:3px;'>";
            echo "<span>" . __('Sprint progress', 'sprint') . "</span>";
            echo "<span><strong>{$done}</strong> / {$total} " . __('items done', 'sprint') . " ({$progressPct}%)</span>";
            echo "</div>";
            echo "<div style='height:10px;background:#e9ecef;border-radius:5px;overflow:hidden;'>";
            echo "<div style='width:{$progressPct}%;height:100%;background:{$progressColor};transition:width 0.3s;'></div>";
            echo "</div>";
            echo "</div>";

            $overflowCap = max($usedCap - $totalCap, 0);
            echo "<div style='margin-bottom:10px;'>";
            echo "<div style='display:flex;justify-content:space-between;font-size:0.78em;color:#6c757d;margin-bottom:3px;'>";
            echo "<span>" . __('Capacity used', 'sprint') . "</span>";
            if ($overflowCap > 0) {
                echo "<span><strong style='color:#dc3545;'>{$usedCap}%</strong> / {$totalCap}% &mdash; "
                    . "<span style='color:#dc3545;font-weight:600;'>"
                    . "<i class='fas fa-exclamation-triangle' style='margin-right:2px;'></i>"
                    . sprintf(__('%d%% overflow', 'sprint'), $overflowCap)
                    . "</span></span>";
            } else {
                echo "<span><strong>{$usedCap}%</strong> / {$totalCap}% &mdash; "
                    . sprintf(__('%d%% free', 'sprint'), $remaining) . "</span>";
            }
            echo "</div>";
            echo self::renderCapacityBar($totalCap, $regularUsed, $fastlaneUsed, $dependencyUsed);
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
                        . "<span class='text-muted'>({$h['capacity']}%";
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

        echo "</div></div>";
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

        $out .= "<div style='flex:1;min-width:120px;height:10px;background:#e9ecef;border-radius:5px;overflow:hidden;display:flex;'>";
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
                    'capacity_choices' => self::getCapacityChoices(),
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

            echo "<tr class='tab_bg_1'><td>" . __('Capacity (%)', 'sprint') . "</td><td>";
            Dropdown::showFromArray('capacity_percent', self::getCapacityChoices(), [
                'value' => $this->fields['capacity_percent'] ?? 100,
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

    /**
     * Capacity already used by a user in a sprint: regular + Fastlane + open
     * Dependency allocations. Exclude ids let an update re-check the row it edits.
     *
     * @param int $sprintId
     * @param int $userId
     * @param int $excludeRegularItemId      SprintItem id to exclude
     * @param int $excludeFastlaneMemberId   SprintFastlaneMember id to exclude
     * @param int $excludeDependencyId       SprintItemDependency id to exclude
     * @return int  Used capacity %
     */
    public static function getUsedCapacityForUser(
        int $sprintId,
        int $userId,
        int $excludeRegularItemId = 0,
        int $excludeFastlaneMemberId = 0,
        int $excludeDependencyId = 0
    ): int {
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
        $regularUsed = 0;
        foreach ($si->find($criteria) as $row) {
            $regularUsed += (int)($row['capacity'] ?? 0);
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
     * Validate that adding $additional% stays within the member's capacity.
     * Queues a Session message on failure (or a warning when $allowOverflow).
     */
    public static function checkCapacityForUser(
        int $sprintId,
        int $userId,
        int $additional,
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
        $totalCapacity = (int)$row['capacity_percent'];

        $used      = self::getUsedCapacityForUser($sprintId, $userId, $excludeRegularItemId, $excludeFastlaneMemberId, $excludeDependencyId);
        $remaining = $totalCapacity - $used;

        if ($additional > $remaining) {
            if ($allowOverflow) {
                $newUsed = $used + $additional;
                Session::addMessageAfterRedirect(
                    sprintf(
                        __('%s is now over capacity: %d%% used of %d%% (+%d%% overflow).', 'sprint'),
                        getUserName($userId),
                        $newUsed,
                        $totalCapacity,
                        $newUsed - $totalCapacity
                    ),
                    false,
                    WARNING
                );
                return true;
            }
            Session::addMessageAfterRedirect(
                sprintf(
                    __('%s has only %d%% capacity remaining (total: %d%%, used: %d%%). Cannot assign %d%%.', 'sprint'),
                    getUserName($userId),
                    max($remaining, 0),
                    $totalCapacity,
                    $used,
                    $additional
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
     * @return array{used:int,total:int,after:int,overflow:int,name:string}|null
     */
    public static function overflowInfo(
        int $sprintId,
        int $userId,
        int $additional,
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
        $total = (int)reset($members)['capacity_percent'];
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
            'name'     => getUserName($userId),
        ];
    }

    /**
     * Plain-text overflow confirmation question. Shown in a native JS
     * confirm() dialog, so no HTML escaping is needed.
     */
    public static function overflowConfirmMessage(array $info): string
    {
        return sprintf(
            __('%1$s is already at %2$d%% of %3$d%% capacity. This brings the total to %4$d%% (+%5$d%% over). Assign anyway?', 'sprint'),
            $info['name'],
            $info['used'],
            $info['total'],
            $info['after'],
            $info['overflow']
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
            $name     = getUserName($row['users_id']);
            $roleName = $roles[$row['role']] ?? $row['role'];
            $options[(int)$row['users_id']] = "{$name} ({$roleName})";
        }

        return $options;
    }
}
