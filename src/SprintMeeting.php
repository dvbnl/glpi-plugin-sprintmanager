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

    /** Reserved phase_notes key: previous sprint picked by the facilitator for the retro follow-up. */
    const PREV_SPRINT_NOTE_KEY = '_prev_sprint_id';

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

    /** Default length in minutes per meeting type (add form and one-click stand-up). */
    const DEFAULT_DURATIONS = [
        self::TYPE_KICKOFF       => 60,
        self::TYPE_STANDUP       => 15,
        self::TYPE_REVIEW        => 60,
        self::TYPE_RETROSPECTIVE => 45,
    ];

    public static function getDefaultDuration(string $meetingType): int
    {
        return self::DEFAULT_DURATIONS[$meetingType] ?? self::DEFAULT_DURATIONS[self::TYPE_STANDUP];
    }

    /** Today (Y-m-d) on GLPI's clock, so it matches the stored meeting dates. */
    private static function today(): string
    {
        return substr((string)($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')), 0, 10);
    }

    /** Default title for a new meeting of this type: "Stand-up dd-mm", "Sprint Review dd-mm", ... */
    public static function getDefaultTitle(string $meetingType): string
    {
        $day = date('d-m', (int)strtotime(self::today()));
        if ($meetingType === self::TYPE_STANDUP) {
            return sprintf(__('Stand-up %s', 'sprint'), $day);
        }
        $types = self::getAllTypes();
        return trim(($types[$meetingType] ?? self::getTypeName(1)) . ' ' . $day);
    }

    /** Id of today's stand-up in this sprint (the latest one), or 0. */
    public static function getTodayStandupId(int $sprintId): int
    {
        if ($sprintId <= 0) {
            return 0;
        }
        $today = self::today();
        $rows  = (new self())->find(
            [
                'plugin_sprint_sprints_id' => $sprintId,
                'meeting_type'             => self::TYPE_STANDUP,
                ['date_meeting' => ['>=', $today . ' 00:00:00']],
                ['date_meeting' => ['<=', $today . ' 23:59:59']],
            ],
            ['date_meeting DESC', 'id DESC'],
            1
        );
        $row = reset($rows);
        return $row ? (int)$row['id'] : 0;
    }

    /** Input for the one-click stand-up: today, 15 minutes, the current user facilitates. */
    public static function getStandupInput(int $sprintId): array
    {
        return [
            'plugin_sprint_sprints_id' => $sprintId,
            'name'                     => self::getDefaultTitle(self::TYPE_STANDUP),
            'meeting_type'             => self::TYPE_STANDUP,
            'date_meeting'             => (string)($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
            'duration_minutes'         => self::getDefaultDuration(self::TYPE_STANDUP),
            'users_id'                 => (int)Session::getLoginUserID(),
        ];
    }

    /**
     * "Start stand-up" button for a sprint, as HTML. One click creates today's
     * stand-up and opens it (POST `start_standup` to front/sprintmeeting.form.php).
     * When today's stand-up exists it is a plain link to that meeting. Empty
     * string when the user may not do either.
     */
    public static function startStandupButton(int $sprintId): string
    {
        if ($sprintId <= 0) {
            return '';
        }
        $icon     = "<i class='fas fa-coffee me-1' aria-hidden='true'></i>";
        $existing = self::getTodayStandupId($sprintId);
        if ($existing > 0) {
            if (!self::canView()) {
                return '';
            }
            return "<a href='" . static::getFormURLWithID($existing) . "' class='btn btn-primary sprint-standup-btn'>"
                . $icon . __s("Open today's stand-up", 'sprint') . "</a>";
        }
        // Same rights as the "Schedule a meeting" form and its handler.
        if (!Sprint::canUpdate() || !self::canCreate()) {
            return '';
        }
        return "<form method='post' action='" . static::getFormURL() . "' class='d-inline-block sprint-standup-form'>"
            . Html::hidden('plugin_sprint_sprints_id', ['value' => $sprintId])
            . Html::hidden('start_standup', ['value' => 1])
            . "<button type='submit' class='btn btn-primary sprint-standup-btn' title='"
            . __s('Creates a stand-up for today and opens it', 'sprint') . "'>"
            . $icon . __s('Start stand-up', 'sprint') . "</button>"
            . Html::closeForm(false);
    }

    /**
     * Show meetings list + add form for a sprint
     */
    public static function showForSprint(Sprint $sprint): void
    {
        $ID      = $sprint->getID();
        $canedit = Sprint::canUpdate();

        echo "<div class='sprint-meetings-tab'>";

        // === One-click stand-up ===
        $standupButton = self::startStandupButton((int)$ID);
        if ($standupButton !== '') {
            echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'>" . $standupButton . "</div>";
        }

        // === Add form ===
        if ($canedit) {
            $types    = self::getAllTypes();
            $defaults = [];
            foreach ($types as $typeKey => $typeLabel) {
                $defaults[$typeKey] = [
                    'minutes' => self::getDefaultDuration($typeKey),
                    'title'   => self::getDefaultTitle($typeKey),
                ];
            }
            $rand         = mt_rand();
            $defaultType  = self::TYPE_STANDUP;
            $defaultTitle = $defaults[$defaultType]['title'];

            echo "<div class='card mb-3 sprint-meeting-add'>";
            echo "<div class='card-header'><h3 class='card-title'>"
                . "<i class='fas fa-calendar-plus me-2' aria-hidden='true'></i>"
                . __s('Schedule a meeting', 'sprint') . "</h3></div>";
            echo "<div class='card-body'>";
            echo "<form method='post' action='" . static::getFormURL() . "' class='sprint-meeting-add-form' "
                . "data-type-defaults='" . htmlspecialchars((string)json_encode($defaults), ENT_QUOTES, 'UTF-8') . "'>";
            echo Html::hidden('plugin_sprint_sprints_id', ['value' => $ID]);
            echo "<div class='row g-2 align-items-end'>";

            // The type comes first: it sets the default title and duration.
            echo "<div class='col-12 col-md-6 col-xl-2' role='group' aria-labelledby='sprint-meeting-type-label-{$rand}'>";
            echo "<div class='form-label' id='sprint-meeting-type-label-{$rand}'>" . __s('Type', 'sprint') . "</div>";
            Dropdown::showFromArray('meeting_type', $types, [
                'value' => $defaultType,
                'width' => '100%',
            ]);
            echo "</div>";

            echo "<div class='col-12 col-md-6 col-xl-3'>";
            echo "<label class='form-label' for='sprint-meeting-title-{$rand}'>" . __s('Title') . "</label>";
            echo "<input type='text' class='form-control' name='name' id='sprint-meeting-title-{$rand}' "
                . "value='" . htmlspecialchars($defaultTitle, ENT_QUOTES, 'UTF-8') . "' "
                . "data-auto='" . htmlspecialchars($defaultTitle, ENT_QUOTES, 'UTF-8') . "'>";
            echo "</div>";

            echo "<div class='col-12 col-md-6 col-xl-3' role='group' aria-labelledby='sprint-meeting-date-label-{$rand}'>";
            echo "<div class='form-label' id='sprint-meeting-date-label-{$rand}'>" . __s('Date') . "</div>";
            Html::showDateTimeField('date_meeting', ['value' => date('Y-m-d H:i:s')]);
            echo "</div>";

            echo "<div class='col-6 col-md-3 col-xl-1' role='group' aria-labelledby='sprint-meeting-duration-label-{$rand}'>";
            echo "<div class='form-label' id='sprint-meeting-duration-label-{$rand}'>" . __s('Duration (min)', 'sprint') . "</div>";
            Dropdown::showNumber('duration_minutes', [
                'value' => self::getDefaultDuration($defaultType), 'min' => 5, 'max' => 240, 'step' => 5,
                'width' => '100%',
            ]);
            echo "</div>";

            echo "<div class='col-6 col-md-3 col-xl-2' role='group' aria-labelledby='sprint-meeting-facilitator-label-{$rand}'>";
            echo "<div class='form-label' id='sprint-meeting-facilitator-label-{$rand}'>" . __s('Facilitator', 'sprint')
                . " <span class='required'>*</span></div>";
            // Facilitator is required — no empty option
            $memberOpts = SprintMember::getSprintMemberOptions($ID);
            unset($memberOpts[0]); // remove "-----" empty option
            $defaultFacilitator = array_key_exists(Session::getLoginUserID(), $memberOpts)
                ? Session::getLoginUserID()
                : (int)array_key_first($memberOpts);
            Dropdown::showFromArray('users_id', $memberOpts, [
                'value' => $defaultFacilitator,
                'width' => '100%',
            ]);
            echo "</div>";

            echo "<div class='col-12 col-md-auto'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</div>";

            echo "</div>"; // .row
            Html::closeForm();
            echo "</div></div>"; // .card-body, .card
        }

        // === List meetings: upcoming (next one first) and past ===
        $meeting  = new self();
        $meetings = $meeting->find(
            ['plugin_sprint_sprints_id' => $ID],
            ['date_meeting DESC', 'id DESC']
        );

        $todayStart = self::today() . ' 00:00:00';
        $upcoming   = [];
        $past       = [];
        foreach ($meetings as $row) {
            $completed = self::isGuidedType((string)$row['meeting_type'])
                && (string)($row['meeting_status'] ?? '') === 'completed';
            if ((string)$row['date_meeting'] >= $todayStart && !$completed) {
                $upcoming[] = $row;
            } else {
                $past[] = $row;
            }
        }
        $upcoming = array_reverse($upcoming); // soonest first

        if (count($meetings) === 0) {
            echo "<div class='card'><div class='card-body text-center text-muted py-4'>"
                . "<i class='fas fa-calendar sprint-empty-icon' aria-hidden='true'></i><br>"
                . "<span class='fw-bold'>" . __s('No meetings scheduled', 'sprint') . "</span>"
                . "</div></div>";
        } else {
            self::renderMeetingCard(__('Upcoming', 'sprint'), 'fas fa-calendar-day', $upcoming, $canedit, true);
            if (count($past) > 0) {
                self::renderMeetingCard(__('Past', 'sprint'), 'fas fa-clock-rotate-left', $past, $canedit, false);
            }
        }

        echo "</div>"; // .sprint-meetings-tab

        self::renderMeetingsTabScript();
    }

    /** Review and retrospective run through the guided rail and carry a session status. */
    private static function isGuidedType(string $meetingType): bool
    {
        return in_array($meetingType, [self::TYPE_REVIEW, self::TYPE_RETROSPECTIVE], true);
    }

    /**
     * One card of the meetings tab ("Upcoming" or "Past"). In the upcoming
     * card the first row is the next meeting and gets the primary button.
     */
    private static function renderMeetingCard(string $title, string $icon, array $rows, bool $canedit, bool $isUpcoming): void
    {
        $types     = self::getAllTypes();
        $typeIcons = [
            self::TYPE_KICKOFF       => 'fas fa-rocket',
            self::TYPE_STANDUP       => 'fas fa-coffee',
            self::TYPE_REVIEW        => 'fas fa-clipboard-check',
            self::TYPE_RETROSPECTIVE => 'fas fa-lightbulb',
        ];
        $statusChips = [
            'open'        => ['sprint-chip', __('Not started', 'sprint')],
            'in_progress' => ['sprint-chip sprint-chip-info', __('In progress', 'sprint')],
            'completed'   => ['sprint-chip sprint-chip-success', __('Completed', 'sprint')],
        ];
        $today = self::today();

        echo "<div class='card mb-3 sprint-meeting-list'>";
        echo "<div class='card-header'><h3 class='card-title'>"
            . "<i class='" . $icon . " me-2' aria-hidden='true'></i>" . htmlescape($title)
            . " <span class='sprint-chip ms-1'>" . count($rows) . "</span></h3></div>";

        if (count($rows) === 0) {
            echo "<div class='card-body text-muted'>" . __s('No upcoming meetings', 'sprint') . "</div></div>";
            return;
        }

        echo "<div class='table-responsive'><table class='table table-vcenter card-table mb-0'>";
        echo "<thead><tr>";
        echo "<th>" . __s('Title') . "</th>";
        echo "<th>" . __s('Type', 'sprint') . "</th>";
        echo "<th>" . __s('Date') . "</th>";
        echo "<th>" . __s('Duration', 'sprint') . "</th>";
        echo "<th>" . __s('Facilitator', 'sprint') . "</th>";
        echo "<th>" . __s('Status') . "</th>";
        echo "<th class='text-end'><span class='visually-hidden'>" . __s('Actions') . "</span></th>";
        echo "</tr></thead><tbody>";

        $first = true;
        foreach ($rows as $row) {
            $isNext   = $isUpcoming && $first;
            $first    = false;
            $rowIcon  = $typeIcons[$row['meeting_type']] ?? 'fas fa-calendar';
            $name     = (string)$row['name'];
            $nameHtml = htmlescape($name);
            $url      = static::getFormURLWithID($row['id']);
            $isToday  = substr((string)$row['date_meeting'], 0, 10) === $today;

            echo "<tr" . ($isNext ? " class='sprint-meeting-next'" : '') . ">";
            echo "<td><a href='" . $url . "'>" . $nameHtml . "</a>";
            if ($isNext) {
                echo " <span class='sprint-chip sprint-chip-info ms-1'>" . __s('Next meeting', 'sprint') . "</span>";
            }
            echo "</td>";
            echo "<td><i class='" . $rowIcon . " me-1' aria-hidden='true'></i>"
                . htmlescape((string)($types[$row['meeting_type']] ?? $row['meeting_type'])) . "</td>";
            echo "<td>" . Html::convDateTime($row['date_meeting']);
            if ($isToday) {
                echo " <span class='sprint-chip sprint-chip-orange ms-1'>" . __s('Today', 'sprint') . "</span>";
            }
            echo "</td>";
            echo "<td>" . (int)$row['duration_minutes'] . " " . __s('min', 'sprint') . "</td>";
            echo "<td>" . htmlescape(SprintCache::userName($row['users_id'])) . "</td>";
            echo "<td>";
            if (self::isGuidedType((string)$row['meeting_type'])) {
                $chip = $statusChips[(string)($row['meeting_status'] ?? 'open')] ?? $statusChips['open'];
                echo "<span class='" . $chip[0] . "'>" . htmlescape($chip[1]) . "</span>";
            }
            echo "</td>";

            echo "<td class='text-end sprint-nowrap'>";
            if ($isNext) {
                echo "<a href='" . $url . "' class='btn btn-sm btn-primary'>"
                    . "<i class='fas fa-arrow-right me-1' aria-hidden='true'></i>" . __s('Open meeting', 'sprint') . "</a>";
            } elseif ($canedit) {
                $editLabel = htmlescape(sprintf(__('Edit "%s"', 'sprint'), $name));
                echo "<a href='" . $url . "' class='btn btn-sm btn-outline-secondary' "
                    . "title='" . $editLabel . "' aria-label='" . $editLabel . "'>"
                    . "<i class='fas fa-pen' aria-hidden='true'></i></a>";
            }
            if ($canedit) {
                // Destructive action last, confirmed in-page (see renderMeetingsTabScript()).
                $deleteLabel = htmlescape(sprintf(__('Delete "%s"', 'sprint'), $name));
                echo "<form method='post' action='" . static::getFormURL() . "' class='d-inline-block ms-2'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::hidden('purge', ['value' => 1]);
                echo "<button type='button' class='btn btn-sm btn-outline-danger sprint-meeting-delete-btn' "
                    . "title='" . $deleteLabel . "' aria-label='" . $deleteLabel . "' "
                    . "data-confirm='" . htmlescape(sprintf(__('Delete the meeting "%s"? Its notes are deleted too.', 'sprint'), $name)) . "'>"
                    . "<i class='fas fa-trash' aria-hidden='true'></i></button>";
                Html::closeForm();
            }
            echo "</td></tr>";
        }

        echo "</tbody></table></div></div>";
    }

    /**
     * Page-wide handlers of the meetings tab, bound once: the add form's title
     * and duration follow the chosen type, and delete asks through the shared
     * in-page confirm.
     */
    private static function renderMeetingsTabScript(): void
    {
        echo <<<HTML
<script>
(function() {
    if (window.__sprintMeetingsTabBound) { return; }
    window.__sprintMeetingsTabBound = true;

    jQuery(document).on('change', '.sprint-meeting-add-form select[name=meeting_type]', function() {
        var form = jQuery(this).closest('form');
        var defaults = form.data('type-defaults') || {};
        var d = defaults[String(jQuery(this).val())];
        if (!d) { return; }
        form.find('select[name=duration_minutes]').val(String(d.minutes)).trigger('change');
        // The title follows the type until someone typed their own.
        var title = form.find('input[name=name]');
        var current = String(title.val() || '');
        if (current === '' || current === String(title.attr('data-auto') || '')) {
            title.val(d.title);
        }
        title.attr('data-auto', d.title);
    });

    jQuery(document).on('click', '.sprint-meeting-delete-btn', function(e) {
        e.preventDefault();
        var btn  = jQuery(this);
        var form = btn.closest('form')[0];
        var msg  = String(btn.attr('data-confirm') || '');
        if (!form) { return; }
        var go = function() { form.submit(); };
        if (typeof window.sprintConfirm === 'function') {
            window.sprintConfirm(msg, go, { danger: true });
        } else if (window.confirm(msg)) {
            go();
        }
    });
})();
</script>
HTML;
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
                    'title'       => __('Follow-up of earlier actions', 'sprint'),
                    'description' => __('Go through the actions agreed in the previous retrospective and those still open from earlier ones: what was tried, what is stuck, what is the next concrete step — and did completed changes help?', 'sprint'),
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
            // Follow-up of earlier actions: predecessor in the sprint series,
            // its agreed actions and older still-open ones (one identity each).
            $followUp = SprintAgility::getRetroFollowUp($this->fields);
            $isMember    = SprintAgility::isCurrentUserMember($sprintId);
            $canFollowUp = $isScrumMaster || ($isFacilitator && $isMember);
            foreach (['previous', 'older'] as $group) {
                foreach ($followUp[$group] as &$fuRow) {
                    $fuRow['can_edit'] = $canFollowUp || ($isMember && $uid > 0 && (int)$fuRow['users_id'] === $uid);
                }
                unset($fuRow);
            }
            $predecessor = $followUp['predecessor'];
            unset($followUp['predecessor']);
            $followUp['predecessor_name'] = $predecessor ? (string)$predecessor['name'] : '';
            $followUp['predecessor_url']  = $predecessor ? Sprint::getFormURLWithID((int)$predecessor['id']) : '';
            $followUp['chosen_id']        = ($predecessor && $followUp['manual']) ? (int)$predecessor['id'] : 0;
            $guided['followup']        = $followUp;
            $guided['followup_helped'] = SprintAgility::helpedOptions();

            // Previous sprint's velocity, for the comparison card: the series
            // predecessor when known, else the last completed sprint of the entity.
            $prevVelocity = null;
            $prevSprint   = $predecessor;
            if ($sprintLoaded && $prevSprint === null) {
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
            if ($prevVelocity !== null) {
                $statCards[] = [
                    'label'  => sprintf(__('Previous sprint (%s)', 'sprint'), (string)$prevSprint['name']),
                    'value'  => $prevVelocity . ' SP',
                    'icon'   => 'fas fa-history',
                    'accent' => '#fd7e14',
                ];
            }
            $guided['stat_cards'] = $statCards;

            $guided['improvements']           = SprintAgility::getImprovementsForSprint($sprintId);
            $guided['improvement_categories'] = SprintAgility::improvementCategories();
            // Dot-voting: a fixed number of votes per member, none on your own
            // input. Once voting closed the top themes are actions and the
            // remaining input is greyed out (it returns in the next retro).
            $guided['votes_per_member'] = Config::getRetroVotesPerMember();
            $guided['votes_used']       = SprintAgility::votesUsed($sprintId, $uid);
            $guided['inputs_handled']   = SprintAgility::inputsHandled($sprintId);
            $guided['top_actions']      = SprintAgility::RETRO_TOP_ACTIONS;
            $guided['prev_inputs']      = $predecessor ? SprintAgility::getUnhandledInputs((int)$predecessor['id']) : [];
            $guided['member_options']   = $memberOptions;
        }

        return $guided;
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        SprintI18n::render();

        $sprintId = (int)($this->fields['plugin_sprint_sprints_id'] ?? 0);

        // "Back to Sprint" button linking to the parent sprint's Meetings tab
        if ($sprintId > 0) {
            $sprintUrl = Sprint::getFormURLWithID($sprintId) . '&forcetab=' . urlencode('GlpiPlugin\\Sprint\\SprintMeeting$1');
            echo "<div class='mb-2'>";
            echo "<a href='$sprintUrl' class='btn btn-outline-secondary'>";
            echo "<i class='fas fa-arrow-left me-1' aria-hidden='true'></i> " . __('Back to Sprint', 'sprint');
            echo "</a></div>";
        }
        $memberOptions = SprintMember::getSprintMemberOptions($sprintId);
        $isExisting = ($ID > 0);

        // Build sprint items data for the review table
        $sprintItemsData = [];
        $showCredits     = SprintCustomer::canUseCredits();
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
                    'customer_id'          => $showCredits ? (int)($row['plugin_sprint_sprintcustomers_id'] ?? 0) : 0,
                    'customer_name'        => $showCredits ? SprintCustomer::getNameFor((int)($row['plugin_sprint_sprintcustomers_id'] ?? 0)) : '',
                    'credit_product_id'    => $showCredits ? (int)($row['plugin_sprint_sprintcreditproducts_id'] ?? 0) : 0,
                    'credits'              => $showCredits ? SprintCustomer::formatCredits($row['credits'] ?? 0) : '0',
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
                    'category_id'          => (int)($row['plugin_sprint_sprintcategories_id'] ?? 0),
                    'category_name'        => SprintCategory::getFullNameFor((int)($row['plugin_sprint_sprintcategories_id'] ?? 0)),
                    'category_pill_html'   => SprintCategory::renderPill((int)($row['plugin_sprint_sprintcategories_id'] ?? 0)),
                    // Same shape as SprintItem::buildRowDataAttrs(): the shared
                    // quick-edit dialog shows the DoD checks and the epic.
                    'done_checks'          => implode('|', SprintAgility::checklist((string)($row['done_checks'] ?? ''))),
                    'epic_id'              => (int)($row['plugin_sprint_sprintepics_id'] ?? 0),
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
                'can_view_credits'  => SprintCustomer::canUseCredits(),
                'can_edit_credits'  => SprintCustomer::canUseCredits(),
                'customer_label'    => SprintCustomer::getTypeName(1),
                'customers'         => array_map(
                    static fn($c) => (string)$c['name'],
                    SprintCustomer::getAll()
                ),
                'credit_product_label' => \GlpiPlugin\Sprint\SprintCreditProduct::getTypeName(1),
                'credit_products'   => \GlpiPlugin\Sprint\SprintCreditProduct::pickerRows(),
                'backlog_url'       => \GlpiPlugin\Sprint\Backlog::getFormURL(),
                'meeting_url'       => static::getFormURLWithID($ID),
                'meeting_id'        => $ID,
                'sprint_id'         => $sprintId,
                'move_target_sprints' => $carryOverTargetSprints,
                'capacity_locked'   => \GlpiPlugin\Sprint\Config::isScrumMasterOnlyCapacity()
                    && !SprintItem::currentUserIsScrumMasterOf($sprintId),
                'is_scrum_master'   => SprintItem::currentUserIsScrumMasterOf($sprintId),
                // Category is Scrum Master territory inside a sprint; everyone
                // else raises an approval request (see SprintRequest).
                'categories'        => SprintCategory::getAll(),
                'category_locked'   => $sprintId > 0 && !SprintItem::currentUserIsScrumMasterOf($sprintId),
                'defined_tags'      => \GlpiPlugin\Sprint\Config::getDefinedTags(),
                'tag_required'      => \GlpiPlugin\Sprint\Config::isTagRequired(),
                'guided'            => $guided,
            ]
        );
        // Shared item dialogs (same as board/dashboard); the quick-edit one
        // also renders the carry-over split dialog.
        if ($isExisting && $sprintId > 0) {
            SprintItem::renderQuickEditUI($sprintId, (int)$ID);
            SprintItem::renderBackToBacklogUI();
            // Definition of Done checklist and "linked item still open"
            // dialogs behind window.sprintStatusChange() (row "Mark as done").
            if (method_exists(SprintItem::class, 'renderStatusGateUI')) {
                SprintItem::renderStatusGateUI();
            }
        }
        SprintItem::renderLinkedQuickEditUI();
        SprintItem::renderCarrySplitModalUI();

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
