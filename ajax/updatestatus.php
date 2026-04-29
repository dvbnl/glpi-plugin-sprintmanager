<?php

/**
 * AJAX handler for quick sprint item status updates
 * GLPI 11 compatible: no exit(), uses return flow
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$response = ['success' => false, 'message' => 'Request failed'];

if (!isset($_POST['id']) || !isset($_POST['status'])) {
    echo json_encode($response);
    return;
}

$item = new GlpiPlugin\Sprint\SprintItem();
if (!$item->getFromDB((int)$_POST['id'])) {
    echo json_encode($response);
    return;
}

// Check if user can update this item (full right or own-item right)
$hasFullUpdate = Session::haveRight('plugin_sprint_item', UPDATE);
$hasOwnOnly = !$hasFullUpdate
    && Session::haveRight('plugin_sprint_item', GlpiPlugin\Sprint\Profile::RIGHT_OWN_ITEMS);
$isOwner = (int)$item->fields['users_id'] === (int)Session::getLoginUserID();

if (!$hasFullUpdate && !($hasOwnOnly && $isOwner)) {
    echo json_encode($response);
    return;
}

$validStatuses = array_keys(GlpiPlugin\Sprint\SprintItem::getAllStatuses());
if (!in_array($_POST['status'], $validStatuses)) {
    echo json_encode($response);
    return;
}

$result = $item->update([
    'id'     => (int)$_POST['id'],
    'status' => $_POST['status'],
]);

echo json_encode([
    'success' => (bool)$result,
    'message' => $result ? 'Status updated' : 'Update failed',
]);
