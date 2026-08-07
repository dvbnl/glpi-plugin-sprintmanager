<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use Html;
use Dropdown;
use Log;

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

        return true;
    }

    /**
     * Get template data for AJAX pre-fill
     */
    public function getTemplateData(): array
    {
        $templateId = $this->getID();

        $membersCount = countElementsInTable(
            SprintTemplateMember::getTable(),
            ['plugin_sprint_sprinttemplates_id' => $templateId]
        );
        $itemsCount = countElementsInTable(
            SprintTemplateItem::getTable(),
            ['plugin_sprint_sprinttemplates_id' => $templateId]
        );
        $meetingsCount = countElementsInTable(
            SprintTemplateMeeting::getTable(),
            ['plugin_sprint_sprinttemplates_id' => $templateId]
        );

        return [
            'name_pattern'    => $this->fields['name_pattern'] ?? '',
            'duration_weeks'  => (int)($this->fields['duration_weeks'] ?? 2),
            'goal'            => $this->fields['goal'] ?? '',
            'comment'         => $this->fields['comment'] ?? '',
            'members_count'   => $membersCount,
            'items_count'     => $itemsCount,
            'meetings_count'  => $meetingsCount,
        ];
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

        // Safety net for the JS pre-fill (Sprint::showTemplateLoadScript):
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
