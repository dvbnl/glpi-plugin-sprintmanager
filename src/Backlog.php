<?php

namespace GlpiPlugin\Sprint;

use Html;
use Session;
use Dropdown;
use Plugin;
use Ticket;
use Change;
use Problem;
use ProjectTask;
use User;

/**
 * Sprint backlog: a virtual collection over SprintItem rows where
 * plugin_sprint_sprints_id = 0 (not a CommonDBTM). Provides the menu
 * entry and the listing/assignment UI.
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
     * Visibility piggy-backs on the SprintItem READ right (plus the
     * "own items" right), since the backlog is just a filtered view of them.
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
     * Build a 1-click "Add to backlog" form for a Ticket/Change/ProjectTask.
     * Renders nothing when the item is already in a sprint: backlog and sprint
     * membership are mutually exclusive (use "Carry over to sprint" instead).
     */
    public static function showAddToBacklogButton(string $itemtype, int $itemId): void
    {
        if (!Session::haveRight(SprintItem::$rightname, CREATE)
            && !Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS)) {
            return;
        }

        $allowed = ['Ticket', 'Change', 'Problem', 'ProjectTask'];
        if (!in_array($itemtype, $allowed, true) || $itemId <= 0) {
            return;
        }

        if (self::isLinkedItemInAnySprint($itemtype, $itemId)) {
            echo "<div class='center' style='margin:8px 0;'>";
            echo "<span class='text-muted small'>"
                . "<i class='fas fa-info-circle me-1'></i>"
                . __('Already linked to a sprint — use "Carry over to sprint" to move it between sprints.', 'sprint')
                . "</span>";
            echo "</div>";
            return;
        }

        // Real <button> instead of Html::submit() so the icon survives
        // (Html::submit escapes its value).
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

    /** True when the linked GLPI item is in a real sprint (sprints_id > 0). */
    public static function isLinkedItemInAnySprint(string $itemtype, int $itemId): bool
    {
        if ($itemtype === '' || $itemId <= 0) {
            return false;
        }
        return countElementsInTable(
            SprintItem::getTable(),
            [
                'itemtype' => $itemtype,
                'items_id' => $itemId,
                ['NOT' => ['plugin_sprint_sprints_id' => 0]],
            ]
        ) > 0;
    }

    /** Labels for the linked-item type icons, shared by page and row fragments. */
    public static function getTypeLabels(): array
    {
        return [
            ''            => __('Manual', 'sprint'),
            'Ticket'      => __('Ticket'),
            'Change'      => __('Change'),
            'Problem'     => __('Problem'),
            'ProjectTask' => __('Project task'),
        ];
    }

    /**
     * Assign-permission flags for a backlog row, mirroring
     * ajax/assigntosprint.php: fastlane items are assignable by anyone;
     * normal items only by the proposed sprint's Scrum Master. Others get a
     * request button instead (pending_assign marks an open request).
     * Caches are per-request so rendering many rows stays cheap.
     *
     * @return array{can_assign: bool, pending_assign: bool}
     */
    public static function computeRowFlags(array $row): array
    {
        static $masterCache  = [];
        static $pendingItems = null;

        if ($pendingItems === null) {
            $pendingItems = [];
            if (SprintRequest::ensureTable()) {
                foreach ((new SprintRequest())->find([
                    'request_type' => SprintRequest::TYPE_ASSIGN,
                    'status'       => SprintRequest::STATUS_PENDING,
                ]) as $req) {
                    $pendingItems[(int)$req['plugin_sprint_sprintitems_id']] = true;
                }
            }
        }

        $proposedId = (int)($row['proposed_sprints_id'] ?? 0);
        $canAssign  = false;
        if ($proposedId > 0) {
            if ((int)($row['is_fastlane'] ?? 0) === 1) {
                $canAssign = true;
            } else {
                if (!isset($masterCache[$proposedId])) {
                    $masterCache[$proposedId] = SprintItem::currentUserIsScrumMasterOf($proposedId);
                }
                $canAssign = $masterCache[$proposedId];
            }
        }

        return [
            'can_assign'     => $canAssign,
            'pending_assign' => isset($pendingItems[(int)$row['id']]),
        ];
    }

    public static function showBacklog(): void
    {
        $canedit = Session::haveRight(SprintItem::$rightname, UPDATE)
            || Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS);

        $typeLabels = self::getTypeLabels();

        // Manual drag order wins; un-ordered items (sort_order 0) fall back to
        // priority/date so existing backlogs look unchanged until reordered.
        $orderBy = ['sort_order ASC', 'priority DESC', 'date_creation DESC'];
        $item    = new SprintItem();
        $blocked = $item->find(['plugin_sprint_sprints_id' => 0, 'is_blocked' => 1], $orderBy);
        $parked  = $item->find(['plugin_sprint_sprints_id' => 0, 'is_blocked' => 0, 'is_parked' => 1], $orderBy);
        $items   = $item->find(['plugin_sprint_sprints_id' => 0, 'is_blocked' => 0, 'is_parked' => 0], $orderBy);

        $allRows  = array_merge($blocked, $parked, $items);
        $allIds   = array_map(fn($r) => (int)$r['id'], $allRows);
        $tagsById = SprintItem::getTagsForItems($allIds);

        // Resolve proposed-sprint names once for the read-only sprint column.
        $sprintNames = [];
        foreach ($allRows as $r) {
            $sid = (int)($r['proposed_sprints_id'] ?? 0);
            if ($sid > 0 && !isset($sprintNames[$sid])) {
                $sprint = new Sprint();
                $sprintNames[$sid] = $sprint->getFromDB($sid)
                    ? (string)$sprint->fields['name']
                    : ('#' . $sid);
            }
        }

        echo "<div class='center'>";
        echo "<h2><i class='" . self::getIcon() . "'></i> " . self::getTypeName(2) . "</h2>";
        echo "<p class='text-muted'>" .
            __('Items waiting to be assigned to a sprint. Use the dropdown to move an item into a sprint.', 'sprint') .
            "</p>";

        // Pending assign requests for the sprints this user is Scrum Master of.
        SprintRequest::renderPanel();

        // Prognosis views: category × sprint matrix + weekly inflow/outflow.
        self::renderCategoryDashboard();
        self::renderFlowCard();

        self::renderBlockedSection($blocked, $canedit, $typeLabels, $tagsById, $sprintNames);

        // Count complete items (owner + sprint + capacity, same rule as the
        // per-row Ready badge) that THIS user may actually assign — the bulk
        // button only covers those, split per target sprint so a single
        // sprint can be kicked off while future ones stay queued.
        $assignableReady = 0;
        $readyBySprint   = [];
        foreach (array_merge($blocked, $items) as $r) {
            $isReady = (int)($r['proposed_sprints_id'] ?? 0) > 0
                && (int)($r['users_id'] ?? 0) > 0
                && (float)($r['capacity'] ?? 0) > 0;
            if (!$isReady) {
                continue;
            }
            $flags = self::computeRowFlags($r);
            if (!$flags['can_assign']) {
                continue;
            }
            $assignableReady++;
            $sid = (int)$r['proposed_sprints_id'];
            $readyBySprint[$sid] = ($readyBySprint[$sid] ?? 0) + 1;
        }

        echo "<div style='display:flex;align-items:center;gap:10px;margin-top:20px;'>";
        echo "<h3 style='margin:0;text-align:left;flex:1;'>"
            . "<i class='fas fa-list'></i> " . __('Backlog items', 'sprint')
            . " <span class='badge bg-secondary sprint-backlog-count'>" . count($items) . "</span></h3>";
        if ($canedit && $assignableReady > 0) {
            echo "<button type='button' class='btn btn-sm btn-success sprint-backlog-bulk-assign'>"
                . "<i class='fas fa-layer-group me-1'></i>"
                . sprintf(__('Assign all ready (%d)', 'sprint'), $assignableReady)
                . "</button>";
        }
        echo "</div>";

        // Owners present in the backlog, for the owner filter.
        $owners = [];
        foreach (array_merge($blocked, $items) as $r) {
            $uid = (int)($r['users_id'] ?? 0);
            if ($uid > 0 && !isset($owners[$uid])) {
                $owners[$uid] = SprintCache::userName($uid);
            }
        }
        asort($owners);

        self::renderFilterBar($typeLabels, $owners);

        // One collapsible section per admin-defined category ("mini kanban"),
        // plus a catch-all for uncategorized items. Empty categories still
        // render so rows can be dragged into them.
        $categories = SprintCategory::getAll();
        $byCat      = [];
        foreach ($items as $row) {
            $cid = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
            if ($cid > 0 && !isset($categories[$cid])) {
                $cid = 0; // deactivated category → show with the uncategorized items
            }
            $byCat[$cid][] = $row;
        }

        foreach ($categories as $cid => $cat) {
            self::renderCategorySection(
                (int)$cid,
                (string)$cat['name'],
                (string)$cat['color'],
                $byCat[(int)$cid] ?? [],
                $canedit,
                $typeLabels,
                $tagsById,
                $sprintNames,
                (int)($cat['level'] ?? 0)
            );
        }
        self::renderCategorySection(
            0,
            __('No category', 'sprint'),
            '#6c757d',
            $byCat[0] ?? [],
            $canedit,
            $typeLabels,
            $tagsById,
            $sprintNames
        );

        self::renderParkedSection($parked, $canedit, $typeLabels, $tagsById, $sprintNames);

        echo "</div>";

        // Mounts the modal + JS for the quick-edit-linked-item buttons rendered
        // by SprintItem::getLinkedItemDisplay(); without it they're no-ops.
        SprintItem::renderLinkedQuickEditUI();

        self::renderInlineEditScript($readyBySprint, $sprintNames);
        self::renderDependencyUI();
        if ($canedit) {
            self::renderEditModal();
        }
    }

    /**
     * Compact backlog table header. Type/fastlane/blocked collapsed into the
     * name cell as icons; owner/sprint/capacity are read-only — editing
     * happens in the per-row modal.
     */
    private static function renderTableHeaders(bool $canedit, bool $reorderable): void
    {
        echo "<tr class='tab_bg_2'>";
        if ($reorderable && $canedit) {
            echo "<th style='width:26px;' title='" . __('Drag to reorder', 'sprint') . "'></th>";
        }
        if ($reorderable) {
            echo "<th style='width:40px;' title='" . __s('Priority rank — the higher an item sits, the sooner it should be picked up', 'sprint') . "'>#</th>";
        }
        echo "<th>" . __('Name') . "</th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th><i class='fas fa-user' style='margin-right:4px;'></i>" . __('Owner', 'sprint') . "</th>";
        echo "<th><i class='fas fa-flag-checkered' style='margin-right:4px;'></i>" . __('Sprint') . "</th>";
        echo "<th title='" . __('Estimated capacity (informational on backlog, enforced once the item joins a sprint)', 'sprint') . "'>"
            . __('Est. capacity', 'sprint') . " %</th>";
        if ($canedit) {
            echo "<th style='width:120px;'>" . __('Actions') . "</th>";
        }
        echo "</tr>";
    }

    /** Number of columns a backlog table renders, for empty-row colspans. */
    private static function columnCount(bool $canedit, bool $reorderable): int
    {
        return 5
            + (($reorderable && $canedit) ? 1 : 0)
            + ($reorderable ? 1 : 0)
            + ($canedit ? 1 : 0);
    }

    /** Collapsible category section with count, capacity subtotal and ranks. */
    private static function renderCategorySection(int $catId, string $name, string $color, array $rows, bool $canedit, array $typeLabels, array $tagsById, array $sprintNames, int $level = 0): void
    {
        $count    = count($rows);
        $capSum   = 0.0;
        foreach ($rows as $r) {
            $capSum += (float)($r['capacity'] ?? 0);
        }
        $colorEsc = htmlescape($color);
        $capLabel = SprintMember::formatCapacity($capSum);
        // Subcategory sections nest visually under their parent section.
        $indent   = $level > 0 ? "margin:8px 0 8px 28px;" : "margin:14px 0;";
        $folder   = $level > 0 ? 'fa-folder-open' : 'fa-folder';

        echo "<div class='sprint-backlog-cat-section' data-category-id='{$catId}' "
            . "style='{$indent}border:1px solid var(--tblr-border-color,#e2e8f0);border-left:4px solid {$colorEsc};border-radius:8px;overflow:hidden;'>";
        echo "<div class='sprint-backlog-cat-header' "
            . "style='display:flex;align-items:center;gap:8px;padding:9px 14px;font-weight:700;cursor:pointer;user-select:none;"
            . "background:color-mix(in srgb,{$colorEsc} 8%,var(--tblr-bg-surface,#fff));'>";
        echo "<i class='fas fa-chevron-down sprint-backlog-cat-chevron' style='transition:transform 0.15s;'></i>";
        if ($level > 0) {
            echo "<i class='fas fa-turn-up fa-rotate-90 text-muted' style='font-size:0.8em;'></i>";
        }
        echo "<i class='fas {$folder}' style='color:{$colorEsc};'></i>";
        echo "<span>" . htmlescape($name) . "</span>";
        echo "<span class='badge bg-secondary sprint-backlog-cat-count'>{$count}</span>";
        echo "<span style='flex:1;'></span>";
        echo "<span class='text-muted small' style='font-weight:400;' title='"
            . __s('Total estimated capacity on the backlog in this category', 'sprint') . "'>"
            . "<i class='fas fa-gauge-high me-1'></i>"
            . "<span class='sprint-backlog-cat-capsum'>{$capLabel}%</span></span>";
        echo "</div>";

        echo "<div class='sprint-backlog-cat-body'>";
        echo "<table class='tab_cadre_fixe sprint-themed sprint-backlog-table' style='margin:0;'>";
        self::renderTableHeaders($canedit, true);
        $cols = self::columnCount($canedit, true);
        echo "<tr class='sprint-backlog-empty-row'" . ($count > 0 ? " style='display:none;'" : '') . ">"
            . "<td colspan='{$cols}' class='center text-muted'>"
            . __('No items — drag a row here or pick this category in the edit dialog.', 'sprint')
            . "</td></tr>";
        $rank = 1;
        foreach ($rows as $row) {
            self::renderItemRow($row, $canedit, $typeLabels, $tagsById, true, $sprintNames, $rank++, false);
        }
        echo "</table></div></div>";
    }

    /** Parked (non-active) tier: long-term work outside planning and totals. */
    private static function renderParkedSection(array $parkedItems, bool $canedit, array $typeLabels, array $tagsById, array $sprintNames): void
    {
        $count = count($parkedItems);

        echo "<div class='sprint-backlog-parked' style='margin:18px 0;border:1px solid var(--tblr-border-color,#e2e8f0);border-radius:8px;overflow:hidden;'>";
        echo "<div class='sprint-backlog-parked-header' "
            . "style='display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--tblr-bg-surface-secondary,#f8fafc);color:var(--tblr-secondary,#6c757d);font-weight:700;cursor:pointer;user-select:none;'>";
        echo "<i class='fas fa-chevron-down sprint-backlog-parked-chevron' style='transition:transform 0.15s;'></i>";
        echo "<i class='fas fa-box-archive'></i>";
        echo "<span>" . __('Non-active (long term)', 'sprint') . "</span>";
        echo "<span class='badge bg-secondary'>" . $count . "</span>";
        echo "<span style='flex:1;'></span>";
        echo "<span class='text-muted small' style='font-weight:400;'>"
            . __('Low-priority / long-term work, outside the active planning and capacity totals.', 'sprint') . "</span>";
        echo "</div>";

        echo "<div class='sprint-backlog-parked-body' style='padding:0;'>";
        if ($count === 0) {
            echo "<div class='center text-muted' style='padding:14px;'>"
                . __('No parked items.', 'sprint') . "</div>";
        } else {
            echo "<table class='tab_cadre_fixe sprint-themed' style='margin:0;'>";
            self::renderTableHeaders($canedit, false);
            foreach ($parkedItems as $row) {
                self::renderItemRow($row, $canedit, $typeLabels, $tagsById, false, $sprintNames);
            }
            echo "</table>";
        }
        echo "</div></div>";

        echo "<script>
        (function() {
            var key = 'sprint.backlog.parked.collapsed';
            $(function() {
                var \$wrap = $('.sprint-backlog-parked').last();
                if (!\$wrap.length) return;
                var \$body = \$wrap.find('.sprint-backlog-parked-body');
                var \$chev = \$wrap.find('.sprint-backlog-parked-chevron');
                if (localStorage.getItem(key) !== '0') {
                    \$body.hide();
                    \$chev.css('transform', 'rotate(-90deg)');
                }
                \$wrap.find('.sprint-backlog-parked-header').on('click', function() {
                    var collapsed = \$body.is(':visible');
                    \$body.slideToggle(120);
                    \$chev.css('transform', collapsed ? 'rotate(-90deg)' : 'rotate(0deg)');
                    localStorage.setItem(key, collapsed ? '1' : '0');
                });
            });
        })();
        </script>";
    }

    /**
     * Per-row edit modal: the backlog rows are read-only and compact; every
     * editable field (name, owner, estimated capacity, target sprint,
     * fastlane/blocked flags) lives here. Saving goes through
     * ajax/updateitemquick.php and reloads the page so derived bits (ready
     * badge, capacity chip, blocked section) stay consistent.
     */
    private static function renderEditModal(): void
    {
        $endpoint    = Plugin::getWebDir('sprint') . '/ajax/updateitemquick.php';
        $tokenUrl    = Plugin::getWebDir('sprint') . '/ajax/csrftoken.php';
        $capacityUrl = Plugin::getWebDir('sprint') . '/ajax/backlogcapacity.php';
        $rowUrl      = Plugin::getWebDir('sprint') . '/ajax/backlogrow.php';

        $lblTitle    = __s('Edit backlog item', 'sprint');
        $lblName     = __s('Name');
        $lblOwner    = __s('Owner', 'sprint');
        $lblCapacity = __s('Est. capacity', 'sprint');
        $lblSprint   = __s('Assign to sprint', 'sprint');
        $lblFastlane = __s('Fastlane', 'sprint');
        $lblBlocked  = __s('Blocked', 'sprint');
        $lblAdhoc    = __s('Adhoc', 'sprint');
        $adhocHint   = __s('Marks the item as post-kick-off work. The flag travels with it into the sprint.', 'sprint');
        $lblNameFollows = addslashes(__('The name follows the linked item and cannot be edited here.', 'sprint'));
        $lblCancel   = __s('Cancel');
        $lblSave     = __s('Save');
        $lblDelete   = __s('Delete');
        $lblDeps     = __s('Add dependency', 'sprint');
        $sprintHint  = __s('Pre-selecting a sprint marks the item for the Scrum Master to assign at kick-off.', 'sprint');

        echo "<div class='modal fade' id='sprint-backlog-edit-modal' tabindex='-1' aria-hidden='true'>";
        echo "<div class='modal-dialog modal-dialog-centered modal-lg'>";
        echo "<div class='modal-content'>";
        echo "<div class='modal-header'>";
        echo "<h5 class='modal-title'><i class='fas fa-pen me-1'></i> {$lblTitle}: <span class='sprint-be-title'></span></h5>";
        echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>";
        echo "</div>";
        echo "<div class='modal-body'>";
        echo "<div class='alert alert-danger sprint-be-error' style='display:none;white-space:pre-line;'></div>";
        echo "<input type='hidden' name='id' value=''>";

        echo "<div class='mb-3'><label class='form-label'>{$lblName}</label>";
        echo "<input type='text' name='name' class='form-control' value=''></div>";

        echo "<div class='row g-3'>";
        echo "<div class='col-md-6 mb-3'><label class='form-label'>{$lblOwner}</label>";
        User::dropdown([
            'name'   => '_backlog_modal_users_id',
            'value'  => 0,
            'right'  => 'all',
            'entity' => $_SESSION['glpiactiveentities'] ?? -1,
            'rand'   => 424242,
            'width'  => '100%',
        ]);
        echo "</div>";
        echo "<div class='col-md-6 mb-3'><label class='form-label'>{$lblCapacity} %</label>";
        Dropdown::showFromArray('_backlog_modal_capacity', SprintMember::getCapacityChoices(), [
            'value' => 0,
            'rand'  => 424244,
            'width' => '100%',
        ]);
        echo "</div>";
        echo "</div>";

        echo "<div class='row g-3'>";
        echo "<div class='col-md-6 mb-3'><label class='form-label'>{$lblSprint}</label>";
        Sprint::dropdown([
            'name'  => '_backlog_modal_sprint_id',
            'value' => 0,
            'rand'  => 424243,
            'width' => '100%',
        ]);
        echo "<div class='form-text small text-muted'>{$sprintHint}</div>";
        echo "</div>";
        echo "<div class='col-md-6 mb-3'><label class='form-label'>"
            . "<i class='fas fa-folder me-1'></i>" . __s('Category', 'sprint') . "</label>";
        echo "<select class='form-select' name='_backlog_modal_category'>";
        echo "<option value='0'>-----</option>";
        echo SprintCategory::dropdownOptions();
        echo "</select></div>";
        echo "</div>";

        // Live capacity preview for the chosen owner in the chosen sprint.
        echo "<div class='sprint-be-cap-preview alert py-2 small mb-3' style='display:none;'></div>";

        echo "<div class='d-flex gap-4 mb-2'>";
        echo "<div class='form-check form-switch'>";
        echo "<input class='form-check-input' type='checkbox' id='sprint-be-fastlane' name='is_fastlane'>";
        echo "<label class='form-check-label' for='sprint-be-fastlane'><i class='fas fa-bolt' style='color:#fd7e14;'></i> {$lblFastlane}</label>";
        echo "</div>";
        echo "<div class='form-check form-switch'>";
        echo "<input class='form-check-input' type='checkbox' id='sprint-be-blocked' name='is_blocked'>";
        echo "<label class='form-check-label' for='sprint-be-blocked'><i class='fas fa-ban' style='color:#dc3545;'></i> {$lblBlocked}</label>";
        echo "</div>";
        echo "<div class='form-check form-switch' title='{$adhocHint}'>";
        echo "<input class='form-check-input' type='checkbox' id='sprint-be-adhoc' name='is_adhoc'>";
        echo "<label class='form-check-label' for='sprint-be-adhoc'><i class='fas fa-plus-circle' style='color:#6f42c1;'></i> {$lblAdhoc}</label>";
        echo "</div>";
        $parkedHint = __s('Long-term / low-priority: keeps the item out of the active planning sections and capacity totals.', 'sprint');
        echo "<div class='form-check form-switch' title='{$parkedHint}'>";
        echo "<input class='form-check-input' type='checkbox' id='sprint-be-parked' name='is_parked'>";
        echo "<label class='form-check-label' for='sprint-be-parked'><i class='fas fa-box-archive' style='color:#6c757d;'></i> " . __s('Non-active', 'sprint') . "</label>";
        echo "</div>";
        echo "</div>";

        // Tags can be assigned while the item is still on the backlog, so
        // filtering and planning don't have to wait for sprint assignment.
        $definedTags = Config::getDefinedTags();
        if (!empty($definedTags)) {
            echo "<div class='mb-2 sprint-be-tags-block'><label class='form-label'>"
                . "<i class='fas fa-tags me-1'></i>" . __('Tags', 'sprint') . "</label>";
            echo "<div class='d-flex flex-wrap gap-3'>";
            foreach ($definedTags as $tag) {
                echo "<label style='display:inline-flex;align-items:center;gap:6px;'>"
                    . "<input type='checkbox' class='sprint-be-tag' value='" . htmlescape($tag) . "'>"
                    . "<span>" . htmlescape($tag) . "</span>"
                    . "</label>";
            }
            echo "</div></div>";
        }

        echo "</div>";
        echo "<div class='modal-footer'>";
        // Delete keeps its POST flow (backlog.form.php purge) with confirm.
        echo "<form method='post' action='" . self::getFormURL() . "' class='me-auto sprint-be-delete-form' "
            . "onsubmit=\"return confirm('" . __s('Confirm deletion?') . "');\">";
        echo Html::hidden('id', ['value' => 0]);
        echo "<button type='submit' name='purge' value='1' class='btn btn-outline-danger'>"
            . "<i class='fas fa-trash me-1'></i>{$lblDelete}</button>";
        Html::closeForm();
        echo "<button type='button' class='btn btn-outline-secondary sprint-be-deps-open'>"
            . "<i class='fas fa-link me-1'></i>{$lblDeps}</button>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>{$lblCancel}</button>";
        echo "<button type='button' class='btn btn-primary sprint-be-save'><i class='fas fa-save me-1'></i> {$lblSave}</button>";
        echo "</div>";
        echo "</div></div></div>";

        $msgNotMember  = addslashes(__('Owner is not a member of the selected sprint', 'sprint'));
        $msgPreview    = addslashes(__('Total %total%% — used in sprint %used%%, pending from backlog %pending%%, this item %own%% → %free%% free after assigning', 'sprint'));
        $msgSaveFailed = addslashes(__('Save failed', 'sprint'));

        echo <<<HTML
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }
    var endpoint    = "{$endpoint}";
    var tokenUrl    = "{$tokenUrl}";
    var capacityUrl = "{$capacityUrl}";
    var \$modal;

    jQuery(function(){
        \$modal = jQuery('#sprint-backlog-edit-modal');
        if (\$modal.length === 0) { return; }
        \$modal = \$modal.detach().appendTo('body');

        // Live preview refresh when owner / sprint / capacity change; the
        // dependency button stays greyed out until a sprint is selected.
        \$modal.on('change', "select[name='_backlog_modal_users_id'], select[name='_backlog_modal_sprint_id'], select[name='_backlog_modal_capacity']", function(){
            refreshPreview();
            updateDepsButton();
        });
    });

    function updateDepsButton() {
        var sprintId = parseInt(\$modal.find("select[name='_backlog_modal_sprint_id']").val(), 10) || 0;
        \$modal.find('.sprint-be-deps-open').prop('disabled', sprintId <= 0);
    }

    // Select a value without wiping preloaded options (the sprint dropdown is
    // a static select); append the option only when it's missing (ajax
    // select2s like the user dropdown, or a no-longer-listed sprint).
    function setSelect2(\$sel, value, label) {
        if (!\$sel.length) { return; }
        if (!\$sel.find("option[value='" + String(value) + "']").length) {
            \$sel.append(new Option(label || '-----', String(value)));
        }
        \$sel.val(String(value)).trigger('change');
    }

    function refreshPreview() {
        var \$box     = \$modal.find('.sprint-be-cap-preview');
        var sprintId = parseInt(\$modal.find("select[name='_backlog_modal_sprint_id']").val(), 10) || 0;
        var userId   = parseInt(\$modal.find("select[name='_backlog_modal_users_id']").val(), 10) || 0;
        var own      = parseFloat(\$modal.find("select[name='_backlog_modal_capacity']").val()) || 0;
        var itemId   = parseInt(\$modal.find('input[name=id]').val(), 10) || 0;

        if (sprintId <= 0 || userId <= 0) {
            \$box.hide();
            return;
        }
        jQuery.ajax({
            url: capacityUrl, type: 'GET', dataType: 'json', cache: false,
            data: { sprint_id: sprintId, users_id: userId, item_id: itemId }
        }).done(function(resp){
            if (!resp || !resp.success) { \$box.hide(); return; }
            \$box.removeClass('alert-info alert-warning alert-danger alert-success');
            if (!resp.is_member) {
                \$box.addClass('alert-warning')
                    .html('<i class="fas fa-user-slash me-1"></i>{$msgNotMember}')
                    .show();
                return;
            }
            var total   = parseFloat(resp.total) || 0;
            var used    = parseFloat(resp.used) || 0;
            var pending = parseFloat(resp.pending_other) || 0;
            var free    = total - used - pending - own;
            var cls     = free < 0 ? 'alert-danger' : (free < 10 ? 'alert-warning' : 'alert-success');
            var txt     = '{$msgPreview}'
                .replace('%total%', total)
                .replace('%used%', used)
                .replace('%pending%', pending)
                .replace('%own%', own)
                .replace('%free%', Math.round(free * 10) / 10);
            \$box.addClass(cls).html('<i class="fas fa-gauge-high me-1"></i>' + txt).show();
        }).fail(function(){ \$box.hide(); });
    }

    jQuery(document).on('click', '.sprint-backlog-edit-btn', function(){
        var \$row = jQuery(this).closest('tr.sprint-backlog-row');
        if (!\$row.length) { return; }
        var itemId = parseInt(\$row.data('item-id'), 10) || 0;
        if (itemId <= 0) { return; }

        \$modal.find('.sprint-be-error').hide().text('');
        \$modal.find('.sprint-be-title').text(\$row.data('item-name') || '');
        \$modal.find('input[name=id]').val(itemId);
        \$modal.find('input[name=name]').val(\$row.data('item-name') || '');
        // Linked items mirror the underlying item's name (server-enforced);
        // only manual items expose an editable name.
        (function() {
            var isLinked = String(\$row.data('item-type') || 'manual') !== 'manual';
            \$modal.find('input[name=name]')
                .prop('readonly', isLinked)
                .attr('title', isLinked ? '{$lblNameFollows}' : '');
        })();
        \$modal.find('input[name=is_fastlane]').prop('checked', parseInt(\$row.data('is-fastlane'), 10) === 1);
        \$modal.find('input[name=is_blocked]').prop('checked', parseInt(\$row.data('is-blocked'), 10) === 1);
        \$modal.find('input[name=is_adhoc]').prop('checked', parseInt(\$row.attr('data-is-adhoc'), 10) === 1);
        \$modal.find('input[name=is_parked]').prop('checked', parseInt(\$row.attr('data-is-parked'), 10) === 1);
        \$modal.find("select[name='_backlog_modal_category']").val(String(parseInt(\$row.attr('data-category-id'), 10) || 0));
        // Populate tag checkboxes from the row's `|tag1|tag2|` lowercased blob.
        var tagBlob = String(\$row.attr('data-item-tags') || '|').toLowerCase();
        \$modal.find('.sprint-be-tag').each(function() {
            var v = String(jQuery(this).val() || '').toLowerCase();
            jQuery(this).prop('checked', v !== '' && tagBlob.indexOf('|' + v + '|') !== -1);
        });
        \$modal.find('.sprint-be-delete-form input[name=id]').val(itemId);
        \$modal.find('.sprint-be-deps-open').toggle(parseInt(\$row.data('is-fastlane'), 10) !== 1);

        var ownerId   = parseInt(\$row.data('users-id'), 10) || 0;
        var ownerName = \$row.data('owner-name') || '';
        setSelect2(\$modal.find("select[name='_backlog_modal_users_id']"), ownerId, ownerId > 0 ? ownerName : '-----');

        var sprintId   = parseInt(\$row.data('proposed-sprint-id'), 10) || 0;
        var sprintName = \$row.data('proposed-sprint-name') || '';
        setSelect2(\$modal.find("select[name='_backlog_modal_sprint_id']"), sprintId, sprintId > 0 ? sprintName : '-----');

        // Match the capacity option numerically ("0.5"/"5.0"/5 all resolve to
        // the same option) so decimal formatting never clears the selection.
        (function() {
            var \$sel = \$modal.find("select[name='_backlog_modal_capacity']");
            var num  = parseFloat(\$row.data('capacity'));
            if (isNaN(num)) { num = 0; }
            var match = '';
            \$sel.find('option').each(function() {
                if (parseFloat(this.value) === num) { match = this.value; return false; }
            });
            \$sel.val(match).trigger('change');
        })();

        refreshPreview();
        updateDepsButton();
        bootstrap.Modal.getOrCreateInstance(\$modal[0]).show();
    });

    function saveItem() {
        var itemId = parseInt(\$modal.find('input[name=id]').val(), 10) || 0;
        var tags = [];
        \$modal.find('.sprint-be-tag:checked').each(function() {
            tags.push(jQuery(this).val());
        });
        return jQuery.ajax({
            url: tokenUrl, type: 'GET', dataType: 'json', cache: false
        }).then(function(tokResp){
            return jQuery.ajax({
                url: endpoint, type: 'POST', dataType: 'json',
                data: {
                    _tags_json: JSON.stringify(tags),
                    id: itemId,
                    name: \$modal.find('input[name=name]').val(),
                    users_id: parseInt(\$modal.find("select[name='_backlog_modal_users_id']").val(), 10) || 0,
                    capacity: parseFloat(\$modal.find("select[name='_backlog_modal_capacity']").val()) || 0,
                    proposed_sprints_id: parseInt(\$modal.find("select[name='_backlog_modal_sprint_id']").val(), 10) || 0,
                    is_fastlane: \$modal.find('input[name=is_fastlane]').is(':checked') ? 1 : 0,
                    is_blocked: \$modal.find('input[name=is_blocked]').is(':checked') ? 1 : 0,
                    is_adhoc: \$modal.find('input[name=is_adhoc]').is(':checked') ? 1 : 0,
                    is_parked: \$modal.find('input[name=is_parked]').is(':checked') ? 1 : 0,
                    plugin_sprint_sprintcategories_id: parseInt(\$modal.find("select[name='_backlog_modal_category']").val(), 10) || 0,
                    _glpi_csrf_token: tokResp && tokResp.token ? tokResp.token : ''
                }
            });
        });
    }

    // In-place row swap; a category change moves the row to its new section
    // without a page load. Only a blocked/parked tier change still reloads,
    // because those lists use a different table layout.
    function refreshRow(itemId, done) {
        jQuery.ajax({
            url: "{$rowUrl}", type: 'GET', dataType: 'json', cache: false,
            data: { id: itemId }
        }).done(function(resp){
            if (!resp || !resp.success || !resp.html) {
                window.location.reload();
                return;
            }
            var \$old = jQuery('tr.sprint-backlog-row[data-item-id="' + itemId + '"]');
            if (!\$old.length) { window.location.reload(); return; }

            var wasBlocked = parseInt(\$old.attr('data-is-blocked'), 10) === 1;
            var wasParked  = parseInt(\$old.attr('data-is-parked'), 10) === 1;
            var wasCat     = parseInt(\$old.attr('data-category-id'), 10) || 0;
            var newCat     = parseInt(resp.category_id, 10) || 0;
            if (wasBlocked !== (parseInt(resp.is_blocked, 10) === 1)
                || wasParked !== (parseInt(resp.is_parked, 10) === 1)) {
                window.location.reload();
                return;
            }
            var \$new = jQuery(resp.html);
            if (wasCat !== newCat && !wasBlocked && !wasParked) {
                var \$target = jQuery('.sprint-backlog-cat-section[data-category-id="' + newCat + '"] .sprint-backlog-table tbody').first();
                if (!\$target.length) { window.location.reload(); return; }
                \$old.remove();
                \$target.append(\$new);
            } else {
                \$old.replaceWith(\$new);
            }
            if (window.sprintBacklogRefreshSections) { window.sprintBacklogRefreshSections(); }
            if (window.sprintBacklogReloadMatrix) { window.sprintBacklogReloadMatrix(); }
            if (done) { done(); }
        }).fail(function(){ window.location.reload(); });
    }

    jQuery(document).on('click', '.sprint-be-save', function(){
        var \$btn   = jQuery(this);
        var itemId = parseInt(\$modal.find('input[name=id]').val(), 10) || 0;
        if (itemId <= 0) { return; }
        \$btn.prop('disabled', true);
        saveItem().done(function(resp){
            if (resp && resp.success) {
                if (resp.message && resp.message !== 'Item updated' && window.glpi_toast_info) {
                    window.glpi_toast_info(resp.message);
                }
                refreshRow(itemId, function(){
                    \$btn.prop('disabled', false);
                    bootstrap.Modal.getOrCreateInstance(\$modal[0]).hide();
                });
            } else {
                \$modal.find('.sprint-be-error')
                    .text((resp && resp.message) || '{$msgSaveFailed}').show();
                \$btn.prop('disabled', false);
            }
        }).fail(function(){
            \$modal.find('.sprint-be-error').text('Network error').show();
            \$btn.prop('disabled', false);
        });
    });

    // Add dependency: persist the item first (the endpoint only accepts
    // dependencies for a saved proposed sprint), then stack the deps modal on
    // top of this one so closing it lands back here.
    jQuery(document).on('click', '.sprint-be-deps-open', function(){
        var \$btn     = jQuery(this);
        var itemId   = parseInt(\$modal.find('input[name=id]').val(), 10) || 0;
        var sprintId = parseInt(\$modal.find("select[name='_backlog_modal_sprint_id']").val(), 10) || 0;
        if (itemId <= 0 || sprintId <= 0) { return; }
        \$btn.prop('disabled', true);
        saveItem().done(function(resp){
            \$btn.prop('disabled', false);
            if (!resp || !resp.success) {
                \$modal.find('.sprint-be-error')
                    .text((resp && resp.message) || '{$msgSaveFailed}').show();
                return;
            }
            var name    = \$modal.find('input[name=name]').val() || '';
            var ownerId = parseInt(\$modal.find("select[name='_backlog_modal_users_id']").val(), 10) || 0;
            jQuery('tr.sprint-backlog-row[data-item-id="' + itemId + '"]')
                .attr('data-proposed-sprint-id', sprintId);
            if (window.sprintBacklogOpenDeps) {
                window.sprintBacklogOpenDeps(itemId, name, ownerId, sprintId);
            }
        }).fail(function(){
            \$modal.find('.sprint-be-error').text('Network error').show();
            \$btn.prop('disabled', false);
        });
    });
})();
</script>
HTML;
    }

    /**
     * Modal + JS for the per-row "add dependency" button. Dependencies are
     * limited to members of the row's pre-selected sprint; the member list is
     * fetched live for whatever sprint is chosen in that row's dropdown.
     */
    private static function renderDependencyUI(): void
    {
        $depEndpoint = Plugin::getWebDir('sprint') . '/ajax/dependencyadd.php';
        $membersUrl  = Plugin::getWebDir('sprint') . '/ajax/getsprintmembers.php';
        $tokenUrl    = Plugin::getWebDir('sprint') . '/ajax/csrftoken.php';
        $depsUrl     = Plugin::getWebDir('sprint') . '/ajax/dependencies.php';

        $capacityOptions = '';
        foreach (SprintMember::getCapacityChoices(false) as $val => $label) {
            $capacityOptions .= "<option value='" . htmlescape((string)$val) . "'>" . htmlescape($label) . "</option>";
        }

        $titleAdd      = __('Add dependency', 'sprint');
        $lblMember     = __('Sprint member', 'sprint');
        $lblCapacity   = __('Capacity', 'sprint');
        $lblCancel     = __('Cancel');
        $lblAdd        = __('Add', 'sprint');
        $msgPickSprint = addslashes(__('Pre-select a sprint for this item first (edit the item and choose a sprint).', 'sprint'));
        $msgNoMembers  = addslashes(__('The selected sprint has no members yet.', 'sprint'));
        $msgSelectBoth = addslashes(__('Please select a sprint member and a capacity > 0', 'sprint'));
        $hint          = __('Only members of the pre-selected sprint can be added.', 'sprint');

        echo <<<HTML
<div class="modal fade" id="sprint-backlog-deps-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-link me-1"></i> {$titleAdd}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted sprint-deps-item-name fw-bold mb-2"></p>
        <p class="text-muted small">{$hint}</p>
        <div class="alert alert-warning py-1 small sprint-deps-status mb-2" style="display:none;"></div>
        <div class="sprint-deps-list small mb-2"></div>
        <div class="mb-2">
          <label class="form-label fw-bold">{$lblMember}</label>
          <select class="form-select sprint-deps-member"></select>
        </div>
        <div class="mb-2">
          <label class="form-label fw-bold">{$lblCapacity} %</label>
          <select class="form-select sprint-deps-capacity">{$capacityOptions}</select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$lblCancel}</button>
        <button type="button" class="btn btn-primary sprint-deps-add">{$lblAdd}</button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }
    var depEndpoint = "{$depEndpoint}";
    var membersUrl  = "{$membersUrl}";
    var tokenUrl    = "{$tokenUrl}";
    var \$modal;

    // Modals below this one keep their focus trap and their body lock, so the
    // deps dialog can open on top of the edit modal and hand control back.
    function modalsBelow() {
        return jQuery('.modal.show').not(\$modal);
    }
    function setFocusTrap(\$modals, on) {
        \$modals.each(function(){
            var inst = bootstrap.Modal.getInstance(this);
            try { on ? inst._focustrap.activate() : inst._focustrap.deactivate(); } catch (e) {}
        });
    }

    jQuery(function(){
        \$modal = jQuery('#sprint-backlog-deps-modal');
        if (\$modal.length === 0) { return; }
        \$modal = \$modal.detach().appendTo('body');

        \$modal.on('show.bs.modal', function(){
            var \$below = modalsBelow();
            if (!\$below.length) { return; }
            \$modal.css('z-index', 1065);
            setFocusTrap(\$below, false);
        });
        \$modal.on('shown.bs.modal', function(){
            if (modalsBelow().length) {
                jQuery('.modal-backdrop').last().css('z-index', 1060);
            }
        });
        \$modal.on('hidden.bs.modal', function(){
            \$modal.css('z-index', '');
            var \$below = modalsBelow();
            if (!\$below.length) { return; }
            jQuery(document.body).addClass('modal-open');
            setFocusTrap(\$below, true);
        });
    });

    // The compact rows no longer embed a sprint dropdown: the pre-selected
    // sprint is exposed on the row as data-proposed-sprint-id.
    function currentSprintFor(itemId) {
        var \$row = jQuery('tr.sprint-backlog-row[data-item-id="' + itemId + '"]');
        return \$row.length ? (parseInt(\$row.attr('data-proposed-sprint-id'), 10) || 0) : 0;
    }

    // Feedback that never fails silently: toast when GLPI provides one,
    // alert() otherwise (older builds lack glpi_toast_warning).
    function depsWarn(msg) {
        if (window.glpi_toast_warning) { window.glpi_toast_warning(msg); }
        else if (window.glpi_toast_info) { window.glpi_toast_info(msg); }
        else { alert(msg); }
    }

    function showDepsStatus(msg, ok) {
        var \$status = \$modal ? \$modal.find('.sprint-deps-status') : jQuery();
        if (\$status.length) {
            \$status.toggleClass('alert-warning', !ok).toggleClass('alert-success', !!ok)
                .text(msg).show();
        } else { depsWarn(msg); }
    }

    // Current dependency list, so multiple helpers can be added in a row.
    function loadDepsList(itemId) {
        var \$list = \$modal.find('.sprint-deps-list').empty();
        jQuery.ajax({
            url: "{$depsUrl}", type: 'GET', dataType: 'json', cache: false,
            data: { action: 'list', plugin_sprint_sprintitems_id: itemId }
        }).done(function(resp){
            if (!resp || !resp.success || !resp.deps || !resp.deps.length) { return; }
            resp.deps.forEach(function(d){
                var \$line = jQuery('<div class="d-flex align-items-center gap-2 py-1"></div>');
                var \$name = jQuery('<span></span>').append(
                    jQuery('<i class="fas fa-link me-1" style="color:#20c997;font-size:0.8em;"></i>')
                ).append(document.createTextNode(d.name + ' (' + d.capacity + '%)'));
                if (parseInt(d.is_resolved, 10) === 1) {
                    \$name.css('text-decoration', 'line-through').addClass('text-muted');
                }
                \$line.append(\$name);
                \$line.append(
                    jQuery('<button type="button" class="btn btn-sm btn-outline-danger sprint-deps-remove ms-auto" style="padding:0 6px;"><i class="fas fa-times"></i></button>')
                        .attr('data-dep-id', d.id)
                );
                \$list.append(\$line);
            });
        });
    }

    jQuery(document).on('click', '.sprint-deps-remove', function(){
        var \$btn   = jQuery(this);
        var depId  = parseInt(\$btn.attr('data-dep-id'), 10) || 0;
        var itemId = parseInt(\$modal.data('item-id'), 10) || 0;
        if (depId <= 0 || itemId <= 0) { return; }
        \$btn.prop('disabled', true);
        jQuery.ajax({
            url: tokenUrl, type: 'GET', dataType: 'json', cache: false
        }).then(function(tokResp){
            return jQuery.ajax({
                url: "{$depsUrl}", type: 'POST', dataType: 'json',
                data: {
                    action: 'remove',
                    plugin_sprint_sprintitems_id: itemId,
                    id: depId,
                    _glpi_csrf_token: tokResp && tokResp.token ? tokResp.token : ''
                }
            });
        }).done(function(resp){
            if (resp && resp.success) { loadDepsList(itemId); }
            else { \$btn.prop('disabled', false); }
        }).fail(function(){ \$btn.prop('disabled', false); });
    });

    function openDepsModal(itemId, name, ownerId, sprintId) {
        if (itemId <= 0) { return; }
        if (!sprintId || sprintId <= 0) { sprintId = currentSprintFor(itemId); }
        if (sprintId <= 0) {
            depsWarn("{$msgPickSprint}");
            return;
        }
        \$modal.data('item-id', itemId);
        \$modal.find('.sprint-deps-item-name').text(name);
        \$modal.find('.sprint-deps-status').hide().text('');
        loadDepsList(itemId);
        var \$member = \$modal.find('.sprint-deps-member');
        \$member.html('<option value="0">…</option>');

        jQuery.ajax({
            url: membersUrl, type: 'GET', dataType: 'json', cache: false,
            data: { sprint_id: sprintId, exclude_user: ownerId }
        }).done(function(resp){
            \$member.empty();
            if (resp && resp.success && resp.members && resp.members.length) {
                resp.members.forEach(function(m){
                    \$member.append(jQuery('<option>').val(m.id).text(m.label));
                });
            } else {
                \$member.append('<option value="0">{$msgNoMembers}</option>');
                showDepsStatus("{$msgNoMembers}");
            }
        });

        bootstrap.Modal.getOrCreateInstance(\$modal[0]).show();
    }
    // Used by the backlog edit modal's "Add dependency" button.
    window.sprintBacklogOpenDeps = openDepsModal;

    jQuery(document).on('click', '.sprint-backlog-deps-btn', function(){
        var \$btn = jQuery(this);
        openDepsModal(
            parseInt(\$btn.data('item-id'), 10) || 0,
            \$btn.data('item-name') || '',
            parseInt(\$btn.data('owner-id'), 10) || 0,
            0
        );
    });

    function doAdd(itemId, userId, capacity, confirmOverflow) {
        jQuery.ajax({
            url: tokenUrl, type: 'GET', dataType: 'json', cache: false
        }).then(function(tokResp){
            return jQuery.ajax({
                url: depEndpoint, type: 'POST', dataType: 'json',
                data: {
                    plugin_sprint_sprintitems_id: itemId,
                    users_id: userId,
                    capacity: capacity,
                    confirm_overflow: confirmOverflow ? 1 : 0,
                    _glpi_csrf_token: tokResp && tokResp.token ? tokResp.token : ''
                }
            });
        }).done(function(resp){
            if (resp && resp.success) {
                // Stay open so more helpers can be added in a row.
                showDepsStatus(resp.message || 'Saved', true);
                \$modal.find('.sprint-deps-member').val('0');
                loadDepsList(itemId);
            } else if (resp && resp.needs_confirm) {
                if (confirm(resp.message)) {
                    doAdd(itemId, userId, capacity, true);
                }
            } else {
                if (window.glpi_toast_error) { window.glpi_toast_error((resp && resp.message) || 'Failed'); }
                else { alert((resp && resp.message) || 'Failed'); }
            }
        }).fail(function(){
            if (window.glpi_toast_error) { window.glpi_toast_error('Network error'); }
            else { showDepsStatus('Network error'); }
        });
    }

    jQuery(document).on('click', '.sprint-deps-add', function(){
        var itemId   = parseInt(\$modal.data('item-id'), 10) || 0;
        var userId   = parseInt(\$modal.find('.sprint-deps-member').val(), 10) || 0;
        var capacity = parseFloat(\$modal.find('.sprint-deps-capacity').val()) || 0;
        if (itemId <= 0 || userId <= 0 || capacity <= 0) {
            showDepsStatus("{$msgSelectBoth}");
            return;
        }
        \$modal.find('.sprint-deps-status').hide();
        doAdd(itemId, userId, capacity, false);
    });
})();
</script>
HTML;
    }

    /**
     * Backlog list behaviors that live outside the edit modal: the bulk
     * "assign all ready" flow (with per-sprint scoping), the non-Scrum-Master
     * "request assignment" button and drag-to-reorder persistence.
     *
     * @param array<int,int>    $readyBySprint sprintId => count of ready items
     *                          the current user may assign
     * @param array<int,string> $sprintNames   sprintId => sprint name
     */
    private static function renderInlineEditScript(array $readyBySprint = [], array $sprintNames = []): void
    {
        $tokenUrl = Plugin::getWebDir('sprint') . '/ajax/csrftoken.php';
        $bulkUrl  = Plugin::getWebDir('sprint') . '/ajax/bulkassign.php';
        $requestUrl  = Plugin::getWebDir('sprint') . '/ajax/requestcreate.php';
        $reorderUrl  = Plugin::getWebDir('sprint') . '/ajax/reorder.php';
        $savedOrderMsg = addslashes(__('Order saved', 'sprint'));

        // Sprint-scope modal: assign everything, or kick off one sprint while
        // future sprints (already marked ready) stay queued.
        $lblBulkTitle  = __s('Assign ready backlog items', 'sprint');
        $lblBulkHint   = __s('Assign every ready item, or limit the kick-off to a single sprint — future sprints that are already marked ready stay on the backlog.', 'sprint');
        $lblBulkAll    = __s('All sprints', 'sprint');
        $lblCancel     = __s('Cancel');
        $lblBulkGo     = __s('Assign', 'sprint');

        $dor = Config::getDefinitionReady();
        $dorSection = '';
        if ($dor) {
            $dorSection = "<hr class='my-3'><label class='form-label fw-bold'>"
                . __s('Definition of Ready', 'sprint') . "</label>"
                . "<p class='text-muted small mb-2'>"
                . __s('Confirm the checks that hold for the items you are assigning.', 'sprint') . "</p>";
            foreach ($dor as $check) {
                $dorSection .= "<label class='form-check d-block'><input class='form-check-input sprint-dor-check' type='checkbox' value='"
                    . htmlescape($check) . "'><span class='form-check-label'>" . htmlescape($check) . "</span></label>";
            }
        }

        $totalReady = array_sum($readyBySprint);
        echo "<div class='modal fade' id='sprint-backlog-bulk-modal' tabindex='-1' aria-hidden='true'>";
        echo "<div class='modal-dialog modal-dialog-centered'>";
        echo "<div class='modal-content'>";
        echo "<div class='modal-header'><h5 class='modal-title'>"
            . "<i class='fas fa-layer-group me-1'></i> {$lblBulkTitle}</h5>"
            . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button></div>";
        echo "<div class='modal-body'>";
        echo "<p class='text-muted small'>{$lblBulkHint}</p>";
        echo "<select class='form-select sprint-bulk-sprint-choice'>";
        echo "<option value='0'>{$lblBulkAll} (" . (int)$totalReady . ")</option>";
        foreach ($readyBySprint as $sid => $cnt) {
            $name = $sprintNames[$sid] ?? ('#' . $sid);
            echo "<option value='" . (int)$sid . "'>" . htmlescape($name) . " (" . (int)$cnt . ")</option>";
        }
        echo "</select>";
        echo $dorSection;
        echo "</div>";
        echo "<div class='modal-footer'>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>{$lblCancel}</button>";
        echo "<button type='button' class='btn btn-success sprint-backlog-bulk-go'" . ($dor ? ' disabled' : '') . ">"
            . "<i class='fas fa-check me-1'></i>{$lblBulkGo}</button>";
        echo "</div>";
        echo "</div></div></div>";

        // Single-assign DoR dialog, opened via window.sprintDorConfirm.
        if ($dor) {
            $lblDorTitle = __s('Definition of Ready', 'sprint');
            $lblDorHint  = __s('Confirm the checks that hold for this item.', 'sprint');
            $lblDorGo    = __s('Assign', 'sprint');
            echo "<div class='modal fade' id='sprint-dor-modal' tabindex='-1' aria-hidden='true'>";
            echo "<div class='modal-dialog modal-dialog-centered'><div class='modal-content'>";
            echo "<div class='modal-header'><h5 class='modal-title'><i class='fas fa-clipboard-check me-1'></i> {$lblDorTitle}</h5>"
                . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button></div>";
            echo "<div class='modal-body'><p class='text-muted small'>{$lblDorHint}</p>";
            foreach ($dor as $check) {
                echo "<label class='form-check d-block'><input class='form-check-input sprint-dor-single-check' type='checkbox' value='"
                    . htmlescape($check) . "'><span class='form-check-label'>" . htmlescape($check) . "</span></label>";
            }
            echo "</div>";
            echo "<div class='modal-footer'>";
            echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>{$lblCancel}</button>";
            echo "<button type='button' class='btn btn-success sprint-dor-go' disabled><i class='fas fa-check me-1'></i>{$lblDorGo}</button>";
            echo "</div>";
            echo "</div></div></div>";
        }

        // Reason dialog for the non-Scrum-Master "request assignment" button.
        SprintRequest::renderReasonModalUI();

        echo <<<HTML
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }
    var tokenUrl = "{$tokenUrl}";

    var \$bulkModal, \$dorModal;
    jQuery(function(){
        \$bulkModal = jQuery('#sprint-backlog-bulk-modal');
        if (\$bulkModal.length) { \$bulkModal = \$bulkModal.detach().appendTo('body'); }
        \$dorModal = jQuery('#sprint-dor-modal');
        if (\$dorModal.length) { \$dorModal = \$dorModal.detach().appendTo('body'); }
    });

    // DoR confirmation: all checks mandatory; resolves with the checked
    // values, or null when no DoR is configured.
    window.sprintDorConfirm = function(callback) {
        if (!\$dorModal || !\$dorModal.length) { callback(null); return; }
        \$dorModal.find('.sprint-dor-single-check').prop('checked', false);
        \$dorModal.find('.sprint-dor-go').prop('disabled', true);
        \$dorModal.data('cb', callback);
        bootstrap.Modal.getOrCreateInstance(\$dorModal[0]).show();
    };

    jQuery(document).on('change', '.sprint-dor-single-check', function(){
        \$dorModal.find('.sprint-dor-go')
            .prop('disabled', \$dorModal.find('.sprint-dor-single-check:not(:checked)').length > 0);
    });

    jQuery(document).on('change', '.sprint-dor-check', function(){
        \$bulkModal.find('.sprint-backlog-bulk-go')
            .prop('disabled', \$bulkModal.find('.sprint-dor-check:not(:checked)').length > 0);
    });

    jQuery(document).on('click', '.sprint-dor-go', function(){
        var cb = \$dorModal.data('cb');
        \$dorModal.removeData('cb');
        var checks = \$dorModal.find('.sprint-dor-single-check:checked')
            .map(function(){ return this.value; }).get();
        bootstrap.Modal.getOrCreateInstance(\$dorModal[0]).hide();
        if (typeof cb === 'function') { cb(checks); }
    });

    // Kick-off: open the scope modal (everything, or one specific sprint).
    jQuery(document).on('click', '.sprint-backlog-bulk-assign', function() {
        if (\$bulkModal && \$bulkModal.length) {
            bootstrap.Modal.getOrCreateInstance(\$bulkModal[0]).show();
        }
    });

    jQuery(document).on('click', '.sprint-backlog-bulk-go', function() {
        var \$btn     = jQuery(this);
        var sprintId = parseInt(\$bulkModal.find('.sprint-bulk-sprint-choice').val(), 10) || 0;
        var ready    = \$bulkModal.find('.sprint-dor-check:checked')
            .map(function(){ return this.value; }).get();
        \$btn.prop('disabled', true);
        jQuery.ajax({ url: tokenUrl, type: 'GET', dataType: 'json', cache: false })
        .then(function(tok) {
            return jQuery.ajax({
                url: "{$bulkUrl}", type: 'POST', dataType: 'json',
                data: {
                    sprint_id: sprintId,
                    ready: ready,
                    _glpi_csrf_token: tok && tok.token ? tok.token : ''
                }
            });
        }).done(function(resp) {
            if (resp && resp.success) {
                if (window.glpi_toast_info) { window.glpi_toast_info(resp.message); }
                window.location.reload();
            } else {
                if (window.glpi_toast_error) { window.glpi_toast_error((resp && resp.message) || 'Failed'); }
                \$btn.prop('disabled', false);
            }
        }).fail(function() {
            if (window.glpi_toast_error) { window.glpi_toast_error('Network error'); }
            \$btn.prop('disabled', false);
        });
    });

    // Non-Scrum-Master path: same button spot, but the click files an
    // assignment request that lands in the Scrum Master's approval panel.
    // Ask for a motivation first (same dialog family as back-to-backlog);
    // dismissing the dialog aborts and leaves the button untouched.
    jQuery(document).on('click', '.sprint-backlog-request-btn', function() {
        var \$btn   = jQuery(this);
        var itemId = parseInt(\$btn.data('item-id'), 10) || 0;
        if (itemId <= 0) { return; }
        if (typeof window.sprintRequestReason === 'function') {
            window.sprintRequestReason(function(reason){ sprintSendAssignRequest(\$btn, itemId, reason); });
        } else {
            sprintSendAssignRequest(\$btn, itemId, '');
        }
    });

    function sprintSendAssignRequest(\$btn, itemId, reason) {
        \$btn.prop('disabled', true);
        jQuery.ajax({ url: tokenUrl, type: 'GET', dataType: 'json', cache: false })
        .then(function(tok) {
            return jQuery.ajax({
                url: "{$requestUrl}", type: 'POST', dataType: 'json',
                data: { id: itemId, reason: reason, _glpi_csrf_token: tok && tok.token ? tok.token : '' }
            });
        }).done(function(resp) {
            if (resp && resp.success) {
                if (window.glpi_toast_info) { window.glpi_toast_info(resp.message || 'Requested'); }
                \$btn.removeClass('btn-outline-primary').addClass('btn-outline-secondary')
                    .prop('disabled', true)
                    .find('i').attr('class', 'fas fa-hourglass-half');
            } else {
                if (window.glpi_toast_error) { window.glpi_toast_error((resp && resp.message) || 'Failed'); }
                \$btn.prop('disabled', false);
            }
        }).fail(function() {
            if (window.glpi_toast_error) { window.glpi_toast_error('Network error'); }
            \$btn.prop('disabled', false);
        });
    }

    // Recompute per-section counts, capacity subtotals, ranks and empty rows.
    window.sprintBacklogRefreshSections = function() {
        jQuery('.sprint-backlog-cat-section').each(function() {
            var \$sec  = jQuery(this);
            var \$rows = \$sec.find('tr.sprint-backlog-row');
            \$sec.find('.sprint-backlog-empty-row').toggle(\$rows.length === 0);
            \$sec.find('.sprint-backlog-cat-count').text(\$rows.length);
            var sum = 0;
            \$rows.each(function() { sum += parseFloat(this.getAttribute('data-capacity')) || 0; });
            \$sec.find('.sprint-backlog-cat-capsum').text((Math.round(sum * 10) / 10) + '%');
            var i = 1;
            \$rows.each(function() { jQuery(this).find('.sprint-backlog-rank').text(i++); });
        });
    };

    // Collapsible category sections (state per category in localStorage).
    jQuery(function() {
        jQuery('.sprint-backlog-cat-section').each(function() {
            var \$sec = jQuery(this);
            var key  = 'sprint.backlog.cat.' + (\$sec.attr('data-category-id') || '0') + '.collapsed';
            if (localStorage.getItem(key) === '1') {
                \$sec.find('.sprint-backlog-cat-body').hide();
                \$sec.find('.sprint-backlog-cat-chevron').css('transform', 'rotate(-90deg)');
            }
        });
    });
    jQuery(document).on('click', '.sprint-backlog-cat-header', function() {
        var \$sec  = jQuery(this).closest('.sprint-backlog-cat-section');
        var key   = 'sprint.backlog.cat.' + (\$sec.attr('data-category-id') || '0') + '.collapsed';
        var \$body = \$sec.find('.sprint-backlog-cat-body');
        var collapsed = \$body.is(':visible');
        \$body.slideToggle(120);
        \$sec.find('.sprint-backlog-cat-chevron').css('transform', collapsed ? 'rotate(-90deg)' : 'rotate(0deg)');
        localStorage.setItem(key, collapsed ? '1' : '0');
    });

    // Multi-select: Ctrl/Cmd-click toggles a row, Shift-click extends from the
    // last toggled row; a plain click outside links/inputs clears the selection.
    var sprintLastSelected = null;
    jQuery(document).on('click', '.sprint-backlog-table .sprint-backlog-row', function(e) {
        if (e.ctrlKey || e.metaKey) {
            e.preventDefault();
            jQuery(this).toggleClass('sprint-row-selected');
            sprintLastSelected = jQuery(this).hasClass('sprint-row-selected') ? this : null;
            return;
        }
        if (e.shiftKey && sprintLastSelected && sprintLastSelected !== this) {
            var \$all = jQuery('.sprint-backlog-table .sprint-backlog-row');
            var a = \$all.index(sprintLastSelected);
            var b = \$all.index(this);
            if (a > -1 && b > -1) {
                e.preventDefault();
                if (window.getSelection) { window.getSelection().removeAllRanges(); }
                \$all.slice(Math.min(a, b), Math.max(a, b) + 1).addClass('sprint-row-selected');
                return;
            }
        }
        if (!jQuery(e.target).closest('a, button, input, select, label, form').length) {
            jQuery('.sprint-row-selected').removeClass('sprint-row-selected');
            sprintLastSelected = null;
        }
    });

    // Drag-to-reorder via the grip; dropping in another section recategorizes.
    // Dragging a selected row takes the whole selection along.
    var sprintDragRow  = null;
    var sprintDragRows = null;
    jQuery(document).on('mousedown', '.sprint-backlog-table .sprint-backlog-grip', function() {
        var row = this.closest('tr');
        if (row) { row.setAttribute('draggable', 'true'); }
    });
    jQuery(document).on('dragstart', '.sprint-backlog-table .sprint-backlog-row', function(e) {
        sprintDragRow = this;
        var \$sel = jQuery('.sprint-backlog-table .sprint-backlog-row.sprint-row-selected');
        sprintDragRows = (\$sel.length > 1 && jQuery(this).hasClass('sprint-row-selected'))
            ? \$sel.toArray()
            : [this];
        sprintDragRows.forEach(function(r) { r.classList.add('sprint-row-dragging'); });
        try {
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData('text/plain', '');
        } catch (ex) {}
    });
    jQuery(document).on('dragover', '.sprint-backlog-table .sprint-backlog-row', function(e) {
        if (!sprintDragRow || this === sprintDragRow) { return; }
        e.preventDefault();
        var rect  = this.getBoundingClientRect();
        var after = (e.originalEvent.clientY - rect.top) > rect.height / 2;
        var parent = this.parentNode;
        parent.insertBefore(sprintDragRow, after ? this.nextSibling : this);
    });
    // Empty sections have no row to hover — accept the drop on the table.
    jQuery(document).on('dragover', '.sprint-backlog-cat-section .sprint-backlog-table', function(e) {
        if (!sprintDragRow) { return; }
        var \$tbody = jQuery(this).find('tbody').first();
        if (\$tbody.find('tr.sprint-backlog-row').length === 0) {
            e.preventDefault();
            \$tbody.append(sprintDragRow);
        }
    });
    jQuery(document).on('dragend', '.sprint-backlog-table .sprint-backlog-row', function() {
        if (!sprintDragRow) { return; }
        var row   = sprintDragRow;
        var group = sprintDragRows || [row];
        sprintDragRow  = null;
        sprintDragRows = null;
        group.forEach(function(r) {
            r.classList.remove('sprint-row-dragging');
            r.removeAttribute('draggable');
        });

        // Group drag: gather the other selected rows around the dropped anchor,
        // keeping the group's own order (members before the anchor stay before).
        if (group.length > 1 && row.parentNode) {
            var before = [], after = [], seen = false;
            group.forEach(function(r) {
                if (r === row) { seen = true; return; }
                (seen ? after : before).push(r);
            });
            before.forEach(function(r) { row.parentNode.insertBefore(r, row); });
            var ref = row;
            after.forEach(function(r) { row.parentNode.insertBefore(r, ref.nextSibling); ref = r; });
        }

        var catMap = {};
        group.forEach(function(r) {
            var rowId = parseInt(r.getAttribute('data-item-id'), 10) || 0;
            var \$sec  = jQuery(r).closest('.sprint-backlog-cat-section');
            if (rowId && \$sec.length) {
                var newCat = parseInt(\$sec.attr('data-category-id'), 10) || 0;
                var oldCat = parseInt(r.getAttribute('data-category-id'), 10) || 0;
                if (newCat !== oldCat) {
                    catMap[rowId] = newCat;
                    r.setAttribute('data-category-id', newCat);
                }
            }
        });
        jQuery('.sprint-row-selected').removeClass('sprint-row-selected');
        sprintLastSelected = null;

        var ids = [];
        jQuery('.sprint-backlog-table .sprint-backlog-row').each(function() {
            var id = parseInt(this.getAttribute('data-item-id'), 10) || 0;
            if (id) { ids.push(id); }
        });
        if (window.sprintBacklogRefreshSections) { window.sprintBacklogRefreshSections(); }
        jQuery.ajax({ url: tokenUrl, type: 'GET', dataType: 'json', cache: false })
        .then(function(tok) {
            return jQuery.ajax({
                url: "{$reorderUrl}", type: 'POST', dataType: 'json',
                data: {
                    order: JSON.stringify(ids),
                    categories: JSON.stringify(catMap),
                    _glpi_csrf_token: tok && tok.token ? tok.token : ''
                }
            });
        }).done(function(resp) {
            if (resp && resp.success) {
                if (window.glpi_toast_info) { window.glpi_toast_info('{$savedOrderMsg}'); }
                if (window.sprintBacklogReloadMatrix) { window.sprintBacklogReloadMatrix(); }
            } else if (window.glpi_toast_error) {
                window.glpi_toast_error('Save failed');
            }
        });
    });
})();
</script>
HTML;
    }

    /** Prognosis dashboard: category × upcoming-sprints matrix + work-supply. */
    private static function renderCategoryDashboard(): void
    {
        $statsUrl = Plugin::getWebDir('sprint') . '/ajax/backlogcategorystats.php';
        $capUrl   = Plugin::getWebDir('sprint') . '/ajax/categorycap.php';
        $tokenUrl = Plugin::getWebDir('sprint') . '/ajax/csrftoken.php';

        echo "<div class='sprint-backlog-dash' "
            . "style='margin:14px 0;border:1px solid var(--tblr-border-color,#e2e8f0);border-radius:8px;overflow:hidden;text-align:left;'>";
        echo "<div style='display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 14px;"
            . "background:var(--tblr-bg-surface-secondary,#f8fafc);border-bottom:1px solid var(--tblr-border-color,#e2e8f0);'>";
        echo "<span style='font-weight:700;'><i class='fas fa-chart-simple me-1'></i>"
            . __('Capacity per category', 'sprint') . "</span>";
        echo "<span class='text-muted small'>"
            . __('Backlog capacity per category across the upcoming sprints, against optional min/max limits.', 'sprint') . "</span>";
        echo "<span style='flex:1;'></span>";
        echo "<label class='text-muted small mb-0' for='sprint-backlog-horizon'>" . __('Horizon', 'sprint') . "</label>";
        echo "<select id='sprint-backlog-horizon' class='form-select form-select-sm sprint-backlog-horizon' style='max-width:140px;'>";
        foreach ([4, 8, 13] as $h) {
            echo "<option value='{$h}'>" . sprintf(_n('%d sprint', '%d sprints', $h, 'sprint'), $h) . "</option>";
        }
        echo "</select>";
        echo "</div>";
        echo "<div class='sprint-backlog-dash-body'>" . self::renderCategoryMatrixFragment() . "</div>";
        echo "</div>";

        // Limits modal: one dual-range slider (min/max %) per category, saved
        // for the sprint whose header button opened it.
        $lblTitle  = __s('Category limits', 'sprint');
        $lblHint   = __s('Reserve a minimum and cap a maximum share of capacity per category for this sprint. 0 = no limit.', 'sprint');
        $lblMin    = __s('min', 'sprint');
        $lblMax    = __s('max', 'sprint');
        $lblCancel = __s('Cancel');
        $lblSave   = __s('Save');
        echo "<div class='modal fade' id='sprint-limits-modal' tabindex='-1' aria-hidden='true'>";
        echo "<div class='modal-dialog modal-dialog-centered'>";
        echo "<div class='modal-content'>";
        echo "<div class='modal-header'>";
        echo "<h5 class='modal-title'><i class='fas fa-sliders me-1'></i> {$lblTitle}: <span class='sprint-limits-sprintname'></span></h5>";
        echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>";
        echo "</div>";
        echo "<div class='modal-body'>";
        echo "<div class='text-muted small mb-3'>{$lblHint}</div>";
        echo "<div class='sprint-limits-rows'></div>";
        echo "</div>";
        echo "<div class='modal-footer'>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>{$lblCancel}</button>";
        echo "<button type='button' class='btn btn-primary sprint-limits-save'><i class='fas fa-save me-1'></i> {$lblSave}</button>";
        echo "</div>";
        echo "</div></div></div>";

        echo <<<HTML
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }

    var SLIDER_MAX = 1000;

    function currentHorizon() {
        return parseInt(jQuery('.sprint-backlog-horizon').val(), 10) || 4;
    }

    function reloadMatrix() {
        jQuery('.sprint-backlog-dash-body').css('opacity', 0.5);
        jQuery.ajax({ url: "{$statsUrl}", type: 'GET', dataType: 'json', cache: false,
            data: { horizon: currentHorizon() } })
        .done(function(resp){
            if (resp && resp.success) { jQuery('.sprint-backlog-dash-body').html(resp.html); }
        }).always(function(){ jQuery('.sprint-backlog-dash-body').css('opacity', 1); });
    }
    // Row edits and drags elsewhere on the page keep the matrix in sync.
    window.sprintBacklogReloadMatrix = reloadMatrix;

    // Planning horizon: persisted per user, restored on load.
    jQuery(document).on('change', '.sprint-backlog-horizon', function(){
        try { localStorage.setItem('sprint.backlog.matrix.horizon', this.value); } catch (e) {}
        reloadMatrix();
    });
    (function(){
        var stored = 0;
        try { stored = parseInt(localStorage.getItem('sprint.backlog.matrix.horizon'), 10) || 0; } catch (e) {}
        if (stored > 0 && jQuery('.sprint-backlog-horizon option[value="' + stored + '"]').length) {
            jQuery('.sprint-backlog-horizon').val(String(stored));
            if (stored !== 4) { reloadMatrix(); }
        }
    })();

    function updateFill(\$row) {
        var min   = parseFloat(\$row.find('.sprint-limit-min').val()) || 0;
        var max   = parseFloat(\$row.find('.sprint-limit-max').val()) || 0;
        var left  = 0, width = 0;
        if (max > 0) {
            left  = Math.min(min, max) / SLIDER_MAX * 100;
            width = (max - Math.min(min, max)) / SLIDER_MAX * 100;
        } else if (min > 0) {
            // Minimum without cap: filled from the floor to the end.
            left  = min / SLIDER_MAX * 100;
            width = 100 - left;
        }
        \$row.find('.sprint-limits-fill').css({ left: left + '%', width: Math.max(0, width) + '%' });
    }

    function buildRow(cat) {
        var \$row = jQuery(
            "<div class='sprint-limits-row' data-category-id='" + (parseInt(cat.id, 10) || 0) + "'>" +
            "<div class='sprint-limits-row-head'>" +
            "<span class='sprint-limits-dot'></span><strong class='sprint-limits-name'></strong>" +
            "<span class='sprint-limits-vals'>" +
            "<label>{$lblMin} <input type='number' class='form-control form-control-sm sprint-limit-min' min='0' max='" + SLIDER_MAX + "' step='0.5'></label>" +
            "<label>{$lblMax} <input type='number' class='form-control form-control-sm sprint-limit-max' min='0' max='" + SLIDER_MAX + "' step='0.5'></label>" +
            "<span class='text-muted small'>%</span></span></div>" +
            "<div class='sprint-limits-slider'>" +
            "<div class='sprint-limits-track'><div class='sprint-limits-fill'></div></div>" +
            "<input type='range' class='sprint-range-min' min='0' max='" + SLIDER_MAX + "' step='5'>" +
            "<input type='range' class='sprint-range-max' min='0' max='" + SLIDER_MAX + "' step='5'>" +
            "</div></div>");
        \$row.find('.sprint-limits-dot').css('background', String(cat.color || '#6c757d'));
        \$row.find('.sprint-limits-name').text(String(cat.name || ''));
        \$row.find('.sprint-limit-min').val(cat.min > 0 ? cat.min : 0);
        \$row.find('.sprint-limit-max').val(cat.max > 0 ? cat.max : 0);
        \$row.find('.sprint-range-min').val(cat.min || 0);
        \$row.find('.sprint-range-max').val(cat.max || 0);
        updateFill(\$row);
        return \$row;
    }

    jQuery(document).on('click', '.sprint-limits-open', function(){
        var \$btn = jQuery(this);
        var cats  = [];
        try { cats = JSON.parse(\$btn.attr('data-limits') || '[]'); } catch (e) { cats = []; }
        var \$modal = jQuery('#sprint-limits-modal');
        \$modal.attr('data-sprint-id', \$btn.attr('data-sprint-id') || '0');
        \$modal.find('.sprint-limits-sprintname').text(\$btn.attr('data-sprint-name') || '');
        var \$rows = \$modal.find('.sprint-limits-rows').empty();
        cats.forEach(function(cat){ \$rows.append(buildRow(cat)); });
        bootstrap.Modal.getOrCreateInstance(\$modal[0]).show();
    });

    // Keep sliders, number inputs and the fill in sync; min never crosses max.
    jQuery(document).on('input', '#sprint-limits-modal .sprint-range-min', function(){
        var \$row = jQuery(this).closest('.sprint-limits-row');
        var v    = parseFloat(this.value) || 0;
        var max  = parseFloat(\$row.find('.sprint-range-max').val()) || 0;
        if (max > 0 && v > max) { v = max; this.value = v; }
        \$row.find('.sprint-limit-min').val(v);
        updateFill(\$row);
    });
    jQuery(document).on('input', '#sprint-limits-modal .sprint-range-max', function(){
        var \$row = jQuery(this).closest('.sprint-limits-row');
        var v    = parseFloat(this.value) || 0;
        var min  = parseFloat(\$row.find('.sprint-range-min').val()) || 0;
        if (v > 0 && v < min) {
            \$row.find('.sprint-range-min').val(v);
            \$row.find('.sprint-limit-min').val(v);
        }
        \$row.find('.sprint-limit-max').val(v);
        updateFill(\$row);
    });
    jQuery(document).on('change', '#sprint-limits-modal .sprint-limit-min, #sprint-limits-modal .sprint-limit-max', function(){
        var \$row = jQuery(this).closest('.sprint-limits-row');
        var min = Math.max(0, Math.min(SLIDER_MAX, parseFloat(\$row.find('.sprint-limit-min').val()) || 0));
        var max = Math.max(0, Math.min(SLIDER_MAX, parseFloat(\$row.find('.sprint-limit-max').val()) || 0));
        if (max > 0 && min > max) {
            if (jQuery(this).hasClass('sprint-limit-min')) { max = min; } else { min = max; }
        }
        \$row.find('.sprint-limit-min').val(min);
        \$row.find('.sprint-limit-max').val(max);
        \$row.find('.sprint-range-min').val(min);
        \$row.find('.sprint-range-max').val(max);
        updateFill(\$row);
    });

    jQuery(document).on('click', '.sprint-limits-save', function(){
        var \$modal   = jQuery('#sprint-limits-modal');
        var sprintId = parseInt(\$modal.attr('data-sprint-id'), 10) || 0;
        if (sprintId <= 0) { return; }
        var limits = {};
        \$modal.find('.sprint-limits-row').each(function(){
            var \$row  = jQuery(this);
            var catId = parseInt(\$row.attr('data-category-id'), 10) || 0;
            if (catId <= 0) { return; }
            limits[catId] = {
                min: parseFloat(\$row.find('.sprint-limit-min').val()) || 0,
                max: parseFloat(\$row.find('.sprint-limit-max').val()) || 0
            };
        });
        jQuery.ajax({ url: "{$tokenUrl}", type: 'GET', dataType: 'json', cache: false })
        .then(function(tok){
            return jQuery.ajax({
                url: "{$capUrl}", type: 'POST', dataType: 'json',
                data: {
                    sprint_id: sprintId, limits: JSON.stringify(limits),
                    _glpi_csrf_token: tok && tok.token ? tok.token : ''
                }
            });
        }).done(function(resp){
            if (resp && resp.success) {
                bootstrap.Modal.getOrCreateInstance(\$modal[0]).hide();
                reloadMatrix();
            } else if (window.glpi_toast_error) {
                window.glpi_toast_error((resp && resp.message) || 'Failed');
            }
        });
    });
})();
</script>
HTML;
    }

    /** Matrix fragment: categories × upcoming sprints (+ unplanned + work-supply). */
    public static function renderCategoryMatrixFragment(int $horizon = 4): string
    {
        global $DB;

        $horizon    = max(1, min(26, $horizon));
        $categories = SprintCategory::getAll();
        $sprints    = array_slice((new Sprint())->find(
            ['status' => Sprint::STATUS_PLANNED]
                + getEntitiesRestrictCriteria(Sprint::getTable(), '', '', true),
            ['date_start ASC']
        ), 0, $horizon, true);
        $sprintIds  = array_map(fn($s) => (int)$s['id'], $sprints);

        // One pass over the active backlog: capacity + counts per category,
        // split per proposed sprint (0 = unplanned).
        $alloc = $count = $totalByCat = [];
        foreach ((new SprintItem())->find([
            'plugin_sprint_sprints_id' => 0, 'is_blocked' => 0, 'is_parked' => 0,
        ]) as $row) {
            $cid = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
            if ($cid > 0 && !isset($categories[$cid])) {
                $cid = 0;
            }
            $sid = (int)($row['proposed_sprints_id'] ?? 0);
            if ($sid > 0 && !in_array($sid, $sprintIds, true)) {
                $sid = -1; // proposed for a sprint outside the horizon
            }
            $cap = (float)($row['capacity'] ?? 0);
            $alloc[$cid][$sid] = ($alloc[$cid][$sid] ?? 0) + $cap;
            $count[$cid][$sid] = ($count[$cid][$sid] ?? 0) + 1;
            $totalByCat[$cid]  = ($totalByCat[$cid] ?? 0) + $cap;
        }

        $limits = $team = $canCap = [];
        foreach ($sprintIds as $sid) {
            $limits[$sid] = SprintCategory::getEffectiveLimitsForSprint($sid);
            $canCap[$sid] = Sprint::canUpdate();
            $team[$sid]   = 0.0;
            foreach ($DB->request([
                'FROM'  => SprintMember::getTable(),
                'WHERE' => ['plugin_sprint_sprints_id' => $sid],
            ]) as $m) {
                $team[$sid] += (float)($m['capacity_percent'] ?? 0);
            }
        }

        // Work-supply: delivered capacity per sprint over the last completed
        // sprints, per category with an overall fallback.
        $histIds = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => Sprint::getTable(),
            'WHERE'  => ['status' => Sprint::STATUS_COMPLETED]
                + getEntitiesRestrictCriteria(Sprint::getTable(), '', '', true),
            'ORDER'  => ['date_end DESC'],
            'LIMIT'  => 6,
        ]) as $past) {
            $histIds[] = (int)$past['id'];
        }
        $histCount = count($histIds);
        $delivered = [];
        $deliveredTotal = 0.0;
        if ($histIds) {
            foreach ($DB->request([
                'FROM'  => SprintItem::getTable(),
                'WHERE' => [
                    'plugin_sprint_sprints_id' => $histIds,
                    'status'                   => SprintItem::STATUS_DONE,
                    'is_fastlane'              => 0,
                ],
            ]) as $row) {
                $cid = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
                $cap = (float)($row['capacity'] ?? 0);
                $delivered[$cid] = ($delivered[$cid] ?? 0) + $cap;
                $deliveredTotal += $cap;
            }
        }
        $overallAvg = $histCount > 0 ? $deliveredTotal / $histCount : 0.0;
        $supplyFor  = function (int $cid, float $backlogCap) use ($delivered, $histCount, $overallAvg): string {
            if ($backlogCap <= 0) {
                return '';
            }
            $avg = $histCount > 0 ? (($delivered[$cid] ?? 0) / $histCount) : 0.0;
            if ($avg <= 0) {
                $avg = $overallAvg;
            }
            if ($avg <= 0) {
                return '—';
            }
            return '≈ ' . number_format($backlogCap / $avg, 1);
        };

        $rows = $categories;
        if (!empty($totalByCat[0])) {
            $rows[0] = ['name' => __('No category', 'sprint'), 'color' => '#6c757d'];
        }

        ob_start();
        echo "<div class='table-responsive' style='padding:6px 10px;'>";
        echo "<table class='table table-sm mb-1 sprint-backlog-matrix' style='min-width:640px;'>";
        echo "<thead><tr><th style='min-width:150px;'>" . __('Category', 'sprint') . "</th>";
        $limitsTitle = __s('Set min/max capacity % per category for this sprint', 'sprint');
        foreach ($sprints as $s) {
            $sid = (int)$s['id'];
            $sub = !empty($s['date_start']) ? substr((string)$s['date_start'], 0, 10) : '';
            echo "<th class='text-center'>" . htmlescape((string)$s['name'])
                . ($sub !== '' ? "<div class='text-muted small fw-normal'>{$sub}</div>" : '');
            if (!empty($canCap[$sid])) {
                // data-limits carries every category so the modal needs no fetch.
                $data = [];
                foreach ($categories as $cid => $cat) {
                    $l = $limits[$sid][(int)$cid] ?? null;
                    $data[] = [
                        'id'    => (int)$cid,
                        'name'  => (((int)($cat['level'] ?? 0) > 0) ? '— ' : '') . (string)$cat['name'],
                        'color' => (string)$cat['color'],
                        'min'   => (float)($l['min'] ?? 0),
                        'max'   => (float)($l['max'] ?? 0),
                    ];
                }
                echo "<div style='margin-top:2px;'><button type='button' class='sprint-cap-edit sprint-limits-open' "
                    . "title='{$limitsTitle}' data-sprint-id='{$sid}' "
                    . "data-sprint-name='" . htmlescape((string)$s['name']) . "' "
                    . "data-limits='" . htmlescape((string)json_encode($data)) . "'>"
                    . "<i class='fas fa-sliders'></i> " . __('Limits', 'sprint') . "</button></div>";
            }
            echo "</th>";
        }
        echo "<th class='text-center'>" . __('Not yet planned', 'sprint') . "</th>";
        echo "<th class='text-center' title='"
            . __s('Backlog capacity divided by the average delivered capacity per sprint (last completed sprints)', 'sprint') . "'>"
            . __('Work supply (sprints)', 'sprint') . "</th></tr></thead><tbody>";

        foreach ($rows as $cid => $cat) {
            $cid    = (int)$cid;
            $color  = htmlescape((string)$cat['color']);
            $child  = (int)($cat['level'] ?? 0) > 0;
            $pad    = $child ? "padding-left:26px;" : '';
            $prefix = $child ? "<i class='fas fa-turn-up fa-rotate-90 text-muted me-1' style='font-size:0.8em;'></i>" : '';
            echo "<tr><td style='{$pad}'>{$prefix}<span style='display:inline-block;width:10px;height:10px;border-radius:3px;background:{$color};margin-right:6px;'></span>"
                . ($child
                    ? htmlescape((string)$cat['name'])
                    : "<strong>" . htmlescape((string)$cat['name']) . "</strong>")
                . "</td>";
            foreach ($sprintIds as $sid) {
                $a     = (float)($alloc[$cid][$sid] ?? 0);
                $n     = (int)($count[$cid][$sid] ?? 0);
                $min   = (float)($limits[$sid][$cid]['min'] ?? 0);
                $max   = (float)($limits[$sid][$cid]['max'] ?? 0);
                $over  = $max > 0 && $a > $max;
                $under = $min > 0 && $a < $min;
                $bg    = $over ? "background:color-mix(in srgb,#dc3545 12%,transparent);" : '';
                echo "<td class='text-center sprint-matrix-cell' style='{$bg}'>";
                if ($a > 0) {
                    echo "<div class='fw-bold" . ($over ? " text-danger" : '') . "' title='"
                        . htmlescape(sprintf(_n('%d item', '%d items', $n, 'sprint'), $n)) . "'>"
                        . SprintMember::formatCapacity($a) . "%</div>";
                } else {
                    echo "<div class='text-muted'>–</div>";
                }
                // Bar scales to the cap, or to the floor when no cap is set.
                $scale = $max > 0 ? $max : max($min, $a);
                if (($min > 0 || $max > 0) && $scale > 0) {
                    $pct  = round(min(100, 100 * $a / $scale), 1);
                    echo "<div class='sprint-matrix-bar'><div style='width:{$pct}%;background:"
                        . ($over ? '#dc3545' : $color) . ";'></div>";
                    if ($min > 0 && $min < $scale) {
                        $tick = round(100 * $min / $scale, 1);
                        echo "<span class='sprint-matrix-min-tick' style='left:{$tick}%;'></span>";
                    }
                    echo "</div>";
                }
                if ($min > 0 || $max > 0) {
                    $parts = [];
                    if ($min > 0) {
                        $lbl = __('min', 'sprint') . ' ' . SprintMember::formatCapacity($min) . '%';
                        $parts[] = $under
                            ? "<span style='color:#d97706;font-weight:600;' title='"
                                . __s('Less allocated than the minimum for this category', 'sprint') . "'>{$lbl}</span>"
                            : $lbl;
                    }
                    if ($max > 0) {
                        $lbl = __('max', 'sprint') . ' ' . SprintMember::formatCapacity($max) . '%';
                        $parts[] = $over ? "<span class='text-danger fw-semibold'>{$lbl}</span>" : $lbl;
                    }
                    echo "<div class='text-muted small'>" . implode(' · ', $parts) . "</div>";
                }
                echo "</td>";
            }
            $u  = (float)($alloc[$cid][0] ?? 0) + (float)($alloc[$cid][-1] ?? 0);
            $un = (int)($count[$cid][0] ?? 0) + (int)($count[$cid][-1] ?? 0);
            echo "<td class='text-center'>" . ($u > 0
                ? SprintMember::formatCapacity($u) . "% <span class='text-muted small'>(" . $un . ")</span>"
                : "<span class='text-muted'>–</span>") . "</td>";
            echo "<td class='text-center'>" . ($supplyFor($cid, (float)($totalByCat[$cid] ?? 0)) ?: "<span class='text-muted'>–</span>") . "</td>";
            echo "</tr>";
        }

        // Total row against each sprint's team capacity.
        echo "<tr class='fw-bold' style='border-top:2px solid var(--tblr-border-color,#e2e8f0);'><td>" . __('Total', 'sprint') . "</td>";
        $grandTotal = array_sum($totalByCat);
        $unplTotal  = 0.0;
        foreach ($rows as $cid => $cat) {
            $unplTotal += (float)($alloc[(int)$cid][0] ?? 0) + (float)($alloc[(int)$cid][-1] ?? 0);
        }
        foreach ($sprintIds as $sid) {
            $t = 0.0;
            foreach ($rows as $cid => $cat) {
                $t += (float)($alloc[(int)$cid][$sid] ?? 0);
            }
            // Overflow = more ambition than the linked members can take on. A
            // sprint without members counts too: 0% capacity with work planned
            // is exactly the signal the planner needs.
            $noTeam = $team[$sid] <= 0;
            $over   = $t > 0 && ($noTeam || $t > $team[$sid]);
            $bg     = ($over && !$noTeam) ? "background:color-mix(in srgb,#dc3545 10%,transparent);" : '';
            echo "<td class='text-center' style='{$bg}'>"
                . "<span" . (($over && !$noTeam) ? " class='text-danger'" : '') . ">" . SprintMember::formatCapacity($t) . "%</span>";
            if ($over && !$noTeam) {
                echo "<div><span class='badge bg-red-lt' title='"
                    . __s('More work planned for this sprint than the linked members can take on', 'sprint') . "'>"
                    . "<i class='fas fa-triangle-exclamation me-1'></i>+"
                    . SprintMember::formatCapacity($t - $team[$sid]) . "%</span></div>";
            } elseif ($over && $noTeam) {
                echo "<div><span class='badge bg-orange-lt' title='"
                    . __s('Work is planned for this sprint but no members are linked yet', 'sprint') . "'>"
                    . "<i class='fas fa-user-slash me-1'></i>" . __('No members yet', 'sprint') . "</span></div>";
            }
            echo "<div class='text-muted small fw-normal' title='" . __s('Total team capacity of the selected sprint', 'sprint') . "'>"
                . __('Team', 'sprint') . " " . SprintMember::formatCapacity($team[$sid]) . "%</div></td>";
        }
        echo "<td class='text-center'>" . SprintMember::formatCapacity($unplTotal) . "%</td>";
        echo "<td class='text-center'>" . ($supplyFor(-1, $grandTotal) ?: "<span class='text-muted'>–</span>") . "</td>";
        echo "</tr>";
        echo "</tbody></table>";
        if ($histCount > 0) {
            echo "<div class='text-muted small' style='padding:0 4px 6px;'>"
                . sprintf(__('Work supply based on the last %d completed sprints.', 'sprint'), $histCount) . "</div>";
        }
        echo "</div>";
        return (string)ob_get_clean();
    }

    /** Record a backlog flow event; survives item deletion. */
    public static function logFlow(array $itemFields, string $direction): void
    {
        global $DB;
        if (!in_array($direction, ['in', 'out'], true)
            || !$DB->tableExists('glpi_plugin_sprint_backlogflow')) {
            return;
        }
        $DB->insert('glpi_plugin_sprint_backlogflow', [
            'date'                              => date('Y-m-d'),
            'plugin_sprint_sprintcategories_id' => (int)($itemFields['plugin_sprint_sprintcategories_id'] ?? 0),
            'direction'                         => $direction,
            'capacity'                          => (float)($itemFields['capacity'] ?? 0),
        ]);
    }

    /** Inflow vs outflow card: last 12 weeks, optional category filter. */
    private static function renderFlowCard(): void
    {
        $flowUrl = Plugin::getWebDir('sprint') . '/ajax/backlogflow.php';

        echo "<div class='sprint-backlog-flow' "
            . "style='margin:14px 0;border:1px solid var(--tblr-border-color,#e2e8f0);border-radius:8px;overflow:hidden;text-align:left;'>";
        echo "<div style='display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 14px;"
            . "background:var(--tblr-bg-surface-secondary,#f8fafc);border-bottom:1px solid var(--tblr-border-color,#e2e8f0);'>";
        echo "<span style='font-weight:700;'><i class='fas fa-arrow-right-arrow-left me-1'></i>"
            . __('Backlog inflow vs outflow', 'sprint') . "</span>";
        echo "<span class='text-muted small'>"
            . __('New backlog work versus work assigned or resolved, per week.', 'sprint') . "</span>";
        echo "<span style='flex:1;'></span>";
        echo "<select class='form-select form-select-sm sprint-backlog-flow-cat' style='max-width:220px;'>";
        echo "<option value='0'>" . __('All categories', 'sprint') . "</option>";
        echo SprintCategory::dropdownOptions();
        echo "</select>";
        echo "</div>";
        echo "<div class='sprint-backlog-flow-body'>" . self::renderFlowChartFragment(0) . "</div>";
        echo "</div>";

        echo <<<HTML
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }
    jQuery(document).on('change', '.sprint-backlog-flow-cat', function(){
        var catId = parseInt(this.value, 10) || 0;
        jQuery('.sprint-backlog-flow-body').css('opacity', 0.5);
        jQuery.ajax({
            url: "{$flowUrl}", type: 'GET', dataType: 'json', cache: false,
            data: { category_id: catId }
        }).done(function(resp){
            if (resp && resp.success) { jQuery('.sprint-backlog-flow-body').html(resp.html); }
        }).always(function(){ jQuery('.sprint-backlog-flow-body').css('opacity', 1); });
    });
})();
</script>
HTML;
    }

    /** Grouped weekly bars (capacity in vs out) for the last 12 weeks. */
    public static function renderFlowChartFragment(int $categoryId = 0): string
    {
        global $DB;

        $weeks = [];
        $monday = new \DateTime('monday this week');
        for ($i = 11; $i >= 0; $i--) {
            $w = (clone $monday)->modify("-{$i} weeks");
            $weeks[$w->format('Y-m-d')] = [
                'label' => $w->format('\WW'),
                'in'    => 0.0, 'out' => 0.0, 'in_n' => 0, 'out_n' => 0,
            ];
        }
        $firstMonday = array_key_first($weeks);

        if ($DB->tableExists('glpi_plugin_sprint_backlogflow')) {
            $where = [['date' => ['>=', $firstMonday]]];
            if ($categoryId > 0) {
                $where['plugin_sprint_sprintcategories_id'] = $categoryId;
            }
            foreach ($DB->request(['FROM' => 'glpi_plugin_sprint_backlogflow', 'WHERE' => $where]) as $row) {
                $day = new \DateTime((string)$row['date']);
                $key = $day->modify('monday this week')->format('Y-m-d');
                if (!isset($weeks[$key])) {
                    continue;
                }
                $dir = (string)$row['direction'];
                $weeks[$key][$dir]        += (float)$row['capacity'];
                $weeks[$key][$dir . '_n'] += 1;
            }
        }

        $max = 1.0;
        foreach ($weeks as $w) {
            $max = max($max, $w['in'], $w['out']);
        }

        ob_start();
        echo "<div style='padding:12px 14px;'>";
        echo "<div style='display:flex;align-items:flex-end;gap:10px;height:110px;'>";
        foreach ($weeks as $w) {
            $hIn  = (int)round(90 * $w['in'] / $max);
            $hOut = (int)round(90 * $w['out'] / $max);
            $tip  = htmlescape(sprintf(
                __('In: %1$s%% (%2$d items) · Out: %3$s%% (%4$d items)', 'sprint'),
                SprintMember::formatCapacity($w['in']),
                $w['in_n'],
                SprintMember::formatCapacity($w['out']),
                $w['out_n']
            ));
            echo "<div style='flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;' title='{$tip}'>";
            echo "<div style='display:flex;align-items:flex-end;gap:2px;height:90px;'>";
            echo "<div style='width:9px;height:" . max(2, $hIn) . "px;border-radius:2px 2px 0 0;background:var(--tblr-primary,#0d6efd);'></div>";
            echo "<div style='width:9px;height:" . max(2, $hOut) . "px;border-radius:2px 2px 0 0;background:#fd7e14;'></div>";
            echo "</div>";
            echo "<span class='text-muted' style='font-size:0.68em;'>" . htmlescape($w['label']) . "</span>";
            echo "</div>";
        }
        echo "</div>";
        echo "<div class='text-muted small' style='display:flex;gap:14px;margin-top:6px;'>"
            . "<span><span style='display:inline-block;width:9px;height:9px;background:var(--tblr-primary,#0d6efd);border-radius:2px;'></span> "
            . __('Inflow (new on backlog)', 'sprint') . "</span>"
            . "<span><span style='display:inline-block;width:9px;height:9px;background:#fd7e14;border-radius:2px;'></span> "
            . __('Outflow (assigned or resolved)', 'sprint') . "</span>"
            . "</div>";
        echo "</div>";
        return (string)ob_get_clean();
    }

    private static function renderBlockedSection(array $blockedItems, bool $canedit, array $typeLabels, array $tagsById = [], array $sprintNames = []): void
    {
        $count = count($blockedItems);

        echo "<div class='sprint-backlog-blocked' style='margin:18px 0;border:1px solid #f5c2c7;border-radius:8px;overflow:hidden;'>";
        echo "<div class='sprint-backlog-blocked-header' "
            . "style='display:flex;align-items:center;gap:8px;padding:10px 14px;background:color-mix(in srgb,#dc3545 14%,var(--tblr-bg-surface,#fff));color:var(--tblr-danger,#b02a37);font-weight:700;cursor:pointer;user-select:none;'>";
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
            echo "<table class='tab_cadre_fixe sprint-themed' style='margin:0;'>";
            self::renderTableHeaders($canedit, false);
            foreach ($blockedItems as $row) {
                self::renderItemRow($row, $canedit, $typeLabels, $tagsById, false, $sprintNames);
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

    /**
     * Freshly rendered <tr> fragment for one backlog item, so the edit modal
     * can swap the row in place instead of reloading the page. Returns null
     * when the item is gone or no longer on the backlog.
     *
     * @return array{html: string, is_blocked: int}|null
     */
    public static function renderRowFragment(int $id): ?array
    {
        $si = new SprintItem();
        if ($id <= 0 || !$si->getFromDB($id)
            || (int)$si->fields['plugin_sprint_sprints_id'] !== 0) {
            return null;
        }

        $row     = $si->fields;
        $canedit = Session::haveRight(SprintItem::$rightname, UPDATE)
            || Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS);

        $sprintNames = [];
        $proposedId  = (int)($row['proposed_sprints_id'] ?? 0);
        if ($proposedId > 0) {
            $sprint = new Sprint();
            $sprintNames[$proposedId] = $sprint->getFromDB($proposedId)
                ? (string)$sprint->fields['name']
                : ('#' . $proposedId);
        }

        $isBlocked = (int)($row['is_blocked'] ?? 0) === 1;
        $isParked  = (int)($row['is_parked'] ?? 0) === 1;
        $inSection = !$isBlocked && !$isParked;

        ob_start();
        // Fragment must match the table it lands in (grip/rank columns).
        self::renderItemRow(
            $row,
            $canedit,
            self::getTypeLabels(),
            SprintItem::getTagsForItems([$id]),
            $inSection,
            $sprintNames,
            0,
            !$inSection
        );
        $html = (string)ob_get_clean();

        return [
            'html'        => $html,
            'is_blocked'  => $isBlocked ? 1 : 0,
            'is_parked'   => $isParked ? 1 : 0,
            'category_id' => (int)($row['plugin_sprint_sprintcategories_id'] ?? 0),
        ];
    }

    private static function renderItemRow(array $row, bool $canedit, array $typeLabels, array $tagsById = [], bool $reorderable = false, array $sprintNames = [], int $rank = 0, bool $showCategoryPill = true): void
    {
        $typeIcons = [
            ''            => ['fas fa-clipboard-list', '#6c757d'],
            'Ticket'      => ['fas fa-ticket-alt', '#0d6efd'],
            'Change'      => ['fas fa-exchange-alt', '#6f42c1'],
            'Problem'     => ['fas fa-exclamation-circle', '#dc3545'],
            'ProjectTask' => ['fas fa-tasks', '#fd7e14'],
        ];

        $linkedDisplay = '<span style="color:#ccc;">-</span>';
        if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
            $tmp = new SprintItem();
            $tmp->fields = $row;
            $linkedDisplay = $tmp->getLinkedItemDisplay();
        }

        $itemtype   = (string)($row['itemtype'] ?? '');
        $typeKey    = $itemtype === '' ? 'manual' : $itemtype;
        $typeLabel  = $typeLabels[$itemtype] ?? __('Manual', 'sprint');
        $typeIcon   = $typeIcons[$itemtype] ?? $typeIcons[''];
        $isFastlane = (int)($row['is_fastlane'] ?? 0) === 1;
        $isBlocked  = (int)($row['is_blocked'] ?? 0) === 1;
        $isAdhoc    = (int)($row['is_adhoc'] ?? 0) === 1;
        $isParked   = (int)($row['is_parked'] ?? 0) === 1;
        $categoryId = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
        $rowTags    = $tagsById[(int)$row['id']] ?? [];
        $flags      = self::computeRowFlags($row);

        $ownerId      = (int)($row['users_id'] ?? 0);
        $ownerName    = $ownerId > 0 ? SprintCache::userName($ownerId) : '';
        $estCapacity  = (float)($row['capacity'] ?? 0);
        $proposedId   = (int)($row['proposed_sprints_id'] ?? 0);
        $proposedName = $proposedId > 0 ? (string)($sprintNames[$proposedId] ?? ('#' . $proposedId)) : '';

        echo "<tr class='tab_bg_1 sprint-filterable-row sprint-backlog-row' "
            . "data-item-id='" . (int)$row['id'] . "' "
            . "data-item-name='" . htmlescape($row['name']) . "' "
            . "data-item-type='" . htmlescape($typeKey) . "' "
            . "data-users-id='" . $ownerId . "' "
            . "data-owner-name='" . htmlescape($ownerName) . "' "
            . "data-capacity='" . SprintMember::formatCapacity($estCapacity) . "' "
            . "data-proposed-sprint-id='" . $proposedId . "' "
            . "data-proposed-sprint-name='" . htmlescape($proposedName) . "' "
            . "data-is-fastlane='" . ($isFastlane ? 1 : 0) . "' "
            . "data-is-blocked='" . ($isBlocked ? 1 : 0) . "' "
            . "data-is-adhoc='" . ($isAdhoc ? 1 : 0) . "' "
            . "data-is-parked='" . ($isParked ? 1 : 0) . "' "
            . "data-category-id='{$categoryId}' "
            . "data-item-tags='" . htmlescape(SprintItem::tagsToBlob($rowTags)) . "'>";
        if ($reorderable && $canedit) {
            echo "<td class='sprint-backlog-grip' style='cursor:grab;text-align:center;color:#adb5bd;' "
                . "title='" . __('Drag to reorder — Ctrl/Cmd-click rows to select more and drag them together', 'sprint') . "'><i class='fas fa-grip-vertical'></i></td>";
        }
        if ($reorderable) {
            echo "<td class='center'><span class='sprint-backlog-rank text-muted small fw-bold'>"
                . ($rank > 0 ? $rank : '') . "</span></td>";
        }

        $isReady = $proposedId > 0 && $ownerId > 0 && $estCapacity > 0;

        // Name cell carries the compact indicators: type icon, fastlane bolt,
        // blocked ban, tags and the ready badge.
        echo "<td>";
        echo "<i class='{$typeIcon[0]}' style='color:{$typeIcon[1]};margin-right:6px;opacity:0.85;' title='" . htmlescape($typeLabel) . "'></i>";
        if ($isFastlane) {
            echo "<i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;' title='" . __s('Fastlane', 'sprint') . "'></i>";
        }
        if ($isBlocked) {
            echo "<i class='fas fa-ban' style='color:#dc3545;margin-right:4px;' title='" . __s('Blocked', 'sprint') . "'></i>";
        }
        echo "<a href='" . SprintItem::getFormURLWithID($row['id']) . "'>"
            . htmlescape($row['name']) . "</a>"
            . SprintItem::renderParentProjectSuffix($itemtype, (int)($row['items_id'] ?? 0))
            . SprintItem::renderAdhocBadge($isAdhoc)
            . ($showCategoryPill ? SprintCategory::renderPill($categoryId) : '')
            . SprintItem::renderTagPills($rowTags);
        if ($isReady) {
            echo " <span class='sprint-ready-badge' title='" . __('Owner, capacity and sprint set — ready for the Scrum Master to assign', 'sprint') . "'>"
                . "<i class='fas fa-check'></i> " . __('Ready', 'sprint') . "</span>";
        }
        // Aging: unplanned active items that sit too long get a nudge.
        $agingDays = Config::getBacklogAgingDays();
        if ($reorderable && $agingDays > 0 && $proposedId <= 0 && !empty($row['date_creation'])) {
            $age = (int)floor((time() - strtotime((string)$row['date_creation'])) / DAY_TIMESTAMP);
            if ($age >= $agingDays) {
                $late = $age >= 2 * $agingDays;
                echo " <span class='sprint-aging-badge " . ($late ? 'sprint-aging-late' : 'sprint-aging-warn') . "' title='"
                    . htmlescape(sprintf(__('Unplanned on the backlog for %d days — schedule it or park it', 'sprint'), $age)) . "'>"
                    . "<i class='fas fa-clock'></i> " . sprintf(__('%dd', 'sprint'), $age) . "</span>";
            }
        }
        echo "</td>";
        echo "<td>" . $linkedDisplay . "</td>";

        echo "<td class='center'>";
        echo $ownerId > 0
            ? "<i class='fas fa-user text-muted me-1'></i>" . htmlescape($ownerName)
            : "<span class='text-muted'>" . __('Unassigned', 'sprint') . "</span>";
        echo "</td>";

        echo "<td class='center'>";
        if ($proposedId > 0) {
            echo htmlescape($proposedName);
            // Capacity chip: what remains for this owner in that sprint once
            // everything pending (incl. this row) is assigned.
            $capInfo = SprintMember::backlogCapacityPreview($proposedId, $ownerId, (int)$row['id']);
            if ($capInfo !== null && $ownerId > 0) {
                if (!$capInfo['is_member']) {
                    echo " <span class='sprint-cap-chip sprint-cap-chip-warn' title='"
                        . __s('Owner is not a member of the selected sprint', 'sprint') . "'>"
                        . "<i class='fas fa-user-slash'></i></span>";
                } else {
                    $freeAfter = $capInfo['total'] - $capInfo['used'] - $capInfo['pending_other'] - $estCapacity;
                    $chipClass = $freeAfter < 0 ? 'sprint-cap-chip-over' : ($freeAfter < 10 ? 'sprint-cap-chip-warn' : 'sprint-cap-chip-ok');
                    $tooltip = sprintf(
                        __('Capacity %1$s: total %2$s%%, used in sprint %3$s%%, pending from backlog %4$s%%, this item %5$s%% → %6$s%% free after assigning', 'sprint'),
                        $proposedName,
                        SprintMember::formatCapacity($capInfo['total']),
                        SprintMember::formatCapacity($capInfo['used']),
                        SprintMember::formatCapacity($capInfo['pending_other']),
                        SprintMember::formatCapacity($estCapacity),
                        SprintMember::formatCapacity($freeAfter)
                    );
                    echo " <span class='sprint-cap-chip {$chipClass}' title='" . htmlescape($tooltip) . "'>"
                        . SprintMember::formatCapacity($freeAfter) . "% " . __('free', 'sprint') . "</span>";
                }
            }
        } else {
            echo "<span class='text-muted'>-</span>";
        }
        echo "</td>";

        echo "<td class='center'>";
        echo $estCapacity > 0
            ? SprintMember::formatCapacity($estCapacity) . '%'
            : "<span class='text-muted'>-</span>";
        echo "</td>";

        if ($canedit) {
            echo "<td class='center' style='white-space:nowrap;'>";
            echo "<button type='button' class='btn btn-sm btn-outline-secondary sprint-backlog-edit-btn me-1' "
                . "data-item-id='" . (int)$row['id'] . "' "
                . "title='" . __s('Edit', 'sprint') . "'>"
                . "<i class='fas fa-pen'></i></button>";
            if ($flags['can_assign'] || $proposedId <= 0) {
                // Assign posts the pre-selected sprint; server-side permission
                // check stays in backlog.form.php / assigntosprint.php. The
                // sprint-backlog-assign-form class lets js/sprint.js hijack the
                // submit into the AJAX assign flow.
                echo "<form method='post' action='" . self::getFormURL() . "' "
                    . "class='sprint-backlog-assign-form' data-item-id='" . (int)$row['id'] . "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::hidden('plugin_sprint_sprints_id', ['value' => $proposedId]);
                $assignTitle = $proposedId <= 0
                    ? __('Pre-select a sprint first (edit the item)', 'sprint')
                    : ($isFastlane
                        ? __('Fastlane item — anyone can assign it to a sprint', 'sprint')
                        : __('Assign to the selected sprint', 'sprint'));
                echo "<button type='submit' name='assign_to_sprint' value='1' "
                    . "class='btn btn-sm btn-primary sprint-backlog-assign-btn' "
                    . ($proposedId <= 0 ? "disabled " : "")
                    . "title='" . htmlescape($assignTitle) . "'>"
                    . "<i class='fas fa-arrow-right'></i></button>";
                Html::closeForm();
            } else {
                // Same spot, same intent: non-Scrum-Masters ask the Scrum
                // Master to assign it — the request lands in their approval
                // panel. Handled by ajax/requestcreate.php.
                $hasPending = $flags['pending_assign'];
                echo "<button type='button' "
                    . "class='btn btn-sm " . ($hasPending ? "btn-outline-secondary" : "btn-outline-primary") . " sprint-backlog-request-btn' "
                    . "data-item-id='" . (int)$row['id'] . "' "
                    . ($hasPending ? "disabled " : "")
                    . "title='" . ($hasPending
                        ? __s('Assignment already requested — waiting for the Scrum Master', 'sprint')
                        : __s('Ask the Scrum Master to assign this item to the selected sprint', 'sprint')) . "'>"
                    . "<i class='fas fa-" . ($hasPending ? "hourglass-half" : "paper-plane") . "'></i></button>";
            }
            echo "</td>";
        }
        echo "</tr>";
    }

    private static function renderFilterBar(array $typeLabels, array $owners = []): void
    {
        echo "<div class='sprint-filter-bar sprint-backlog-filter' "
            . "style='display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:center;"
            . "padding:10px;margin-bottom:12px;background:var(--tblr-bg-surface-secondary,#f8fafc);border:1px solid var(--tblr-border-color,#e2e8f0);border-radius:8px;'>";

        echo "<div class='d-flex align-items-center gap-1 text-muted small'>"
            . "<i class='fas fa-filter'></i><span>" . __('Filter', 'sprint') . "</span></div>";

        echo "<input type='search' class='form-control form-control-sm sf-text' "
            . "style='max-width:240px;' placeholder='" . __('Search by name', 'sprint') . "'>";

        echo "<select class='form-select form-select-sm sf-type' style='max-width:200px;'>";
        echo "<option value=''>" . __('All') . " — " . __('Type', 'sprint') . "</option>";
        echo "<option value='Ticket'>" . htmlescape($typeLabels['Ticket']) . "</option>";
        echo "<option value='Change'>" . htmlescape($typeLabels['Change']) . "</option>";
        echo "<option value='Problem'>" . htmlescape($typeLabels['Problem']) . "</option>";
        echo "<option value='ProjectTask'>" . htmlescape($typeLabels['ProjectTask']) . "</option>";
        echo "<option value='manual'>" . htmlescape($typeLabels['']) . "</option>";
        echo "</select>";

        // Owner filter, handled by the shared filter JS (.sf-owner +
        // data-users-id / data-owner-name), as inside a sprint.
        if (!empty($owners)) {
            echo "<select class='form-select form-select-sm sf-owner' style='max-width:220px;'>";
            echo "<option value=''>" . __('All owners', 'sprint') . "</option>";
            echo "<option value='__unassigned__'>" . __('Unassigned only', 'sprint') . "</option>";
            foreach ($owners as $uidOpt => $name) {
                if ((int)$uidOpt === 0) {
                    continue;
                }
                echo "<option value='" . (int)$uidOpt . "'>" . htmlescape((string)$name) . "</option>";
            }
            echo "</select>";
        }

        $definedTags = Config::getDefinedTags();
        if (!empty($definedTags)) {
            echo "<select class='form-select form-select-sm sf-tag' style='max-width:180px;'>";
            echo "<option value=''>" . __('All tags', 'sprint') . "</option>";
            foreach ($definedTags as $tag) {
                echo "<option value='" . htmlescape(mb_strtolower($tag)) . "'>" . htmlescape($tag) . "</option>";
            }
            echo "</select>";
        }

        echo "<button type='button' class='btn btn-sm btn-outline-secondary sf-reset' data-sprint-action='filter-reset'>"
            . "<i class='fas fa-times me-1'></i>" . __('Reset', 'sprint') . "</button>";

        echo "</div>";
    }

    /** item_update hook tail: purge backlog rows whose linked item just got solved. */
    public static function autoCleanupForItem(\CommonDBTM $item): void
    {
        if (!Config::isBacklogAutoCleanup()) {
            return;
        }
        $updates = (array)($item->updates ?? []);
        if (empty(array_intersect(['status', 'percent_done', 'projectstates_id'], $updates))) {
            return;
        }
        // Hook fires post-update; drop any stale cached copy first.
        SprintCache::forget($item->getType(), (int)$item->getID());
        self::purgeSolvedRows($item->getType(), (int)$item->getID());
    }

    /**
     * Purge backlog rows whose linked item is solved/closed. Vanished links
     * are kept visible.
     */
    public static function purgeSolvedRows(?string $itemtype = null, ?int $itemsId = null): int
    {
        if (!Config::isBacklogAutoCleanup()) {
            return 0;
        }
        $criteria = [
            'plugin_sprint_sprints_id' => 0,
            ['NOT' => ['itemtype' => '']],
            ['items_id' => ['>', 0]],
        ];
        if ($itemtype !== null && $itemsId !== null) {
            $criteria['itemtype'] = $itemtype;
            $criteria['items_id'] = $itemsId;
        }

        $purged = 0;
        foreach ((new SprintItem())->find($criteria) as $row) {
            $rowType = (string)$row['itemtype'];
            $rowId   = (int)$row['items_id'];
            if ($rowType === '' || $rowId <= 0 || !class_exists($rowType)) {
                continue;
            }
            if (SprintCache::getObject($rowType, $rowId) === null) {
                continue; // vanished link — keep the row visible
            }
            if (!SprintItem::linkedItemClosedFor($rowType, $rowId)) {
                continue;
            }
            $si = new SprintItem();
            if ($si->delete(['id' => (int)$row['id']], true)) {
                $purged++;
            }
        }
        return $purged;
    }

    /**
     * Create a backlog item from a Ticket/Change/ProjectTask. Returns the
     * SprintItem ID, or 0 if it could not be created. An existing backlog
     * entry for the same linked item is reused instead of duplicated.
     */
    public static function addFromLinkedItem(string $itemtype, int $itemId): int
    {
        $allowed = ['Ticket', 'Change', 'Problem', 'ProjectTask'];
        if (!in_array($itemtype, $allowed, true) || $itemId <= 0) {
            return 0;
        }

        // Backlog and sprint membership are mutually exclusive.
        if (self::isLinkedItemInAnySprint($itemtype, $itemId)) {
            return 0;
        }

        // Reuse an existing backlog row for the same linked item.
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
            'story_points'             => 1,
        ]);

        return (int)$newId;
    }
}
