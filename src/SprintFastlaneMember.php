<?php

namespace GlpiPlugin\Sprint;

use CommonDBRelation;
use CommonGLPI;
use Html;
use Session;
use User;
use Dropdown;

/**
 * SprintFastlaneMember - Junction linking a Fastlane SprintItem to multiple
 * sprint members, each with their own assigned capacity %.
 *
 * Registered as a tab on SprintItem (only displayed when the parent item
 * has is_fastlane = 1). Allocations made here count against the member's
 * total sprint capacity, just like regular SprintItem.capacity, and are
 * surfaced in the dashboard under the "Fastlane" category so the team can
 * steer how much of the sprint goes to fastlane work.
 */
class SprintFastlaneMember extends CommonDBRelation
{
    public static $itemtype_1 = 'GlpiPlugin\Sprint\SprintItem';
    public static $items_id_1 = 'plugin_sprint_sprintitems_id';
    public static $itemtype_2 = 'User';
    public static $items_id_2 = 'users_id';

    public static $rightname  = 'plugin_sprint_item';
    public $dohistory          = true;

    public static function getTypeName($nb = 0): string
    {
        return _n('Fastlane Member', 'Fastlane Members', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-bolt';
    }

    /**
     * Tab is only meaningful on Fastlane SprintItems.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof SprintItem && (int)($item->fields['is_fastlane'] ?? 0) === 1) {
            $count = countElementsInTable(
                self::getTable(),
                ['plugin_sprint_sprintitems_id' => $item->getID()]
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
        if (isset($input['users_id']))                    $input['users_id']                    = (int)$input['users_id'];
        if (isset($input['plugin_sprint_sprintitems_id'])) $input['plugin_sprint_sprintitems_id'] = (int)$input['plugin_sprint_sprintitems_id'];
        if (isset($input['capacity']))                    $input['capacity']                    = max(0, min(100, (int)$input['capacity']));

        if (!$this->validateCapacity($input)) {
            return false;
        }
        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['capacity'])) $input['capacity'] = max(0, min(100, (int)$input['capacity']));
        if (isset($input['users_id'])) $input['users_id'] = (int)$input['users_id'];

        if (!$this->validateCapacity($input, (int)($input['id'] ?? 0))) {
            return false;
        }
        return parent::prepareInputForUpdate($input);
    }

    /**
     * Make sure assigning capacity to this user does not exceed their
     * remaining sprint capacity (after counting both regular SprintItems
     * and other Fastlane allocations).
     */
    private function validateCapacity(array $input, int $excludeId = 0): bool
    {
        $capacity = (int)($input['capacity'] ?? 0);
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
            0,                  // not excluding any regular item
            $excludeId          // exclude this fastlane member row when updating
        );
    }

    /**
     * List + add form for fastlane members of a SprintItem.
     */
    public static function showForSprintItem(SprintItem $sprintItem): void
    {
        $itemId   = $sprintItem->getID();
        $sprintId = (int)$sprintItem->fields['plugin_sprint_sprints_id'];
        $canedit  = $sprintItem->canUpdateItem();

        if ((int)$sprintItem->fields['is_fastlane'] !== 1) {
            echo "<div class='center'><p class='text-muted'>" .
                __('This is not a fastlane item. Toggle the fastlane flag from the backlog or item edit form first.', 'sprint') .
                "</p></div>";
            return;
        }

        // === Add member form ===
        if ($canedit && $sprintId > 0) {
            $memberOptions = SprintMember::getSprintMemberOptions($sprintId);

            echo "<div class='center'>";
            echo "<form method='post' action='" . static::getFormURL() . "'>";
            echo Html::hidden('plugin_sprint_sprintitems_id', ['value' => $itemId]);

            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_2'><th colspan='4'>" .
                __('Assign a sprint member to this fastlane item', 'sprint') . "</th></tr>";
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Sprint member', 'sprint') . "</td><td>";
            Dropdown::showFromArray('users_id', $memberOptions);
            echo "</td>";
            echo "<td>" . __('Capacity (%)', 'sprint') . "</td><td>";
            Dropdown::showFromArray('capacity', SprintMember::getCapacityChoices(), [
                'value' => 5,
            ]);
            echo "</td></tr>";
            echo "<tr class='tab_bg_1'><td colspan='4' class='center'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        // === List existing fastlane members ===
        $rel  = new self();
        $rows = $rel->find(['plugin_sprint_sprintitems_id' => $itemId]);

        echo "<div class='center'><table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('User') . "</th>";
        echo "<th>" . __('Capacity (%)', 'sprint') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($rows) === 0) {
            $cols = $canedit ? 3 : 2;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('No members assigned yet', 'sprint') . "</td></tr>";
        }

        $totalCap = 0;
        foreach ($rows as $row) {
            $uid      = (int)$row['users_id'];
            $cap      = (int)$row['capacity'];
            $totalCap += $cap;

            echo "<tr class='tab_bg_1'>";
            echo "<td><i class='fas fa-user' style='margin-right:6px;opacity:0.6;'></i>" . getUserName($uid) . "</td>";
            echo "<td class='center'>{$cap}%</td>";
            if ($canedit) {
                echo "<td class='center' style='white-space:nowrap;'>";
                // Inline capacity update form
                echo "<form method='post' action='" . static::getFormURL() . "' style='display:inline-flex;gap:4px;align-items:center;margin-right:6px;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                Dropdown::showFromArray('capacity', SprintMember::getCapacityChoices(), [
                    'value' => $cap,
                ]);
                echo "<button type='submit' name='update' value='1' class='btn btn-sm btn-outline-primary' title='" . __('Update') . "'><i class='fas fa-save'></i></button>";
                Html::closeForm();
                // Delete
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
        echo "<th class='right'>" . __('Total Fastlane capacity', 'sprint') . "</th>";
        echo "<th class='center'>{$totalCap}%</th>";
        if ($canedit) {
            echo "<th></th>";
        }
        echo "</tr>";

        echo "</table></div>";
    }

    /**
     * Get the IDs of fastlane SprintItems belonging to a sprint.
     *
     * @return int[]
     */
    private static function getFastlaneItemIdsForSprint(int $sprintId): array
    {
        $si  = new SprintItem();
        $ids = [];
        foreach ($si->find([
            'plugin_sprint_sprints_id' => $sprintId,
            'is_fastlane'              => 1,
        ]) as $row) {
            $ids[] = (int)$row['id'];
        }
        return $ids;
    }

    /**
     * Sum the fastlane capacity assigned to a given user across all
     * fastlane items of a sprint.
     */
    public static function getUsedFastlaneCapacityForUser(int $sprintId, int $userId, int $excludeId = 0): int
    {
        $itemIds = self::getFastlaneItemIdsForSprint($sprintId);
        if (count($itemIds) === 0) {
            return 0;
        }

        $criteria = [
            'plugin_sprint_sprintitems_id' => $itemIds,
            'users_id'                     => $userId,
        ];
        if ($excludeId > 0) {
            $criteria['NOT'] = ['id' => $excludeId];
        }

        $rel   = new self();
        $total = 0;
        foreach ($rel->find($criteria) as $row) {
            $total += (int)$row['capacity'];
        }
        return $total;
    }

    /**
     * Sum the total fastlane capacity allocated across the whole sprint
     * (all members, all fastlane items).
     */
    public static function getTotalFastlaneCapacityForSprint(int $sprintId): int
    {
        $itemIds = self::getFastlaneItemIdsForSprint($sprintId);
        if (count($itemIds) === 0) {
            return 0;
        }

        $rel   = new self();
        $total = 0;
        foreach ($rel->find(['plugin_sprint_sprintitems_id' => $itemIds]) as $row) {
            $total += (int)$row['capacity'];
        }
        return $total;
    }
}
