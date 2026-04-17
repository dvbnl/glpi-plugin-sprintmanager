/**
 * Sprint Plugin - JavaScript
 */

(function() {
    'use strict';

    /**
     * Mobile responsiveness helper.
     *
     * GLPI's `tab_cadre_fixe` tables don't reflow on narrow viewports —
     * they overflow horizontally and the page crops awkwardly. This
     * helper wraps every plugin table in a `.sprint-table-scroll` div
     * so the table can scroll independently from the page.
     *
     * Runs on initial load AND after AJAX tab loads (so SprintMember,
     * SprintItem, SprintMeeting, etc. tabs that are loaded via
     * common.tabs.php also get wrapped).
     */
    function isSprintPluginContext() {
        // Direct sprint pages
        if (window.location.pathname.indexOf('/plugins/sprint/') !== -1) {
            return true;
        }
        // Sprint tabs rendered into Ticket/Change/ProjectTask pages —
        // detect by the presence of any plugin-specific marker class.
        return document.querySelector(
            '.sprint-dashboard, .sprint-stats-row, .sprint-board, '
            + '.sprint-backlog-filter, [class^="sprint-status-"]'
        ) !== null;
    }

    function wrapSprintTables(root) {
        if (!isSprintPluginContext()) {
            return;
        }
        var scope = root || document;
        var tables = scope.querySelectorAll(
            'table.tab_cadre_fixe:not(.sprint-table-wrapped)'
        );
        for (var i = 0; i < tables.length; i++) {
            var table = tables[i];
            // Skip tables that are themselves nested inside another
            // tab_cadre_fixe (some forms re-open/close the table mid-render).
            if (table.parentElement
                && table.parentElement.classList
                && table.parentElement.classList.contains('sprint-table-scroll')) {
                table.classList.add('sprint-table-wrapped');
                continue;
            }
            var wrapper = document.createElement('div');
            wrapper.className = 'sprint-table-scroll';
            table.parentNode.insertBefore(wrapper, table);
            wrapper.appendChild(table);
            table.classList.add('sprint-table-wrapped');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { wrapSprintTables(); });
    } else {
        wrapSprintTables();
    }

    // Re-scan after every AJAX completion so tab content loaded via
    // /ajax/common.tabs.php also gets wrapped. jQuery is always present
    // in GLPI so this is safe.
    if (typeof $ !== 'undefined' && $.fn) {
        $(document).ajaxComplete(function() {
            wrapSprintTables();
        });
    }


    /**
     * Quick status update for sprint items via AJAX
     *
     * @param {number} itemId  - The sprint item ID
     * @param {string} status  - New status value
     */
    window.sprintUpdateItemStatus = function(itemId, status) {
        var pluginRoot = CFG_GLPI.root_doc + '/plugins/sprint/ajax/updatestatus.php';

        $.ajax({
            url: pluginRoot,
            type: 'POST',
            dataType: 'json',
            data: {
                id: itemId,
                status: status,
                _glpi_csrf_token: getAjaxCsrfToken()
            },
            success: function(response) {
                if (response.success) {
                    // Reload the current tab to reflect changes
                    if (typeof reloadTab === 'function') {
                        reloadTab('', '');
                    } else {
                        location.reload();
                    }
                } else {
                    alert(response.message || 'Failed to update status');
                }
            },
            error: function() {
                alert('Network error while updating status');
            }
        });
    };

    /**
     * Toggle item type dropdown visibility in the Sprint Item add form
     *
     * @param {string} itemtype - Selected item type ('' | 'Ticket' | 'Change' | 'ProjectTask')
     * @param {string} rand     - Random suffix for element IDs
     */
    window.sprintToggleItemType = function(itemtype, rand) {
        var types = ['Ticket', 'Change', 'ProjectTask'];
        var manualEl = document.getElementById('sprint_manual_name_' + rand);
        var labelEl  = document.getElementById('sprint_label_' + rand);
        var isManual = (itemtype === '' || !itemtype);

        // Toggle manual name input vs GLPI dropdowns
        if (manualEl) {
            manualEl.style.display = isManual ? '' : 'none';
        }

        types.forEach(function(type) {
            var el = document.getElementById('sprint_item_' + type + '_' + rand);
            if (el) {
                el.style.display = (type === itemtype) ? '' : 'none';
            }
        });

        // Update the label
        if (labelEl) {
            var labels = {
                '': 'Name',
                'Ticket': 'Ticket',
                'Change': 'Change',
                'ProjectTask': 'Project task'
            };
            labelEl.textContent = labels[itemtype] || 'Name';
        }
    };

    /**
     * Load sprint dashboard statistics
     *
     * @param {number} sprintId - The sprint ID
     * @param {string} targetSelector - CSS selector for the target container
     */
    window.sprintLoadDashboard = function(sprintId, targetSelector) {
        var pluginRoot = CFG_GLPI.root_doc + '/plugins/sprint/ajax/sprintstats.php';

        $.ajax({
            url: pluginRoot,
            type: 'GET',
            dataType: 'json',
            data: { sprint_id: sprintId },
            success: function(response) {
                if (response.success && response.data) {
                    sprintRenderDashboard(response.data, targetSelector);
                }
            }
        });
    };

    /**
     * Render a simple sprint dashboard with progress bar
     *
     * @param {Object} stats - Sprint statistics object
     * @param {string} targetSelector - CSS selector
     */
    function sprintRenderDashboard(stats, targetSelector) {
        var container = document.querySelector(targetSelector);
        if (!container) return;

        var total = stats.total_items || 1;
        var donePercent       = ((stats.done_items / total) * 100).toFixed(1);
        var progressPercent   = ((stats.in_progress / total) * 100).toFixed(1);
        var blockedPercent    = ((stats.blocked_items / total) * 100).toFixed(1);
        var todoPercent       = ((stats.todo_items / total) * 100).toFixed(1);

        var html = '<div class="sprint-dashboard">';
        html += '<h4>Sprint Progress</h4>';

        // Progress bar
        html += '<div class="sprint-progress-bar">';
        html += '<div class="bar-segment bar-done" style="width:' + donePercent + '%"></div>';
        html += '<div class="bar-segment bar-in-progress" style="width:' + progressPercent + '%"></div>';
        html += '<div class="bar-segment bar-blocked" style="width:' + blockedPercent + '%"></div>';
        html += '<div class="bar-segment bar-todo" style="width:' + todoPercent + '%"></div>';
        html += '</div>';

        // Stats summary
        html += '<div class="sprint-stats-summary" style="display:flex;gap:20px;margin-top:10px;">';
        html += '<div><strong>' + stats.total_items + '</strong> items</div>';
        html += '<div style="color:#198754"><strong>' + stats.done_items + '</strong> done</div>';
        html += '<div style="color:#0d6efd"><strong>' + stats.in_progress + '</strong> in progress</div>';
        html += '<div style="color:#dc3545"><strong>' + stats.blocked_items + '</strong> blocked</div>';
        html += '<div><strong>' + stats.done_points + '/' + stats.total_points + '</strong> story points</div>';
        html += '</div>';

        // Linked items
        html += '<div style="margin-top:10px;font-size:0.9em;color:#6c757d;">';
        html += stats.tickets + ' tickets, ';
        html += stats.changes + ' changes, ';
        html += stats.project_tasks + ' project tasks, ';
        html += stats.meetings + ' meetings';
        html += '</div>';

        html += '</div>';

        container.innerHTML = html;
    }

    /**
     * Get CSRF token for AJAX requests
     */
    function getAjaxCsrfToken() {
        // GLPI stores the token in a meta tag or hidden field
        var tokenField = document.querySelector('input[name="_glpi_csrf_token"]');
        if (tokenField) {
            return tokenField.value;
        }
        // Fallback: check meta tag
        var meta = document.querySelector('meta[name="glpi_csrf_token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /**
     * Load sprint template data and pre-fill form fields
     *
     * @param {number} templateId - The template ID
     */
    window.sprintLoadTemplate = function(templateId) {
        if (!templateId || templateId == 0) {
            return;
        }

        var pluginRoot = CFG_GLPI.root_doc + '/plugins/sprint/ajax/gettemplate.php';

        $.ajax({
            url: pluginRoot,
            type: 'POST',
            dataType: 'json',
            data: {
                id: templateId,
                _glpi_csrf_token: getAjaxCsrfToken()
            },
            success: function(response) {
                if (response.success && response.data) {
                    var data = response.data;

                    // Pre-fill name from pattern
                    if (data.name_pattern) {
                        var nameField = document.querySelector('input[name="name"]');
                        if (nameField && !nameField.value) {
                            nameField.value = data.name_pattern;
                        }
                    }

                    // Pre-fill goal
                    if (data.goal) {
                        var goalField = document.querySelector('textarea[name="goal"]');
                        if (goalField && !goalField.value) {
                            goalField.value = data.goal;
                        }
                    }

                    // Pre-fill duration_weeks dropdown
                    if (data.duration_weeks) {
                        var durationSelect = document.querySelector('select[name="duration_weeks"]');
                        if (durationSelect) {
                            durationSelect.value = data.duration_weeks;
                            // Trigger change event for GLPI dropdowns
                            $(durationSelect).trigger('change');
                        }
                    }
                }
            }
        });
    };

})();

/**
 * ================================================================
 * Sprint filter + sort (v1.0.7+ — CSP-compliant)
 * ================================================================
 *
 * Markup contract:
 *
 *     <div class="sprint-filter-bar" data-target="<table-class>">
 *       <input class="sf-text">
 *       <select class="sf-status">
 *       <select class="sf-owner">
 *       <button class="sf-reset" data-sprint-action="filter-reset">Wissen</button>
 *     </div>
 *     <table class="... <table-class>">
 *       <tr class="sprint-filterable-row" data-item-name data-item-status data-users-id ...>
 *     </table>
 *
 *     <th class="sprint-sortable" data-sort-type="name" data-sprint-action="sort">...</th>
 *
 * For the audit tab: same bar class, but contains `.sprint-audit-kind`
 * instead of `.sf-status/.sf-owner`, and filters `tr.sprint-audit-row`
 * on `data-search` + `data-area`.
 *
 * All wiring is done via capture-phase delegated listeners on `document`
 * — there are NO inline `onclick`/`oninput` attributes. That keeps
 * filter + sort functional under a Content-Security-Policy that
 * forbids inline script (`script-src 'self'`, no `'unsafe-inline'`).
 *
 * The target table is located by walking the DOM forward from the bar
 * until a <table> containing `tr.sprint-filterable-row` is found. That
 * makes the filter robust against class-name mismatches and any outer
 * wrapping GLPI may add.
 */
(function() {
    'use strict';

    // ----- Helpers ---------------------------------------------------

    function resolveBar(arg) {
        if (!arg) { return null; }
        if (typeof arg === 'string') { return document.getElementById(arg); }
        if (arg.nodeType === 1) {
            if (arg.classList && arg.classList.contains('sprint-filter-bar')) { return arg; }
            if (arg.closest) { return arg.closest('.sprint-filter-bar'); }
        }
        return null;
    }

    function findTableForBar(bar) {
        if (!bar) { return null; }
        // Walk the DOM tree from the bar forward/up looking for the first
        // <table> that actually contains `tr.sprint-filterable-row`.
        //
        // Global class lookup via `document.querySelector('table.<class>')`
        // is NOT reliable here: GLPI's tab machinery can leave stale copies
        // of tab HTML in the DOM when switching tabs, leaving two tables
        // with identical class lists where only one holds the live rows.
        // Walking from the bar's own subtree is deterministic.
        var walker = bar;
        while (walker) {
            var sib = walker.nextElementSibling;
            while (sib) {
                if (sib.tagName === 'TABLE' && sib.querySelector('tr.sprint-filterable-row')) {
                    return sib;
                }
                var nested = sib.querySelector && sib.querySelector('table tr.sprint-filterable-row');
                if (nested) { return nested.closest('table'); }
                sib = sib.nextElementSibling;
            }
            walker = walker.parentElement;
        }
        return null;
    }

    function applyFilter(bar) {
        bar = resolveBar(bar);
        if (!bar) { return; }
        // Audit-tab bars carry a `.sprint-audit-kind` dropdown and filter
        // rows with class `.sprint-audit-row` on different attributes.
        if (bar.querySelector('.sprint-audit-kind')) {
            applyAuditFilter(bar);
            return;
        }
        var table = findTableForBar(bar);
        if (!table) { return; }

        var textEl   = bar.querySelector('.sf-text');
        var statusEl = bar.querySelector('.sf-status');
        var ownerEl  = bar.querySelector('.sf-owner');
        var text   = textEl   ? (textEl.value   || '').toLowerCase().trim() : '';
        var status = statusEl ? (statusEl.value || '').toString()           : '';
        var owner  = ownerEl  ? (ownerEl.value  || '').toString()           : '';

        var rows = table.querySelectorAll('tr.sprint-filterable-row');
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var show = true;
            if (text) {
                var name = (row.getAttribute('data-item-name') || '').toLowerCase();
                if (name.indexOf(text) === -1) { show = false; }
            }
            if (show && status) {
                if (String(row.getAttribute('data-item-status') || '') !== status) { show = false; }
            }
            if (show && owner) {
                if (String(row.getAttribute('data-users-id') || '') !== owner) { show = false; }
            }
            // Some GLPI table row utility classes force `display: table-row`
            // with higher CSS priority than a plain inline style update.
            // Toggle a dedicated hidden class instead so filtering works on
            // dashboard/items tables and the meeting review alike.
            row.classList.toggle('sprint-row-hidden', !show);
            row.setAttribute('aria-hidden', show ? 'false' : 'true');
            if (show) {
                row.style.removeProperty('display');
            } else {
                row.style.setProperty('display', 'none', 'important');
            }
        }
    }

    function applyAuditFilter(bar) {
        var textEl = bar.querySelector('.sf-text');
        var kindEl = bar.querySelector('.sprint-audit-kind');
        var text = textEl ? (textEl.value || '').toLowerCase().trim() : '';
        var kind = kindEl ? (kindEl.value || '').toString()           : '';

        var rows = document.querySelectorAll('tr.sprint-audit-row');
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var show = true;
            if (text) {
                var hay = (r.getAttribute('data-search') || '');
                if (hay.indexOf(text) === -1) { show = false; }
            }
            if (show && kind) {
                if (String(r.getAttribute('data-area') || '') !== kind) { show = false; }
            }
            r.style.display = show ? '' : 'none';
        }
    }

    function resetFilter(bar) {
        bar = resolveBar(bar);
        if (!bar) { return; }
        var inputs = bar.querySelectorAll('.sf-text, .sf-status, .sf-owner, .sprint-audit-kind');
        for (var i = 0; i < inputs.length; i++) { inputs[i].value = ''; }
        applyFilter(bar);
    }

    // ----- Global functions (inline onclick/onkeydown) ---------------

    window.sprintFilterApply = function(arg) { applyFilter(arg); };
    window.sprintFilterReset = function(arg) { resetFilter(arg); };

    // ----- Sort ------------------------------------------------------

    var sortState = {};

    function rowSortValue(row, type) {
        switch (type) {
            case 'name':         return (row.getAttribute('data-item-name')         || '').toLowerCase();
            case 'status':       return (row.getAttribute('data-item-status-label') || '').toLowerCase();
            case 'owner':        return (row.getAttribute('data-owner-name')        || '').toLowerCase();
            case 'type':         return (row.getAttribute('data-item-type-label')   || '').toLowerCase();
            case 'priority':     return parseInt(row.getAttribute('data-item-priority'),  10) || 0;
            case 'capacity':     return parseInt(row.getAttribute('data-capacity'),      10) || 0;
            case 'story_points': return parseInt(row.getAttribute('data-story-points'),  10) || 0;
            default: return '';
        }
    }

    window.sprintSortClick = function(th) {
        if (!th || !th.closest) { return; }
        var table = th.closest('table');
        if (!table) { return; }
        var type = th.getAttribute('data-sort-type');
        var key = (table.className || '') + ':' + type;
        var dir = sortState[key] === 'asc' ? 'desc' : 'asc';
        sortState[key] = dir;

        var icons = table.querySelectorAll('.sprint-sortable i');
        for (var j = 0; j < icons.length; j++) { icons[j].className = 'fas fa-sort text-muted'; }
        var icon = th.querySelector('i');
        if (icon) { icon.className = dir === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down'; }

        var rows = Array.prototype.slice.call(table.querySelectorAll('tr.sprint-filterable-row'));
        rows.sort(function(a, b) {
            var av = rowSortValue(a, type);
            var bv = rowSortValue(b, type);
            if (av < bv) { return dir === 'asc' ? -1 : 1; }
            if (av > bv) { return dir === 'asc' ? 1 : -1; }
            return 0;
        });
        var parent = rows[0] ? rows[0].parentNode : null;
        if (!parent) { return; }
        for (var k = 0; k < rows.length; k++) { parent.appendChild(rows[k]); }
    };

    // ----- Three redundant wiring layers -----------------------------
    //
    // 1. Capture-phase document listeners — always installed. Capture
    //    phase fires before any descendant handler can stopPropagation,
    //    and works on any element added later to the DOM.
    // 2. jQuery bubbling-phase delegation — covers any case where we
    //    got installed before jQuery or where some intermediate element
    //    reroutes the event. Harmless if layer 1 already fired (the
    //    filter is idempotent).
    // 3. Direct binding via MutationObserver — for every new filter
    //    bar found in the DOM, attach `change`/`input` listeners
    //    straight to its select/input elements. Marked with a
    //    data-attr so we don't double-bind.
    //
    // Any of the three is enough. All three combined guarantees the
    // filter responds regardless of how a particular tab or page is
    // rendered.

    function onSelectChange(el) {
        applyFilter(el);
    }

    // --- Layer 1: capture-phase document listeners ---
    //
    // Pure CSP-compliant delegation — no dependency on inline `onclick`
    // attributes. GLPI installations running a `script-src` policy
    // without `'unsafe-inline'` will block inline handlers; this layer
    // keeps filtering + sorting working there.
    //
    // All markup emitted by this plugin (v1.0.7+) uses data-sprint-action
    // attributes instead of inline handlers. Class-based matching stays
    // as a fallback for any legacy tab HTML still lingering in the DOM.

    function matchAction(el, action) {
        if (!el || !el.closest) { return null; }
        var byAttr = el.closest('[data-sprint-action="' + action + '"]');
        if (byAttr) { return byAttr; }
        if (action === 'sort')         { return el.closest('.sprint-sortable'); }
        if (action === 'filter-reset') { return el.closest('.sf-reset'); }
        if (action === 'filter-apply') { return el.closest('.sf-apply'); }
        return null;
    }

    // Sort is NOT idempotent (each call toggles direction). We mark the
    // event so that if both this listener and a legacy inline onclick
    // somehow fire, only the first toggles. Filter apply/reset ARE
    // idempotent, so they don't need the mark.
    function claimEvent(ev) {
        if (ev.__sprintHandled) { return false; }
        try { ev.__sprintHandled = true; } catch (_) {}
        return true;
    }

    document.addEventListener('change', function(ev) {
        var t = ev.target;
        if (!t || !t.classList) { return; }
        if (t.classList.contains('sf-status') || t.classList.contains('sf-owner') || t.classList.contains('sprint-audit-kind')) {
            onSelectChange(t);
        }
    }, true);
    document.addEventListener('input', function(ev) {
        var t = ev.target;
        if (t && t.classList && t.classList.contains('sf-text')) {
            applyFilter(t);
        }
    }, true);
    document.addEventListener('keydown', function(ev) {
        var t = ev.target;
        if (t && t.classList && t.classList.contains('sf-text') && (ev.key === 'Enter' || ev.keyCode === 13)) {
            ev.preventDefault();
            applyFilter(t);
        }
    }, true);
    document.addEventListener('click', function(ev) {
        var t = ev.target;
        if (!t) { return; }

        var sortEl = matchAction(t, 'sort');
        if (sortEl) {
            if (claimEvent(ev)) { window.sprintSortClick(sortEl); }
            return;
        }
        var resetEl = matchAction(t, 'filter-reset');
        if (resetEl) { resetFilter(resetEl); return; }
        var applyEl = matchAction(t, 'filter-apply');
        if (applyEl) { applyFilter(applyEl); return; }
    }, true);

    // --- Layer 2: jQuery bubbling delegation ---
    if (typeof window.jQuery === 'function') {
        window.jQuery(function($) {
            $(document).off('.sprintFilter')
                .on('change.sprintFilter', '.sprint-filter-bar .sf-status, .sprint-filter-bar .sf-owner', function() {
                    applyFilter(this);
                })
                .on('input.sprintFilter', '.sprint-filter-bar .sf-text', function() {
                    applyFilter(this);
                });
        });
    }

    // --- Layer 3: per-bar direct binding via MutationObserver ---
    function wireBarDirect(bar) {
        if (!bar || bar.dataset.sprintFilterWired === '1') { return; }
        bar.dataset.sprintFilterWired = '1';

        var selects = bar.querySelectorAll('.sf-status, .sf-owner, .sprint-audit-kind');
        for (var i = 0; i < selects.length; i++) {
            selects[i].addEventListener('change', function() { applyFilter(this); });
        }
        var textEl = bar.querySelector('.sf-text');
        if (textEl) {
            textEl.addEventListener('input', function() { applyFilter(this); });
        }
    }

    function scanAndWire() {
        var bars = document.querySelectorAll('.sprint-filter-bar');
        for (var i = 0; i < bars.length; i++) { wireBarDirect(bars[i]); }
    }

    function bootBarWiring() {
        scanAndWire();
        if (typeof MutationObserver === 'function') {
            var pending = null;
            var obs = new MutationObserver(function() {
                if (pending) { return; }
                pending = setTimeout(function() {
                    pending = null;
                    scanAndWire();
                }, 30);
            });
            obs.observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootBarWiring);
    } else {
        bootBarWiring();
    }
})();
