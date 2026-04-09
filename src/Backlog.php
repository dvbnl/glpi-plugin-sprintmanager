<?php

namespace GlpiPlugin\Sprint;

use Html;
use Session;
use Dropdown;
use Plugin;
use Ticket;
use Change;
use ProjectTask;

/**
 * Backlog - Sprint backlog (un-assigned SprintItems)
 *
 * Not a CommonDBTM: this is a virtual collection over SprintItem rows
 * where plugin_sprint_sprints_id = 0. Provides menu integration and
 * the listing/assignment UI.
 */
class Backlog
{
    public static function getTypeName($nb = 0): string
    {
        return _n('Backlog', 'Backlog', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-layer-group';
    }

    public static function getSearchURL(bool $full = true): string
    {
        return Plugin::getWebDir('sprint', $full) . '/front/backlog.php';
    }

    public static function getFormURL(bool $full = true): string
    {
        return Plugin::getWebDir('sprint', $full) . '/front/backlog.form.php';
    }

    /**
     * Permission shim used by the menu registration.
     *
     * The backlog is just a filtered view over SprintItem rows, so its
     * visibility piggy-backs on the SprintItem READ right (and the
     * "own items" right used elsewhere in the plugin).
     */
    public static function canView(): bool
    {
        return Session::haveRight(SprintItem::$rightname, READ)
            || Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(SprintItem::$rightname, CREATE)
            || Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS);
    }

    /**
     * Menu entry for the helpdesk menu group.
     *
     * Registered via $PLUGIN_HOOKS['menu_toadd'] in setup.php so that
     * "Backlog" appears as its own clickable item next to SprintManager.
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(2),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * Build a 1-click "Add to backlog" form for a Ticket / Change / ProjectTask
     */
    public static function showAddToBacklogButton(string $itemtype, int $itemId): void
    {
        if (!Session::haveRight(SprintItem::$rightname, CREATE)
            && !Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS)) {
            return;
        }

        $allowed = ['Ticket', 'Change', 'ProjectTask'];
        if (!in_array($itemtype, $allowed, true) || $itemId <= 0) {
            return;
        }

        // Use a real <button> instead of Html::submit() so we can render
        // an icon inside the button (Html::submit escapes its value).
        echo "<div class='center' style='margin:8px 0;'>";
        echo "<form method='post' action='" . self::getFormURL() . "' style='display:inline;'>";
        echo Html::hidden('itemtype', ['value' => $itemtype]);
        echo Html::hidden('items_id', ['value' => $itemId]);
        echo "<button type='submit' name='add_to_backlog' value='1' class='btn btn-outline-secondary'>"
            . "<i class='fas fa-layer-group'></i> " . __('Add to backlog', 'sprint')
            . "</button>";
        Html::closeForm();
        echo "</div>";
    }

    /**
     * Render the full backlog page (filter bar + list + per-row "assign to
     * sprint" dropdown).
     *
     * Filtering is driven by GET parameters so the URL is shareable:
     *   - q       : free-text search on the item name
     *   - type    : '', 'Ticket', 'Change', 'ProjectTask', or 'manual'
     *   - sort    : 'priority' (default), 'name', 'recent', 'oldest'
     */
    public static function showBacklog(): void
    {
        $canedit = Session::haveRight(SprintItem::$rightname, UPDATE)
            || Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS);

        $typeLabels = [
            ''            => __('Manual', 'sprint'),
            'Ticket'      => __('Ticket'),
            'Change'      => __('Change'),
            'ProjectTask' => __('Project task'),
        ];

        // === Read filter inputs from query string =========================
        $filterQ    = trim((string)($_GET['q'] ?? ''));
        $filterType = (string)($_GET['type'] ?? '');
        $filterSort = (string)($_GET['sort'] ?? 'priority');

        // Allowlist filter values
        $allowedTypes = ['', 'Ticket', 'Change', 'ProjectTask', 'manual'];
        if (!in_array($filterType, $allowedTypes, true)) {
            $filterType = '';
        }
        $allowedSorts = ['priority', 'name', 'recent', 'oldest'];
        if (!in_array($filterSort, $allowedSorts, true)) {
            $filterSort = 'priority';
        }

        // === Build the WHERE criteria =====================================
        $criteria = ['plugin_sprint_sprints_id' => 0];

        if ($filterType === 'manual') {
            // Items added manually (no linked GLPI item)
            $criteria['itemtype'] = '';
        } elseif ($filterType !== '') {
            $criteria['itemtype'] = $filterType;
        }

        if ($filterQ !== '') {
            $escaped = str_replace(
                ['\\', '%', '_'],
                ['\\\\', '\\%', '\\_'],
                $filterQ
            );
            $criteria['name'] = ['LIKE', '%' . $escaped . '%'];
        }

        // === Sort order ===================================================
        $sortMap = [
            'priority' => ['priority DESC', 'date_creation DESC'],
            'name'     => ['name ASC'],
            'recent'   => ['date_creation DESC'],
            'oldest'   => ['date_creation ASC'],
        ];
        $orderBy = $sortMap[$filterSort];

        $item  = new SprintItem();
        $items = $item->find($criteria, $orderBy);

        // === Header ======================================================
        echo "<div class='center'>";
        echo "<h2><i class='" . self::getIcon() . "'></i> " . self::getTypeName(2) . "</h2>";
        echo "<p class='text-muted'>" .
            __('Items waiting to be assigned to a sprint. Use the dropdown to move an item into a sprint.', 'sprint') .
            "</p>";

        // === Filter bar ==================================================
        self::renderFilterBar($filterQ, $filterType, $filterSort, $typeLabels);

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Name') . "</th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th>" . __('Type', 'sprint') . "</th>";
        echo "<th title='" . __('Items flagged as fastlane will be assigned to a sprint via the dedicated Fastlane tab.', 'sprint') . "'>" .
            "<i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;'></i>" .
            __('Is Fastlane', 'sprint') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Assign to sprint', 'sprint') . "</th>";
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($items) === 0) {
            $cols = $canedit ? 6 : 4;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('Backlog is empty', 'sprint') . "</td></tr>";
        }

        foreach ($items as $row) {
            $linkedDisplay = '<span style="color:#ccc;">-</span>';
            if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
                $tmp = new SprintItem();
                $tmp->fields = $row;
                $linkedDisplay = $tmp->getLinkedItemDisplay();
            }

            $typeLabel = $typeLabels[$row['itemtype'] ?? ''] ?? __('Manual', 'sprint');

            $isFastlane = (int)($row['is_fastlane'] ?? 0) === 1;

            echo "<tr class='tab_bg_1'>";
            echo "<td><a href='" . SprintItem::getFormURLWithID($row['id']) . "'>" .
                htmlescape($row['name']) . "</a></td>";
            echo "<td>" . $linkedDisplay . "</td>";
            echo "<td>" . $typeLabel . "</td>";

            // Fastlane checkbox column — inline toggle form. Posts to
            // backlog.form.php which routes to the toggle_fastlane action.
            echo "<td class='center'>";
            if ($canedit) {
                echo "<form method='post' action='" . self::getFormURL() . "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::hidden('is_fastlane', ['value' => $isFastlane ? 0 : 1]);
                echo "<button type='submit' name='toggle_fastlane' value='1' "
                    . "class='btn btn-sm " . ($isFastlane ? 'btn-warning' : 'btn-outline-secondary') . "' "
                    . "title='" . ($isFastlane ? __('Disable fastlane', 'sprint') : __('Enable fastlane', 'sprint')) . "'>"
                    . "<i class='fas " . ($isFastlane ? 'fa-bolt' : 'fa-bolt') . "'></i> "
                    . ($isFastlane ? __('Yes') : __('No'))
                    . "</button>";
                Html::closeForm();
            } else {
                echo $isFastlane
                    ? "<i class='fas fa-bolt' style='color:#fd7e14;'></i> " . __('Yes')
                    : "<span class='text-muted'>" . __('No') . "</span>";
            }
            echo "</td>";

            if ($canedit) {
                // Inline assign-to-sprint form (dropdown + submit)
                echo "<td>";
                echo "<form method='post' action='" . self::getFormURL() . "' style='display:flex;gap:4px;align-items:center;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                Sprint::dropdown([
                    'name'      => 'plugin_sprint_sprints_id',
                    'value'     => 0,
                    'condition' => ['status' => [Sprint::STATUS_PLANNED, Sprint::STATUS_ACTIVE]],
                ]);
                echo "<button type='submit' name='assign_to_sprint' value='1' class='btn btn-sm btn-primary'>"
                    . "<i class='fas fa-arrow-right'></i> " . __('Assign', 'sprint')
                    . "</button>";
                Html::closeForm();
                echo "</td>";

                // Delete from backlog
                echo "<td class='center' style='white-space:nowrap;'>";
                echo "<form method='post' action='" . self::getFormURL() . "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::submit(__('Delete'), [
                    'name'    => 'purge',
                    'class'   => 'btn btn-sm btn-outline-danger',
                    'confirm' => __('Confirm deletion?'),
                ]);
                Html::closeForm();
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</table>";
        echo "</div>";
    }

    /**
     * Render the filter bar above the backlog table.
     *
     * Uses a plain GET form so the URL captures the filter state and the
     * page is shareable / bookmarkable, mirroring how GLPI's standard
     * search pages behave.
     */
    private static function renderFilterBar(
        string $filterQ,
        string $filterType,
        string $filterSort,
        array $typeLabels
    ): void {
        $sortLabels = [
            'priority' => __('Priority') . ' ↓',
            'name'     => __('Name') . ' ↑',
            'recent'   => __('Newest first', 'sprint'),
            'oldest'   => __('Oldest first', 'sprint'),
        ];

        $hasActiveFilter = ($filterQ !== '' || $filterType !== '' || $filterSort !== 'priority');

        echo "<form method='get' action='" . self::getSearchURL() . "' class='sprint-backlog-filter' "
            . "style='display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:center;"
            . "padding:10px;margin-bottom:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;'>";

        // Free-text search on name
        echo "<div style='display:flex;align-items:center;gap:4px;'>";
        echo "<i class='fas fa-search' style='color:#6c757d;'></i>";
        echo "<input type='text' name='q' value='" . htmlescape($filterQ) . "' "
            . "placeholder='" . __('Search by name', 'sprint') . "' "
            . "class='form-control form-control-sm' style='min-width:220px;'>";
        echo "</div>";

        // Type filter
        echo "<div style='display:flex;align-items:center;gap:4px;'>";
        echo "<label style='margin:0;font-weight:600;'>" . __('Type', 'sprint') . ":</label>";
        echo "<select name='type' class='form-select form-select-sm'>";
        echo "<option value=''" . ($filterType === '' ? ' selected' : '') . ">" . __('All') . "</option>";
        echo "<option value='Ticket'" . ($filterType === 'Ticket' ? ' selected' : '') . ">" . htmlescape($typeLabels['Ticket']) . "</option>";
        echo "<option value='Change'" . ($filterType === 'Change' ? ' selected' : '') . ">" . htmlescape($typeLabels['Change']) . "</option>";
        echo "<option value='ProjectTask'" . ($filterType === 'ProjectTask' ? ' selected' : '') . ">" . htmlescape($typeLabels['ProjectTask']) . "</option>";
        echo "<option value='manual'" . ($filterType === 'manual' ? ' selected' : '') . ">" . htmlescape($typeLabels['']) . "</option>";
        echo "</select>";
        echo "</div>";

        // Sort
        echo "<div style='display:flex;align-items:center;gap:4px;'>";
        echo "<label style='margin:0;font-weight:600;'>" . __('Sort') . ":</label>";
        echo "<select name='sort' class='form-select form-select-sm'>";
        foreach ($sortLabels as $key => $label) {
            $selected = ($filterSort === $key) ? ' selected' : '';
            echo "<option value='" . $key . "'" . $selected . ">" . htmlescape($label) . "</option>";
        }
        echo "</select>";
        echo "</div>";

        // Buttons
        echo "<button type='submit' class='btn btn-sm btn-primary'>"
            . "<i class='fas fa-filter'></i> " . __('Filter', 'sprint')
            . "</button>";

        if ($hasActiveFilter) {
            echo "<a href='" . self::getSearchURL() . "' class='btn btn-sm btn-outline-secondary'>"
                . "<i class='fas fa-times'></i> " . __('Reset', 'sprint')
                . "</a>";
        }

        echo "</form>";
    }

    /**
     * Create a backlog item from a Ticket / Change / ProjectTask
     *
     * Returns the created SprintItem ID, or 0 if it could not be created
     * (for example because an identical backlog entry already exists).
     */
    public static function addFromLinkedItem(string $itemtype, int $itemId): int
    {
        $allowed = ['Ticket', 'Change', 'ProjectTask'];
        if (!in_array($itemtype, $allowed, true) || $itemId <= 0) {
            return 0;
        }

        // Avoid duplicates: same linked item already in backlog
        $existing = (new SprintItem())->find([
            'plugin_sprint_sprints_id' => 0,
            'itemtype'                 => $itemtype,
            'items_id'                 => $itemId,
        ]);
        if (count($existing) > 0) {
            $first = reset($existing);
            return (int)$first['id'];
        }

        $linked = new $itemtype();
        if (!$linked->getFromDB($itemId)) {
            return 0;
        }

        $name     = $linked->fields['name'] ?? ($itemtype . ' #' . $itemId);
        $priority = (int)($linked->fields['priority'] ?? 3);

        $sprintItem = new SprintItem();
        $newId = $sprintItem->add([
            'plugin_sprint_sprints_id' => 0,
            'name'                     => $name,
            'itemtype'                 => $itemtype,
            'items_id'                 => $itemId,
            'status'                   => SprintItem::STATUS_TODO,
            'priority'                 => $priority,
            'users_id'                 => 0,
            'capacity'                 => 0,
            'story_points'             => 0,
        ]);

        return (int)$newId;
    }
}
