<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use Html;
use Dropdown;

/**
 * SprintTemplate - Blueprint for creating sprints with pre-defined settings
 */
class SprintTemplate extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_sprint';
    public $dohistory        = true;

    public static function getTypeName($nb = 0): string
    {
        return _n('Sprint Template', 'Sprint Templates', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-clone';
    }

    public static function getMenuContent(): array
    {
        $menu = [
            'title' => self::getTypeName(2),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];

        if (self::canCreate()) {
            $menu['links']['add'] = self::getFormURL(false);
        }
        if (self::canView()) {
            $menu['links']['search'] = self::getSearchURL(false);
        }

        return $menu;
    }

    public function rawSearchOptions(): array
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'name',
            'name'     => __('Name'),
            'datatype' => 'itemlink',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'name_pattern',
            'name'     => __('Name pattern', 'sprint'),
            'datatype' => 'string',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'duration_weeks',
            'name'     => __('Duration (weeks)', 'sprint'),
            'datatype' => 'integer',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'is_active',
            'name'     => __('Active'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => 19,
            'table'    => $this->getTable(),
            'field'    => 'date_mod',
            'name'     => __('Last update'),
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'       => 121,
            'table'    => $this->getTable(),
            'field'    => 'date_creation',
            'name'     => __('Creation date'),
            'datatype' => 'datetime',
        ];

        return $tab;
    }

    public function defineTabs($options = []): array
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab('GlpiPlugin\Sprint\SprintTemplateMember', $ong, $options);
        $this->addStandardTab('GlpiPlugin\Sprint\SprintTemplateItem', $ong, $options);
        $this->addStandardTab('GlpiPlugin\Sprint\SprintTemplateMeeting', $ong, $options);
        $this->addStandardTab('Log', $ong, $options);
        return $ong;
    }

    public function prepareInputForAdd($input)
    {
        if (isset($input['fastlane_capacity'])) {
            $input['fastlane_capacity'] = SprintMember::normalizeCapacity($input['fastlane_capacity']);
        }
        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['fastlane_capacity'])) {
            $input['fastlane_capacity'] = SprintMember::normalizeCapacity($input['fastlane_capacity']);
        }
        return parent::prepareInputForUpdate($input);
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        if (class_exists('Glpi\Application\View\TemplateRenderer')) {
            \Glpi\Application\View\TemplateRenderer::getInstance()->display(
                '@sprint/sprinttemplate.form.html.twig',
                [
                    'item'   => $this,
                    'params' => $options,
                ]
            );
        } else {
            $this->showFormHeader($options);

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Name') . "</td>";
            echo "<td>" . Html::input('name', ['value' => $this->fields['name'] ?? '', 'size' => 40]) . "</td>";
            echo "<td>" . __('Active') . "</td>";
            echo "<td>";
            Dropdown::showYesNo('is_active', $this->fields['is_active'] ?? 1);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Name pattern', 'sprint') . "</td>";
            echo "<td>" . Html::input('name_pattern', [
                'value' => $this->fields['name_pattern'] ?? '',
                'size'  => 40,
            ]) . "</td>";
            echo "<td>" . __('Duration (weeks)', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showNumber('duration_weeks', [
                'value' => $this->fields['duration_weeks'] ?? 2,
                'min' => 1, 'max' => 8,
            ]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Sprint goal', 'sprint') . "</td>";
            echo "<td colspan='3'><textarea name='goal' rows='4' cols='80'>" .
                htmlescape($this->fields['goal'] ?? '') . "</textarea></td></tr>";

            echo "<tr class='tab_bg_1'><td>" . __('WIP limits: progress / review / dependency', 'sprint') . "</td><td><input type='number' min='0' name='wip_in_progress' value='" . (int)($this->fields['wip_in_progress'] ?? 0) . "'> / <input type='number' min='0' name='wip_review' value='" . (int)($this->fields['wip_review'] ?? 0) . "'> / <input type='number' min='0' name='wip_dependency' value='" . (int)($this->fields['wip_dependency'] ?? 0) . "'></td><td>" . __('Enforce WIP limits', 'sprint') . "</td><td>"; Dropdown::showYesNo('wip_hard', $this->fields['wip_hard'] ?? 0); echo '</td></tr>';

            echo "<tr class='tab_bg_1'><td>" . __('Fastlane capacity cap (%)', 'sprint') . "</td><td><input type='number' min='0' max='100' step='.5' name='fastlane_capacity' value='" . htmlescape((string)($this->fields['fastlane_capacity'] ?? 0)) . "'></td><td colspan='2'></td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Comments') . "</td>";
            echo "<td colspan='3'><textarea name='comment' rows='4' cols='80'>" .
                htmlescape($this->fields['comment'] ?? '') . "</textarea></td></tr>";

            $this->showFormButtons($options);
        }

        $this->renderSummary();

        return true;
    }

    /**
     * Get template data for AJAX pre-fill, plus a translated summary of what
     * applying the template adds (shown on the sprint form before saving).
     */
    public function getTemplateData(): array
    {
        $templateId = $this->getID();
        $summary    = self::summarize($templateId, $this->fields);

        return [
            'name_pattern'    => $this->fields['name_pattern'] ?? '',
            'duration_weeks'  => (int)($this->fields['duration_weeks'] ?? 2),
            'goal'            => $this->fields['goal'] ?? '',
            'comment'         => $this->fields['comment'] ?? '',
            'members_count'   => $summary['members'],
            'items_count'     => $summary['items'],
            'meetings_count'  => $summary['meetings'],
            'summary_lines'   => $summary['lines'],
        ];
    }

    /**
     * What a template adds to a sprint, as counts and short translated lines
     * ("3 team members: Ann, Bob, Carl", "5 items · 21 story points", ...).
     *
     * @return array{members:int,items:int,meetings:int,leave:int,lines:string[]}
     */
    public static function summarize(int $templateId, array $fields): array
    {
        $lines = [];

        $members = (new SprintTemplateMember())->find(['plugin_sprint_sprinttemplates_id' => $templateId]);
        if ($members) {
            $names = [];
            foreach ($members as $row) {
                $names[] = SprintCache::userName((int)$row['users_id'])
                    . ' (' . SprintMember::formatCapacity($row['capacity_percent'] ?? 0) . '%)';
            }
            $shown = array_slice($names, 0, 6);
            $more  = count($names) - count($shown);
            $lines[] = sprintf(_n('%d team member', '%d team members', count($members), 'sprint'), count($members))
                . ': ' . implode(', ', $shown) . ($more > 0 ? ' ' . sprintf(__('and %d more', 'sprint'), $more) : '');
        }

        $items = (new SprintTemplateItem())->find(['plugin_sprint_sprinttemplates_id' => $templateId]);
        if ($items) {
            $points = 0;
            foreach ($items as $row) {
                $points += max(0, (int)($row['story_points'] ?? 0));
            }
            $lines[] = sprintf(_n('%d item', '%d items', count($items), 'sprint'), count($items))
                . ' · ' . sprintf(_n('%d story point', '%d story points', $points, 'sprint'), $points);
        }

        $meetings = (new SprintTemplateMeeting())->find(['plugin_sprint_sprinttemplates_id' => $templateId], ['sort_order ASC']);
        if ($meetings) {
            $schedules = SprintTemplateMeeting::getAllScheduleTypes();
            $parts = [];
            foreach ($meetings as $row) {
                $when = ($row['schedule_type'] ?? '') === SprintTemplateMeeting::SCHEDULE_INTERVAL
                    ? sprintf(__('every %d days', 'sprint'), (int)$row['interval_days'])
                    : mb_strtolower((string)($schedules[$row['schedule_type']] ?? $row['schedule_type']));
                $parts[] = (string)$row['name'] . ' (' . $when . ')';
            }
            $lines[] = sprintf(_n('%d meeting schedule', '%d meeting schedules', count($meetings), 'sprint'), count($meetings))
                . ': ' . implode(', ', $parts);
        }

        $leave = countElementsInTable(SprintTemplateAvailability::getTable(), ['plugin_sprint_sprinttemplates_id' => $templateId]);
        if ($leave > 0) {
            $lines[] = sprintf(_n('%d fixed weekly leave rule', '%d fixed weekly leave rules', $leave, 'sprint'), $leave);
        }

        $wip = array_filter([
            (int)($fields['wip_in_progress'] ?? 0),
            (int)($fields['wip_review'] ?? 0),
            (int)($fields['wip_dependency'] ?? 0),
        ]);
        if ($wip) {
            $lines[] = sprintf(
                __('WIP limits: %1$d in progress / %2$d review / %3$d dependency', 'sprint'),
                (int)($fields['wip_in_progress'] ?? 0),
                (int)($fields['wip_review'] ?? 0),
                (int)($fields['wip_dependency'] ?? 0)
            ) . ((int)($fields['wip_hard'] ?? 0) === 1 ? ' (' . __('enforced', 'sprint') . ')' : '');
        }
        if ((float)($fields['fastlane_capacity'] ?? 0) > 0) {
            $lines[] = sprintf(__('Fastlane cap: %s%%', 'sprint'), SprintMember::formatCapacity($fields['fastlane_capacity']));
        }

        return [
            'members'  => count($members),
            'items'    => count($items),
            'meetings' => count($meetings),
            'leave'    => $leave,
            'lines'    => $lines,
        ];
    }

    /** "This template adds ..." card below the template's main form. */
    private function renderSummary(): void
    {
        $id = (int)$this->getID();
        if ($id <= 0) {
            return;
        }
        $summary = self::summarize($id, $this->fields);
        $url     = static fn(string $tab) => self::getFormURLWithID($id) . '&forcetab=' . rawurlencode($tab . '$1');

        echo "<div class='card mt-3 sprint-template-overview' style='max-width:1200px;margin:18px auto 0;'>";
        echo "<div class='card-header'><h3 class='card-title m-0'><i class='fas fa-clipboard-check me-2' aria-hidden='true'></i>"
            . __s('What this template adds to a new sprint', 'sprint') . "</h3></div>";
        echo "<div class='card-body'>";
        if ($summary['lines']) {
            echo "<ul class='mb-2'>";
            foreach ($summary['lines'] as $line) {
                echo "<li>" . htmlescape($line) . "</li>";
            }
            echo "</ul>";
        } else {
            echo "<p class='text-muted mb-2'>" . __s('Nothing yet: add team members, items or meetings in the tabs above.', 'sprint') . "</p>";
        }
        echo "<div class='d-flex flex-wrap gap-2 sprint-small'>";
        foreach ([
            [SprintTemplateMember::class, __('Edit team', 'sprint'), 'fas fa-users'],
            [SprintTemplateItem::class, __('Edit items', 'sprint'), 'fas fa-list-ul'],
            [SprintTemplateMeeting::class, __('Edit meetings', 'sprint'), 'fas fa-calendar'],
        ] as [$tab, $label, $icon]) {
            echo "<a class='btn btn-sm btn-outline-secondary' href='" . htmlescape($url($tab)) . "'><i class='" . $icon . " me-1' aria-hidden='true'></i>" . htmlescape($label) . "</a>";
        }
        if (Sprint::canCreate()) {
            echo "<a class='btn btn-sm btn-primary' href='" . htmlescape(Sprint::getFormURL() . '?' . http_build_query(['tab_params' => ['plugin_sprint_sprinttemplates_id' => $id]])) . "'>"
                . "<i class='fas fa-plus me-1' aria-hidden='true'></i>" . __s('Create a sprint from this template', 'sprint') . "</a>";
        }
        echo "</div>";
        echo "<div class='sprint-small text-muted mt-2'>" . __s('Values you type on the sprint form win over the template: name, goal and description are only filled in when left empty.', 'sprint') . "</div>";
        echo "</div></div>";
    }

    /**
     * Copy a template's members, items and meetings onto a newly created sprint.
     */
    public static function applyToSprint(int $templateId, int $sprintId): void
    {
        $template = new self();
        if (!$template->getFromDB($templateId)) {
            return;
        }

        // Safety net for the JS pre-fill (window.sprintLoadTemplate in js/sprint.js):
        // backfill goal/comment only when the user left them empty.
        $sprint = new Sprint();
        if ($sprint->getFromDB($sprintId)) {
            $updates = ['id' => $sprintId];
            if (empty($sprint->fields['goal']) && !empty($template->fields['goal'])) {
                $updates['goal'] = $template->fields['goal'];
            }
            if (empty($sprint->fields['comment']) && !empty($template->fields['comment'])) {
                $updates['comment'] = $template->fields['comment'];
            }
            $updates['wip_hard'] = (int)($template->fields['wip_hard'] ?? 0);
            $updates['wip_limits'] = json_encode([
                SprintItem::STATUS_IN_PROGRESS => max(0, (int)($template->fields['wip_in_progress'] ?? 0)),
                SprintItem::STATUS_REVIEW => max(0, (int)($template->fields['wip_review'] ?? 0)),
                SprintItem::STATUS_DEPENDENCY => max(0, (int)($template->fields['wip_dependency'] ?? 0)),
            ]);
            $updates['fastlane_capacity'] = SprintMember::normalizeCapacity($template->fields['fastlane_capacity'] ?? 0);
            if (count($updates) > 1) {
                $sprint->update($updates);
            }
        }

        $tmplMember = new SprintTemplateMember();
        $members = $tmplMember->find(['plugin_sprint_sprinttemplates_id' => $templateId]);
        foreach ($members as $row) {
            $member = new SprintMember();
            $member->add([
                'plugin_sprint_sprints_id' => $sprintId,
                'users_id'                 => $row['users_id'],
                'role'                     => $row['role'],
                'capacity_percent'         => $row['capacity_percent'],
                'comment'                  => $row['comment'] ?? '',
            ]);
        }

        self::applyFixedLeave($template, $sprint, $sprintId);

        $tmplItem = new SprintTemplateItem();
        $items = $tmplItem->find(
            ['plugin_sprint_sprinttemplates_id' => $templateId],
            ['sort_order ASC']
        );
        // The template author fills the sprint, not necessarily its Scrum Master.
        SprintItem::withoutAssignGuard(static function () use ($items, $sprintId) {
            foreach ($items as $row) {
                $item = new SprintItem();
                $item->add([
                    'plugin_sprint_sprints_id' => $sprintId,
                    'name'                     => $row['name'],
                    'description'              => $row['description'] ?? '',
                    'priority'                 => $row['priority'],
                    'story_points'             => $row['story_points'],
                    'sort_order'               => $row['sort_order'],
                    'status'                   => SprintItem::STATUS_TODO,
                ]);
            }
        });

        SprintTemplateMeeting::applyToSprint($templateId, $sprintId);
    }

    /**
     * Materialize the template's fixed weekly leave into dated availability
     * exceptions for the sprint window (working days only).
     */
    private static function applyFixedLeave(self $template, Sprint $sprint, int $sprintId): void
    {
        global $DB;

        $rules = (new SprintTemplateAvailability())->find(
            ['plugin_sprint_sprinttemplates_id' => (int)$template->getID()]
        );
        $startRaw = substr((string)($sprint->fields['date_start'] ?? ''), 0, 10);
        $endRaw   = substr((string)($sprint->fields['date_end'] ?? ''), 0, 10);
        if (!$rules || $startRaw === '' || $endRaw === '') {
            return;
        }
        try {
            $cursor = new \DateTimeImmutable($startRaw);
            $end    = new \DateTimeImmutable($endRaw);
        } catch (\Exception $e) {
            return;
        }

        $guard = 0;
        while ($cursor <= $end && $guard < 120) {
            $dow = (int)$cursor->format('N');
            foreach ($rules as $rule) {
                if ((int)$rule['weekday'] !== $dow) {
                    continue;
                }
                $DB->insert('glpi_plugin_sprint_sprintavailabilities', [
                    'plugin_sprint_sprints_id' => $sprintId,
                    'users_id'                 => (int)$rule['users_id'],
                    'date_start'               => $cursor->format('Y-m-d'),
                    'date_end'                 => $cursor->format('Y-m-d'),
                    'availability_percent'     => $rule['availability_percent'],
                    'comment'                  => ($rule['comment'] ?? '') !== ''
                        ? $rule['comment']
                        : __('Fixed leave', 'sprint'),
                    'date_creation'            => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ]);
            }
            $cursor = $cursor->modify('+1 day');
            $guard++;
        }
    }
}
