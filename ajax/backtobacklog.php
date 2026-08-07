<?php

/**
 * AJAX handler that moves a SprintItem back to the backlog from the sprint
 * meeting view. JSON variant of front/backlog.form.php's `back_to_backlog`.
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

$reason           = (string)($_POST['reason'] ?? '');
$removeFromSprint = (int)($_POST['remove_from_sprint'] ?? 0) === 1;

$outcome = GlpiPlugin\Sprint\SprintItem::backToBacklog($id, $reason, $removeFromSprint);

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

if (!$outcome['ok']) {
    echo json_encode([
        'success' => false,
        'message' => ($outcome['message'] ?? '') !== ''
            ? $outcome['message']
            : ($messages ? implode("\n", $messages) : __('Could not move item back to backlog', 'sprint')),
    ]);
    return;
}

// `stayed`: linked item decoupled but the sprint item remains (keep the meeting
// row). Otherwise the whole row left the sprint and should be removed.
echo json_encode([
    'success'    => true,
    'message'    => $outcome['stayed']
        ? __('Underlying item moved to backlog. The sprint item stays for capacity.', 'sprint')
        : __('Item moved back to backlog', 'sprint'),
    'item_id'    => $id,
    'stayed'     => $outcome['stayed'],
    'backlog_id' => $outcome['backlog_id'],
]);
