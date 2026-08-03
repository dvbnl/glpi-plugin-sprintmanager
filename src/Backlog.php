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
        $items   = $item->find(['plugin_sprint_sprints_id' => 0, 'is_blocked' => 0], $orderBy);

        $allIds   = array_merge(
            array_map(fn($r) => (int)$r['id'], $blocked),
            array_map(fn($r) => (int)$r['id'], $items)
        );
        $tagsById = SprintItem::getTagsForItems($allIds);

        // Resolve proposed-sprint names once for the read-only sprint column.
        $sprintNames = [];
        foreach (array_merge($blocked, $items) as $r) {
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

        echo "<table class='tab_cadre_fixe sprint-themed sprint-backlog-table'>";
        self::renderTableHeaders($canedit, true);

        if (count($items) === 0) {
            $cols = $canedit ? 7 : 5;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>"
                . __('Backlog is empty', 'sprint') . "</td></tr>";
        }

        foreach ($items as $row) {
            self::renderItemRow($row, $canedit, $typeLabels, $tagsById, true, $sprintNames);
        }

        echo "</table>";
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
        echo "<th>" . __('Name') . "</th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th><i class='fas fa-user' style='margin-right:4px;'></i>" . __('Owner', 'sprint') . "</th>";
        echo "<th><i class='fas fa-flag-checkered' style='margin-right:4px;'></i>" . __('Sprint') . "</th>";
        echo "<th title='" . __('Estimated capacity (informational on backlog; enforced once the item joins a sprint)', 'sprint') . "'>"
            . __('Est. capacity', 'sprint') . " %</th>";
        if ($canedit) {
            echo "<th style='width:120px;'>" . __('Actions') . "</th>";
        }
        echo "</tr>";
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
        $adhocHint   = __s('Marks the item as post-kick-off work; the flag travels with it into the sprint.', 'sprint');
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

        echo "<div class='mb-3'><label class='form-label'>{$lblSprint}</label>";
        Sprint::dropdown([
            'name'  => '_backlog_modal_sprint_id',
            'value' => 0,
            'rand'  => 424243,
            'width' => '100%',
        ]);
        echo "<div class='form-text small text-muted'>{$sprintHint}</div>";
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
                    _glpi_csrf_token: tokResp && tokResp.token ? tokResp.token : ''
                }
            });
        });
    }

    // Swap the saved row in place (no full page reload): fetch the freshly
    // rendered fragment and replace the old <tr>. A blocked-flag change moves
    // the row between the blocked and regular tables; when the target table
    // does not exist in the DOM (empty section) we fall back to a reload.
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

            var wasBlocked = \$old.closest('.sprint-backlog-blocked').length > 0;
            var nowBlocked = parseInt(resp.is_blocked, 10) === 1;
            var \$new = jQuery(resp.html);

            if (wasBlocked === nowBlocked) {
                \$old.replaceWith(\$new);
            } else {
                var \$target = nowBlocked
                    ? jQuery('.sprint-backlog-blocked table tbody').first()
                    : jQuery('.sprint-backlog-table tbody').first();
                if (!\$target.length) {
                    // Target table not rendered (section was empty) — the
                    // fragment has nowhere consistent to land.
                    window.location.reload();
                    return;
                }
                \$old.remove();
                \$target.append(\$new);
                // Keep the header badges roughly in sync.
                var \$blockedBadge = jQuery('.sprint-backlog-blocked .badge');
                var \$countBadge   = jQuery('.sprint-backlog-count');
                var blockedCount  = jQuery('.sprint-backlog-blocked tr.sprint-backlog-row').length;
                var normalCount   = jQuery('.sprint-backlog-table tr.sprint-backlog-row').length;
                if (\$blockedBadge.length) { \$blockedBadge.first().text(blockedCount); }
                if (\$countBadge.length) { \$countBadge.first().text(normalCount); }
            }
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
    // dependencies for a saved proposed sprint), then open the deps modal.
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
            bootstrap.Modal.getOrCreateInstance(\$modal[0]).hide();
            setTimeout(function(){
                if (window.sprintBacklogOpenDeps) {
                    window.sprintBacklogOpenDeps(itemId, name, ownerId, sprintId);
                }
            }, 250);
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

    jQuery(function(){
        \$modal = jQuery('#sprint-backlog-deps-modal');
        if (\$modal.length === 0) { return; }
        \$modal = \$modal.detach().appendTo('body');
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
        echo "</div>";
        echo "<div class='modal-footer'>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>{$lblCancel}</button>";
        echo "<button type='button' class='btn btn-success sprint-backlog-bulk-go'>"
            . "<i class='fas fa-check me-1'></i>{$lblBulkGo}</button>";
        echo "</div>";
        echo "</div></div></div>";

        // Reason dialog for the non-Scrum-Master "request assignment" button.
        SprintRequest::renderReasonModalUI();

        echo <<<HTML
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }
    var tokenUrl = "{$tokenUrl}";

    var \$bulkModal;
    jQuery(function(){
        \$bulkModal = jQuery('#sprint-backlog-bulk-modal');
        if (\$bulkModal.length) { \$bulkModal = \$bulkModal.detach().appendTo('body'); }
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
        \$btn.prop('disabled', true);
        jQuery.ajax({ url: tokenUrl, type: 'GET', dataType: 'json', cache: false })
        .then(function(tok) {
            return jQuery.ajax({
                url: "{$bulkUrl}", type: 'POST', dataType: 'json',
                data: {
                    sprint_id: sprintId,
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

    // Drag-to-reorder the backlog via the grip handle (persists sort_order).
    var sprintDragRow = null;
    jQuery(document).on('mousedown', '.sprint-backlog-table .sprint-backlog-grip', function() {
        var row = this.closest('tr');
        if (row) { row.setAttribute('draggable', 'true'); }
    });
    jQuery(document).on('dragstart', '.sprint-backlog-table .sprint-backlog-row', function(e) {
        sprintDragRow = this;
        this.classList.add('sprint-row-dragging');
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
    jQuery(document).on('dragend', '.sprint-backlog-table .sprint-backlog-row', function() {
        if (!sprintDragRow) { return; }
        sprintDragRow.classList.remove('sprint-row-dragging');
        sprintDragRow.removeAttribute('draggable');
        sprintDragRow = null;
        var ids = [];
        jQuery('.sprint-backlog-table .sprint-backlog-row').each(function() {
            var id = parseInt(this.getAttribute('data-item-id'), 10) || 0;
            if (id) { ids.push(id); }
        });
        jQuery.ajax({ url: tokenUrl, type: 'GET', dataType: 'json', cache: false })
        .then(function(tok) {
            return jQuery.ajax({
                url: "{$reorderUrl}", type: 'POST', dataType: 'json',
                data: { order: JSON.stringify(ids), _glpi_csrf_token: tok && tok.token ? tok.token : '' }
            });
        }).done(function(resp) {
            if (resp && resp.success) {
                if (window.glpi_toast_info) { window.glpi_toast_info('{$savedOrderMsg}'); }
            } else if (window.glpi_toast_error) {
                window.glpi_toast_error('Save failed');
            }
        });
    });
})();
</script>
HTML;
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

        ob_start();
        // The regular table has the drag-grip column, the blocked table does
        // not — the fragment must match the table it will be inserted into.
        self::renderItemRow(
            $row,
            $canedit,
            self::getTypeLabels(),
            SprintItem::getTagsForItems([$id]),
            !$isBlocked,
            $sprintNames
        );
        $html = (string)ob_get_clean();

        return ['html' => $html, 'is_blocked' => $isBlocked ? 1 : 0];
    }

    private static function renderItemRow(array $row, bool $canedit, array $typeLabels, array $tagsById = [], bool $reorderable = false, array $sprintNames = []): void
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
            . "data-item-tags='" . htmlescape(SprintItem::tagsToBlob($rowTags)) . "'>";
        if ($reorderable && $canedit) {
            echo "<td class='sprint-backlog-grip' style='cursor:grab;text-align:center;color:#adb5bd;' "
                . "title='" . __('Drag to reorder', 'sprint') . "'><i class='fas fa-grip-vertical'></i></td>";
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
            . SprintItem::renderTagPills($rowTags);
        if ($isReady) {
            echo " <span class='sprint-ready-badge' title='" . __('Owner, capacity and sprint set — ready for the Scrum Master to assign', 'sprint') . "'>"
                . "<i class='fas fa-check'></i> " . __('Ready', 'sprint') . "</span>";
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
