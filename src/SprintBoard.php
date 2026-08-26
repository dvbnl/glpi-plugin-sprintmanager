<?php

namespace GlpiPlugin\Sprint;

use CommonGLPI;
use Session;
use Plugin;

/**
 * Kanban board tab on a Sprint. Not a CommonDBTM: a virtual view over the
 * sprint's SprintItem rows grouped by status. Cards drag between columns
 * (native HTML5 DnD) and persist via ajax/updatestatus.php, which enforces
 * the per-item permission.
 */
class SprintBoard extends CommonGLPI
{
    public static $rightname = 'plugin_sprint_item';

    public static function getTypeName($nb = 0): string
    {
        return _n('Board', 'Board', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-columns';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Sprint) {
            $count = countElementsInTable(
                SprintItem::getTable(),
                ['plugin_sprint_sprints_id' => $item->getID()]
            );
            return self::createTabEntry(self::getTypeName(1), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof Sprint) {
            self::showForSprint($item);
            return true;
        }
        return false;
    }

    /**
     * Workflow columns, in board order, with their accent colour.
     */
    private static function columns(): array
    {
        return [
            SprintItem::STATUS_TODO        => '#6c757d',
            SprintItem::STATUS_IN_PROGRESS => '#0d6efd',
            SprintItem::STATUS_REVIEW      => '#6f42c1',
            SprintItem::STATUS_DEPENDENCY  => '#20c997',
            SprintItem::STATUS_BLOCKED     => '#dc3545',
            SprintItem::STATUS_DONE        => '#198754',
        ];
    }

    public static function showForSprint(Sprint $sprint): void
    {
        $sprintId = $sprint->getID();
        $fullEdit = SprintItem::canUpdate();
        $ownEdit  = Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS);
        $canedit  = $fullEdit || $ownEdit;
        $uid      = (int)Session::getLoginUserID();

        Sprint::renderHeaderBar($sprint);

        $si    = new SprintItem();
        $items = $si->find(
            ['plugin_sprint_sprints_id' => $sprintId],
            ['sort_order ASC', 'priority DESC']
        );

        $statuses = SprintItem::getAllStatuses();
        $columns  = self::columns();

        $byStatus = [];
        foreach (array_keys($columns) as $st) {
            $byStatus[$st] = [];
        }
        foreach ($items as $row) {
            $st = (string)($row['status'] ?? SprintItem::STATUS_TODO);
            if (!isset($byStatus[$st])) {
                // Unknown/legacy status falls into To-do so it's never hidden.
                $st = SprintItem::STATUS_TODO;
            }
            $byStatus[$st][] = $row;
        }

        $itemIds  = array_map(fn($r) => (int)$r['id'], $items);
        $tagsById = SprintItem::getTagsForItems($itemIds);
        $depsById = SprintItemDependency::getOpenSummariesForItems($itemIds);

        $memberCount = countElementsInTable(
            SprintMember::getTable(),
            ['plugin_sprint_sprints_id' => $sprintId]
        );
        $wipLimits = SprintAgility::getWipLimits($sprint->fields);

        echo "<div class='sprint-kanban-intro text-muted' style='padding:10px 4px;'>"
            . "<i class='" . self::getIcon() . "' style='margin-right:6px;'></i>"
            . ($canedit
                ? __('Drag a card to another column to change its status.', 'sprint')
                : __('Sprint items grouped by status.', 'sprint'))
            . "</div>";

        self::renderViewTools($items, $tagsById);

        echo "<div class='sprint-kanban' data-sprint-id='" . (int)$sprintId . "'>";
        foreach ($columns as $status => $color) {
            $label = $statuses[$status] ?? $status;
            $cards = $byStatus[$status];
            $limit = (int)($wipLimits[$status] ?? 0);
            // Per-person limits: flag when one owner exceeds it (fastlane
            // exempt); team-size fallback for In-progress without a limit.
            $perOwner = [];
            foreach ($cards as $row) {
                if ((int)($row['is_fastlane'] ?? 0) === 1) {
                    continue;
                }
                $ownerKey = (int)($row['users_id'] ?? 0);
                $perOwner[$ownerKey] = ($perOwner[$ownerKey] ?? 0) + 1;
            }
            $maxPerOwner = $perOwner ? max($perOwner) : 0;
            $overWip = $limit > 0
                ? $maxPerOwner > $limit
                : ($status === SprintItem::STATUS_IN_PROGRESS && $memberCount > 0 && count($cards) > $memberCount);
            echo "<div class='sprint-kanban-col" . ($overWip ? ' sk-over-wip' : '') . "' style='--sk-accent:" . htmlescape($color) . ";'>";
            echo "<div class='sprint-kanban-col-header'>"
                . "<span class='sprint-kanban-dot' style='background:" . htmlescape($color) . ";'></span>"
                . "<span class='sprint-kanban-col-title'>" . htmlescape($label) . "</span>"
                . ($overWip
                    ? "<i class='fas fa-triangle-exclamation' style='color:#fd7e14;' title='"
                        . ($limit > 0
                            ? sprintf(__('WIP limit exceeded: a member has %1$d items, limit %2$d per person', 'sprint'), $maxPerOwner, $limit)
                            : sprintf(__('WIP limit exceeded: %1$d items, limit %2$d', 'sprint'), count($cards), $memberCount))
                        . "'></i>"
                    : "")
                . "<span class='sprint-kanban-count'>" . count($cards) . ($limit > 0 ? ' / ' . $limit : '') . "</span>"
                . "</div>";
            echo "<div class='sprint-kanban-body' data-status='" . htmlescape($status) . "'>";
            foreach ($cards as $row) {
                self::renderCard($row, $tagsById, $depsById, $fullEdit, $ownEdit, $uid);
            }
            echo "</div>"; // body
            echo "</div>"; // col
        }
        echo "</div>"; // kanban

        self::renderBoardScript();
        // Quick-edit modals opened by the card and linked-item pencil buttons.
        if ($canedit) {
            SprintItem::renderQuickEditUI($sprintId);
        }
        SprintItem::renderLinkedQuickEditUI();
    }

    private static function renderCard(
        array $row,
        array $tagsById,
        array $depsById,
        bool $fullEdit,
        bool $ownEdit,
        int $uid
    ): void {
        $itemId    = (int)$row['id'];
        $isOwn     = (int)($row['users_id'] ?? 0) === $uid;
        $draggable = $fullEdit || ($ownEdit && $isOwn);

        $rowTags = $tagsById[$itemId] ?? [];
        $rowDeps = $depsById[$itemId] ?? [];

        $linkedDisplay = '';
        if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
            $tmp = new SprintItem();
            $tmp->fields = $row;
            $linkedDisplay = $tmp->getLinkedItemDisplay();
        }

        $ownerId   = (int)($row['users_id'] ?? 0);
        $ownerName = $ownerId > 0 ? SprintCache::userName($ownerId) : '';
        $points    = (int)($row['story_points'] ?? 0);
        $capacity  = (float)($row['capacity'] ?? 0);
        $isFastlane = (int)($row['is_fastlane'] ?? 0) === 1;

        // Quick-edit data-* attrs (includes data-item-id — don't duplicate it).
        $dataAttrs = SprintItem::buildItemDataAttrs($row, $rowTags);
        echo "<div class='sprint-kanban-card' {$dataAttrs} "
            . "data-status='" . htmlescape((string)($row['status'] ?? '')) . "'"
            . ($draggable ? " draggable='true'" : " data-locked='1'")
            . ">";

        echo "<div class='sk-card-title'>";
        if ($isFastlane) {
            echo "<i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;' title='" . __('Fastlane', 'sprint') . "'></i>";
        }
        echo "<a href='" . SprintItem::getFormURLWithID($itemId) . "'>" . htmlescape($row['name']) . "</a>";
        echo SprintCategory::renderPill((int)($row['plugin_sprint_sprintcategories_id'] ?? 0));
        echo SprintItem::renderTagPills($rowTags);
        echo SprintItem::renderDependencyBadge($rowDeps);
        echo SprintItem::renderLinkedItemOpenBadge($row);
        if ($draggable) {
            echo "<button type='button' class='btn btn-sm btn-link p-0 sk-card-edit sprint-quick-edit-btn' "
                . "title='" . __('Quick edit', 'sprint') . "'><i class='fas fa-pen'></i></button>";
        }
        echo "</div>";

        if ($linkedDisplay !== '') {
            echo "<div class='sk-card-linked'>" . $linkedDisplay
                . SprintItem::renderParentProjectSuffix((string)($row['itemtype'] ?? ''), (int)($row['items_id'] ?? 0))
                . "</div>";
        }

        echo "<div class='sk-card-meta'>";
        if ($ownerName !== '') {
            echo "<span class='sk-card-owner'><i class='fas fa-user'></i> " . htmlescape($ownerName) . "</span>";
        } else {
            echo "<span class='sk-card-owner text-muted fst-italic'>" . __('Unassigned', 'sprint') . "</span>";
        }
        echo "<span class='sk-card-stats'>";
        echo "<span title='" . __('Story Points', 'sprint') . "'><i class='fas fa-bullseye'></i> " . $points . "</span>";
        if ($capacity > 0) {
            echo " <span title='" . __('Capacity (%)', 'sprint') . "'><i class='fas fa-gauge-high'></i> " . SprintMember::formatCapacity($capacity) . "%</span>";
        }
        echo "</span>";
        echo "</div>";

        echo "</div>"; // card
    }

    private static function renderViewTools(array $items, array $tagsById): void
    {
        $owners = []; $tags = [];
        foreach ($items as $row) {
            $uid = (int)($row['users_id'] ?? 0);
            $owners[$uid] = $uid > 0 ? SprintCache::userName($uid) : __('Unassigned', 'sprint');
            foreach ($tagsById[(int)$row['id']] ?? [] as $tag) $tags[$tag] = $tag;
        }
        asort($owners); natcasesort($tags);
        echo "<div class='d-flex flex-wrap gap-2 align-items-center mb-2 p-2 rounded border'><i class='fas fa-filter text-muted'></i><select class='form-select form-select-sm' id='sb-owner' style='width:auto'><option value=''>" . __('All owners', 'sprint') . '</option>';
        foreach ($owners as $id => $name) echo "<option value='" . (int)$id . "'>" . htmlescape($name) . '</option>';
        echo "</select><select class='form-select form-select-sm' id='sb-tag' style='width:auto'><option value=''>" . __('All tags', 'sprint') . '</option>';
        foreach ($tags as $tag) echo "<option value='" . htmlescape(mb_strtolower($tag)) . "'>" . htmlescape($tag) . '</option>';
        echo "</select><label class='form-check form-switch mb-0'><input class='form-check-input' id='sb-swimlane' type='checkbox'><span class='form-check-label'>" . __('Owner swimlanes', 'sprint') . "</span></label><button type='button' class='btn btn-sm btn-outline-secondary' id='sb-reset'>" . __('Reset') . '</button></div>';
    }

    private static function renderBoardScript(): void
    {
        $endpoint = Plugin::getWebDir('sprint') . '/ajax/updatestatus.php';
        $tokenUrl = Plugin::getWebDir('sprint') . '/ajax/csrftoken.php';
        $errMove  = addslashes(__('Could not change the status — you can only move your own items.', 'sprint'));

        // Confirmation dialog shown when a card moves to Review/Done while its
        // underlying GLPI item is still open (see ajax/updatestatus.php).
        $ttlLinkedOpen = htmlescape(__('Linked item open', 'sprint'));
        $msgLinkedOpen = htmlescape(__('The linked ticket/change is not closed/solved yet', 'sprint'));
        $btnOpenLinked = htmlescape(__('Open linked item', 'sprint'));
        $btnContinue   = htmlescape(__('Continue anyway', 'sprint'));
        $btnCancel     = htmlescape(__('Cancel'));
        $unassignedJs  = addslashes(__('Unassigned', 'sprint'));
        echo "<div class='modal fade' id='sprint-linkedopen-modal' tabindex='-1' aria-hidden='true'>"
            . "<div class='modal-dialog modal-dialog-centered'>"
            . "<div class='modal-content'>"
            . "<div class='modal-header'>"
            . "<h5 class='modal-title'><i class='fas fa-exclamation-triangle text-warning me-2'></i>{$ttlLinkedOpen}</h5>"
            . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>"
            . "</div>"
            . "<div class='modal-body'>"
            . "<p class='mb-1'>{$msgLinkedOpen}</p>"
            . "<p class='sprint-lo-name fw-bold mb-0'></p>"
            . "</div>"
            . "<div class='modal-footer'>"
            . "<a href='#' target='_blank' rel='noopener' class='btn btn-outline-primary sprint-lo-open'>"
            . "<i class='fas fa-external-link-alt me-1'></i>{$btnOpenLinked}</a>"
            . "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>{$btnCancel}</button>"
            . "<button type='button' class='btn btn-warning sprint-lo-continue'>{$btnContinue}</button>"
            . "</div>"
            . "</div></div></div>";

        // DoD dialog for moves to Review/Done (needs_dod response).
        $ttlDod = htmlescape(__('Definition of Done', 'sprint'));
        $msgDod = htmlescape(__('Confirm every check before moving this item on.', 'sprint'));
        $btnDod = htmlescape(__('Confirm', 'sprint'));
        echo "<div class='modal fade' id='sprint-dod-modal' tabindex='-1' aria-hidden='true'>"
            . "<div class='modal-dialog modal-dialog-centered'>"
            . "<div class='modal-content'>"
            . "<div class='modal-header'>"
            . "<h5 class='modal-title'><i class='fas fa-list-check me-2'></i>{$ttlDod}</h5>"
            . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>"
            . "</div>"
            . "<div class='modal-body'>"
            . "<p class='text-muted sprint-small mb-2'>{$msgDod}</p>"
            . "<div class='sprint-dod-list'></div>"
            . "</div>"
            . "<div class='modal-footer'>"
            . "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>{$btnCancel}</button>"
            . "<button type='button' class='btn btn-success sprint-dod-go' disabled>{$btnDod}</button>"
            . "</div>"
            . "</div></div></div>";

        echo <<<JS
<script>
(function(){
    if (window.__sprintKanbanBound) { return; }
    window.__sprintKanbanBound = true;
    if (typeof jQuery === 'undefined') { return; }

    // Personal display settings stay local.
    (function(){
        var owner = document.getElementById('sb-owner'), tag = document.getElementById('sb-tag'), swim = document.getElementById('sb-swimlane');
        if (!owner || !tag || !swim) return;
        document.querySelectorAll('.sprint-kanban-body').forEach(function(body){Array.from(body.querySelectorAll('.sprint-kanban-card')).forEach(function(card,index){card.dataset.boardOrder=String(index);});});
        var key = 'sprint-board-view-' + (document.querySelector('.sprint-kanban') || {}).dataset.sprintId;
        try { var saved = JSON.parse(localStorage.getItem(key) || '{}'); owner.value=saved.owner||'';tag.value=saved.tag||'';swim.checked=!!saved.swim; } catch(e) {}
        function apply(){
            document.querySelectorAll('.sprint-kanban-card').forEach(function(card){
                var ownerOk=!owner.value||String(card.dataset.usersId||'0')===owner.value;
                var blob=String(card.dataset.itemTags||'').toLowerCase();
                var tagOk=!tag.value||blob.indexOf('|'+tag.value+'|')!==-1;
                card.style.display=ownerOk&&tagOk?'':'none';
            });
            document.querySelectorAll('.sb-owner-lane').forEach(function(n){n.remove();});
            if(swim.checked){
                document.querySelectorAll('.sprint-kanban-body').forEach(function(body){
                    var last='';
                    Array.from(body.querySelectorAll('.sprint-kanban-card')).sort(function(a,b){return String(a.dataset.ownerName).localeCompare(String(b.dataset.ownerName));}).forEach(function(card){
                        var name=card.dataset.ownerName||'{$unassignedJs}';
                        if(card.style.display!== 'none' && name!==last){var h=document.createElement('div');h.className='sb-owner-lane sprint-small fw-bold text-muted mt-2 mb-1';h.textContent=name;body.appendChild(h);last=name;}
                        body.appendChild(card);
                    });
                });
            } else {
                document.querySelectorAll('.sprint-kanban-body').forEach(function(body){
                    Array.from(body.querySelectorAll('.sprint-kanban-card')).sort(function(a,b){return parseInt(a.dataset.boardOrder||'0',10)-parseInt(b.dataset.boardOrder||'0',10);}).forEach(function(card){body.appendChild(card);});
                });
            }
            try { localStorage.setItem(key,JSON.stringify({owner:owner.value,tag:tag.value,swim:swim.checked})); } catch(e) {}
        }
        owner.addEventListener('change',apply);tag.addEventListener('change',apply);swim.addEventListener('change',apply);
        document.getElementById('sb-reset').addEventListener('click',function(){owner.value='';tag.value='';swim.checked=false;apply();});
        setTimeout(apply,0);
    })();

    var endpoint = "{$endpoint}";
    var tokenUrl = "{$tokenUrl}";

    function recount() {
        document.querySelectorAll('.sprint-kanban-col').forEach(function(col){
            var n = col.querySelectorAll('.sprint-kanban-card').length;
            var c = col.querySelector('.sprint-kanban-count');
            if (c) { c.textContent = n; }
        });
    }

    document.addEventListener('dragstart', function(e){
        var card = e.target.closest && e.target.closest('.sprint-kanban-card');
        if (!card || card.getAttribute('draggable') !== 'true') { return; }
        try { e.dataTransfer.setData('text/plain', card.getAttribute('data-item-id')); } catch (ex) {}
        e.dataTransfer.effectAllowed = 'move';
        card.classList.add('sk-dragging');
    });

    document.addEventListener('dragend', function(e){
        var card = e.target.closest && e.target.closest('.sprint-kanban-card');
        if (card) { card.classList.remove('sk-dragging'); }
        document.querySelectorAll('.sprint-kanban-body.sk-drag-over')
            .forEach(function(b){ b.classList.remove('sk-drag-over'); });
    });

    document.addEventListener('dragover', function(e){
        var body = e.target.closest && e.target.closest('.sprint-kanban-body');
        if (!body) { return; }
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        body.classList.add('sk-drag-over');
    });

    document.addEventListener('dragleave', function(e){
        var body = e.target.closest && e.target.closest('.sprint-kanban-body');
        if (body && !body.contains(e.relatedTarget)) { body.classList.remove('sk-drag-over'); }
    });

    // Context of a move waiting on the "linked item still open" confirmation.
    var pendingMove = null;
    var pendingMoveConfirmed = false;

    function revertMove(card, fromBody) {
        fromBody.appendChild(card);
        card.setAttribute('data-status', fromBody.getAttribute('data-status'));
        recount();
    }

    // Swap the card's "Linked item open" warning badge for the fresh
    // server-rendered state (empty string removes it).
    function syncLinkedOpenBadge(card, html) {
        var title = card.querySelector('.sk-card-title');
        if (!title) { return; }
        title.querySelectorAll('.sprint-review-unclosed-badge').forEach(function(b){ b.remove(); });
        if (html) {
            var editBtn = title.querySelector('.sk-card-edit');
            if (editBtn) { editBtn.insertAdjacentHTML('beforebegin', html); }
            else { title.insertAdjacentHTML('beforeend', html); }
        }
    }

    function persistStatus(id, newStatus, card, fromBody, confirmOpen, doneList) {
        jQuery.ajax({ url: tokenUrl, type: 'GET', dataType: 'json', cache: false })
        .then(function(tok){
            var data = {
                id: id, status: newStatus,
                confirm_linked_open: confirmOpen ? 1 : 0,
                _glpi_csrf_token: tok && tok.token ? tok.token : ''
            };
            if (doneList && doneList.length) { data.done = doneList; }
            return jQuery.ajax({
                url: endpoint, type: 'POST', dataType: 'json',
                data: data
            });
        }).done(function(resp){
            if (resp && resp.needs_dod) {
                var dodEl = document.getElementById('sprint-dod-modal');
                if (!dodEl) {
                    revertMove(card, fromBody);
                    if (window.glpi_toast_error) { window.glpi_toast_error(resp.message || "{$errMove}"); }
                    return;
                }
                pendingMove = { id: id, newStatus: newStatus, card: card, fromBody: fromBody, doneList: doneList || null };
                pendingMoveConfirmed = false;
                var list = dodEl.querySelector('.sprint-dod-list');
                list.innerHTML = '';
                (resp.dod || []).forEach(function(check){
                    var label = document.createElement('label');
                    label.className = 'form-check d-block';
                    var input = document.createElement('input');
                    input.type = 'checkbox';
                    input.className = 'form-check-input sprint-dod-check';
                    input.value = check;
                    input.checked = (resp.checked || []).indexOf(check) !== -1;
                    var span = document.createElement('span');
                    span.className = 'form-check-label';
                    span.textContent = check;
                    label.appendChild(input);
                    label.appendChild(span);
                    list.appendChild(label);
                });
                var goBtn = dodEl.querySelector('.sprint-dod-go');
                var refreshGo = function(){
                    goBtn.disabled = !!list.querySelector('.sprint-dod-check:not(:checked)');
                };
                list.querySelectorAll('.sprint-dod-check').forEach(function(cb){
                    cb.addEventListener('change', refreshGo);
                });
                refreshGo();
                bootstrap.Modal.getOrCreateInstance(dodEl).show();
                return;
            }
            if (resp && resp.needs_confirm) {
                var modalEl = document.getElementById('sprint-linkedopen-modal');
                if (!modalEl) {
                    // Modal missing (shouldn't happen): behave like before.
                    revertMove(card, fromBody);
                    if (window.glpi_toast_error) { window.glpi_toast_error(resp.message || "{$errMove}"); }
                    return;
                }
                pendingMove = { id: id, newStatus: newStatus, card: card, fromBody: fromBody };
                pendingMoveConfirmed = false;
                var nameEl = modalEl.querySelector('.sprint-lo-name');
                if (nameEl) { nameEl.textContent = resp.linked_name || ''; }
                var openBtn = modalEl.querySelector('.sprint-lo-open');
                if (openBtn) {
                    if (resp.linked_url) {
                        openBtn.style.display = '';
                        openBtn.setAttribute('href', resp.linked_url);
                    } else {
                        openBtn.style.display = 'none';
                    }
                }
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
                return;
            }
            if (resp && resp.success) {
                syncLinkedOpenBadge(card, resp.linked_open_badge_html || '');
                if (window.glpi_toast_info) { window.glpi_toast_info(resp.message || 'Status updated'); }
            } else {
                revertMove(card, fromBody);
                var msg = (resp && resp.message && resp.message !== 'Update failed')
                    ? resp.message : "{$errMove}";
                if (window.glpi_toast_error) { window.glpi_toast_error(msg); }
            }
        }).fail(function(){
            revertMove(card, fromBody);
            if (window.glpi_toast_error) { window.glpi_toast_error('Network error'); }
        });
    }

    document.addEventListener('drop', function(e){
        var body = e.target.closest && e.target.closest('.sprint-kanban-body');
        if (!body) { return; }
        e.preventDefault();
        body.classList.remove('sk-drag-over');
        var id = null;
        try { id = e.dataTransfer.getData('text/plain'); } catch (ex) {}
        if (!id) { return; }
        var card = document.querySelector('.sprint-kanban-card[data-item-id="' + id + '"]');
        if (!card) { return; }
        var fromBody = card.parentNode;
        var newStatus = body.getAttribute('data-status');
        if (fromBody === body) { return; }

        // Optimistic move.
        body.appendChild(card);
        card.setAttribute('data-status', newStatus);
        recount();

        persistStatus(id, newStatus, card, fromBody, false);
    });

    // "Continue anyway": retry the move with the confirmation flag set.
    jQuery(document).on('click', '#sprint-linkedopen-modal .sprint-lo-continue', function(){
        var modalEl = document.getElementById('sprint-linkedopen-modal');
        pendingMoveConfirmed = true;
        var inst = modalEl ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
        if (inst) { inst.hide(); }
        if (pendingMove) {
            var mv = pendingMove;
            pendingMove = null;
            persistStatus(mv.id, mv.newStatus, mv.card, mv.fromBody, true, mv.doneList);
        }
    });

    // DoD confirmed: retry the move carrying the checked DoD values.
    jQuery(document).on('click', '#sprint-dod-modal .sprint-dod-go', function(){
        var dodEl = document.getElementById('sprint-dod-modal');
        pendingMoveConfirmed = true;
        var done = [];
        dodEl.querySelectorAll('.sprint-dod-check:checked').forEach(function(cb){ done.push(cb.value); });
        bootstrap.Modal.getOrCreateInstance(dodEl).hide();
        if (pendingMove) {
            var mv = pendingMove;
            pendingMove = null;
            persistStatus(mv.id, mv.newStatus, mv.card, mv.fromBody, false, done);
        }
    });

    // Dismissed without confirming (Cancel, X, backdrop): undo the move.
    jQuery(document).on('hidden.bs.modal', '#sprint-linkedopen-modal, #sprint-dod-modal', function(){
        if (pendingMoveConfirmed) { pendingMoveConfirmed = false; return; }
        if (pendingMove) {
            revertMove(pendingMove.card, pendingMove.fromBody);
            pendingMove = null;
        }
    });
})();
</script>
JS;
    }
}
