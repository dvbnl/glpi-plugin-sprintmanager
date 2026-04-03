<?php

/**
 * AJAX handler to convert an existing sprint into a template.
 * Copies sprint settings, members, and items into a new SprintTemplate.
 */

include('../../../inc/includes.php');

header('Content-Type: application/json');

Session::checkCSRFToken();
Session::checkRight('plugin_sprint_sprint', CREATE);

$response = ['success' => false, 'message' => 'Request failed'];

$sprintId    = (int)($_POST['sprint_id'] ?? 0);
$templateName = trim($_POST['template_name'] ?? '');

if ($sprintId <= 0) {
    echo json_encode($response);
    return;
}

$sprint = new GlpiPlugin\Sprint\Sprint();
if (!$sprint->getFromDB($sprintId)
    || !Session::haveAccessToEntity($sprint->fields['entities_id'] ?? 0)) {
    $response['message'] = 'Access denied';
    echo json_encode($response);
    return;
}

if (empty($templateName)) {
    $templateName = $sprint->fields['name'] . ' - Template';
}

// Create the template
$template = new GlpiPlugin\Sprint\SprintTemplate();
$templateId = $template->add([
    'name'           => $templateName,
    'name_pattern'   => $sprint->fields['name'],
    'duration_weeks' => $sprint->fields['duration_weeks'],
    'goal'           => $sprint->fields['goal'] ?? '',
    'comment'        => $sprint->fields['comment'] ?? '',
    'is_active'      => 1,
    'entities_id'    => $sprint->fields['entities_id'] ?? 0,
    'is_recursive'   => $sprint->fields['is_recursive'] ?? 0,
]);

if (!$templateId) {
    echo json_encode($response);
    return;
}

// Copy sprint members to template members
$memberObj = new GlpiPlugin\Sprint\SprintMember();
$members = $memberObj->find(['plugin_sprint_sprints_id' => $sprintId]);
foreach ($members as $row) {
    $tmplMember = new GlpiPlugin\Sprint\SprintTemplateMember();
    $tmplMember->add([
        'plugin_sprint_sprinttemplates_id' => $templateId,
        'users_id'                         => $row['users_id'],
        'role'                             => $row['role'],
        'capacity_percent'                 => $row['capacity_percent'],
        'comment'                          => $row['comment'] ?? '',
    ]);
}

// Copy sprint items to template items
$itemObj = new GlpiPlugin\Sprint\SprintItem();
$items = $itemObj->find(
    ['plugin_sprint_sprints_id' => $sprintId],
    ['sort_order ASC']
);
foreach ($items as $row) {
    $tmplItem = new GlpiPlugin\Sprint\SprintTemplateItem();
    $tmplItem->add([
        'plugin_sprint_sprinttemplates_id' => $templateId,
        'name'                             => $row['name'],
        'description'                      => $row['description'] ?? '',
        'priority'                         => $row['priority'],
        'story_points'                     => $row['story_points'],
        'sort_order'                       => $row['sort_order'],
    ]);
}

// Analyze sprint meetings and create template meeting schedule
$meetingObj = new GlpiPlugin\Sprint\SprintMeeting();
$meetings = $meetingObj->find(
    ['plugin_sprint_sprints_id' => $sprintId],
    ['date_meeting ASC']
);

$sprintStart = !empty($sprint->fields['date_start']) ? new DateTime($sprint->fields['date_start']) : null;
$sprintEnd   = !empty($sprint->fields['date_end']) ? new DateTime($sprint->fields['date_end']) : null;

// Detect schedule pattern from existing meetings
$meetingsByType = [];
foreach ($meetings as $row) {
    $meetingsByType[$row['meeting_type']][] = $row;
}

$order = 0;
foreach ($meetingsByType as $type => $typeMeetings) {
    $first = $typeMeetings[0];

    if (count($typeMeetings) === 1) {
        // Single meeting: determine if first day, last day, or day before end
        $meetingDate = new DateTime($first['date_meeting']);
        $scheduleType = 'first_day';

        if ($sprintStart && $sprintEnd) {
            $diffStart = abs($meetingDate->diff($sprintStart)->days);
            $diffEnd   = abs($meetingDate->diff($sprintEnd)->days);

            if ($diffEnd === 0) {
                $scheduleType = 'last_day';
            } elseif ($diffEnd === 1) {
                $scheduleType = 'day_before_end';
            }
        }

        $tmplMeeting = new GlpiPlugin\Sprint\SprintTemplateMeeting();
        $tmplMeeting->add([
            'plugin_sprint_sprinttemplates_id' => $templateId,
            'name'                             => $first['name'],
            'meeting_type'                     => $type,
            'schedule_type'                    => $scheduleType,
            'interval_days'                    => 1,
            'duration_minutes'                 => $first['duration_minutes'],
            'is_optional'                      => 0,
            'sort_order'                       => $order++,
        ]);
    } else {
        // Multiple meetings of same type: detect interval
        $dates = [];
        foreach ($typeMeetings as $m) {
            $dates[] = new DateTime($m['date_meeting']);
        }

        $totalDiff = 0;
        for ($i = 1; $i < count($dates); $i++) {
            $totalDiff += $dates[$i - 1]->diff($dates[$i])->days;
        }
        $avgInterval = max(1, (int)round($totalDiff / (count($dates) - 1)));

        $tmplMeeting = new GlpiPlugin\Sprint\SprintTemplateMeeting();
        $tmplMeeting->add([
            'plugin_sprint_sprinttemplates_id' => $templateId,
            'name'                             => $first['name'],
            'meeting_type'                     => $type,
            'schedule_type'                    => 'interval',
            'interval_days'                    => $avgInterval,
            'duration_minutes'                 => $first['duration_minutes'],
            'is_optional'                      => 0,
            'sort_order'                       => $order++,
        ]);
    }
}

echo json_encode([
    'success'     => true,
    'message'     => 'Template created successfully',
    'template_id' => $templateId,
    'template_url' => GlpiPlugin\Sprint\SprintTemplate::getFormURLWithID($templateId),
]);
