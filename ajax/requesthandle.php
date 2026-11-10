<?php

/**
 * AJAX handler for accepting/rejecting/cancelling a SprintRequest. Only the
 * Scrum Master of the request's sprint may accept or reject it (accepting
 * performs the requested action); only the requester may cancel it.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$id     = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['request_action'] ?? '');

if ($id <= 0 || !in_array($action, ['accept', 'reject', 'cancel'], true)) {
    echo json_encode(['success' => false, 'message' => __('Request failed', 'sprint')]);
    return;
}

// What the client needs to keep its counters right, read before the request
// is closed (the methods below do every permission check).
$sprintId = 0;
$itemId   = 0;
$request  = new GlpiPlugin\Sprint\SprintRequest();
if (GlpiPlugin\Sprint\SprintRequest::ensureTable() && $request->getFromDB($id)) {
    $sprintId = (int)$request->fields['plugin_sprint_sprints_id'];
    $itemId   = (int)$request->fields['plugin_sprint_sprintitems_id'];
}

$result = match ($action) {
    'accept' => GlpiPlugin\Sprint\SprintRequest::accept($id),
    // Optional reason from the Scrum Master, shown to the requester.
    'reject' => GlpiPlugin\Sprint\SprintRequest::reject($id, (string)($_POST['decision_reason'] ?? '')),
    'cancel' => GlpiPlugin\Sprint\SprintRequest::cancel($id),
};

// Relay queued GLPI messages (capacity warnings etc.) instead of stacking
// them for the next page load.
$extra = [];
if (isset($_SESSION['MESSAGE_AFTER_REDIRECT']) && is_array($_SESSION['MESSAGE_AFTER_REDIRECT'])) {
    foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] as $msgs) {
        if (is_array($msgs)) {
            foreach ($msgs as $m) {
                $extra[] = strip_tags((string)$m);
            }
        }
    }
    $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
}

$message = $result['message'];
if (!empty($extra)) {
    $message .= "\n" . implode("\n", $extra);
}

$response = [
    'success' => $result['ok'],
    'message' => $message,
];
// Only for a handled request, so nothing about a request leaks to a session
// that was refused.
if ($result['ok']) {
    $response['sprint_id']     = $sprintId;
    $response['item_id']       = $itemId;
    $response['pending_count'] = GlpiPlugin\Sprint\SprintRequest::pendingCountForSprint($sprintId);
    $response['item_pending']  = count(GlpiPlugin\Sprint\SprintRequest::describePendingForItem($itemId)['requests']);
}
echo json_encode($response);
