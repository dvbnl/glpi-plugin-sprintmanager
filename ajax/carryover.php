<?php

/**
 * AJAX handler that carries a SprintItem over to another sprint. JSON variant
 * of front/backlog.form.php's `carry_over_to_sprint`. Carry-over is additive:
 * the original stays put and a fresh copy is created in the target sprint.
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
if (!$sprint->getFromDB($sprintId)
    || !Session::haveAccessToEntity($sprint->fields['entities_id'] ?? 0)) {
    echo json_encode([
        'success' => false,
        'message' => __('Please select a sprint', 'sprint'),
    ]);
    return;
}

$newId = GlpiPlugin\Sprint\SprintItem::carryOverTo($id, $sprintId);

if ($newId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => __('Could not carry the item over to the target sprint', 'sprint'),
    ]);
    return;
}

echo json_encode([
    'success'     => true,
    'message'     => sprintf(__('Carried over to %s', 'sprint'), (string)$sprint->fields['name']),
    'item_id'     => $id,
    'new_id'      => $newId,
    'sprint_id'   => $sprintId,
    'sprint_name' => (string)$sprint->fields['name'],
]);
