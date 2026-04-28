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

        $orderBy = ['priority DESC', 'date_creation DESC'];
        $item    = new SprintItem();
        $blocked = $item->find(['plugin_sprint_sprints_id' => 0, 'is_blocked' => 1], $orderBy);
        $items   = $item->find(['plugin_sprint_sprints_id' => 0, 'is_blocked' => 0], $orderBy);

        echo "<div class='center'>";
        echo "<h2><i class='" . self::getIcon() . "'></i> " . self::getTypeName(2) . "</h2>";
        echo "<p class='text-muted'>" .
            __('Items waiting to be assigned to a sprint. Use the dropdown to move an item into a sprint.', 'sprint') .
            "</p>";

        self::renderBlockedSection($blocked, $canedit, $typeLabels);

        echo "<h3 style='margin-top:20px;text-align:left;'>"
            . "<i class='fas fa-list'></i> " . __('Backlog items', 'sprint')
            . " <span class='badge bg-secondary'>" . count($items) . "</span></h3>";

        self::renderFilterBar($typeLabels);

        echo "<table class='tab_cadre_fixe sprint-backlog-table'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Name') . "</th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th>" . __('Type', 'sprint') . "</th>";
        echo "<th><i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;'></i>" . __('Is Fastlane', 'sprint') . "</th>";
        echo "<th><i class='fas fa-ban' style='color:#dc3545;margin-right:4px;'></i>" . __('Is Blocked', 'sprint') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Assign to sprint', 'sprint') . "</th>";
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($items) === 0) {
            $cols = $canedit ? 7 : 5;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>"
                . __('Backlog is empty', 'sprint') . "</td></tr>";
        }

        foreach ($items as $row) {
            self::renderItemRow($row, $canedit, $typeLabels);
        }

        echo "</table>";
        echo "</div>";
    }

    private static function renderBlockedSection(array $blockedItems, bool $canedit, array $typeLabels): void
    {
        $count = count($blockedItems);

        echo "<div class='sprint-backlog-blocked' style='margin:18px 0;border:1px solid #f5c2c7;border-radius:8px;overflow:hidden;'>";
        echo "<div class='sprint-backlog-blocked-header' "
            . "style='display:flex;align-items:center;gap:8px;padding:10px 14px;background:#f8d7da;color:#842029;font-weight:700;cursor:pointer;user-select:none;'>";
        echo "<i class='fas fa-chevron-down sprint-backlog-blocked-chevron' style='transition:transform 0.15s;'></i>";
        echo "<i class='fas fa-ban'></i>";
        echo "<span>" . __('Blocked items', 'sprint') . "</span>";
        echo "<span class='badge bg-danger'>" . $count . "</span>";
        echo "<span style='flex:1;'></span>";
        echo "<span class='text-muted small' style='font-weight:400;'>"
            . __('Review periodically and unblock when ready.', 'sprint') . "</span>";
        echo "</div>";

        echo "<div class='sprint-backlog-blocked-body' style='padding:0;'>";
        if ($count === 0) {
            echo "<div class='center text-muted' style='padding:14px;'>"
                . __('No blocked items.', 'sprint') . "</div>";
        } else {
            echo "<table class='tab_cadre_fixe' style='margin:0;'>";
            echo "<tr class='tab_bg_2'>";
            echo "<th>" . __('Name') . "</th>";
            echo "<th>" . __('Linked item', 'sprint') . "</th>";
            echo "<th>" . __('Type', 'sprint') . "</th>";
            echo "<th><i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;'></i>" . __('Is Fastlane', 'sprint') . "</th>";
            echo "<th><i class='fas fa-ban' style='color:#dc3545;margin-right:4px;'></i>" . __('Is Blocked', 'sprint') . "</th>";
            if ($canedit) {
                echo "<th>" . __('Assign to sprint', 'sprint') . "</th>";
                echo "<th>" . __('Actions') . "</th>";
            }
            echo "</tr>";
            foreach ($blockedItems as $row) {
                self::renderItemRow($row, $canedit, $typeLabels);
            }
            echo "</table>";
        }
        echo "</div></div>";

        echo "<script>
        (function() {
            var key = 'sprint.backlog.blocked.collapsed';
            $(function() {
                var \$wrap = $('.sprint-backlog-blocked').last();
                if (!\$wrap.length) return;
                var \$body = \$wrap.find('.sprint-backlog-blocked-body');
                var \$chev = \$wrap.find('.sprint-backlog-blocked-chevron');
                if (localStorage.getItem(key) === '1') {
                    \$body.hide();
                    \$chev.css('transform', 'rotate(-90deg)');
                }
                \$wrap.find('.sprint-backlog-blocked-header').on('click', function() {
                    var collapsed = \$body.is(':visible');
                    \$body.slideToggle(120);
                    \$chev.css('transform', collapsed ? 'rotate(-90deg)' : 'rotate(0deg)');
                    localStorage.setItem(key, collapsed ? '1' : '0');
                });
            });
        })();
        </script>";
    }

    private static function renderItemRow(array $row, bool $canedit, array $typeLabels): void
    {
        $linkedDisplay = '<span style="color:#ccc;">-</span>';
        if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
            $tmp = new SprintItem();
            $tmp->fields = $row;
            $linkedDisplay = $tmp->getLinkedItemDisplay();
        }

        $itemtype   = (string)($row['itemtype'] ?? '');
        $typeKey    = $itemtype === '' ? 'manual' : $itemtype;
        $typeLabel  = $typeLabels[$itemtype] ?? __('Manual', 'sprint');
        $isFastlane = (int)($row['is_fastlane'] ?? 0) === 1;
        $isBlocked  = (int)($row['is_blocked'] ?? 0) === 1;

        echo "<tr class='tab_bg_1 sprint-filterable-row' "
            . "data-item-name='" . htmlescape($row['name']) . "' "
            . "data-item-type='" . htmlescape($typeKey) . "'>";
        echo "<td><a href='" . SprintItem::getFormURLWithID($row['id']) . "'>"
            . htmlescape($row['name']) . "</a></td>";
        echo "<td>" . $linkedDisplay . "</td>";
        echo "<td>" . $typeLabel . "</td>";

        echo "<td class='center'>";
        if ($canedit) {
            echo "<form method='post' action='" . self::getFormURL() . "' style='display:inline;'>";
            echo Html::hidden('id', ['value' => $row['id']]);
            echo Html::hidden('is_fastlane', ['value' => $isFastlane ? 0 : 1]);
            echo "<button type='submit' name='toggle_fastlane' value='1' "
                . "class='btn btn-sm " . ($isFastlane ? 'btn-warning' : 'btn-outline-secondary') . "' "
                . "title='" . ($isFastlane ? __('Disable fastlane', 'sprint') : __('Enable fastlane', 'sprint')) . "'>"
                . "<i class='fas fa-bolt'></i> " . ($isFastlane ? __('Yes') : __('No'))
                . "</button>";
            Html::closeForm();
        } else {
            echo $isFastlane
                ? "<i class='fas fa-bolt' style='color:#fd7e14;'></i> " . __('Yes')
                : "<span class='text-muted'>" . __('No') . "</span>";
        }
        echo "</td>";

        echo "<td class='center'>";
        if ($canedit) {
            echo "<form method='post' action='" . self::getFormURL() . "' style='display:inline;'>";
            echo Html::hidden('id', ['value' => $row['id']]);
            echo Html::hidden('is_blocked', ['value' => $isBlocked ? 0 : 1]);
            echo "<button type='submit' name='toggle_blocked' value='1' "
                . "class='btn btn-sm " . ($isBlocked ? 'btn-danger' : 'btn-outline-secondary') . "' "
                . "title='" . ($isBlocked ? __('Unblock', 'sprint') : __('Mark as blocked', 'sprint')) . "'>"
                . "<i class='fas fa-ban'></i> " . ($isBlocked ? __('Yes') : __('No'))
                . "</button>";
            Html::closeForm();
        } else {
            echo $isBlocked
                ? "<i class='fas fa-ban' style='color:#dc3545;'></i> " . __('Yes')
                : "<span class='text-muted'>" . __('No') . "</span>";
        }
        echo "</td>";

        if ($canedit) {
            echo "<td>";
            echo "<form method='post' action='" . self::getFormURL() . "' style='display:flex;gap:4px;align-items:center;'>";
            echo Html::hidden('id', ['value' => $row['id']]);
            Sprint::dropdown([
                'name'      => 'plugin_sprint_sprints_id',
                'value'     => 0,
                'condition' => ['status' => [Sprint::STATUS_PLANNED, Sprint::STATUS_ACTIVE]],
            ]);
            echo "<button type='submit' name='assign_to_sprint' value='1' class='btn btn-sm btn-primary'>"
                . "<i class='fas fa-arrow-right'></i> " . __('Assign', 'sprint') . "</button>";
            Html::closeForm();
            echo "</td>";

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

    private static function renderFilterBar(array $typeLabels): void
    {
        echo "<div class='sprint-filter-bar' "
            . "style='display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:center;"
            . "padding:10px;margin-bottom:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;'>";

        echo "<div class='d-flex align-items-center gap-1 text-muted small'>"
            . "<i class='fas fa-filter'></i><span>" . __('Filter', 'sprint') . "</span></div>";

        echo "<input type='search' class='form-control form-control-sm sf-text' "
            . "style='max-width:240px;' placeholder='" . __('Search by name', 'sprint') . "'>";

        echo "<select class='form-select form-select-sm sf-type' style='max-width:200px;'>";
        echo "<option value=''>" . __('All') . " — " . __('Type', 'sprint') . "</option>";
        echo "<option value='Ticket'>" . htmlescape($typeLabels['Ticket']) . "</option>";
        echo "<option value='Change'>" . htmlescape($typeLabels['Change']) . "</option>";
        echo "<option value='ProjectTask'>" . htmlescape($typeLabels['ProjectTask']) . "</option>";
        echo "<option value='manual'>" . htmlescape($typeLabels['']) . "</option>";
        echo "</select>";

        echo "<button type='button' class='btn btn-sm btn-outline-secondary sf-reset' data-sprint-action='filter-reset'>"
            . "<i class='fas fa-times me-1'></i>" . __('Reset', 'sprint') . "</button>";

        echo "</div>";
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
