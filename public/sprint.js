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
        // On core pages (Ticket/Change/... with a Sprint tab) only wrap the
        // plugin's own tables, so core tables keep GLPI's layout.
        var onPluginPage = /\/(plugins|marketplace)\/sprint\//.test(window.location.pathname);
        for (var i = 0; i < tables.length; i++) {
            var table = tables[i];
            if (!onPluginPage && !table.closest('[class*="sprint-"], [id^="sprint"]')) {
                continue;
            }
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
        var summary = document.querySelector('.sprint-template-summary');
        if (!templateId || templateId == 0) {
            if (summary) { summary.hidden = true; summary.innerHTML = ''; }
            return;
        }

        var ajaxBase = CFG_GLPI.root_doc + '/plugins/sprint/ajax/';
        var labels = {};
        try { labels = JSON.parse((summary && summary.getAttribute('data-labels')) || '{}') || {}; } catch (e) { labels = {}; }
        var fieldNames = labels.fields || {};

        function esc(text) {
            var d = document.createElement('div');
            d.textContent = text == null ? '' : String(text);
            return d.innerHTML;
        }

        // Fill a field only when the user left it empty; remember which
        // values came from the template so the summary can say so.
        var filled = [], kept = [];
        function prefill(selector, key, value) {
            if (!value) { return; }
            var el = document.querySelector(selector);
            if (!el) { return; }
            // A value an earlier template filled in may be replaced; one the
            // user typed (or edited afterwards) is kept.
            if (!el.value || el.classList.contains('sprint-from-template')) {
                el.value = value;
                el.classList.add('sprint-from-template');
                el.addEventListener('input', function() { el.classList.remove('sprint-from-template'); }, { once: true });
                filled.push(fieldNames[key] || key);
            } else if (el.value !== String(value)) {
                kept.push(fieldNames[key] || key);
            }
        }

        function render(data) {
            prefill('input[name="name"]', 'name', data.name_pattern);
            prefill('textarea[name="goal"]', 'goal', data.goal);
            prefill('textarea[name="comment"]', 'comment', data.comment);

            // Duration always follows the template (it has no "empty" state).
            if (data.duration_weeks) {
                var durationSelect = document.querySelector('select[name="duration_weeks"]');
                if (durationSelect) {
                    if (String(durationSelect.value) !== String(data.duration_weeks)) {
                        filled.push(fieldNames.duration_weeks || 'duration_weeks');
                    }
                    durationSelect.value = data.duration_weeks;
                    // Trigger change event for GLPI dropdowns
                    $(durationSelect).trigger('change');
                }
            }

            if (!summary) { return; }
            var html = '<div class="sprint-template-summary-box"><strong><i class="fas fa-clipboard-check me-1" aria-hidden="true"></i>'
                + esc(labels.title || '') + '</strong>';
            var lines = data.summary_lines || [];
            if (lines.length) {
                html += '<ul class="mb-1">';
                for (var i = 0; i < lines.length; i++) { html += '<li>' + esc(lines[i]) + '</li>'; }
                html += '</ul>';
            } else {
                html += '<div>' + esc(labels.empty || '') + '</div>';
            }
            if (filled.length && labels.filled) {
                html += '<div class="sprint-small text-muted">' + esc(labels.filled.replace('%s', filled.join(', '))) + '</div>';
            }
            if (kept.length && labels.kept) {
                html += '<div class="sprint-small text-muted">' + esc(labels.kept.replace('%s', kept.join(', '))) + '</div>';
            }
            html += '</div>';
            summary.innerHTML = html;
            summary.hidden = false;
        }

        function fail() {
            if (!summary) { return; }
            summary.innerHTML = '<div class="sprint-template-summary-box text-danger">' + esc(labels.error || '') + '</div>';
            summary.hidden = false;
        }

        // Fresh one-shot token: spending the form's own token here would
        // break the later form submit on GLPI 11.
        $.ajax({ url: ajaxBase + 'csrftoken.php', type: 'GET', dataType: 'json' })
            .always(function(tok) {
                var token = (tok && tok.token) ? tok.token : getAjaxCsrfToken();
                $.ajax({
                    url: ajaxBase + 'gettemplate.php',
                    type: 'POST',
                    dataType: 'json',
                    data: { id: templateId, _glpi_csrf_token: token },
                    success: function(response) {
                        if (response && response.success && response.data) { render(response.data); } else { fail(); }
                    },
                    error: fail
                });
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
        var tables = findTablesForBar(bar);
        if (!tables.length) { return; }

        var textEl   = bar.querySelector('.sf-text');
        var statusEl = bar.querySelector('.sf-status');
        var ownerEl  = bar.querySelector('.sf-owner');
        var typeEl   = bar.querySelector('.sf-type');
        var tagEl    = bar.querySelector('.sf-tag');
        var sprintEl = bar.querySelector('.sf-sprint');
        var catEl    = bar.querySelector('.sf-category');
        var text   = textEl   ? (textEl.value   || '').toLowerCase().trim() : '';
        var status = statusEl ? (statusEl.value || '').toString()           : '';
        var owner  = ownerEl  ? (ownerEl.value  || '').toString()           : '';
        var type   = typeEl   ? (typeEl.value   || '').toString()           : '';
        var tag    = tagEl    ? (tagEl.value    || '').toLowerCase()        : '';
        var sprint = sprintEl ? (sprintEl.value || '').toString()           : '';
        var cat    = catEl    ? (catEl.value    || '').toString()           : '';

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
            // '' = all categories; '0' explicitly means "no category", so the
            // check is on !== '' rather than on truthiness.
            if (show && cat !== '') {
                if (String(parseInt(row.getAttribute('data-category-id'), 10) || 0) !== cat) { show = false; }
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
        // Chips, "x of y items" and the filtered-empty state (quickedit block).
        if (typeof window.sprintFilterAfterApply === 'function') {
            window.sprintFilterAfterApply(bar, rows);
        }
    }

    function resetFilter(bar) {
        bar = resolveBar(bar);
        if (!bar) { return; }
        var inputs = bar.querySelectorAll('.sf-text, .sf-status, .sf-owner, .sf-type, .sf-tag, .sf-category, .sf-sprint');
        for (var i = 0; i < inputs.length; i++) { inputs[i].value = ''; }
        applyFilter(bar);
    }

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
            case 'category':     return (row.getAttribute('data-category-name')      || '').toLowerCase();
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
        // Announce the active sort to assistive tech; other headers reset.
        var sortables = table.querySelectorAll('.sprint-sortable');
        for (var q = 0; q < sortables.length; q++) { sortables[q].setAttribute('aria-sort', 'none'); }
        th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');

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
        if (t.classList.contains('sf-status') || t.classList.contains('sf-owner') || t.classList.contains('sf-type') || t.classList.contains('sf-tag') || t.classList.contains('sf-category') || t.classList.contains('sf-sprint')) {
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
    }, true);

    // --- Layer 2: jQuery bubbling delegation ---
    if (typeof window.jQuery === 'function') {
        window.jQuery(function($) {
            $(document).off('.sprintFilter')
                .on('change.sprintFilter', '.sprint-filter-bar .sf-status, .sprint-filter-bar .sf-owner, .sprint-filter-bar .sf-type, .sprint-filter-bar .sf-tag, .sprint-filter-bar .sf-category, .sprint-filter-bar .sf-sprint', function() {
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

        var selects = bar.querySelectorAll('.sf-status, .sf-owner, .sf-type, .sf-tag, .sf-category, .sf-sprint');
        for (var i = 0; i < selects.length; i++) {
            selects[i].addEventListener('change', function() { applyFilter(this); });
        }
        var textEl = bar.querySelector('.sf-text');
        if (textEl) {
            textEl.addEventListener('input', function() { applyFilter(this); });
        }
        // Bars with a result label show it from the start.
        if (bar.querySelector('.sf-summary, .sf-chips')) { applyFilter(bar); }
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
        // Keyboard + screen-reader semantics for every wrapper, keyed or not.
        var all = (root || document).querySelectorAll('.sprint-collapsible');
        for (var a = 0; a < all.length; a++) { syncCollapsibleA11y(all[a]); }
    }

    // A <button> header gets aria-expanded/aria-controls; a legacy <div>
    // header additionally becomes a focusable role=button (Enter/Space are
    // handled by the keydown listener below).
    var collapsibleSeq = 0;
    function syncCollapsibleA11y(wrap) {
        var header = wrap.querySelector(':scope > .sprint-collapsible-header');
        var body   = wrap.querySelector(':scope > .sprint-collapsible-body');
        if (!header) { return; }
        if (header.tagName !== 'BUTTON' && !header.hasAttribute('role')) {
            header.setAttribute('role', 'button');
            header.setAttribute('tabindex', '0');
        }
        if (body) {
            if (!body.id) { body.id = 'sprint-collapsible-body-' + (++collapsibleSeq); }
            header.setAttribute('aria-controls', body.id);
        }
        header.setAttribute('aria-expanded', wrap.classList.contains('sprint-collapsed') ? 'false' : 'true');
    }

    document.addEventListener('keydown', function(ev) {
        if (ev.key !== 'Enter' && ev.key !== ' ') { return; }
        var t = ev.target;
        if (!t || !t.matches || !t.matches('.sprint-collapsible-header[role="button"]')) { return; }
        ev.preventDefault();
        t.click();
    }, false);

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
        syncCollapsibleA11y(wrap);
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
                window.sprintNotify(window.sprintT('selectSprintFirst', 'Select a sprint first'), 'warning');
                return;
            }

            var btn = form.querySelector('.sprint-backlog-assign-btn');
            var extra = {};

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
                Object.keys(extra).forEach(function(k) { data[k] = extra[k]; });
                return window.jQuery.ajax({
                    url: ajaxBase + 'assigntosprint.php',
                    type: 'POST', dataType: 'json',
                    data: data
                });
            }).done(function(resp) {
                // Capacity review / credit confirmation first, then again.
                if (window.sprintAssignGuard(resp, function(more) {
                    Object.keys(more).forEach(function(k) { extra[k] = more[k]; });
                    doAssign(readyChecks);
                })) {
                    if (btn) { btn.disabled = false; }
                    return;
                }
                if (resp && resp.success) {
                    window.sprintNotify(resp.message || window.sprintT('assigned', 'Assigned'), 'success');
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
                    window.sprintNotify((resp && resp.message) || window.sprintT('assignFailed', 'Could not assign the item'), 'error');
                    if (btn) { btn.disabled = false; }
                }
            }).fail(function() {
                window.sprintNotify(window.sprintT('networkError', 'Network error'), 'error');
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
            window.sprintNotify((resp && resp.message) || window.sprintT('meetingUpdateFailed', 'Could not update the meeting'), 'error');
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
                card.scrollIntoView({ behavior: window.sprintReducedMotion() ? 'auto' : 'smooth', block: 'start' });
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
        timer.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2) + ' / ' + window.sprintT('minutes', '%d min').replace('%d', String(mins));
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
            navPost(root, 'set_phase', { phase: target }).done(function(resp) {
                // Voting closed: the top themes became actions, rendered server-side.
                if (resp && resp.success && resp.promoted > 0) { window.location.reload(); return; }
                applySession(root, resp, true);
            }).always(function() { el.disabled = false; });

        } else if (action === 'meeting-goto' && canDrive(root)) {
            if ((root.getAttribute('data-status') || '') !== 'in_progress') { return; }
            var card = el.closest('.sprint-phase-card');
            if (!card) { return; }
            var idx = parseInt(card.getAttribute('data-phase-index'), 10) || 0;
            if (idx === (parseInt(root.getAttribute('data-current-phase'), 10) || 0)) { return; }
            navPost(root, 'set_phase', { phase: idx }).done(function(resp) {
                if (resp && resp.success && resp.promoted > 0) { window.location.reload(); return; }
                applySession(root, resp, false);
            });

        } else if (action === 'meeting-end' && canDrive(root)) {
            var msg = el.getAttribute('data-confirm');
            var endMeeting = function() {
                el.disabled = true;
                navPost(root, 'end_meeting').done(function(resp) {
                    if (resp && resp.success && resp.promoted > 0) { window.location.reload(); return; }
                    applySession(root, resp, false);
                }).always(function() { el.disabled = false; });
            };
            if (msg) { window.sprintConfirm(msg, endMeeting); } else { endMeeting(); }
        }
    }, true);

    // ---- Per-phase notes autosave ----------------------------------------
    // Every note shows its own status (Not saved / Saving… / Saved / Save
    // failed + Retry); the text is never touched on failure and an unsaved
    // draft is kept in this browser until the server confirms it. All saves
    // run through one queue: the server keeps every note in one field
    // (read-modify-write), so parallel saves could drop each other's edits.
    // Sequence numbers make sure an older response never marks newer text
    // as saved. Phase navigation flushes pending saves first.

    var noteTimers = {};
    var noteState  = {};
    var noteQueue  = null;

    function resolvedPromise() {
        return window.jQuery.Deferred().resolve().promise();
    }

    function noteKey(ta) {
        return ta.getAttribute('data-phase-key') || '';
    }

    function noteStateFor(key) {
        if (!noteState[key]) {
            noteState[key] = { seq: 0, savedSeq: 0, saving: false, failed: false, restored: false, message: '' };
        }
        return noteState[key];
    }

    function noteDirty(st) {
        return st.seq > st.savedSeq;
    }

    function noteDraftKey(root, key) {
        return 'sprintMeetingNote:' + (root.getAttribute('data-meeting-id') || '0') + ':' + key;
    }

    function storeNoteDraft(root, key, value) {
        try { window.localStorage.setItem(noteDraftKey(root, key), value); } catch (err) { /* storage unavailable */ }
    }

    function clearNoteDraft(root, key, savedValue) {
        try {
            var k = noteDraftKey(root, key);
            if (window.localStorage.getItem(k) === savedValue) { window.localStorage.removeItem(k); }
        } catch (err) { /* storage unavailable */ }
    }

    function renderNoteStatus(root, ta) {
        var wrap = ta.closest('.sprint-phase-notes');
        if (!wrap) { return; }
        var el    = wrap.querySelector('.sprint-phase-note-status');
        var retry = wrap.querySelector('.sprint-phase-note-retry');
        var st    = noteStateFor(noteKey(ta));
        var label = '', icon = '', cls = '';
        if (noteDirty(st) && st.restored && !st.saving) {
            label = root.getAttribute('data-lbl-note-restored') || ''; icon = 'fas fa-clock-rotate-left'; cls = 'text-warning';
        } else if (noteDirty(st) && st.failed && !st.saving) {
            label = root.getAttribute('data-lbl-note-failed') || ''; icon = 'fas fa-triangle-exclamation'; cls = 'text-danger';
        } else if (st.saving) {
            label = root.getAttribute('data-lbl-note-saving') || ''; icon = 'fas fa-spinner fa-spin'; cls = 'text-muted';
        } else if (noteDirty(st)) {
            label = root.getAttribute('data-lbl-note-dirty') || ''; icon = 'fas fa-circle-dot'; cls = 'text-muted';
        } else if (st.savedSeq > 0) {
            label = root.getAttribute('data-lbl-note-saved') || ''; icon = 'fas fa-check'; cls = 'text-success';
        }
        if (el) {
            el.className = 'sprint-phase-note-status sprint-small ' + cls;
            el.textContent = '';
            el.title = (st.failed && st.message) ? st.message : '';
            if (label !== '') {
                var i = document.createElement('i');
                i.className = icon + ' me-1';
                i.setAttribute('aria-hidden', 'true');
                el.appendChild(i);
                el.appendChild(document.createTextNode(label));
            }
        }
        if (retry) {
            retry.style.display = (noteDirty(st) && (st.failed || st.restored) && !st.saving) ? '' : 'none';
        }
    }

    function queueNoteSave(root, ta) {
        var key = noteKey(ta);
        var st  = noteStateFor(key);
        if (!noteQueue) { noteQueue = resolvedPromise(); }
        noteQueue = noteQueue.then(function() {
            // An earlier queued run may already have saved this text.
            if (!noteDirty(st)) { return null; }
            var seq   = st.seq;
            var value = ta.value;
            var done  = window.jQuery.Deferred();
            st.saving = true;
            renderNoteStatus(root, ta);
            phasePost(root, 'save_phase_note', { phase_key: key, note: value, seq: seq })
                .done(function(resp) {
                    if (resp && resp.success && (parseInt(resp.seq, 10) || 0) === seq) {
                        if (seq > st.savedSeq) { st.savedSeq = seq; }
                        st.failed = false; st.restored = false; st.message = '';
                        clearNoteDraft(root, key, value);
                    } else {
                        st.failed = true;
                        st.message = (resp && resp.message) || '';
                    }
                })
                .fail(function() { st.failed = true; st.message = ''; })
                .always(function() {
                    st.saving = false;
                    renderNoteStatus(root, ta);
                    done.resolve();
                });
            return done.promise();
        });
        return noteQueue;
    }

    // Save every pending note now; always resolves (failed notes keep their
    // text and show Retry), so navigation never blocks on a dead connection.
    function flushNotes(root) {
        if (!window.jQuery) { return null; }
        if (!root || !canDrive(root)) { return resolvedPromise(); }
        root.querySelectorAll('textarea.sprint-phase-note').forEach(function(ta) {
            var key = noteKey(ta);
            if (noteTimers[key]) { clearTimeout(noteTimers[key]); delete noteTimers[key]; }
            if (noteDirty(noteStateFor(key))) { queueNoteSave(root, ta); }
        });
        return noteQueue || resolvedPromise();
    }

    function notesPending() {
        for (var k in noteState) {
            if (Object.prototype.hasOwnProperty.call(noteState, k)
                && (noteDirty(noteState[k]) || noteState[k].saving)) { return true; }
        }
        return false;
    }

    // Phase moves wait for pending note saves, so no edit is lost or saved
    // against a phase the meeting already left.
    function navPost(root, action, extra) {
        return flushNotes(root).then(function() { return phasePost(root, action, extra); });
    }

    // Unsaved text from an earlier visit (connection lost, tab closed) comes
    // back with a visible status; it is saved when the facilitator retries
    // or types on.
    function restoreNoteDrafts(root) {
        if (!canDrive(root)) { return; }
        root.querySelectorAll('textarea.sprint-phase-note').forEach(function(ta) {
            var key = noteKey(ta), draft = null;
            try { draft = window.localStorage.getItem(noteDraftKey(root, key)); } catch (err) { draft = null; }
            if (draft === null) { return; }
            if (draft === ta.value) { clearNoteDraft(root, key, draft); return; }
            ta.value = draft;
            var st = noteStateFor(key);
            st.seq++; st.restored = true;
            renderNoteStatus(root, ta);
        });
    }

    document.addEventListener('input', function(e) {
        var ta = e.target;
        if (!ta || !ta.classList || !ta.classList.contains('sprint-phase-note')) { return; }
        var root = rail();
        if (!root || !canDrive(root)) { return; }
        var key = noteKey(ta);
        var st  = noteStateFor(key);
        st.seq++; st.failed = false; st.restored = false;
        storeNoteDraft(root, key, ta.value);
        renderNoteStatus(root, ta);
        if (noteTimers[key]) { clearTimeout(noteTimers[key]); }
        noteTimers[key] = setTimeout(function() {
            delete noteTimers[key];
            queueNoteSave(root, ta);
        }, 800);
    }, true);

    document.addEventListener('click', function(e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-sprint-action="note-retry"]') : null;
        if (!btn) { return; }
        var root = rail();
        var wrap = btn.closest('.sprint-phase-notes');
        var ta   = wrap ? wrap.querySelector('textarea.sprint-phase-note') : null;
        if (!root || !ta || !canDrive(root)) { return; }
        e.preventDefault();
        var st = noteStateFor(noteKey(ta));
        st.failed = false; st.restored = false;
        queueNoteSave(root, ta);
    }, true);

    window.addEventListener('beforeunload', function(e) {
        var root = rail();
        if (!root || !canDrive(root)) { return; }
        if (!notesPending() && !followUpsPending(root)) { return; }
        flushNotes(root);
        var msg = root.getAttribute('data-lbl-note-leave') || '';
        e.preventDefault();
        e.returnValue = msg;
        return msg;
    });

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
        item.setAttribute('data-mine', row.is_mine ? '1' : '0');

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

        var canVoteHere = d.impVoteBtn === '1' && root.getAttribute('data-can-contribute') === '1'
            && root.getAttribute('data-inputs-handled') !== '1' && !row.is_mine;
        if (canVoteHere) {
            var voteBtn = document.createElement('button');
            voteBtn.type = 'button';
            voteBtn.className = 'btn btn-sm btn-link sprint-imp-vote-btn text-muted';
            voteBtn.setAttribute('data-sprint-action', 'meeting-imp-vote');
            voteBtn.title = root.getAttribute('data-lbl-vote') || window.sprintT('vote', 'Vote');
            voteBtn.setAttribute('aria-label', voteBtn.title);
            voteBtn.innerHTML = '<i class="far fa-thumbs-up"></i> ';
            var votes = document.createElement('span');
            votes.className = 'sprint-imp-votes';
            votes.textContent = String(row.votes || 0);
            voteBtn.appendChild(votes);
            item.appendChild(voteBtn);
        } else if (d.impVotes === '1' || d.impVoteBtn === '1') {
            var pill = document.createElement('span');
            pill.className = 'badge bg-secondary-lt sprint-imp-votepill';
            if (row.is_mine && d.impVoteBtn === '1') {
                pill.title = root.getAttribute('data-lbl-own-input') || window.sprintT('ownInput', 'Your own input: no vote');
            }
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
                ? (root.getAttribute('data-lbl-done') || window.sprintT('done', 'Done'))
                : (root.getAttribute('data-lbl-open') || window.sprintT('open', 'Open'));
            item.appendChild(st);
        }

        if (d.impToggleBtn === '1' && root.getAttribute('data-can-toggle') === '1') {
            var tg = document.createElement('button');
            tg.type = 'button';
            tg.className = 'btn btn-sm btn-outline-success sprint-imp-toggle-btn';
            tg.setAttribute('data-sprint-action', 'meeting-imp-toggle');
            tg.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i>';
            tg.title = window.sprintT('toggleDone', 'Toggle done');
            tg.setAttribute('aria-label', tg.title);
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
                meeting_id: parseInt(root.getAttribute('data-meeting-id'), 10) || 0,
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
                        window.glpi_toast_error((resp && resp.message) || window.sprintT('saveFailed', 'Could not save'));
                    }
                    return;
                }
                var ownerName = '';
                if (isAnon && category !== 'action') {
                    ownerName = root.getAttribute('data-lbl-anon') || window.sprintT('anonymous', 'Anonymous');
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
                    status: 'open',
                    // Retro input is owned by its contributor: no vote on it.
                    is_mine: category !== 'action'
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
                        window.glpi_toast_error((resp && resp.message) || window.sprintT('voteFailed', 'Could not vote'));
                    }
                    return;
                }
                var nowVoted = (typeof resp.voted === 'boolean') ? resp.voted : !wasVoted;
                wasVoted = !nowVoted;
                var delta = wasVoted ? -1 : 1;
                if (typeof resp.votes_left === 'number') {
                    var perMember = parseInt(root.getAttribute('data-votes-per-member'), 10) || 0;
                    root.setAttribute('data-votes-used', String(Math.max(0, perMember - resp.votes_left)));
                    root.querySelectorAll('.sprint-imp-votes-left').forEach(function(c) {
                        c.textContent = (root.getAttribute('data-lbl-votes-left') || window.sprintT('votesLeft', '%1$s of %2$s votes left'))
                            .replace('%1$s', String(resp.votes_left)).replace('%2$s', String(perMember));
                    });
                }
                root.querySelectorAll('[data-improvement-id="' + impId + '"]').forEach(function(r) {
                    var votes = Math.max(0, (parseInt(r.getAttribute('data-votes'), 10) || 0) + delta);
                    r.setAttribute('data-votes', String(votes));
                    r.querySelectorAll('.sprint-imp-votes').forEach(function(v) { v.textContent = String(votes); });
                    var vb = r.querySelector('.sprint-imp-vote-btn');
                    if (vb) {
                        vb.classList.toggle('text-muted', wasVoted);
                        vb.title = wasVoted
                            ? (root.getAttribute('data-lbl-vote') || window.sprintT('vote', 'Vote'))
                            : (root.getAttribute('data-lbl-unvote') || window.sprintT('unvote', 'Remove my vote'));
                        var ic = vb.querySelector('i');
                        if (ic) {
                            ic.classList.toggle('fas', !wasVoted);
                            ic.classList.toggle('far', wasVoted);
                        }
                    }
                });
                resortVoteLists(root);
            }).always(function() { el.disabled = false; });

        } else if (action === 'meeting-imp-carry') {
            // Unhandled input of the previous retrospective joins this one.
            var prevRow = el.closest('[data-prev-improvement-id]');
            if (!prevRow) { return; }
            var prevId = parseInt(prevRow.getAttribute('data-prev-improvement-id'), 10) || 0;
            el.disabled = true;
            impPost(root, {
                action: 'improvement_carry',
                id: prevId,
                meeting_id: parseInt(root.getAttribute('data-meeting-id'), 10) || 0
            }).done(function(resp) {
                if (!resp || !resp.success || !resp.row) {
                    if (window.glpi_toast_error) {
                        window.glpi_toast_error((resp && resp.message) || window.sprintT('saveFailed', 'Could not save'));
                    }
                    el.disabled = false;
                    return;
                }
                insertImpRow(root, resp.row);
                var box = prevRow.closest('.sprint-imp-prev');
                if (prevRow.parentNode) { prevRow.parentNode.removeChild(prevRow); }
                if (box) {
                    var left = box.querySelectorAll('[data-prev-improvement-id]').length;
                    var count = box.querySelector('.sprint-imp-prev-count');
                    if (count) { count.textContent = String(left); }
                    if (left === 0) { box.style.display = 'none'; }
                }
                if (window.glpi_toast_info) { window.glpi_toast_info(window.sprintT('carried', 'Added to this retrospective')); }
            }).fail(function() { el.disabled = false; });

        } else if (action === 'meeting-imp-assign') {
            // Owner and due date for an action made from a top-voted theme.
            var aRow = el.closest('[data-improvement-id]');
            var aBox = el.closest('.sprint-imp-assign');
            if (!aRow || !aBox) { return; }
            var ownerSelect = aBox.querySelector('.sprint-imp-assign-owner');
            var dueField    = aBox.querySelector('.sprint-imp-assign-due');
            var ownerId     = ownerSelect ? (parseInt(ownerSelect.value, 10) || 0) : 0;
            if (ownerId <= 0) { if (ownerSelect) { ownerSelect.focus(); } return; }
            var aId = parseInt(aRow.getAttribute('data-improvement-id'), 10) || 0;
            el.disabled = true;
            impPost(root, {
                action: 'improvement_assign',
                id: aId,
                users_id: ownerId,
                due_date: dueField ? dueField.value : '',
                meeting_id: parseInt(root.getAttribute('data-meeting-id'), 10) || 0
            }).done(function(resp) {
                if (!resp || !resp.success) {
                    if (window.glpi_toast_error) {
                        window.glpi_toast_error((resp && resp.message) || window.sprintT('updateFailed', 'Could not update'));
                    }
                    el.disabled = false;
                    return;
                }
                var ownerLabel = resp.owner_name || (ownerSelect ? ownerSelect.options[ownerSelect.selectedIndex].text : '');
                root.querySelectorAll('[data-improvement-id="' + aId + '"]').forEach(function(r) {
                    var o = r.querySelector('.sprint-imp-owner');
                    if (o) {
                        o.innerHTML = '<i class="fas fa-user me-1"></i>';
                        o.appendChild(document.createTextNode(ownerLabel || '—'));
                    }
                    if (dueField && dueField.value && !r.querySelector('.sprint-imp-due')) {
                        var dueEl = document.createElement('span');
                        dueEl.className = 'sprint-imp-due text-muted sprint-small';
                        dueEl.innerHTML = '<i class="far fa-calendar me-1"></i>';
                        dueEl.appendChild(document.createTextNode(dueField.value));
                        if (o && o.parentNode) { o.parentNode.insertBefore(dueEl, o.nextSibling); }
                    }
                    var picker = r.querySelector('.sprint-imp-assign');
                    if (picker && picker.parentNode) { picker.parentNode.removeChild(picker); }
                });
            }).fail(function() { el.disabled = false; });

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
                        window.glpi_toast_error((resp && resp.message) || window.sprintT('updateFailed', 'Could not update'));
                    }
                    return;
                }
                var nowDone = row.getAttribute('data-status') !== 'done';
                root.querySelectorAll('[data-improvement-id="' + togId + '"]').forEach(function(r) {
                    r.setAttribute('data-status', nowDone ? 'done' : 'open');
                    // Follow-up rows: the evaluation is asked once an action is done.
                    var fuEval = r.querySelector('.sprint-fu-eval');
                    if (fuEval) { fuEval.style.display = nowDone ? '' : 'none'; }
                    var pill = r.querySelector('.sprint-imp-statuspill');
                    if (pill) {
                        pill.classList.toggle('bg-green-lt', nowDone);
                        pill.classList.toggle('bg-yellow-lt', !nowDone);
                        pill.textContent = nowDone
                            ? (root.getAttribute('data-lbl-done') || window.sprintT('done', 'Done'))
                            : (root.getAttribute('data-lbl-open') || window.sprintT('open', 'Open'));
                    }
                });
            }).always(function() { el.disabled = false; });
        }
    }, true);

    // ---- Retrospective follow-up of earlier actions -----------------------
    // Progress notes / evaluations per action per retrospective
    // (improvement_followup), and the facilitator's choice of the previous
    // sprint (set_prev_sprint). Status toggles reuse meeting-imp-toggle.

    function followUpsPending(root) {
        return !!root.querySelector('.sprint-fu-item[data-fu-dirty="1"]');
    }

    function renderFollowUpStatus(root, item, state) {
        var el = item.querySelector('.sprint-fu-status');
        if (!el) { return; }
        var map = {
            dirty:  ['data-lbl-note-dirty', 'fas fa-circle-dot', 'text-muted'],
            saving: ['data-lbl-note-saving', 'fas fa-spinner fa-spin', 'text-muted'],
            saved:  ['data-lbl-note-saved', 'fas fa-check', 'text-success'],
            failed: ['data-lbl-note-failed', 'fas fa-triangle-exclamation', 'text-danger']
        };
        var m = map[state];
        el.textContent = '';
        el.className = 'sprint-fu-status sprint-small ' + (m ? m[2] : '');
        if (!m) { return; }
        var i = document.createElement('i');
        i.className = m[1] + ' me-1';
        i.setAttribute('aria-hidden', 'true');
        el.appendChild(i);
        el.appendChild(document.createTextNode(root.getAttribute(m[0]) || ''));
    }

    function markFollowUpDirty(e) {
        var t = e.target;
        if (!t || !t.closest) { return; }
        var item = t.closest('.sprint-fu-item');
        if (!item || !t.closest('.sprint-fu-edit')) { return; }
        var root = rail();
        if (!root) { return; }
        item.setAttribute('data-fu-dirty', '1');
        item.setAttribute('data-fu-seq', String((parseInt(item.getAttribute('data-fu-seq'), 10) || 0) + 1));
        renderFollowUpStatus(root, item, 'dirty');
    }
    document.addEventListener('input', markFollowUpDirty, true);
    document.addEventListener('change', function(e) {
        if (e.target && e.target.classList && e.target.classList.contains('sprint-fu-helped')) { markFollowUpDirty(e); }
    }, true);

    document.addEventListener('click', function(e) {
        var el = e.target && e.target.closest ? e.target.closest('[data-sprint-action^="meeting-fu-"]') : null;
        if (!el) { return; }
        var root = rail();
        if (!root) { return; }
        var action = el.getAttribute('data-sprint-action');
        var meetingId = parseInt(root.getAttribute('data-meeting-id'), 10) || 0;

        if (action === 'meeting-fu-save') {
            var item = el.closest('.sprint-fu-item');
            if (!item) { return; }
            var val = function(sel) { var f = item.querySelector(sel); return f ? f.value : ''; };
            var seq = parseInt(item.getAttribute('data-fu-seq'), 10) || 0;
            el.disabled = true;
            renderFollowUpStatus(root, item, 'saving');
            impPost(root, {
                action: 'improvement_followup',
                id: parseInt(item.getAttribute('data-improvement-id'), 10) || 0,
                meeting_id: meetingId,
                progress_note: val('.sprint-fu-progress'),
                evaluation: val('.sprint-fu-evaluation'),
                helped: val('.sprint-fu-helped')
            }).done(function(resp) {
                var ok = !!(resp && resp.success);
                // Typing during the save keeps the item dirty.
                if (ok && (parseInt(item.getAttribute('data-fu-seq'), 10) || 0) === seq) {
                    item.removeAttribute('data-fu-dirty');
                    renderFollowUpStatus(root, item, 'saved');
                } else if (!ok) {
                    renderFollowUpStatus(root, item, 'failed');
                } else {
                    renderFollowUpStatus(root, item, 'dirty');
                }
            }).fail(function() {
                renderFollowUpStatus(root, item, 'failed');
            }).always(function() { el.disabled = false; });

        } else if (action === 'meeting-fu-prev' && canDrive(root)) {
            var box = el.closest('.sprint-fu-choice');
            var sel = box ? box.querySelector('.sprint-fu-prev-select') : null;
            if (!sel) { return; }
            el.disabled = true;
            navPost(root, 'set_prev_sprint', { prev_sprint_id: parseInt(sel.value, 10) || 0 }).done(function(resp) {
                if (resp && resp.success) {
                    window.location.reload();
                    return;
                }
                if (window.glpi_toast_error) {
                    window.glpi_toast_error((resp && resp.message) || root.getAttribute('data-lbl-note-failed') || '');
                }
                el.disabled = false;
            }).fail(function() { el.disabled = false; });
        }
    }, true);

    // ---- Boot -------------------------------------------------------------

    function bootRail() {
        var root = rail();
        if (!root) { return; }
        recalcOffset(root);
        syncUI(root);
        tick(root);
        restoreNoteDrafts(root);
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

/*
 * Catalogue product picker (select.sprint-credit-product): the product is
 * leading. Picking one copies its credits into the input named by
 * data-credits-for and locks that input; "Manual credits" unlocks it.
 * Lives here instead of inline so every page and modal gets it, whatever
 * order its markup and jQuery arrive in.
 */
(function() {
    if (window.__sprintCreditProductBound) { return; }
    window.__sprintCreditProductBound = true;

    function sync(select, notify) {
        var target = select.getAttribute('data-credits-for');
        if (!target) { return; }
        var scope = select.closest('.modal, form') || document;
        var input = scope.querySelector("input[name='" + target + "']");
        if (!input) { return; }
        var option  = select.options[select.selectedIndex];
        var credits = option ? option.getAttribute('data-credits') : null;
        var locked  = !!option && parseInt(option.value, 10) > 0 && credits !== null && credits !== '';
        if (locked) {
            // A change always takes the product's amount; on a mere re-sync
            // (modal opened, markup loaded) a stored amount stands — a
            // carried-over split keeps its share under the same product.
            var empty = input.value === '' || parseFloat(input.value) === 0;
            if ((notify || empty) && input.value !== credits) {
                input.value = credits;
                if (notify) { input.dispatchEvent(new Event('change', { bubbles: true })); }
            }
            input.readOnly = true;
            input.classList.add('sprint-credits-locked');
        } else if (input.classList.contains('sprint-credits-locked')) {
            // Only undo our own lock: a session without the credits right
            // gets a read-only input from the server.
            input.readOnly = false;
            input.classList.remove('sprint-credits-locked');
        }
    }

    function syncAll(root) {
        (root || document).querySelectorAll('select.sprint-credit-product').forEach(function(select) {
            sync(select, false);
        });
    }
    window.sprintCreditProductSync = syncAll;

    document.addEventListener('change', function(e) {
        if (e.target && e.target.matches && e.target.matches('select.sprint-credit-product')) {
            sync(e.target, true);
        }
    }, true);
    // The edit modals fill their fields with .val() right before showing.
    document.addEventListener('show.bs.modal', function(e) { syncAll(e.target); });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { syncAll(); });
    } else {
        syncAll();
    }
})();

/* === design-system: "+N" overflow for long tag lists === */
/**
 * Shows the first few `.sprint-tag-pill`s of every `.sprint-tag-pills` box
 * and folds the rest behind a "+N" toggle (spec 12). The limit defaults to
 * 3 and can be set per box with data-sprint-tag-limit. Boxes replaced by
 * AJAX (quick edit, meeting rows) are picked up again automatically.
 */
(function() {
    'use strict';

    if (window.__sprintTagOverflowBound) {
        return;
    }
    window.__sprintTagOverflowBound = true;

    function collapseTagPills(root) {
        var boxes = (root || document).querySelectorAll('.sprint-tag-pills:not([data-sprint-tags-done])');
        boxes.forEach(function(box) {
            box.setAttribute('data-sprint-tags-done', '1');
            var limit = parseInt(box.getAttribute('data-sprint-tag-limit') || '3', 10);
            var pills = Array.prototype.filter.call(box.children, function(el) {
                return el.classList.contains('sprint-tag-pill');
            });
            // Folding a single tag saves nothing: "+1" is as wide as the tag.
            if (!(limit > 0) || pills.length <= limit + 1) {
                return;
            }
            var hidden = pills.slice(limit);
            hidden.forEach(function(el) { el.classList.add('sprint-tag-overflow'); });
            var names = hidden.map(function(el) { return (el.textContent || '').trim(); }).join(', ');
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'sprint-tag-more';
            btn.textContent = '+' + hidden.length;
            btn.title = names;
            btn.setAttribute('aria-expanded', 'false');
            btn.setAttribute('aria-label', window.sprintT('showMore', 'Show %d more tags').replace('%d', String(hidden.length)) + ': ' + names);
            btn.setAttribute('data-sprint-more', '+' + hidden.length);
            box.appendChild(btn);
        });
    }

    document.addEventListener('click', function(e) {
        var btn = e.target && e.target.closest ? e.target.closest('.sprint-tag-more') : null;
        if (!btn) {
            return;
        }
        // Tag boxes sit inside clickable rows/links: keep the click local.
        e.preventDefault();
        e.stopPropagation();
        var box = btn.parentElement;
        var open = box.classList.toggle('is-expanded');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.textContent = open ? '−' : btn.getAttribute('data-sprint-more');
        if (open) {
            btn.setAttribute('data-sprint-more-label', btn.getAttribute('aria-label') || '');
            btn.setAttribute('aria-label', window.sprintT('showLess', 'Show fewer tags'));
        } else if (btn.hasAttribute('data-sprint-more-label')) {
            btn.setAttribute('aria-label', btn.getAttribute('data-sprint-more-label'));
        }
    }, true);

    var pending = false;
    function schedule() {
        if (pending) {
            return;
        }
        pending = true;
        window.requestAnimationFrame(function() {
            pending = false;
            collapseTagPills();
        });
    }

    function start() {
        collapseTagPills();
        if (typeof MutationObserver === 'function' && document.body) {
            new MutationObserver(function(mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    if (mutations[i].addedNodes.length) {
                        schedule();
                        return;
                    }
                }
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
/* === end design-system === */

/* === credits-admin: fixed product price hint === */
/*
 * Under a credits input that a catalogue product locked, say so and offer
 * the switch to "Manual credits" as a button. Runs after the picker's own
 * sync (bubble phase vs. its capture phase) and only reads its result, the
 * .sprint-credits-locked class. The texts come from the product <option>
 * (SprintCreditProduct::dropdownOptions()); without them nothing is shown.
 */
(function() {
    if (window.__sprintCreditFixedHintBound) { return; }
    window.__sprintCreditFixedHintBound = true;

    function update(select) {
        var target = select.getAttribute('data-credits-for');
        if (!target) { return; }
        var scope = select.closest('.modal, form') || document;
        var input = scope.querySelector("input[name='" + target + "']");
        if (!input) { return; }
        var hint = input.parentNode.querySelector('.sprint-credit-fixed-hint');
        var option = select.options[select.selectedIndex];
        var text = option ? option.getAttribute('data-fixed-hint') : null;
        if (!input.classList.contains('sprint-credits-locked') || !text) {
            if (hint) { hint.parentNode.removeChild(hint); }
            return;
        }
        if (!hint) {
            hint = document.createElement('div');
            hint.className = 'sprint-credit-fixed-hint sprint-small text-muted';
            var span = document.createElement('span');
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-link btn-sm p-0 ms-1 align-baseline sprint-credit-go-manual';
            hint.appendChild(span);
            hint.appendChild(btn);
            input.insertAdjacentElement('afterend', hint);
        }
        hint.firstChild.textContent = text;
        hint.lastChild.textContent = option.getAttribute('data-manual-label') || '';
        hint._sprintSelect = select;
    }
    function updateAll(root) {
        (root || document).querySelectorAll('select.sprint-credit-product').forEach(update);
    }

    document.addEventListener('change', function(e) {
        if (e.target && e.target.matches && e.target.matches('select.sprint-credit-product')) { update(e.target); }
    });
    document.addEventListener('click', function(e) {
        var btn = e.target && e.target.closest ? e.target.closest('.sprint-credit-go-manual') : null;
        if (!btn) { return; }
        var select = btn.parentNode._sprintSelect;
        if (!select) { return; }
        var manual = select.querySelector("option[value='0']");
        if (!manual) { return; }
        select.value = '0';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        var target = select.getAttribute('data-credits-for');
        var scope = select.closest('.modal, form') || document;
        var input = target ? scope.querySelector("input[name='" + target + "']") : null;
        if (input) { input.focus(); input.select && input.select(); }
    });
    document.addEventListener('shown.bs.modal', function(e) { updateAll(e.target); });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { updateAll(); });
    } else {
        updateAll();
    }
})();
/* === end credits-admin === */

/* === dashboard-nav: keyboard sort, sprint form guidance, side nav === */
(function() {
    'use strict';
    if (window.__sprintDashboardNavBound) { return; }
    window.__sprintDashboardNavBound = true;

    // Sortable headers rendered with tabindex: Enter/Space sort like a click.
    document.addEventListener('keydown', function(ev) {
        if (ev.key !== 'Enter' && ev.key !== ' ') { return; }
        var th = ev.target && ev.target.closest ? ev.target.closest('.sprint-sortable[tabindex]') : null;
        if (!th || ev.target !== th) { return; }
        ev.preventDefault();
        if (typeof window.sprintSortClick === 'function') { window.sprintSortClick(th); }
    }, false);

    // Sprint form: explain whether the end date or the duration wins.
    function updateEndDateHints() {
        var hints = document.querySelectorAll('.sprint-enddate-hint');
        for (var i = 0; i < hints.length; i++) {
            var hint = hints[i];
            var form = hint.closest('form') || document;
            var start = form.querySelector('input[name="date_start"]');
            var end = form.querySelector('input[name="date_end"]');
            var weeksEl = form.querySelector('select[name="duration_weeks"]');
            var weeks = weeksEl ? (parseInt(weeksEl.value, 10) || 2) : 2;
            var text;
            if (end && end.value) {
                text = hint.getAttribute('data-manual') || '';
            } else if (start && start.value) {
                var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(start.value);
                if (!m) { continue; }
                var d = new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
                d.setDate(d.getDate() + weeks * 7);
                text = (hint.getAttribute('data-calc') || '')
                    .replace('%s', d.toLocaleDateString())
                    .replace('%d', String(weeks));
            } else {
                text = hint.getAttribute('data-nostart') || '';
            }
            if (hint.textContent !== text) { hint.textContent = text; }
        }
    }
    document.addEventListener('change', updateEndDateHints, false);
    document.addEventListener('input', updateEndDateHints, false);
    if (typeof $ !== 'undefined' && $.fn) {
        // select2 dropdowns (duration) report through jQuery only.
        $(document).on('change', 'select[name="duration_weeks"]', updateEndDateHints);
    }
    // Date pickers do not always emit DOM events on the hidden input.
    setInterval(function() {
        if (document.querySelector('.sprint-enddate-hint')) { updateEndDateHints(); }
    }, 1500);

    // "Create a sprint from this template" deeplink: show the summary at once.
    function loadPreselectedTemplate() {
        var box = document.querySelector('.sprint-template-summary[data-preselect]:not([data-preselect-done])');
        if (!box) { return; }
        box.setAttribute('data-preselect-done', '1');
        var id = parseInt(box.getAttribute('data-preselect'), 10) || 0;
        if (id > 0 && typeof window.sprintLoadTemplate === 'function') { window.sprintLoadTemplate(id); }
    }

    // Plugin side nav: Bootstrap normally drives the small-screen toggle;
    // this keeps it working where its collapse plugin is unavailable.
    document.addEventListener('click', function(ev) {
        var btn = ev.target && ev.target.closest ? ev.target.closest('.sprint-nav-toggle') : null;
        if (!btn || (window.bootstrap && window.bootstrap.Collapse)) { return; }
        var panel = document.getElementById(btn.getAttribute('aria-controls'));
        if (!panel) { return; }
        var open = panel.classList.toggle('show');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }, false);

    function onReady() {
        updateEndDateHints();
        loadPreselectedTemplate();
    }
    // The main form tab arrives over ajax on both GLPI 10 and 11.
    if (typeof $ !== 'undefined' && $.fn) {
        $(document).ajaxComplete(function() { setTimeout(onReady, 0); });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();
/* === end dashboard-nav === */

/* === quickedit: filter chips, result count, filtered-empty state === */
/*
 * Opt-in for filter bars that contain `.sf-summary` and/or `.sf-chips`
 * (SprintItem::renderFilterBar(), meeting item tables). Called by
 * applyFilter() after every run: shows the active filters as removable
 * chips, "x of y items", and — when the filters hide every row — an empty
 * state that says so and offers the one reset. Texts come from the bar's
 * data-sf-* attributes (SprintItem::filterBarTextAttrs()).
 */
(function() {
    'use strict';
    if (window.sprintFilterAfterApply) { return; }

    var CONTROLS = '.sf-text, .sf-status, .sf-owner, .sf-type, .sf-tag, .sf-category, .sf-sprint';

    function fmt(tpl, a, b) {
        return String(tpl || '').replace('%1$s', a).replace('%2$s', b).replace('%s', a);
    }
    function valueLabel(c) {
        if (c.tagName === 'SELECT') {
            var o = c.options[c.selectedIndex];
            return o ? o.text.replace(/^[\s —-]+/, '').trim() : c.value;
        }
        return '"' + String(c.value).trim() + '"';
    }
    function fire(c) {
        c.dispatchEvent(new Event(c.tagName === 'INPUT' ? 'input' : 'change', { bubbles: true }));
    }
    function clearAll(bar) {
        var first = null;
        bar.querySelectorAll(CONTROLS).forEach(function(c) {
            if (String(c.value || '') !== '') { c.value = ''; first = first || c; }
        });
        if (first) { fire(first); }
    }

    window.sprintFilterAfterApply = function(bar, rows) {
        if (!bar || !bar.querySelector) { return; }
        var summary = bar.querySelector('.sf-summary');
        var chips   = bar.querySelector('.sf-chips');
        if (!summary && !chips) { return; }
        rows = rows || [];

        var total = rows.length;
        var shown = 0;
        for (var i = 0; i < rows.length; i++) {
            if (!rows[i].classList.contains('sprint-row-hidden')) { shown++; }
        }
        var active = [];
        bar.querySelectorAll(CONTROLS).forEach(function(c) {
            if (String(c.value || '').trim() !== '') { active.push(c); }
        });

        if (summary) {
            var many = total !== 1;
            summary.textContent = active.length
                ? fmt(bar.getAttribute(many ? 'data-sf-of-many' : 'data-sf-of-one'), shown, total)
                : fmt(bar.getAttribute(many ? 'data-sf-all-many' : 'data-sf-all-one'), total);
        }

        if (chips) {
            chips.innerHTML = '';
            active.forEach(function(c) {
                var text = (c.getAttribute('data-sf-label') || c.getAttribute('aria-label') || '') + ': ' + valueLabel(c);
                var chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'btn btn-sm sprint-filter-chip';
                chip.setAttribute('aria-label', fmt(bar.getAttribute('data-sf-remove'), text));
                var span = document.createElement('span');
                span.textContent = text;
                chip.appendChild(span);
                chip.insertAdjacentHTML('beforeend', ' <i class="fas fa-times" aria-hidden="true"></i>');
                chip.addEventListener('click', function() {
                    c.value = '';
                    fire(c);
                    var next = chips.querySelector('.sprint-filter-chip');
                    (next || c).focus();
                });
                chips.appendChild(chip);
            });
            chips.style.setProperty('display', active.length ? 'flex' : 'none', 'important');
        }

        // Filtered-empty state, distinct from "no items at all" (server side).
        var lastTable = rows.length ? rows[rows.length - 1].closest('table') : null;
        var empty = bar.__sprintFilterEmpty;
        if (total > 0 && shown === 0 && lastTable) {
            if (!empty || !document.contains(empty)) {
                empty = document.createElement('div');
                empty.className = 'sprint-filter-empty text-center py-3';
                empty.setAttribute('role', 'status');
                var title = document.createElement('div');
                title.className = 'fw-bold';
                title.innerHTML = '<i class="fas fa-filter me-1" aria-hidden="true"></i>';
                title.appendChild(document.createTextNode(bar.getAttribute('data-sf-empty') || ''));
                var hint = document.createElement('div');
                hint.className = 'text-muted sprint-small mb-2';
                hint.textContent = bar.getAttribute('data-sf-empty-hint') || '';
                var reset = document.createElement('button');
                reset.type = 'button';
                reset.className = 'btn btn-sm btn-outline-secondary';
                reset.innerHTML = '<i class="fas fa-times me-1" aria-hidden="true"></i>';
                reset.appendChild(document.createTextNode(bar.getAttribute('data-sf-reset-label') || ''));
                reset.addEventListener('click', function() {
                    clearAll(bar);
                    var text = bar.querySelector('.sf-text');
                    if (text) { text.focus(); }
                });
                empty.appendChild(title);
                empty.appendChild(hint);
                empty.appendChild(reset);
                bar.__sprintFilterEmpty = empty;
            }
            if (empty.previousElementSibling !== lastTable) {
                lastTable.parentNode.insertBefore(empty, lastTable.nextSibling);
            }
            empty.style.display = '';
        } else if (empty) {
            empty.style.display = 'none';
        }
    };
})();
/* === end quickedit === */

/* === terminology: runtime i18n, feedback and confirm helpers === */
/**
 * window.sprintT(key, fallback): translated runtime message from
 * window.sprintI18n (emitted by SprintI18n::render()), English otherwise.
 * window.sprintNotify(msg, level): the shared toast (sprintUi) when present,
 * GLPI's toasts otherwise; level info|success|warning|error.
 * window.sprintConfirm(msg, onOk, opts): in-page confirmation dialog instead
 * of the browser confirm(); opts.danger styles the OK button as destructive.
 * window.sprintReducedMotion(): true when the user asked for less motion.
 */
(function() {
    'use strict';

    window.sprintT = function(key, fallback) {
        var map = window.sprintI18n;
        return (map && typeof map[key] === 'string' && map[key] !== '') ? map[key] : fallback;
    };

    window.sprintReducedMotion = function() {
        try {
            return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        } catch (e) {
            return false;
        }
    };

    window.sprintNotify = function(msg, level) {
        msg = String(msg == null ? '' : msg);
        if (msg === '') { return; }
        if (window.sprintUi && typeof window.sprintUi.toast === 'function') {
            window.sprintUi.toast(msg, level);
            return;
        }
        var fn = { success: 'glpi_toast_info', info: 'glpi_toast_info', warning: 'glpi_toast_warning', error: 'glpi_toast_error' }[level] || 'glpi_toast_info';
        if (typeof window[fn] === 'function') {
            window[fn](msg);
            return;
        }
        if (typeof window.sprintBacklogNotify === 'function') {
            window.sprintBacklogNotify(level === 'error' ? 'danger' : (level || 'info'), msg);
        }
    };

    var seq = 0;

    // In-page dialog with a custom body. opts.validate() (optional) says
    // whether OK is allowed; opts.onOk(box) runs on confirm.
    function openDialog(opts) {
        var back = document.activeElement;
        var id = 'sprint-confirm-' + (++seq);

        var overlay = document.createElement('div');
        overlay.className = 'sprint-confirm-overlay';
        overlay.style.cssText = 'position:fixed;inset:0;z-index:2200;background:rgba(0,0,0,.45);'
            + 'display:flex;align-items:center;justify-content:center;padding:16px;';

        var box = document.createElement('div');
        box.setAttribute('role', opts.body ? 'dialog' : 'alertdialog');
        box.setAttribute('aria-modal', 'true');
        box.setAttribute('aria-labelledby', id + '-title');
        box.setAttribute('aria-describedby', id + '-msg');
        box.style.cssText = 'background:var(--tblr-bg-surface,#fff);color:var(--tblr-body-color,#212529);'
            + 'border:1px solid var(--tblr-border-color,#dee2e6);border-radius:8px;width:100%;max-width:' + (opts.wide ? '640px' : '480px') + ';'
            + 'max-height:calc(100vh - 32px);overflow:auto;'
            + 'padding:16px;box-shadow:0 8px 32px rgba(0,0,0,.25);';

        var title = document.createElement('div');
        title.id = id + '-title';
        title.className = 'fw-bold mb-2';
        title.textContent = opts.title || window.sprintT('confirmTitle', 'Please confirm');

        var text = document.createElement('div');
        text.id = id + '-msg';
        text.className = 'mb-3';
        text.style.whiteSpace = 'pre-line';
        text.textContent = opts.message || '';

        var actions = document.createElement('div');
        actions.className = 'd-flex justify-content-end gap-2';
        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-outline-secondary';
        cancel.textContent = opts.cancelLabel || window.sprintT('cancel', 'Cancel');
        var ok = document.createElement('button');
        ok.type = 'button';
        ok.className = 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary');
        ok.textContent = opts.okLabel || window.sprintT('confirm', 'Confirm');
        actions.appendChild(cancel);
        actions.appendChild(ok);

        box.appendChild(title);
        if (opts.message) { box.appendChild(text); }
        if (opts.body) { box.appendChild(opts.body); }
        box.appendChild(actions);
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        function refresh() {
            ok.disabled = typeof opts.validate === 'function' ? !opts.validate(box) : false;
        }
        function close(confirmed) {
            document.removeEventListener('keydown', onKey, true);
            if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
            if (back && document.contains(back) && typeof back.focus === 'function') { back.focus(); }
            if (confirmed && typeof opts.onOk === 'function') { opts.onOk(box); }
            if (!confirmed && typeof opts.onCancel === 'function') { opts.onCancel(); }
        }
        function onKey(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                close(false);
            } else if (e.key === 'Tab' && !opts.body) {
                // Two buttons: keep focus inside the dialog.
                e.preventDefault();
                (document.activeElement === ok ? cancel : ok).focus();
            }
        }
        document.addEventListener('keydown', onKey, true);
        box.addEventListener('input', refresh);
        box.addEventListener('change', refresh);
        cancel.addEventListener('click', function() { close(false); });
        ok.addEventListener('click', function() { if (!ok.disabled) { close(true); } });
        overlay.addEventListener('click', function(e) { if (e.target === overlay) { close(false); } });
        refresh();
        var first = opts.body ? opts.body.querySelector('textarea, input, select') : null;
        // Destructive questions start on Cancel, so Enter never deletes by accident.
        (first || (opts.danger ? cancel : ok)).focus();
    }

    window.sprintConfirm = function(msg, onOk, opts) {
        opts = opts || {};
        openDialog({
            title: opts.title, message: msg, danger: !!opts.danger,
            okLabel: opts.okLabel, cancelLabel: opts.cancelLabel,
            onOk: onOk, onCancel: opts.onCancel
        });
    };

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text != null) { node.textContent = String(text); }
        return node;
    }

    /**
     * Reason dialog for a capacity above the maximum per item (reply
     * `needs_justification` of ajax/updateitemquick.php). onOk(reason).
     */
    window.sprintCapacityJustify = function(resp, onOk) {
        var body = el('div');
        var label = el('label', 'form-label fw-bold', window.sprintT('reasonLabel', 'Reason'));
        var area = el('textarea', 'form-control');
        area.rows = 3;
        area.maxLength = 2000;
        area.placeholder = window.sprintT('reasonPlaceholder', 'Why does this item need more than the maximum?');
        area.value = resp && resp.justification ? String(resp.justification) : '';
        label.appendChild(area);
        body.appendChild(label);
        openDialog({
            title: window.sprintT('reasonTitle', 'Reason needed'),
            message: resp && resp.message ? resp.message : '',
            body: body,
            okLabel: window.sprintT('save', 'Save'),
            validate: function() { return area.value.trim() !== ''; },
            onOk: function() { onOk(area.value.trim()); }
        });
    };

    /**
     * Scrum Master review of items above the maximum capacity per item
     * (reply `needs_cap_review`): approve the planner's reason as it is or
     * lower the capacity inline. onOk({itemId: capacity}).
     */
    function capacityReviewDialog(resp, onOk) {
        var body = el('div');
        var inputs = {};
        (resp.items || []).forEach(function(item) {
            var card = el('div', 'border rounded p-2 mb-2');
            var head = el('div', 'd-flex flex-wrap align-items-center gap-2 mb-1');
            head.appendChild(el('span', 'fw-bold flex-grow-1', item.name || ('#' + item.id)));
            if (item.owner) { head.appendChild(el('span', 'text-muted sprint-small', item.owner)); }
            head.appendChild(el('span', 'badge bg-yellow-lt', item.capacity + '% (+' + item.over + '%)'));
            card.appendChild(head);
            var reason = el('div', 'sprint-small mb-2');
            reason.style.whiteSpace = 'pre-line';
            if (item.justification) {
                reason.appendChild(el('span', 'fw-bold', window.sprintT('reasonLabel', 'Reason') + ': '));
                reason.appendChild(document.createTextNode(item.justification));
            } else {
                reason.className += ' text-muted fst-italic';
                reason.textContent = window.sprintT('noReason', 'No reason given');
            }
            card.appendChild(reason);
            var row = el('div', 'd-flex align-items-center gap-2');
            var lbl = el('label', 'form-label mb-0 sprint-small', window.sprintT('capacityLabel', 'Capacity (%)'));
            var inp = el('input', 'form-control form-control-sm');
            inp.type = 'number';
            inp.min = '0.5';
            inp.max = String(parseFloat(item.capacity) || 100);
            inp.step = '0.5';
            inp.style.maxWidth = '110px';
            inp.value = item.capacity;
            inp.id = 'sprint-capreview-' + item.id;
            lbl.htmlFor = inp.id;
            row.appendChild(lbl);
            row.appendChild(inp);
            row.appendChild(el('span', 'text-muted sprint-small', window.sprintT('capLowerHint', 'Keep the value to approve it, or lower it')));
            card.appendChild(row);
            body.appendChild(card);
            inputs[item.id] = inp;
        });
        openDialog({
            title: window.sprintT('capReviewTitle', 'Capacity above the maximum'),
            message: resp.message || '',
            body: body,
            wide: true,
            okLabel: window.sprintT('approveAssign', 'Approve and assign'),
            validate: function() {
                return Object.keys(inputs).every(function(id) {
                    var v = parseFloat(inputs[id].value);
                    return v > 0 && v <= (parseFloat(inputs[id].max) || 100);
                });
            },
            onOk: function() {
                var decisions = {};
                Object.keys(inputs).forEach(function(id) { decisions[id] = inputs[id].value; });
                onOk(decisions);
            }
        });
    }

    /**
     * The questions an assign endpoint may answer with instead of assigning:
     * a capacity review (`needs_cap_review`) or a credit confirmation
     * (`needs_credit_confirm`). Opens the dialog and, on OK, calls
     * retry(extraPostData). Returns true when the reply was such a question.
     */
    window.sprintAssignGuard = function(resp, retry) {
        if (!resp || resp.success) { return false; }
        if (resp.needs_cap_review) {
            capacityReviewDialog(resp, function(decisions) { retry({ cap_capacity: decisions }); });
            return true;
        }
        if (resp.needs_credit_confirm) {
            window.sprintConfirm(resp.message || '', function() { retry({ confirm_credits: 1 }); }, {
                title: window.sprintT('creditTitle', 'Credits past the agreement'),
                okLabel: window.sprintT('assignAnyway', 'Assign anyway')
            });
            return true;
        }
        return false;
    };
})();
/* === end terminology === */
