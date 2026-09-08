<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use Html;
use Session;
use User;
use Dropdown;

/**
 * Sprint - a configurable-length sprint with goal, status, and linked items.
 */
class Sprint extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_sprint';
    public $dohistory        = true;

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
        // Sub-items mirror SprintOverview::navStart().
        $overviewUrl = self::getSearchURL(false) . '?section=' . SprintOverview::SECTION_OVERVIEW;
        $listUrl     = self::getSearchURL(false) . '?section=' . SprintOverview::SECTION_LIST;

        $menu = [
            'title' => 'SprintManager',
            'page'  => $overviewUrl,
            'icon'  => self::getIcon(),
        ];

        if (self::canCreate()) {
            $menu['links']['add'] = self::getFormURL(false);
        }
        if (self::canView()) {
            $menu['links']['search'] = $listUrl;
            $menu['links']['template'] = SprintTemplate::getSearchURL(false);
        }

        $menu['options']['overview'] = [
            'title' => SprintOverview::getTypeName(),
            'page'  => $overviewUrl,
            'icon'  => SprintOverview::getIcon(),
        ];

        $menu['options']['sprint'] = [
            'title' => self::getTypeName(2),
            'page'  => $listUrl,
            'icon'  => self::getIcon(),
            'links' => [
                'add'    => self::getFormURL(false),
                'search' => $listUrl,
            ],
        ];

        if (Backlog::canView()) {
            $menu['options']['backlog'] = [
                'title' => Backlog::getTypeName(2),
                'page'  => Backlog::getSearchURL(false),
                'icon'  => Backlog::getIcon(),
            ];
        }

        if (SprintCustomer::canViewCredits()) {
            $menu['options']['credits'] = [
                'title' => SprintCredits::getTypeName(2),
                'page'  => SprintCredits::getSearchURL(false),
                'icon'  => SprintCredits::getIcon(),
                'links' => [
                    'add'    => SprintCustomer::getFormURL(false),
                    'search' => SprintCustomer::getSearchURL(false),
                    'lists'  => SprintCreditProduct::getSearchURL(false),
                ],
            ];
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

        // Also a tab on GLPI's Config page; exposed here for discoverability.
        if (Session::haveRight('config', READ)) {
            $menu['options']['config'] = [
                'title' => __('Settings', 'sprint'),
                // Resolved: the plugin also lives under marketplace/.
                'page'  => \Plugin::getWebDir('sprint', false) . '/front/config.php',
                'icon'  => 'fas fa-cog',
            ];
        }

        return $menu;
    }

    /**
     * @return array
     */
    public function rawSearchOptions(): array
    {
        $tab = parent::rawSearchOptions();

        // Make the name field (id 1) a clickable link.
        foreach ($tab as &$entry) {
            if (isset($entry['id']) && $entry['id'] == 1) {
                $entry['datatype'] = 'itemlink';
                break;
            }
        }
        unset($entry);

        $tab[] = [
            'id'         => 3,
            'table'      => $this->getTable(),
            'field'      => 'status',
            'name'       => __('Status'),
            'datatype'   => 'specific',
            'searchtype' => ['equals', 'notequals'],
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
     * Planned/active sprints as [id => label] for the carry-over picker.
     */
    public static function getMoveTargetOptions(int $excludeSprintId = 0): array
    {
        $criteria = [
            'status' => [self::STATUS_PLANNED, self::STATUS_ACTIVE],
        ];
        if ($excludeSprintId > 0) {
            $criteria[] = ['NOT' => ['id' => $excludeSprintId]];
        }

        $sprintModel = new self();
        $rows = $sprintModel->find($criteria, ['date_start ASC']);

        $statuses = self::getAllStatuses();
        $options  = [];
        foreach ($rows as $row) {
            $label = $row['name'];
            if (!empty($row['date_start'])) {
                $label .= ' (' . \Html::convDate($row['date_start']);
                if (!empty($row['date_end'])) {
                    $label .= ' → ' . \Html::convDate($row['date_end']);
                }
                $label .= ')';
            }
            $statusLabel = $statuses[$row['status']] ?? $row['status'];
            $label .= ' — ' . $statusLabel;
            $options[(int)$row['id']] = $label;
        }
        return $options;
    }

    /**
     * Sprint picker. Overrides CommonDBTM::dropdown() for natural ordering
     * (GLPI sorts names as strings, so "Sprint 10" beats "Sprint 9") and to
     * hide completed/cancelled sprints unless the caller scopes status itself.
     *
     * @param array $options
     * @return int|string
     */
    public static function dropdown($options = [])
    {
        $p = [
            'name'                => 'plugin_sprint_sprints_id',
            'value'               => 0,
            'condition'           => [],
            'display'             => true,
            'width'               => '',
            'rand'                => mt_rand(),
            'display_emptychoice' => true,
            'on_change'           => '',
        ];
        foreach ($options as $key => $val) {
            $p[$key] = $val;
        }

        $criteria = is_array($p['condition']) ? $p['condition'] : [];
        // Hide completed/cancelled sprints unless the caller scopes status.
        if (!array_key_exists('status', $criteria)) {
            $criteria['status'] = [self::STATUS_PLANNED, self::STATUS_ACTIVE];
        }
        // Honour multi-entity setups like GLPI's native dropdown.
        $entityCriteria = getEntitiesRestrictCriteria(self::getTable(), '', '', true);
        if (!empty($entityCriteria)) {
            $criteria = array_merge($criteria, $entityCriteria);
        }

        $model = new self();
        $rows  = $model->find($criteria);
        usort($rows, [self::class, 'compareForDropdownOrder']);

        $elements = [];
        foreach ($rows as $row) {
            $elements[(int)$row['id']] = $row['name'];
        }

        // Keep the selected sprint visible even if now completed/cancelled,
        // so edit forms still render its value.
        $value = (int)$p['value'];
        if ($value > 0 && !isset($elements[$value])) {
            $current = new self();
            if ($current->getFromDB($value)) {
                $elements = [$value => $current->fields['name']] + $elements;
            }
        }

        return Dropdown::showFromArray($p['name'], $elements, [
            'value'               => $value,
            'width'               => $p['width'],
            'rand'                => $p['rand'],
            'display'             => $p['display'],
            'display_emptychoice' => $p['display_emptychoice'],
            'on_change'           => $p['on_change'],
        ]);
    }

    /**
     * Natural sprint ordering: start date, then sprint number, then a
     * natural-case name compare so "Sprint 9" precedes "Sprint 10".
     */
    private static function compareForDropdownOrder(array $a, array $b): int
    {
        $da = (string)($a['date_start'] ?? '');
        $db = (string)($b['date_start'] ?? '');
        if ($da !== '' && $db !== '' && $da !== $db) {
            return strcmp($da, $db);
        }

        $na = (int)($a['sprint_number'] ?? 0);
        $nb = (int)($b['sprint_number'] ?? 0);
        if ($na !== $nb) {
            return $na <=> $nb;
        }

        return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    }

    /**
     * Shared header bar atop every sprint tab: name/status, goal, progress,
     * days remaining, and a quick sprint switcher.
     */
    public static function renderHeaderBar(Sprint $sprint): void
    {
        $id = (int)$sprint->getID();
        if ($id <= 0) {
            return;
        }

        $statuses    = self::getAllStatuses();
        $statusKey   = (string)($sprint->fields['status'] ?? '');
        $statusLabel = $statuses[$statusKey] ?? $statusKey;
        $statusColors = [
            self::STATUS_PLANNED   => '#6c757d',
            self::STATUS_ACTIVE    => '#0d6efd',
            self::STATUS_COMPLETED => '#198754',
            self::STATUS_CANCELLED => '#dc3545',
        ];
        $sColor = $statusColors[$statusKey] ?? '#6c757d';
        $goal   = trim((string)($sprint->fields['goal'] ?? ''));

        $total = countElementsInTable(SprintItem::getTable(), ['plugin_sprint_sprints_id' => $id]);
        $done  = countElementsInTable(SprintItem::getTable(), [
            'plugin_sprint_sprints_id' => $id,
            'status'                   => SprintItem::STATUS_DONE,
        ]);
        $pct = $total > 0 ? (int)round($done / $total * 100) : 0;

        // Days remaining (hidden for finished/cancelled sprints).
        $daysHtml = '';
        $endRaw   = (string)($sprint->fields['date_end'] ?? '');
        if ($endRaw !== '' && !in_array($statusKey, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            try {
                $end   = new \DateTimeImmutable(substr($endRaw, 0, 10));
                $today = new \DateTimeImmutable('today');
                $diff  = (int)$today->diff($end)->format('%r%a');
                if ($diff > 0) {
                    $daysHtml = sprintf(_n('%d day left', '%d days left', $diff, 'sprint'), $diff);
                } elseif ($diff === 0) {
                    $daysHtml = __('Last day', 'sprint');
                } else {
                    $daysHtml = sprintf(_n('%d day overdue', '%d days overdue', -$diff, 'sprint'), -$diff);
                }
            } catch (\Exception $e) {
                $daysHtml = '';
            }
        }

        $dateRange = '';
        if ($endRaw !== '' || !empty($sprint->fields['date_start'])) {
            $s = !empty($sprint->fields['date_start']) ? \Html::convDate($sprint->fields['date_start']) : '?';
            $e = $endRaw !== '' ? \Html::convDate($endRaw) : '?';
            $dateRange = $s . ' → ' . $e;
        }

        echo "<div class='sprint-header-bar'>";

        echo "<div class='sprint-header-main'>";
        echo "<span class='sprint-header-status' style='background:" . htmlescape($sColor) . ";'>"
            . htmlescape($statusLabel) . "</span>";
        echo "<span class='sprint-header-name'>" . htmlescape((string)$sprint->fields['name']) . "</span>";
        if ($dateRange !== '') {
            echo "<span class='sprint-header-dates'><i class='fas fa-calendar-alt'></i> " . htmlescape($dateRange) . "</span>";
        }
        if ($daysHtml !== '') {
            echo "<span class='sprint-header-days'><i class='fas fa-hourglass-half'></i> " . htmlescape($daysHtml) . "</span>";
        }
        echo "</div>";

        if ($goal !== '') {
            echo "<div class='sprint-header-goal'><i class='fas fa-bullseye'></i> " . htmlescape($goal) . "</div>";
        }

        echo "<div class='sprint-header-right'>";
        echo "<div class='sprint-header-progress' title='" . sprintf(__('%1$d of %2$d items done', 'sprint'), $done, $total) . "'>";
        echo "<div class='sprint-header-progress-bar'><span style='width:{$pct}%;'></span></div>";
        echo "<span class='sprint-header-progress-label'>{$done}/{$total} · {$pct}%</span>";
        echo "</div>";

        // Sprint switcher — planned/active sprints plus the current one.
        $model = new self();
        $rows  = $model->find(['status' => [self::STATUS_PLANNED, self::STATUS_ACTIVE]]);
        $haveCurrent = false;
        foreach ($rows as $r) {
            if ((int)$r['id'] === $id) { $haveCurrent = true; break; }
        }
        if (!$haveCurrent) {
            $rows[] = $sprint->fields;
        }
        usort($rows, [self::class, 'compareForDropdownOrder']);

        $activeTab = (string)($_GET['forcetab'] ?? '');
        echo "<select class='form-select form-select-sm sprint-switcher' "
            . "data-base-url='" . htmlescape(self::getFormURL()) . "' "
            . "data-forcetab='" . htmlescape($activeTab) . "' "
            . "title='" . __('Switch sprint', 'sprint') . "' style='max-width:240px;'>";
        foreach ($rows as $r) {
            $rid = (int)$r['id'];
            $sel = $rid === $id ? ' selected' : '';
            echo "<option value='{$rid}'{$sel}>" . htmlescape((string)$r['name']) . "</option>";
        }
        echo "</select>";
        echo "</div>";

        echo "</div>";

        echo "<script>(function(){"
            . "if (window.__sprintSwitcherBound) { return; } window.__sprintSwitcherBound = true;"
            . "document.addEventListener('change', function(e){"
            . "var s = e.target.closest && e.target.closest('.sprint-switcher'); if(!s) return;"
            . "var id = parseInt(s.value,10)||0; if(id<=0) return;"
            . "var url = s.getAttribute('data-base-url') + '?id=' + id;"
            . "var tab = s.getAttribute('data-forcetab'); if(tab){ url += '&forcetab=' + encodeURIComponent(tab); }"
            . "window.location.href = url;"
            . "});"
            . "})();</script>";
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
     * On completion, warn about unfinished items so the team carries them
     * over or returns them to the backlog. Never moves items itself.
     */
    public function post_updateItem($history = 1)
    {
        if (in_array('status', $this->updates ?? [], true)
            && ($this->fields['status'] ?? '') === self::STATUS_ACTIVE
            && (($this->oldvalues['status'] ?? '') !== self::STATUS_ACTIVE)) {
            global $DB;
            $items = (new SprintItem())->find(['plugin_sprint_sprints_id' => (int)$this->getID()]);
            $baselineItems = count($items);
            $baselinePoints = array_sum(array_map(static fn($item) => max(0, (int)($item['story_points'] ?? 0)), $items));
            $DB->update(self::getTable(), ['scope_baseline_items' => $baselineItems, 'scope_baseline_points' => $baselinePoints], ['id' => (int)$this->getID()]);
            $this->fields['scope_baseline_items'] = $baselineItems;
            $this->fields['scope_baseline_points'] = $baselinePoints;
        }
        if (
            in_array('status', $this->updates ?? [], true)
            && ($this->fields['status'] ?? '') === self::STATUS_COMPLETED
        ) {
            // Freeze the credit agreement so a later change to a customer's
            // retainer cannot rewrite what this sprint granted.
            SprintCustomer::snapshotAgreements((int)$this->getID());
            SprintAgility::carryImprovementsForward($this);
            $unfinished = countElementsInTable(
                SprintItem::getTable(),
                [
                    'plugin_sprint_sprints_id' => (int)$this->getID(),
                    ['NOT' => ['status' => SprintItem::STATUS_DONE]],
                ]
            );
            if ($unfinished > 0) {
                Session::addMessageAfterRedirect(
                    sprintf(
                        _n(
                            'Sprint completed with %d unfinished item — carry it over to another sprint or send it back to the backlog.',
                            'Sprint completed with %d unfinished items — carry them over to another sprint or send them back to the backlog.',
                            $unfinished,
                            'sprint'
                        ),
                        $unfinished
                    ),
                    false,
                    WARNING
                );
            }
        }

        return parent::post_updateItem($history);
    }

    public function defineTabs($options = []): array
    {
        $ong = [];

        $this->addStandardTab('GlpiPlugin\Sprint\SprintDashboard', $ong, $options);
        $this->addDefaultFormTab($ong);
        $this->addStandardTab('GlpiPlugin\Sprint\SprintMember', $ong, $options);
        $this->addStandardTab('GlpiPlugin\Sprint\SprintItem', $ong, $options);
        $this->addStandardTab('GlpiPlugin\Sprint\SprintBoard', $ong, $options);
        $this->addStandardTab('GlpiPlugin\Sprint\SprintFastlane', $ong, $options);
        $this->addStandardTab('GlpiPlugin\Sprint\SprintMeeting', $ong, $options);
        $this->addStandardTab('GlpiPlugin\Sprint\SprintRequest', $ong, $options);
        $this->addStandardTab('GlpiPlugin\Sprint\SprintAgility', $ong, $options);
        $this->addStandardTab('GlpiPlugin\Sprint\SprintAudit', $ong, $options);
        $this->addStandardTab('Log', $ong, $options);

        // Rename the default form tab to "General".
        foreach ($ong as $key => $label) {
            if (str_contains($key, '$main')) {
                $ong[$key] = self::createTabEntry(__('General', 'sprint'));
                break;
            }
        }

        return $ong;
    }

    /**
     * Main form. Twig TemplateRenderer on GLPI 11, classic PHP form on GLPI 10.
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

            // Once set, only the Scrum Master can reassign the role; read-only for others.
            $currentMaster        = (int)($this->fields['users_id'] ?? 0);
            $canReassignScrumMaster = $isNew
                || $currentMaster === 0
                || $currentMaster === (int)Session::getLoginUserID();

            // Pre-populate options so the read-only label has a user name.
            if ($currentMaster > 0 && !isset($memberOptions[$currentMaster])) {
                $memberOptions[$currentMaster] = SprintCache::userName($currentMaster);
            }

            \Glpi\Application\View\TemplateRenderer::getInstance()->display(
                '@sprint/sprint.form.html.twig',
                [
                    'item'                     => $this,
                    'params'                   => $options,
                    'statuses'                 => self::getAllStatuses(),
                    'is_new'                   => $isNew,
                    'member_options'           => $memberOptions,
                    'can_reassign_scrum_master' => $canReassignScrumMaster,
                ]
            );

            if (
                !$isNew
                && Session::haveRight(self::$rightname, PURGE)
                && Config::isCurrentUserScrumMaster((int)$this->getID())
            ) {
                $this->renderDangerZone();
            }
        } else {
            // GLPI 10.x fallback: classic PHP form.
            $this->showFormHeader($options);

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
            echo "<td>";
            User::dropdown(['name' => 'users_id', 'value' => $this->fields['users_id'] ?? 0, 'right' => 'all']);
            echo "</td>";
            echo "<td>" . __('Fastlane capacity cap (%)', 'sprint') . "<br>"
                . "<span class='text-muted' style='font-size:0.82em;'>" . __('0 = no cap. Exceeding it shows as overflow on the fastlane', 'sprint') . "</span></td>";
            echo "<td>";
            Dropdown::showNumber('fastlane_capacity', [
                'value' => $this->fields['fastlane_capacity'] ?? 0,
                'min'   => 0, 'max' => 100, 'step' => 0.5,
                'unit'  => '%',
            ]);
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

        if (!$isNew && $this->getID() && self::canCreate()) {
            $this->showSaveAsTemplateForm();
        }

        return true;
    }

    /**
     * Scrum-Master-only permanent delete, rendered below the General form.
     */
    private function renderDangerZone(): void
    {
        $id      = (int)$this->getID();
        $confirm = __('This permanently removes the sprint and all its items, members, meetings and audit history. This cannot be undone. Continue?', 'sprint');

        echo "<div class='card mt-3' style='max-width:1200px;margin:18px auto 0;border-color:#dc3545;'>";
        echo "<div class='card-header' style='background:color-mix(in srgb,#dc3545 14%,var(--tblr-bg-surface,#fff));color:var(--tblr-danger,#b02a37);'>"
            . "<i class='fas fa-exclamation-triangle me-1'></i>"
            . htmlescape(__('Danger zone', 'sprint'))
            . "</div>";
        echo "<div class='card-body'>";
        echo "<p class='mb-3 sprint-small text-muted'>"
            . htmlescape(__('Permanent deletion is restricted to the Scrum Master and bypasses the trash. Use this only when the sprint should leave no trace.', 'sprint'))
            . "</p>";
        echo "<form method='post' action='" . htmlescape(self::getFormURL()) . "'>";
        echo Html::hidden('id', ['value' => $id]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo "<button type='submit' name='purge' value='1' class='btn btn-danger' "
            . "data-sprint-confirm='" . htmlescape($confirm) . "'>"
            . "<i class='fas fa-trash me-1'></i>"
            . htmlescape(__('Permanently delete sprint', 'sprint'))
            . "</button>";
        echo "</form>";
        echo "</div></div>";
    }

    private function showSaveAsTemplateForm(): void
    {
        $sprintId = $this->getID();
        $defaultName = $this->fields['name'] . ' - Template';

        echo "<div class='center' style='margin-top:20px;'>";
        echo "<table class='tab_cadre_fixe sprint-themed'>";
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

    public function prepareInputForAdd($input)
    {
        if (isset($input['fastlane_capacity'])) {
            $input['fastlane_capacity'] = SprintMember::normalizeCapacity($input['fastlane_capacity']);
        }
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
     * Once assigned, only the Scrum Master may reassign the role — stops
     * other members silently transferring it during normal sprint edits.
     */
    public function prepareInputForUpdate($input)
    {
        if (isset($input['fastlane_capacity'])) {
            $input['fastlane_capacity'] = SprintMember::normalizeCapacity($input['fastlane_capacity']);
        }
        if (array_key_exists('users_id', $input)) {
            $currentMaster = (int)($this->fields['users_id'] ?? 0);
            $newMaster     = (int)$input['users_id'];
            $actor         = (int)Session::getLoginUserID();

            if (
                $currentMaster > 0
                && $newMaster !== $currentMaster
                && $actor !== $currentMaster
            ) {
                Session::addMessageAfterRedirect(
                    __('Only the current Scrum Master can reassign this role.', 'sprint'),
                    false,
                    ERROR
                );
                // Preserve the existing value so other submitted fields still apply.
                $input['users_id'] = $currentMaster;
            }
        }
        return parent::prepareInputForUpdate($input);
    }

    public function post_addItem(): void
    {
        parent::post_addItem();

        // Auto-calculate end date if not set.
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

        // Apply template if selected.
        $templateId = (int)($this->input['plugin_sprint_sprinttemplates_id'] ?? 0);
        if ($templateId > 0) {
            SprintTemplate::applyToSprint($templateId, $this->getID());
        }
        SprintAgility::carryImprovementsToSprint($this);
    }

}
