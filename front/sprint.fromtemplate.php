<?php

/**
 * Create a new sprint from a selected template.
 * Shows template picker, then creates the sprint with template applied.
 */

include('../../../inc/includes.php');

Session::checkRight('plugin_sprint_sprint', CREATE);

if (isset($_POST['create_from_template'])) {
    $templateId = (int)($_POST['plugin_sprint_sprinttemplates_id'] ?? 0);
    if ($templateId <= 0) {
        Session::addMessageAfterRedirect(__('Please select a template', 'sprint'), false, ERROR);
        Html::back();
    }

    $template = new GlpiPlugin\Sprint\SprintTemplate();
    if (!$template->getFromDB($templateId)) {
        Session::addMessageAfterRedirect(__('Template not found', 'sprint'), false, ERROR);
        Html::back();
    }

    $data = $template->getTemplateData();

    // Build sprint data from template + user input
    $sprintData = [
        'name'            => $_POST['name'] ?? $data['name_pattern'],
        'duration_weeks'  => $data['duration_weeks'],
        'goal'            => $data['goal'],
        'status'          => GlpiPlugin\Sprint\Sprint::STATUS_PLANNED,
        'sprint_number'   => (int)($_POST['sprint_number'] ?? 0),
        'date_start'      => $_POST['date_start'] ?? '',
        'users_id'        => (int)($_POST['users_id'] ?? 0),
        'projects_id'     => (int)($_POST['projects_id'] ?? 0),
        'entities_id'     => $_SESSION['glpiactive_entity'] ?? 0,
        'plugin_sprint_sprinttemplates_id' => $templateId,
    ];

    $sprint = new GlpiPlugin\Sprint\Sprint();
    $sprint->check(-1, CREATE, $sprintData);
    $newID = $sprint->add($sprintData);

    if ($newID) {
        Html::redirect(GlpiPlugin\Sprint\Sprint::getFormURLWithID($newID));
    } else {
        Session::addMessageAfterRedirect(__('Failed to create sprint', 'sprint'), false, ERROR);
        Html::back();
    }
} else {
    Html::header(
        __('Create sprint from template', 'sprint'),
        $_SERVER['PHP_SELF'],
        'helpdesk',
        'GlpiPlugin\Sprint\Sprint'
    );

    // Get active templates
    $template = new GlpiPlugin\Sprint\SprintTemplate();
    $templates = $template->find(['is_active' => 1], ['name ASC']);

    echo "<div class='center'>";
    echo "<form method='post' action='" . $_SERVER['PHP_SELF'] . "'>";

    echo "<table class='tab_cadre_fixe'>";
    echo "<tr class='tab_bg_2'><th colspan='4'>";
    echo "<i class='fas fa-clone me-2'></i>" . __('Create sprint from template', 'sprint');
    echo "</th></tr>";

    // Template selector
    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Template', 'sprint') . " <span class='text-danger'>*</span></td>";
    echo "<td colspan='3'>";
    GlpiPlugin\Sprint\SprintTemplate::dropdown([
        'name'      => 'plugin_sprint_sprinttemplates_id',
        'value'     => 0,
        'condition' => ['is_active' => 1],
        'on_change' => 'sprintLoadTemplatePreview(this.value)',
    ]);
    echo "</td></tr>";

    // Template preview area
    echo "<tr class='tab_bg_1' id='template_preview_row' style='display:none;'>";
    echo "<td>" . __('Template details', 'sprint') . "</td>";
    echo "<td colspan='3'><div id='template_preview'></div></td>";
    echo "</tr>";

    // Sprint name override
    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Sprint name', 'sprint') . "</td>";
    echo "<td colspan='3'>";
    echo Html::input('name', ['size' => 40, 'id' => 'sprint_from_tmpl_name']);
    echo "</td></tr>";

    // Sprint number
    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Sprint number', 'sprint') . "</td>";
    echo "<td>";
    echo Html::input('sprint_number', ['type' => 'number', 'min' => 1, 'value' => '']);
    echo "</td>";
    echo "<td>" . __('Start date', 'sprint') . "</td>";
    echo "<td>";
    Html::showDateTimeField('date_start', ['value' => '']);
    echo "</td></tr>";

    // Scrum Master + Project
    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Scrum Master', 'sprint') . " <span class='text-danger'>*</span></td>";
    echo "<td>";
    User::dropdown(['name' => 'users_id', 'value' => 0, 'right' => 'all']);
    echo "</td>";
    echo "<td>" . __('Project') . "</td>";
    echo "<td>";
    Project::dropdown(['name' => 'projects_id', 'value' => 0]);
    echo "</td></tr>";

    // Submit
    echo "<tr class='tab_bg_1'>";
    echo "<td colspan='4' class='center'>";
    echo Html::submit(__('Create sprint', 'sprint'), [
        'name'  => 'create_from_template',
        'class' => 'btn btn-primary',
    ]);
    echo "</td></tr>";

    echo "</table>";
    Html::closeForm();
    echo "</div>";

    // JS: preview template details
    echo "<script>
    function sprintLoadTemplatePreview(templateId) {
        var previewRow = $('#template_preview_row');
        var preview = $('#template_preview');
        var nameField = $('#sprint_from_tmpl_name');

        if (!templateId || templateId == 0) {
            previewRow.hide();
            return;
        }

        $.ajax({
            url: CFG_GLPI.root_doc + '/plugins/sprint/ajax/gettemplate.php',
            type: 'POST',
            dataType: 'json',
            data: {
                id: templateId,
                _glpi_csrf_token: $('input[name=\"_glpi_csrf_token\"]').first().val()
            },
            success: function(resp) {
                if (resp.success && resp.data) {
                    var d = resp.data;
                    var html = '<ul style=\"margin:0;padding-left:20px;\">';
                    html += '<li><strong>" . __('Duration', 'sprint') . ":</strong> ' + d.duration_weeks + ' " . __('weeks', 'sprint') . "</li>';
                    if (d.goal) {
                        html += '<li><strong>" . __('Goal', 'sprint') . ":</strong> ' + $('<span>').text(d.goal).html() + '</li>';
                    }
                    if (d.members_count !== undefined) {
                        html += '<li><strong>" . __('Members', 'sprint') . ":</strong> ' + d.members_count + '</li>';
                    }
                    if (d.items_count !== undefined) {
                        html += '<li><strong>" . __('Items', 'sprint') . ":</strong> ' + d.items_count + '</li>';
                    }
                    if (d.meetings_count !== undefined) {
                        html += '<li><strong>" . __('Meetings', 'sprint') . ":</strong> ' + d.meetings_count + '</li>';
                    }
                    html += '</ul>';
                    preview.html(html);
                    previewRow.show();

                    if (d.name_pattern && !nameField.val()) {
                        nameField.val(d.name_pattern);
                    }
                }
            }
        });
    }
    </script>";

    Html::footer();
}
