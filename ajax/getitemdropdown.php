<?php

/**
 * AJAX handler to render a searchable GLPI dropdown for a given itemtype
 * (Ticket, Change, or ProjectTask).
 */

include(dirname(__DIR__, 3) . '/inc/includes.php');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$itemtype = (string)($_POST['itemtype'] ?? '');
$rand     = (int)($_POST['rand'] ?? mt_rand());

$allowed = ['Ticket', 'Change', 'ProjectTask'];

if (!in_array($itemtype, $allowed, true)) {
    return;
}

if ($itemtype === 'ProjectTask') {
    // Show project dropdown first, then task dropdown loaded via separate AJAX
    echo "<div style='margin-bottom:6px;'>";
    echo "<label style='font-weight:600;margin-right:6px;'>" . __('Project') . ":</label>";
    \Project::dropdown([
        'name' => '_sprint_project',
        'rand' => $rand,
    ]);
    echo "</div>";
    echo "<div id='sprint_projecttask_select_{$rand}'>";
    echo "<label style='font-weight:600;margin-right:6px;'>" . __('Project task') . ":</label>";
    echo "<select name='_linked_projecttask' id='sprint_projecttask_dd_{$rand}' class='form-select' style='width:auto;display:inline-block;min-width:250px;'>";
    echo "<option value='0'>--</option>";
    echo "</select>";
    echo "</div>";

    // Bind project change -> load tasks
    echo "<script>
    $(function() {
        var ajaxUrl = CFG_GLPI.root_doc + '/plugins/sprint/ajax/getprojecttasks.php';
        $(document).on('change', 'select[name=\"_sprint_project\"]', function() {
            var projectId = $(this).val();
            var dd = $('#sprint_projecttask_dd_{$rand}');
            dd.html('<option value=\"0\">--</option>');
            if (!projectId || projectId == 0) return;
            $.ajax({
                url: ajaxUrl, type: 'POST', dataType: 'json',
                data: { projects_id: projectId, _glpi_csrf_token: $('input[name=\"_glpi_csrf_token\"]').first().val() },
                success: function(resp) {
                    if (resp.success && resp.tasks) {
                        dd.html('<option value=\"0\">--</option>');
                        resp.tasks.forEach(function(t) {
                            dd.append($('<option>').val(t.id).text(t.name));
                        });
                    }
                }
            });
        });
    });
    </script>";
} else {
    // Ticket or Change - render GLPI's searchable dropdown
    $fieldName = ($itemtype === 'Ticket') ? '_linked_ticket' : '_linked_change';
    $itemtype::dropdown([
        'name'        => $fieldName,
        'displaywith' => ['id'],
    ]);
}
