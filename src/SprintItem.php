<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use CommonGLPI;
use Html;
use Session;
use User;
use Dropdown;
use Ticket;
use Change;
use ProjectTask;

/**
 * SprintItem - Backlog items within a sprint
 *
 * Can optionally link to a GLPI item (Ticket, Change, ProjectTask)
 * via the itemtype + items_id fields.
 */
class SprintItem extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_item';
    public $dohistory        = true;

    const STATUS_TODO        = 'todo';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_REVIEW      = 'review';
    const STATUS_DONE        = 'done';
    const STATUS_BLOCKED     = 'blocked';

    /**
     * Check if the current user owns this item
     */
    private function isOwnItem(): bool
    {
        return (int)($this->fields['users_id'] ?? 0) === (int)Session::getLoginUserID();
    }

    /**
     * Check if user has only the "own items" right (not full rights)
     */
    private static function hasOnlyOwnRight(int $rightFlag): bool
    {
        return !Session::haveRight(self::$rightname, $rightFlag)
            && Session::haveRight(self::$rightname, Profile::RIGHT_OWN_ITEMS);
    }

    public function canCreateItem(): bool
    {
        // Users with OWN_ITEMS right can create items (they'll be assigned to themselves)
        if (self::hasOnlyOwnRight(CREATE)) {
            return true;
        }
        return parent::canCreateItem();
    }

    public function canUpdateItem(): bool
    {
        if (self::hasOnlyOwnRight(UPDATE)) {
            return $this->isOwnItem();
        }
        return parent::canUpdateItem();
    }

    public function canDeleteItem(): bool
    {
        if (self::hasOnlyOwnRight(DELETE)) {
            return $this->isOwnItem();
        }
        return parent::canDeleteItem();
    }

    public function canPurgeItem(): bool
    {
        if (self::hasOnlyOwnRight(PURGE)) {
            return $this->isOwnItem();
        }
        return parent::canPurgeItem();
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Sprint Item', 'Sprint Items', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-clipboard-list';
    }

    public static function getAllStatuses(): array
    {
        return [
            self::STATUS_TODO        => __('To Do', 'sprint'),
            self::STATUS_IN_PROGRESS => __('In Progress', 'sprint'),
            self::STATUS_REVIEW      => __('In Review', 'sprint'),
            self::STATUS_DONE        => __('Done', 'sprint'),
            self::STATUS_BLOCKED     => __('Blocked', 'sprint'),
        ];
    }

    /**
     * Get the supported linked item types
     */
    public static function getLinkedItemTypes(): array
    {
        return [
            ''            => __('Manual item', 'sprint'),
            'Ticket'      => __('Ticket'),
            'Change'      => __('Change'),
            'ProjectTask' => __('Project task'),
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

    /**
     * Get the display name for a linked GLPI item
     */
    public function getLinkedItemDisplay(): string
    {
        $itemtype = $this->fields['itemtype'] ?? '';
        $itemsId  = (int)($this->fields['items_id'] ?? 0);

        $allowedTypes = ['Ticket', 'Change', 'ProjectTask'];

        if (empty($itemtype) || $itemsId <= 0) {
            return '';
        }

        if (!in_array($itemtype, $allowedTypes, true) || !class_exists($itemtype)) {
            return '';
        }

        $linkedItem = new $itemtype();
        if (!$linkedItem->getFromDB($itemsId)) {
            return '<span style="color:#dc3545;"><i class="fas fa-exclamation-triangle"></i> ' .
                __('Item not found', 'sprint') . '</span>';
        }

        $icons = [
            'Ticket'      => 'fas fa-ticket-alt',
            'Change'      => 'fas fa-exchange-alt',
            'ProjectTask' => 'fas fa-tasks',
        ];
        $icon = $icons[$itemtype] ?? 'fas fa-link';
        $url  = $itemtype::getFormURLWithID($itemsId);
        $name = htmlescape($linkedItem->fields['name'] ?? '');

        return "<a href='{$url}'><i class='{$icon}'></i> {$name}</a>";
    }

    /**
     * Show sprint items list and add form
     */
    public static function showForSprint(Sprint $sprint): void
    {
        $ID      = $sprint->getID();
        $canedit = self::canUpdate()
            || Session::haveRight(self::$rightname, Profile::RIGHT_OWN_ITEMS);

        // Add form
        if ($canedit) {
            $memberOptions = SprintMember::getSprintMemberOptions($ID);
            $rand = mt_rand();

            echo "<div class='center'>";
            echo "<form method='post' action='" . static::getFormURL() . "'>";
            echo Html::hidden('plugin_sprint_sprints_id', ['value' => $ID]);

            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_2'>";
            echo "<th colspan='6'>" . __('Add a sprint item', 'sprint') . "</th>";
            echo "</tr>";

            // Row 1: Type + Item selector (dynamic)
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Type', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('itemtype', self::getLinkedItemTypes(), [
                'value' => '',
                'rand'  => $rand,
            ]);
            echo "</td>";
            // Label changes dynamically
            echo "<td><span id='sprint_label_{$rand}'>" . __('Name') . "</span></td>";
            echo "<td colspan='3'>";
            // Manual name input (shown by default)
            echo "<div id='sprint_manual_name_{$rand}'>";
            echo Html::input('name', ['size' => 40]);
            echo "</div>";
            // Container for AJAX-loaded item dropdown
            echo "<div id='sprint_item_container_{$rand}' style='display:none;'></div>";
            echo "</td></tr>";

            // Inline script: load item dropdown via AJAX when type changes
            echo "<script>
            $(function() {
                var r = '{$rand}';
                var dropdownUrl = CFG_GLPI.root_doc + '/plugins/sprint/ajax/getitemdropdown.php';
                var labels = {
                    '': '" . __('Name') . "',
                    'Ticket': '" . __('Ticket') . "',
                    'Change': '" . __('Change') . "',
                    'ProjectTask': '" . __('Project') . " / " . __('Project task') . "'
                };

                var sel = $('select[name=\"itemtype\"]').last();
                sel.on('change', function() {
                    var val = $(this).val();
                    var container = $('#sprint_item_container_' + r);
                    var manualName = $('#sprint_manual_name_' + r);

                    $('#sprint_label_' + r).text(labels[val] || '" . __('Name') . "');

                    if (!val || val === '') {
                        manualName.show();
                        container.hide().empty();
                    } else {
                        manualName.hide();
                        container.html('<i class=\"fas fa-spinner fa-spin\"></i>').show();
                        $.ajax({
                            url: dropdownUrl,
                            type: 'POST',
                            data: {
                                itemtype: val,
                                rand: r,
                                _glpi_csrf_token: $('input[name=\"_glpi_csrf_token\"]').first().val()
                            },
                            success: function(html) {
                                container.html(html);
                            }
                        });
                    }
                });
            });
            </script>";

            // Row 2: Owner, Status, Story Points
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Owner', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('users_id', $memberOptions);
            echo "</td>";
            echo "<td>" . __('Status') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('status', self::getAllStatuses(), [
                'value' => self::STATUS_TODO,
            ]);
            echo "</td>";
            echo "<td>" . __('Story Points', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showNumber('story_points', ['value' => 0, 'min' => 0, 'max' => 100]);
            echo "</td></tr>";

            // Row 3: Capacity, Priority + Submit
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Capacity (%)', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showNumber('capacity', [
                'value' => 0, 'min' => 0, 'max' => 100, 'step' => 5,
            ]);
            echo "</td>";
            echo "<td>" . __('Priority') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('priority', [
                1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
                4 => __('High'), 5 => __('Very high'),
            ], ['value' => 3]);
            echo "</td>";
            echo "<td colspan='2'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";

            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        // List existing items
        $item  = new self();
        $items = $item->find(
            ['plugin_sprint_sprints_id' => $ID],
            ['sort_order ASC', 'priority DESC']
        );

        $statuses   = self::getAllStatuses();
        $priorities = [
            1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
            4 => __('High'), 5 => __('Very high'),
        ];

        echo "<div class='center'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Name') . "</th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th>" . __('Status') . "</th>";
        echo "<th>" . __('Priority') . "</th>";
        echo "<th>" . __('Story Points', 'sprint') . "</th>";
        echo "<th>" . __('Capacity (%)', 'sprint') . "</th>";
        echo "<th>" . __('Owner', 'sprint') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($items) === 0) {
            $cols = $canedit ? 8 : 7;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('No items found', 'sprint') . "</td></tr>";
        }

        foreach ($items as $row) {
            $statusClass = 'sprint-status-' . str_replace('_', '-', $row['status']);

            // Build linked item display
            $linkedDisplay = '<span style="color:#ccc;">-</span>';
            if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
                $tmpItem = new self();
                $tmpItem->fields = $row;
                $linkedDisplay = $tmpItem->getLinkedItemDisplay();
            }

            echo "<tr class='tab_bg_1'>";
            echo "<td><a href='" . static::getFormURLWithID($row['id']) . "'>" .
                htmlescape($row['name']) . "</a></td>";
            echo "<td>" . $linkedDisplay . "</td>";
            echo "<td><span class='sprint-badge {$statusClass}'>" .
                ($statuses[$row['status']] ?? $row['status']) . "</span></td>";
            echo "<td>" . ($priorities[$row['priority']] ?? $row['priority']) . "</td>";
            echo "<td class='center'>" . (int)$row['story_points'] . "</td>";
            echo "<td class='center'>" . (int)($row['capacity'] ?? 0) . "%</td>";
            echo "<td>" . (((int)$row['users_id'] > 0) ? getUserName($row['users_id']) :
                '<span style="color:#999;">' . __('Unassigned', 'sprint') . '</span>') . "</td>";
            if ($canedit) {
                $isOwn = (int)$row['users_id'] === (int)Session::getLoginUserID();
                $canEditRow = self::canUpdate() || (self::hasOnlyOwnRight(UPDATE) && $isOwn);
                $canDeleteRow = Session::haveRight(self::$rightname, PURGE)
                    || (self::hasOnlyOwnRight(PURGE) && $isOwn);

                echo "<td class='center' style='white-space:nowrap;'>";
                if ($canEditRow) {
                    echo "<a href='" . static::getFormURLWithID($row['id']) .
                        "' class='btn btn-sm btn-outline-primary' title='" . __('Edit') . "'>" .
                        "<i class='fas fa-edit'></i></a> ";
                }
                if ($canDeleteRow) {
                    echo "<form method='post' action='" . static::getFormURL() . "' style='display:inline;'>";
                    echo Html::hidden('id', ['value' => $row['id']]);
                    echo Html::submit(__('Delete'), [
                        'name'    => 'purge',
                        'class'   => 'btn btn-sm btn-outline-danger',
                        'confirm' => __('Confirm deletion?'),
                    ]);
                    Html::closeForm();
                }
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</table></div>";
    }

    /**
     * Process input before adding: resolve linked item name + validate capacity
     */
    public function prepareInputForAdd($input)
    {
        $input = self::sanitizeInput($input);
        $input = $this->resolveLinkedItem($input);
        if (!$this->validateCapacity($input)) {
            return false;
        }
        return parent::prepareInputForAdd($input);
    }

    /**
     * Process input before updating: resolve linked item name + validate capacity
     */
    public function prepareInputForUpdate($input)
    {
        $input = self::sanitizeInput($input);
        $input = $this->resolveLinkedItem($input);
        if (!$this->validateCapacity($input, (int)($input['id'] ?? 0))) {
            return false;
        }
        return parent::prepareInputForUpdate($input);
    }

    /**
     * Whitelist allowed fields and validate itemtype
     */
    private static function sanitizeInput(array $input): array
    {
        // Validate itemtype against allowlist
        $allowedTypes = array_keys(self::getLinkedItemTypes());
        if (isset($input['itemtype']) && !in_array($input['itemtype'], $allowedTypes, true)) {
            $input['itemtype'] = '';
            $input['items_id'] = 0;
        }

        // Cast numeric fields
        if (isset($input['items_id']))    $input['items_id']    = (int)$input['items_id'];
        if (isset($input['users_id']))    $input['users_id']    = (int)$input['users_id'];
        if (isset($input['story_points'])) $input['story_points'] = max(0, (int)$input['story_points']);
        if (isset($input['capacity']))    $input['capacity']    = max(0, min(100, (int)$input['capacity']));
        if (isset($input['priority']))    $input['priority']    = max(1, min(5, (int)$input['priority']));
        if (isset($input['plugin_sprint_sprints_id'])) $input['plugin_sprint_sprints_id'] = (int)$input['plugin_sprint_sprints_id'];

        return $input;
    }

    /**
     * Check that assigning capacity to an owner does not exceed their available capacity.
     *
     * @param array $input    The input data
     * @param int   $excludeId  Item ID to exclude from calculation (for updates)
     * @return bool
     */
    private function validateCapacity(array $input, int $excludeId = 0): bool
    {
        $capacity = (int)($input['capacity'] ?? 0);
        $userId   = (int)($input['users_id'] ?? 0);
        $sprintId = (int)($input['plugin_sprint_sprints_id'] ?? $this->fields['plugin_sprint_sprints_id'] ?? 0);

        if ($capacity <= 0 || $userId <= 0 || $sprintId <= 0) {
            return true;
        }

        // Get member's total capacity
        $member = new SprintMember();
        $members = $member->find([
            'plugin_sprint_sprints_id' => $sprintId,
            'users_id'                 => $userId,
        ]);

        if (count($members) === 0) {
            return true;
        }

        $memberData    = reset($members);
        $totalCapacity = (int)$memberData['capacity_percent'];

        // Sum capacity already used by this member's other items
        $si = new self();
        $criteria = [
            'plugin_sprint_sprints_id' => $sprintId,
            'users_id'                 => $userId,
        ];
        if ($excludeId > 0) {
            $criteria['NOT'] = ['id' => $excludeId];
        }
        $existingItems = $si->find($criteria);
        $usedCapacity = 0;
        foreach ($existingItems as $row) {
            $usedCapacity += (int)($row['capacity'] ?? 0);
        }

        $remaining = $totalCapacity - $usedCapacity;

        if ($capacity > $remaining) {
            $userName = getUserName($userId);
            Session::addMessageAfterRedirect(
                sprintf(
                    __('%s has only %d%% capacity remaining (total: %d%%, used: %d%%). Cannot assign %d%%.', 'sprint'),
                    $userName,
                    $remaining,
                    $totalCapacity,
                    $usedCapacity,
                    $capacity
                ),
                false,
                ERROR
            );
            return false;
        }

        return true;
    }

    /**
     * If a linked item type is selected, set the itemtype/items_id
     * and auto-fill the name from the linked item
     */
    private function resolveLinkedItem(array $input): array
    {
        $itemtype = $input['itemtype'] ?? '';

        if ($itemtype === 'Ticket' && !empty($input['_linked_ticket'])) {
            $input['items_id'] = (int)$input['_linked_ticket'];
            $linked = new Ticket();
            if ($linked->getFromDB($input['items_id']) && empty($input['name'])) {
                $input['name'] = $linked->fields['name'];
            }
        } elseif ($itemtype === 'Change' && !empty($input['_linked_change'])) {
            $input['items_id'] = (int)$input['_linked_change'];
            $linked = new Change();
            if ($linked->getFromDB($input['items_id']) && empty($input['name'])) {
                $input['name'] = $linked->fields['name'];
            }
        } elseif ($itemtype === 'ProjectTask' && !empty($input['_linked_projecttask'])) {
            $input['items_id'] = (int)$input['_linked_projecttask'];
            $linked = new ProjectTask();
            if ($linked->getFromDB($input['items_id']) && empty($input['name'])) {
                $input['name'] = $linked->fields['name'];
            }
        }

        return $input;
    }

    /**
     * Show the item edit form
     */
    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $sprintId = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
        $memberOptions = SprintMember::getSprintMemberOptions($sprintId);
        $isNew = !$this->getID() || $this->isNewItem();

        $this->showFormHeader($options);

        // Name
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Name') . "</td>";
        echo "<td>" . Html::input('name', ['value' => $this->fields['name'] ?? '', 'size' => 40]) . "</td>";
        echo "<td>" . __('Status') . "</td><td>";
        Dropdown::showFromArray('status', self::getAllStatuses(), [
            'value' => $this->fields['status'] ?? self::STATUS_TODO,
        ]);
        echo "</td></tr>";

        // Linked item
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Linked item type', 'sprint') . "</td><td>";
        $currentType = $this->fields['itemtype'] ?? '';
        Dropdown::showFromArray('itemtype', self::getLinkedItemTypes(), [
            'value' => $currentType,
        ]);
        echo "</td>";
        echo "<td>" . __('Linked item', 'sprint') . "</td><td>";
        if (!empty($currentType) && (int)($this->fields['items_id'] ?? 0) > 0) {
            echo $this->getLinkedItemDisplay();
            echo Html::hidden('items_id', ['value' => $this->fields['items_id']]);
        } else {
            echo "<span style='color:#999;'>" . __('None', 'sprint') . "</span>";
            echo Html::hidden('items_id', ['value' => 0]);
        }
        echo "</td></tr>";

        // Points + Priority
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Story Points', 'sprint') . "</td><td>";
        Dropdown::showNumber('story_points', [
            'value' => $this->fields['story_points'] ?? 0, 'min' => 0, 'max' => 100,
        ]);
        echo "</td><td>" . __('Priority') . "</td><td>";
        Dropdown::showFromArray('priority', [
            1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
            4 => __('High'), 5 => __('Very high'),
        ], ['value' => $this->fields['priority'] ?? 3]);
        echo "</td></tr>";

        // Capacity + Owner
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Capacity (%)', 'sprint') . "</td><td>";
        Dropdown::showNumber('capacity', [
            'value' => $this->fields['capacity'] ?? 0, 'min' => 0, 'max' => 100, 'step' => 5,
        ]);
        echo "</td><td>" . __('Owner', 'sprint') . "</td><td>";
        Dropdown::showFromArray('users_id', $memberOptions, [
            'value' => $this->fields['users_id'] ?? 0,
        ]);
        echo "</td></tr>";

        // Sprint
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Sprint') . "</td><td>";
        Sprint::dropdown(['name' => 'plugin_sprint_sprints_id', 'value' => $sprintId]);
        echo "</td><td colspan='2'></td></tr>";

        // Description
        echo "<tr class='tab_bg_1'><td>" . __('Description') . "</td>";
        echo "<td colspan='3'><textarea name='description' rows='6' cols='80'>" .
            htmlescape($this->fields['description'] ?? '') . "</textarea></td></tr>";

        $this->showFormButtons($options);

        return true;
    }
}
