<?php

/**
 * AJAX handler creating an approval request for the Scrum Master:
 * a non-Scrum-Master asking to assign a backlog item to its pre-selected
 * sprint. (Capacity requests are created server-side by SprintItem when a
 * guarded capacity edit is attempted.)
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$id = (int)($_POST['id'] ?? 0);

$item = new GlpiPlugin\Sprint\SprintItem();
if ($id <= 0 || !$item->getFromDB($id)) {
    echo json_encode(['success' => false, 'message' => __('Request failed', 'sprint')]);
    return;
}

// Only backlog items with a pre-selected sprint can be requested.
if ((int)$item->fields['plugin_sprint_sprints_id'] !== 0) {
    echo json_encode(['success' => false, 'message' => __('The item is no longer on the backlog.', 'sprint')]);
    return;
}
$sprintId = (int)($item->fields['proposed_sprints_id'] ?? 0);
if ($sprintId <= 0) {
    echo json_encode(['success' => false, 'message' => __('Pre-select a sprint first (edit the item)', 'sprint')]);
    return;
}

$sprint = new GlpiPlugin\Sprint\Sprint();
if (!$sprint->getFromDB($sprintId)) {
    echo json_encode(['success' => false, 'message' => __('Please select a sprint', 'sprint')]);
    return;
}

// The requester must at least be allowed to edit their own backlog items.
$hasFullUpdate = Session::haveRight('plugin_sprint_item', UPDATE);
$hasOwnOnly    = Session::haveRight('plugin_sprint_item', GlpiPlugin\Sprint\Profile::RIGHT_OWN_ITEMS);
if (!$hasFullUpdate && !$hasOwnOnly) {
    echo json_encode(['success' => false, 'message' => __('Request failed', 'sprint')]);
    return;
}

$reqId = GlpiPlugin\Sprint\SprintRequest::createPending(
    GlpiPlugin\Sprint\SprintRequest::TYPE_ASSIGN,
    $id,
    $sprintId,
    0.0,
    trim((string)($_POST['reason'] ?? ''))
);

if ($reqId <= 0) {
    echo json_encode(['success' => false, 'message' => __('Request failed', 'sprint')]);
    return;
}

echo json_encode([
    'success'    => true,
    'request_id' => $reqId,
    'message'    => sprintf(
        __('Assignment requested — the Scrum Master of %s will review it.', 'sprint'),
        (string)$sprint->fields['name']
    ),
]);
