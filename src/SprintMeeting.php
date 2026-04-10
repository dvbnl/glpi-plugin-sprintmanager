<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use CommonGLPI;
use Html;
use Session;
use User;
use Dropdown;

/**
 * SprintMeeting - Meetings within a sprint (kickoff, standup, retrospective)
 */
class SprintMeeting extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_sprint';
    public $dohistory        = true;

    const TYPE_KICKOFF       = 'kickoff';
    const TYPE_STANDUP       = 'standup';
    const TYPE_REVIEW        = 'review';
    const TYPE_RETROSPECTIVE = 'retrospective';

    public function prepareInputForAdd($input)
    {
        $input = self::sanitizeMeetingInput($input);
        if (empty($input['users_id']) || (int)$input['users_id'] <= 0) {
            Session::addMessageAfterRedirect(
                __('A facilitator is required to create a meeting', 'sprint'),
                false,
                ERROR
            );
            return false;
        }
        return parent::prepareInputForAdd($input);
    }

    private static function sanitizeMeetingInput(array $input): array
    {
        if (isset($input['users_id']))                 $input['users_id']                 = (int)$input['users_id'];
        if (isset($input['plugin_sprint_sprints_id'])) $input['plugin_sprint_sprints_id'] = (int)$input['plugin_sprint_sprints_id'];
        if (isset($input['duration_minutes']))          $input['duration_minutes']          = max(5, min(480, (int)$input['duration_minutes']));
        if (isset($input['meeting_type']) && !array_key_exists($input['meeting_type'], self::getAllTypes())) {
            $input['meeting_type'] = self::TYPE_STANDUP;
        }
        return $input;
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Meeting', 'Meetings', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-users';
    }

    public static function getAllTypes(): array
    {
        return [
            self::TYPE_KICKOFF       => __('Sprint Kickoff', 'sprint'),
            self::TYPE_STANDUP       => __('Standup', 'sprint'),
            self::TYPE_REVIEW        => __('Sprint Review', 'sprint'),
            self::TYPE_RETROSPECTIVE => __('Sprint Retrospective', 'sprint'),
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
     * Show meetings list + add form for a sprint
     */
    public static function showForSprint(Sprint $sprint): void
    {
        $ID      = $sprint->getID();
        $canedit = Sprint::canUpdate();

        // === Add form ===
        if ($canedit) {
            echo "<div class='center'>";
            echo "<form method='post' action='" . static::getFormURL() . "'>";
            echo Html::hidden('plugin_sprint_sprints_id', ['value' => $ID]);

            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_2'><th colspan='6'>" .
                __('Schedule a meeting', 'sprint') . "</th></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Title') . "</td>";
            echo "<td>" . Html::input('name', ['size' => 30]) . "</td>";
            echo "<td>" . __('Type', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('meeting_type', self::getAllTypes(), [
                'value' => self::TYPE_STANDUP,
            ]);
            echo "</td>";
            echo "<td>" . __('Date') . "</td>";
            echo "<td>";
            Html::showDateTimeField('date_meeting', ['value' => date('Y-m-d H:i:s')]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Duration (min)', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showNumber('duration_minutes', [
                'value' => 15, 'min' => 5, 'max' => 240, 'step' => 5,
            ]);
            echo "</td>";
            echo "<td>" . __('Facilitator', 'sprint') . " *</td>";
            echo "<td>";
            // Facilitator is required — no empty option
            $memberOpts = SprintMember::getSprintMemberOptions($ID);
            unset($memberOpts[0]); // remove "-----" empty option
            $defaultFacilitator = array_key_exists(Session::getLoginUserID(), $memberOpts)
                ? Session::getLoginUserID()
                : (int)array_key_first($memberOpts);
            Dropdown::showFromArray('users_id', $memberOpts, [
                'value' => $defaultFacilitator,
            ]);
            echo "</td>";
            echo "<td colspan='2'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";

            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        // === List meetings ===
        $meeting  = new self();
        $meetings = $meeting->find(
            ['plugin_sprint_sprints_id' => $ID],
            ['date_meeting DESC']
        );

        $types     = self::getAllTypes();
        $typeIcons = [
            self::TYPE_KICKOFF       => 'fas fa-rocket',
            self::TYPE_STANDUP       => 'fas fa-coffee',
            self::TYPE_REVIEW        => 'fas fa-clipboard-check',
            self::TYPE_RETROSPECTIVE => 'fas fa-lightbulb',
        ];

        echo "<div class='center'><table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Title') . "</th>";
        echo "<th>" . __('Type', 'sprint') . "</th>";
        echo "<th>" . __('Date') . "</th>";
        echo "<th>" . __('Duration', 'sprint') . "</th>";
        echo "<th>" . __('Facilitator', 'sprint') . "</th>";
        echo "<th>" . __('Actions') . "</th>";
        echo "</tr>";

        if (count($meetings) === 0) {
            echo "<tr class='tab_bg_1'><td colspan='6' class='center'>" .
                __('No meetings scheduled', 'sprint') . "</td></tr>";
        }

        foreach ($meetings as $row) {
            $icon = $typeIcons[$row['meeting_type']] ?? 'fas fa-calendar';

            echo "<tr class='tab_bg_1'>";
            echo "<td><a href='" . static::getFormURLWithID($row['id']) . "'>" .
                htmlescape($row['name']) . "</a></td>";
            echo "<td><i class='{$icon}'></i> " .
                ($types[$row['meeting_type']] ?? $row['meeting_type']) . "</td>";
            echo "<td>" . Html::convDateTime($row['date_meeting']) . "</td>";
            echo "<td class='center'>" . (int)$row['duration_minutes'] . " min</td>";
            echo "<td>" . getUserName($row['users_id']) . "</td>";
            echo "<td class='center'>";
            if ($canedit) {
                echo "<a href='" . static::getFormURLWithID($row['id']) .
                    "' class='btn btn-sm btn-outline-primary'><i class='fas fa-edit'></i></a> ";
                echo "<form method='post' action='" . static::getFormURL() .
                    "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::submit(__('Delete'), [
                    'name'    => 'purge',
                    'class'   => 'btn btn-sm btn-outline-danger',
                    'confirm' => __('Confirm deletion?'),
                ]);
                Html::closeForm();
            }
            echo "</td></tr>";
        }

        echo "</table></div>";
    }

    /**
     * Show the meeting detail form with notes + embedded standup entries
     */
    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        $sprintId = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
        $memberOptions = SprintMember::getSprintMemberOptions($sprintId);
        $isExisting = ($ID > 0);

        // Build sprint items data for the review table
        $sprintItemsData = [];
        if ($isExisting && $sprintId > 0) {
            $si = new SprintItem();
            $statuses = SprintItem::getAllStatuses();
            foreach ($si->find(['plugin_sprint_sprints_id' => $sprintId], ['sort_order ASC', 'priority DESC']) as $row) {
                $linkedDisplay = '';
                $itemtype = $row['itemtype'] ?? '';
                $allowedTypes = ['Ticket', 'Change', 'ProjectTask'];
                if (!empty($itemtype) && (int)$row['items_id'] > 0 && in_array($itemtype, $allowedTypes, true) && class_exists($itemtype)) {
                    $tmpItem = new SprintItem();
                    $tmpItem->fields = $row;
                    $linkedDisplay = $tmpItem->getLinkedItemDisplay();
                }

                // Resolve parent project name for ProjectTask items
                $projectName = '';
                if ($itemtype === 'ProjectTask' && (int)$row['items_id'] > 0) {
                    $linkedPT = new \ProjectTask();
                    if ($linkedPT->getFromDB((int)$row['items_id'])) {
                        $projectId = (int)($linkedPT->fields['projects_id'] ?? 0);
                        if ($projectId > 0) {
                            $project = new \Project();
                            if ($project->getFromDB($projectId)) {
                                $projectName = $project->fields['name'] ?? '';
                            }
                        }
                    }
                }

                $sprintItemsData[] = [
                    'id'             => (int)$row['id'],
                    'name'           => $row['name'],
                    'url'            => SprintItem::getFormURLWithID((int)$row['id']),
                    'linked_display' => $linkedDisplay,
                    'status'         => $row['status'],
                    'users_id'       => (int)$row['users_id'],
                    'story_points'   => (int)$row['story_points'],
                    'note'           => $row['note'] ?? '',
                    'itemtype'       => $itemtype,
                    'project_name'   => $projectName,
                    'is_fastlane'    => (int)($row['is_fastlane'] ?? 0),
                ];
            }
        }

        // Parse treated items from JSON
        $treatedItems = [];
        if (!empty($this->fields['treated_items'])) {
            $treatedItems = json_decode($this->fields['treated_items'], true) ?: [];
        }

        if (class_exists('Glpi\Application\View\TemplateRenderer')) {
            \Glpi\Application\View\TemplateRenderer::getInstance()->display(
                '@sprint/sprintmeeting.form.html.twig',
                [
                    'item'            => $this,
                    'params'          => $options,
                    'meeting_types'   => self::getAllTypes(),
                    'member_options'  => $memberOptions,
                    'is_existing'     => $isExisting,
                    'sprint_items'    => $sprintItemsData,
                    'item_statuses'   => SprintItem::getAllStatuses(),
                    'treated_items'   => $treatedItems,
                    'backlog_url'     => \GlpiPlugin\Sprint\Backlog::getFormURL(),
                    'meeting_url'     => static::getFormURLWithID($ID),
                    'sprint_id'       => $sprintId,
                ]
            );
        } else {
            $this->showFormHeader($options);
            $types = self::getAllTypes();

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Title') . "</td>";
            echo "<td>" . Html::input('name', [
                'value' => $this->fields['name'] ?? '', 'size' => 40
            ]) . "</td>";
            echo "<td>" . __('Type', 'sprint') . "</td><td>";
            Dropdown::showFromArray('meeting_type', $types, [
                'value' => $this->fields['meeting_type'] ?? self::TYPE_STANDUP,
            ]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'><td>" . __('Date') . "</td><td>";
            Html::showDateTimeField('date_meeting', ['value' => $this->fields['date_meeting'] ?? '']);
            echo "</td><td>" . __('Duration (min)', 'sprint') . "</td><td>";
            Dropdown::showNumber('duration_minutes', [
                'value' => $this->fields['duration_minutes'] ?? 15,
                'min' => 5, 'max' => 240, 'step' => 5,
            ]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'><td>" . __('Facilitator', 'sprint') . "</td><td>";
            $facilitatorId = (int)($this->fields['users_id'] ?? 0);
            echo ($facilitatorId > 0) ? getUserName($facilitatorId) : '-';
            echo Html::hidden('users_id', ['value' => $facilitatorId]);
            echo "</td><td>" . __('Sprint') . "</td><td>";
            Sprint::dropdown(['name' => 'plugin_sprint_sprints_id', 'value' => $this->fields['plugin_sprint_sprints_id'] ?? 0]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'><td>" . __('Meeting Notes', 'sprint') . "</td>";
            echo "<td colspan='3'><textarea name='notes' rows='10' cols='100'>" .
                htmlescape($this->fields['notes'] ?? '') . "</textarea></td></tr>";

            // Embed sprint items review inside the form (before buttons)
            if ($ID > 0) {
                $sprintId = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
                if ($sprintId > 0) {
                    echo "</table>"; // close the form table temporarily
                    self::showSprintItemsReview($sprintId, $ID);
                    echo "<table class='tab_cadre_fixe'>"; // re-open for showFormButtons
                }
            }

            $this->showFormButtons($options);
        }

        return true;
    }

    /**
     * Show sprint items review table embedded in the meeting form.
     * Uses array field names _sprintitems[id][field] so the main
     * meeting save button processes all changes at once.
     */
    public static function showSprintItemsReview(int $sprintId, int $meetingId = 0): void
    {
        $canedit       = Sprint::canUpdate();
        $memberOptions = SprintMember::getSprintMemberOptions($sprintId);
        $statuses      = SprintItem::getAllStatuses();

        $si    = new SprintItem();
        $allItems = $si->find(
            ['plugin_sprint_sprints_id' => $sprintId],
            ['sort_order ASC', 'priority DESC']
        );

        // Split into fastlane and regular items
        $fastlaneItems = array_filter($allItems, fn($r) => !empty($r['is_fastlane']));
        $regularItems  = array_filter($allItems, fn($r) => empty($r['is_fastlane']));

        $typeIcons = [
            ''            => ['fas fa-clipboard-list', '#6c757d', __('Manual', 'sprint')],
            'Ticket'      => ['fas fa-ticket-alt', '#0d6efd', __('Ticket')],
            'Change'      => ['fas fa-exchange-alt', '#6f42c1', __('Change')],
            'ProjectTask' => ['fas fa-tasks', '#fd7e14', __('Project task')],
        ];
        $backlogUrl = Backlog::getFormURL();
        $meetingUrl = ($meetingId > 0) ? static::getFormURLWithID($meetingId) : '';

        // Helper to render a row
        $renderRow = function (array $row, bool $isFastlane) use ($canedit, $statuses, $memberOptions, $typeIcons, $backlogUrl, $meetingUrl) {
            $itemId    = (int)$row['id'];
            $itemtype  = $row['itemtype'] ?? '';
            $typeInfo  = $typeIcons[$itemtype] ?? $typeIcons[''];

            $linkedDisplay = '<span style="color:#ccc;">-</span>';
            if (!empty($itemtype) && (int)$row['items_id'] > 0) {
                $tmpItem = new SprintItem();
                $tmpItem->fields = $row;
                $linkedDisplay = $tmpItem->getLinkedItemDisplay();
            }

            echo "<tr class='tab_bg_1'>";
            echo "<td class='center'><i class='{$typeInfo[0]}' style='color:{$typeInfo[1]};' title='{$typeInfo[2]}'></i></td>";
            $fastlaneIcon = $isFastlane ? "<i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;'></i>" : '';
            echo "<td>{$fastlaneIcon}<a href='" . SprintItem::getFormURLWithID($itemId) . "'>" .
                htmlescape($row['name']) . "</a></td>";
            echo "<td>" . $linkedDisplay . "</td>";

            if ($canedit) {
                echo "<td>";
                Dropdown::showFromArray("_sprintitems[{$itemId}][status]", $statuses, [
                    'value' => $row['status'],
                    'width' => '140px',
                ]);
                echo "</td>";
                echo "<td>";
                Dropdown::showFromArray("_sprintitems[{$itemId}][users_id]", $memberOptions, [
                    'value' => (int)$row['users_id'],
                    'width' => '170px',
                ]);
                echo "</td>";
            } else {
                $statusClass = 'sprint-status-' . str_replace('_', '-', $row['status']);
                echo "<td><span class='sprint-badge {$statusClass}'>" .
                    ($statuses[$row['status']] ?? $row['status']) . "</span></td>";
                echo "<td>" . (((int)$row['users_id'] > 0) ? getUserName($row['users_id']) :
                    '<span style="color:#999;">' . __('Unassigned', 'sprint') . '</span>') . "</td>";
            }
            echo "<td class='center'>" . (int)$row['story_points'] . "</td>";

            echo "<td class='center'>";
            if ($canedit) {
                echo "<form method='post' action='{$backlogUrl}' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $itemId]);
                if ($meetingUrl) {
                    echo Html::hidden('_redirect', ['value' => $meetingUrl]);
                }
                echo "<button type='submit' name='back_to_backlog' class='btn btn-sm btn-outline-warning' "
                    . "title='" . __('Back to backlog', 'sprint') . "' "
                    . "onclick=\"return confirm('" . __('Move this item back to the backlog?', 'sprint') . "');\">"
                    . "<i class='fas fa-undo'></i></button>";
                Html::closeForm();
            }
            echo "</td>";
            echo "</tr>";
        };

        $tableHeaders = function () {
            echo "<tr class='tab_bg_2'>";
            echo "<th style='width:40px;'>" . __('Type') . "</th>";
            echo "<th>" . __('Name') . "</th>";
            echo "<th>" . __('Linked item', 'sprint') . "</th>";
            echo "<th>" . __('Status') . "</th>";
            echo "<th>" . __('Owner', 'sprint') . "</th>";
            echo "<th>" . __('Story Points', 'sprint') . "</th>";
            echo "<th style='width:40px;'></th>";
            echo "</tr>";
        };

        // === Fastlane section ===
        if (count($fastlaneItems) > 0) {
            echo "<div class='center' style='margin-top:20px;'>";
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_2'><th colspan='7' style='background:#fff3cd;border-bottom:2px solid #fd7e14;'>" .
                "<i class='fas fa-bolt' style='color:#fd7e14;'></i> " .
                __('Fastlane', 'sprint') . "</th></tr>";
            $tableHeaders();
            foreach ($fastlaneItems as $row) {
                $renderRow($row, true);
            }
            echo "</table></div>";
        }

        // === Regular items section ===
        echo "<div class='center' style='margin-top:" . (count($fastlaneItems) > 0 ? '8' : '20') . "px;'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'><th colspan='7'>" .
            "<i class='fas fa-clipboard-list'></i> " .
            __('Sprint Items Review', 'sprint') . "</th></tr>";
        $tableHeaders();

        if (count($regularItems) === 0) {
            echo "<tr class='tab_bg_1'><td colspan='7' class='center' style='padding:16px;color:#999;'>" .
                __('No items in this sprint', 'sprint') . "</td></tr>";
        }

        foreach ($regularItems as $row) {
            $renderRow($row, false);
        }

        echo "</table></div>";
    }

    /**
     * Define tabs for meeting detail view
     */
    public function prepareInputForUpdate($input)
    {
        // Process sprint item changes before the meeting update
        if (!empty($input['_sprintitems']) && is_array($input['_sprintitems'])) {
            $si = new SprintItem();
            foreach ($input['_sprintitems'] as $itemId => $fields) {
                $itemId = (int)$itemId;
                if ($itemId <= 0) {
                    continue;
                }
                // Skip treated items — they are locked
                if (!empty($fields['_treated'])) {
                    continue;
                }
                $update = ['id' => $itemId];
                if (isset($fields['status'])) {
                    $update['status'] = $fields['status'];
                }
                if (isset($fields['users_id'])) {
                    $update['users_id'] = (int)$fields['users_id'];
                }
                if (array_key_exists('note', $fields)) {
                    $update['note'] = $fields['note'];
                }
                $si->update($update);
            }
        }

        // Store treated items as JSON
        if (isset($input['_treated_items']) && is_array($input['_treated_items'])) {
            $input['treated_items'] = json_encode(array_map('intval', $input['_treated_items']));
        } else {
            $input['treated_items'] = json_encode([]);
        }

        return parent::prepareInputForUpdate($input);
    }

    public function defineTabs($options = []): array
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab('Log', $ong, $options);
        return $ong;
    }
}
