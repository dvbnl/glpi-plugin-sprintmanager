<?php

/**
 * AJAX handler behind the close-out screen (SprintCloseout): handles every
 * open item of a sprint in one call (carry over, back to backlog) and then,
 * when asked, completes the sprint through Sprint::update().
 *
 * POST: sprint_id, complete (0|1), reason (shared, optional) and
 * items[<item id>][action|target|capacity|credits|reason]. Items that stay in
 * the sprint are simply not sent.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_sprint', READ);

$response = [
    'success'   => false,
    'completed' => false,
    'message'   => __('Request failed', 'sprint'),
    'results'   => [],
    'notices'   => [],
    'redirect'  => '',
];

$sprintId = (int)($_POST['sprint_id'] ?? 0);
$sprint   = new GlpiPlugin\Sprint\Sprint();

// Sprint update right, entity access and the Scrum Master of this sprint.
if ($sprintId <= 0
    || !$sprint->getFromDB($sprintId)
    || !$sprint->can($sprintId, UPDATE)
    || !GlpiPlugin\Sprint\Sprint::canManageLifecycle($sprintId)) {
    echo json_encode($response);
    return;
}

$complete = (int)($_POST['complete'] ?? 0) === 1;
$status   = (string)($sprint->fields['status'] ?? '');
$allowed  = $complete
    ? [GlpiPlugin\Sprint\Sprint::STATUS_ACTIVE]
    : [GlpiPlugin\Sprint\Sprint::STATUS_ACTIVE, GlpiPlugin\Sprint\Sprint::STATUS_COMPLETED];
if (!in_array($status, $allowed, true)) {
    $response['message'] = __('Only an active sprint can be completed. Start the sprint first.', 'sprint');
    echo json_encode($response);
    return;
}

$rows = $_POST['items'] ?? [];

echo json_encode(GlpiPlugin\Sprint\SprintCloseout::process(
    $sprint,
    is_array($rows) ? $rows : [],
    (string)($_POST['reason'] ?? ''),
    $complete
));
