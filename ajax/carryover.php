<?php

/**
 * AJAX handler that carries a SprintItem over to another sprint. Carry-over
 * is additive: the original stays put and a fresh copy is created in the
 * target sprint. Anyone but the target sprint's Scrum Master files a request.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$response = ['success' => false, 'message' => __('Request failed', 'sprint')];

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
if (!$item->getFromDB($id) || !$item->hasEntityAccess()) {
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

$carryCapacity = isset($_POST['carry_capacity']) && $_POST['carry_capacity'] !== '' ? (float)$_POST['carry_capacity'] : null;
$carryCredits  = isset($_POST['carry_credits']) && $_POST['carry_credits'] !== '' ? (float)$_POST['carry_credits'] : null;
if (!GlpiPlugin\Sprint\SprintCustomer::canUseCredits()) {
    $carryCredits = $carryCapacity !== null ? 0.0 : null;
}
// Direct for the target sprint's Scrum Master, an approval request otherwise.
$result = GlpiPlugin\Sprint\SprintItem::carryOverOrRequest(
    $id,
    $sprintId,
    $carryCapacity,
    $carryCredits,
    trim((string)($_POST['reason'] ?? ''))
);

echo json_encode([
    'success'   => $result['ok'],
    'requested' => $result['requested'],
    'message'   => $result['message'],
    'item_id'   => $id,
    'new_id'    => $result['new_id'],
    'sprint_id' => $sprintId,
    // A request is reported as such (current → requested), never as done.
    'requests'  => $result['requested']
        ? GlpiPlugin\Sprint\SprintRequest::describePendingForItem($id)['requests']
        : [],
]);
