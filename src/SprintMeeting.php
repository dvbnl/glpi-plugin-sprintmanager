<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use CommonGLPI;
use Html;
use Session;
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

            echo "<table class='tab_cadre_fixe sprint-themed'>";
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

        echo "<div class='center'><table class='tab_cadre_fixe sprint-themed'>";
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
            echo "<td>" . htmlescape(SprintCache::userName($row['users_id'])) . "</td>";
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
     * The meeting chronologically before this one in the sprint, or null.
     * Ordered by date_meeting then id for deterministic tie-breaking.
     */
    private function getPreviousMeeting(): ?self
    {
        $sprintId    = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);
        $currentDate = (string)($this->fields['date_meeting'] ?? '');
        $currentId   = (int)$this->getID();
        if ($sprintId <= 0 || $currentDate === '' || $currentId <= 0) {
            return null;
        }

        $candidates = (new self())->find(
            [
                'plugin_sprint_sprints_id' => $sprintId,
                'OR'                       => [
                    ['date_meeting' => ['<', $currentDate]],
                    [
                        'date_meeting' => $currentDate,
                        'id'           => ['<', $currentId],
                    ],
                ],
            ],
            ['date_meeting DESC', 'id DESC'],
            1
        );
        if (empty($candidates)) {
            return null;
        }

        $prev = new self();
        $prev->fields = reset($candidates);
        return $prev;
    }

    /**
     * Replace this meeting's blocked-item snapshot. A sentinel row (item 0)
     * marks the meeting as snapshotted, so an empty set is distinguishable
     * from "never viewed".
     *
     * @param int[] $blockedItemIds
     */
    public static function recordBlockedSnapshot(int $meetingId, array $blockedItemIds): void
    {
        global $DB;

        $table = 'glpi_plugin_sprint_meetingblockedsnapshots';
        if ($meetingId <= 0 || !$DB->tableExists($table)) {
            return;
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->delete($table, ['plugin_sprint_sprintmeetings_id' => $meetingId]);
        $DB->insert($table, [
            'plugin_sprint_sprintmeetings_id' => $meetingId,
            'plugin_sprint_sprintitems_id'    => 0,
            'date_creation'                   => $now,
        ]);
        foreach (array_unique(array_filter(array_map('intval', $blockedItemIds))) as $itemId) {
            $DB->insert($table, [
                'plugin_sprint_sprintmeetings_id' => $meetingId,
                'plugin_sprint_sprintitems_id'    => $itemId,
                'date_creation'                   => $now,
            ]);
        }
    }

    /**
     * Item IDs blocked as of this meeting's last view, or null when never
     * snapshotted (caller then falls back to audit-log reconstruction).
     *
     * @return int[]|null
     */
    public static function getBlockedSnapshot(int $meetingId): ?array
    {
        global $DB;

        $table = 'glpi_plugin_sprint_meetingblockedsnapshots';
        if ($meetingId <= 0 || !$DB->tableExists($table)) {
            return null;
        }

        $ids       = [];
        $hasMarker = false;
        foreach ($DB->request([
            'SELECT' => ['plugin_sprint_sprintitems_id'],
            'FROM'   => $table,
            'WHERE'  => ['plugin_sprint_sprintmeetings_id' => $meetingId],
        ]) as $r) {
            $itemId = (int)$r['plugin_sprint_sprintitems_id'];
            if ($itemId === 0) {
                $hasMarker = true;
                continue;
            }
            $ids[$itemId] = true;
        }

        if (!$hasMarker && empty($ids)) {
            return null;
        }
        return array_keys($ids);
    }

    /**
     * Phase lineup for the guided rail. Per phase: key, minutes, title,
     * description, blocks (content identifiers for the rail template), has_notes.
     */
    public static function getPhaseDefinitions(string $meetingType): array
    {
        if ($meetingType === self::TYPE_REVIEW) {
            return [
                [
                    'key'         => 'opening',
                    'minutes'     => 5,
                    'title'       => __('Opening & sprint goal', 'sprint'),
                    'description' => __('Restate the sprint goal and planned scope. Was the goal met — yes or no, and why in one sentence.', 'sprint'),
                    'blocks'      => ['sprint_goal'],
                    'has_notes'   => true,
                ],
                [
                    'key'         => 'stats',
                    'minutes'     => 5,
                    'title'       => __('Results in numbers', 'sprint'),
                    'description' => __('The numbers: planned vs done per the Definition of Done, plus carry-over. Name what is not done — no discussion yet.', 'sprint'),
                    'blocks'      => ['stat_cards', 'charts'],
                    'has_notes'   => true,
                ],
                [
                    'key'         => 'walkthrough',
                    'minutes'     => 15,
                    'title'       => __('Sprint items walkthrough', 'sprint'),
                    'description' => __('Walk through the sprint items together: update status, owner and notes, resolve blockers, and decide per unfinished item whether it carries over or returns to the backlog.', 'sprint'),
                    'blocks'      => ['sprint_items'],
                    'has_notes'   => true,
                ],
                [
                    'key'         => 'demo',
                    'minutes'     => 10,
                    'title'       => __('Demo of the increment', 'sprint'),
                    'description' => __('Each member briefly demos their own finished items — working functionality, no slides, done items only. Agree the order upfront so it tells one story.', 'sprint'),
                    'blocks'      => ['done_items'],
                    'has_notes'   => true,
                ],
                [
                    'key'         => 'feedback',
                    'minutes'     => 10,
                    'title'       => __('Stakeholder feedback', 'sprint'),
                    'description' => __('Collect feedback on the increment from stakeholders. Capture remarks below and turn concrete insights into backlog items right away.', 'sprint'),
                    'blocks'      => [],
                    'has_notes'   => true,
                ],
                [
                    'key'         => 'wrapup',
                    'minutes'     => 5,
                    'title'       => __('Wrap up & next steps', 'sprint'),
                    'description' => __('Summarise the key outcomes and confirm backlog changes. Then on to the retrospective.', 'sprint'),
                    'blocks'      => ['carryover_summary'],
                    'has_notes'   => true,
                ],
            ];
        }
        if ($meetingType === self::TYPE_RETROSPECTIVE) {
            return [
                [
                    'key'         => 'checkin',
                    'minutes'     => 5,
                    'title'       => __('Opening & check-in', 'sprint'),
                    'description' => __('Everyone scores the sprint 1–5. Restate the goal: improve, no blame.', 'sprint'),
                    'blocks'      => [],
                    'has_notes'   => true,
                ],
                [
                    'key'         => 'prev_actions',
                    'minutes'     => 5,
                    'title'       => __('Previous retro actions', 'sprint'),
                    'description' => __("Walk through the previous retro's actions: what was done, what stalled and why.", 'sprint'),
                    'blocks'      => ['prev_actions'],
                    'has_notes'   => true,
                ],
                [
                    'key'         => 'data',
                    'minutes'     => 5,
                    'title'       => __('Sprint in numbers', 'sprint'),
                    'description' => __('A quick, neutral look at the sprint numbers as input for the conversation — observations only, no conclusions yet.', 'sprint'),
                    'blocks'      => ['stat_cards'],
                    'has_notes'   => true,
                ],
                [
                    'key'         => 'collect',
                    'minutes'     => 15,
                    'title'       => __('Collect input', 'sprint'),
                    'description' => __('Everyone writes input in silence first (Start/Stop/Continue or Mad/Sad/Glad) before anyone speaks.', 'sprint'),
                    'blocks'      => ['improvement_collect'],
                    'has_notes'   => true,
                ],
                [
                    'key'         => 'vote',
                    'minutes'     => 10,
                    'title'       => __('Cluster & vote', 'sprint'),
                    'description' => __('Group similar input and dot-vote the two or three themes that matter most.', 'sprint'),
                    'blocks'      => ['improvement_vote'],
                    'has_notes'   => true,
                ],
                [
                    'key'         => 'actions',
                    'minutes'     => 10,
                    'title'       => __('Decide actions', 'sprint'),
                    'description' => __('Define at most two or three actions with an owner and a due date — more usually means none get done.', 'sprint'),
                    'blocks'      => ['improvement_actions'],
                    'has_notes'   => true,
                ],
                [
                    'key'         => 'close',
                    'minutes'     => 5,
                    'title'       => __('Wrap up', 'sprint'),
                    'description' => __('Short retro on the retro: was this useful, what do we change next time?', 'sprint'),
                    'blocks'      => ['retro_summary'],
                    'has_notes'   => true,
                ],
            ];
        }
        return [];
    }

    /** Twig context for the guided rail: phases, session state, per-type data blocks. */
    private function buildGuidedContext(int $sprintId, array $sprintItemsData, array $memberOptions): array
    {
        global $DB;

        $meetingType  = (string)($this->fields['meeting_type'] ?? '');
        $sprint       = new Sprint();
        $sprintLoaded = $sprint->getFromDB($sprintId);

        $uid           = (int)Session::getLoginUserID();
        $isScrumMaster = SprintItem::currentUserIsScrumMasterOf($sprintId);
        $isFacilitator = ((int)($this->fields['users_id'] ?? 0) === $uid);
        $canDrive      = Sprint::canUpdate() && ($isScrumMaster || ($isFacilitator && SprintAgility::isCurrentUserMember($sprintId)));

        $totalSp = $doneSp = $doneItems = $blockedCount = $adhocCount = 0;
        foreach ($sprintItemsData as $si) {
            $totalSp += (int)$si['story_points'];
            if ($si['status'] === SprintItem::STATUS_DONE) {
                $doneSp += (int)$si['story_points'];
                $doneItems++;
            }
            if ($si['status'] === SprintItem::STATUS_BLOCKED) {
                $blockedCount++;
            }
            if (!empty($si['is_adhoc'])) {
                $adhocCount++;
            }
        }
        $totalItems = count($sprintItemsData);

        $guided = [
            'meeting_type'        => $meetingType,
            'phases'              => self::getPhaseDefinitions($meetingType),
            'status'              => (string)($this->fields['meeting_status'] ?? 'open') ?: 'open',
            'current_phase'       => (int)($this->fields['current_phase'] ?? 0),
            'phase_started_at_ts' => !empty($this->fields['phase_started_at']) ? (int)strtotime((string)$this->fields['phase_started_at']) : 0,
            'started_at'          => $this->fields['started_at'] ?? null,
            'ended_at'            => $this->fields['ended_at'] ?? null,
            'server_now_ts'       => time(),
            'phase_notes'         => json_decode((string)($this->fields['phase_notes'] ?? ''), true) ?: [],
            'can_drive'           => $canDrive,
            'can_toggle_actions'  => $isScrumMaster || ($isFacilitator && SprintAgility::isCurrentUserMember($sprintId)),
            'can_contribute'      => SprintAgility::isCurrentUserMember($sprintId),
            'current_user_name'   => SprintCache::userName($uid),
            'sprint_goal'         => $sprintLoaded ? trim((string)($sprint->fields['goal'] ?? '')) : '',
            'sprint_name'         => $sprintLoaded ? (string)$sprint->fields['name'] : '',
            'sprint_start'        => ($sprintLoaded && !empty($sprint->fields['date_start'])) ? Html::convDate($sprint->fields['date_start']) : '',
            'sprint_end'          => ($sprintLoaded && !empty($sprint->fields['date_end'])) ? Html::convDate($sprint->fields['date_end']) : '',
        ];

        $statCards = [
            ['label' => __('Delivered', 'sprint'),        'value' => $doneSp . ' / ' . $totalSp . ' SP',        'icon' => 'fas fa-chart-line',       'accent' => '#198754'],
            ['label' => __('Items completed', 'sprint'),  'value' => $doneItems . ' / ' . $totalItems,          'icon' => 'fas fa-clipboard-check',  'accent' => '#0d6efd'],
            ['label' => __('Adhoc added', 'sprint'),      'value' => $adhocCount,                               'icon' => 'fas fa-plus-circle',      'accent' => '#6f42c1'],
            ['label' => __('Blocked', 'sprint'),          'value' => $blockedCount,                             'icon' => 'fas fa-ban',              'accent' => '#dc3545'],
        ];

        if ($meetingType === self::TYPE_REVIEW) {
            $guided['stat_cards'] = $statCards;

            if ($sprintLoaded) {
                ob_start();
                SprintDashboard::renderBurndownChartBody($sprint);
                $guided['burndown_html'] = (string)ob_get_clean();
                ob_start();
                SprintDashboard::renderVelocityChartBody($sprint);
                $guided['velocity_html'] = (string)ob_get_clean();
            }

            // Demo running order: done items grouped per owner.
            $doneByOwner = [];
            $unfinished  = [];
            foreach ($sprintItemsData as $si) {
                if ($si['status'] === SprintItem::STATUS_DONE) {
                    $ownerName = $memberOptions[(int)$si['users_id']] ?? __('Unassigned', 'sprint');
                    $doneByOwner[$ownerName][] = $si;
                } else {
                    $unfinished[] = $si;
                }
            }
            $guided['done_by_owner']    = $doneByOwner;
            $guided['unfinished_items'] = $unfinished;
        }

        if ($meetingType === self::TYPE_RETROSPECTIVE) {
            // Previous completed sprint's velocity, for the comparison card.
            $prevVelocity = null;
            $prevSprint   = null;
            if ($sprintLoaded) {
                foreach ($DB->request([
                    'FROM'  => Sprint::getTable(),
                    'WHERE' => [
                        'entities_id' => (int)($sprint->fields['entities_id'] ?? 0),
                        'status'      => Sprint::STATUS_COMPLETED,
                        ['NOT' => ['id' => $sprintId]],
                    ],
                    'ORDER' => ['date_end DESC'],
                    'LIMIT' => 1,
                ]) as $row) {
                    $prevSprint = $row;
                }
                if ($prevSprint !== null) {
                    $prevVelocity = 0;
                    foreach ($DB->request([
                        'SELECT' => ['story_points'],
                        'FROM'   => SprintItem::getTable(),
                        'WHERE'  => [
                            'plugin_sprint_sprints_id' => (int)$prevSprint['id'],
                            'status'                   => SprintItem::STATUS_DONE,
                        ],
                    ]) as $itemRow) {
                        $prevVelocity += max(0, (int)$itemRow['story_points']);
                    }
                }
            }
            if ($prevVelocity !== null) {
                $statCards[] = [
                    'label'  => sprintf(__('Previous sprint (%s)', 'sprint'), (string)$prevSprint['name']),
                    'value'  => $prevVelocity . ' SP',
                    'icon'   => 'fas fa-history',
                    'accent' => '#fd7e14',
                ];
            }
            $guided['stat_cards'] = $statCards;

            $improvements = SprintAgility::getImprovementsForSprint($sprintId);
            $guided['improvements']           = $improvements;
            $guided['improvement_categories'] = SprintAgility::improvementCategories();

            // Open actions that predate the meeting start (= carried in from the
            // previous sprint); actions created during this retro are excluded.
            $startedAt = (string)($this->fields['started_at'] ?? '');
            $guided['prev_actions'] = array_values(array_filter($improvements, function ($row) use ($startedAt) {
                if (($row['category'] ?? '') !== 'action') {
                    return false;
                }
                if ($startedAt !== '' && (string)($row['date_creation'] ?? '') > $startedAt) {
                    return false;
                }
                return true;
            }));
        }

        return $guided;
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        $sprintId = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);

        // "Back to Sprint" button linking to the parent sprint's Meetings tab
        if ($sprintId > 0) {
            $sprintUrl = Sprint::getFormURLWithID($sprintId) . '&forcetab=' . urlencode('GlpiPlugin\\Sprint\\SprintMeeting$1');
            echo "<div style='margin-bottom:10px;'>";
            echo "<a href='$sprintUrl' class='btn btn-outline-secondary'>";
            echo "<i class='fas fa-arrow-left me-1'></i> " . __('Back to Sprint', 'sprint');
            echo "</a></div>";
        }
        $memberOptions = SprintMember::getSprintMemberOptions($sprintId);
        $isExisting = ($ID > 0);

        // Build sprint items data for the review table
        $sprintItemsData = [];
        if ($isExisting && $sprintId > 0) {
            $si        = new SprintItem();
            $statuses  = SprintItem::getAllStatuses();
            $allRows   = $si->find(['plugin_sprint_sprints_id' => $sprintId], ['sort_order ASC', 'priority DESC']);
            $rowItemIds = array_map(fn($r) => (int)$r['id'], $allRows);
            $tagsByItem = SprintItem::getTagsForItems($rowItemIds);
            $depsByItem = SprintItemDependency::getOpenSummariesForItems($rowItemIds);

            // Highlight items newly blocked since the previous meeting.
            // Baseline = previous meeting's recorded snapshot; for meetings
            // predating this feature, reconstruct from the audit log at the
            // previous meeting's date.
            $prevBlockedSet   = [];      // [itemId => true]
            $havePrevBaseline = false;
            $prevMeeting      = $this->getPreviousMeeting();
            if ($prevMeeting !== null) {
                $prevSnapshot = self::getBlockedSnapshot((int)$prevMeeting->getID());
                if ($prevSnapshot !== null) {
                    foreach ($prevSnapshot as $iid) {
                        $prevBlockedSet[(int)$iid] = true;
                    }
                    $havePrevBaseline = true;
                } else {
                    $prevDate = (string)($prevMeeting->fields['date_meeting'] ?? '');
                    if ($prevDate !== '') {
                        $currentStatuses = [];
                        foreach ($allRows as $r) {
                            $currentStatuses[(int)$r['id']] = (string)$r['status'];
                        }
                        foreach (SprintAudit::getItemStatusAtTimestamp($rowItemIds, $prevDate, $currentStatuses) as $iid => $st) {
                            if ($st === SprintItem::STATUS_BLOCKED) {
                                $prevBlockedSet[(int)$iid] = true;
                            }
                        }
                        $havePrevBaseline = true;
                    }
                }
            }

            $currentBlockedIds = [];

            foreach ($allRows as $row) {
                $itemId        = (int)$row['id'];
                $isBlockedNow  = ($row['status'] ?? '') === SprintItem::STATUS_BLOCKED;
                if ($isBlockedNow) {
                    $currentBlockedIds[] = $itemId;
                }
                $newlyBlocked  = $havePrevBaseline && $isBlockedNow && empty($prevBlockedSet[$itemId]);
                $linkedDisplay = '';
                $reviewUnclosed = false;
                $itemtype = $row['itemtype'] ?? '';
                $allowedTypes = ['Ticket', 'Change', 'Problem', 'ProjectTask'];
                if (!empty($itemtype) && (int)$row['items_id'] > 0 && in_array($itemtype, $allowedTypes, true) && class_exists($itemtype)) {
                    $tmpItem = new SprintItem();
                    $tmpItem->fields = $row;
                    $linkedDisplay = $tmpItem->getLinkedItemDisplay();
                    // Keep "In Review"/"Done" rows highlighted while their
                    // linked ticket/change is still open.
                    $rowStatus = (string)($row['status'] ?? '');
                    if (
                        ($rowStatus === SprintItem::STATUS_REVIEW || $rowStatus === SprintItem::STATUS_DONE)
                        && !$tmpItem->isLinkedItemClosed()
                    ) {
                        $reviewUnclosed = true;
                    }
                }

                // Muted "(project)" suffix for ProjectTask rows, same as the
                // dashboard/backlog/board renderers.
                $projectSuffix = SprintItem::renderParentProjectSuffix(
                    (string)$itemtype,
                    (int)($row['items_id'] ?? 0)
                );

                // Fastlane items allocate capacity across members via the
                // SprintFastlaneMember junction (users_id alone isn't
                // authoritative), so resolve the per-user list here.
                $fastlaneAllocations = [];
                $fastlaneTotal       = 0.0;
                if ((int)($row['is_fastlane'] ?? 0) === 1) {
                    $rel = new SprintFastlaneMember();
                    foreach ($rel->find(['plugin_sprint_sprintitems_id' => (int)$row['id']]) as $alloc) {
                        $uid = (int)$alloc['users_id'];
                        $cap = (float)$alloc['capacity'];
                        $fastlaneTotal += $cap;
                        $fastlaneAllocations[] = [
                            'users_id' => $uid,
                            'name'     => SprintCache::userName($uid),
                            'capacity' => $cap,
                        ];
                    }
                }

                $rowTags = $tagsByItem[(int)$row['id']] ?? [];
                $rowDeps = $depsByItem[(int)$row['id']] ?? [];
                $sprintItemsData[] = [
                    'id'                   => (int)$row['id'],
                    'name'                 => $row['name'],
                    'url'                  => SprintItem::getFormURLWithID((int)$row['id']),
                    'linked_display'       => $linkedDisplay,
                    'status'               => $row['status'],
                    'users_id'             => (int)$row['users_id'],
                    'story_points'         => (int)$row['story_points'],
                    'capacity'             => (float)($row['capacity'] ?? 0),
                    'priority'             => (int)($row['priority'] ?? 3),
                    'note'                 => $row['note'] ?? '',
                    'itemtype'             => $itemtype,
                    'project_suffix_html'  => $projectSuffix,
                    'is_fastlane'          => (int)($row['is_fastlane'] ?? 0),
                    'is_adhoc'             => (int)($row['is_adhoc'] ?? 0),
                    'fastlane_allocations' => $fastlaneAllocations,
                    'fastlane_total'       => $fastlaneTotal,
                    'fastlane_url'         => SprintItem::getFormURLWithID((int)$row['id']) . '&forcetab=' . urlencode('GlpiPlugin\\Sprint\\SprintFastlaneMember$1'),
                    'tags_blob'            => SprintItem::tagsToBlob($rowTags),
                    'tags_pills_html'      => SprintItem::renderTagPills($rowTags),
                    'deps_open'            => $rowDeps,
                    'deps_open_count'      => count($rowDeps),
                    'newly_blocked'        => $newlyBlocked,
                    'review_unclosed'      => $reviewUnclosed,
                ];
            }

            // Record this meeting's blocked set as the next meeting's
            // baseline, so already-blocked items aren't re-flagged.
            self::recordBlockedSnapshot((int)$ID, $currentBlockedIds);
        }

        $carryOverTargetSprints = ($isExisting && $sprintId > 0)
            ? Sprint::getMoveTargetOptions($sprintId)
            : [];

        // Guided meeting rail (review/retrospective on the Twig path only)
        $meetingType = (string)($this->fields['meeting_type'] ?? '');
        $guided      = null;
        if (
            $isExisting && $sprintId > 0
            && in_array($meetingType, [self::TYPE_REVIEW, self::TYPE_RETROSPECTIVE], true)
        ) {
            $guided = $this->buildGuidedContext($sprintId, $sprintItemsData, $memberOptions);
        }

        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@sprint/sprintmeeting.form.html.twig',
            [
                'item'              => $this,
                'params'            => $options,
                'meeting_types'     => self::getAllTypes(),
                'member_options'    => $memberOptions,
                'is_existing'       => $isExisting,
                'sprint_items'      => $sprintItemsData,
                'item_statuses'     => SprintItem::getAllStatuses(),
                'item_priorities'   => [
                    1 => __('Very low'),
                    2 => __('Low'),
                    3 => __('Medium'),
                    4 => __('High'),
                    5 => __('Very high'),
                ],
                'capacity_choices'  => SprintMember::getCapacityChoices(),
                'backlog_url'       => \GlpiPlugin\Sprint\Backlog::getFormURL(),
                'meeting_url'       => static::getFormURLWithID($ID),
                'meeting_id'        => $ID,
                'sprint_id'         => $sprintId,
                'move_target_sprints' => $carryOverTargetSprints,
                'capacity_locked'   => \GlpiPlugin\Sprint\Config::isScrumMasterOnlyCapacity()
                    && !SprintItem::currentUserIsScrumMasterOf($sprintId),
                'is_scrum_master'   => SprintItem::currentUserIsScrumMasterOf($sprintId),
                'defined_tags'      => \GlpiPlugin\Sprint\Config::getDefinedTags(),
                'guided'            => $guided,
            ]
        );
        SprintItem::renderLinkedQuickEditUI();

        return true;
    }


    public function defineTabs($options = []): array
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab('Log', $ong, $options);
        return $ong;
    }
}
