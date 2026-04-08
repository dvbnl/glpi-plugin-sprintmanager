<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use CommonGLPI;
use Html;
use Session;
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
     * Apply template to a newly created sprint:
     * copy members and items from template to sprint
     */
    public static function applyToSprint(int $templateId, int $sprintId): void
    {
        $template = new self();
        if (!$template->getFromDB($templateId)) {
            return;
        }

        // Backfill goal/comment on the sprint if the user did not override
        // them (e.g. when JavaScript pre-fill did not run). This is a safety
        // net for the JS pre-fill in Sprint::showTemplateLoadScript().
        $sprint = new Sprint();
        if ($sprint->getFromDB($sprintId)) {
            $updates = ['id' => $sprintId];
            if (empty($sprint->fields['goal']) && !empty($template->fields['goal'])) {
                $updates['goal'] = $template->fields['goal'];
            }
            if (empty($sprint->fields['comment']) && !empty($template->fields['comment'])) {
                $updates['comment'] = $template->fields['comment'];
            }
            if (count($updates) > 1) {
                $sprint->update($updates);
            }
        }

        // Copy template members to sprint members
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

        // Copy template items to sprint items
        $tmplItem = new SprintTemplateItem();
        $items = $tmplItem->find(
            ['plugin_sprint_sprinttemplates_id' => $templateId],
            ['sort_order ASC']
        );
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

        // Generate meetings from template schedule
        SprintTemplateMeeting::applyToSprint($templateId, $sprintId);
    }
}
