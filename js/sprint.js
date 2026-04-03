/**
 * Sprint Plugin - JavaScript
 */

(function() {
    'use strict';

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

