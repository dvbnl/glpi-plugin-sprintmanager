<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use CommonGLPI;
use Html;
use Session;
use Dropdown;

/**
 * SprintTemplateMeeting - Default meeting schedule for a sprint template
 *
 * Defines ceremonies like kickoff, standups (with interval), review, and retrospective.
 */
class SprintTemplateMeeting extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_sprint';

    // Schedule types
    const SCHEDULE_FIRST_DAY      = 'first_day';
    const SCHEDULE_LAST_DAY       = 'last_day';
    const SCHEDULE_DAY_BEFORE_END = 'day_before_end';
    const SCHEDULE_INTERVAL       = 'interval';

    public static function getTypeName($nb = 0): string
    {
        return _n('Template Meeting', 'Template Meetings', $nb, 'sprint');
    }

    public static function getAllScheduleTypes(): array
    {
        return [
            self::SCHEDULE_FIRST_DAY      => __('First day of sprint', 'sprint'),
            self::SCHEDULE_LAST_DAY       => __('Last day of sprint', 'sprint'),
            self::SCHEDULE_DAY_BEFORE_END => __('Day before end', 'sprint'),
            self::SCHEDULE_INTERVAL       => __('Recurring interval', 'sprint'),
        ];
    }

    public function prepareInputForAdd($input)
    {
        if (isset($input['plugin_sprint_sprinttemplates_id'])) $input['plugin_sprint_sprinttemplates_id'] = (int)$input['plugin_sprint_sprinttemplates_id'];
        if (isset($input['duration_minutes']))  $input['duration_minutes']  = max(5, min(480, (int)$input['duration_minutes']));
        if (isset($input['interval_days']))     $input['interval_days']     = max(1, min(14, (int)$input['interval_days']));
        if (isset($input['is_optional']))       $input['is_optional']       = (int)(bool)$input['is_optional'];
        if (isset($input['skip_weekends']))     $input['skip_weekends']     = (int)(bool)$input['skip_weekends'];
        if (isset($input['meeting_type']) && !array_key_exists($input['meeting_type'], SprintMeeting::getAllTypes())) {
            $input['meeting_type'] = SprintMeeting::TYPE_STANDUP;
        }
        if (isset($input['schedule_type']) && !array_key_exists($input['schedule_type'], self::getAllScheduleTypes())) {
            $input['schedule_type'] = self::SCHEDULE_FIRST_DAY;
        }
        return parent::prepareInputForAdd($input);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof SprintTemplate) {
            $count = countElementsInTable(
                self::getTable(),
                ['plugin_sprint_sprinttemplates_id' => $item->getID()]
            );
            return self::createTabEntry(__('Meetings', 'sprint'), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof SprintTemplate) {
            self::showForTemplate($item);
            return true;
        }
        return false;
    }

    public static function showForTemplate(SprintTemplate $template): void
    {
        $ID      = $template->getID();
        $canedit = SprintTemplate::canUpdate();

        $meetingTypes  = SprintMeeting::getAllTypes();
        $scheduleTypes = self::getAllScheduleTypes();

        if ($canedit) {
            echo "<div class='center'>";
            echo "<form method='post' action='" . static::getFormURL() . "'>";
            echo Html::hidden('plugin_sprint_sprinttemplates_id', ['value' => $ID]);

            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_2'><th colspan='8'>" .
                __('Add a meeting to the schedule', 'sprint') . "</th></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Ceremony', 'sprint') . "</td>";
            echo "<td>";
            $rand = mt_rand();
            Dropdown::showFromArray('meeting_type', $meetingTypes, [
                'value' => SprintMeeting::TYPE_STANDUP,
                'rand'  => $rand,
            ]);
            echo "</td>";
            echo "<td>" . __('Schedule', 'sprint') . "</td>";
            echo "<td>";
            $randSchedule = mt_rand();
            Dropdown::showFromArray('schedule_type', $scheduleTypes, [
                'value' => self::SCHEDULE_FIRST_DAY,
                'rand'  => $randSchedule,
            ]);
            echo "</td>";
            echo "<td><span id='interval_label_{$randSchedule}'>" .
                __('Interval (days)', 'sprint') . "</span></td>";
            echo "<td>";
            echo "<span id='interval_field_{$randSchedule}'>";
            Dropdown::showNumber('interval_days', [
                'value' => 2, 'min' => 1, 'max' => 14,
            ]);
            echo "</span>";
            echo "</td>";
            echo "<td>" . __('Duration (min)', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showNumber('duration_minutes', [
                'value' => 15, 'min' => 5, 'max' => 240, 'step' => 5,
            ]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Name') . "</td>";
            echo "<td colspan='3'>";
            echo Html::input('name', ['size' => 40, 'id' => 'tmpl_meeting_name_' . $rand]);
            echo "</td>";
            echo "<td>" . __('Optional', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showYesNo('is_optional', 0);
            echo "</td>";
            echo "<td>" . __('Skip weekends', 'sprint') . "</td>";
            echo "<td id='skip_weekends_field_{$randSchedule}'>";
            Dropdown::showYesNo('skip_weekends', 1);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td colspan='8' class='center'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";

            echo "</table>";
            Html::closeForm();
            echo "</div>";

            // JS: show/hide interval field, auto-fill name
            echo "<script>
            $(function() {
                var schedSel = $('select[id^=\"dropdown_schedule_type\"]').last();
                var intLabel = $('#interval_label_{$randSchedule}');
                var intField = $('#interval_field_{$randSchedule}');

                var skipField = $('#skip_weekends_field_{$randSchedule}').closest('td');
                var skipLabel = skipField.prev('td');

                function toggleFields() {
                    var val = schedSel.val();
                    var isInterval = val === 'interval';
                    var showSkip = val === 'interval' || val === 'day_before_end' || val === 'last_day';
                    intLabel.toggle(isInterval);
                    intField.toggle(isInterval);
                    skipField.toggle(showSkip);
                    skipLabel.toggle(showSkip);
                }
                schedSel.on('change', toggleFields);
                toggleFields();

                // Auto-fill name from ceremony type
                var typeSel = $('select[id^=\"dropdown_meeting_type\"]').last();
                var nameField = $('#tmpl_meeting_name_{$rand}');
                var typeNames = " . json_encode($meetingTypes) . ";
                typeSel.on('change', function() {
                    if (!nameField.val() || nameField.data('autofilled')) {
                        nameField.val(typeNames[$(this).val()] || '');
                        nameField.data('autofilled', true);
                    }
                });
                nameField.on('input', function() {
                    nameField.data('autofilled', false);
                });
            });
            </script>";
        }

        // List existing meeting templates
        $item  = new self();
        $items = $item->find(
            ['plugin_sprint_sprinttemplates_id' => $ID],
            ['sort_order ASC']
        );

        echo "<div class='center'><table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Ceremony', 'sprint') . "</th>";
        echo "<th>" . __('Name') . "</th>";
        echo "<th>" . __('Schedule', 'sprint') . "</th>";
        echo "<th>" . __('Interval', 'sprint') . "</th>";
        echo "<th>" . __('Duration (min)', 'sprint') . "</th>";
        echo "<th>" . __('Optional', 'sprint') . "</th>";
        echo "<th>" . __('Skip weekends', 'sprint') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($items) === 0) {
            $cols = $canedit ? 8 : 7;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('No meetings in schedule', 'sprint') . "</td></tr>";
        }

        $typeIcons = [
            SprintMeeting::TYPE_KICKOFF       => 'fas fa-flag',
            SprintMeeting::TYPE_STANDUP       => 'fas fa-comments',
            SprintMeeting::TYPE_REVIEW        => 'fas fa-search',
            SprintMeeting::TYPE_RETROSPECTIVE => 'fas fa-sync-alt',
        ];

        foreach ($items as $row) {
            $icon = $typeIcons[$row['meeting_type']] ?? 'fas fa-calendar';
            $intervalDisplay = '-';
            if ($row['schedule_type'] === self::SCHEDULE_INTERVAL) {
                $intervalDisplay = sprintf(
                    __('Every %d days', 'sprint'),
                    (int)$row['interval_days']
                );
            }

            echo "<tr class='tab_bg_1'>";
            echo "<td><i class='{$icon} me-1'></i>" .
                ($meetingTypes[$row['meeting_type']] ?? $row['meeting_type']) . "</td>";
            echo "<td>" . htmlescape($row['name']) . "</td>";
            echo "<td>" . ($scheduleTypes[$row['schedule_type']] ?? $row['schedule_type']) . "</td>";
            echo "<td>" . $intervalDisplay . "</td>";
            echo "<td class='center'>" . (int)$row['duration_minutes'] . " min</td>";
            echo "<td class='center'>" . ((int)$row['is_optional']
                ? '<i class="fas fa-check text-muted"></i>'
                : '') . "</td>";
            echo "<td class='center'>" . ((int)($row['skip_weekends'] ?? 0)
                ? '<i class="fas fa-check text-muted"></i>'
                : '') . "</td>";
            if ($canedit) {
                echo "<td class='center'>";
                echo "<form method='post' action='" . static::getFormURL() . "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::submit(__('Delete'), [
                    'name'    => 'purge',
                    'class'   => 'btn btn-sm btn-outline-danger',
                    'confirm' => __('Remove this meeting?', 'sprint'),
                ]);
                Html::closeForm();
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</table></div>";
    }

    /**
     * Generate actual meetings for a sprint based on template meeting schedule
     */
    public static function applyToSprint(int $templateId, int $sprintId): void
    {
        $sprint = new Sprint();
        if (!$sprint->getFromDB($sprintId)) {
            return;
        }

        $dateStart = $sprint->fields['date_start'] ?? '';
        $dateEnd   = $sprint->fields['date_end'] ?? '';
        if (empty($dateStart)) {
            return;
        }

        $start = new \DateTime($dateStart);
        $end   = !empty($dateEnd) ? new \DateTime($dateEnd) : null;

        // Determine the scrum master as default facilitator
        $facilitator = (int)($sprint->fields['users_id'] ?? 0);

        $tmplMeeting = new self();
        $meetings = $tmplMeeting->find(
            ['plugin_sprint_sprinttemplates_id' => $templateId],
            ['sort_order ASC']
        );

        // Two-pass scheduling so recurring standups can be excluded from
        // days that already host a fixed ceremony (review / retrospective /
        // kickoff). First pass: collect dates for all non-interval meetings
        // and remember which calendar days are "reserved" by them.
        $reservedDays = [];
        $planned      = []; // [['row' => ..., 'dates' => [...]], ...]

        foreach ($meetings as $row) {
            if ($row['schedule_type'] === self::SCHEDULE_INTERVAL) {
                continue;
            }
            $skipWeekends = (bool)($row['skip_weekends'] ?? false);
            $dates = self::calculateMeetingDates(
                $row['schedule_type'],
                (int)$row['interval_days'],
                $start,
                $end,
                $skipWeekends
            );
            foreach ($dates as $d) {
                $reservedDays[$d->format('Y-m-d')] = true;
            }
            $planned[] = ['row' => $row, 'dates' => $dates];
        }

        // Second pass: interval meetings, dropping any occurrence whose
        // date collides with a fixed ceremony day.
        foreach ($meetings as $row) {
            if ($row['schedule_type'] !== self::SCHEDULE_INTERVAL) {
                continue;
            }
            $skipWeekends = (bool)($row['skip_weekends'] ?? false);
            $dates = self::calculateMeetingDates(
                $row['schedule_type'],
                (int)$row['interval_days'],
                $start,
                $end,
                $skipWeekends
            );
            $filtered = [];
            foreach ($dates as $d) {
                if (!isset($reservedDays[$d->format('Y-m-d')])) {
                    $filtered[] = $d;
                }
            }
            $planned[] = ['row' => $row, 'dates' => $filtered];
        }

        foreach ($planned as $entry) {
            $row = $entry['row'];
            foreach ($entry['dates'] as $meetingDate) {
                $meeting = new SprintMeeting();
                $meeting->add([
                    'plugin_sprint_sprints_id' => $sprintId,
                    'name'                     => $row['name'],
                    'meeting_type'             => $row['meeting_type'],
                    'date_meeting'             => $meetingDate->format('Y-m-d H:i:s'),
                    'duration_minutes'         => $row['duration_minutes'],
                    'users_id'                 => $facilitator,
                ]);
            }
        }
    }

    /**
     * Calculate meeting dates based on schedule type
     *
     * @return \DateTime[]
     */
    private static function calculateMeetingDates(
        string $scheduleType,
        int $intervalDays,
        \DateTime $start,
        ?\DateTime $end,
        bool $skipWeekends = false
    ): array {
        $dates = [];

        switch ($scheduleType) {
            case self::SCHEDULE_FIRST_DAY:
                $dates[] = clone $start;
                break;

            case self::SCHEDULE_LAST_DAY:
                if ($end) {
                    $d = clone $end;
                    if ($skipWeekends) {
                        // Snap backwards: a Sun-ending sprint must put the
                        // ceremony on Fri *inside* the sprint, not Mon of
                        // the next sprint.
                        $d = self::skipToWeekday($d, 'backward');
                    }
                    $dates[] = $d;
                }
                break;

            case self::SCHEDULE_DAY_BEFORE_END:
                if ($end) {
                    $d = clone $end;
                    $d->modify('-1 day');
                    if ($skipWeekends) {
                        $d = self::skipToWeekday($d, 'backward');
                    }
                    $dates[] = $d;
                }
                break;

            case self::SCHEDULE_INTERVAL:
                if (!$end || $intervalDays < 1) {
                    break;
                }
                $current = clone $start;
                $current->modify("+{$intervalDays} days");
                while ($current <= $end) {
                    $d = clone $current;
                    if ($skipWeekends) {
                        $d = self::skipToWeekday($d);
                    }
                    $dates[] = $d;
                    $current->modify("+{$intervalDays} days");
                }
                break;
        }

        // Hard guarantee: every meeting date must fall within the sprint
        // window [start, end]. Any case above (forward weekend snap, future
        // schedule types, ...) that drifts outside the window is silently
        // dropped here so the rule lives in one place.
        return array_values(array_filter(
            $dates,
            static function (\DateTime $d) use ($start, $end): bool {
                if ($d < $start) {
                    return false;
                }
                if ($end !== null && $d > $end) {
                    return false;
                }
                return true;
            }
        ));
    }

    /**
     * If the date falls on a weekend, snap to the nearest weekday.
     *
     * Direction matters: end-of-sprint ceremonies (review/retrospective)
     * must snap *backwards* to Friday so they stay inside the sprint
     * window — snapping forward would land on Monday of the next sprint.
     * Recurring standups snap *forwards* to the next working day.
     */
    private static function skipToWeekday(\DateTime $date, string $direction = 'forward'): \DateTime
    {
        $dow = (int)$date->format('N'); // 1=Mon ... 7=Sun
        if ($direction === 'backward') {
            if ($dow === 6) {
                $date->modify('-1 day');  // Sat → Fri
            } elseif ($dow === 7) {
                $date->modify('-2 days'); // Sun → Fri
            }
        } else {
            if ($dow === 6) {
                $date->modify('+2 days'); // Sat → Mon
            } elseif ($dow === 7) {
                $date->modify('+1 day');  // Sun → Mon
            }
        }
        return $date;
    }
}
