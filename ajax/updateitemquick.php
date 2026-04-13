<?php

/**
 * AJAX handler for quick sprint item edits from the meeting view.
 * Accepts the full editable fieldset; sanitization and capacity validation
 * are handled inside SprintItem::prepareInputForUpdate().
 */

include(dirname(__DIR__, 3) . '/inc/includes.php');

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$response = ['success' => false, 'message' => 'Request failed'];

if (!isset($_POST['id'])) {
    echo json_encode($response);
    return;
}

$item = new GlpiPlugin\Sprint\SprintItem();
if (!$item->getFromDB((int)$_POST['id'])) {
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

$update = ['id' => (int)$_POST['id']];

$allowed = ['name', 'status', 'priority', 'users_id', 'story_points', 'capacity', 'note'];
foreach ($allowed as $field) {
    if (array_key_exists($field, $_POST)) {
        $update[$field] = $_POST[$field];
    }
}

$result = $item->update($update);

// Drain any messages that SprintItem::validateCapacity (and similar) may
// have queued via Session::addMessageAfterRedirect so we can surface them
// back to the client instead of them stacking up for the next page load.
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
        'message' => $messages ? implode("\n", $messages) : 'Update failed',
    ]);
    return;
}

$item->getFromDB((int)$_POST['id']);
echo json_encode([
    'success'      => true,
    'message'      => $messages ? implode("\n", $messages) : 'Item updated',
    'name'         => (string)$item->fields['name'],
    'status'       => (string)$item->fields['status'],
    'priority'     => (int)$item->fields['priority'],
    'users_id'     => (int)$item->fields['users_id'],
    'story_points' => (int)$item->fields['story_points'],
    'capacity'     => (int)($item->fields['capacity'] ?? 0),
    'note'         => (string)($item->fields['note'] ?? ''),
]);
