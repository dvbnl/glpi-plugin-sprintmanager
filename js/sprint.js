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
            '.sprint-dashboard, .sprint-stat-cards, .sprint-board, '
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

    function findTablesForBar(bar) {
        if (!bar) { return []; }
        // Walk the DOM tree from the bar forward/up looking for the <table>s
        // that contain `tr.sprint-filterable-row`; every match at the first
        // level that yields any is kept, as the backlog splits its rows over
        // one table per category bucket.
        //
        // Global class lookup via `document.querySelector('table.<class>')`
        // is NOT reliable here: GLPI's tab machinery can leave stale copies
        // of tab HTML in the DOM when switching tabs, leaving two tables
        // with identical class lists where only one holds the live rows.
        // Walking from the bar's own subtree is deterministic.
        var found  = [];
        var walker = bar;
        while (walker && found.length === 0) {
            var sib = walker.nextElementSibling;
            while (sib) {
                if (sib.tagName === 'TABLE') {
                    if (sib.querySelector('tr.sprint-filterable-row')) { found.push(sib); }
                } else if (sib.querySelectorAll) {
                    var nested = sib.querySelectorAll('table');
                    for (var n = 0; n < nested.length; n++) {
                        if (nested[n].querySelector('tr.sprint-filterable-row')) { found.push(nested[n]); }
                    }
                }
                sib = sib.nextElementSibling;
            }
            walker = walker.parentElement;
        }
        return found;
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
        var tables = findTablesForBar(bar);
        if (!tables.length) { return; }

        var textEl   = bar.querySelector('.sf-text');
        var statusEl = bar.querySelector('.sf-status');
        var ownerEl  = bar.querySelector('.sf-owner');
        var typeEl   = bar.querySelector('.sf-type');
        var tagEl    = bar.querySelector('.sf-tag');
        var sprintEl = bar.querySelector('.sf-sprint');
        var text   = textEl   ? (textEl.value   || '').toLowerCase().trim() : '';
        var status = statusEl ? (statusEl.value || '').toString()           : '';
        var owner  = ownerEl  ? (ownerEl.value  || '').toString()           : '';
        var type   = typeEl   ? (typeEl.value   || '').toString()           : '';
        var tag    = tagEl    ? (tagEl.value    || '').toLowerCase()        : '';
        var sprint = sprintEl ? (sprintEl.value || '').toString()           : '';

        var rows = [];
        for (var t = 0; t < tables.length; t++) {
            var found = tables[t].querySelectorAll('tr.sprint-filterable-row');
            for (var f = 0; f < found.length; f++) { rows.push(found[f]); }
        }
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var show = true;
            if (text) {
                var name = (row.getAttribute('data-item-name') || '').toLowerCase();
                if (name.indexOf(text) === -1) { show = false; }
            }
            if (show && status) {
                var rowStatus = String(row.getAttribute('data-item-status') || '');
                if (status === '__not_done__') {
                    if (rowStatus === 'done') { show = false; }
                } else if (rowStatus !== status) {
                    show = false;
                }
            }
            if (show && owner) {
                var rowOwner = String(row.getAttribute('data-users-id') || '0');
                if (owner === '__unassigned__') {
                    if (rowOwner !== '0' && rowOwner !== '') { show = false; }
                } else if (rowOwner !== owner) {
                    show = false;
                }
            }
            if (show && type) {
                if (String(row.getAttribute('data-item-type') || '') !== type) { show = false; }
            }
            if (show && tag) {
                var tagBlob = String(row.getAttribute('data-item-tags') || '');
                if (tagBlob.indexOf('|' + tag + '|') === -1) { show = false; }
            }
            if (show && sprint) {
                var rowSprint = String(row.getAttribute('data-proposed-sprint-id') || '0');
                if (sprint === '__none__') {
                    if (rowSprint !== '0' && rowSprint !== '') { show = false; }
                } else if (rowSprint !== sprint) {
                    show = false;
                }
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

        // Backlog buckets keep their own header counts; let them resync.
        if (typeof window.sprintBacklogRefreshSections === 'function') {
            window.sprintBacklogRefreshSections();
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
        var inputs = bar.querySelectorAll('.sf-text, .sf-status, .sf-owner, .sf-type, .sf-tag, .sf-sprint, .sprint-audit-kind');
        for (var i = 0; i < inputs.length; i++) { inputs[i].value = ''; }
        applyFilter(bar);
    }

    // ----- Global functions (inline onclick/onkeydown) ---------------


    // ----- Sort ------------------------------------------------------

    var sortState = {};

    function rowSortValue(row, type) {
        switch (type) {
            case 'name':         return (row.getAttribute('data-item-name')         || '').toLowerCase();
            case 'status':       return (row.getAttribute('data-item-status-label') || '').toLowerCase();
            case 'owner':        return (row.getAttribute('data-owner-name')        || '').toLowerCase();
            case 'type':         return (row.getAttribute('data-item-type-label')   || '').toLowerCase();
            case 'priority':     return parseInt(row.getAttribute('data-item-priority'),  10) || 0;
            case 'capacity':     return parseFloat(row.getAttribute('data-capacity')) || 0;
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
        if (t.classList.contains('sf-status') || t.classList.contains('sf-owner') || t.classList.contains('sf-type') || t.classList.contains('sf-tag') || t.classList.contains('sf-sprint') || t.classList.contains('sprint-audit-kind')) {
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

        // Capture-phase so Cancel aborts the click before any form handler runs.
        var confirmEl = t.closest && t.closest('[data-sprint-confirm]');
        if (confirmEl) {
            var msg = confirmEl.getAttribute('data-sprint-confirm') || '';
            if (msg && !window.confirm(msg)) {
                ev.preventDefault();
                ev.stopPropagation();
                return;
            }
        }

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
                .on('change.sprintFilter', '.sprint-filter-bar .sf-status, .sprint-filter-bar .sf-owner, .sprint-filter-bar .sf-type, .sprint-filter-bar .sf-tag, .sprint-filter-bar .sf-sprint', function() {
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

        var selects = bar.querySelectorAll('.sf-status, .sf-owner, .sf-type, .sf-tag, .sf-sprint, .sprint-audit-kind');
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
                    initCollapsibles();
                }, 30);
            });
            obs.observe(document.body, { childList: true, subtree: true });
        }
    }

    // === Collapsible sections ========================================
    // Toggle handler + initial state restore for .sprint-collapsible
    // wrappers (dashboard fastlane + regular items blocks). State is
    // stored per data-sprint-collapse-key in localStorage so the user's
    // preference survives a page reload.
    function initCollapsibles(root) {
        var wraps = (root || document).querySelectorAll(
            '.sprint-collapsible[data-sprint-collapse-key]:not([data-sprint-collapse-wired])'
        );
        for (var i = 0; i < wraps.length; i++) {
            var w = wraps[i];
            w.setAttribute('data-sprint-collapse-wired', '1');
            var key = w.getAttribute('data-sprint-collapse-key');
            try {
                var stored = localStorage.getItem('sprint-collapse-' + key);
                if (stored === '1') {
                    w.classList.add('sprint-collapsed');
                } else if (stored === '0') {
                    // Explicitly expanded — overrides a server-rendered default collapse.
                    w.classList.remove('sprint-collapsed');
                }
            } catch (e) { /* localStorage unavailable — ignore */ }
        }
    }

    document.addEventListener('click', function(ev) {
        var t = ev.target;
        if (!t || !t.closest) { return; }

        // Member-activity legend — click to isolate that member's line.
        // Clicking the already-isolated member clears the selection.
        var legend = t.closest('.sprint-activity-legend');
        if (legend) {
            var chart = legend.closest('.sprint-member-activity');
            if (chart) {
                var idx = legend.getAttribute('data-member-idx');
                var current = chart.getAttribute('data-active-idx');
                if (current === idx) {
                    chart.removeAttribute('data-active-idx');
                    applyActivityFocus(chart, null);
                } else {
                    chart.setAttribute('data-active-idx', idx);
                    // Clear any hover-state so the click wins cleanly.
                    chart.removeAttribute('data-hover-idx');
                    applyActivityFocus(chart, idx);
                }
                ev.stopPropagation(); // don't bubble into collapsible-header
                return;
            }
        }

        var header = t.closest('.sprint-collapsible-header');
        if (!header) { return; }
        var wrap = header.closest('.sprint-collapsible');
        if (!wrap) { return; }
        var collapsed = wrap.classList.toggle('sprint-collapsed');
        var key = wrap.getAttribute('data-sprint-collapse-key');
        if (key) {
            try {
                localStorage.setItem('sprint-collapse-' + key, collapsed ? '1' : '0');
            } catch (e) { /* ignore */ }
        }
    }, false);

    // Hover preview on member-activity legend entries. Skipped when a
    // member is already click-isolated so the pinned selection stays
    // visible while the mouse wanders.
    function onActivityLegendEnter(ev) {
        var legend = ev.target.closest ? ev.target.closest('.sprint-activity-legend') : null;
        if (!legend) { return; }
        var chart = legend.closest('.sprint-member-activity');
        if (!chart) { return; }
        if (chart.hasAttribute('data-active-idx')) { return; }
        var idx = legend.getAttribute('data-member-idx');
        chart.setAttribute('data-hover-idx', idx);
        applyActivityFocus(chart, idx);
    }
    function onActivityLegendLeave(ev) {
        var legend = ev.target.closest ? ev.target.closest('.sprint-activity-legend') : null;
        if (!legend) { return; }
        var chart = legend.closest('.sprint-member-activity');
        if (!chart) { return; }
        if (chart.hasAttribute('data-active-idx')) { return; }
        chart.removeAttribute('data-hover-idx');
        applyActivityFocus(chart, null);
    }
    // Toggle .is-focus on the polyline, dots, and legend entry whose
    // data-member-idx matches. CSS handles the fade of the rest via the
    // parent's data-active-idx / data-hover-idx attribute.
    function applyActivityFocus(chart, idx) {
        var nodes = chart.querySelectorAll('.sprint-activity-line, .sprint-activity-dot, .sprint-activity-legend');
        for (var i = 0; i < nodes.length; i++) {
            var n = nodes[i];
            if (idx !== null && n.getAttribute('data-member-idx') === idx) {
                n.classList.add('is-focus');
            } else {
                n.classList.remove('is-focus');
            }
        }
    }
    document.addEventListener('mouseover', onActivityLegendEnter, false);
    document.addEventListener('mouseout', onActivityLegendLeave, false);

    function refreshActivityChart(sprintId, fromVal, toVal) {
        sprintId = parseInt(sprintId, 10) || 0;
        if (!sprintId) { return; }
        var wrap = document.querySelector('.sprint-activity-chart-wrap[data-sprint-id="' + sprintId + '"]');
        if (!wrap) { return; }
        var url = (window.CFG_GLPI && window.CFG_GLPI.root_doc ? window.CFG_GLPI.root_doc : '')
            + '/plugins/sprint/ajax/activitychart.php?sprint_id=' + encodeURIComponent(sprintId)
            + '&activity_from=' + encodeURIComponent(fromVal || '')
            + '&activity_to='   + encodeURIComponent(toVal   || '');
        wrap.style.opacity = '0.5';
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.onload = function() {
            wrap.style.opacity = '';
            if (xhr.status >= 200 && xhr.status < 300) {
                wrap.innerHTML = xhr.responseText;
            }
        };
        xhr.onerror = function() { wrap.style.opacity = ''; };
        xhr.send();
    }
    document.addEventListener('submit', function(ev) {
        var form = ev.target;
        if (!form || !form.classList || !form.classList.contains('sprint-activity-range')) { return; }
        ev.preventDefault();
        refreshActivityChart(
            form.getAttribute('data-sprint-id'),
            (form.querySelector('input[name="activity_from"]') || {}).value,
            (form.querySelector('input[name="activity_to"]')   || {}).value
        );
    }, true);
    document.addEventListener('click', function(ev) {
        var btn = ev.target && ev.target.closest && ev.target.closest('[data-sprint-action="activity-reset"]');
        if (!btn) { return; }
        ev.preventDefault();
        refreshActivityChart(btn.getAttribute('data-sprint-id'), '', '');
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            bootBarWiring();
            initCollapsibles();
        });
    } else {
        bootBarWiring();
        initCollapsibles();
    }

    // ---- Backlog: AJAX assign-to-sprint ----
    //
    // Hijacks the per-row "Toewijzen" form so the backlog page no longer
    // does a full reload on every assignment. On success the row is faded
    // out + removed; the visible badge / counter stays in sync because
    // the row no longer exists in the DOM. CSP-safe (delegated, no
    // inline handlers on the markup itself).
    function wireBacklogAssign() {
        var pluginRoot = (window.CFG_GLPI && window.CFG_GLPI.root_doc)
            ? window.CFG_GLPI.root_doc
            : '';
        var ajaxBase = pluginRoot + '/plugins/sprint/ajax/';

        document.addEventListener('submit', function(e) {
            var form = e.target;
            if (!form || !form.classList || !form.classList.contains('sprint-backlog-assign-form')) {
                return;
            }
            e.preventDefault();

            var $form = (typeof window.jQuery === 'function') ? window.jQuery(form) : null;
            var itemId = parseInt(form.getAttribute('data-item-id'), 10) || 0;
            // Compact rows carry the pre-selected sprint as a hidden input;
            // older markup used an inline select.
            var sel    = form.querySelector('select[name="plugin_sprint_sprints_id"], input[name="plugin_sprint_sprints_id"]');
            var sprintId = sel ? parseInt(sel.value, 10) || 0 : 0;
            if (itemId <= 0 || sprintId <= 0) {
                if (window.glpi_toast_warning) { window.glpi_toast_warning('Select a sprint first'); }
                return;
            }

            var btn = form.querySelector('.sprint-backlog-assign-btn');

            var doAssign = function(readyChecks) {
            if (btn) { btn.disabled = true; }

            window.jQuery.ajax({
                url: ajaxBase + 'csrftoken.php',
                type: 'GET', dataType: 'json', cache: false
            }).then(function(tokResp) {
                var data = {
                    id: itemId,
                    plugin_sprint_sprints_id: sprintId,
                    _glpi_csrf_token: tokResp && tokResp.token ? tokResp.token : ''
                };
                if (readyChecks && readyChecks.length) { data.ready = readyChecks; }
                return window.jQuery.ajax({
                    url: ajaxBase + 'assigntosprint.php',
                    type: 'POST', dataType: 'json',
                    data: data
                });
            }).done(function(resp) {
                if (resp && resp.success) {
                    if (window.glpi_toast_info) {
                        window.glpi_toast_info(resp.message || 'Assigned');
                    }
                    var row = form.closest('tr.sprint-backlog-row');
                    if (row) {
                        var rowCols = row.cells ? row.cells.length : 7;
                        row.style.transition = 'opacity 0.25s';
                        row.style.opacity = '0';
                        setTimeout(function() {
                            row.parentNode && row.parentNode.removeChild(row);
                            // If the parent <tbody>/table is now empty, drop
                            // a placeholder row so the table doesn't look
                            // visually broken until the next reload.
                            var table = row.closest('table');
                            if (table) {
                                var dataRows = table.querySelectorAll('tr.sprint-backlog-row');
                                if (dataRows.length === 0) {
                                    var emptyTr = table.querySelector('tr.sprint-backlog-empty');
                                    if (!emptyTr) {
                                        var trEmpty = document.createElement('tr');
                                        trEmpty.className = 'tab_bg_1 sprint-backlog-empty';
                                        var td = document.createElement('td');
                                        td.colSpan = rowCols;
                                        td.className = 'center';
                                        td.textContent = '—';
                                        trEmpty.appendChild(td);
                                        var tbody = table.querySelector('tbody') || table;
                                        tbody.appendChild(trEmpty);
                                    }
                                }
                            }
                        }, 260);
                    }
                } else {
                    if (window.glpi_toast_error) {
                        window.glpi_toast_error((resp && resp.message) || 'Assign failed');
                    } else {
                        alert((resp && resp.message) || 'Assign failed');
                    }
                    if (btn) { btn.disabled = false; }
                }
            }).fail(function() {
                if (window.glpi_toast_error) {
                    window.glpi_toast_error('Network error');
                } else {
                    alert('Network error');
                }
                if (btn) { btn.disabled = false; }
            });
            };

            // Definition of Ready confirmation dialog, when the page provides one.
            if (typeof window.sprintDorConfirm === 'function') {
                window.sprintDorConfirm(doAssign);
            } else {
                doAssign(null);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireBacklogAssign);
    } else {
        wireBacklogAssign();
    }
})();

/**
 * ================================================================
 * Guided meeting rail (review / retrospective)
 * ================================================================
 * Session state persists via ajax/meetingphase.php (resume after refresh);
 * improvement widgets post to front/sprintagility.form.php with _ajax=1 and
 * patch every matching [data-imp-list] so all phases stay in sync.
 */
(function() {
    'use strict';

    function pluginRoot() {
        return ((window.CFG_GLPI && window.CFG_GLPI.root_doc) ? window.CFG_GLPI.root_doc : '') + '/plugins/sprint/';
    }

    function rail() {
        return document.querySelector('.sprint-meeting-rail');
    }

    // Offset between the browser clock and the server clock, so the phase
    // timer survives refreshes and client clock skew.
    var clockOffset = 0;

    function serverNowSec() {
        return Math.floor(Date.now() / 1000) - clockOffset;
    }

    function recalcOffset(root) {
        var serverNow = parseInt(root.getAttribute('data-server-now'), 10) || 0;
        if (serverNow > 0) {
            clockOffset = Math.floor(Date.now() / 1000) - serverNow;
        }
    }

    function fetchToken() {
        return window.jQuery.ajax({
            url: pluginRoot() + 'ajax/csrftoken.php',
            type: 'GET', dataType: 'json', cache: false
        });
    }

    function phasePost(root, action, extra) {
        return fetchToken().then(function(tok) {
            var data = {
                action: action,
                meeting_id: parseInt(root.getAttribute('data-meeting-id'), 10) || 0,
                _glpi_csrf_token: tok && tok.token ? tok.token : ''
            };
            if (extra) {
                for (var k in extra) { data[k] = extra[k]; }
            }
            return window.jQuery.ajax({
                url: pluginRoot() + 'ajax/meetingphase.php',
                type: 'POST', dataType: 'json', data: data
            });
        });
    }

    function applySession(root, resp, scroll) {
        if (!resp || !resp.success) {
            if (window.glpi_toast_error) {
                window.glpi_toast_error((resp && resp.message) || 'Could not update the meeting');
            }
            return;
        }
        root.setAttribute('data-status', resp.status);
        root.setAttribute('data-current-phase', String(resp.current_phase));
        root.setAttribute('data-phase-started-at', String(resp.phase_started_at_ts || 0));
        root.setAttribute('data-server-now', String(resp.server_now_ts || 0));
        recalcOffset(root);
        syncUI(root);
        tick(root);
        if (scroll) {
            var card = root.querySelector('.sprint-phase-card[data-phase-index="' + resp.current_phase + '"]');
            if (card && card.scrollIntoView) {
                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    function syncUI(root) {
        var status  = root.getAttribute('data-status') || 'open';
        var phase   = parseInt(root.getAttribute('data-current-phase'), 10) || 0;
        var total   = parseInt(root.getAttribute('data-phase-count'), 10) || 0;
        var running = (status === 'in_progress');

        // Status badge
        var badge = root.querySelector('.sprint-meeting-status-badge');
        if (badge) {
            var lbl = badge.getAttribute('data-lbl-' + status.replace('_', '-')) || status;
            badge.textContent = lbl;
            badge.className = 'badge sprint-meeting-status-badge ' +
                (status === 'in_progress' ? 'bg-blue-lt' : (status === 'completed' ? 'bg-green-lt' : 'bg-secondary-lt'));
        }

        // Step + active phase title
        var step = root.querySelector('.sprint-meeting-step');
        if (step) { step.textContent = running ? ((phase + 1) + ' / ' + total) : ''; }
        var activeCard = root.querySelector('.sprint-phase-card[data-phase-index="' + phase + '"]');
        var titleEl = root.querySelector('.sprint-meeting-phase-title');
        if (titleEl) {
            var t = (running && activeCard) ? activeCard.querySelector('.sprint-phase-title') : null;
            titleEl.textContent = t ? ('— ' + t.textContent) : '';
        }

        // Nav buttons
        var btnStart = root.querySelector('.sprint-meeting-btn-start');
        if (btnStart) {
            btnStart.style.display = running ? 'none' : '';
            var startLbl = btnStart.querySelector('.sprint-meeting-btn-start-label');
            if (startLbl) {
                startLbl.textContent = (status === 'completed')
                    ? btnStart.getAttribute('data-lbl-restart')
                    : btnStart.getAttribute('data-lbl-start');
            }
        }
        var btnPrev = root.querySelector('.sprint-meeting-btn-prev');
        var btnNext = root.querySelector('.sprint-meeting-btn-next');
        var btnEnd  = root.querySelector('.sprint-meeting-btn-end');
        if (btnPrev) { btnPrev.style.display = running ? '' : 'none'; btnPrev.disabled = phase <= 0; }
        if (btnNext) { btnNext.style.display = running ? '' : 'none'; btnNext.disabled = phase >= total - 1; }
        if (btnEnd)  { btnEnd.style.display  = running ? '' : 'none'; }

        // Timer visibility
        var timer = root.querySelector('.sprint-meeting-timer');
        if (timer && !running) { timer.textContent = ''; }

        // Phase card states
        root.querySelectorAll('.sprint-phase-card').forEach(function(card) {
            var idx = parseInt(card.getAttribute('data-phase-index'), 10) || 0;
            card.classList.remove('is-active', 'is-done', 'is-upcoming');
            if (status === 'completed') {
                card.classList.add('is-done');
            } else if (running && idx === phase) {
                card.classList.add('is-active');
            } else if (running && idx < phase) {
                card.classList.add('is-done');
            } else {
                card.classList.add('is-upcoming');
            }
        });
    }

    function tick(root) {
        if ((root.getAttribute('data-status') || '') !== 'in_progress') { return; }
        var startedAt = parseInt(root.getAttribute('data-phase-started-at'), 10) || 0;
        var phase     = parseInt(root.getAttribute('data-current-phase'), 10) || 0;
        var card      = root.querySelector('.sprint-phase-card[data-phase-index="' + phase + '"]');
        var mins      = card ? (parseInt(card.getAttribute('data-minutes'), 10) || 0) : 0;
        var timer     = root.querySelector('.sprint-meeting-timer');
        if (!timer || startedAt <= 0) { return; }
        var s = Math.max(0, serverNowSec() - startedAt);
        timer.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2) + ' / ' + mins + ' min';
        timer.classList.toggle('text-danger', mins > 0 && s > mins * 60);
    }

    // ---- Phase navigation -------------------------------------------------

    function canDrive(root) {
        return root && root.getAttribute('data-can-drive') === '1';
    }

    document.addEventListener('click', function(e) {
        var el = e.target && e.target.closest
            ? e.target.closest('[data-sprint-action^="meeting-"]')
            : null;
        if (!el) { return; }
        var root = rail();
        if (!root) { return; }
        var action = el.getAttribute('data-sprint-action');

        if (action === 'meeting-start' && canDrive(root)) {
            el.disabled = true;
            phasePost(root, 'start_meeting').done(function(resp) {
                applySession(root, resp, true);
            }).always(function() { el.disabled = false; });

        } else if ((action === 'meeting-prev' || action === 'meeting-next') && canDrive(root)) {
            var phase = parseInt(root.getAttribute('data-current-phase'), 10) || 0;
            var total = parseInt(root.getAttribute('data-phase-count'), 10) || 0;
            var target = action === 'meeting-next'
                ? Math.min(total - 1, phase + 1)
                : Math.max(0, phase - 1);
            if (target === phase) { return; }
            el.disabled = true;
            phasePost(root, 'set_phase', { phase: target }).done(function(resp) {
                applySession(root, resp, true);
            }).always(function() { el.disabled = false; });

        } else if (action === 'meeting-goto' && canDrive(root)) {
            if ((root.getAttribute('data-status') || '') !== 'in_progress') { return; }
            var card = el.closest('.sprint-phase-card');
            if (!card) { return; }
            var idx = parseInt(card.getAttribute('data-phase-index'), 10) || 0;
            if (idx === (parseInt(root.getAttribute('data-current-phase'), 10) || 0)) { return; }
            phasePost(root, 'set_phase', { phase: idx }).done(function(resp) {
                applySession(root, resp, false);
            });

        } else if (action === 'meeting-end' && canDrive(root)) {
            var msg = el.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) { return; }
            el.disabled = true;
            phasePost(root, 'end_meeting').done(function(resp) {
                applySession(root, resp, false);
            }).always(function() { el.disabled = false; });
        }
    }, true);

    // ---- Per-phase notes autosave ----------------------------------------

    var noteTimers = {};

    document.addEventListener('input', function(e) {
        var ta = e.target;
        if (!ta || !ta.classList || !ta.classList.contains('sprint-phase-note')) { return; }
        var root = rail();
        if (!root || !canDrive(root)) { return; }
        var key = ta.getAttribute('data-phase-key') || '';
        if (noteTimers[key]) { clearTimeout(noteTimers[key]); }
        noteTimers[key] = setTimeout(function() {
            phasePost(root, 'save_phase_note', { phase_key: key, note: ta.value }).done(function(resp) {
                if (!resp || !resp.success) { return; }
                var wrap  = ta.closest('.sprint-phase-notes');
                var saved = wrap ? wrap.querySelector('.sprint-phase-note-saved') : null;
                if (saved) {
                    saved.style.display = '';
                    setTimeout(function() { saved.style.display = 'none'; }, 1500);
                }
            });
        }, 800);
    }, true);

    // ---- Improvements (collect / vote / actions / summary) ----------------

    function impPost(root, data) {
        return fetchToken().then(function(tok) {
            data.sprint_id = parseInt(root.getAttribute('data-sprint-id'), 10) || 0;
            data._ajax = 1;
            data._glpi_csrf_token = tok && tok.token ? tok.token : '';
            return window.jQuery.ajax({
                url: pluginRoot() + 'front/sprintagility.form.php',
                type: 'POST', dataType: 'json', data: data
            });
        });
    }

    function catLabels(root) {
        try {
            return JSON.parse(root.getAttribute('data-cat-labels') || '{}') || {};
        } catch (err) { return {}; }
    }

    // Build one improvement row for a given list, honouring the list's
    // display flags (vote button / votes pill / owner / status / toggle).
    function buildImpRow(root, list, row) {
        var d = list.dataset;
        var item = document.createElement('div');
        item.className = 'sprint-imp-item d-flex flex-wrap align-items-center gap-2';
        item.setAttribute('data-improvement-id', String(row.id));
        item.setAttribute('data-category', row.category);
        item.setAttribute('data-votes', String(row.votes || 0));
        item.setAttribute('data-status', row.status || 'open');

        var cat = document.createElement('span');
        cat.className = 'badge bg-blue-lt sprint-imp-cat';
        cat.textContent = catLabels(root)[row.category] || row.category;
        item.appendChild(cat);

        var desc = document.createElement('span');
        desc.className = 'sprint-imp-desc flex-grow-1';
        desc.textContent = row.description;
        item.appendChild(desc);

        if (d.impOwner === '1') {
            var owner = document.createElement('span');
            owner.className = 'sprint-imp-owner text-muted small';
            owner.innerHTML = '<i class="fas fa-user me-1"></i>';
            owner.appendChild(document.createTextNode(row.owner_name || '—'));
            item.appendChild(owner);
            if (row.due_date) {
                var due = document.createElement('span');
                due.className = 'sprint-imp-due text-muted small';
                due.innerHTML = '<i class="far fa-calendar me-1"></i>';
                due.appendChild(document.createTextNode(row.due_date));
                item.appendChild(due);
            }
        }

        if (d.impVoteBtn === '1' && root.getAttribute('data-can-contribute') === '1') {
            var voteBtn = document.createElement('button');
            voteBtn.type = 'button';
            voteBtn.className = 'btn btn-sm btn-link sprint-imp-vote-btn text-muted';
            voteBtn.setAttribute('data-sprint-action', 'meeting-imp-vote');
            voteBtn.title = root.getAttribute('data-lbl-vote') || 'Vote';
            voteBtn.innerHTML = '<i class="far fa-thumbs-up"></i> ';
            var votes = document.createElement('span');
            votes.className = 'sprint-imp-votes';
            votes.textContent = String(row.votes || 0);
            voteBtn.appendChild(votes);
            item.appendChild(voteBtn);
        } else if (d.impVotes === '1') {
            var pill = document.createElement('span');
            pill.className = 'badge bg-secondary-lt sprint-imp-votepill';
            pill.innerHTML = '<i class="far fa-thumbs-up me-1"></i>';
            var votes2 = document.createElement('span');
            votes2.className = 'sprint-imp-votes';
            votes2.textContent = String(row.votes || 0);
            pill.appendChild(votes2);
            item.appendChild(pill);
        }

        if (d.impStatus === '1') {
            var st = document.createElement('span');
            var done = (row.status === 'done');
            st.className = 'badge sprint-imp-statuspill ' + (done ? 'bg-green-lt' : 'bg-yellow-lt');
            st.textContent = done
                ? (root.getAttribute('data-lbl-done') || 'Done')
                : (root.getAttribute('data-lbl-open') || 'Open');
            item.appendChild(st);
        }

        if (d.impToggleBtn === '1' && root.getAttribute('data-can-toggle') === '1') {
            var tg = document.createElement('button');
            tg.type = 'button';
            tg.className = 'btn btn-sm btn-outline-success sprint-imp-toggle-btn';
            tg.setAttribute('data-sprint-action', 'meeting-imp-toggle');
            tg.innerHTML = '<i class="fas fa-check"></i>';
            item.appendChild(tg);
        }

        return item;
    }

    function insertImpRow(root, row) {
        root.querySelectorAll('[data-imp-list]').forEach(function(list) {
            var cats = (list.getAttribute('data-imp-categories') || '').split(',');
            if (cats.indexOf(row.category) === -1) { return; }
            var empty = list.querySelector('.sprint-imp-empty');
            if (empty) { empty.style.display = 'none'; }
            var el = buildImpRow(root, list, row);
            if (empty) { list.insertBefore(el, empty); } else { list.appendChild(el); }
        });
        resortVoteLists(root);
    }

    function resortVoteLists(root) {
        root.querySelectorAll('[data-imp-list][data-imp-sort="votes"]').forEach(function(list) {
            var rows = Array.prototype.slice.call(list.querySelectorAll('.sprint-imp-item'));
            rows.sort(function(a, b) {
                return (parseInt(b.getAttribute('data-votes'), 10) || 0)
                    - (parseInt(a.getAttribute('data-votes'), 10) || 0);
            });
            var empty = list.querySelector('.sprint-imp-empty');
            rows.forEach(function(r) {
                if (empty) { list.insertBefore(r, empty); } else { list.appendChild(r); }
            });
        });
    }

    document.addEventListener('click', function(e) {
        var el = e.target && e.target.closest
            ? e.target.closest('[data-sprint-action^="meeting-imp-"]')
            : null;
        if (!el) { return; }
        var root = rail();
        if (!root) { return; }
        var action = el.getAttribute('data-sprint-action');

        if (action === 'meeting-imp-add') {
            var box = el.closest('.sprint-imp-add');
            if (!box) { return; }
            var descInput = box.querySelector('.sprint-imp-add-description');
            var desc = descInput ? descInput.value.trim() : '';
            if (desc === '') {
                if (descInput) { descInput.focus(); }
                return;
            }
            var catSel   = box.querySelector('.sprint-imp-add-category');
            var category = box.getAttribute('data-imp-fixed-category') || (catSel ? catSel.value : 'action');
            var ownerSel = box.querySelector('.sprint-imp-add-owner');
            var dueInput = box.querySelector('.sprint-imp-add-due');
            var anonBox  = box.querySelector('.sprint-imp-add-anonymous');
            var isAnon   = !!(anonBox && anonBox.checked);

            var payload = {
                action: 'improvement',
                category: category,
                description: desc,
                users_id: ownerSel ? (parseInt(ownerSel.value, 10) || 0) : 0,
                due_date: dueInput ? dueInput.value : ''
            };
            if (isAnon) { payload.is_anonymous = 1; }

            el.disabled = true;
            impPost(root, payload).done(function(resp) {
                if (!resp || !resp.success || !resp.id) {
                    if (window.glpi_toast_error) {
                        window.glpi_toast_error((resp && resp.message) || 'Could not save');
                    }
                    return;
                }
                var ownerName = '';
                if (isAnon && category !== 'action') {
                    ownerName = root.getAttribute('data-lbl-anon') || 'Anonymous';
                } else if (ownerSel && ownerSel.selectedIndex > 0) {
                    ownerName = ownerSel.options[ownerSel.selectedIndex].text;
                } else if (!ownerSel && category !== 'action') {
                    ownerName = root.getAttribute('data-current-user') || '';
                }
                insertImpRow(root, {
                    id: resp.id,
                    category: category,
                    description: desc,
                    owner_name: ownerName,
                    due_date: dueInput ? dueInput.value : '',
                    votes: 0,
                    status: 'open'
                });
                if (descInput) { descInput.value = ''; }
                if (dueInput) { dueInput.value = ''; }
                if (anonBox) { anonBox.checked = false; }
                if (window.glpi_toast_info && resp.message) { window.glpi_toast_info(resp.message); }
            }).always(function() { el.disabled = false; });

        } else if (action === 'meeting-imp-vote') {
            var item = el.closest('[data-improvement-id]');
            if (!item) { return; }
            var impId = parseInt(item.getAttribute('data-improvement-id'), 10) || 0;
            var wasVoted = !!el.querySelector('i.fas');
            el.disabled = true;
            impPost(root, { action: 'improvement_vote', id: impId }).done(function(resp) {
                if (!resp || !resp.success) {
                    if (window.glpi_toast_error) {
                        window.glpi_toast_error((resp && resp.message) || 'Could not vote');
                    }
                    return;
                }
                var delta = wasVoted ? -1 : 1;
                root.querySelectorAll('[data-improvement-id="' + impId + '"]').forEach(function(r) {
                    var votes = Math.max(0, (parseInt(r.getAttribute('data-votes'), 10) || 0) + delta);
                    r.setAttribute('data-votes', String(votes));
                    r.querySelectorAll('.sprint-imp-votes').forEach(function(v) { v.textContent = String(votes); });
                    var vb = r.querySelector('.sprint-imp-vote-btn');
                    if (vb) {
                        vb.classList.toggle('text-muted', wasVoted);
                        vb.title = wasVoted
                            ? (root.getAttribute('data-lbl-vote') || 'Vote')
                            : (root.getAttribute('data-lbl-unvote') || 'Remove my vote');
                        var ic = vb.querySelector('i');
                        if (ic) {
                            ic.classList.toggle('fas', !wasVoted);
                            ic.classList.toggle('far', wasVoted);
                        }
                    }
                });
                resortVoteLists(root);
            }).always(function() { el.disabled = false; });

        } else if (action === 'meeting-imp-toggle') {
            var row = el.closest('[data-improvement-id]');
            if (!row) { return; }
            var togId = parseInt(row.getAttribute('data-improvement-id'), 10) || 0;
            el.disabled = true;
            impPost(root, {
                action: 'improvement_toggle',
                id: togId,
                meeting_id: parseInt(root.getAttribute('data-meeting-id'), 10) || 0
            }).done(function(resp) {
                if (!resp || !resp.success) {
                    if (window.glpi_toast_error) {
                        window.glpi_toast_error((resp && resp.message) || 'Could not update');
                    }
                    return;
                }
                var nowDone = row.getAttribute('data-status') !== 'done';
                root.querySelectorAll('[data-improvement-id="' + togId + '"]').forEach(function(r) {
                    r.setAttribute('data-status', nowDone ? 'done' : 'open');
                    var pill = r.querySelector('.sprint-imp-statuspill');
                    if (pill) {
                        pill.classList.toggle('bg-green-lt', nowDone);
                        pill.classList.toggle('bg-yellow-lt', !nowDone);
                        pill.textContent = nowDone
                            ? (root.getAttribute('data-lbl-done') || 'Done')
                            : (root.getAttribute('data-lbl-open') || 'Open');
                    }
                });
            }).always(function() { el.disabled = false; });
        }
    }, true);

    // ---- Boot -------------------------------------------------------------

    function bootRail() {
        var root = rail();
        if (!root) { return; }
        recalcOffset(root);
        syncUI(root);
        tick(root);
        setInterval(function() {
            var r = rail();
            if (r) { tick(r); }
        }, 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootRail);
    } else {
        bootRail();
    }
})();
