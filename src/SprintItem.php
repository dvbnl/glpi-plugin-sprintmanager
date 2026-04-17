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

    /**
     * Cascade purge: fastlane member rows, plus any legacy relation rows
     * in SprintTicket / SprintChange / SprintProjectTask that pointed at
     * the same (sprint, linked item) pair. SprintItem is the single source
     * of truth for the reverse "Sprints" tab, but those legacy tables may
     * still contain rows from earlier add flows and must stay in sync.
     */
    public function cleanDBonPurge()
    {
        $rel = new SprintFastlaneMember();
        $rel->deleteByCriteria(['plugin_sprint_sprintitems_id' => $this->getID()], 1);

        $sprintId = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
        $itemtype = (string)($this->fields['itemtype'] ?? '');
        $itemsId  = (int)($this->fields['items_id'] ?? 0);

        if ($sprintId <= 0 || $itemsId <= 0) {
            return;
        }

        if ($itemtype === 'Ticket') {
            (new SprintTicket())->deleteByCriteria([
                'plugin_sprint_sprints_id' => $sprintId,
                'tickets_id'               => $itemsId,
            ], 1);
        } elseif ($itemtype === 'Change') {
            (new SprintChange())->deleteByCriteria([
                'plugin_sprint_sprints_id' => $sprintId,
                'changes_id'               => $itemsId,
            ], 1);
        } elseif ($itemtype === 'ProjectTask') {
            (new SprintProjectTask())->deleteByCriteria([
                'plugin_sprint_sprints_id' => $sprintId,
                'projecttasks_id'          => $itemsId,
            ], 1);
        }
    }

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

    /**
     * Declare search options for every field we want the history tracker
     * to label. GLPI's `CommonDBTM::post_updateItem()` writes one log
     * row per changed field, and looks up the field label via search
     * option id — fields without an entry here end up with `id_search_option = 0`
     * and no label, which is why capacity / story_points / status changes
     * weren't visible on the audit tab.
     */
    public function rawSearchOptions(): array
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'status',
            'name'     => __('Status'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'priority',
            'name'     => __('Priority'),
            'datatype' => 'integer',
        ];
        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'story_points',
            'name'     => __('Story Points', 'sprint'),
            'datatype' => 'integer',
        ];
        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'capacity',
            'name'     => __('Capacity (%)', 'sprint'),
            'datatype' => 'integer',
        ];
        $tab[] = [
            'id'       => 7,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'linkfield' => 'users_id',
            'name'     => __('Owner', 'sprint'),
            'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id'       => 8,
            'table'    => $this->getTable(),
            'field'    => 'note',
            'name'     => __('Note', 'sprint'),
            'datatype' => 'text',
        ];
        $tab[] = [
            'id'       => 9,
            'table'    => $this->getTable(),
            'field'    => 'is_fastlane',
            'name'     => __('Is Fastlane', 'sprint'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => 10,
            'table'    => $this->getTable(),
            'field'    => 'itemtype',
            'name'     => __('Linked item type', 'sprint'),
            'datatype' => 'string',
        ];

        return $tab;
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
            // Fastlane items live in their own tab, so exclude them here.
            $count = countElementsInTable(
                self::getTable(),
                [
                    'plugin_sprint_sprints_id' => $item->getID(),
                    'is_fastlane'              => 0,
                ]
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
     * Get the display name for a linked GLPI item.
     *
     * For ProjectTask, the parent project name is appended in parentheses
     * because task names are often duplicated across projects.
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

        // For project tasks, append the parent project name so users can
        // distinguish tasks with identical names across projects.
        $suffix = '';
        if ($itemtype === 'ProjectTask') {
            $projectId = (int)($linkedItem->fields['projects_id'] ?? 0);
            if ($projectId > 0) {
                $project = new \Project();
                if ($project->getFromDB($projectId)) {
                    $projectName = htmlescape($project->fields['name'] ?? '');
                    $suffix = " <span style='color:#6c757d;'>({$projectName})</span>";
                }
            }
        }

        return "<a href='{$url}'><i class='{$icon}'></i> {$name}</a>{$suffix}";
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
            Dropdown::showFromArray('capacity', SprintMember::getCapacityChoices(), [
                'value' => 0,
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

        // List existing items (fastlane items are excluded — they live in
        // the dedicated Fastlane tab).
        $item  = new self();
        $items = $item->find(
            [
                'plugin_sprint_sprints_id' => $ID,
                'is_fastlane'              => 0,
            ],
            ['sort_order ASC', 'priority DESC']
        );

        $statuses   = self::getAllStatuses();
        $priorities = [
            1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
            4 => __('High'), 5 => __('Very high'),
        ];

        echo "<div class='center'>";

        // Filter bar for the items list (handled by window.SprintFilter).
        self::renderFilterBar('sprint-items-list-table', [
            'statuses' => $statuses,
            'owners'   => SprintMember::getSprintMemberOptions($ID),
        ]);

        echo "<table class='tab_cadre_fixe sprint-items-list-table'>";
        echo "<tr class='tab_bg_2'>";
        $sc = self::sortClickAttr('sprint-items-list-table');
        echo "<th class='sprint-sortable' data-sort-type='name' style='cursor:pointer;' {$sc}>" . __('Name') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th class='sprint-sortable' data-sort-type='status' style='cursor:pointer;' {$sc}>" . __('Status') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='priority' style='cursor:pointer;' {$sc}>" . __('Priority') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='story_points' style='cursor:pointer;' {$sc}>" . __('Story Points', 'sprint') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='capacity' style='cursor:pointer;' {$sc}>" . __('Capacity (%)', 'sprint') . " <i class='fas fa-sort text-muted'></i></th>";
        echo "<th class='sprint-sortable' data-sort-type='owner' style='cursor:pointer;' {$sc}>" . __('Owner', 'sprint') . " <i class='fas fa-sort text-muted'></i></th>";
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
            $statusLabel = $statuses[$row['status']] ?? $row['status'];
            $ownerName   = ((int)$row['users_id'] > 0) ? getUserName((int)$row['users_id']) : '';

            // Build linked item display
            $linkedDisplay = '<span style="color:#ccc;">-</span>';
            if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
                $tmpItem = new self();
                $tmpItem->fields = $row;
                $linkedDisplay = $tmpItem->getLinkedItemDisplay();
            }

            $dataAttrs = self::buildRowDataAttrs($row, $statusLabel, $ownerName);

            echo "<tr class='tab_bg_1 sprint-row sprint-filterable-row' {$dataAttrs}>";
            echo "<td class='sprint-cell-name'><a href='" . static::getFormURLWithID($row['id']) . "'>" .
                htmlescape($row['name']) . "</a></td>";
            echo "<td>" . $linkedDisplay . "</td>";
            echo "<td class='sprint-cell-status'><span class='sprint-badge {$statusClass}'>" .
                $statusLabel . "</span></td>";
            echo "<td class='sprint-cell-priority'>" . ($priorities[$row['priority']] ?? $row['priority']) . "</td>";
            echo "<td class='center sprint-cell-story-points'>" . (int)$row['story_points'] . "</td>";
            echo "<td class='center sprint-cell-capacity'>" . (int)($row['capacity'] ?? 0) . "%</td>";
            echo "<td class='sprint-cell-owner'>" . (((int)$row['users_id'] > 0) ? getUserName($row['users_id']) :
                '<span style="color:#999;">' . __('Unassigned', 'sprint') . '</span>') . "</td>";
            if ($canedit) {
                $isOwn = (int)$row['users_id'] === (int)Session::getLoginUserID();
                $canEditRow = self::canUpdate() || (self::hasOnlyOwnRight(UPDATE) && $isOwn);
                $canDeleteRow = Session::haveRight(self::$rightname, PURGE)
                    || (self::hasOnlyOwnRight(PURGE) && $isOwn);

                echo "<td class='center' style='white-space:nowrap;'>";
                if ($canEditRow) {
                    // Quick-edit covers everything that used to live on the
                    // full item form — the explicit "Edit" link was redundant
                    // and has been removed.
                    echo "<button type='button' class='btn btn-sm btn-outline-secondary sprint-quick-edit-btn me-1' "
                        . "title='" . __('Quick edit', 'sprint') . "' data-item-id='" . (int)$row['id'] . "'>"
                        . "<i class='fas fa-pen'></i></button> ";
                    // Back to backlog button
                    echo "<form method='post' action='" . Backlog::getFormURL() . "' style='display:inline;'>";
                    echo Html::hidden('id', ['value' => $row['id']]);
                    echo "<button type='submit' name='back_to_backlog' class='btn btn-sm btn-outline-warning' "
                        . "title='" . __('Back to backlog', 'sprint') . "' "
                        . "onclick=\"return confirm('" . __('Move this item back to the backlog?', 'sprint') . "');\">"
                        . "<i class='fas fa-undo'></i></button>";
                    Html::closeForm();
                    echo " ";
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

        if ($canedit) {
            self::renderQuickEditUI($ID);
        }
    }

    /**
     * Build a set of data-* attributes describing a sprint item row. Used
     * by the quick-edit JS to pre-populate the modal without an extra
     * round-trip.
     */
    private static function buildRowDataAttrs(array $row, string $statusLabel, string $ownerName): string
    {
        $attrs = [
            'data-item-id'           => (int)$row['id'],
            'data-item-name'         => (string)$row['name'],
            'data-item-status'       => (string)$row['status'],
            'data-item-status-label' => $statusLabel,
            'data-item-priority'     => (int)($row['priority'] ?? 3),
            'data-users-id'          => (int)($row['users_id'] ?? 0),
            'data-owner-name'        => $ownerName,
            'data-story-points'      => (int)($row['story_points'] ?? 0),
            'data-capacity'          => (int)($row['capacity'] ?? 0),
            'data-is-fastlane'       => (int)($row['is_fastlane'] ?? 0),
            'data-note'              => (string)($row['note'] ?? ''),
        ];

        $parts = [];
        foreach ($attrs as $k => $v) {
            $parts[] = $k . '="' . htmlescape((string)$v) . '"';
        }
        return implode(' ', $parts);
    }

    /**
     * Render a shared filter bar for any sprint item table: text search
     * + single-select status + single-select owner + reset. Emits an
     * inline <script> right after the markup so the wiring happens
     * immediately, regardless of tab-load timing or jQuery delegation.
     *
     * @param string $tableClass  CSS class on the target <table>.
     * @param array<string,mixed> $options {
     *     statuses: [key=>label] for the status dropdown,
     *     owners:   [uid=>name]  for the owner dropdown,
     * }
     */
    public static function renderFilterBar(string $tableClass, array $options): void
    {
        $statuses = $options['statuses'] ?? [];
        $owners   = $options['owners']   ?? [];
        $barId    = 'sprint-filter-' . mt_rand();
        $tc       = htmlescape($tableClass);

        echo "<div id='{$barId}' class='sprint-filter-bar d-flex flex-wrap align-items-center gap-2 p-2 mb-2' "
            . "data-target='{$tc}' style='background:#f1f3f5;border-radius:6px;'>";
        echo "<div class='d-flex align-items-center gap-1 text-muted small'>"
            . "<i class='fas fa-filter'></i><span>" . __('Filter', 'sprint') . "</span></div>";

        // Handlers pass `this` so the JS walks up to the enclosing
        // `.sprint-filter-bar` and reads `data-target`. Robust against
        // id regeneration, duplicates, and any kind of DOM reshuffling.
        echo "<input type='search' class='form-control form-control-sm sf-text' "
            . "style='max-width:220px;' placeholder='" . __('Search name...', 'sprint') . "' "
            . "oninput=\"sprintFilterApply(this)\" "
            . "onkeydown=\"if(event.key==='Enter'){event.preventDefault();sprintFilterApply(this);}\">";

        if (!empty($statuses)) {
            echo "<select class='form-select form-select-sm sf-status' style='max-width:180px;' "
                . "onchange=\"sprintFilterApply(this)\">";
            echo "<option value=''>" . __('All statuses', 'sprint') . "</option>";
            foreach ($statuses as $key => $label) {
                echo "<option value='" . htmlescape((string)$key) . "'>" . htmlescape((string)$label) . "</option>";
            }
            echo "</select>";
        }

        if (!empty($owners)) {
            echo "<select class='form-select form-select-sm sf-owner' style='max-width:200px;' "
                . "onchange=\"sprintFilterApply(this)\">";
            echo "<option value=''>" . __('All owners', 'sprint') . "</option>";
            foreach ($owners as $uidOpt => $name) {
                if ((int)$uidOpt === 0) continue;
                echo "<option value='" . (int)$uidOpt . "'>" . htmlescape((string)$name) . "</option>";
            }
            echo "</select>";
        }

        echo "<button type='button' class='btn btn-sm btn-outline-secondary sf-reset' "
            . "onclick=\"sprintFilterReset(this)\">"
            . "<i class='fas fa-times me-1'></i>" . __('Reset', 'sprint') . "</button>";
        echo "</div>";
    }

    /**
     * Build an `onclick` attribute fragment for a sortable <th>.
     * The JS walks from the <th> to the enclosing <table>, so no table
     * class argument is needed.
     */
    public static function sortClickAttr(string $tableClass = ''): string
    {
        return 'onclick="sprintSortClick(this)"';
    }

    /**
     * Render the shared quick-edit modal + JS bindings for sprint item
     * rows. Designed to be called on any page that lists sprint items
     * with the `.sprint-quick-edit-btn` button and rows carrying the data
     * attributes produced by {@see buildRowDataAttrs()}.
     */
    public static function renderQuickEditUI(int $sprintId = 0): void
    {
        $statuses       = self::getAllStatuses();
        $priorities     = [
            1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
            4 => __('High'), 5 => __('Very high'),
        ];
        $capacityChoices = SprintMember::getCapacityChoices();
        $memberOptions  = $sprintId > 0 ? SprintMember::getSprintMemberOptions($sprintId) : [];

        // Capacity lock: if the plugin is configured to restrict capacity
        // edits to the Scrum Master, disable the capacity control in the
        // modal for other users (regular items only — fastlane always free).
        $capacityLocked = Config::isScrumMasterOnlyCapacity()
            && $sprintId > 0
            && !Config::isCurrentUserScrumMaster($sprintId);
        $capacityLockedJs = $capacityLocked ? 'true' : 'false';

        $cfgRoot = 'CFG_GLPI.root_doc';

        echo "<div class='modal fade' id='sprint-quickedit-modal' tabindex='-1' aria-hidden='true'>";
        echo "<div class='modal-dialog modal-dialog-centered modal-lg'>";
        echo "<div class='modal-content'>";
        echo "<div class='modal-header'>";
        echo "<h5 class='modal-title'><i class='fas fa-pen me-1'></i>" .
            __('Quick edit', 'sprint') . ": <span class='sprint-qe-title'></span></h5>";
        echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>";
        echo "</div>";
        echo "<div class='modal-body'>";
        echo "<div class='alert alert-danger sprint-qe-error' style='display:none;white-space:pre-line;'></div>";
        echo "<input type='hidden' name='id' value=''>";

        echo "<div class='mb-3'><label class='form-label'>" . __('Name') . "</label>";
        echo "<input type='text' name='name' class='form-control' value=''></div>";

        echo "<div class='row g-3'>";
        echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __('Status') . "</label>";
        echo "<select name='status' class='form-select'>";
        foreach ($statuses as $k => $v) {
            echo "<option value='" . htmlescape($k) . "'>" . htmlescape($v) . "</option>";
        }
        echo "</select></div>";
        echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __('Priority') . "</label>";
        echo "<select name='priority' class='form-select'>";
        foreach ($priorities as $k => $v) {
            echo "<option value='{$k}'>" . htmlescape($v) . "</option>";
        }
        echo "</select></div>";
        echo "</div>";

        echo "<div class='row g-3'>";
        echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __('Owner', 'sprint') . "</label>";
        echo "<select name='users_id' class='form-select'>";
        foreach ($memberOptions as $uid => $uname) {
            echo "<option value='" . (int)$uid . "'>" . htmlescape((string)$uname) . "</option>";
        }
        echo "</select></div>";
        echo "<div class='col-md-3 mb-3 sprint-qe-story-points'><label class='form-label'>" . __('Story Points', 'sprint') . "</label>";
        echo "<input type='number' name='story_points' class='form-control' min='0' step='1' value='0'></div>";
        echo "<div class='col-md-3 mb-3 sprint-qe-capacity'><label class='form-label'>" . __('Capacity (%)', 'sprint') . "</label>";
        echo "<select name='capacity' class='form-select'>";
        foreach ($capacityChoices as $val => $label) {
            echo "<option value='" . (int)$val . "'>" . htmlescape((string)$label) . "</option>";
        }
        echo "</select></div>";
        echo "</div>";

        echo "<div class='mb-3'><label class='form-label'>" . __('Note', 'sprint') . "</label>";
        echo "<textarea name='note' class='form-control' rows='8' style='min-height:180px;'></textarea></div>";

        echo "</div>";
        echo "<div class='modal-footer'>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . __('Cancel') . "</button>";
        echo "<button type='button' class='btn btn-primary sprint-qe-save'><i class='fas fa-save me-1'></i> " .
            __('Save') . "</button>";
        echo "</div>";
        echo "</div></div></div>";

        $labelUnassigned = addslashes(__('Unassigned', 'sprint'));

        echo <<<JS
<script>
$(function() {
    if (window.__sprintQuickEditBound) { return; }
    window.__sprintQuickEditBound = true;

    var statusColors = {
        'todo': '#6c757d',
        'in_progress': '#0d6efd',
        'review': '#6f42c1',
        'done': '#198754',
        'blocked': '#dc3545'
    };

    var \$modal = \$('#sprint-quickedit-modal');

    \$(document).on('click', '.sprint-quick-edit-btn', function() {
        var \$row       = \$(this).closest('tr');
        var itemId     = \$row.data('item-id');
        var itemName   = \$row.data('item-name') || '';
        var status     = \$row.data('item-status') || '';
        var priority   = \$row.data('item-priority') || 3;
        var usersId    = \$row.data('users-id') || 0;
        var points     = \$row.data('story-points') || 0;
        var capacity   = \$row.data('capacity') || 0;
        var note       = \$row.data('note') || '';
        var isFastlane = parseInt(\$row.data('is-fastlane'), 10) === 1;

        \$modal.find('.sprint-qe-title').text(itemName);
        \$modal.find('input[name=id]').val(itemId);
        \$modal.find('input[name=name]').val(itemName);
        \$modal.find('select[name=status]').val(String(status));
        \$modal.find('select[name=priority]').val(String(priority));
        \$modal.find('select[name=users_id]').val(String(usersId));
        \$modal.find('input[name=story_points]').val(points);
        \$modal.find('select[name=capacity]').val(String(capacity));
        \$modal.find('textarea[name=note]').val(note);
        \$modal.find('.sprint-qe-error').hide().text('');
        \$modal.find('.sprint-qe-story-points, .sprint-qe-capacity').toggle(!isFastlane);

        // Lock capacity for non-scrum-master users when the plugin setting
        // requires it — fastlane items stay editable.
        var capacityLocked = {$capacityLockedJs};
        \$modal.find('select[name=capacity]').prop('disabled', capacityLocked && !isFastlane);

        var m = new bootstrap.Modal(\$modal[0]);
        m.show();
        \$modal.data('bs-instance', m);
    });

    \$(document).on('click', '.sprint-qe-save', function() {
        var \$btn = \$(this);
        var id = \$modal.find('input[name=id]').val();
        \$btn.prop('disabled', true);
        \$modal.find('.sprint-qe-error').hide().text('');

        \$.ajax({
            url: {$cfgRoot} + '/plugins/sprint/ajax/csrftoken.php',
            type: 'GET', dataType: 'json', cache: false
        }).then(function(tokResp) {
            return \$.ajax({
                url: {$cfgRoot} + '/plugins/sprint/ajax/updateitemquick.php',
                type: 'POST', dataType: 'json',
                data: {
                    id: id,
                    name: \$modal.find('input[name=name]').val(),
                    status: \$modal.find('select[name=status]').val(),
                    priority: \$modal.find('select[name=priority]').val(),
                    users_id: \$modal.find('select[name=users_id]').val(),
                    story_points: \$modal.find('input[name=story_points]').val(),
                    capacity: \$modal.find('select[name=capacity]').val(),
                    note: \$modal.find('textarea[name=note]').val(),
                    _glpi_csrf_token: tokResp && tokResp.token ? tokResp.token : ''
                }
            });
        }).done(function(resp) {
            if (resp && resp.success) {
                var \$row = \$('tr.sprint-row[data-item-id="' + id + '"], tr.sprint-review-row[data-item-id="' + id + '"]');
                var statusSelect   = \$modal.find('select[name=status]');
                var statusLabel    = statusSelect.find('option:selected').text() || '';
                var ownerSelect    = \$modal.find('select[name=users_id]');
                var ownerLabel     = ownerSelect.find('option:selected').text() || '';
                var prioritySelect = \$modal.find('select[name=priority]');
                var priorityLabel  = prioritySelect.find('option:selected').text() || '';
                var capacitySelect = \$modal.find('select[name=capacity]');
                var capacityLabel  = capacitySelect.find('option:selected').text() || (resp.capacity + '%');
                var statusColor    = statusColors[resp.status] || '#6c757d';
                var ownerId        = parseInt(resp.users_id, 10) || 0;

                // Sync row data attributes so filters/sorts and subsequent
                // edits all start from fresh values.
                \$row.attr('data-item-name', resp.name).data('item-name', resp.name);
                \$row.attr('data-item-status', resp.status).data('item-status', resp.status);
                \$row.attr('data-item-status-label', statusLabel).data('item-status-label', statusLabel);
                \$row.attr('data-item-priority', resp.priority).data('item-priority', resp.priority);
                \$row.attr('data-users-id', resp.users_id).data('users-id', resp.users_id);
                \$row.attr('data-owner-name', ownerLabel).data('owner-name', ownerLabel);
                \$row.attr('data-story-points', resp.story_points).data('story-points', resp.story_points);
                \$row.attr('data-capacity', resp.capacity).data('capacity', resp.capacity);
                \$row.attr('data-note', resp.note).data('note', resp.note);

                // === Refresh visible cells on PHP-rendered list/dashboard rows ===
                var \$listRow = \$row.filter('.sprint-row');
                if (\$listRow.length) {
                    // Name link text
                    \$listRow.find('.sprint-cell-name a').text(resp.name);
                    // Status badge
                    \$listRow.find('.sprint-cell-status .sprint-badge')
                        .removeClass(function(i,c){ return (c.match(/sprint-status-\\S+/g) || []).join(' '); })
                        .addClass('sprint-status-' + String(resp.status).replace(/_/g, '-'))
                        .text(statusLabel)
                        .css('background-color', statusColor);
                    // Priority label
                    \$listRow.find('.sprint-cell-priority').text(priorityLabel);
                    // Owner name (or "Unassigned" placeholder)
                    var \$ownerListCell = \$listRow.find('.sprint-cell-owner');
                    if (\$ownerListCell.length) {
                        if (ownerId > 0 && ownerLabel) {
                            \$ownerListCell.text(ownerLabel);
                        } else {
                            \$ownerListCell.html('<span style="color:#999;">{$labelUnassigned}</span>');
                        }
                    }
                    // Story points
                    \$listRow.find('.sprint-cell-story-points').text(resp.story_points);
                    // Capacity — retain "%" suffix
                    \$listRow.find('.sprint-cell-capacity').text(resp.capacity + '%');
                }

                // === Refresh meeting-review read-only cells ===
                \$row.find('.sprint-review-status')
                    .text(statusLabel)
                    .css('background-color', statusColor);
                var \$ownerCell = \$row.find('.sprint-review-owner');
                if (\$ownerCell.length) {
                    if (ownerId > 0 && ownerLabel) {
                        \$ownerCell.html('<i class="fas fa-user text-muted me-1"></i>' + \$('<div>').text(ownerLabel).html());
                    } else {
                        \$ownerCell.html('<span class="text-muted fst-italic">{$labelUnassigned}</span>');
                    }
                }
                \$row.find('.sprint-note-display').text(resp.note || '');

                // Keep the meeting name cell's link text in sync too.
                \$row.find('td a').filter(function() {
                    return \$(this).attr('href') && \$(this).attr('href').indexOf('sprintitem.form.php') !== -1;
                }).text(resp.name);

                var m = \$modal.data('bs-instance');
                if (m) { m.hide(); }
            } else {
                \$modal.find('.sprint-qe-error').text(resp && resp.message ? resp.message : 'Save failed').show();
            }
        }).fail(function() {
            \$modal.find('.sprint-qe-error').text('Network error').show();
        }).always(function() {
            \$btn.prop('disabled', false);
        });
    });
});
</script>
JS;
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
        if (!self::validateNoDuplicateLink($input)) {
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

        // Enforce plugin setting: only the sprint's Scrum Master may edit
        // capacity on regular (non-fastlane) sprint items when the guard is
        // enabled. Silently drop the field for others so validation of the
        // rest of the update still goes through.
        $isFastlane = (int)($this->fields['is_fastlane'] ?? 0) === 1;
        if (
            !$isFastlane
            && array_key_exists('capacity', $input)
            && Config::isScrumMasterOnlyCapacity()
        ) {
            $sprintId = (int)($this->fields['plugin_sprint_sprints_id'] ?? $input['plugin_sprint_sprints_id'] ?? 0);
            if (!Config::isCurrentUserScrumMaster($sprintId)) {
                unset($input['capacity']);
            }
        }

        if (!$this->validateCapacity($input, (int)($input['id'] ?? 0))) {
            return false;
        }
        if (!self::validateNoDuplicateLink($input, (int)($input['id'] ?? 0))) {
            return false;
        }
        return parent::prepareInputForUpdate($input);
    }

    /**
     * Reject creating a second SprintItem row for the same
     * (sprint, itemtype, items_id) triple. Manual items (empty itemtype
     * or items_id == 0) and backlog rows (sprint id == 0) are skipped —
     * Backlog has its own de-duplication and manual items can legitimately
     * repeat.
     */
    private static function validateNoDuplicateLink(array $input, int $excludeId = 0): bool
    {
        $itemtype = (string)($input['itemtype'] ?? '');
        $itemsId  = (int)($input['items_id'] ?? 0);
        $sprintId = (int)($input['plugin_sprint_sprints_id'] ?? 0);

        if ($itemtype === '' || $itemsId <= 0 || $sprintId <= 0) {
            return true;
        }

        if (!self::isLinkedItemInSprint($sprintId, $itemtype, $itemsId, $excludeId)) {
            return true;
        }

        $typeLabels = self::getLinkedItemTypes();
        $typeLabel  = $typeLabels[$itemtype] ?? $itemtype;
        Session::addMessageAfterRedirect(
            sprintf(
                __('This %1$s (#%2$d) is already linked to this sprint.', 'sprint'),
                $typeLabel,
                $itemsId
            ),
            false,
            ERROR
        );
        return false;
    }

    /**
     * Check whether a linked item (Ticket/Change/ProjectTask) is already
     * attached to a given sprint via a SprintItem row.
     */
    public static function isLinkedItemInSprint(
        int $sprintId,
        string $itemtype,
        int $itemsId,
        int $excludeId = 0
    ): bool {
        if ($sprintId <= 0 || $itemtype === '' || $itemsId <= 0) {
            return false;
        }

        $criteria = [
            'plugin_sprint_sprints_id' => $sprintId,
            'itemtype'                 => $itemtype,
            'items_id'                 => $itemsId,
        ];
        if ($excludeId > 0) {
            $criteria['NOT'] = ['id' => $excludeId];
        }

        return countElementsInTable(self::getTable(), $criteria) > 0;
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
        if (isset($input['is_fastlane']))  $input['is_fastlane'] = (int)(bool)$input['is_fastlane'];

        return $input;
    }

    /**
     * Check that assigning capacity to an owner does not exceed their
     * available capacity. Defers to SprintMember::checkCapacityForUser so
     * regular items and fastlane allocations are counted together.
     *
     * @param array $input    The input data
     * @param int   $excludeId  Item ID to exclude from calculation (for updates)
     * @return bool
     */
    private function validateCapacity(array $input, int $excludeId = 0): bool
    {
        // Fastlane items distribute capacity through the
        // SprintFastlaneMember junction, so the regular per-row capacity
        // field is irrelevant for them.
        $isFastlane = (int)($input['is_fastlane'] ?? $this->fields['is_fastlane'] ?? 0) === 1;
        if ($isFastlane) {
            return true;
        }

        $capacity = (int)($input['capacity'] ?? 0);
        $userId   = (int)($input['users_id'] ?? 0);
        $sprintId = (int)($input['plugin_sprint_sprints_id'] ?? $this->fields['plugin_sprint_sprints_id'] ?? 0);

        return SprintMember::checkCapacityForUser(
            $sprintId,
            $userId,
            $capacity,
            $excludeId,
            0
        );
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
        $isFastlane = (int)($this->fields['is_fastlane'] ?? 0) === 1;

        // "Back to Sprint" button linking to the parent sprint's Items tab
        if ($sprintId > 0) {
            $sprintUrl = Sprint::getFormURLWithID($sprintId) . '&forcetab=' . urlencode('GlpiPlugin\\Sprint\\SprintItem$1');
            echo "<div style='margin-bottom:10px;'>";
            echo "<a href='$sprintUrl' class='btn btn-outline-secondary'>";
            echo "<i class='fas fa-arrow-left me-1'></i> " . __('Back to Sprint', 'sprint');
            echo "</a></div>";
        }

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

        // Fastlane flag — paired hidden input ensures unchecking actually
        // submits 0, since unchecked HTML checkboxes are simply omitted.
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Is Fastlane', 'sprint') . "</td><td colspan='3'>";
        echo "<input type='hidden' name='is_fastlane' value='0'>";
        echo "<label style='display:inline-flex;align-items:center;gap:6px;'>";
        echo "<input type='checkbox' name='is_fastlane' value='1'" . ($isFastlane ? ' checked' : '') . ">";
        echo "<span class='text-muted'>" . __('Mark this item as fastlane (managed via the Fastlane tab on the sprint).', 'sprint') . "</span>";
        echo "</label>";
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

        // Priority (+ Story Points for non-fastlane items only: story points
        // on fastlane items don't count towards sprint velocity).
        echo "<tr class='tab_bg_1'>";
        if ($isFastlane) {
            echo Html::hidden('story_points', ['value' => $this->fields['story_points'] ?? 0]);
            echo "<td>" . __('Priority') . "</td><td colspan='3'>";
            Dropdown::showFromArray('priority', [
                1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
                4 => __('High'), 5 => __('Very high'),
            ], ['value' => $this->fields['priority'] ?? 3]);
            echo "</td>";
        } else {
            echo "<td>" . __('Story Points', 'sprint') . "</td><td>";
            Dropdown::showNumber('story_points', [
                'value' => $this->fields['story_points'] ?? 0, 'min' => 0, 'max' => 100,
            ]);
            echo "</td><td>" . __('Priority') . "</td><td>";
            Dropdown::showFromArray('priority', [
                1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
                4 => __('High'), 5 => __('Very high'),
            ], ['value' => $this->fields['priority'] ?? 3]);
            echo "</td>";
        }
        echo "</tr>";

        // Capacity + Owner — irrelevant for Fastlane items, where capacity
        // is distributed across multiple members via the Fastlane Members
        // tab. Hidden inputs preserve existing values.
        if ($isFastlane) {
            echo Html::hidden('capacity', ['value' => $this->fields['capacity'] ?? 0]);
            echo Html::hidden('users_id', ['value' => $this->fields['users_id'] ?? 0]);
            echo "<tr class='tab_bg_1'><td colspan='4'>";
            echo "<div class='alert alert-info' style='margin:6px 0;'>";
            echo "<i class='fas fa-bolt' style='color:#fd7e14;margin-right:6px;'></i>";
            echo __('Fastlane item: assign sprint members and capacity from the Fastlane Members tab.', 'sprint');
            echo "</div></td></tr>";
        } else {
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Capacity (%)', 'sprint') . "</td><td>";
            Dropdown::showFromArray('capacity', SprintMember::getCapacityChoices(), [
                'value' => $this->fields['capacity'] ?? 0,
            ]);
            echo "</td><td>" . __('Owner', 'sprint') . "</td><td>";
            Dropdown::showFromArray('users_id', $memberOptions, [
                'value' => $this->fields['users_id'] ?? 0,
            ]);
            echo "</td></tr>";
        }

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
