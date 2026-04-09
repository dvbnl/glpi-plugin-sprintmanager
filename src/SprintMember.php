<?php

namespace GlpiPlugin\Sprint;

use CommonDBRelation;
use CommonGLPI;
use Html;
use Session;
use User;
use Dropdown;

/**
 * SprintMember - Link GLPI Users to Sprints with a role
 *
 * Roles: Scrum Master, Product Owner, Developer, Tester, Designer, etc.
 * Also tracks capacity (availability percentage) per sprint.
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
     * Build the canonical capacity-percent dropdown choices.
     *
     * Granular at the bottom (1..5) so very small allocations
     * (e.g. a 1% Fastlane slot) can still be expressed, then
     * grouped per 5% from 5..100 to keep the picker manageable.
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
        // 1..5 incremental
        for ($i = 1; $i <= 5; $i++) {
            $values[] = $i;
        }
        // 10..100 grouped per 5
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
     * Get all available roles
     */
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

    /**
     * Show members list + add form for a sprint
     */
    public static function showForSprint(Sprint $sprint): void
    {
        $ID      = $sprint->getID();
        $canedit = Sprint::canUpdate();
        $roles   = self::getAllRoles();

        // === Add member form ===
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

        // === List members ===
        $member  = new self();
        $members = $member->find(
            ['plugin_sprint_sprints_id' => $ID],
            ['role ASC']
        );

        // Count linked items per member
        $memberItemCounts = self::getMemberItemCounts($ID);

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

        echo "<div class='center'><table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('User') . "</th>";
        echo "<th>" . __('Role', 'sprint') . "</th>";
        echo "<th>" . __('Capacity', 'sprint') . "</th>";
        echo "<th>" . __('Linked Items', 'sprint') . "</th>";
        echo "<th>" . __('Comment') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($members) === 0) {
            $cols = $canedit ? 6 : 5;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('No team members added', 'sprint') . "</td></tr>";
        }

        foreach ($members as $row) {
            $icon       = $roleIcons[$row['role']] ?? 'fas fa-user';
            $roleName   = $roles[$row['role']] ?? $row['role'];
            $userId     = (int)$row['users_id'];
            $itemCount  = $memberItemCounts[$userId] ?? 0;

            echo "<tr class='tab_bg_1'>";
            echo "<td><i class='fas fa-user'></i> " . getUserName($userId) . "</td>";
            echo "<td><i class='{$icon}'></i> {$roleName}</td>";
            echo "<td class='center'>";
            // Visual capacity bar
            $pct = (int)$row['capacity_percent'];
            $barColor = $pct >= 80 ? '#198754' : ($pct >= 50 ? '#ffc107' : '#dc3545');
            echo "<div style='display:flex;align-items:center;gap:8px;'>";
            echo "<div style='width:80px;height:10px;background:#e9ecef;border-radius:5px;overflow:hidden;'>";
            echo "<div style='width:{$pct}%;height:100%;background:{$barColor};'></div>";
            echo "</div>";
            echo "<span>{$pct}%</span>";
            echo "</div>";
            echo "</td>";
            echo "<td class='center'>{$itemCount}</td>";
            echo "<td>" . htmlescape($row['comment'] ?? '') . "</td>";

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
     * Count how many tickets/changes/project tasks are assigned to each member in this sprint
     *
     * @param int $sprintId
     * @return array [users_id => count]
     */
    private static function getMemberItemCounts(int $sprintId): array
    {
        $counts = [];

        // Tickets
        $st = new SprintTicket();
        foreach ($st->find(['plugin_sprint_sprints_id' => $sprintId]) as $row) {
            $uid = (int)$row['users_id'];
            if ($uid > 0) {
                $counts[$uid] = ($counts[$uid] ?? 0) + 1;
            }
        }

        // Changes
        $sc = new SprintChange();
        foreach ($sc->find(['plugin_sprint_sprints_id' => $sprintId]) as $row) {
            $uid = (int)$row['users_id'];
            if ($uid > 0) {
                $counts[$uid] = ($counts[$uid] ?? 0) + 1;
            }
        }

        // Project Tasks
        $sp = new SprintProjectTask();
        foreach ($sp->find(['plugin_sprint_sprints_id' => $sprintId]) as $row) {
            $uid = (int)$row['users_id'];
            if ($uid > 0) {
                $counts[$uid] = ($counts[$uid] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Show edit form for a member
     */
    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

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
     * Compute the capacity already used by a user in a sprint, summing
     * both regular SprintItem allocations and Fastlane member allocations.
     *
     * @param int $sprintId
     * @param int $userId
     * @param int $excludeRegularItemId  SprintItem id to exclude (for updates of a regular item)
     * @param int $excludeFastlaneMemberId  SprintFastlaneMember id to exclude (for updates of a fastlane allocation)
     * @return int  Used capacity %
     */
    public static function getUsedCapacityForUser(
        int $sprintId,
        int $userId,
        int $excludeRegularItemId = 0,
        int $excludeFastlaneMemberId = 0
    ): int {
        // Regular sprint items: sum capacity for non-fastlane items the
        // user owns. Fastlane items have multiple owners via the junction
        // table, so we explicitly exclude them here.
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

        // Fastlane allocations from the junction table.
        $fastlaneUsed = SprintFastlaneMember::getUsedFastlaneCapacityForUser(
            $sprintId,
            $userId,
            $excludeFastlaneMemberId
        );

        return $regularUsed + $fastlaneUsed;
    }

    /**
     * Validate that adding $additional% to the user's allocation in a sprint
     * does not exceed the member's total capacity. Adds an error message
     * via Session::addMessageAfterRedirect on failure.
     */
    public static function checkCapacityForUser(
        int $sprintId,
        int $userId,
        int $additional,
        int $excludeRegularItemId = 0,
        int $excludeFastlaneMemberId = 0
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

        $used      = self::getUsedCapacityForUser($sprintId, $userId, $excludeRegularItemId, $excludeFastlaneMemberId);
        $remaining = $totalCapacity - $used;

        if ($additional > $remaining) {
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
     * Get members of a sprint as dropdown options
     * Useful for the link tables (SprintTicket, SprintChange, SprintProjectTask)
     *
     * @param int $sprintId
     * @return array [users_id => "Username (Role)"]
     */
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
