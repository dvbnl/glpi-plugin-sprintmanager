<?php

/**
 * AJAX handler for assigning a backlog SprintItem to a sprint.
 *
 * Replaces the form-submit + page-refresh flow on the backlog page with
 * an in-place update: the row removes itself once the assignment is
 * persisted server-side. Feeds back the chosen sprint's name + URL so
 * the UI can show a confirmation toast that links to the sprint.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$response = ['success' => false, 'message' => 'Request failed'];

$id       = (int)($_POST['id'] ?? 0);
$sprintId = (int)($_POST['plugin_sprint_sprints_id'] ?? 0);

if ($id <= 0 || $sprintId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => __('Please select a sprint', 'sprint'),
    ]);
    return;
}

$item = new GlpiPlugin\Sprint\SprintItem();
if (!$item->getFromDB($id)) {
    echo json_encode($response);
    return;
}

$hasFullUpdate = Session::haveRight('plugin_sprint_item', UPDATE);
$hasOwnOnly = !$hasFullUpdate
    && Session::haveRight('plugin_sprint_item', GlpiPlugin\Sprint\Profile::RIGHT_OWN_ITEMS);
$isOwner = (int)$item->fields['users_id'] === (int)Session::getLoginUserID();

if (!$hasFullUpdate && !($hasOwnOnly && $isOwner)) {
    echo json_encode($response);
    return;
}

$sprint = new GlpiPlugin\Sprint\Sprint();
if (!$sprint->getFromDB($sprintId)) {
    echo json_encode([
        'success' => false,
        'message' => __('Please select a sprint', 'sprint'),
    ]);
    return;
}

$result = $item->update([
    'id'                       => $id,
    'plugin_sprint_sprints_id' => $sprintId,
]);

$messages = [];
if (isset($_SESSION['MESSAGE_AFTER_REDIRECT']) && is_array($_SESSION['MESSAGE_AFTER_REDIRECT'])) {
    foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] as $level => $msgs) {
        if (is_array($msgs)) {
            foreach ($msgs as $m) {
                $messages[] = strip_tags((string)$m);
            }
        }
    }
    $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
}

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => $messages ? implode("\n", $messages) : __('Could not assign item to sprint', 'sprint'),
    ]);
    return;
}

echo json_encode([
    'success'      => true,
    'message'      => sprintf(__('Assigned to %s', 'sprint'), (string)$sprint->fields['name']),
    'sprint_id'    => $sprintId,
    'sprint_name'  => (string)$sprint->fields['name'],
    'sprint_url'   => GlpiPlugin\Sprint\Sprint::getFormURLWithID($sprintId),
]);
