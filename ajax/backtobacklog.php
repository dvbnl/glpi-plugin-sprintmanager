<?php

/**
 * AJAX handler that moves a SprintItem back to the backlog from inside the
 * sprint meeting view. Mirrors front/backlog.form.php's `back_to_backlog`
 * branch but returns JSON so the row can be removed in place instead of
 * triggering a full page reload.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$response = ['success' => false, 'message' => 'Request failed'];

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    echo json_encode($response);
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

$wasBlocked = ($item->fields['status'] ?? '') === GlpiPlugin\Sprint\SprintItem::STATUS_BLOCKED
    || (int)($item->fields['is_blocked'] ?? 0) === 1;

$update = [
    'id'                       => $id,
    'plugin_sprint_sprints_id' => 0,
    'is_fastlane'              => 0,
];
if ($wasBlocked) {
    $update['is_blocked'] = 1;
}

$result = $item->update($update);

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
        'message' => $messages ? implode("\n", $messages) : __('Could not move item back to backlog', 'sprint'),
    ]);
    return;
}

GlpiPlugin\Sprint\SprintItemDependency::purgeForItem($id);

echo json_encode([
    'success' => true,
    'message' => __('Item moved back to backlog', 'sprint'),
    'item_id' => $id,
]);
