<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use CommonGLPI;
use Html;
use Session;
use User;
use Project;
use Dropdown;
use Search;
use Log;

/**
 * Sprint - Main sprint entity
 *
 * Represents a 2-week (configurable) sprint with goal, status, and linked items.
 */
class Sprint extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_sprint';
    public $dohistory        = true;

    // Sprint statuses
    const STATUS_PLANNED    = 'planned';
    const STATUS_ACTIVE     = 'active';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_CANCELLED  = 'cancelled';

    /**
     * @return string
     */
    public static function getTypeName($nb = 0): string
    {
        return _n('Sprint', 'Sprints', $nb, 'sprint');
    }

    /**
     * @return string
     */
    public static function getIcon(): string
    {
        return 'fas fa-running';
    }

    /**
     * @return array
     */
    public static function getMenuContent(): array
    {
        $menu = [
            'title' => 'SprintManager',
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];

        if (self::canCreate()) {
            $menu['links']['add'] = self::getFormURL(false);
        }
        if (self::canView()) {
            $menu['links']['search'] = self::getSearchURL(false);
            $menu['links']['template'] = SprintTemplate::getSearchURL(false);
        }

        $menu['options']['sprinttemplate'] = [
            'title' => SprintTemplate::getTypeName(2),
            'page'  => SprintTemplate::getSearchURL(false),
            'icon'  => SprintTemplate::getIcon(),
            'links' => [
                'add'    => SprintTemplate::getFormURL(false),
                'search' => SprintTemplate::getSearchURL(false),
            ],
        ];

        // Backlog is exposed as its own top-level helpdesk menu entry via
        // setup.php (menu_toadd), see Backlog::getMenuContent().

        return $menu;
    }

    /**
     * @return array
     */
    public function rawSearchOptions(): array
    {
        $tab = parent::rawSearchOptions();

        // Override default name field (id 1) to make it a clickable link
        foreach ($tab as &$entry) {
            if (isset($entry['id']) && $entry['id'] == 1) {
                $entry['datatype'] = 'itemlink';
                break;
            }
        }
        unset($entry);

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'status',
            'name'     => __('Status'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'date_start',
            'name'     => __('Start date', 'sprint'),
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'date_end',
            'name'     => __('End date', 'sprint'),
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'sprint_number',
            'name'     => __('Sprint number', 'sprint'),
            'datatype' => 'integer',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => __('Scrum Master', 'sprint'),
            'datatype' => 'dropdown',
            'linkfield' => 'users_id',
        ];

        $tab[] = [
            'id'       => 8,
            'table'    => 'glpi_projects',
            'field'    => 'name',
            'name'     => __('Project'),
            'datatype' => 'dropdown',
            'linkfield' => 'projects_id',
        ];

        $tab[] = [
            'id'       => 9,
            'table'    => $this->getTable(),
            'field'    => 'goal',
            'name'     => __('Sprint goal', 'sprint'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 10,
            'table'    => $this->getTable(),
            'field'    => 'duration_weeks',
            'name'     => __('Duration (weeks)', 'sprint'),
            'datatype' => 'integer',
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

    /**
     * Get all possible statuses
     *
     * @return array
     */
    public static function getAllStatuses(): array
    {
        return [
            self::STATUS_PLANNED   => __('Planned', 'sprint'),
            self::STATUS_ACTIVE    => __('Active', 'sprint'),
            self::STATUS_COMPLETED => __('Completed', 'sprint'),
            self::STATUS_CANCELLED => __('Cancelled', 'sprint'),
        ];
    }

    /**
     * @param $field
     * @param $values
     * @param array $options
     * @return string
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = []): string
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if ($field === 'status') {
            $statuses = self::getAllStatuses();
            return $statuses[$values[$field]] ?? $values[$field];
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * @param $field
     * @param $name
     * @param $values
     * @param array $options
     * @return string
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = []): string
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if ($field === 'status') {
            return Dropdown::showFromArray(
                $name,
                self::getAllStatuses(),
                [
                    'value'   => $values[$field] ?? '',
                    'display' => false,
                ]
            );
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /**
     * Define tabs
     *
     * @param array $options
     * @return array
     */
    public function defineTabs($options = []): array
    {
        $ong = [];

        $this->addStandardTab('GlpiPlugin\Sprint\SprintDashboard', $ong, $options);
        $this->addDefaultFormTab($ong);
        $this->addStandardTab('GlpiPlugin\Sprint\SprintMember', $ong, $options);
        $this->addStandardTab('GlpiPlugin\Sprint\SprintItem', $ong, $options);
        $this->addStandardTab('GlpiPlugin\Sprint\SprintMeeting', $ong, $options);
        $this->addStandardTab('Log', $ong, $options);

        // Rename the default form tab from "Sprint" to "General"
        foreach ($ong as $key => $label) {
            if (str_contains($key, '$main')) {
                $ong[$key] = self::createTabEntry(__('General', 'sprint'));
                break;
            }
        }

        return $ong;
    }

    /**
     * Show the main form
     *
     * Uses Twig TemplateRenderer for GLPI 11 compatibility.
     * Falls back to generic form on GLPI 10.
     *
     * @param int $ID
     * @param array $options
     * @return bool
     */
    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $isNew = !$this->getID() || $this->isNewItem();

        if (class_exists('Glpi\Application\View\TemplateRenderer')) {
            $memberOptions = [];
            if (!$isNew && $this->getID()) {
                $memberOptions = SprintMember::getSprintMemberOptions($this->getID());
            }
            \Glpi\Application\View\TemplateRenderer::getInstance()->display(
                '@sprint/sprint.form.html.twig',
                [
                    'item'           => $this,
                    'params'         => $options,
                    'statuses'       => self::getAllStatuses(),
                    'is_new'         => $isNew,
                    'member_options' => $memberOptions,
                ]
            );
        } else {
            // Fallback for GLPI 10.x: use classic PHP form rendering
            $this->showFormHeader($options);

            // Template selector (only for new sprints)
            if ($isNew) {
                echo "<tr class='tab_bg_1'>";
                echo "<td>" . __('From template', 'sprint') . "</td>";
                echo "<td colspan='3'>";
                SprintTemplate::dropdown([
                    'name'      => 'plugin_sprint_sprinttemplates_id',
                    'value'     => 0,
                    'condition' => ['is_active' => 1],
                    'on_change' => 'sprintLoadTemplate(this.value)',
                ]);
                echo "</td></tr>";
            }

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Name') . "</td>";
            echo "<td>";
            echo Html::input('name', ['value' => $this->fields['name'] ?? '', 'size' => 40]);
            echo "</td>";
            echo "<td>" . __('Status') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('status', self::getAllStatuses(), [
                'value' => $this->fields['status'] ?? self::STATUS_PLANNED,
            ]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Sprint number', 'sprint') . "</td>";
            echo "<td>";
            echo Html::input('sprint_number', [
                'value' => $this->fields['sprint_number'] ?? '',
                'type'  => 'number', 'min' => 1,
            ]);
            echo "</td>";
            echo "<td>" . __('Duration (weeks)', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showNumber('duration_weeks', [
                'value' => $this->fields['duration_weeks'] ?? 2,
                'min' => 1, 'max' => 8,
            ]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Start date', 'sprint') . "</td>";
            echo "<td>";
            Html::showDateTimeField('date_start', ['value' => $this->fields['date_start'] ?? '']);
            echo "</td>";
            echo "<td>" . __('End date', 'sprint') . "</td>";
            echo "<td>";
            Html::showDateTimeField('date_end', ['value' => $this->fields['date_end'] ?? '']);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Scrum Master', 'sprint') . " *</td>";
            echo "<td colspan='3'>";
            User::dropdown(['name' => 'users_id', 'value' => $this->fields['users_id'] ?? 0, 'right' => 'all']);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Sprint goal', 'sprint') . "</td>";
            echo "<td colspan='3'><textarea name='goal' rows='4' cols='80'>" .
                htmlescape($this->fields['goal'] ?? '') . "</textarea></td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Description', 'sprint') . "</td>";
            echo "<td colspan='3'><textarea name='comment' rows='4' cols='80'>" .
                htmlescape($this->fields['comment'] ?? '') . "</textarea></td></tr>";

            $this->showFormButtons($options);
        }

        // "Save as Template" button for existing sprints
        if (!$isNew && $this->getID() && self::canCreate()) {
            $this->showSaveAsTemplateForm();
        }

        // Template pre-fill JS for new sprints
        if ($isNew) {
            $this->showTemplateLoadScript();
        }

        return true;
    }

    /**
     * Show a "Save as Template" form below the sprint form
     */
    private function showSaveAsTemplateForm(): void
    {
        $sprintId = $this->getID();
        $defaultName = $this->fields['name'] . ' - Template';

        echo "<div class='center' style='margin-top:20px;'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'><th colspan='3'>" .
            '<i class="fas fa-clone me-2"></i>' .
            __('Save as template', 'sprint') . "</th></tr>";
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Template name', 'sprint') . "</td>";
        echo "<td>" . Html::input('_template_name', [
            'value' => $defaultName,
            'size'  => 40,
            'id'    => 'sprint_template_name',
        ]) . "</td>";
        echo "<td>";
        echo "<button type='button' class='btn btn-outline-primary' id='sprint_save_as_template'>";
        echo "<i class='fas fa-clone me-1'></i>" . __('Save as template', 'sprint');
        echo "</button>";
        echo "</td></tr>";
        echo "<tr class='tab_bg_1'><td colspan='3'>";
        echo "<div id='sprint_template_result'></div>";
        echo "</td></tr>";
        echo "</table></div>";

        echo "<script>
        $(function() {
            $('#sprint_save_as_template').on('click', function() {
                var btn = $(this);
                var name = $('#sprint_template_name').val();
                btn.prop('disabled', true).html('<i class=\"fas fa-spinner fa-spin me-1\"></i>" . __('Saving...', 'sprint') . "');

                $.ajax({
                    url: CFG_GLPI.root_doc + '/plugins/sprint/ajax/savetemplate.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        sprint_id: {$sprintId},
                        template_name: name,
                        _glpi_csrf_token: $('input[name=\"_glpi_csrf_token\"]').first().val()
                    },
                    success: function(resp) {
                        if (resp.success) {
                            $('#sprint_template_result').html(
                                '<div class=\"alert alert-success mt-2\">' +
                                '<i class=\"fas fa-check-circle me-1\"></i>' +
                                '" . __('Template created successfully!', 'sprint') . " ' +
                                '<a href=\"' + resp.template_url + '\" class=\"alert-link\">" . __('Open template', 'sprint') . "</a>' +
                                '</div>'
                            );
                        } else {
                            $('#sprint_template_result').html(
                                '<div class=\"alert alert-danger mt-2\">' + resp.message + '</div>'
                            );
                        }
                        btn.prop('disabled', false).html('<i class=\"fas fa-clone me-1\"></i>" . __('Save as template', 'sprint') . "');
                    },
                    error: function() {
                        $('#sprint_template_result').html(
                            '<div class=\"alert alert-danger mt-2\">" . __('An error occurred', 'sprint') . "</div>'
                        );
                        btn.prop('disabled', false).html('<i class=\"fas fa-clone me-1\"></i>" . __('Save as template', 'sprint') . "');
                    }
                });
            });
        });
        </script>";
    }

    /**
     * Show template pre-fill JS for new sprint forms
     */
    private function showTemplateLoadScript(): void
    {
        echo "<script>
        function sprintLoadTemplate(templateId) {
            if (!templateId || templateId == 0) return;

            $.ajax({
                url: CFG_GLPI.root_doc + '/plugins/sprint/ajax/gettemplate.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    id: templateId,
                    _glpi_csrf_token: $('input[name=\"_glpi_csrf_token\"]').first().val()
                },
                success: function(resp) {
                    if (resp.success && resp.data) {
                        var d = resp.data;
                        if (d.name_pattern) {
                            $('input[name=\"name\"]').val(d.name_pattern);
                        }
                        if (d.goal) {
                            $('textarea[name=\"goal\"]').val(d.goal);
                        }
                        if (d.comment) {
                            $('textarea[name=\"comment\"]').val(d.comment);
                        }
                        if (d.duration_weeks) {
                            var dw = $('select[name=\"duration_weeks\"]');
                            if (dw.length) {
                                dw.val(d.duration_weeks).trigger('change');
                            }
                        }
                    }
                }
            });
        }
        </script>";
    }

    /**
     * Validate input before adding
     */
    public function prepareInputForAdd($input)
    {
        if (empty($input['users_id']) || (int)$input['users_id'] <= 0) {
            Session::addMessageAfterRedirect(
                __('A Scrum Master is required to create a sprint', 'sprint'),
                false,
                ERROR
            );
            return false;
        }
        return parent::prepareInputForAdd($input);
    }

    /**
     * Actions done after adding an item
     */
    public function post_addItem(): void
    {
        parent::post_addItem();

        // Auto-calculate end date if not set
        if (
            empty($this->fields['date_end'])
            && !empty($this->fields['date_start'])
        ) {
            $weeks    = $this->fields['duration_weeks'] ?: 2;
            $start    = new \DateTime($this->fields['date_start']);
            $start->modify("+{$weeks} weeks");
            $this->update([
                'id'       => $this->getID(),
                'date_end' => $start->format('Y-m-d H:i:s'),
            ]);
        }

        // Apply template if selected
        $templateId = (int)($this->input['plugin_sprint_sprinttemplates_id'] ?? 0);
        if ($templateId > 0) {
            SprintTemplate::applyToSprint($templateId, $this->getID());
        }
    }

    /**
     * Get the count of linked items for dashboard display
     *
     * @return array
     */
    public function getSprintStats(): array
    {
        $stats = [
            'total_items'     => 0,
            'todo_items'      => 0,
            'in_progress'     => 0,
            'done_items'      => 0,
            'blocked_items'   => 0,
            'total_points'    => 0,
            'done_points'     => 0,
            'tickets'         => 0,
            'changes'         => 0,
            'project_tasks'   => 0,
            'meetings'        => 0,
        ];

        if (!$this->getID()) {
            return $stats;
        }

        $item = new SprintItem();
        $items = $item->find(['plugin_sprint_sprints_id' => $this->getID()]);

        $stats['total_items'] = count($items);
        foreach ($items as $row) {
            $stats['total_points'] += (int)$row['story_points'];
            switch ($row['status']) {
                case SprintItem::STATUS_TODO:
                    $stats['todo_items']++;
                    break;
                case SprintItem::STATUS_IN_PROGRESS:
                    $stats['in_progress']++;
                    break;
                case SprintItem::STATUS_DONE:
                    $stats['done_items']++;
                    $stats['done_points'] += (int)$row['story_points'];
                    break;
                case SprintItem::STATUS_BLOCKED:
                    $stats['blocked_items']++;
                    break;
            }
        }

        // Count linked item types from SprintItem
        foreach ($items as $row) {
            switch ($row['itemtype'] ?? '') {
                case 'Ticket':
                    $stats['tickets']++;
                    break;
                case 'Change':
                    $stats['changes']++;
                    break;
                case 'ProjectTask':
                    $stats['project_tasks']++;
                    break;
            }
        }

        $meeting = new SprintMeeting();
        $stats['meetings'] = count($meeting->find([
            'plugin_sprint_sprints_id' => $this->getID()
        ]));

        return $stats;
    }
}
