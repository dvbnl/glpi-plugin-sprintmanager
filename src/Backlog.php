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
     * Menu entry, registered via $PLUGIN_HOOKS['menu_toadd'] in setup.php so
     * "Backlog" appears as its own item next to SprintManager.
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

    public static function showBacklog(): void
    {
        $canedit = Session::haveRight(SprintItem::$rightname, UPDATE)
            || Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS);

        $typeLabels = [
            ''            => __('Manual', 'sprint'),
            'Ticket'      => __('Ticket'),
            'Change'      => __('Change'),
            'Problem'     => __('Problem'),
            'ProjectTask' => __('Project task'),
        ];

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

        echo "<div class='center'>";
        echo "<h2><i class='" . self::getIcon() . "'></i> " . self::getTypeName(2) . "</h2>";
        echo "<p class='text-muted'>" .
            __('Items waiting to be assigned to a sprint. Use the dropdown to move an item into a sprint.', 'sprint') .
            "</p>";

        self::renderBlockedSection($blocked, $canedit, $typeLabels, $tagsById);

        // Count pre-selected items the Scrum Master can assign in one click.
        $readyCount = 0;
        foreach (array_merge($blocked, $items) as $r) {
            if ((int)($r['proposed_sprints_id'] ?? 0) > 0) {
                $readyCount++;
            }
        }

        echo "<div style='display:flex;align-items:center;gap:10px;margin-top:20px;'>";
        echo "<h3 style='margin:0;text-align:left;flex:1;'>"
            . "<i class='fas fa-list'></i> " . __('Backlog items', 'sprint')
            . " <span class='badge bg-secondary'>" . count($items) . "</span></h3>";
        if ($canedit && $readyCount > 0) {
            echo "<button type='button' class='btn btn-sm btn-success sprint-backlog-bulk-assign'>"
                . "<i class='fas fa-layer-group me-1'></i>"
                . sprintf(__('Assign all ready (%d)', 'sprint'), $readyCount)
                . "</button>";
        }
        echo "</div>";

        // Owners present in the backlog, for the owner filter.
        $owners = [];
        foreach (array_merge($blocked, $items) as $r) {
            $uid = (int)($r['users_id'] ?? 0);
            if ($uid > 0 && !isset($owners[$uid])) {
                $owners[$uid] = getUserName($uid);
            }
        }
        asort($owners);

        self::renderFilterBar($typeLabels, $owners);

        echo "<table class='tab_cadre_fixe sprint-backlog-table'>";
        echo "<tr class='tab_bg_2'>";
        if ($canedit) {
            echo "<th style='width:26px;' title='" . __('Drag to reorder', 'sprint') . "'></th>";
        }
        echo "<th>" . __('Name') . "</th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th>" . __('Type', 'sprint') . "</th>";
        echo "<th><i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;'></i>" . __('Is Fastlane', 'sprint') . "</th>";
        echo "<th><i class='fas fa-ban' style='color:#dc3545;margin-right:4px;'></i>" . __('Is Blocked', 'sprint') . "</th>";
        echo "<th><i class='fas fa-user' style='margin-right:4px;'></i>" . __('Owner', 'sprint') . "</th>";
        echo "<th title='" . __('Estimated capacity (informational on backlog; enforced once the item joins a sprint)', 'sprint') . "'>"
            . __('Est. capacity', 'sprint') . " %</th>";
        if ($canedit) {
            echo "<th>" . __('Assign to sprint', 'sprint') . "</th>";
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($items) === 0) {
            $cols = $canedit ? 10 : 7;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>"
                . __('Backlog is empty', 'sprint') . "</td></tr>";
        }

        foreach ($items as $row) {
            self::renderItemRow($row, $canedit, $typeLabels, $tagsById, true);
        }

        echo "</table>";
        echo "</div>";

        // Mounts the modal + JS for the quick-edit-linked-item buttons rendered
        // by SprintItem::getLinkedItemDisplay(); without it they're no-ops.
        SprintItem::renderLinkedQuickEditUI();

        self::renderInlineEditScript();
        self::renderDependencyUI();
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

        $capacityOptions = '';
        foreach (SprintMember::getCapacityChoices(false) as $val => $label) {
            $capacityOptions .= "<option value='" . (int)$val . "'>" . htmlescape($label) . "</option>";
        }

        $titleAdd      = __('Add dependency', 'sprint');
        $lblMember     = __('Sprint member', 'sprint');
        $lblCapacity   = __('Capacity', 'sprint');
        $lblCancel     = __('Cancel');
        $lblAdd        = __('Add', 'sprint');
        $msgPickSprint = __('Pre-select a sprint for this item first.', 'sprint');
        $msgNoMembers  = __('The selected sprint has no members yet.', 'sprint');
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

    function currentSprintFor(itemId) {
        var \$sel = jQuery('.sprint-backlog-sprint-wrap[data-item-id="' + itemId + '"] select');
        return \$sel.length ? (parseInt(\$sel.val(), 10) || 0) : 0;
    }

    jQuery(document).on('click', '.sprint-backlog-deps-btn', function(){
        var \$btn    = jQuery(this);
        var itemId  = parseInt(\$btn.data('item-id'), 10) || 0;
        var name    = \$btn.data('item-name') || '';
        var ownerId = parseInt(\$btn.data('owner-id'), 10) || 0;
        var sprintId = currentSprintFor(itemId);
        if (itemId <= 0) { return; }
        if (sprintId <= 0) {
            if (window.glpi_toast_warning) { window.glpi_toast_warning("{$msgPickSprint}"); }
            return;
        }
        \$modal.data('item-id', itemId);
        \$modal.find('.sprint-deps-item-name').text(name);
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
            }
        });

        var inst = bootstrap.Modal.getOrCreateInstance(\$modal[0]);
        inst.show();
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
                if (window.glpi_toast_info) { window.glpi_toast_info(resp.message || 'Saved'); }
                bootstrap.Modal.getOrCreateInstance(\$modal[0]).hide();
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
        });
    }

    jQuery(document).on('click', '.sprint-deps-add', function(){
        var itemId   = parseInt(\$modal.data('item-id'), 10) || 0;
        var userId   = parseInt(\$modal.find('.sprint-deps-member').val(), 10) || 0;
        var capacity = parseInt(\$modal.find('.sprint-deps-capacity').val(), 10) || 0;
        if (itemId <= 0 || userId <= 0 || capacity <= 0) {
            if (window.glpi_toast_warning) { window.glpi_toast_warning('Select a member and capacity'); }
            return;
        }
        doAdd(itemId, userId, capacity, false);
    });
})();
</script>
HTML;
    }

    /**
     * On-change handlers for the inline owner/capacity/sprint selects.
     * Persist via ajax/updateitemquick.php so users can pre-plan on the
     * backlog without round-tripping a form.
     */
    private static function renderInlineEditScript(): void
    {
        $endpoint = Plugin::getWebDir('sprint') . '/ajax/updateitemquick.php';
        $tokenUrl = Plugin::getWebDir('sprint') . '/ajax/csrftoken.php';
        $bulkUrl  = Plugin::getWebDir('sprint') . '/ajax/bulkassign.php';
        $bulkConfirm = addslashes(__('Assign all backlog items that have a pre-selected sprint? Only the ones you are Scrum Master of will be assigned.', 'sprint'));
        $reorderUrl  = Plugin::getWebDir('sprint') . '/ajax/reorder.php';
        $savedOrderMsg = addslashes(__('Order saved', 'sprint'));

        echo <<<HTML
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }
    var endpoint = "{$endpoint}";
    var tokenUrl = "{$tokenUrl}";

    function findRowId(\$el) {
        var \$row = \$el.closest('tr.sprint-backlog-row');
        return \$row.length ? parseInt(\$row.data('item-id'), 10) || 0 : 0;
    }

    function postUpdate(itemId, field, value, \$el) {
        \$el.prop('disabled', true);
        jQuery.ajax({
            url: tokenUrl, type: 'GET', dataType: 'json', cache: false
        }).then(function(tokResp) {
            var data = {
                id: itemId,
                _glpi_csrf_token: tokResp && tokResp.token ? tokResp.token : ''
            };
            data[field] = value;
            return jQuery.ajax({
                url: endpoint, type: 'POST', dataType: 'json', data: data
            });
        }).done(function(resp) {
            if (resp && resp.success) {
                if (window.glpi_toast_info) {
                    window.glpi_toast_info('Saved');
                }
            } else {
                if (window.glpi_toast_error) {
                    window.glpi_toast_error((resp && resp.message) || 'Save failed');
                }
            }
        }).fail(function() {
            if (window.glpi_toast_error) {
                window.glpi_toast_error('Network error');
            }
        }).always(function() {
            \$el.prop('disabled', false);
        });
    }

    jQuery(document).on('change', '.sprint-backlog-owner-wrap select', function() {
        var \$sel = jQuery(this);
        var itemId = parseInt(\$sel.closest('.sprint-backlog-owner-wrap').data('item-id'), 10) || 0;
        if (itemId > 0) {
            postUpdate(itemId, 'users_id', parseInt(\$sel.val(), 10) || 0, \$sel);
        }
    });

    jQuery(document).on('change', '.sprint-backlog-capacity-wrap select', function() {
        var \$sel = jQuery(this);
        var itemId = parseInt(\$sel.closest('.sprint-backlog-capacity-wrap').data('item-id'), 10) || 0;
        if (itemId > 0) {
            postUpdate(itemId, 'capacity', parseInt(\$sel.val(), 10) || 0, \$sel);
        }
    });

    // Pre-select a target sprint (Scrum Master still has to press Assign).
    jQuery(document).on('change', '.sprint-backlog-sprint-wrap select', function() {
        var \$sel = jQuery(this);
        var itemId = parseInt(\$sel.closest('.sprint-backlog-sprint-wrap').data('item-id'), 10) || 0;
        if (itemId > 0) {
            postUpdate(itemId, 'proposed_sprints_id', parseInt(\$sel.val(), 10) || 0, \$sel);
        }
    });

    // Kick-off: assign every item that has a pre-selected sprint in one click.
    jQuery(document).on('click', '.sprint-backlog-bulk-assign', function() {
        var \$btn = jQuery(this);
        if (!window.confirm('{$bulkConfirm}')) { return; }
        \$btn.prop('disabled', true);
        jQuery.ajax({ url: tokenUrl, type: 'GET', dataType: 'json', cache: false })
        .then(function(tok) {
            return jQuery.ajax({
                url: "{$bulkUrl}", type: 'POST', dataType: 'json',
                data: { _glpi_csrf_token: tok && tok.token ? tok.token : '' }
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

    private static function renderBlockedSection(array $blockedItems, bool $canedit, array $typeLabels, array $tagsById = []): void
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
            echo "<th><i class='fas fa-user' style='margin-right:4px;'></i>" . __('Owner', 'sprint') . "</th>";
            echo "<th>" . __('Est. capacity', 'sprint') . " %</th>";
            if ($canedit) {
                echo "<th>" . __('Assign to sprint', 'sprint') . "</th>";
                echo "<th>" . __('Actions') . "</th>";
            }
            echo "</tr>";
            foreach ($blockedItems as $row) {
                self::renderItemRow($row, $canedit, $typeLabels, $tagsById);
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

    private static function renderItemRow(array $row, bool $canedit, array $typeLabels, array $tagsById = [], bool $reorderable = false): void
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
        $rowTags    = $tagsById[(int)$row['id']] ?? [];

        $ownerName = (int)($row['users_id'] ?? 0) > 0 ? getUserName((int)$row['users_id']) : '';
        echo "<tr class='tab_bg_1 sprint-filterable-row sprint-backlog-row' "
            . "data-item-id='" . (int)$row['id'] . "' "
            . "data-item-name='" . htmlescape($row['name']) . "' "
            . "data-item-type='" . htmlescape($typeKey) . "' "
            . "data-users-id='" . (int)($row['users_id'] ?? 0) . "' "
            . "data-owner-name='" . htmlescape($ownerName) . "' "
            . "data-item-tags='" . htmlescape(SprintItem::tagsToBlob($rowTags)) . "'>";
        if ($reorderable && $canedit) {
            echo "<td class='sprint-backlog-grip' style='cursor:grab;text-align:center;color:#adb5bd;' "
                . "title='" . __('Drag to reorder', 'sprint') . "'><i class='fas fa-grip-vertical'></i></td>";
        }

        $isReady = (int)($row['proposed_sprints_id'] ?? 0) > 0
            && (int)($row['users_id'] ?? 0) > 0
            && (int)($row['capacity'] ?? 0) > 0;
        echo "<td><a href='" . SprintItem::getFormURLWithID($row['id']) . "'>"
            . htmlescape($row['name']) . "</a>" . SprintItem::renderTagPills($rowTags);
        if ($isReady) {
            echo " <span class='sprint-ready-badge' title='" . __('Owner, capacity and sprint set — ready for the Scrum Master to assign', 'sprint') . "'>"
                . "<i class='fas fa-check'></i> " . __('Ready', 'sprint') . "</span>";
        }
        echo "</td>";
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

        $ownerId       = (int)($row['users_id'] ?? 0);
        $estCapacity   = (int)($row['capacity'] ?? 0);

        echo "<td class='center'>";
        if ($canedit) {
            echo "<span class='sprint-backlog-owner-wrap' data-item-id='" . (int)$row['id'] . "'>";
            User::dropdown([
                'name'      => '_backlog_users_id_' . (int)$row['id'],
                'value'     => $ownerId,
                'right'     => 'all',
                'entity'    => $_SESSION['glpiactiveentities'] ?? -1,
                'rand'      => (int)$row['id'],
                'width'     => '180px',
            ]);
            echo "</span>";
        } else {
            echo $ownerId > 0
                ? htmlescape(getUserName($ownerId))
                : "<span class='text-muted'>" . __('Unassigned', 'sprint') . "</span>";
        }
        echo "</td>";

        echo "<td class='center'>";
        if ($canedit) {
            echo "<span class='sprint-backlog-capacity-wrap' data-item-id='" . (int)$row['id'] . "'>";
            Dropdown::showFromArray('_backlog_capacity_' . (int)$row['id'], SprintMember::getCapacityChoices(), [
                'value'    => $estCapacity,
                'rand'     => (int)$row['id'],
                'width'    => '90px',
            ]);
            echo "</span>";
        } else {
            echo $estCapacity > 0
                ? $estCapacity . '%'
                : "<span class='text-muted'>-</span>";
        }
        echo "</td>";

        if ($canedit) {
            $proposedId = (int)($row['proposed_sprints_id'] ?? 0);

            echo "<td>";
            // Anyone who can edit may pre-select a sprint (persisted to
            // proposed_sprints_id), but only its Scrum Master or a full updater
            // can Assign — enforced server-side in assigntosprint.php.
            echo "<form method='post' action='" . self::getFormURL() . "' "
                . "class='sprint-backlog-assign-form' style='display:flex;gap:4px;align-items:center;' "
                . "data-item-id='" . (int)$row['id'] . "'>";
            echo Html::hidden('id', ['value' => $row['id']]);
            echo "<span class='sprint-backlog-sprint-wrap' data-item-id='" . (int)$row['id'] . "'>";
            Sprint::dropdown([
                'name'  => 'plugin_sprint_sprints_id',
                'value' => $proposedId,
                'width' => '160px',
                'rand'  => (int)$row['id'],
            ]);
            echo "</span>";
            $assignTitle = $isFastlane
                ? __('Fastlane item — anyone can assign it to a sprint', 'sprint')
                : __('Only the Scrum Master of the selected sprint can assign it', 'sprint');
            echo "<button type='submit' name='assign_to_sprint' value='1' class='btn btn-sm btn-primary sprint-backlog-assign-btn' "
                . "title='" . $assignTitle . "'>"
                . "<i class='fas fa-arrow-right'></i> " . __('Assign', 'sprint') . "</button>";
            Html::closeForm();
            echo "</td>";

            echo "<td class='center' style='white-space:nowrap;'>";
            // Dependency button hidden for fastlane items: they spread capacity
            // across members via the Fastlane junction, not single-owner deps.
            if (!$isFastlane) {
                echo "<button type='button' class='btn btn-sm btn-outline-secondary sprint-backlog-deps-btn me-1' "
                    . "data-item-id='" . (int)$row['id'] . "' "
                    . "data-item-name='" . htmlescape($row['name']) . "' "
                    . "data-owner-id='" . (int)($row['users_id'] ?? 0) . "' "
                    . "title='" . __('Add dependency (only members of the selected sprint)', 'sprint') . "'>"
                    . "<i class='fas fa-link'></i></button>";
            }
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

    private static function renderFilterBar(array $typeLabels, array $owners = []): void
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
