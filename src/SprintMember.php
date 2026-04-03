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
            Dropdown::showNumber('capacity_percent', [
                'value' => 100,
                'min'   => 0,
                'max'   => 100,
                'step'  => 10,
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
                    'item'   => $this,
                    'params' => $options,
                    'roles'  => self::getAllRoles(),
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
            Dropdown::showNumber('capacity_percent', [
                'value' => $this->fields['capacity_percent'] ?? 100, 'min' => 0, 'max' => 100, 'step' => 10,
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
